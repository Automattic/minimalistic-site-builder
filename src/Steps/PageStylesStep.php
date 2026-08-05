<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\SiteBuild\CssContrastAdjuster;
use Automattic\SiteBuild\CssContrastCheck;
use Automattic\SiteBuild\CssScrub;
use Automattic\SiteBuild\Html;
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
 * In explicit HTML-first composition mode, this step deterministically merges
 * scrubbed design/site.css bytes, each delivered nonfailed page artifact's
 * data-page-css contents, and optional scrubbed transformer-carried CSS. It
 * checks and adjusts only that merged tail against delivered markup before
 * appending it; existing scaffold CSS and all source artifacts stay untouched.
 * This path never asks the model.
 *
 * In legacy composition mode, the step reads designDirection.json +
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

    private const PAGE_ARTIFACT_MAP = 'design/page-artifact-map.json';

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

    /**
     * Deterministic wrap policy for the HTML-first path. Display headlines set
     * at hero scale are where a browser's hyphenation or an inherited
     * word-break splits a word across lines ("SQUIRR·EL"); headings wrap only
     * at spaces and never split a token. overflow-wrap:break-word is the
     * last-resort escape hatch for body copy only (p, li), where a single
     * unbreakable token like a URL would otherwise overflow its container.
     * word-break:break-all is deliberately absent everywhere.
     */
    public const WORD_WRAP_CSS = <<<'CSS'
/* Wrap at spaces only — never split a word mid-token. */
body,
h1, h2, h3, h4, h5, h6,
p, li, dt, dd, blockquote, figcaption, caption, th, td,
.wp-block-heading,
.wp-block-post-title,
.wp-block-button__link {
  hyphens: none;
  -webkit-hyphens: none;
  word-break: normal;
  overflow-wrap: normal;
}

