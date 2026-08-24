<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
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
 * Generated markup is untrusted. One malformed response gets one direct repair
 * request; a missing or still-invalid response degrades to a deterministic,
 * contract-valid scaffold instead of aborting the build.
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
            $siteSpec = $project->readText('siteSpec.json');
            $designDirection = $project->readText('designDirection.json');
            $prompt = $this->renderer->render('design-preview.md', [
                'brief' => $brief,
                'site_spec' => $siteSpec,
                'site_pages' => PagePlanStep::sitePagesList(PagePlanStep::flattenPages($siteSpecData)),
                'design_direction' => $designDirection,
            ]);

            try {
                $authored = $this->llm->complete(
                    $prompt,
                    $this->withOptions(['log_label' => 'design-preview']),
                );
            } catch (\RuntimeException $error) {
                $scaffold = self::safeScaffold($siteSpec, $brief);
                self::writePreview($project, $scaffold);
                $warnings[] = self::degradedWarning(
                    'initial LLM request failed: ' . $error->getMessage(),
                    $scaffold,
                );
                return;
            }

            $initialSanitizerWarnings = [];
            try {
                $candidate = self::sanitize(
                    $authored,
                    'initial preview generation',
                    $initialSanitizerWarnings,
                );
                $issue = self::designIssue($candidate);
            } catch (\RuntimeException $error) {
                $candidate = $authored;
                $issue = 'sanitizer failed: ' . $error->getMessage();
            }

            if ($issue === null) {
                self::writePreview($project, $candidate);
                array_push($warnings, ...$initialSanitizerWarnings);
                return;
            }

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
                $repairedIssue = self::designIssue($repaired);
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
                $scaffold = self::safeScaffold($siteSpec, $brief);
                self::writePreview($project, $scaffold);
                $warnings[] = self::degradedWarning(
                    $authored . '; repair failure: ' . $error->getMessage(),
                    $scaffold,
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
     * The header is one horizontal row (BIGR-872): identity at the start, nav
     * at the end. A `header` rule that turns the bar into a column stacks the
     * wordmark above the nav, which the prompt already forbids — this makes it
     * a rejection instead of a hope.
     *
     * Only the `header` element itself is judged, and only unconditionally: a
     * descendant may legitimately be a column, and so may the bar itself inside
     * a narrow-viewport media query. The defect is the desktop masthead.
     */
    private static function headerColumnIssue(string $css): ?string
    {
        $matched = preg_match_all(
            '/(?:^|[};])\s*([^{};]+)\{([^{}]*)\}/',
            $css,
            $rules,
            PREG_SET_ORDER,
        );
        if ($matched === false || $matched === 0) {
            return null;
        }
        foreach ($rules as $rule) {
            $declarations = strtolower((string) preg_replace('/\s+/', '', $rule[2]));
            if (!str_contains($declarations, 'flex-direction:column')) {
                continue;
            }
            foreach (explode(',', $rule[1]) as $selector) {
                $parts = preg_split('/[\s>+~]+/', trim($selector)) ?: [];
                $last = strtolower(trim((string) end($parts)));
                if (
                    $last === 'header'
                    || str_starts_with($last, 'header.')
                    || str_starts_with($last, 'header#')
                    || str_starts_with($last, 'header[')
                    || str_starts_with($last, 'header:')
                ) {
                    return 'header must be one horizontal row, not a column';
                }
            }
        }
        return null;
    }

    private static function designIssue(string $html): ?string
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
        if (
            $xpath->query('//nav')->length !== 1
            || $xpath->query('/html/body/header//nav')->length !== 1
        ) {
            return 'header must contain the only navigation';
        }
        // The prompt calls a populated nav mandatory; without this the rule is
        // model compliance only, and an empty header nav ships clean (BIGR-872).
        // The page list is not final at design-preview time, so completeness
        // against SITE PAGES is enforced later, on the block markup.
        if ($xpath->query('/html/body/header//nav//a')->length === 0) {
            return 'header navigation contains no links';
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
        $columnIssue = self::headerColumnIssue($cssInspection['code']);
        if ($columnIssue !== null) {
            return $columnIssue;
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

    private static function safeScaffold(string $siteSpec, string $brief): string
    {
        $decoded = json_decode($siteSpec, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $name = self::safeText((string) ($decoded['name'] ?? 'Site Preview'), 'Site Preview');
        $title = self::safeText((string) ($decoded['title'] ?? $name), $name);
        $description = self::safeText(
            (string) ($decoded['description'] ?? $brief),
            'A focused introduction to this site.',
        );

        return '<!doctype html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root { --content-size: 800px; --wide-size: 1280px; }'
            . 'body { margin: 0; font-family: system-ui, sans-serif; }'
            . 'header, main { width: min(100% - 2rem, var(--wide-size)); margin-inline: auto; }'
            . '#hero { min-height: 70vh; display: grid; align-content: center; gap: 1rem; }'
            . 'img { display: block; max-width: 100%; height: auto; }</style>'
            . '</head><body><header><nav aria-label="Primary"><a href="/">'
            . self::escape($name)
            . '</a></nav></header><main><section id="hero"><h1>'
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

    private static function degradedWarning(string $authored, string $scaffold): string
    {
        return 'malformed_design file design/preview.html block_path document '
            . 'authored_value ' . self::warningValue($authored)
            . ' delivered_value safe scaffold (' . strlen($scaffold) . ' bytes) '
            . 'disposition degraded';
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
