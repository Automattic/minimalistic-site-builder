<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ContinuationRecovery;
use Automattic\SiteBuild\DesignMarkupSanitizer;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TruncatedGenerationException;

/**
 * Designs every non-front page against the exact homepage and stylesheet.
 *
 * The one batch is the only concurrent operation. Generated fragments and
 * their one semantic-repair attempt are normalized serially per page.
 */
final class InnerPagesDesignStep implements Step
{
    use LlmOptions;

    private const MAX_PAGE_CSS_BYTES = 4096;
    private const VOID_ELEMENTS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];
    private const RAW_TEXT_ELEMENTS = [
        'script',
        'style',
        'title',
        'textarea',
    ];
    private const DOCUMENT_ELEMENTS = ['html', 'head', 'body'];

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'inner-pages-design';
    }

    public function label(): string
    {
        return 'Design inner pages';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['siteSpec.json', 'design/site.css', 'design/home.html'],
            writes: ['design/*', 'warnings.json'],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $siteSpec = $project->readJson('siteSpec.json');
        $pages = array_values(array_filter(
            PagePlanStep::flattenPages($siteSpec),
            static fn (array $page): bool => !((bool) ($page['front'] ?? false)),
        ));
        if ($pages === []) {
            return;
        }

        $siteCss = $project->readText('design/site.css');
        $homeHtml = $project->readText('design/home.html');
        $warnings = [];
        $homeBody = self::homeReference($homeHtml);
        if ($homeBody === null) {
            $homeBody = $homeHtml;
            $warnings[] = 'malformed_design: design/home.html context inner-page cache reference; '
                . 'authored homepage without a source <main> or <body>; delivered whole document '
                . 'as the design reference; disposition retained';
        }
        $cachedPrefixes = [
            self::cacheLayer($siteCss),
            self::cacheLayer($homeBody),
        ];

        $requests = [];
        $renderedPrompts = [];
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            if (array_key_exists($slug, $requests)) {
                throw new \RuntimeException("inner-pages-design: duplicate page slug '{$slug}'");
            }
            $pageSpec = json_encode(
                $page,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR,
            );
            $prompt = $this->renderer->render('inner-page-design.md', [
                'page_spec' => $pageSpec,
                'site_css'  => '[cached prefix layer 1 contains the exact design/site.css bytes]',
                'home_body' => '[cached prefix layer 2 contains the exact homepage <main> or body bytes]',
            ]);
            $renderedPrompts[$slug] = $prompt;
            $requests[$slug] = $this->withOptions([
                'prompt'           => $prompt,
                'cached_prefixes'  => $cachedPrefixes,
            ]);
        }

        $batch = $this->llm->completeBatch($requests);
        foreach ($pages as $page) {
            $slug = (string) $page['slug'];
            $path = "design/{$slug}.html";
            if (!array_key_exists($slug, $batch->texts)) {
                throw new \RuntimeException(
                    "inner-pages-design: missing batch result for page '{$slug}'"
                );
            }

            foreach ($batch->notesFor($slug) as $note) {
                $warnings[] = "page_generation: {$path} context page {$slug}; authored batch "
                    . 'response; delivered best available response; disposition degraded: '
                    . self::warningValue($note);
            }

            $authored = $batch->texts[$slug];
            $sanitized = DesignMarkupSanitizer::sanitize(
                $authored,
                $path,
                "page {$slug} batch response",
                $warnings,
            );
            $sanitized = self::removeDocumentDeclarations(
                $sanitized,
                $path,
                "page {$slug} batch response",
                $warnings,
            );
            $sanitized = trim($sanitized);
            if (self::isValidFragment($sanitized)) {
                $project->writeText($path, $sanitized);
                continue;
            }

            $repairPrompt = $renderedPrompts[$slug]
                . "\n\nThe previous response was empty or malformed. Repair it once. Return the full "
                . "optional <style data-page-css> followed by one closed <main> fragment only.\n\n"
                . "<authored_fragment>\n{$authored}\n</authored_fragment>";
            try {
                $repair = ContinuationRecovery::completeToClose(
                    $this->llm,
                    $repairPrompt,
                    $this->withOptions(['cached_prefixes' => $cachedPrefixes]),
                    static fn (string $fragment): bool => self::isValidFragment(trim($fragment)),
                );
            } catch (TruncatedGenerationException $error) {
                $repair = $error->getPartialText();
                $warnings[] = "page_generation: {$path} context page {$slug} semantic repair; "
                    . 'authored truncated repair; delivered best partial repair for validation; '
                    . 'disposition degraded';
            }

            $repair = DesignMarkupSanitizer::sanitize(
                $repair,
                $path,
                "page {$slug} semantic repair",
                $warnings,
            );
            $repair = self::removeDocumentDeclarations(
                $repair,
                $path,
                "page {$slug} semantic repair",
                $warnings,
            );
            $repair = trim($repair);
            if (self::isValidFragment($repair)) {
                $project->writeText($path, $repair);
                $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
                    . self::warningValue($authored)
                    . "; delivered {$path} repaired fragment; disposition replaced";
                continue;
            }

            $failedPath = "design/{$slug}.failed";
            $project->writeText(
                $failedPath,
                "Inner-page generation failed after one semantic repair.\n",
            );
            $warnings[] = "malformed_design: {$path} context page {$slug}; authored "
                . self::warningValue($authored)
                . "; delivered {$failedPath} marker; disposition removed after one semantic repair";
        }

        $project->addWarnings($this->id(), $warnings);
    }

    private static function homeReference(string $html): ?string
    {
        foreach (['main', 'body'] as $element) {
            $source = self::sourceElement($html, $element);
            if ($source !== null) {
                return $source;
            }
        }
        return null;
    }

    private static function cacheLayer(string $content): string
    {
        return rtrim($content, "\r\n") . "\n\n";
    }

    private static function isValidFragment(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return false;
        }

        $stack = [];
        $roots = [];
        $mainCount = 0;
        foreach ($tokens as $token) {
            if ($token['type'] === 'declaration') {
                return false;
            }

            $name = $token['name'];
            if (in_array($name, self::DOCUMENT_ELEMENTS, true)) {
                return false;
            }
            if ($token['closing']) {
                if (in_array($name, self::VOID_ELEMENTS, true)) {
                    return false;
                }
                $last = array_key_last($stack);
                if ($last === null || $stack[$last]['name'] !== $name) {
                    return false;
                }
                $opening = array_pop($stack);
                if ($stack === []) {
                    $roots[] = [
                        'name'        => $name,
                        'start'       => $opening['start'],
                        'open_end'    => $opening['end'],
                        'close_start' => $token['start'],
                        'end'         => $token['end'],
                    ];
                }
                continue;
            }

            if ($name === 'main') {
                $mainCount++;
                if ($mainCount > 1) {
                    return false;
                }
            }
            if ($name === 'style' && $stack !== []) {
                return false;
            }
            if ($stack === [] && !in_array($name, ['style', 'main'], true)) {
                return false;
            }
            if (!in_array($name, self::VOID_ELEMENTS, true)) {
                $stack[] = $token;
            }
        }

        if ($stack !== [] || $mainCount !== 1) {
            return false;
        }
        $names = array_column($roots, 'name');
        if ($names !== ['main'] && $names !== ['style', 'main']) {
            return false;
        }
        if ($roots[0]['start'] !== 0 || $roots[count($roots) - 1]['end'] !== strlen($html)) {
            return false;
        }

        if ($names === ['style', 'main']) {
            $style = $roots[0];
            $styleOpen = substr($html, $style['start'], $style['open_end'] - $style['start']);
            if (
                preg_match(
                    '/(?:^|\s)data-page-css(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/i',
                    $styleOpen,
                ) !== 1
                || strlen(substr(
                    $html,
                    $style['open_end'],
                    $style['close_start'] - $style['open_end'],
                )) > self::MAX_PAGE_CSS_BYTES
                || !self::isHtmlWhitespace(substr(
                    $html,
                    $style['end'],
                    $roots[1]['start'] - $style['end'],
                ))
            ) {
                return false;
            }
        }
        return true;
    }

    private static function sourceElement(string $html, string $wanted): ?string
    {
        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return null;
        }

        $start = null;
        $depth = 0;
        foreach ($tokens as $token) {
            if (
                $token['type'] !== 'tag'
                || $token['name'] !== $wanted
                || in_array($wanted, self::VOID_ELEMENTS, true)
            ) {
                continue;
            }
            if (!$token['closing']) {
                if ($start === null) {
                    $start = $token['start'];
                }
                $depth++;
                continue;
            }
            if ($start === null || $depth === 0) {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $token['end'] - $start);
            }
        }
        return null;
    }

    /**
     * Doctypes and processing instructions are never part of a <main> fragment.
     *
     * The shared sanitizer owns hostile-markup handling. This shape normalizer
     * removes only declaration tokens that remain inert after that pass.
     *
     * @param list<string> $warnings
     */
    private static function removeDocumentDeclarations(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $tokens = self::sourceTokens($html);
        if ($tokens === null) {
            return $html;
        }

        $declarations = array_values(array_filter(
            $tokens,
            static fn (array $token): bool => $token['type'] === 'declaration',
        ));
        foreach (array_reverse($declarations) as $token) {
            $authored = substr($html, $token['start'], $token['end'] - $token['start']);
            $html = substr_replace(
                $html,
                '',
                $token['start'],
                $token['end'] - $token['start'],
            );
            $warnings[] = "malformed_design: {$path} context {$context}; authored "
                . self::warningValue($authored)
                . '; delivered removed; disposition removed document declaration';
        }
        return $html;
    }

    /**
     * @return list<array{type:string,start:int,end:int,name:string,closing:bool}>|null
     */
    private static function sourceTokens(string $html): ?array
    {
        $tokens = [];
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }

            if (substr($html, $start, 4) === '<!--') {
                $end = self::commentEnd($html, $start);
                if ($end === null) {
                    return null;
                }
                $offset = $end;
                continue;
            }
            if (substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?') {
                $end = strpos($html, '>', $start + 2);
                if ($end === false) {
                    return null;
                }
                $tokens[] = [
                    'type'    => 'declaration',
                    'start'   => $start,
                    'end'     => $end + 1,
                    'name'    => '',
                    'closing' => false,
                ];
                $offset = $end + 1;
                continue;
            }

            $tag = self::sourceTagAt($html, $start);
            if ($tag === null) {
                if (preg_match('/\G<\/?[A-Za-z]/', $html, $unused, 0, $start) === 1) {
                    return null;
                }
                $offset = $start + 1;
                continue;
            }
            $tokens[] = $tag;
            $offset = $tag['end'];
            if (
                !$tag['closing']
                && in_array($tag['name'], self::RAW_TEXT_ELEMENTS, true)
            ) {
                $close = self::rawTextCloseTag($html, $tag['name'], $offset);
                if ($close === null) {
                    return null;
                }
                $tokens[] = $close;
                $offset = $close['end'];
            }
        }
        return $tokens;
    }

    /**
     * @return array{type:string,start:int,end:int,name:string,closing:bool}|null
     */
    private static function sourceTagAt(string $html, int $start): ?array
    {
        if (
            preg_match(
                '/\G<(\/?)([A-Za-z][A-Za-z0-9:-]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/',
                $html,
                $match,
                0,
                $start,
            ) !== 1
        ) {
            return null;
        }

        $end = self::tagEnd($html, $start + strlen($match[0]));
        if ($end === null) {
            return null;
        }
        if ($match[1] === '/') {
            $tail = substr(
                $html,
                $start + strlen($match[0]),
                $end - $start - strlen($match[0]),
            );
            if (preg_match('/\A[\x09\x0A\x0C\x0D\x20]*>\z/D', $tail) !== 1) {
                return null;
            }
        }
        return [
            'type'    => 'tag',
            'start'   => $start,
            'end'     => $end,
            'name'    => strtolower($match[2]),
            'closing' => $match[1] === '/',
        ];
    }

    private static function tagEnd(string $html, int $offset): ?int
    {
        $quote = null;
        for ($length = strlen($html); $offset < $length; $offset++) {
            $char = $html[$offset];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $offset + 1;
            }
        }
        return null;
    }

    /**
     * @return array{type:string,start:int,end:int,name:string,closing:bool}|null
     */
    private static function rawTextCloseTag(string $html, string $name, int $offset): ?array
    {
        while (($start = stripos($html, '</' . $name, $offset)) !== false) {
            $tag = self::sourceTagAt($html, $start);
            if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                return $tag;
            }
            $offset = $start + 2;
        }
        return null;
    }

    private static function commentEnd(string $html, int $start): ?int
    {
        $offset = $start + 4;
        while (($end = strpos($html, '>', $offset)) !== false) {
            if (
                substr($html, max($start + 4, $end - 2), 2) === '--'
                || substr($html, max($start + 4, $end - 3), 3) === '--!'
            ) {
                return $end + 1;
            }
            $offset = $end + 1;
        }
        return null;
    }

    private static function isHtmlWhitespace(string $text): bool
    {
        return preg_match('/\A[\x09\x0A\x0C\x0D\x20]*\z/D', $text) === 1;
    }

    private static function warningValue(string $value): string
    {
        if (strlen($value) > 320) {
            $value = substr($value, 0, 317) . '...';
        }
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '"(unprintable)"';
    }
}