/* Body copy may break a lone overlong token (a URL); headings never do. */
p, li {
  overflow-wrap: break-word;
  word-break: normal;
}
CSS;

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
        private bool $htmlFirst = false,
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
        $reads = [
            'pages.json',
            'theme/theme.json',
            'theme/style.css',
            'designDirection.json',
            'theme/parts/*',
            'theme/templates/*',
        ];
        if ($this->htmlFirst) {
            $reads[] = self::PAGE_ARTIFACT_MAP;
            $reads[] = 'design/*';
            $reads[] = 'plugin/pages/*';
        }

        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: $reads,
            writes: ['theme/style.css', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        if ($this->htmlFirst) {
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
        $sectionRootIds = self::sectionRootIds($project, $warnings);

        $siteCss = self::scrubAndNeutralizeChunk(
            $project->readText(TransformArtifacts::SITE_CSS),
            TransformArtifacts::SITE_CSS,
            $sectionRootIds,
            $warnings,
        );
        if ($siteCss !== '') {
            $chunks[] = $siteCss;
        }

        foreach (self::deliveredDesignSources($project) as $source) {
            $html = $project->readText($source);
            foreach (self::pageCssChunks($html) as $index => $pageCss) {
                $css = self::scrubAndNeutralizeChunk(
                    $pageCss,
                    $source . ' style[data-page-css]#' . ($index + 1),
                    $sectionRootIds,
                    $warnings,
                );
                if ($css !== '') {
                    $chunks[] = $css;
                }
            }
        }

        if ($project->exists(TransformArtifacts::CARRIED_CSS)) {
            $carriedCss = self::scrubAndNeutralizeChunk(
                $project->readText(TransformArtifacts::CARRIED_CSS),
                TransformArtifacts::CARRIED_CSS,
                $sectionRootIds,
                $warnings,
            );
            if ($carriedCss !== '') {
                $chunks[] = $carriedCss;
            }
        }

        $project->addWarnings('page-styles', $warnings);
        $design = implode("\n", $chunks);
        $markup = self::deliveredMarkup($project);
        // Contrast is a judgment on the DESIGN's colors: the wrap policy has
        // none, and including it would only add unverified-selector findings.
        $design = CssContrastAdjuster::apply(
            $project,
            'theme/style.css',
            $design,
            $markup,
            CssContrastCheck::check($design, $markup),
        );
        // Wrap policy first, so a design that deliberately hyphenates still
        // wins; it ships even when the design contributed no CSS at all.
        $tail = self::WORD_WRAP_CSS . "\n" . $design;
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

    /**
     * Remove inline-axis padding only from selectors whose final subject is a
     * delivered page section root. Scrubbing stays first so unsafe generated
     * declarations never reach this structural pass. A malformed stylesheet
     * keeps its scrubbed pre-neutralization bytes and records the degradation.
     *
     * @param array<string,true> $sectionRootIds
     * @param list<string>       $warnings
     */
    private static function scrubAndNeutralizeChunk(
        string $css,
        string $source,
        array $sectionRootIds,
        array &$warnings,
    ): string {
        $css = self::scrubChunk($css, $source, $warnings);
        if ($css === '' || $sectionRootIds === []) {
            return $css;
        }

        $error = null;
        $rewritten = self::rewriteRuleList($css, $sectionRootIds, $error);
        if ($rewritten !== null) {
            return $rewritten;
        }

        $warnings[] = sprintf(
            'source=%s; block_path=stylesheet; authored_value=%s; '
                . 'delivered_value=pre-neutralization scrubbed CSS; '
                . 'disposition=retained malformed CSS; reason=%s',
            $source,
            self::warningValue(strlen($css) > 320 ? substr($css, 0, 317) . '...' : $css),
            self::warningValue($error ?? 'unknown CSS parse failure'),
        );
        return $css;
    }

    /**
     * Final content-plugin page roots are the sole scope authority. Source
     * design HTML is intentionally excluded: failed or stale source sections
     * must not widen the delivered CSS mutation boundary.
     *
     * @param list<string> $warnings
     * @return array<string,true>
     */
    private static function sectionRootIds(Project $project, array &$warnings): array
    {
        $files = glob($project->pluginPath('pages') . '/*.html') ?: [];
        sort($files, SORT_STRING);
        $ids = [];
        foreach ($files as $file) {
            $markup = @file_get_contents($file);
            if ($markup === false) {
                throw new \RuntimeException("Could not read file: {$file}");
            }
            $dom = Html::loadUtf8Html(
                '<!doctype html><html><body id="page-styles-page-root">'
                    . $markup
                    . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            if (!$dom instanceof \DOMDocument) {
                $relative = str_starts_with($file, $project->root . '/')
                    ? substr($file, strlen($project->root) + 1)
                    : $file;
                $warnings[] = 'source=' . $relative
                    . '; block_path=page root; authored_value=unparseable final page markup; '
                    . 'delivered_value=no section-root CSS scope from this page; '
                    . 'disposition=retained CSS unchanged for unknown roots';
                continue;
            }
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('/html/body[@id="page-styles-page-root"]/section[@id]') ?: [] as $section) {
                if (!$section instanceof \DOMElement) {
                    continue;
                }
                $id = trim($section->getAttribute('id'));
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }
        return $ids;
    }

    /**
     * Rewrite a stylesheet rule-list, recursing through grouping at-rules.
     * Returns null on syntax this bounded transformer cannot prove safe.
     *
     * @param array<string,true> $sectionRootIds
     */
    private static function rewriteRuleList(
        string $css,
        array $sectionRootIds,
        ?string &$error,
    ): ?string {
        $length = strlen($css);
        $offset = 0;
        $statementStart = 0;
        $out = '';
        $state = CssSyntaxScanner::state();

        while ($offset < $length) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $css[$offset];
            if ($topLevel && $byte === '}') {
                $error = "unexpected closing brace at byte {$offset}";
                return null;
            }
            if ($topLevel && $byte === ';') {
                $statement = substr($css, $statementStart, $offset + 1 - $statementStart);
                if (!self::isTriviaOrAtRuleStatement($statement)) {
                    $error = "unexpected top-level statement at byte {$statementStart}";
                    return null;
                }
                $out .= $statement;
                $offset++;
                $statementStart = $offset;
                $state = CssSyntaxScanner::state();
                continue;
            }
            if ($topLevel && $byte === '{') {
                $close = self::matchingBrace($css, $offset, $error);
                if ($close === null) {
                    return null;
                }
                $prelude = substr($css, $statementStart, $offset - $statementStart);
                $body = substr($css, $offset + 1, $close - $offset - 1);
                $atRule = self::atRuleName($prelude);
                if ($atRule !== null) {
                    if (in_array(
                        $atRule,
                        ['media', 'supports', 'container', 'layer', 'scope', 'document', 'starting-style'],
                        true,
                    )) {
                        $body = self::rewriteRuleList($body, $sectionRootIds, $error);
                        if ($body === null) {
                            return null;
                        }
                    }
                    $out .= $prelude . '{' . $body . '}';
                } else {
                    $rule = self::rewriteQualifiedRule($prelude, $body, $sectionRootIds, $error);
                    if ($rule === null) {
                        return null;
                    }
                    $out .= $rule;
                }
                $offset = $close + 1;
                $statementStart = $offset;
                $state = CssSyntaxScanner::state();
                continue;
            }

            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                $error = "invalid CSS escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }

        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated CSS string, comment, or function';
            return null;
        }
        $tail = substr($css, $statementStart);
        if (!self::isCssTrivia($tail)) {
            $error = "unterminated CSS rule at byte {$statementStart}";
            return null;
        }
        return $out . $tail;
    }

    private static function matchingBrace(
        string $css,
        int $open,
        ?string &$error,
    ): ?int {
        $length = strlen($css);
        $depth = 1;
        $state = CssSyntaxScanner::state();
        for ($offset = $open + 1; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $css[$offset];
            if ($topLevel && $byte === '{') {
                $depth++;
                $offset++;
                continue;
            }
            if ($topLevel && $byte === '}') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                $error = "invalid CSS escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        $error = "unclosed CSS block at byte {$open}";
        return null;
    }

    /** @param array<string,true> $sectionRootIds */
    private static function rewriteQualifiedRule(
        string $prelude,
        string $body,
        array $sectionRootIds,
        ?string &$error,
    ): ?string {
        [$leading, $selectorText] = self::leadingTriviaAndRest($prelude);
        $branches = self::splitSelectorList($selectorText, $error);
        if ($branches === null) {
            return null;
        }

        $rootBranches = [];
        $otherBranches = [];
        foreach ($branches as $branch) {
            $targetsRoot = self::selectorTargetsRoot($branch, $sectionRootIds, $error);
            if ($targetsRoot === null) {
                return null;
            }
            if ($targetsRoot) {
                $rootBranches[] = trim($branch);
            } else {
                $otherBranches[] = trim($branch);
            }
        }
        if ($rootBranches === []) {
            return $prelude . '{' . $body . '}';
        }

        $changed = false;
        $rootBody = self::rewriteRootDeclarations($body, $changed, $error);
        if ($rootBody === null) {
            return null;
        }
        if (!$changed) {
            return $prelude . '{' . $body . '}';
        }
        if ($otherBranches === []) {
            return $prelude . '{' . $rootBody . '}';
        }

        $indent = '';
        if (preg_match('/\n([ \t]*)\z/', $leading, $match) === 1) {
            $indent = $match[1];
        }
        return $leading
            . implode(', ', $rootBranches) . ' {' . $rootBody . '}'
            . "\n{$indent}"
            . implode(', ', $otherBranches) . ' {' . $body . '}';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function leadingTriviaAndRest(string $value): array
    {
        preg_match('/\A(?:(?:[ \t\r\n\f]+)|\/\*.*?\*\/)+/s', $value, $match);
        $leading = $match[0] ?? '';
        return [$leading, substr($value, strlen($leading))];
    }

    /** @return list<string>|null */
    private static function splitSelectorList(string $selectors, ?string &$error): ?array
    {
        $length = strlen($selectors);
        $state = CssSyntaxScanner::state();
        $start = 0;
        $branches = [];
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $selectors[$offset];
            if ($topLevel && ($byte === '{' || $byte === '}' || $byte === ';')) {
                $error = "invalid selector delimiter at byte {$offset}";
                return null;
            }
            if ($topLevel && $byte === ',') {
                $branch = substr($selectors, $start, $offset - $start);
                if (trim($branch) === '') {
                    $error = "empty selector branch at byte {$offset}";
                    return null;
                }
                $branches[] = $branch;
                $start = $offset + 1;
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($selectors, $offset, $state);
            if ($next === null) {
                $error = "invalid selector escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated selector string, comment, or function';
            return null;
        }
        $branch = substr($selectors, $start);
        if (trim($branch) === '') {
            $error = 'empty final selector branch';
            return null;
        }
        $branches[] = $branch;
        return $branches;
    }

    /**
     * A selector qualifies only when its final compound contains a delivered
     * root ID. IDs inside ancestors, attributes, or pseudo-class arguments do
     * not widen the mutation boundary.
     *
     * @param array<string,true> $sectionRootIds
     */
    private static function selectorTargetsRoot(
        string $selector,
        array $sectionRootIds,
        ?string &$error,
    ): ?bool {
        $length = strlen($selector);
        $state = CssSyntaxScanner::state();
        $flat = '';
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            if ($topLevel && substr($selector, $offset, 2) === '/*') {
                $end = strpos($selector, '*/', $offset + 2);
                if ($end === false) {
                    $error = 'unterminated selector comment';
                    return null;
                }
                $offset = $end + 2;
                continue;
            }
            $byte = $selector[$offset];
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ($next === null) {
                $error = "invalid selector escape or delimiter at byte {$offset}";
                return null;
            }
            if ($topLevel) {
                if ($byte === '\\' || $byte === '[' || $byte === '(' || $byte === '"' || $byte === "'") {
                    $flat .= 'X';
                } else {
                    $flat .= substr($selector, $offset, $next - $offset);
                }
            } elseif (CssSyntaxScanner::isTopLevel($state) && ($byte === ']' || $byte === ')')) {
                $flat .= 'X';
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated selector string, comment, or function';
            return null;
        }

        $compounds = preg_split('/(?:[ \t\r\n\f]+|[>+~]|\|\|)+/', trim($flat), -1, PREG_SPLIT_NO_EMPTY);
        $subject = $compounds === false || $compounds === [] ? '' : (string) end($compounds);
        if ($subject === '' || str_contains($subject, '::')) {
            return false;
        }
        if (preg_match('/:(?:before|after|first-letter|first-line)\b/i', $subject) === 1) {
            return false;
        }
        if (preg_match_all('/#([A-Za-z0-9_-]+)/', $subject, $matches) !== false) {
            foreach ($matches[1] as $id) {
                if (isset($sectionRootIds[$id])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function rewriteRootDeclarations(
        string $body,
        bool &$changed,
        ?string &$error,
    ): ?string {
        $length = strlen($body);
        $state = CssSyntaxScanner::state();
        $start = 0;
        $out = '';
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $body[$offset];
            if ($topLevel && ($byte === '{' || $byte === '}')) {
                $error = "nested rule in section-root declaration block at byte {$offset}";
                return null;
            }
            if ($topLevel && $byte === ';') {
                $segment = substr($body, $start, $offset - $start);
                $rewritten = self::rewriteRootDeclaration($segment, $error);
                if ($rewritten === null) {
                    return null;
                }
                $out .= $rewritten['text'] . ';';
                $changed = $changed || $rewritten['changed'];
                $start = $offset + 1;
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($body, $offset, $state);
            if ($next === null) {
                $error = "invalid declaration escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated declaration string, comment, or function';
            return null;
        }
        $rewritten = self::rewriteRootDeclaration(substr($body, $start), $error);
        if ($rewritten === null) {
            return null;
        }
        $changed = $changed || $rewritten['changed'];
        return $out . $rewritten['text'];
    }

    /** @return array{text:string,changed:bool}|null */
    private static function rewriteRootDeclaration(string $segment, ?string &$error): ?array
    {
        $colon = self::topLevelColon($segment, $error);
        if ($colon === null) {
            if (self::isCssTrivia($segment)) {
                return ['text' => $segment, 'changed' => false];
            }
            if ($error === null) {
                $error = 'declaration has no top-level colon';
            }
            return null;
        }

        $property = preg_replace('/\/\*.*?\*\//s', '', substr($segment, 0, $colon));
        $property = strtolower(trim((string) $property));
        if (!in_array($property, ['padding', 'padding-inline', 'padding-left', 'padding-right'], true)) {
            return ['text' => $segment, 'changed' => false];
        }

        [$leading] = self::leadingTriviaAndRest($segment);
        if ($property !== 'padding') {
            return ['text' => $leading, 'changed' => true];
        }

        $padding = self::paddingBlockValues(substr($segment, $colon + 1), $error);
        if ($padding === null) {
            return null;
        }
        [$top, $bottom, $important] = $padding;
        $indent = '';
        if (preg_match('/\n([ \t]*)\z/', $leading, $match) === 1) {
            $indent = $match[1];
        }
        $separator = str_contains($leading, "\n") ? "\n{$indent}" : ' ';
        return [
            'text' => $leading
                . "padding-top: {$top}{$important};{$separator}padding-bottom: {$bottom}{$important}",
            'changed' => true,
        ];
    }

    private static function topLevelColon(string $value, ?string &$error): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $value[$offset] === ':') {
                return $offset;
            }
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                $error = "invalid declaration escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated declaration prefix';
        }
        return null;
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function paddingBlockValues(string $raw, ?string &$error): ?array
    {
        $value = trim($raw);
        $important = '';
        $bang = self::topLevelBang($value, $error);
        if ($error !== null) {
            return null;
        }
        if ($bang !== null) {
            $suffix = substr($value, $bang);
            $normalized = preg_replace('/\/\*.*?\*\//s', '', $suffix);
            if (preg_match('/^!\s*important\s*$/i', (string) $normalized) !== 1) {
                $error = 'padding shorthand has invalid !important suffix';
                return null;
            }
            $important = ' !important';
            $value = rtrim(substr($value, 0, $bang));
        }

        $values = self::splitPaddingValues($value, $error);
        if ($values === null || count($values) < 1 || count($values) > 4) {
            $error ??= 'padding shorthand must contain one to four values';
            return null;
        }
        $top = $values[0];
        $bottom = match (count($values)) {
            1, 2 => $values[0],
            3, 4 => $values[2],
        };
        return [$top, $bottom, $important];
    }

    private static function topLevelBang(string $value, ?string &$error): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $value[$offset] === '!') {
                return $offset;
            }
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                $error = "invalid padding escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated padding value';
        }
        return null;
    }

    /** @return list<string>|null */
    private static function splitPaddingValues(string $value, ?string &$error): ?array
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        $current = '';
        $values = [];
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            if ($topLevel && substr($value, $offset, 2) === '/*') {
                if ($current !== '') {
                    $values[] = $current;
                    $current = '';
                }
                $end = strpos($value, '*/', $offset + 2);
                if ($end === false) {
                    $error = 'unterminated padding comment';
                    return null;
                }
                $offset = $end + 2;
                continue;
            }
            $byte = $value[$offset];
            if ($topLevel && CssSyntaxScanner::isCssWhitespace($byte)) {
                if ($current !== '') {
                    $values[] = $current;
                    $current = '';
                }
                $offset++;
                continue;
            }
            if ($topLevel && ($byte === ',' || $byte === '/' || $byte === '!')) {
                $error = "invalid top-level padding token {$byte}";
                return null;
            }
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                $error = "invalid padding escape or delimiter at byte {$offset}";
                return null;
            }
            $current .= substr($value, $offset, $next - $offset);
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated padding string, comment, or function';
            return null;
        }
        if ($current !== '') {
            $values[] = $current;
        }
        return $values;
    }

    private static function atRuleName(string $prelude): ?string
    {
        [, $rest] = self::leadingTriviaAndRest($prelude);
        return preg_match('/\A@([A-Za-z-]+)/', $rest, $match) === 1
            ? strtolower($match[1])
            : null;
    }

    private static function isTriviaOrAtRuleStatement(string $statement): bool
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $statement);
        $trimmed = trim((string) $withoutComments);
        return $trimmed === ';' || str_starts_with($trimmed, '@');
    }

    private static function isCssTrivia(string $value): bool
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $value);
        return trim((string) $withoutComments) === '';
    }

    private static function warningValue(string $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        return is_string($encoded) ? $encoded : '"unencodable CSS error"';
    }

    /** @return list<string> */
    private static function deliveredDesignSources(Project $project): array
    {
        $pages = $project->readJson('pages.json')['pages'] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new \RuntimeException('page-styles: pages.json has no delivered pages');
        }

        $artifactMap = self::pageArtifactMap($project, $pages);

        $sources = [];
        foreach ($pages as $page) {
            if (!is_array($page) || trim((string) ($page['slug'] ?? '')) === '') {
                throw new \RuntimeException('page-styles: pages.json contains a page without a slug');
            }
            $slug = (string) $page['slug'];
            $artifactSlug = $artifactMap[$slug];
            if ($project->exists("design/{$artifactSlug}.failed")) {
                continue;
            }
            $source = "design/{$artifactSlug}.html";
            $sources[$source] = true;
        }
        $sources = array_keys($sources);
        sort($sources, SORT_STRING);
        return $sources;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,string> semantic page slug => physical design basename
     */
    private static function pageArtifactMap(Project $project, array $pages): array
    {
        try {
            $map = $project->readJson(self::PAGE_ARTIFACT_MAP);
        } catch (\RuntimeException $error) {
            throw new \RuntimeException(
                'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                    . ': ' . $error->getMessage(),
                previous: $error,
            );
        }

        foreach ($map as $semanticSlug => $artifactSlug) {
            $semanticKey = is_string($semanticSlug) || is_int($semanticSlug)
                ? (string) $semanticSlug
                : '';
            if (
                $semanticKey === ''
                || !is_string($artifactSlug)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $artifactSlug) !== 1
            ) {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ': expected direct semantic-slug to physical-basename string map',
                );
            }
        }

        $resolved = [];
        $claimed = [];
        foreach ($pages as $page) {
            $semanticSlug = (string) ($page['slug'] ?? '');
            if (!array_key_exists($semanticSlug, $map)) {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ": missing semantic slug '{$semanticSlug}'",
                );
            }
            $artifactSlug = $map[$semanticSlug];
            if (!is_string($artifactSlug)) {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ": non-string physical basename for '{$semanticSlug}'",
                );
            }
            if (!empty($page['front']) && $artifactSlug !== 'home') {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ": front page '{$semanticSlug}' must map to 'home'",
                );
            }
            if (empty($page['front']) && in_array($artifactSlug, ['home', 'preview', 'home-body'], true)) {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ": inner page '{$semanticSlug}' maps to reserved basename '{$artifactSlug}'",
                );
            }
            if (isset($claimed[$artifactSlug])) {
                throw new \RuntimeException(
                    'page-styles: corrupt required artifact ' . self::PAGE_ARTIFACT_MAP
                        . ": physical basename '{$artifactSlug}' is shared by '{$claimed[$artifactSlug]}'"
                        . " and '{$semanticSlug}'",
                );
            }
            $claimed[$artifactSlug] = $semanticSlug;
            $resolved[$semanticSlug] = $artifactSlug;
        }
        return $resolved;
    }

    /** Final theme and content-plugin markup in deterministic path order. */
    private static function deliveredMarkup(Project $project): string
    {
        $files = $project->markupFiles();
        sort($files, SORT_STRING);

        $markup = '';
        foreach ($files as $file) {
            $markup .= "\n" . (string) file_get_contents($file);
        }
        return $markup;
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
