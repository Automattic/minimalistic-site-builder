<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\SiteBuild\CodeFences;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\DesignMarkupSanitizer;
use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;

/**
 * Generates one standalone first-fold design preview. The preview remains an
 * additive artifact until later slices make it the design seed.
 *
 * Generated markup is untrusted. Recoverable defects (comments, leftover
 * script/link/iframe, picture/source, trailing junk around a complete
 * document) are stripped and re-validated. One remaining defect gets one
 * direct repair request; a missing or still-invalid response degrades to a
 * deterministic, contract-valid scaffold instead of aborting the build.
 */
final class DesignPreviewStep implements Step
{
    use LlmOptions;

    private const PATH = 'design/preview.html';

    private const CSS_PATH = 'design/site.css';

    private const RAW_TEXT_ELEMENTS = [
        'iframe', 'noembed', 'noframes', 'script', 'style', 'textarea', 'title', 'xmp',
    ];

    private const IMAGE_ALT_PATTERN = '/^AI_IMAGE: [^|\r\n]+ \| [^|\r\n]+ \| '
        . '(?:photorealistic|digital-art|illustration|minimalist|flat-design|3d-render|abstract|watercolor) '
        . '\| (?:square|landscape|portrait)$/D';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'design-preview';
    }

    public function label(): string
    {
        return 'Design first-fold preview';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['meta.json', 'siteSpec.json', 'designDirection.json'],
            writes: [self::PATH, self::CSS_PATH, 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $warnings = [];
        try {
            $meta = $project->readJson('meta.json');
            $brief = trim((string) ($meta['prompt'] ?? ''));
            if ($brief === '') {
                throw new \RuntimeException('meta.json has no "prompt"');
            }

            $siteSpecData = $project->readJson('siteSpec.json');
            $sitePages = PagePlanStep::flattenPages($siteSpecData);
            $siteSpec = $project->readText('siteSpec.json');
            $designDirection = $project->readText('designDirection.json');
            $prompt = $this->renderer->render('design-preview.md', [
                'brief' => $brief,
                'site_spec' => $siteSpec,
                'site_pages' => PagePlanStep::sitePagesList($sitePages),
                'design_direction' => $designDirection,
            ]);

            try {
                $authored = $this->llm->complete(
                    $prompt,
                    $this->withOptions(['log_label' => 'design-preview']),
                );
            } catch (\RuntimeException $error) {
                $scaffold = self::safeScaffold($siteSpec, $brief, $sitePages);
                self::writePreview($project, $scaffold);
                $message = 'initial LLM request failed: ' . $error->getMessage();
                $warnings[] = self::degradedWarning($message, $scaffold, $message);
                return;
            }

            $issue = 'unknown design defect';
            $initialSanitizerWarnings = [];
            try {
                $candidate = self::sanitize(
                    $authored,
                    'initial preview generation',
                    $initialSanitizerWarnings,
                );
                $recovered = self::recover($candidate, $sitePages, $initialSanitizerWarnings);
                $candidate = $recovered['html'];
                $issue = $recovered['issue'];
            } catch (\RuntimeException $error) {
                $candidate = $authored;
                $issue = 'sanitizer failed: ' . $error->getMessage();
            }

            array_push($warnings, ...$initialSanitizerWarnings);
            if ($issue === null) {
                self::writePreview($project, $candidate);
                return;
            }

            $repairedIssue = null;
            try {
                $repairedAuthored = $this->llm->complete(
                    self::repairPrompt($prompt, $authored, $issue),
                    $this->withOptions(['log_label' => 'design-preview-repair']),
                );
                $repairSanitizerWarnings = [];
                $repaired = self::sanitize(
                    $repairedAuthored,
                    'malformed preview repair',
                    $repairSanitizerWarnings,
                );
                $recoveredRepair = self::recover($repaired, $sitePages, $repairSanitizerWarnings);
                $repaired = $recoveredRepair['html'];
                $repairedIssue = $recoveredRepair['issue'];
                if ($repairedIssue !== null) {
                    throw new \RuntimeException("repair remained invalid: {$repairedIssue}");
                }

                self::writePreview($project, $repaired);
                array_push($warnings, ...$repairSanitizerWarnings);
                $warnings[] = 'malformed_design file design/preview.html block_path document '
                    . 'authored_value ' . self::warningValue($authored)
                    . ' delivered_value contract-valid repaired preview document '
                    . 'disposition repaired; defect ' . self::warningValue($issue);
            } catch (\RuntimeException $error) {
                $scaffold = self::safeScaffold($siteSpec, $brief, $sitePages);
                self::writePreview($project, $scaffold);
                $warnings[] = self::degradedWarning(
                    $authored . '; repair failure: ' . $error->getMessage(),
                    $scaffold,
                    $issue,
                    $repairedIssue ?? $error->getMessage(),
                );
            }
        } finally {
            $project->addWarnings($this->id(), $warnings);
        }
    }

    private static function writePreview(Project $project, string $html): void
    {
        $css = self::headStyleContents($html);
        if ($css === null) {
            throw new \RuntimeException('validated preview has no inline head style');
        }

        $project->writeText(self::PATH, $html);
        $project->writeText(self::CSS_PATH, $css);
    }

    private static function headStyleContents(string $html): ?string
    {
        $length = strlen($html);
        $offset = 0;
        $insideHead = false;
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                return null;
            }
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 3;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = self::sourceMarkupEnd($html, $start + 2);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }

            $tag = self::sourceTagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }
            $offset = $tag['end'];
            if ($tag['closing']) {
                if ($tag['name'] === 'head') {
                    $insideHead = false;
                }
                continue;
            }
            if ($tag['name'] === 'head') {
                $insideHead = true;
                continue;
            }
            if (!in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)) {
                continue;
            }

            $closing = self::rawTextClosingTag($html, $tag['name'], $offset);
            if ($closing === null) {
                return null;
            }
            if ($insideHead && $tag['name'] === 'style') {
                return substr($html, $offset, $closing['start'] - $offset);
            }
            $offset = $closing['end'];
        }
        return null;
    }

    /** @return array{name:string,closing:bool,start:int,end:int}|null */
    private static function sourceTagAt(string $html, int $start): ?array
    {
        if (($html[$start] ?? '') !== '<') {
            return null;
        }
        $length = strlen($html);
        $cursor = $start + 1;
        $closing = ($html[$cursor] ?? '') === '/';
        if ($closing) {
            $cursor++;
        }
        if (!isset($html[$cursor]) || preg_match('/[A-Za-z]/A', substr($html, $cursor, 1)) !== 1) {
            return null;
        }

        $nameStart = $cursor;
        while (isset($html[$cursor]) && preg_match('/[A-Za-z0-9:-]/A', $html[$cursor]) === 1) {
            $cursor++;
        }
        if (isset($html[$cursor]) && !ctype_space($html[$cursor]) && $html[$cursor] !== '>' && $html[$cursor] !== '/') {
            return null;
        }
        $end = self::sourceMarkupEnd($html, $cursor);
        if ($end === null || $end > $length) {
            return null;
        }

        return [
            'name' => strtolower(substr($html, $nameStart, $cursor - $nameStart)),
            'closing' => $closing,
            'start' => $start,
            'end' => $end,
        ];
    }

    private static function sourceMarkupEnd(string $html, int $offset): ?int
    {
        $quote = '';
        $length = strlen($html);
        for ($cursor = $offset; $cursor < $length; $cursor++) {
            $byte = $html[$cursor];
            if ($quote !== '') {
                if ($byte === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '>') {
                return $cursor + 1;
            }
        }
        return null;
    }

    /** @return array{name:string,closing:bool,start:int,end:int}|null */
    private static function rawTextClosingTag(string $html, string $name, int $offset): ?array
    {
        $needle = '</' . $name;
        while (($start = stripos($html, $needle, $offset)) !== false) {
            $tag = self::sourceTagAt($html, $start);
            if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                return $tag;
            }
            $offset = $start + 2;
        }
        return null;
    }

    /**
     * @param list<string> $warnings
     */
    private static function sanitize(string $html, string $context, array &$warnings): string
    {
        $sanitizerWarnings = [];
        $sanitized = DesignMarkupSanitizer::sanitize(
            $html,
            self::PATH,
            $context,
            $sanitizerWarnings,
        );
        foreach ($sanitizerWarnings as $warning) {
            $warnings[] = self::actionableSanitizerWarning($warning, $context);
        }
        return $sanitized;
    }

    private static function actionableSanitizerWarning(string $warning, string $context): string
    {
        $prefix = 'malformed_design: ' . self::PATH . " context {$context}; authored ";
        $suffix = '; delivered removed; disposition removed';
        $authored = $warning;
        if (str_starts_with($warning, $prefix) && str_ends_with($warning, $suffix)) {
            $authored = substr(
                $warning,
                strlen($prefix),
                strlen($warning) - strlen($prefix) - strlen($suffix),
            );
        }

        return 'malformed_design file design/preview.html block_path document '
            . 'authored_value ' . self::warningValue($authored)
            . ' delivered_value removed disposition removed';
    }

    private static function repairPrompt(string $prompt, string $authored, string $issue): string
    {
        return $prompt
            . "\n\nREPAIR ATTEMPT\n"
            . "Previous preview violated contract: {$issue}.\n"
            . "Regenerate whole preview. Preserve usable content and design intent.\n"
            . "Return only one complete replacement HTML document.\n\n"
            . "<malformed_preview>\n{$authored}\n</malformed_preview>";
    }

    /**
     * Prove the browser winners for the five desktop header declarations the
     * preview contract requires. A negative column regex is insufficient:
     * grid, flex-flow, higher-specificity overrides and conditional rules can
     * all stack the identity above the nav without spelling that exact token.
     */
    private static function headerLayoutIssue(
        string $css,
        DOMElement $header,
        DOMElement $identity,
        DOMElement $navigation,
        DOMElement $identityItem,
        DOMElement $navigationItem,
    ): ?string
    {
        foreach (self::headerLayoutViewports($css) as $viewport) {
            $issue = self::headerLayoutIssueAtViewport(
                $css,
                $header,
                $identityItem,
                $navigationItem,
                $viewport,
            );
            if ($issue !== null) {
                return $issue;
            }
            $critical = [
                'header' => $header,
                'identity' => $identity,
                'navigation' => $navigation,
                'identity row item' => $identityItem,
                'navigation row item' => $navigationItem,
            ];
            $seen = [];
            foreach ($critical as $label => $element) {
                $id = spl_object_id($element);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $issue = self::headerCriticalElementIssueAtViewport($css, $element, $label, $viewport);
                if ($issue !== null) {
                    return $issue;
                }
            }
        }
        return null;
    }

    private static function headerLayoutIssueAtViewport(
        string $css,
        DOMElement $header,
        DOMElement $identity,
        DOMElement $navigation,
        float $viewport,
    ): ?string
    {
        $required = [
            'display' => 'flex',
            'flex-direction' => 'row',
            'flex-wrap' => 'nowrap',
            'align-items' => 'center',
            'justify-content' => 'space-between',
        ];
        /** @var array<string,array{value:string,important:bool,specificity:int,order:int}> $winners */
        $winners = [];

        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            if ($declaration['kind'] !== 'style' || !$declaration['structurallySafe']) {
                continue;
            }
            $property = strtolower(trim($declaration['property']));
            if (!in_array(
                $property,
                ['display', 'flex-direction', 'flex-wrap', 'flex-flow', 'align-items', 'place-items', 'justify-content', 'order', 'all'],
                true,
            )) {
                continue;
            }
            $scope = CssChecks::declarationScopeAtViewport($declaration['ancestors'], $viewport);
            if ($scope === 'inert') {
                continue;
            }

            if ($property !== 'order') {
                $match = self::matchingSpecificity(
                    $declaration['context'],
                    $declaration['ancestors'],
                    $header,
                );
                if ($match['unprovable'] || ($match['specificity'] !== null && $scope === 'unprovable')) {
                    return 'desktop header layout cannot be proven across the CSS cascade';
                }
                if ($match['specificity'] !== null) {
                    $priority = CssChecks::splitDeclarationPriority($declaration['value']);
                    $values = self::headerLayoutValues($property, $priority['value']);
                    foreach ($values as $resolvedProperty => $value) {
                        $candidate = [
                            'value' => $value,
                            'important' => $priority['important'],
                            'specificity' => $match['specificity'],
                            'order' => $declaration['start'],
                        ];
                        if (self::cascadeCandidateWins($candidate, $winners[$resolvedProperty] ?? null)) {
                            $winners[$resolvedProperty] = $candidate;
                        }
                    }
                }
            }

            if (!in_array($property, ['order', 'all'], true)) {
                continue;
            }
            $priority = CssChecks::splitDeclarationPriority($declaration['value']);
            foreach (['identity' => $identity, 'navigation' => $navigation] as $key => $element) {
                $match = self::matchingSpecificity(
                    $declaration['context'],
                    $declaration['ancestors'],
                    $element,
                );
                if ($match['unprovable'] || ($match['specificity'] !== null && $scope === 'unprovable')) {
                    return 'desktop header item order cannot be proven across the CSS cascade';
                }
                if ($match['specificity'] === null) {
                    continue;
                }
                $candidate = [
                    'value' => $property === 'all' ? '0' : self::headerOrderValue($priority['value']),
                    'important' => $priority['important'],
                    'specificity' => $match['specificity'],
                    'order' => $declaration['start'],
                ];
                if (self::cascadeCandidateWins($candidate, $winners["{$key}-order"] ?? null)) {
                    $winners["{$key}-order"] = $candidate;
                }
            }
        }

        foreach ($required as $property => $value) {
            if (($winners[$property]['value'] ?? null) !== $value) {
                return 'header must use display:flex, flex-direction:row, flex-wrap:nowrap, '
                    . 'align-items:center, and justify-content:space-between throughout the desktop range';
            }
        }
        $identityOrder = $winners['identity-order']['value'] ?? '0';
        $navigationOrder = $winners['navigation-order']['value'] ?? '0';
        if ($identityOrder === '<unproven>'
            || $navigationOrder === '<unproven>'
            || (int) $identityOrder > (int) $navigationOrder
        ) {
            return 'header identity must remain before navigation throughout the desktop range';
        }
        return null;
    }

    private static function headerCriticalElementIssueAtViewport(
        string $css,
        DOMElement $element,
        string $label,
        float $viewport,
    ): ?string {
        /** @var array<string,array{value:string,important:bool,specificity:int,order:int}> $winners */
        $winners = [];
        $properties = [
            'display',
            'visibility',
            'opacity',
            'position',
            'transform',
            'animation',
            'animation-name',
            'all',
        ];
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            if ($declaration['kind'] !== 'style' || !$declaration['structurallySafe']) {
                continue;
            }
            $property = strtolower(trim($declaration['property']));
            if (!in_array($property, $properties, true)) {
                continue;
            }
            $scope = CssChecks::declarationScopeAtViewport($declaration['ancestors'], $viewport);
            if ($scope === 'inert') {
                continue;
            }
            $match = self::matchingSpecificity(
                $declaration['context'],
                $declaration['ancestors'],
                $element,
            );
            if ($match['unprovable'] || ($match['specificity'] !== null && $scope === 'unprovable')) {
                return "desktop {$label} visibility and flow cannot be proven across the CSS cascade";
            }
            if ($match['specificity'] === null) {
                continue;
            }
            $priority = CssChecks::splitDeclarationPriority($declaration['value']);
            foreach (self::headerCriticalValues($property, $priority['value']) as $resolved => $value) {
                $candidate = [
                    'value' => $value,
                    'important' => $priority['important'],
                    'specificity' => $match['specificity'],
                    'order' => $declaration['start'],
                ];
                if (self::cascadeCandidateWins($candidate, $winners[$resolved] ?? null)) {
                    $winners[$resolved] = $candidate;
                }
            }
        }

        $display = $winners['display']['value'] ?? '<default>';
        if (in_array($display, ['none', 'contents', '<unproven>'], true)) {
            return "desktop {$label} must remain a visible box in the header row";
        }
        if (($winners['visibility']['value'] ?? 'visible') !== 'visible') {
            return "desktop {$label} must remain visible in the header row";
        }
        $opacity = $winners['opacity']['value'] ?? '1';
        if ($opacity === '<unproven>' || (float) $opacity <= 0.0) {
            return "desktop {$label} must remain visible in the header row";
        }
        if (($winners['position']['value'] ?? 'static') !== 'static') {
            return "desktop {$label} must remain in normal header-row flow";
        }
        if (($winners['transform']['value'] ?? 'none') !== 'none') {
            return "desktop {$label} must not be transformed out of its header-row position";
        }
        if (($winners['animation-name']['value'] ?? 'none') !== 'none') {
            return "desktop {$label} must not animate away from the proven header-row layout";
        }
        return null;
    }

    /** @return array<string,string> */
    private static function headerCriticalValues(string $property, string $value): array
    {
        $value = strtolower(trim($value));
        $cssWide = in_array($value, ['initial', 'unset'], true);
        if ($property === 'all') {
            if (!$cssWide) {
                return [
                    'display' => '<unproven>',
                    'visibility' => '<unproven>',
                    'opacity' => '<unproven>',
                    'position' => '<unproven>',
                    'transform' => '<unproven>',
                    'animation-name' => '<unproven>',
                ];
            }
            return [
                'display' => '<default>',
                'visibility' => 'visible',
                'opacity' => '1',
                'position' => 'static',
                'transform' => 'none',
                'animation-name' => 'none',
            ];
        }
        if ($property === 'animation' || $property === 'animation-name') {
            return ['animation-name' => ($cssWide || $value === 'none') ? 'none' : '<active>'];
        }
        if ($property === 'opacity') {
            if ($cssWide) {
                return ['opacity' => '1'];
            }
            if (preg_match('/\A(?:\d+(?:\.\d+)?|\.\d+)\z/', $value) === 1) {
                return ['opacity' => $value];
            }
            if (preg_match('/\A(?:\d+(?:\.\d+)?|\.\d+)%\z/', $value) === 1) {
                return ['opacity' => (string) ((float) rtrim($value, '%') / 100)];
            }
            return ['opacity' => '<unproven>'];
        }
        $defaults = [
            'display' => '<default>',
            'visibility' => 'visible',
            'position' => 'static',
            'transform' => 'none',
        ];
        return [$property => $cssWide ? $defaults[$property] : $value];
    }

    /** @return array<string,string> */
    private static function headerLayoutValues(string $property, string $value): array
    {
        $value = strtolower(trim($value));
        if ($property === 'all') {
            return [
                'display' => '<unproven>',
                'flex-direction' => '<unproven>',
                'flex-wrap' => '<unproven>',
                'align-items' => '<unproven>',
                'justify-content' => '<unproven>',
            ];
        }
        if ($property === 'place-items') {
            $parts = CssValueSplitter::splitTopLevelWhitespace($value);
            return ['align-items' => $parts[0] ?? '<unproven>'];
        }
        if ($property !== 'flex-flow') {
            return [$property => $value];
        }

        $parts = CssValueSplitter::splitTopLevelWhitespace($value);
        if ($parts === []) {
            return ['flex-direction' => '<unproven>', 'flex-wrap' => '<unproven>'];
        }
        $direction = 'row';
        $wrap = 'nowrap';
        foreach ($parts as $part) {
            if (in_array($part, ['row', 'row-reverse', 'column', 'column-reverse'], true)) {
                $direction = $part;
                continue;
            }
            if (in_array($part, ['nowrap', 'wrap', 'wrap-reverse'], true)) {
                $wrap = $part;
                continue;
            }
            $direction = '<unproven>';
            $wrap = '<unproven>';
        }
        return ['flex-direction' => $direction, 'flex-wrap' => $wrap];
    }

    private static function headerOrderValue(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['initial', 'unset'], true)) {
            return '0';
        }
        return preg_match('/\A[+-]?\d+\z/', $value) === 1 ? $value : '<unproven>';
    }

    /**
     * Sample every interval where a simple min/max-width media query can
     * change truth. This proves the whole >=720px inline-navigation range,
     * rather than one convenient desktop screenshot width.
     *
     * @return list<float>
     */
    private static function headerLayoutViewports(string $css): array
    {
        $viewports = [720.0, 1366.0];
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            foreach ($declaration['ancestors'] as $ancestor) {
                if (preg_match_all(
                    '/\((?:min|max)-width\s*:\s*([+-]?(?:\d+(?:\.\d+)?|\.\d+))(px|r?em)?\s*\)/i',
                    $ancestor,
                    $matches,
                    PREG_SET_ORDER,
                ) < 1) {
                    continue;
                }
                foreach ($matches as $match) {
                    $boundary = (float) $match[1];
                    $unit = strtolower($match[2] ?? '');
                    if ($unit === 'em' || $unit === 'rem') {
                        $boundary *= 16.0;
                    } elseif ($unit === '' && $boundary !== 0.0) {
                        continue;
                    }
                    foreach ([$boundary - 0.01, $boundary, $boundary + 0.01] as $candidate) {
                        if ($candidate >= 720.0) {
                            $viewports[] = $candidate;
                        }
                    }
                }
            }
        }
        $viewports = array_values(array_unique(array_map(
            static fn (float $viewport): string => sprintf('%.2F', $viewport),
            $viewports,
        )));
        $viewports = array_map('floatval', $viewports);
        sort($viewports, SORT_NUMERIC);
        return $viewports;
    }

    /**
     * @param array{value:string,important:bool,specificity:int,order:int} $candidate
     * @param array{value:string,important:bool,specificity:int,order:int}|null $winner
     */
    private static function cascadeCandidateWins(array $candidate, ?array $winner): bool
    {
        if ($winner === null || $candidate['important'] !== $winner['important']) {
            return $winner === null || $candidate['important'];
        }
        if ($candidate['specificity'] !== $winner['specificity']) {
            return $candidate['specificity'] > $winner['specificity'];
        }
        return $candidate['order'] >= $winner['order'];
    }

    /** @param array<string,mixed> $parsed */
    private static function selectorSpecificity(array $parsed): int
    {
        $specificity = 0;
        $addCompound = static function (array $compound) use (&$addCompound, &$specificity): void {
            $specificity += count($compound['ids'] ?? []) * 100;
            $specificity += (count($compound['classes'] ?? []) + count($compound['attributes'] ?? [])) * 10;
            if (($compound['nth_child'] ?? null) !== null
                || ($compound['first_child'] ?? false)
                || ($compound['last_child'] ?? false)
            ) {
                $specificity += 10;
            }
            if (($compound['type'] ?? null) !== null) {
                $specificity++;
            }
            foreach ($compound['not'] ?? [] as $negated) {
                $addCompound($negated);
            }
        };
        foreach ($parsed['compounds'] ?? [] as $compound) {
            $addCompound($compound);
        }
        return $specificity;
    }

    /**
     * @param list<string> $ancestors
     * @return array{specificity:?int,unprovable:bool}
     */
    private static function matchingSpecificity(
        string $context,
        array $ancestors,
        DOMElement $element,
    ): array {
        $specificity = null;
        foreach (CssValueSplitter::splitTopLevel($context, [',']) as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (!($parsed['supported'] ?? false)) {
                if (self::selectorMayTargetElement($selector, $ancestors, $element)) {
                    return ['specificity' => null, 'unprovable' => true];
                }
                continue;
            }
            $match = CssSelectorMatcher::matches($element, $parsed, true);
            if (!($match['supported'] ?? false)) {
                if (self::selectorMayTargetElement($selector, $ancestors, $element)) {
                    return ['specificity' => null, 'unprovable' => true];
                }
                continue;
            }
            if (!($match['matches'] ?? false)) {
                continue;
            }
            if (($parsed['pseudo_state_suffix_span'] ?? null) !== null) {
                return ['specificity' => null, 'unprovable' => true];
            }
            $specificity = max($specificity ?? 0, self::selectorSpecificity($parsed));
        }
        return ['specificity' => $specificity, 'unprovable' => false];
    }

    /** @param list<string> $ancestors */
    private static function selectorMayTargetElement(
        string $selector,
        array $ancestors,
        DOMElement $element,
    ): bool
    {
        $source = $selector . ' ' . implode(' ', $ancestors);
        $tag = preg_quote(strtolower($element->tagName), '/');
        if (preg_match('/(?<![-_a-z0-9])' . $tag . '(?![-_a-z0-9])/i', $source) === 1) {
            return true;
        }
        $id = trim($element->getAttribute('id'));
        if ($id !== '' && preg_match('/#' . preg_quote($id, '/') . '(?![-_a-z0-9])/i', $source) === 1) {
            return true;
        }
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
            if ($class !== ''
                && preg_match('/\.' . preg_quote($class, '/') . '(?![-_a-z0-9])/i', $source) === 1
            ) {
                return true;
            }
        }
        return preg_match('/(?:\A|[\s>+~,])\*(?![-_a-z0-9])/i', $selector) === 1;
    }

    private static function headerRowItem(DOMElement $element, DOMElement $header): ?DOMElement
    {
        $item = $element;
        while ($item->parentNode instanceof DOMElement) {
            if ($item->parentNode->isSameNode($header)) {
                return $item;
            }
            $item = $item->parentNode;
        }
        return null;
    }

    /** @param list<array<string,mixed>> $sitePages */
    private static function pageLinkIssue(DOMXPath $xpath, array $sitePages): ?string
    {
        $expected = [];
        foreach ($sitePages as $page) {
            if (!is_array($page) || !empty($page['front']) || trim((string) ($page['path'] ?? '')) === '/') {
                continue;
            }
            $title = trim((string) ($page['title'] ?? ''));
            $path = trim((string) ($page['path'] ?? ''));
            if ($title !== '' && $path !== '') {
                $expected[] = ['title' => $title, 'path' => $path];
            }
        }

        $anchors = $xpath->query('/html/body/header//nav//a');
        if ($anchors->length !== count($expected)) {
            return 'header navigation must contain exactly one link for every inner SITE PAGES entry';
        }
        foreach ($expected as $index => $page) {
            $anchor = $anchors->item($index);
            if (!$anchor instanceof DOMElement
                || $anchor->getAttribute('href') !== $page['path']
                || self::visibleText($anchor->textContent) !== self::visibleText($page['title'])
            ) {
                return 'header navigation labels and destinations must match inner SITE PAGES exactly and in order';
            }
        }
        return null;
    }

    private static function visibleText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param list<array<string,mixed>> $sitePages */
    private static function designIssue(string $html, array $sitePages): ?string
    {
        if (trim($html) === '') {
            return 'document is empty';
        }
        if (
            preg_match(
                '/\A\s*<!doctype\s+html\s*>\s*<html(?=[\s>])[\s\S]*<\/html\s*>\s*\z/i',
                $html,
            ) !== 1
        ) {
            return 'document is not one complete HTML document';
        }
        foreach (['html', 'head', 'body'] as $tag) {
            if (
                self::sourceTagCount($html, $tag, false) !== 1
                || self::sourceTagCount($html, $tag, true) !== 1
            ) {
                return "document must contain one authored <{$tag}> element";
            }
        }

        $dom = Html::loadUtf8Html($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$dom instanceof DOMDocument || !$dom->documentElement instanceof DOMElement) {
            return 'document cannot be parsed as HTML';
        }
        if (
            strtolower($dom->documentElement->tagName) !== 'html'
            || $dom->getElementsByTagName('head')->length !== 1
            || $dom->getElementsByTagName('body')->length !== 1
        ) {
            return 'document shell is malformed';
        }

        $xpath = new DOMXPath($dom);
        if ($xpath->query('//comment()')->length !== 0) {
            return 'document contains HTML comments';
        }
        if ($xpath->query('//script|//link|//iframe')->length !== 0) {
            return 'document contains scripts or dependency elements';
        }
        if ($xpath->query('//picture|//source')->length !== 0) {
            return 'document contains responsive image dependency elements';
        }

        $body = $xpath->query('/html/body')->item(0);
        if (!$body instanceof DOMElement) {
            return 'document has no body';
        }
        $bodyElements = self::directElementNames($body);
        if ($bodyElements !== ['header', 'main']) {
            return 'body must contain header followed by main only';
        }
        if (self::hasDirectText($body)) {
            return 'body contains visitor text outside header and main';
        }
        if (
            $xpath->query('//header')->length !== 1
            || $xpath->query('/html/body/header')->length !== 1
        ) {
            return 'document must contain one direct body header';
        }
        $header = $xpath->query('/html/body/header')->item(0);
        if (!$header instanceof DOMElement) {
            return 'document must contain one direct body header';
        }
        if (
            $xpath->query('//nav')->length !== 1
            || $xpath->query('/html/body/header//nav')->length !== 1
        ) {
            return 'header must contain the only navigation';
        }
        $navigation = $xpath->query('/html/body/header//nav')->item(0);
        if (!$navigation instanceof DOMElement) {
            return 'header must contain the only navigation';
        }
        $pageLinkIssue = self::pageLinkIssue($xpath, $sitePages);
        if ($pageLinkIssue !== null) {
            return $pageLinkIssue;
        }
        $identityLinks = $xpath->query('/html/body/header//a[not(ancestor::nav) and @href="/"]');
        if ($identityLinks->length !== 1) {
            return 'header must contain one identity home link outside navigation';
        }
        $identity = $identityLinks->item(0);
        if (!$identity instanceof DOMElement) {
            return 'header must contain one identity home link outside navigation';
        }
        $identityItem = self::headerRowItem($identity, $header);
        $navigationItem = self::headerRowItem($navigation, $header);
        if ($identityItem === null
            || $navigationItem === null
            || $identityItem->isSameNode($navigationItem)
        ) {
            return 'header identity and navigation must occupy separate row items';
        }
        $rowItems = [];
        foreach ($header->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $rowItems[] = $child;
            }
        }
        if (count($rowItems) !== 2
            || !$rowItems[0]->isSameNode($identityItem)
            || !$rowItems[1]->isSameNode($navigationItem)
        ) {
            return 'header identity must be the first row item and navigation the last row item';
        }

        $main = $xpath->query('/html/body/main')->item(0);
        if (!$main instanceof DOMElement || $xpath->query('/html/body/main')->length !== 1) {
            return 'document must contain one direct body main';
        }
        if (self::hasDirectText($main)) {
            return 'main contains visitor text outside hero';
        }
        if (
            $xpath->query('/html/body/main/*')->length !== 1
            || $xpath->query('/html/body/main/section[@id="hero"]')->length !== 1
            || $xpath->query('//section')->length !== 1
        ) {
            return 'main must contain only section#hero';
        }
        if ($xpath->query('//footer')->length !== 0) {
            return 'document contains a footer';
        }

        $images = $xpath->query('//img');
        if ($images->length !== 1) {
            return 'document must contain exactly one image';
        }
        $image = $images->item(0);
        if (
            !$image instanceof DOMElement
            || $xpath->query('/html/body/main/section[@id="hero"]//img')->length !== 1
        ) {
            return 'only image must belong to hero';
        }
        if (preg_match(self::IMAGE_ALT_PATTERN, $image->getAttribute('alt')) !== 1) {
            return 'hero image alt does not match four-field AI_IMAGE contract';
        }
        foreach (['src', 'srcset', 'sizes'] as $attribute) {
            if ($image->hasAttribute($attribute)) {
                return "hero image must omit {$attribute}";
            }
        }

        if (self::headStyleContents($html) === null) {
            return 'document has no inline head style';
        }

        $styles = $xpath->query('/html/head/style');
        if ($styles->length !== 1 || $xpath->query('//style')->length !== 1) {
            return 'document must contain exactly one inline head style';
        }
        $css = (string) $styles->item(0)?->textContent;
        if (str_contains($css, '\\')) {
            return 'CSS contains escaped syntax that cannot be validated safely';
        }
        $cssInspection = self::inspectCss($css);
        if ($cssInspection['issue'] !== null) {
            return $cssInspection['issue'];
        }
        $widthIssue = self::cssWidthIssue(
            $cssInspection['code'],
            '--content-size',
            '800px',
        );
        if ($widthIssue !== null) {
            return $widthIssue;
        }
        $widthIssue = self::cssWidthIssue(
            $cssInspection['code'],
            '--wide-size',
            '1280px',
        );
        if ($widthIssue !== null) {
            return $widthIssue;
        }
        $layoutIssue = self::headerLayoutIssue(
            $cssInspection['code'],
            $header,
            $identity,
            $navigation,
            $identityItem,
            $navigationItem,
        );
        if ($layoutIssue !== null) {
            return $layoutIssue;
        }

        foreach ($xpath->query('//*[@*]') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if ($name === 'style') {
                    return 'CSS must appear only in the head style element';
                }
                if ($name === 'background') {
                    return 'element contains a legacy background fetch attribute';
                }
                if (
                    in_array(
                        $name,
                        [
                            'popover',
                            'popovertarget',
                            'popovertargetaction',
                            'command',
                            'commandfor',
                            'contenteditable',
                            'draggable',
                            'autofocus',
                        ],
                        true,
                    )
                ) {
                    return "element contains declarative behavior attribute {$name}";
                }
                if (str_starts_with($name, 'on')) {
                    return "element contains JavaScript attribute {$name}";
                }
                $schemeValue = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
                if (
                    str_starts_with($schemeValue, 'javascript:')
                    || str_starts_with($schemeValue, 'vbscript:')
                    || str_starts_with($schemeValue, 'data:text/html')
                ) {
                    return "element attribute {$name} contains an executable URL";
                }
                if (preg_match('~(?:^|[\s,])(?:https?:)?//~i', $value) === 1) {
                    return "element attribute {$name} contains an external URL";
                }
            }
        }

        return null;
    }

    /** @return array{code:string,issue:?string} */
    private static function inspectCss(string $css): array
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($css);
        $code = '';
        for ($offset = 0; $offset < $length;) {
            $insideString = $state['quote'] !== '';
            if (
                !$insideString
                && !$state['comment']
            ) {
                if (substr($css, $offset, 2) === '/*') {
                    return ['code' => $code, 'issue' => 'CSS contains comments'];
                }
                if (self::cssAtRuleAt($css, $offset, 'import')) {
                    return ['code' => $code, 'issue' => 'CSS contains @import'];
                }
                if (self::cssAtRuleAt($css, $offset, 'font-face')) {
                    return ['code' => $code, 'issue' => 'CSS contains @font-face'];
                }
                if (self::cssResourceFunctionAt($css, $offset)) {
                    return ['code' => $code, 'issue' => 'CSS contains a resource-loading function'];
                }
            }

            $opensString = !$insideString
                && !$state['comment']
                && (($css[$offset] ?? '') === '"' || ($css[$offset] ?? '') === "'");
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            $next = $next ?? $offset + 1;
            $unitLength = $next - $offset;
            $code .= ($insideString || $opensString)
                ? str_repeat(' ', $unitLength)
                : substr($css, $offset, $unitLength);
            $offset = $next;
        }
        return ['code' => $code, 'issue' => null];
    }

    private static function cssAtRuleAt(string $css, int $offset, string $rule): bool
    {
        if (($css[$offset] ?? '') !== '@') {
            return false;
        }
        $start = $offset + 1;
        while (isset($css[$start]) && CssSyntaxScanner::isCssWhitespace($css[$start])) {
            $start++;
        }
        $ruleLength = strlen($rule);
        if (strncasecmp(substr($css, $start, $ruleLength), $rule, $ruleLength) !== 0) {
            return false;
        }
        $end = $start + $ruleLength;
        return !isset($css[$end]) || !self::isCssIdentifierByte($css[$end]);
    }

    private static function cssWidthIssue(string $css, string $property, string $required): ?string
    {
        $matched = preg_match_all(
            '~(?:\A|(?<=[;{]))[ \t\n\r\f]*'
                . preg_quote($property, '~')
                . '[ \t\n\r\f]*:[ \t\n\r\f]*([^;{}]+?)[ \t\n\r\f]*;~',
            $css,
            $matches,
        );
        if ($matched !== 1 || trim($matches[1][0] ?? '') !== $required) {
            return "CSS has wrong or missing {$property}";
        }
        return null;
    }

    private static function cssResourceFunctionAt(string $css, int $offset): bool
    {
        if ($offset > 0) {
            $previous = $css[$offset - 1];
            if (
                self::isCssIdentifierByte($previous)
                || $previous === '@'
                || $previous === '#'
            ) {
                return false;
            }
        }

        foreach (
            ['-webkit-image-set', 'image-set', 'cross-fade', 'element', 'paint', 'url', 'src', 'image']
            as $function
        ) {
            $nameLength = strlen($function);
            if (strncasecmp(substr($css, $offset, $nameLength), $function, $nameLength) !== 0) {
                continue;
            }
            $end = $offset + $nameLength;
            if (isset($css[$end]) && self::isCssIdentifierByte($css[$end])) {
                continue;
            }
            while (isset($css[$end]) && CssSyntaxScanner::isCssWhitespace($css[$end])) {
                $end++;
            }
            if (($css[$end] ?? '') === '(') {
                return true;
            }
        }
        return false;
    }

    private static function isCssIdentifierByte(string $byte): bool
    {
        $ord = ord($byte);
        return ($ord >= ord('0') && $ord <= ord('9'))
            || ($ord >= ord('A') && $ord <= ord('Z'))
            || ($ord >= ord('a') && $ord <= ord('z'))
            || $byte === '-'
            || $byte === '_'
            || $ord >= 0x80;
    }

    private static function sourceTagCount(string $html, string $tag, bool $closing): int
    {
        $slash = $closing ? '\\/' : '';
        $matched = preg_match_all('/<\s*' . $slash . preg_quote($tag, '/') . '(?=[\s>])/i', $html);
        return is_int($matched) ? $matched : 0;
    }

    /** @return list<string> */
    private static function directElementNames(DOMElement $element): array
    {
        $names = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = strtolower($child->tagName);
            }
        }
        return $names;
    }

    private static function hasDirectText(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $sitePages */
    private static function safeScaffold(string $siteSpec, string $brief, array $sitePages): string
    {
        $decoded = json_decode($siteSpec, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $name = self::safeText((string) ($decoded['name'] ?? 'Site Preview'), 'Site Preview');
        $title = self::safeText((string) ($decoded['title'] ?? $name), $name);
        $description = self::safeText(
            (string) ($decoded['description'] ?? $brief),
            'A focused introduction to this site.',
        );
        $navigation = '';
        foreach ($sitePages as $page) {
            if (!is_array($page) || !empty($page['front']) || trim((string) ($page['path'] ?? '')) === '/') {
                continue;
            }
            $pageTitle = trim((string) ($page['title'] ?? ''));
            $pagePath = trim((string) ($page['path'] ?? ''));
            if ($pageTitle === '' || $pagePath === '') {
                continue;
            }
            $navigation .= '<a href="' . self::escape($pagePath) . '">' . self::escape($pageTitle) . '</a>';
        }

        return '<!doctype html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
            . 'body { margin: 0; font-family: system-ui, sans-serif; }'
            . 'header, main { width: min(100% - 2rem, var(--wide-size)); margin-inline: auto; }'
            . 'header { display: flex; flex-direction: row; flex-wrap: nowrap; align-items: center; justify-content: space-between; }'
            . '#hero { min-height: 70vh; display: grid; align-content: center; gap: 1rem; }'
            . 'img { display: block; max-width: 100%; height: auto; }</style>'
            . '</head><body><header><a class="site-identity" href="/">'
            . self::escape($name)
            . '</a><nav aria-label="Primary">' . $navigation . '</nav></header>'
            . '<main><section id="hero"><h1>'
            . self::escape($title)
            . '</h1><p>'
            . self::escape($description)
            . '</p><img alt="AI_IMAGE: A carefully framed editorial scene representing the site subject in balanced natural light | homepage hero beside the primary headline | photorealistic | landscape">'
            . '</section></main></body></html>';
    }

    private static function safeText(string $value, string $fallback): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $value !== '' ? $value : $fallback;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
    }

    /**
     * @param list<array<string,mixed>> $sitePages
     * @param list<string> $warnings
     * @return array{html:string,issue:?string}
     */
    private static function recover(string $html, array $sitePages, array &$warnings): array
    {
        $candidate = $html;
        if (str_contains($candidate, '<!--')) {
            $stripped = self::stripHtmlComments($candidate);
            if ($stripped !== $candidate) {
                $warnings[] = 'malformed_design file design/preview.html block_path document '
                    . 'authored_value ' . self::warningValue('document contains HTML comments')
                    . ' delivered_value removed disposition removed';
                $candidate = $stripped;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $issue = self::designIssue($candidate, $sitePages);
            if ($issue === null) {
                return ['html' => $candidate, 'issue' => null];
            }
            $next = self::recoverOnce($candidate, $issue);
            if ($next === null || $next === $candidate) {
                return ['html' => $candidate, 'issue' => $issue];
            }
            $warnings[] = 'malformed_design file design/preview.html block_path document '
                . 'authored_value ' . self::warningValue($issue)
                . ' delivered_value removed disposition removed';
            $candidate = $next;
        }
        return ['html' => $candidate, 'issue' => self::designIssue($candidate, $sitePages)];
    }

    private static function recoverOnce(string $html, string $issue): ?string
    {
        if ($issue === 'document is not one complete HTML document') {
            return self::trimToCompleteDocument($html);
        }
        if ($issue === 'document contains HTML comments') {
            return self::stripHtmlComments($html);
        }
        if ($issue === 'document contains scripts or dependency elements') {
            $next = preg_replace(
                '/<(script|iframe|link)\b[^>]*>.*?<\/\1\s*>/is',
                '',
                $html,
            ) ?? $html;
            return preg_replace('/<(script|iframe|link)\b[^>]*\/?>/i', '', $next) ?? $next;
        }
        if ($issue === 'document contains responsive image dependency elements') {
            $next = preg_replace_callback(
                '/<picture\b[^>]*>.*?<\/picture\s*>/is',
                static function (array $match): string {
                    return preg_match('/<img\b[^>]*>/i', $match[0], $img) === 1 ? $img[0] : '';
                },
                $html,
            ) ?? $html;
            $next = preg_replace('/<source\b[^>]*\/?>/i', '', $next) ?? $next;
            return preg_replace('/<\/source\s*>/i', '', $next) ?? $next;
        }
        return null;
    }

    private static function trimToCompleteDocument(string $html): ?string
    {
        $html = CodeFences::strip($html);
        if (preg_match('/<!doctype\s+html\s*>/i', $html, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = (int) $open[0][1];
        if (preg_match('/<html(?=[\s>])/i', $html, $htmlTag, PREG_OFFSET_CAPTURE, $start) !== 1) {
            return null;
        }
        $close = strripos($html, '</html>');
        if ($close === false || $close < (int) $htmlTag[0][1]) {
            return null;
        }
        $gt = strpos($html, '>', $close);
        if ($gt === false) {
            return null;
        }
        $trimmed = substr($html, $start, $gt + 1 - $start);
        if (
            preg_match(
                '/\A\s*<!doctype\s+html\s*>\s*<html(?=[\s>])[\s\S]*<\/html\s*>\s*\z/i',
                $trimmed,
            ) !== 1
        ) {
            return null;
        }
        return $trimmed;
    }

    private static function stripHtmlComments(string $html): string
    {
        return preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    }

    private static function degradedWarning(
        string $authored,
        string $scaffold,
        string $issue,
        ?string $repairIssue = null,
    ): string {
        $warning = 'malformed_design file design/preview.html block_path document '
            . 'authored_value ' . self::warningValue($authored)
            . ' delivered_value safe scaffold (' . strlen($scaffold) . ' bytes) '
            . 'disposition degraded; defect ' . self::warningValue($issue);
        if ($repairIssue !== null && $repairIssue !== '') {
            $warning .= '; repair_defect ' . self::warningValue($repairIssue);
        }
        return $warning;
    }

    private static function warningValue(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = mb_scrub($value, 'UTF-8');
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '(empty)';
        }
        if (strlen($value) > 320) {
            return mb_strcut($value, 0, 317, 'UTF-8') . '...';
        }
        return $value;
    }
}
