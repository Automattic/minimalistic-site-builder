<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\CssScrub;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * Step: merge HTML-first design CSS, with the LLM utility generator as the
 * legacy path.
 *
 * When design/site.css exists, this step deterministically appends its scrubbed
 * bytes, each delivered page artifact's data-page-css style contents, and the
 * optional scrubbed transformer-carried CSS. It never asks the model and never
 * mutates those source artifacts.
 *
 * Without design/site.css, the legacy path reads designDirection.json +
 * theme/theme.json + the final section markup (theme/parts/*.html and
 * theme/templates/*.html, after fix-blocks), then appends a small plain-CSS
 * utility appendix to theme/style.css.
 *
 * prompts/section.md documents a fixed vocabulary of utility classes (CLASSES
 * below) that sections MAY reference via "className" — structural devices
 * like overlap, masonry, and sticky sidebars that block attributes alone
 * cannot express.
 * Class names on group/columns blocks survive the block-fixer's re-serialization,
 * and style.css is never touched by the fixer, so this pairing is the one
 * `<style>`-free channel for real CSS. This step runs after fix-blocks, scans
 * the final markup for which documented classes actually appear, and asks the
 * model to implement exactly those, tuned to the design direction.
 *
 * The model's CSS is validated (validate()) before writing: every selector must
 * be scoped under a documented class, colors must come from theme preset custom
 * properties, and only @media at-rules are allowed. When validation fails on
 * declaration-level offences only (a raw-color shadow, a --motion-* override),
 * the offending declarations are dropped (dropOffendingDeclarations()) and the
 * rest of the appendix ships — one lost decoration beats every used utility
 * losing its CSS. Structural problems (unbalanced braces, disallowed at-rules,
 * unscoped selectors) still reject the whole appendix: it is logged and
 * skipped rather than failing the build — a utility class without its CSS
 * still renders as a plain block, so degrading (loudly) beats losing a
 * finished build at its final step over decorative styling.
 */
final class PageStylesStep implements Step
{
    use LlmOptions;

    /**
     * The documented utility-class vocabulary, each with its implementation
     * contract (injected into prompts/page-styles.md for the classes a build
     * actually uses). Keep the class list in sync with the "Layout utility
     * classes" section of prompts/section.md, where sections learn them.
     *
     * @var array<string,string> class name => behavior contract for the CSS
     */
    public const CLASSES = [
        'overlap-up'   => 'pulls the block upward over the preceding content: a negative margin-top (typically -3rem to -6rem) plus position:relative and a z-index so it layers above',
        'masonry-3'    => 'CSS-columns masonry on the container: columns:3 with a comfortable gap; direct children get break-inside:avoid, display:block and a bottom margin; drop to 2 columns below 1024px and 1 column below 600px via @media',
        'sticky-side'  => 'position:sticky with a top offset, applied only at desktop widths (@media (min-width: 782px)); align-self:flex-start so the column can stick',
    ];

    /** Hard ceiling on the appendix size; the prompt asks for under 80 lines. */
    private const MAX_LINES = 100;
    private const LOG_FILE = 'page-styles.log';
    private const MARKER = '/* Layout utilities — generated per-design by the page-styles step. */';
    private const RAW_COLOR_NAMES = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige',
        'bisque', 'black', 'blanchedalmond', 'blue', 'blueviolet', 'brown',
        'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral',
        'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue',
        'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey',
        'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange',
        'darkorchid', 'darkred', 'darksalmon', 'darkseagreen',
        'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise',
        'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey',
        'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia',
        'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green',
        'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred', 'indigo',
        'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen',
        'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
        'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey',
        'lightpink', 'lightsalmon', 'lightseagreen', 'lightskyblue',
        'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow',
        'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine',
        'mediumblue', 'mediumorchid', 'mediumpurple', 'mediumseagreen',
        'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
        'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose',
        'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive', 'olivedrab',
        'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen',
        'paleturquoise', 'palevioletred', 'papayawhip', 'peachpuff', 'peru',
        'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple', 'red',
        'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown',
        'seagreen', 'seashell', 'sienna', 'silver', 'skyblue', 'slateblue',
        'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan',
        'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white',
        'whitesmoke', 'yellow', 'yellowgreen',
    ];

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'page-styles';
    }

    public function label(): string
    {
        return 'Generate page styles';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'pages.json',
                'theme/theme.json',
                'theme/style.css',
                'designDirection.json',
                'theme/parts/*',
                'theme/templates/*',
            ],
            writes: ['theme/style.css', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        if ($project->exists(TransformArtifacts::SITE_CSS)) {
            self::mergeDeterministicStyles($project);
            return;
        }

        $used = self::usedClasses($project);
        if ($used === []) {
            echo "  no layout utility classes referenced; nothing to style\n";
            return;
        }

        $rendered = $this->renderer->render('page-styles.md', [
            'design_direction' => DesignDirectionStep::readFor($project),
            'theme_json'       => $project->readText('theme/theme.json'),
            'used_classes'     => self::classList($used),
        ]);
        $css = self::stripFences(trim(
            $this->llm->complete($rendered, $this->withOptions(['log_label' => $this->id()]))
        ));

        $problems = self::validate($css);
        if ($problems !== []) {
            // Before giving up on the whole appendix, drop the offending
            // declarations individually: a raw-color shadow or a --motion-*
            // override is one bad line, while a skipped appendix costs every
            // used utility its CSS (masonry renders as a stack, overlaps
            // disappear). Structural problems — unbalanced braces, disallowed
            // at-rules, unscoped selectors — survive the salvage and still
            // reject everything below.
            [$salvaged, $dropped] = self::dropOffendingDeclarations($css);
            if ($dropped === [] || self::validate($salvaged) !== []) {
                file_put_contents(
                    $project->logPath(self::LOG_FILE),
                    "REJECTED CSS:\n{$css}\n\nPROBLEMS:\n- " . implode("\n- ", $problems) . "\n"
                );
                echo '  page-styles: CSS rejected (' . count($problems)
                    . ' problem(s)); appendix skipped — see logs/' . self::LOG_FILE . "\n";
                $project->addWarnings($this->id(), [sprintf(
                    'model CSS appendix rejected (%s); layout utility class(es) %s ship without their CSS — see logs/%s',
                    implode('; ', $problems),
                    implode(', ', $used),
                    self::LOG_FILE,
                )]);
                return;
            }
            file_put_contents(
                $project->logPath(self::LOG_FILE),
                "SALVAGED CSS (offending declarations dropped):\n{$salvaged}\n\nDROPPED:\n- "
                . implode("\n- ", $dropped) . "\n"
            );
            echo '  page-styles: dropped ' . count($dropped)
                . ' offending declaration(s), kept the rest — see logs/' . self::LOG_FILE . "\n";
            $project->addWarnings($this->id(), array_map(
                static fn (string $declaration): string =>
                    "dropped offending CSS declaration `{$declaration}` from the page-styles appendix",
                $dropped,
            ));
            $css = $salvaged;
        }
        $project->writeText(
            'theme/style.css',
            rtrim($project->readText('theme/style.css')) . "\n\n" . self::MARKER . "\n" . $css . "\n"
        );
        echo '  styled: ' . implode(', ', $used) . "\n";
    }

    private static function mergeDeterministicStyles(Project $project): void
    {
        $chunks = [];
        $warnings = [];

        $siteCss = self::scrubChunk(
            $project->readText(TransformArtifacts::SITE_CSS),
            TransformArtifacts::SITE_CSS,
            $warnings,
        );
        if ($siteCss !== '') {
            $chunks[] = $siteCss;
        }

        foreach (self::deliveredDesignSources($project) as $source) {
            $html = $project->readText($source);
            foreach (self::pageCssChunks($html) as $index => $pageCss) {
                $css = self::scrubChunk(
                    $pageCss,
                    $source . ' style[data-page-css]#' . ($index + 1),
                    $warnings,
                );
                if ($css !== '') {
                    $chunks[] = $css;
                }
            }
        }

        if ($project->exists(TransformArtifacts::CARRIED_CSS)) {
            $carriedCss = self::scrubChunk(
                $project->readText(TransformArtifacts::CARRIED_CSS),
                TransformArtifacts::CARRIED_CSS,
                $warnings,
            );
            if ($carriedCss !== '') {
                $chunks[] = $carriedCss;
            }
        }

        $project->addWarnings('page-styles', $warnings);
        if ($chunks === []) {
            Narrator::write("  no safe deterministic page CSS to merge\n");
            return;
        }

        $tail = implode("\n", $chunks);
        $style = $project->readText('theme/style.css');
        if (str_ends_with($style, $tail)) {
            Narrator::write("  deterministic page CSS already merged\n");
            return;
        }

        $separator = $style !== '' && !str_ends_with($style, "\n") ? "\n" : '';
        $project->writeText('theme/style.css', $style . $separator . $tail);
        Narrator::write("  merged deterministic page CSS\n");
    }

    /**
     * @param list<string> $warnings
     */
    private static function scrubChunk(string $css, string $source, array &$warnings): string
    {
        $result = CssScrub::scrub($css);
        foreach ($result['removals'] as $removal) {
            $authored = json_encode(
                $removal['authored_value'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $warnings[] = sprintf(
                'source=%s; authored_value=%s; delivered_value=%s; disposition=%s',
                $source,
                $authored,
                $removal['delivered_value'],
                $removal['disposition'],
            );
        }
        return $result['css'];
    }

    /** @return list<string> */
    private static function deliveredDesignSources(Project $project): array
    {
        $pages = $project->readJson('pages.json')['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new \RuntimeException('page-styles: pages.json has no delivered pages');
        }

        $sources = [];
        foreach ($pages as $page) {
            if (!is_array($page) || trim((string) ($page['slug'] ?? '')) === '') {
                throw new \RuntimeException('page-styles: pages.json contains a page without a slug');
            }
            $source = !empty($page['front'])
                ? 'design/home.html'
                : 'design/' . (string) $page['slug'] . '.html';
            $sources[$source] = true;
        }
        $sources = array_keys($sources);
        sort($sources, SORT_STRING);
        return $sources;
    }

    /** @return list<string> */
    private static function pageCssChunks(string $html): array
    {
        $chunks = [];
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) {
                break;
            }
            if (substr($html, $start, 4) === '<!--') {
                $offset = self::htmlCommentEnd($html, $start);
                continue;
            }

            $tag = self::htmlTagAt($html, $start);
            if ($tag === null) {
                $offset = $start + 1;
                continue;
            }
            $offset = $tag['end'];
            if (
                $tag['closing']
                || !in_array($tag['name'], ['script', 'style', 'title', 'textarea'], true)
            ) {
                continue;
            }

            $close = self::rawTextCloseTag($html, $tag['name'], $offset);
            if ($close === null) {
                break;
            }
            if ($tag['name'] === 'style') {
                $opening = substr($html, $tag['start'], $tag['end'] - $tag['start']);
                if (
                    preg_match(
                        '/(?:^|\s)data-page-css(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?>)/i',
                        $opening,
                    ) === 1
                ) {
                    $chunks[] = substr($html, $tag['end'], $close['start'] - $tag['end']);
                }
            }
            $offset = $close['end'];
        }
        return $chunks;
    }

    /**
     * @return array{start:int,end:int,name:string,closing:bool}|null
     */
    private static function htmlTagAt(string $html, int $start): ?array
    {
        $length = strlen($html);
        $cursor = $start + 1;
        $closing = ($html[$cursor] ?? '') === '/';
        if ($closing) {
            $cursor++;
        }
        if ($cursor >= $length || preg_match('/^[A-Za-z]$/D', $html[$cursor]) !== 1) {
            return null;
        }

        $nameStart = $cursor;
        while (
            $cursor < $length
            && $html[$cursor] !== '/'
            && $html[$cursor] !== '>'
            && !str_contains(" \t\n\f\r", $html[$cursor])
        ) {
            $cursor++;
        }
        $name = substr($html, $nameStart, $cursor - $nameStart);
        if (preg_match('/^[A-Za-z][A-Za-z0-9:-]*$/D', $name) !== 1) {
            return null;
        }

        $quote = null;
        for ($end = $cursor; $end < $length; $end++) {
            $byte = $html[$end];
            if ($quote !== null) {
                if ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '>') {
                return [
                    'start' => $start,
                    'end' => $end + 1,
                    'name' => strtolower($name),
                    'closing' => $closing,
                ];
            }
        }
        return null;
    }

    /**
     * @return array{start:int,end:int,name:string,closing:bool}|null
     */
    private static function rawTextCloseTag(string $html, string $name, int $offset): ?array
    {
        $needle = "</{$name}";
        while (($start = stripos($html, $needle, $offset)) !== false) {
            $afterName = $start + strlen($needle);
            $delimiter = $html[$afterName] ?? '';
            if (
                $delimiter === '>'
                || $delimiter === '/'
                || ($delimiter !== '' && str_contains(" \t\n\f\r", $delimiter))
            ) {
                $tag = self::htmlTagAt($html, $start);
                if ($tag !== null && $tag['closing'] && $tag['name'] === $name) {
                    return $tag;
                }
            }
            $offset = $afterName;
        }
        return null;
    }

    private static function htmlCommentEnd(string $html, int $start): int
    {
        if (substr($html, $start, 5) === '<!-->') {
            return $start + 5;
        }
        if (substr($html, $start, 6) === '<!--->') {
            return $start + 6;
        }

        $standard = strpos($html, '-->', $start + 4);
        $bang = strpos($html, '--!>', $start + 4);
        if ($standard === false && $bang === false) {
            return strlen($html);
        }
        if ($bang !== false && ($standard === false || $bang < $standard)) {
            return $bang + 4;
        }
        return $standard + 3;
    }

    /**
     * Which documented utility classes the built site actually references,
     * scanning the final theme parts/templates AND the content plugin's pages
     * — content markup renders with the theme stylesheet, so a class used
     * only in a seeded page still needs its CSS here.
     *
     * @return string[]
     */
    public static function usedClasses(Project $project): array
    {
        $markup = '';
        foreach ($project->markupFiles() as $file) {
            $markup .= "\n" . (string) file_get_contents($file);
        }
        return self::classesIn($markup);
    }

    /**
     * The documented classes present in a blob of markup, in vocabulary order.
     * Pure — unit-testable.
     *
     * @return string[]
     */
    public static function classesIn(string $markup): array
    {
        $used = [];
        foreach (array_keys(self::CLASSES) as $class) {
            if (preg_match('/(?<![\w-])' . preg_quote($class, '/') . '(?![\w-])/', $markup) === 1) {
                $used[] = $class;
            }
        }
        return $used;
    }

    /**
     * Validate the model's CSS appendix against the constraints that keep it
     * safe to append to style.css: bounded size, selectors scoped under the
     * documented classes only, colors via theme preset custom properties, no
     * at-rules beyond @media, no url(). Returns problem strings; empty = valid.
     * Pure — unit-testable.
     *
     * @return string[]
     */
    public static function validate(string $css): array
    {
        $css = trim($css);
        if ($css === '') {
            return ['empty CSS'];
        }

        $problems = [];
        if (substr_count($css, "\n") + 1 > self::MAX_LINES) {
            $problems[] = 'more than ' . self::MAX_LINES . ' lines';
        }

        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        // Walk the depth instead of comparing totals: a leading stray `}`
        // balanced by a trailing open brace would leave the appendix with a
        // dangling open rule that swallows whatever is appended to style.css
        // after it (the custom-motion block ships later in the pipeline).
        $depth = 0;
        foreach (str_split($stripped) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth < 0) {
                break;
            }
        }
        if ($depth !== 0) {
            $problems[] = 'unbalanced braces';
        }
        if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $stripped) === 1) {
            $problems[] = 'raw hex color literal (use var(--wp--preset--color--…))';
        }
        if (preg_match('/\b(?:rgba?|hsla?)\s*\(/i', $stripped) === 1) {
            $problems[] = 'raw rgb()/hsl() color literal (use var(--wp--preset--color--…))';
        }
        foreach (self::rawNamedColorProblems($stripped) as $problem) {
            $problems[] = $problem;
        }
        // url() is not the only resource-bearing value form: image-set("…"),
        // image("…"), cross-fade() and friends fetch too (including with
        // vendor prefixes, which is why the match is a bare substring).
        if (preg_match('/(?:image-set|cross-fade|element|paint|url|src|image)\s*\(/i', $stripped) === 1) {
            $problems[] = 'resource-loading CSS functions (url(), image-set(), image(), cross-fade(), …) are not allowed';
        }
        if (preg_match('/--motion-[\w-]+\s*:/i', $stripped) === 1) {
            $problems[] = 'motion custom properties are profile-owned and cannot be overridden';
        }
        // Parse opacity values instead of pattern-matching literal zeros:
        // 0%, .0 and calc(0) hide content just as well as 0.
        if (preg_match_all('/(?<![-\w])opacity\s*:\s*([^;{}]+)/i', $stripped, $opacities) > 0) {
            foreach ($opacities[1] as $value) {
                if (CustomMotionStep::hidesContent($value)) {
                    $problems[] = 'opacity must be a plain value above zero (hidden content): ' . trim($value);
                }
            }
        }
        if (preg_match('/(?<![-\w])visibility\s*:\s*hidden\s*(?:!important\s*)?(?:;|$)/i', $stripped) === 1) {
            $problems[] = 'visibility:hidden hides generated content';
        }
        if (preg_match('/(?<![-\w])display\s*:\s*none\s*(?:!important\s*)?(?:;|$)/i', $stripped) === 1) {
            $problems[] = 'display:none hides generated content';
        }
        if (preg_match_all('/@([a-zA-Z-]+)/', $stripped, $atRules) > 0) {
            foreach (array_unique($atRules[1]) as $at) {
                if (strtolower($at) !== 'media') {
                    $problems[] = "disallowed at-rule: @{$at}";
                }
            }
        }

        // Every style rule's selector must be scoped under a documented class.
        // Drop @media preludes first so only rule selectors precede a '{'; the
        // stray closing braces that leaves behind don't affect the match.
        $allowed = implode('|', array_map(
            static fn (string $c): string => preg_quote($c, '/'),
            array_keys(self::CLASSES)
        ));
        $rules = (string) preg_replace('/@media[^{]*\{/i', '', $stripped);
        if (preg_match_all('/(?:^|[{}])\s*([^{};]+?)\s*\{/s', $rules, $m) > 0) {
            foreach ($m[1] as $selectorList) {
                foreach (explode(',', $selectorList) as $selector) {
                    $selector = trim($selector);
                    if (preg_match('/^\.(?:' . $allowed . ')(?![\w-])/', $selector) !== 1) {
                        $problems[] = "selector not scoped under a documented utility class: {$selector}";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Salvage pass for CSS that failed validate(): remove each declaration
     * that carries a declaration-level offence (raw color literal, resource-
     * loading function, --motion-* override, content-hiding value) and keep
     * the rest. Only rule bodies are touched — selectors, @media preludes and
     * brace structure pass through, so structural problems deliberately
     * survive into the re-validation and still reject the whole appendix.
     * Comments are stripped first (they could shelter braces from the body
     * matcher), so a salvaged appendix ships comment-free. Pure — unit-testable.
     *
     * @return array{0: string, 1: string[]} [salvaged CSS, dropped-declaration notes]
     */
    public static function dropOffendingDeclarations(string $css): array
    {
        $css = trim((string) preg_replace('~/\*.*?\*/~s', '', $css));
        $dropped = [];
        $salvaged = (string) preg_replace_callback(
            // Innermost brace pairs only: rule bodies, never an @media block
            // (its body contains braces and so never matches).
            '/\{([^{}]*)\}/s',
            static function (array $m) use (&$dropped): string {
                $kept = [];
                foreach (explode(';', $m[1]) as $declaration) {
                    if (trim($declaration) === '') {
                        continue;
                    }
                    $problem = self::declarationProblem($declaration);
                    if ($problem !== null) {
                        $dropped[] = trim((string) preg_replace('/\s+/', ' ', $declaration)) . " ({$problem})";
                        continue;
                    }
                    $kept[] = trim((string) preg_replace('/\s+/', ' ', $declaration));
                }
                return $kept === [] ? '{}' : "{\n    " . implode(";\n    ", $kept) . ";\n}";
            },
            $css
        );
        return [$salvaged, $dropped];
    }

    /**
     * The declaration-level offence in one `property: value` declaration, or
     * null when it is clean. Mirrors the declaration-level checks in
     * validate(); anything unparsable is dropped too — the salvage pass fails
     * closed.
     */
    private static function declarationProblem(string $declaration): ?string
    {
        if (preg_match('/^\s*([-\w]+)\s*:\s*(\S[\s\S]*)$/', $declaration, $m) !== 1) {
            return 'not a single property: value declaration';
        }
        $property = strtolower($m[1]);
        $value = $m[2];
        if (str_starts_with($property, '--motion-')) {
            return 'motion custom properties are profile-owned';
        }
        if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $value) === 1
            || preg_match('/\b(?:rgba?|hsla?)\s*\(/i', $value) === 1
            || self::rawNamedColorProblems("{$property}: {$value}") !== []
        ) {
            return 'raw color literal';
        }
        if (preg_match('/(?:image-set|cross-fade|element|paint|url|src|image)\s*\(/i', $value) === 1) {
            return 'resource-loading CSS function';
        }
        if ($property === 'opacity' && CustomMotionStep::hidesContent($value)) {
            return 'hides content';
        }
        if (($property === 'visibility' && preg_match('/^\s*hidden\b/i', $value) === 1)
            || ($property === 'display' && preg_match('/^\s*none\b/i', $value) === 1)
        ) {
            return 'hides content';
        }
        return null;
    }

    /** @return string[] */
    private static function rawNamedColorProblems(string $css): array
    {
        $properties = implode('|', [
            'color',
            'background',
            'background-color',
            'border',
            'border-top',
            'border-right',
            'border-bottom',
            'border-left',
            'border-color',
            'border-top-color',
            'border-right-color',
            'border-bottom-color',
            'border-left-color',
            'outline',
            'outline-color',
            'box-shadow',
            'text-shadow',
            'fill',
            'stroke',
            'caret-color',
            'accent-color',
            'text-decoration-color',
            'column-rule',
            'column-rule-color',
        ]);

        $problems = [];
        if (preg_match_all('/(?<![-\w])(' . $properties . ')\s*:\s*([^;{}]+)/i', $css, $decls, PREG_SET_ORDER) > 0) {
            foreach ($decls as $decl) {
                $property = strtolower($decl[1]);
                $value = strtolower($decl[2]);
                if (preg_match_all('/\b[a-z]+\b/', $value, $tokens) === 0) {
                    continue;
                }
                foreach (array_unique($tokens[0]) as $token) {
                    if (in_array($token, self::RAW_COLOR_NAMES, true)) {
                        $problems[] = "raw named color literal in {$property}: {$token}";
                    }
                }
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * The used classes rendered as the prompt's bullet list.
     *
     * @param string[] $used
     */
    private static function classList(array $used): string
    {
        return implode("\n", array_map(
            static fn (string $c): string => "- .{$c} — " . self::CLASSES[$c],
            $used
        ));
    }

    /** Strip a leading/trailing markdown code fence if the model added one. */
    private static function stripFences(string $text): string
    {
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
