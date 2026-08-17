<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\SiteBuild\CodeFences;
use Automattic\SiteBuild\CssChecks;
use Automattic\SiteBuild\CssContrastAdjuster;
use Automattic\SiteBuild\CssContrastCheck;
use Automattic\SiteBuild\CssScrub;
use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\MarkupScan;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PageScope;
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
 * optional scrubbed before-author transformer support CSS, scrubbed
 * design/site.css bytes, each delivered nonfailed page artifact's data-page-css
 * contents, and optional scrubbed after-author transformer support CSS. It
 * checks and adjusts only that merged tail against delivered markup before
 * appending it. A final block-axis-only author-rhythm tail reasserts
 * source-backed section-root spacing after page-planned presets. It also
 * restores the bounded inner margins that WordPress flow resets erase:
 * painted rhythm labels and trailing flow controls. Viewport-height stages
 * retain their existing box owner, and no inline-axis declaration is copied.
 * Existing scaffold CSS and all source artifacts stay untouched. This path
 * never asks the model.
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
 * declaration-level offences only (a raw-color shadow, a --motion-* override,
 * or a shape-owned corner radius), the offending declarations are dropped
 * (dropOffendingDeclarations()) and the rest of the appendix ships — one lost
 * decoration beats every used utility losing its CSS. Structural problems
 * (unbalanced braces, disallowed at-rules, unscoped selectors) still reject the
 * whole appendix: it is logged and skipped rather than failing the build — a
 * utility class without its CSS still renders as a plain block, so degrading
 * (loudly) beats losing a finished build at its final step over decorative
 * styling.
 */
final class PageStylesStep implements Step
{
    use LlmOptions;

    private const PAGE_ARTIFACT_MAP = 'design/page-artifact-map.json';
    private const ROOT_NONE = 0;
    private const ROOT_ALL = 1;
    private const ROOT_MIXED = 2;

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

    /**
     * core/table ships a default border on every cell (`.wp-block-table td/th
     * { border: 1px }`), boxing the whole grid. Authored designs use a plain
     * <table> — which has no cell borders — and add their own row rules, almost
     * always a bottom-only border. Zeroing the core default here, before the
     * design CSS, lets those authored rules be the only borders that show, so a
     * border-bottom renders as a row rule instead of a full cell grid. Tables
     * that do want full borders carry their own and override this.
     */
    public const TABLE_BORDER_RESET_CSS = <<<'CSS'
/* Let authored table CSS own cell borders; drop core/table's default grid. */
.wp-block-table td,
.wp-block-table th {
  border: 0;
}
CSS;

    /**
     * The browser default a design page's headings start from.
     *
     * theme.json's `styles.elements` typography is invented from the above-fold
     * preview, so it prescribes a family, a scale and a casing for every
     * heading level — including the levels a design page never styles. Those
     * headings are meant to render at the user-agent default, which is what the
     * design itself renders. `revert` rolls the author origin back to exactly
     * that. The selector weighs the same as theme.json's own `h1, h2, …` rule
     * and ships later, so it wins there; every authored rule below outranks it
     * in turn, by document order at equal weight or on specificity above it.
     */
    private const HEADING_BASELINE_DECLARATIONS = <<<'CSS'
  font: revert;
  letter-spacing: revert;
  text-transform: revert;
CSS;

    /**
     * Marks a chrome template-part reference whose part roots the same
     * landmark the reference itself wraps that part in. AssemblePagesStep
     * stamps it; NESTED_LANDMARK_CSS is what makes it mean something.
     */
    public const NESTED_LANDMARK_CLASS = 'chrome-nested-landmark';

    /**
     * A chrome template part renders its own <header>/<footer> wrapper, and
     * the transformed part sometimes roots that same landmark. The design's
     * own `header{…}` rule reaches this stylesheet verbatim as author CSS, so
     * it then matches BOTH boxes and every box-model declaration in it is
     * applied twice: the header's authored padding opens at double height and
     * its content sits at double the authored inset. The authored landmark
     * inside keeps that box model; the wrapper around it contributes none.
     *
     * Paint is deliberately left alone. It is idempotent across nested boxes,
     * and for a header whose top state is transparent this wrapper is the only
     * surface the authored background reaches. The class beats a bare element
     * selector on specificity, so cascade order does not matter here.
     */
    public const NESTED_LANDMARK_CSS = <<<'CSS'
/* Nested chrome landmark: the authored one inside owns the box model. */
.chrome-nested-landmark {
  padding: 0;
  border: 0;
  margin-block: 0;
}
CSS;

    /** Hard ceiling on the appendix size; the prompt asks for under 80 lines. */
    private const MAX_LINES = 100;
    private const LOG_FILE = 'page-styles.log';
    private const MARKER = '/* Layout utilities — generated per-design by the page-styles step. */';
    private const DETERMINISTIC_STYLE_MARKER =
        '/* Wrap at spaces only — never split a word mid-token. */';
    private const VERTICAL_RHYTHM_MARKER =
        '/* Preserve authored block-axis spacing against WordPress layout resets. */';
    private const AUTHOR_WIDTH_PIN_MARKER =
        '/* Pin an authored-width child to its authored content-column edge. */';
    /**
     * The distance from a constrained container's own content box to the
     * content column WordPress centres inside it. Computed rather than read
     * from a preset because the root padding differs per theme, and pinning to
     * the padding box instead lands 41px off at 1366 and diverges at every
     * other width. Falls back to the container edge when contentSize is absent.
     */
    private const CONTENT_COLUMN_INSET =
        'max(0px, (100% - var(--wp--style--global--content-size, 100%)) / 2)';
    /**
     * A grid item's block-axis margin adds to the track gap instead of to the
     * section edge, which is why the transformer zeroes it. Reinforcement is
     * selector-scoped rather than element-scoped, so the exclusion has to
     * travel in the selector. Adds no specificity: :not() takes its argument's,
     * and :where() is zero.
     */
    private const TRAILING_GRID_ITEM_GUARD =
        ':not(:where(.blocks-engine-css-owned-grid > *))';
    /** The four pseudo-elements CSS still spells with one colon. */
    private const LEGACY_PSEUDO_ELEMENTS = ['before', 'after', 'first-line', 'first-letter'];
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
        $css = CodeFences::strip(
            $this->llm->complete($rendered, $this->withOptions(['log_label' => $this->id()]))
        );

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
                    "theme/style.css page-styles appendix: authored declaration `{$declaration}`; "
                    . 'delivered removed; disposition dropped offending CSS declaration; see logs/'
                    . self::LOG_FILE,
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
        $beforeAuthorChunks = [];
        $authorChunks = [];
        $authorRhythmChunks = [];
        $afterAuthorChunks = [];
        $warnings = [];
        $sectionRootIds = self::sectionRoots($project, $warnings);

        if ($project->exists(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR)) {
            $beforeAuthorCss = self::scrubAndNeutralizeChunk(
                $project->readText(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR),
                TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR,
                $sectionRootIds,
                $warnings,
            );
            if ($beforeAuthorCss !== '') {
                $beforeAuthorChunks[] = $beforeAuthorCss;
            }
        }

        $siteCss = self::scrubAndNeutralizeChunk(
            $project->readText(TransformArtifacts::SITE_CSS),
            TransformArtifacts::SITE_CSS,
            $sectionRootIds,
            $warnings,
        );
        if ($siteCss !== '') {
            $authorChunks[] = $siteCss;
            $authorRhythmChunks[] = [
                'source' => TransformArtifacts::SITE_CSS,
                'css' => $siteCss,
            ];
        }

        $carriedPages = self::deliveredDesignPages($project);
        foreach ($carriedPages as $source => $pageSlug) {
            $html = $project->readText($source);
            foreach (self::pageCssChunks($html) as $index => $pageCss) {
                $origin = $source . ' style[data-page-css]#' . ($index + 1);
                $css = self::scrubAndNeutralizeChunk(
                    $pageCss,
                    $origin,
                    $sectionRootIds,
                    $warnings,
                );
                if ($css === '') {
                    continue;
                }
                $authorChunks[] = self::scopeChunkToPage($css, $pageSlug, $origin, $warnings);
            }
        }

        if ($project->exists(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR)) {
            $afterAuthorCss = self::scrubAndNeutralizeChunk(
                $project->readText(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR),
                TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR,
                $sectionRootIds,
                $warnings,
            );
            if ($afterAuthorCss !== '') {
                $afterAuthorChunks[] = $afterAuthorCss;
            }
        }

        $verticalRhythm = self::authoredVerticalRhythmCss(
            $project,
            $authorRhythmChunks,
            $sectionRootIds,
            $warnings,
        );
        // This resumable deterministic pass owns the complete current set;
        // replace stale receipts from prior tails instead of accumulating
        // warnings about CSS bytes no longer delivered.
        $project->replaceWarnings('page-styles', $warnings);
        $baseline = self::headingBaselineCss(array_values($carriedPages));
        $designChunks = array_merge(
            $beforeAuthorChunks,
            $authorChunks,
            $afterAuthorChunks,
        );
        if ($verticalRhythm !== '') {
            $designChunks[] = $verticalRhythm;
        }
        $authoredWidthPin = self::authoredWidthPinCss($sectionRootIds);
        if ($authoredWidthPin !== '') {
            $designChunks[] = $authoredWidthPin;
        }
        $design = implode("\n", $designChunks);
        $markup = self::deliveredMarkup($project);
        // Contrast is a judgment on the DESIGN's colors: the wrap policy has
        // none, and including it would only add unverified-selector findings.
        $findings = CssContrastCheck::check($design, $markup);
        // A resumed build hands this step the tail an earlier revision of the
        // code merged. Appending a second one leaves both in the cascade, and
        // a stale copy of a sibling page's rules is exactly the foreign CSS
        // this step exists to keep off the page — so the merge starts from the
        // bytes that were there before any merge, every time.
        $currentStyle = $project->readText('theme/style.css');
        $style = CssContrastAdjuster::restoreSupersededForegrounds(
            $project,
            'theme/style.css',
            self::withoutDeterministicStyles($currentStyle),
            $findings,
        );
        $design = CssContrastAdjuster::apply(
            $project,
            'theme/style.css',
            $design,
            $markup,
            $findings,
        );
        // Wrap policy first, so a design that deliberately hyphenates still
        // wins; the foundation ships even when the design contributed no CSS
        // at all. The nested-landmark reset joins the other foundation resets;
        // the heading baseline joins them rather than the design chunks,
        // because it declares no color and the contrast pass above has nothing
        // to say about it beyond an unverified-selector row.
        $tail = self::WORD_WRAP_CSS . "\n" . self::TABLE_BORDER_RESET_CSS . "\n"
            . self::NESTED_LANDMARK_CSS . "\n"
            . ($baseline === '' ? '' : $baseline . "\n") . $design;
        $separator = $style !== '' && !str_ends_with($style, "\n") ? "\n" : '';
        $merged = CssContrastAdjuster::reconcileHandledBackgroundCopies(
            $style . $separator . $tail,
            $design,
            $markup,
            $findings,
        );
        if ($merged === $currentStyle) {
            Narrator::write("  deterministic page CSS already merged\n");
            return;
        }
        $project->writeText('theme/style.css', $merged);
        Narrator::write("  merged deterministic page CSS\n");
    }

    /**
     * The deterministic CSS is one owned tail, not an append-only history.
     * Truncate at its stable first marker so a resumed build replaces output
     * produced by an older implementation or older source bytes atomically.
     */
    private static function withoutDeterministicStyles(string $style): string
    {
        $offset = strpos($style, self::DETERMINISTIC_STYLE_MARKER);
        if ($offset === false) {
            return $style;
        }
        return rtrim(substr($style, 0, $offset));
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
     * delivered page section root, except roots whose constrained flow is
     * explicitly left-justified. Those roots need their authored padding as
     * the start inset for narrower children. Scrubbing stays first so unsafe
     * generated declarations never reach this structural pass. A malformed
     * stylesheet keeps its scrubbed pre-neutralization bytes and records the
     * degradation.
     *
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<string>       $warnings
     */
    private static function scrubAndNeutralizeChunk(
        string $css,
        string $source,
        array $sectionRootIds,
        array &$warnings,
    ): string {
        $css = self::scrubChunk($css, $source, $warnings);
        if ($css === '' || $sectionRootIds['roots'] === []) {
            return $css;
        }

        $error = null;
        $removals = [];
        $rewritten = self::rewriteRuleList($css, $sectionRootIds, $removals, $error);
        if ($rewritten !== null) {
            foreach ($removals as $removal) {
                $warnings[] = sprintf(
                    'source=%s; block_path=section-root CSS declaration; authored_value=%s; '
                        . 'authored_spelling=%s; '
                        . 'delivered_value=%s; disposition=%s',
                    $source,
                    self::warningValue($removal['authored_value']),
                    self::warningSourceSpelling($removal['authored_value']),
                    $removal['delivered_value'],
                    $removal['disposition'],
                );
            }
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
     * @return array{
     *     ids:array<string,true>,
     *     roots:list<\DOMElement>,
     *     elements:list<\DOMElement>,
     *     allRootIds:array<string,true>,
     *     allRoots:list<\DOMElement>,
     *     trailingFlowChildren:list<\DOMElement>
     * }
     */
    private static function sectionRoots(Project $project, array &$warnings): array
    {
        $files = glob($project->pluginPath('pages') . '/*.html') ?: [];
        sort($files, SORT_STRING);
        $ids = [];
        $roots = [];
        $allRootIds = [];
        $allRoots = [];
        $elements = [];
        $trailingFlowChildren = [];
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
            foreach ($xpath->query('/html/body[@id="page-styles-page-root"]//*') ?: [] as $element) {
                if ($element instanceof \DOMElement) {
                    $elements[] = $element;
                    $parent = $element->parentNode;
                    if ($parent instanceof \DOMElement) {
                        $parentClasses = preg_split(
                            '/\s+/',
                            trim($parent->getAttribute('class')),
                        ) ?: [];
                        $classes = preg_split(
                            '/\s+/',
                            trim($element->getAttribute('class')),
                        ) ?: [];
                        $nextElement = $element->nextSibling;
                        while ($nextElement !== null && !$nextElement instanceof \DOMElement) {
                            $nextElement = $nextElement->nextSibling;
                        }
                        if ($nextElement === null
                            && (
                                in_array('blocks-engine-css-owned-flow', $classes, true)
                                || in_array('is-layout-flow', $parentClasses, true)
                            )
                        ) {
                            $trailingFlowChildren[] = $element;
                        }
                    }
                }
            }
            foreach ($xpath->query('/html/body[@id="page-styles-page-root"]/section') ?: [] as $section) {
                if (!$section instanceof \DOMElement) {
                    continue;
                }
                $allRoots[] = $section;
                $id = trim($section->getAttribute('id'));
                if ($id !== '') {
                    $allRootIds[$id] = true;
                }
                // These roots keep their authored horizontal padding: excluding
                // them here keeps the padding neutralisation off them. The
                // original reason — that the padding was the start inset for
                // narrower children — died with layout.justifyContent, since a
                // start-aligned child now carries its own pin.
                //
                // It still changes geometry, so it is left alone deliberately
                // rather than retired here. Be aware it couples: the pin's
                // 100% resolves against the padded content box, so a marked
                // child inside one of these roots lands at
                // padding + max(0, (paddedBox - contentSize) / 2), not at the
                // bare content column. Retiring this exclusion is its own
                // change, with its own measurement.
                $classes = preg_split('/\s+/', trim($section->getAttribute('class'))) ?: [];
                if (in_array(SectionLayoutStep::AUTHOR_WIDTH_START_CLASS, $classes, true)) {
                    continue;
                }
                $roots[] = $section;
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }
        return [
            'ids' => $ids,
            'roots' => $roots,
            'elements' => $elements,
            'allRootIds' => $allRootIds,
            'allRoots' => $allRoots,
            'trailingFlowChildren' => $trailingFlowChildren,
        ];
    }

    /**
     * Reassert only the authored block axis after WordPress's generated layout
     * stylesheet. Core's flow first/last-child rules carry more specificity
     * than ordinary design classes, while SectionRhythm's section presets are
     * inline declarations. The former is bounded to authored painted rhythm
     * labels and trailing controls; the latter uses important rules scoped to
     * source-design roots that do not already own a viewport-height stage.
     * Inline-axis values are deliberately never copied into this tail.
     *
     * @param list<array{source:string,css:string}> $authorChunks
     * @param array{
     *     ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>,
     *     allRootIds:array<string,true>,allRoots:list<\DOMElement>,
     *     trailingFlowChildren:list<\DOMElement>
     * } $sectionRoots
     * @param list<string> $warnings
     */
    private static function authoredVerticalRhythmCss(
        Project $project,
        array $authorChunks,
        array $sectionRoots,
        array &$warnings,
    ): string {
        [$authoredRoots, $inlineById] = self::authoredSectionRootContext(
            $project,
            $sectionRoots,
            $warnings,
        );
        if ($authoredRoots['ids'] === []) {
            return '';
        }

        $rhythmRoots = self::rootsWithoutViewportHeightOwnership($authoredRoots);
        $rootFilter = $rhythmRoots['ids'] === [] ? '' : self::rootSubjectFilter($rhythmRoots);
        $rules = [self::VERTICAL_RHYTHM_MARKER];
        $edgeStartIds = array_intersect_key($authoredRoots['edgeStartIds'], $rhythmRoots['ids']);
        if ($edgeStartIds !== []) {
            $edgeStartRoots = [
                'ids' => $edgeStartIds,
                'roots' => array_values(array_filter(
                    $rhythmRoots['roots'],
                    static fn (\DOMElement $root): bool => isset(
                        $edgeStartIds[trim($root->getAttribute('id'))],
                    ),
                )),
                'elements' => $rhythmRoots['elements'],
            ];
            $rules[] = ':root:root :where(' . self::rootSubjectFilter($edgeStartRoots)
                . ') {padding-top:0!important}';
        }
        $resolvedCache = [];
        $rootMatchCache = [];
        $flowMatchCache = [];
        $paintedSelectorKeys = self::paintedRhythmSelectorKeys($authorChunks);

        foreach ($authorChunks as $chunk) {
            foreach (CssChecks::scanDeclarations($chunk['css']) as $declaration) {
                if ($declaration['kind'] !== 'style') {
                    continue;
                }
                $conversionError = null;
                $converted = self::blockAxisDeclarations(
                    $declaration['property'],
                    $declaration['value'],
                    false,
                    $conversionError,
                );
                if ($converted === []) {
                    continue;
                }
                if (!$declaration['structurallySafe'] || $converted === null) {
                    $warnings[] = sprintf(
                        'source=%s; block_path=stylesheet selector %s; authored_value=%s; '
                            . 'delivered_value=authored declaration without cascade reinforcement; '
                            . 'disposition=retained unprovable block-axis declaration; reason=%s',
                        $chunk['source'],
                        self::warningValue($declaration['context']),
                        self::warningValue(trim($declaration['raw'])),
                        self::warningValue(
                            !$declaration['structurallySafe']
                                ? 'declaration is inside structurally recovered CSS'
                                : ($conversionError ?? 'unknown shorthand shape'),
                        ),
                    );
                    continue;
                }

                $selectorKey = json_encode(
                    [$declaration['ancestors'], $declaration['context']],
                    JSON_UNESCAPED_SLASHES,
                );
                $selectorKey = is_string($selectorKey) ? $selectorKey : $declaration['context'];
                if (!array_key_exists($selectorKey, $resolvedCache)) {
                    $selectorError = null;
                    $resolvedCache[$selectorKey] = [
                        'result' => self::resolvedVerticalSelectors($declaration, $selectorError),
                        'error' => $selectorError,
                    ];
                }
                $resolved = $resolvedCache[$selectorKey]['result'];
                $selectorError = $resolvedCache[$selectorKey]['error'];
                if ($resolved === null) {
                    $warnings[] = sprintf(
                        'source=%s; block_path=stylesheet selector %s; authored_value=%s; '
                            . 'delivered_value=authored declaration without cascade reinforcement; '
                            . 'disposition=retained unprovable selector; reason=%s',
                        $chunk['source'],
                        self::warningValue($declaration['context']),
                        self::warningValue(trim($declaration['raw'])),
                        self::warningValue($selectorError ?? 'unknown selector shape'),
                    );
                    continue;
                }

                foreach ($converted as $item) {
                    $flowSelectors = [];
                    $rootSelectors = [];
                    foreach ($resolved['selectors'] as $selector) {
                        $isBlockMargin = str_starts_with($item['property'], 'margin-');
                        if ($isBlockMargin && isset($paintedSelectorKeys[$selectorKey])) {
                            $cacheKey = 'painted:' . $selector;
                            if (!array_key_exists($cacheKey, $flowMatchCache)) {
                                $flowMatchCache[$cacheKey] = self::selectorMatchesAnyElement(
                                    $selector,
                                    $sectionRoots['elements'],
                                );
                            }
                            if ($flowMatchCache[$cacheKey]) {
                                $flowSelectors[] = self::boostVerticalSelector($selector);
                            }
                        } elseif ($isBlockMargin && !self::endsWithPseudoElement($selector)) {
                            $cacheKey = 'trailing:' . $selector;
                            if (!array_key_exists($cacheKey, $flowMatchCache)) {
                                $flowMatchCache[$cacheKey] = self::selectorMatchesAnyElement(
                                    $selector,
                                    $sectionRoots['trailingFlowChildren'],
                                );
                            }
                            if ($flowMatchCache[$cacheKey]) {
                                $flowSelectors[] = self::boostVerticalSelector(
                                    $selector . ':last-child' . self::TRAILING_GRID_ITEM_GUARD,
                                );
                            }
                        }
                        if (!array_key_exists($selector, $rootMatchCache)) {
                            $relationError = null;
                            $rootMatchCache[$selector] = [
                                'matches' => self::selectorMatchesAnyRoot(
                                    $selector,
                                    $rhythmRoots,
                                    $relationError,
                                ),
                                'error' => $relationError,
                            ];
                        }
                        $matchesRoot = $rootMatchCache[$selector]['matches'];
                        $relationError = $rootMatchCache[$selector]['error'];
                        if ($matchesRoot === null) {
                            $warnings[] = sprintf(
                                'source=%s; block_path=stylesheet selector %s; authored_value=%s; '
                                    . 'delivered_value=authored root declaration without inline-preset override; '
                                    . 'disposition=retained unprovable root selector; reason=%s',
                                $chunk['source'],
                                self::warningValue($selector),
                                self::warningValue(trim($declaration['raw'])),
                                self::warningValue($relationError ?? 'unknown root relation'),
                            );
                            continue;
                        }
                        if (!$matchesRoot || $rootFilter === '') {
                            continue;
                        }
                        $filter = $item['important'] ? ':is(' : ':where(';
                        $rootSelectors[] = self::boostVerticalSelector(
                            $selector . $filter . $rootFilter . ')',
                        );
                    }
                    if ($flowSelectors !== []) {
                        $rules[] = self::wrapVerticalGroupingRules(
                            $resolved['grouping'],
                            implode(', ', $flowSelectors)
                                . ' {' . self::verticalDeclarationText($item, false) . '}',
                        );
                    }
                    if ($rootSelectors !== []) {
                        $rules[] = self::wrapVerticalGroupingRules(
                            $resolved['grouping'],
                            implode(', ', $rootSelectors)
                                . ' {' . self::verticalDeclarationText($item, true) . '}',
                        );
                    }
                }
            }
        }

        foreach ($inlineById as $id => $declarations) {
            if (!isset($rhythmRoots['ids'][$id])) {
                continue;
            }
            $selector = ':root:root ' . self::cssIdSelector($id);
            foreach ($declarations as $declaration) {
                $rules[] = $selector . ' {' . self::verticalDeclarationText($declaration, true) . '}';
            }
        }

        return implode("\n", $rules);
    }

    /**
     * Pin each marked authored-width child to the content column edge its
     * authored margin asked for.
     *
     * WordPress's constrained layout centres every child with margin-left and
     * margin-right auto carrying !important, so an ordinary author rule at
     * (0,1,0) can never deliver a one-sided authored margin. :root:root plus
     * the marker class reaches (0,3,0) with !important, which outranks it.
     * Scoped by class rather than by root id because a section root is not
     * required to have one — tbilisi4's dish-list section has none.
     *
     * Emits only for markers actually present in delivered markup, so a design
     * with no authored-width child ships none of this.
     *
     * @param array{elements:list<\DOMElement>} $sectionRoots
     */
    private static function authoredWidthPinCss(array $sectionRoots): string
    {
        $present = [];
        foreach ($sectionRoots['elements'] as $element) {
            $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            foreach ([
                SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS,
                SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
            ] as $marker) {
                if (in_array($marker, $classes, true)) {
                    $present[$marker] = true;
                }
            }
        }
        if ($present === []) {
            return '';
        }

        $rules = [self::AUTHOR_WIDTH_PIN_MARKER];
        // A start-aligned child begins at the column start and keeps its
        // trailing slack; an escaping child is the mirror. Neither touches
        // width, so the authored max-width stays in control of the measure.
        foreach ([
            SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS =>
                ['margin-left: ' . self::CONTENT_COLUMN_INSET, 'margin-right: auto'],
            SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS =>
                ['margin-left: auto', 'margin-right: ' . self::CONTENT_COLUMN_INSET],
        ] as $marker => $declarations) {
            if (!isset($present[$marker])) {
                continue;
            }
            $body = implode('; ', array_map(
                static fn (string $declaration): string => $declaration . ' !important',
                $declarations,
            ));
            $rules[] = ':root:root .' . $marker . ' {' . $body . '}';
        }
        return implode("\n", $rules);
    }

    /**
     * Decorated inline labels are the authored section-rhythm landmarks. This
     * set bounds their own margins only: a trailing flow control is bounded by
     * its own predicate, so a design that paints nothing still keeps one.
     *
     * @param list<array{source:string,css:string}> $authorChunks
     * @return array<string,true>
     */
    private static function paintedRhythmSelectorKeys(array $authorChunks): array
    {
        $traits = [];
        foreach ($authorChunks as $chunk) {
            foreach (CssChecks::scanDeclarations($chunk['css']) as $declaration) {
                if ($declaration['kind'] !== 'style' || !$declaration['structurallySafe']) {
                    continue;
                }
                $property = strtolower(trim($declaration['property']));
                if (!in_array($property, ['background', 'background-color', 'border-radius'], true)) {
                    continue;
                }
                $key = json_encode(
                    [$declaration['ancestors'], $declaration['context']],
                    JSON_UNESCAPED_SLASHES,
                );
                $key = is_string($key) ? $key : $declaration['context'];
                if ($property === 'border-radius') {
                    $traits[$key]['radius'] = true;
                } else {
                    $traits[$key]['background'] = true;
                }
            }
        }

        $painted = [];
        foreach ($traits as $key => $trait) {
            if (($trait['background'] ?? false) && ($trait['radius'] ?? false)) {
                $painted[$key] = true;
            }
        }
        return $painted;
    }

    /**
     * Match source-design section ids to delivered roots. An id is the only
     * stable cross-representation key: class and tree-shape matching would
     * guess when the transformer introduces wrapper groups.
     *
     * @param array{
     *     ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>,
     *     allRootIds:array<string,true>,allRoots:list<\DOMElement>,
     *     trailingFlowChildren:list<\DOMElement>
     * } $sectionRoots
     * @param list<string> $warnings
     * @return array{
     *   0:array{
     *     ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>,
     *     edgeStartIds:array<string,true>
     *   },
     *   1:array<string,list<array{property:string,value:string,important:bool}>>
     * }
     */
    private static function authoredSectionRootContext(
        Project $project,
        array $sectionRoots,
        array &$warnings,
    ): array {
        $sourceIds = [];
        $edgeStartIds = [];
        $inlineCandidates = [];
        foreach (self::deliveredDesignSources($project) as $source) {
            $dom = Html::loadUtf8Html(
                $project->readText($source),
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            if (!$dom instanceof \DOMDocument) {
                $warnings[] = 'source=' . $source
                    . '; block_path=source section roots; authored_value=unparseable HTML; '
                    . 'delivered_value=no source-root vertical cascade; '
                    . 'disposition=retained delivered section presets';
                continue;
            }
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//section[@id]') ?: [] as $section) {
                if (!$section instanceof \DOMElement) {
                    continue;
                }
                $id = trim($section->getAttribute('id'));
                if ($id === '' || !isset($sectionRoots['allRootIds'][$id])) {
                    continue;
                }
                $sourceIds[$id] = true;
                $firstElement = null;
                foreach ($section->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $firstElement = $child;
                        break;
                    }
                }
                if ($firstElement instanceof \DOMElement
                    && strtolower($firstElement->tagName) !== 'div'
                ) {
                    // A leading content/band element begins at the authored
                    // section edge. A build-owned top preset would insert a
                    // new band before it, as opposed to padding a wrapper.
                    $edgeStartIds[$id] = true;
                }
                $style = $section->getAttribute('style');
                if (trim($style) === '') {
                    continue;
                }
                $converted = [];
                foreach (MarkupScan::parseInlineStyle($style) as $declaration) {
                    if ($declaration['value'] === null) {
                        continue;
                    }
                    $error = null;
                    $items = self::blockAxisDeclarations(
                        $declaration['property'],
                        $declaration['value'],
                        false,
                        $error,
                    );
                    if ($items === []) {
                        continue;
                    }
                    if ($items === null) {
                        $warnings[] = sprintf(
                            'source=%s; block_path=section#%s inline style; authored_value=%s; '
                                . 'delivered_value=section preset; disposition=retained unprovable '
                                . 'block-axis shorthand; reason=%s',
                            $source,
                            $id,
                            self::warningValue($declaration['segment']),
                            self::warningValue($error ?? 'unknown shorthand shape'),
                        );
                        continue;
                    }
                    array_push($converted, ...$items);
                }
                if ($converted !== []) {
                    $inlineCandidates[$id][] = [
                        'source' => $source,
                        'declarations' => $converted,
                    ];
                }
            }
        }

        $roots = [];
        $ids = [];
        foreach ($sectionRoots['allRoots'] as $root) {
            $id = trim($root->getAttribute('id'));
            if ($id === '' || !isset($sourceIds[$id])) {
                continue;
            }
            $roots[] = $root;
            $ids[$id] = true;
        }

        $inlineById = [];
        foreach ($inlineCandidates as $id => $candidates) {
            $byValue = [];
            foreach ($candidates as $candidate) {
                $key = json_encode($candidate['declarations'], JSON_UNESCAPED_SLASHES);
                if (is_string($key)) {
                    $byValue[$key][] = $candidate['source'];
                }
            }
            if (count($byValue) !== 1) {
                $warnings[] = sprintf(
                    'source=%s; block_path=section#%s inline style; authored_value=%s; '
                        . 'delivered_value=section preset; disposition=retained ambiguous source-root spacing',
                    implode(',', array_map(
                        static fn (array $candidate): string => $candidate['source'],
                        $candidates,
                    )),
                    $id,
                    self::warningValue(implode(' | ', array_keys($byValue))),
                );
                continue;
            }
            $inlineById[$id] = $candidates[0]['declarations'];
        }

        return [
            [
                'ids' => $ids,
                'roots' => $roots,
                'elements' => $sectionRoots['elements'],
                'edgeStartIds' => array_intersect_key($edgeStartIds, $ids),
            ],
            $inlineById,
        ];
    }

    /**
     * Viewport-height stages already own their complete vertical box. Keep
     * their delivered section preset rather than forcing a second padding
     * owner into that height calculation.
     *
     * @param array{
     *   ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>,
     *   edgeStartIds:array<string,true>
     * } $roots
     * @return array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>}
     */
    private static function rootsWithoutViewportHeightOwnership(array $roots): array
    {
        $kept = [];
        $ids = [];
        foreach ($roots['roots'] as $root) {
            $style = $root->getAttribute('style');
            if (preg_match(
                '/(?:^|;)\s*(?:min-)?height\s*:[^;]*(?:dvh|lvh|svh|vh)(?:\s|;|$)/i',
                $style,
            ) === 1) {
                continue;
            }
            $kept[] = $root;
            $id = trim($root->getAttribute('id'));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return ['ids' => $ids, 'roots' => $kept, 'elements' => $roots['elements']];
    }

    /**
     * @param array{property:string,value:string,important:bool} $declaration
     */
    private static function verticalDeclarationText(array $declaration, bool $forceImportant): string
    {
        $important = $forceImportant || $declaration['important'] ? ' !important' : '';
        return $declaration['property'] . ': ' . $declaration['value'] . $important . ';';
    }

    /**
     * Convert a spacing declaration into block-axis-only longhands. An empty
     * but cannot be decomposed without guessing.
     *
     * @return list<array{property:string,value:string,important:bool}>|null
     */
    private static function blockAxisDeclarations(
        string $property,
        string $rawValue,
        bool $forceImportant,
        ?string &$error,
    ): ?array {
        $property = strtolower(trim($property));
        $priority = CssChecks::splitDeclarationPriority($rawValue);
        $important = $forceImportant || $priority['important'];
        $value = $priority['value'];
        if (in_array($property, [
            'margin-top', 'margin-bottom', 'margin-block-start', 'margin-block-end',
            'padding-top', 'padding-bottom', 'padding-block-start', 'padding-block-end',
            'margin-block', 'padding-block', 'row-gap',
        ], true)) {
            return [[
                'property' => $property,
                'value' => $value,
                'important' => $important,
            ]];
        }
        if (!in_array($property, ['margin', 'padding', 'gap'], true)) {
            return [];
        }

        $values = self::splitPaddingValues($value, $error);
        if ($values === null || count($values) < 1 || count($values) > ($property === 'gap' ? 2 : 4)) {
            $error ??= $property . ' shorthand has an unsupported component count';
            return null;
        }
        if ($property === 'gap') {
            if (self::hasOpaquePaddingComponent([$values[0]])) {
                $error = 'gap shorthand has an opaque row component';
                return null;
            }
            return [[
                'property' => 'row-gap',
                'value' => $values[0],
                'important' => $important,
            ]];
        }

        $bottom = match (count($values)) {
            1, 2 => $values[0],
            3, 4 => $values[2],
        };
        if (self::hasOpaquePaddingComponent([$values[0], $bottom])) {
            $error = $property . ' shorthand has an opaque block-axis component';
            return null;
        }
        return [
            [
                'property' => $property . '-top',
                'value' => $values[0],
                'important' => $important,
            ],
            [
                'property' => $property . '-bottom',
                'value' => $bottom,
                'important' => $important,
            ],
        ];
    }

    /**
     * Resolve CSS nesting for selector matching/emission while retaining only
     * conditional grouping at-rules. @layer is intentionally flattened: a
     * normal declaration inside a layer cannot outrank WordPress's unlayered
     * layout CSS regardless of selector specificity.
     *
     * @param array{context:string,ancestors:list<string>} $declaration
     * @return array{selectors:list<string>,grouping:list<string>}|null
     */
    private static function resolvedVerticalSelectors(array $declaration, ?string &$error): ?array
    {
        $parents = [];
        $grouping = [];
        foreach ($declaration['ancestors'] as $ancestor) {
            $atRule = self::atRuleName($ancestor);
            if ($atRule !== null) {
                if ($atRule === 'layer') {
                    continue;
                }
                if (!in_array(
                    $atRule,
                    ['media', 'supports', 'container', 'scope', 'document', 'starting-style'],
                    true,
                )) {
                    $error = "unsupported vertical-rhythm ancestor @{$atRule}";
                    return null;
                }
                $grouping[] = trim($ancestor);
                continue;
            }
            $parents = self::resolveVerticalSelectorList($ancestor, $parents, $error) ?? [];
            if ($error !== null || $parents === []) {
                return null;
            }
        }
        $selectors = self::resolveVerticalSelectorList($declaration['context'], $parents, $error);
        return $selectors === null ? null : ['selectors' => $selectors, 'grouping' => $grouping];
    }

    /** @param list<string> $parents @return list<string>|null */
    private static function resolveVerticalSelectorList(
        string $selectorText,
        array $parents,
        ?string &$error,
    ): ?array {
        $branches = self::splitSelectorList($selectorText, $error);
        if ($branches === null) {
            return null;
        }
        $resolved = [];
        foreach ($branches as $branch) {
            $selectors = self::resolveNestedSelectorBranch(trim($branch), $parents, $error);
            if ($selectors === null) {
                return null;
            }
            array_push($resolved, ...$selectors);
        }
        return $resolved;
    }

    private static function boostVerticalSelector(string $selector): string
    {
        $selector = trim($selector);
        if (preg_match('/\Ahtml(?=\z|[.#:\[])/i', $selector) === 1) {
            return ':root:root:root' . substr($selector, 4);
        }
        if (preg_match('/\A:root(?=\z|[.#:\[])/i', $selector) === 1) {
            return ':root:root:root' . substr($selector, 5);
        }
        return ':root:root ' . $selector;
    }

    /**
     * A pseudo-element has to be the last thing in a selector, so appending
     * `:last-child` to one produces a rule the browser drops whole. The
     * trailing branch skips these rather than emitting dead CSS.
     */
    private static function endsWithPseudoElement(string $selector): bool
    {
        $selector = rtrim(trim($selector));
        if (preg_match('/::[a-z-]+(\([^()]*\))?\z/i', $selector) === 1) {
            return true;
        }
        $legacy = implode('|', self::LEGACY_PSEUDO_ELEMENTS);
        return preg_match('/(?<!:):(' . $legacy . ')\z/i', $selector) === 1;
    }

    /**
     * Whether a selector matches at least one source-backed delivered root.
     * Unlike selectorSetRootRelation(), this does not scan every non-root
     * element to distinguish ROOT_ALL from ROOT_MIXED: every emitted rule gets
     * the same explicit root-id filter, so that distinction has no effect.
     *
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $roots
     */
    private static function selectorMatchesAnyRoot(
        string $selector,
        array $roots,
        ?string &$error,
    ): ?bool {
        $parsed = CssSelectorMatcher::parse($selector);
        if ($parsed['supported'] ?? false) {
            $allMatchesSupported = true;
            foreach ($roots['roots'] as $root) {
                $match = CssSelectorMatcher::matches($root, $parsed, true);
                if (!($match['supported'] ?? false)) {
                    $allMatchesSupported = false;
                    break;
                }
                if ($match['matches']) {
                    return true;
                }
            }
            if ($allMatchesSupported) {
                return false;
            }
        }

        $relation = self::selectorRootRelation($selector, $roots, $error);
        return $relation === null ? null : $relation !== self::ROOT_NONE;
    }

    /** @param list<\DOMElement> $elements */
    private static function selectorMatchesAnyElement(string $selector, array $elements): bool
    {
        $parsed = CssSelectorMatcher::parse($selector);
        if (!($parsed['supported'] ?? false)) {
            // The source selector is still delivered unchanged. Conservatively
            // reinforce an unsupported selector rather than guessing that no
            // flow child can match it.
            return true;
        }
        foreach ($elements as $element) {
            $match = CssSelectorMatcher::matches($element, $parsed, true);
            if (!($match['supported'] ?? false)) {
                return true;
            }
            if ($match['matches']) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $grouping */
    private static function wrapVerticalGroupingRules(array $grouping, string $rule): string
    {
        for ($index = count($grouping) - 1; $index >= 0; $index--) {
            $rule = $grouping[$index] . ' {' . $rule . '}';
        }
        return $rule;
    }

    /**
     * Rewrite a stylesheet rule-list, recursing through grouping at-rules.
     * Returns null on syntax this bounded transformer cannot prove safe.
     *
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     */
    private static function rewriteRuleList(
        string $css,
        array $sectionRootIds,
        array &$removals,
        ?string &$error,
        array $parentSelectors = [],
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
                        $body = self::rewriteRuleList(
                            $body,
                            $sectionRootIds,
                            $removals,
                            $error,
                            $parentSelectors,
                        );
                        if ($body === null) {
                            return null;
                        }
                    }
                    $out .= $prelude . '{' . $body . '}';
                } else {
                    $rule = self::rewriteQualifiedRule(
                        $prelude,
                        $body,
                        $sectionRootIds,
                        $removals,
                        $error,
                        $parentSelectors,
                    );
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

    /**
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     */
    private static function rewriteQualifiedRule(
        string $prelude,
        string $body,
        array $sectionRootIds,
        array &$removals,
        ?string &$error,
        array $parentSelectors,
    ): ?string {
        [$leading, $selectorText] = self::leadingTriviaAndRest($prelude);
        $branches = self::splitSelectorList($selectorText, $error);
        if ($branches === null) {
            return null;
        }

        $branchInfo = [];
        foreach ($branches as $branch) {
            $branch = trim($branch);
            $resolved = self::resolveNestedSelectorBranch($branch, $parentSelectors, $error);
            if ($resolved === null) {
                return null;
            }
            $relation = self::selectorSetRootRelation($resolved, $sectionRootIds, $error);
            if ($relation === null) {
                return null;
            }
            $branchInfo[] = [
                'branch' => $branch,
                'resolved' => $resolved,
                'relation' => $relation,
            ];
        }

        $hasNested = false;
        $bodyItems = self::splitRuleBodyItems($body, $hasNested, $error);
        if ($bodyItems === null) {
            return null;
        }
        $needsSharedNesting = count($branchInfo) > 1;
        foreach ($branchInfo as $info) {
            $needsSharedNesting = $needsSharedNesting || $info['relation'] === self::ROOT_MIXED;
        }
        if ($hasNested && $needsSharedNesting) {
            return self::rewriteQualifiedRuleWithSharedNesting(
                $leading,
                $selectorText,
                $body,
                $bodyItems,
                $branchInfo,
                $sectionRootIds,
                $removals,
                $error,
            );
        }

        $direct = self::rewriteDirectRuleChunk(
            trim($selectorText),
            $body,
            $branchInfo,
            $sectionRootIds,
            $removals,
            $error,
        );
        if ($direct === null) {
            return null;
        }
        return $direct['changed'] ? $leading . $direct['text'] : $prelude . '{' . $body . '}';
    }

    /**
     * @param list<array{kind:string,text:string}> $bodyItems
     * @param list<array{branch:string,resolved:list<string>,relation:int}> $branchInfo
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     */
    private static function rewriteQualifiedRuleWithSharedNesting(
        string $leading,
        string $selectorText,
        string $originalBody,
        array $bodyItems,
        array $branchInfo,
        array $sectionRootIds,
        array &$removals,
        ?string &$error,
    ): ?string {
        $parentSelectors = [];
        foreach ($branchInfo as $info) {
            array_push($parentSelectors, ...$info['resolved']);
        }
        $authoredSelectorText = $selectorText;
        $selectorText = trim($selectorText);
        $rewritten = self::rewriteSharedBodyItems(
            $bodyItems,
            [],
            $selectorText,
            $branchInfo,
            $parentSelectors,
            $sectionRootIds,
            $removals,
            $error,
        );
        if ($rewritten === null) {
            return null;
        }

        if (!$rewritten['changed']) {
            return $leading . $authoredSelectorText . '{' . $originalBody . '}';
        }
        $indent = '';
        if (preg_match('/\n([ \t]*)\z/', $leading, $match) === 1) {
            $indent = $match[1];
        }
        return $leading . implode("\n{$indent}", $rewritten['pieces']);
    }

    /**
     * Direct declaration chunks may move outward through grouping at-rules so
     * root and non-root selector specificity stays unchanged. Nested selector
     * chunks keep every grouping wrapper under the original complete parent
     * selector list, preserving `&` max-list specificity and source order.
     *
     * @param list<array{kind:string,text:string}> $bodyItems
     * @param list<string> $groupPreludes
     * @param list<array{branch:string,resolved:list<string>,relation:int}> $branchInfo
     * @param list<string> $parentSelectors
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     * @return array{pieces:list<string>,changed:bool}|null
     */
    private static function rewriteSharedBodyItems(
        array $bodyItems,
        array $groupPreludes,
        string $selectorText,
        array $branchInfo,
        array $parentSelectors,
        array $sectionRootIds,
        array &$removals,
        ?string &$error,
    ): ?array {
        $pieces = [];
        $anyChanged = false;
        foreach ($bodyItems as $item) {
            if ($item['kind'] === 'direct') {
                if (self::isCssTrivia($item['text'])) {
                    continue;
                }
                $direct = self::rewriteDirectRuleChunk(
                    $selectorText,
                    $item['text'],
                    $branchInfo,
                    $sectionRootIds,
                    $removals,
                    $error,
                );
                if ($direct === null) {
                    return null;
                }
                $pieces[] = self::wrapGroupingRules($groupPreludes, $direct['text']);
                $anyChanged = $anyChanged || $direct['changed'];
                continue;
            }

            $block = self::nestedBlockParts($item['text'], $error);
            if ($block === null) {
                return null;
            }
            $atRule = self::atRuleName($block['prelude']);
            if ($atRule !== null && self::isGroupingAtRule($atRule)) {
                $hasNested = false;
                $nestedItems = self::splitRuleBodyItems($block['body'], $hasNested, $error);
                if ($nestedItems === null) {
                    return null;
                }
                $nestedGroups = $groupPreludes;
                $nestedGroups[] = trim($block['prelude']);
                $grouped = self::rewriteSharedBodyItems(
                    $nestedItems,
                    $nestedGroups,
                    $selectorText,
                    $branchInfo,
                    $parentSelectors,
                    $sectionRootIds,
                    $removals,
                    $error,
                );
                if ($grouped === null) {
                    return null;
                }
                array_push($pieces, ...$grouped['pieces']);
                $anyChanged = $anyChanged || $grouped['changed'];
                continue;
            }

            $nestedChanged = false;
            $nested = self::rewriteRuleBody(
                $item['text'],
                $sectionRootIds,
                $parentSelectors,
                false,
                $nestedChanged,
                $removals,
                $error,
            );
            if ($nested === null) {
                return null;
            }
            $pieces[] = $selectorText . ' {'
                . self::wrapGroupingRules($groupPreludes, $nested)
                . '}';
            $anyChanged = $anyChanged || $nestedChanged;
        }
        return ['pieces' => $pieces, 'changed' => $anyChanged];
    }

    /** @return array{prelude:string,body:string}|null */
    private static function nestedBlockParts(string $block, ?string &$error): ?array
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($block);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $block[$offset] === '{') {
                $close = self::matchingBrace($block, $offset, $error);
                if ($close === null || !self::isCssTrivia(substr($block, $close + 1))) {
                    $error ??= 'nested block has trailing non-trivia';
                    return null;
                }
                return [
                    'prelude' => substr($block, 0, $offset),
                    'body' => substr($block, $offset + 1, $close - $offset - 1),
                ];
            }
            $next = CssSyntaxScanner::consume($block, $offset, $state);
            if ($next === null) {
                $error = "invalid nested block at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        $error = 'nested block has no opening brace';
        return null;
    }

    /** @param list<string> $groupPreludes */
    private static function wrapGroupingRules(array $groupPreludes, string $contents): string
    {
        for ($index = count($groupPreludes) - 1; $index >= 0; $index--) {
            $contents = $groupPreludes[$index] . ' {' . $contents . '}';
        }
        return $contents;
    }

    private static function isGroupingAtRule(string $atRule): bool
    {
        return in_array(
            $atRule,
            ['media', 'supports', 'container', 'layer', 'scope', 'document', 'starting-style'],
            true,
        );
    }

    /**
     * @param list<array{branch:string,resolved:list<string>,relation:int}> $branchInfo
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     * @return array{text:string,changed:bool}|null
     */
    private static function rewriteDirectRuleChunk(
        string $selectorText,
        string $body,
        array $branchInfo,
        array $sectionRootIds,
        array &$removals,
        ?string &$error,
    ): ?array {
        $entries = [];
        $anyChanged = false;
        $rootFilter = self::rootSubjectFilter($sectionRootIds);
        foreach ($branchInfo as $info) {
            $branch = $info['branch'];
            $resolved = $info['resolved'];
            $relation = $info['relation'];

            if ($relation !== self::ROOT_MIXED) {
                $changed = false;
                $rewrittenBody = self::rewriteRuleBody(
                    $body,
                    $sectionRootIds,
                    $resolved,
                    $relation === self::ROOT_ALL,
                    $changed,
                    $removals,
                    $error,
                );
                if ($rewrittenBody === null) {
                    return null;
                }
                $entries[] = ['selector' => $branch, 'body' => $rewrittenBody];
                $anyChanged = $anyChanged || $changed;
                continue;
            }

            $rootSelector = $branch . ':where(' . $rootFilter . ')';
            $otherSelector = $branch . ':not(:where(' . $rootFilter . '))';
            $rootResolved = array_map(
                static fn (string $selector): string => $selector . ':where(' . $rootFilter . ')',
                $resolved,
            );
            $otherResolved = array_map(
                static fn (string $selector): string => $selector . ':not(:where(' . $rootFilter . '))',
                $resolved,
            );
            $rootChanged = false;
            $rootBody = self::rewriteRuleBody(
                $body,
                $sectionRootIds,
                $rootResolved,
                true,
                $rootChanged,
                $removals,
                $error,
            );
            $otherChanged = false;
            $otherBody = self::rewriteRuleBody(
                $body,
                $sectionRootIds,
                $otherResolved,
                false,
                $otherChanged,
                $removals,
                $error,
            );
            if ($rootBody === null || $otherBody === null) {
                return null;
            }
            if (!$rootChanged && !$otherChanged) {
                $entries[] = ['selector' => $branch, 'body' => $body];
                continue;
            }
            $entries[] = ['selector' => $rootSelector, 'body' => $rootBody];
            $entries[] = ['selector' => $otherSelector, 'body' => $otherBody];
            $anyChanged = true;
        }

        if (!$anyChanged) {
            return ['text' => $selectorText . ' {' . $body . '}', 'changed' => false];
        }
        $rules = [];
        foreach ($entries as $entry) {
            $rules[] = $entry['selector'] . ' {' . $entry['body'] . '}';
        }
        return ['text' => implode("\n", $rules), 'changed' => true];
    }

    /** @return list<array{kind:string,text:string}>|null */
    private static function splitRuleBodyItems(
        string $body,
        bool &$hasNested,
        ?string &$error,
    ): ?array {
        $length = strlen($body);
        $state = CssSyntaxScanner::state();
        $chunkStart = 0;
        $statementStart = 0;
        $items = [];
        $hasNested = false;
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $body[$offset];
            if ($topLevel && $byte === ';') {
                $statementStart = $offset + 1;
                $offset++;
                continue;
            }
            if ($topLevel && $byte === '{') {
                $close = self::matchingBrace($body, $offset, $error);
                if ($close === null) {
                    return null;
                }
                if ($statementStart > $chunkStart) {
                    $items[] = [
                        'kind' => 'direct',
                        'text' => substr($body, $chunkStart, $statementStart - $chunkStart),
                    ];
                }
                $items[] = [
                    'kind' => 'nested',
                    'text' => substr($body, $statementStart, $close + 1 - $statementStart),
                ];
                $hasNested = true;
                $offset = $close + 1;
                $chunkStart = $offset;
                $statementStart = $offset;
                $state = CssSyntaxScanner::state();
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
        if ($chunkStart < $length) {
            $items[] = ['kind' => 'direct', 'text' => substr($body, $chunkStart)];
        }
        return $items;
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
     * @param array{
     *     ids:array<string,true>,
     *     roots:list<\DOMElement>,
     *     elements:list<\DOMElement>
     * } $sectionRootIds
     */
    private static function rootSubjectFilter(array $sectionRootIds): string
    {
        $ids = array_keys($sectionRootIds['ids']);
        sort($ids, SORT_STRING);
        return implode(', ', array_map(self::cssIdSelector(...), $ids));
    }

    private static function cssIdSelector(string $id): string
    {
        if (preg_match('/\A-?(?:[A-Za-z_]|[^\x00-\x7F])(?:[A-Za-z0-9_-]|[^\x00-\x7F])*\z/', $id) === 1) {
            return '#' . $id;
        }
        $escaped = str_replace(
            ["\\", '"', "\n", "\r", "\f"],
            ["\\\\", '\\"', '\\a ', '\\d ', '\\c '],
            $id,
        );
        return '[id="' . $escaped . '"]';
    }

    /**
     * Resolve one nested selector branch only for subject analysis. Emitted
     * CSS keeps its authored nesting. Parent branches are already resolved.
     *
     * @param list<string> $parentSelectors
     * @return list<string>|null
     */
    private static function resolveNestedSelectorBranch(
        string $selector,
        array $parentSelectors,
        ?string &$error,
    ): ?array {
        if ($parentSelectors === []) {
            return [$selector];
        }

        $resolved = [];
        foreach ($parentSelectors as $parent) {
            $usedNesting = false;
            $candidate = self::replaceNestingAmpersands(
                $selector,
                $parent,
                $usedNesting,
                $error,
            );
            if ($candidate === null) {
                return null;
            }
            $resolved[] = $usedNesting ? $candidate : $parent . ' ' . $selector;
        }
        return $resolved;
    }

    private static function replaceNestingAmpersands(
        string $selector,
        string $parent,
        bool &$usedNesting,
        ?string &$error,
    ): ?string {
        $state = CssSyntaxScanner::state();
        $length = strlen($selector);
        $out = '';
        for ($offset = 0; $offset < $length;) {
            $byte = $selector[$offset];
            if (
                $byte === '&'
                && $state['quote'] === ''
                && !$state['comment']
                && $state['brackets'] === 0
            ) {
                $out .= $parent;
                $usedNesting = true;
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ($next === null) {
                $error = "invalid nested selector at byte {$offset}";
                return null;
            }
            $out .= substr($selector, $offset, $next - $offset);
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated nested selector';
            return null;
        }
        return $out;
    }

    /**
     * ROOT_ALL means every explicit alternative identifies a delivered root;
     * ROOT_MIXED means the same branch also has a non-root alternative. That
     * distinction lets the emitter preserve the authored selector specificity
     * while applying disjoint zero-specificity subject filters.
     *
     * @param list<string> $selectors
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     */
    private static function selectorSetRootRelation(
        array $selectors,
        array $sectionRootIds,
        ?string &$error,
    ): ?int {
        $sawRoot = false;
        $sawOther = false;
        foreach ($selectors as $selector) {
            $relation = self::selectorRootRelation($selector, $sectionRootIds, $error);
            if ($relation === null) {
                return null;
            }
            $sawRoot = $sawRoot || $relation !== self::ROOT_NONE;
            $sawOther = $sawOther || $relation !== self::ROOT_ALL;
        }
        if (!$sawRoot) {
            return self::ROOT_NONE;
        }
        return $sawOther ? self::ROOT_MIXED : self::ROOT_ALL;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function selectorRootRelation(
        string $selector,
        array $sectionRootIds,
        ?string &$error,
    ): ?int {
        $matched = self::matchedSelectorRootRelation(trim($selector), $sectionRootIds);
        if ($matched !== null) {
            return $matched;
        }

        $compound = self::finalSelectorCompound($selector, $error);
        if ($compound === null) {
            return null;
        }
        $baseCompound = self::unsupportedTrailingPseudoBase($compound);
        if ($baseCompound !== null) {
            $matched = self::matchedSelectorRootRelation($baseCompound, $sectionRootIds);
            if ($matched !== null) {
                return $matched;
            }
        }
        return self::compoundRootRelation($compound, $sectionRootIds, $error);
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function matchedSelectorRootRelation(string $selector, array $sectionRootIds): ?int
    {
        $parsed = CssSelectorMatcher::parse($selector);
        if (!($parsed['supported'] ?? false)) {
            return null;
        }
        $rootObjectIds = [];
        $sawRoot = false;
        foreach ($sectionRootIds['roots'] as $root) {
            $rootObjectIds[spl_object_id($root)] = true;
            $match = CssSelectorMatcher::matches($root, $parsed, true);
            if ($match['supported'] && $match['matches']) {
                $sawRoot = true;
            }
        }
        if (!$sawRoot) {
            return self::ROOT_NONE;
        }
        foreach ($sectionRootIds['elements'] as $element) {
            if (isset($rootObjectIds[spl_object_id($element)])) {
                continue;
            }
            $match = CssSelectorMatcher::matches($element, $parsed, true);
            if ($match['supported'] && $match['matches']) {
                return self::ROOT_MIXED;
            }
        }
        return self::ROOT_ALL;
    }

    /**
     * Unsupported trailing pseudo-classes do not hide an otherwise resolvable
     * section-root subject. Keep pseudo-elements and non-trailing compounds out
     * of this bounded fallback rather than approximating full selector logic.
     */
    private static function unsupportedTrailingPseudoBase(string $compound): ?string
    {
        $length = strlen($compound);
        $state = CssSyntaxScanner::state();
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            if ($topLevel && substr($compound, $offset, 2) === '/*') {
                $end = strpos($compound, '*/', $offset + 2);
                if ($end === false) {
                    return null;
                }
                $offset = $end + 2;
                continue;
            }
            if ($topLevel && $compound[$offset] === ':') {
                if (($compound[$offset + 1] ?? '') === ':') {
                    return null;
                }
                $base = trim(substr($compound, 0, $offset));
                if ($base === '') {
                    return null;
                }
                for ($suffix = $offset; $suffix < $length;) {
                    $suffix = self::skipSelectorTrivia($compound, $suffix);
                    if ($suffix >= $length) {
                        return $base;
                    }
                    if ($compound[$suffix] !== ':' || ($compound[$suffix + 1] ?? '') === ':') {
                        return null;
                    }
                    $pseudo = self::cssIdentifierAt($compound, $suffix + 1);
                    if ($pseudo === null) {
                        return null;
                    }
                    if (in_array(
                        strtolower($pseudo['decoded']),
                        ['before', 'after', 'first-letter', 'first-line'],
                        true,
                    )) {
                        return null;
                    }
                    $suffix = self::skipSelectorTrivia($compound, $pseudo['end']);
                    if (($compound[$suffix] ?? '') !== '(') {
                        continue;
                    }
                    $suffixError = null;
                    $end = self::selectorGroupEnd($compound, $suffix, '(', ')', $suffixError);
                    if ($end === null) {
                        return null;
                    }
                    $suffix = $end;
                }
                return $base;
            }
            $next = CssSyntaxScanner::consume($compound, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        return null;
    }

    private static function finalSelectorCompound(string $selector, ?string &$error): ?string
    {
        $length = strlen($selector);
        $state = CssSyntaxScanner::state();
        $start = 0;
        $sawToken = false;
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
            if ($topLevel && CssSyntaxScanner::isCssWhitespace($byte)) {
                $next = self::skipSelectorTrivia($selector, $offset);
                if ($sawToken && $next < $length) {
                    $start = $next;
                }
                $offset = $next;
                continue;
            }
            if ($topLevel && ($byte === '>' || $byte === '+' || $byte === '~'
                || substr($selector, $offset, 2) === '||')) {
                $offset += substr($selector, $offset, 2) === '||' ? 2 : 1;
                $offset = self::skipSelectorTrivia($selector, $offset);
                $start = $offset;
                $sawToken = false;
                continue;
            }
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ($next === null) {
                $error = "invalid selector escape or delimiter at byte {$offset}";
                return null;
            }
            if ($topLevel && !CssSyntaxScanner::isCssWhitespace($byte)) {
                $sawToken = true;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated selector string, comment, or function';
            return null;
        }
        $compound = trim(substr($selector, $start));
        if ($compound === '') {
            $error = 'selector has no final subject';
            return null;
        }
        return $compound;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function compoundRootRelation(
        string $compound,
        array $sectionRootIds,
        ?string &$error,
    ): ?int {
        $length = strlen($compound);
        $state = CssSyntaxScanner::state();
        $relation = self::ROOT_NONE;
        $excludesRoot = false;
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            if ($topLevel && substr($compound, $offset, 2) === '/*') {
                $end = strpos($compound, '*/', $offset + 2);
                if ($end === false) {
                    $error = 'unterminated selector comment';
                    return null;
                }
                $offset = $end + 2;
                continue;
            }
            $byte = $compound[$offset];
            if ($topLevel && $byte === '#') {
                $identifier = self::cssIdentifierAt($compound, $offset + 1);
                if ($identifier !== null) {
                    if (isset($sectionRootIds['ids'][$identifier['decoded']])) {
                        $relation = self::ROOT_ALL;
                    }
                    $offset = $identifier['end'];
                    continue;
                }
            }
            if ($topLevel && $byte === '[') {
                $end = self::selectorGroupEnd($compound, $offset, '[', ']', $error);
                if ($end === null) {
                    return null;
                }
                $attributeRelation = self::idAttributeRootRelation(
                    substr($compound, $offset, $end - $offset),
                    $sectionRootIds,
                );
                if ($attributeRelation === self::ROOT_ALL) {
                    $relation = self::ROOT_ALL;
                } elseif (
                    $attributeRelation === self::ROOT_MIXED
                    && $relation !== self::ROOT_ALL
                ) {
                    $relation = self::ROOT_MIXED;
                }
                $offset = $end;
                continue;
            }
            if ($topLevel && $byte === ':') {
                if (($compound[$offset + 1] ?? '') === ':') {
                    return self::ROOT_NONE;
                }
                $pseudo = self::cssIdentifierAt($compound, $offset + 1);
                if ($pseudo !== null) {
                    $name = strtolower($pseudo['decoded']);
                    if (in_array($name, ['before', 'after', 'first-letter', 'first-line'], true)) {
                        return self::ROOT_NONE;
                    }
                    $functionOpen = self::skipSelectorTrivia($compound, $pseudo['end']);
                    if (($compound[$functionOpen] ?? '') === '(') {
                        $end = self::selectorGroupEnd($compound, $functionOpen, '(', ')', $error);
                        if ($end === null) {
                            return null;
                        }
                        $argumentsText = substr(
                            $compound,
                            $functionOpen + 1,
                            $end - $functionOpen - 2,
                        );
                        if (in_array($name, ['is', 'where'], true)) {
                            $argumentRelation = self::selectorArgumentRootRelation(
                                $argumentsText,
                                $sectionRootIds,
                                $error,
                            );
                            if ($argumentRelation === null) {
                                return null;
                            }
                            if ($argumentRelation === self::ROOT_ALL) {
                                $relation = self::ROOT_ALL;
                            } elseif (
                                $argumentRelation === self::ROOT_MIXED
                                && $relation !== self::ROOT_ALL
                            ) {
                                $relation = self::ROOT_MIXED;
                            }
                        } elseif ($name === 'not') {
                            $doubleNegated = self::unwrappedNotArgument($argumentsText, $error);
                            if ($error !== null) {
                                return null;
                            }
                            if ($doubleNegated !== null) {
                                $argumentRelation = self::selectorRootRelation(
                                    $doubleNegated,
                                    $sectionRootIds,
                                    $error,
                                );
                                if ($argumentRelation === null) {
                                    return null;
                                }
                                if ($argumentRelation === self::ROOT_ALL) {
                                    $relation = self::ROOT_ALL;
                                } elseif (
                                    $argumentRelation === self::ROOT_MIXED
                                    && $relation !== self::ROOT_ALL
                                ) {
                                    $relation = self::ROOT_MIXED;
                                }
                            } else {
                                $argumentRelation = self::selectorArgumentRootRelation(
                                    $argumentsText,
                                    $sectionRootIds,
                                    $error,
                                );
                                if ($argumentRelation === null) {
                                    return null;
                                }
                                $definitelyNamesRoot = self::selectorDefinitelyNamesRoot(
                                    $argumentsText,
                                    $sectionRootIds,
                                    $error,
                                );
                                if ($definitelyNamesRoot === null) {
                                    return null;
                                }
                                if ($argumentRelation !== self::ROOT_NONE && $definitelyNamesRoot) {
                                    $excludesRoot = true;
                                }
                            }
                        } elseif (in_array($name, ['nth-child', 'nth-last-child'], true)) {
                            $argumentRelation = self::nthOfRootRelation(
                                $argumentsText,
                                $sectionRootIds,
                                $error,
                            );
                            if ($argumentRelation === null) {
                                return null;
                            }
                            if ($argumentRelation === self::ROOT_ALL) {
                                $relation = self::ROOT_ALL;
                            } elseif (
                                $argumentRelation === self::ROOT_MIXED
                                && $relation !== self::ROOT_ALL
                            ) {
                                $relation = self::ROOT_MIXED;
                            }
                        }
                        $offset = $end;
                        continue;
                    }
                    $offset = $pseudo['end'];
                    continue;
                }
            }

            $next = CssSyntaxScanner::consume($compound, $offset, $state);
            if ($next === null) {
                $error = "invalid selector escape or delimiter at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated final selector compound';
            return null;
        }
        return $excludesRoot ? self::ROOT_NONE : $relation;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function selectorArgumentRootRelation(
        string $arguments,
        array $sectionRootIds,
        ?string &$error,
    ): ?int {
        $branches = self::splitSelectorList($arguments, $error);
        return $branches === null
            ? null
            : self::selectorSetRootRelation($branches, $sectionRootIds, $error);
    }

    private static function unwrappedNotArgument(string $selector, ?string &$error): ?string
    {
        $selector = trim($selector);
        if (($selector[0] ?? '') !== ':') {
            return null;
        }
        $pseudo = self::cssIdentifierAt($selector, 1);
        if ($pseudo === null) {
            return null;
        }
        $name = strtolower($pseudo['decoded']);
        $open = self::skipSelectorTrivia($selector, $pseudo['end']);
        if (($selector[$open] ?? '') !== '(') {
            return null;
        }
        $end = self::selectorGroupEnd($selector, $open, '(', ')', $error);
        if ($end === null || trim(substr($selector, $end)) !== '') {
            return null;
        }
        $arguments = substr($selector, $open + 1, $end - $open - 2);
        if ($name === 'not') {
            return $arguments;
        }
        if (!in_array($name, ['is', 'where'], true)) {
            return null;
        }
        $branches = self::splitSelectorList($arguments, $error);
        return $branches !== null && count($branches) === 1
            ? self::unwrappedNotArgument($branches[0], $error)
            : null;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function selectorDefinitelyNamesRoot(
        string $selector,
        array $sectionRootIds,
        ?string &$error,
    ): ?bool {
        $branches = self::splitSelectorList($selector, $error);
        if ($branches === null) {
            return null;
        }
        foreach ($branches as $branch) {
            $branch = trim($branch);
            if (($branch[0] ?? '') === '#') {
                $identifier = self::cssIdentifierAt($branch, 1);
                if (
                    $identifier !== null
                    && $identifier['end'] === strlen($branch)
                    && isset($sectionRootIds['ids'][$identifier['decoded']])
                ) {
                    return true;
                }
            }
            if (($branch[0] ?? '') === '[') {
                $end = self::selectorGroupEnd($branch, 0, '[', ']', $error);
                if ($end === null) {
                    return null;
                }
                if (
                    $end === strlen($branch)
                    && self::idAttributeRootRelation($branch, $sectionRootIds) === self::ROOT_ALL
                ) {
                    return true;
                }
            }
            if (($branch[0] ?? '') !== ':') {
                continue;
            }
            $pseudo = self::cssIdentifierAt($branch, 1);
            if ($pseudo === null || !in_array(strtolower($pseudo['decoded']), ['is', 'where'], true)) {
                continue;
            }
            $open = self::skipSelectorTrivia($branch, $pseudo['end']);
            if (($branch[$open] ?? '') !== '(') {
                continue;
            }
            $end = self::selectorGroupEnd($branch, $open, '(', ')', $error);
            if ($end === null) {
                return null;
            }
            if ($end !== strlen($branch)) {
                continue;
            }
            $nested = self::selectorDefinitelyNamesRoot(
                substr($branch, $open + 1, $end - $open - 2),
                $sectionRootIds,
                $error,
            );
            if ($nested === null) {
                return null;
            }
            if ($nested) {
                return true;
            }
        }
        return false;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function nthOfRootRelation(
        string $arguments,
        array $sectionRootIds,
        ?string &$error,
    ): ?int {
        $state = CssSyntaxScanner::state();
        $length = strlen($arguments);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state)) {
                $identifier = self::cssIdentifierAt($arguments, $offset);
                if ($identifier !== null) {
                    $hasLeadingGap = $offset > 0
                        && CssSyntaxScanner::isCssWhitespace($arguments[$offset - 1]);
                    $after = self::skipSelectorTrivia($arguments, $identifier['end']);
                    if (
                        $hasLeadingGap
                        && strtolower($identifier['decoded']) === 'of'
                        && $after > $identifier['end']
                        && $after < $length
                    ) {
                        return self::selectorArgumentRootRelation(
                            substr($arguments, $after),
                            $sectionRootIds,
                            $error,
                        );
                    }
                    $offset = $identifier['end'];
                    continue;
                }
            }
            $next = CssSyntaxScanner::consume($arguments, $offset, $state);
            if ($next === null) {
                $error = "invalid nth-child argument at byte {$offset}";
                return null;
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            $error = 'unterminated nth-child argument';
            return null;
        }
        return self::ROOT_NONE;
    }

    private static function skipSelectorTrivia(string $selector, int $offset): int
    {
        $length = strlen($selector);
        while ($offset < $length) {
            if (CssSyntaxScanner::isCssWhitespace($selector[$offset])) {
                $offset++;
                continue;
            }
            if (substr($selector, $offset, 2) === '/*') {
                $end = strpos($selector, '*/', $offset + 2);
                if ($end === false) {
                    return $length;
                }
                $offset = $end + 2;
                continue;
            }
            break;
        }
        return $offset;
    }

    private static function selectorGroupEnd(
        string $selector,
        int $open,
        string $opener,
        string $closer,
        ?string &$error,
    ): ?int {
        if (($selector[$open] ?? '') !== $opener) {
            $error = "selector group does not start with {$opener}";
            return null;
        }
        $state = CssSyntaxScanner::state();
        $length = strlen($selector);
        for ($offset = $open; $offset < $length;) {
            $byte = $selector[$offset];
            $next = CssSyntaxScanner::consume($selector, $offset, $state);
            if ($next === null) {
                $error = "invalid selector group at byte {$offset}";
                return null;
            }
            if ($byte === $closer && CssSyntaxScanner::isTopLevel($state)) {
                return $next;
            }
            $offset = $next;
        }
        $error = "unterminated selector group {$opener}";
        return null;
    }

    /** @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds */
    private static function idAttributeRootRelation(string $attribute, array $sectionRootIds): int
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $attribute);
        if (!is_string($withoutComments) || strlen($withoutComments) < 2) {
            return self::ROOT_NONE;
        }
        $inside = trim(substr($withoutComments, 1, -1));
        $name = self::cssIdentifierAt($inside, 0);
        if ($name === null || strtolower($name['decoded']) !== 'id') {
            return self::ROOT_NONE;
        }
        $rest = ltrim(substr($inside, $name['end']), " \t\r\n\f");
        if (preg_match('/\A([~|^$*]?=)/', $rest, $operatorMatch) !== 1) {
            return self::ROOT_NONE;
        }
        $operator = $operatorMatch[1];
        $rest = ltrim(substr($rest, strlen($operator)), " \t\r\n\f");
        if (preg_match(
            '/\A(?:"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|([^ \t\r\n\f]+?))'
                . '(?:[ \t\r\n\f]+((?:\\\\.|[A-Za-z])+))?[ \t\r\n\f]*\z/s',
            $rest,
            $match,
        ) !== 1) {
            return self::ROOT_NONE;
        }
        $raw = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
        $decoded = self::decodeCssEscapes($raw);
        if ($decoded === null) {
            return self::ROOT_NONE;
        }
        $modifier = isset($match[4]) && $match[4] !== ''
            ? strtolower((string) self::decodeCssIdentifierText($match[4]))
            : '';
        if ($modifier !== '' && !in_array($modifier, ['i', 's'], true)) {
            return self::ROOT_NONE;
        }
        foreach ($sectionRootIds['ids'] as $id => $_) {
            $candidate = $modifier === 'i' ? strtolower($id) : $id;
            $wanted = $modifier === 'i' ? strtolower($decoded) : $decoded;
            $matches = match ($operator) {
                '=' => $candidate === $wanted,
                '|=' => $candidate === $wanted || str_starts_with($candidate, $wanted . '-'),
                '~=' => in_array($wanted, preg_split('/[ \t\r\n\f]+/', $candidate) ?: [], true),
                '^=' => $wanted !== '' && str_starts_with($candidate, $wanted),
                '$=' => $wanted !== '' && str_ends_with($candidate, $wanted),
                '*=' => $wanted !== '' && str_contains($candidate, $wanted),
                default => false,
            };
            if ($matches) {
                return $operator === '=' ? self::ROOT_ALL : self::ROOT_MIXED;
            }
        }
        return self::ROOT_NONE;
    }

    /** @return array{decoded:string,end:int}|null */
    private static function cssIdentifierAt(string $value, int $offset): ?array
    {
        $length = strlen($value);
        $decoded = '';
        $start = $offset;
        while ($offset < $length) {
            $byte = $value[$offset];
            if ($byte === '\\') {
                $escape = self::decodedCssEscapeAt($value, $offset);
                if ($escape === null) {
                    return null;
                }
                $decoded .= $escape['decoded'];
                $offset = $escape['end'];
                continue;
            }
            if (!self::isCssIdentifierByte($byte)) {
                break;
            }
            $decoded .= $byte;
            $offset++;
        }
        return $offset === $start ? null : ['decoded' => $decoded, 'end' => $offset];
    }

    private static function decodeCssIdentifierText(string $value): ?string
    {
        $value = trim($value);
        $identifier = self::cssIdentifierAt($value, 0);
        return $identifier !== null && $identifier['end'] === strlen($value)
            ? $identifier['decoded']
            : null;
    }

    private static function decodeCssEscapes(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            if ($value[$offset] !== '\\') {
                $decoded .= $value[$offset++];
                continue;
            }
            $escape = self::decodedCssEscapeAt($value, $offset);
            if ($escape === null) {
                return null;
            }
            $decoded .= $escape['decoded'];
            $offset = $escape['end'];
        }
        return $decoded;
    }

    /** @return array{decoded:string,end:int}|null */
    private static function decodedCssEscapeAt(string $value, int $offset): ?array
    {
        $end = CssSyntaxScanner::escapeEnd($value, $offset);
        if ($end === null) {
            return null;
        }
        $cursor = $offset + 1;
        if (!isset($value[$cursor])) {
            return null;
        }
        if (!ctype_xdigit($value[$cursor])) {
            if ($value[$cursor] === "\r" || $value[$cursor] === "\n" || $value[$cursor] === "\f") {
                return ['decoded' => '', 'end' => $end];
            }
            return ['decoded' => $value[$cursor], 'end' => $end];
        }
        $hex = '';
        while ($cursor < strlen($value) && strlen($hex) < 6 && ctype_xdigit($value[$cursor])) {
            $hex .= $value[$cursor++];
        }
        $codepoint = hexdec($hex);
        if ($codepoint === 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            $decoded = "\u{FFFD}";
        } elseif ($codepoint <= 0x7F) {
            $decoded = chr($codepoint);
        } else {
            $decoded = html_entity_decode('&#' . $codepoint . ';', ENT_NOQUOTES, 'UTF-8');
        }
        return ['decoded' => $decoded, 'end' => $end];
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
    /**
     * Parse every qualified-rule body so nested selectors inherit the actual
     * resolved parent context. Direct declarations are neutralized only when
     * this rule's subject is known to be a delivered section root.
     *
     * @param array{ids:array<string,true>,roots:list<\DOMElement>,elements:list<\DOMElement>} $sectionRootIds
     * @param list<string> $parentSelectors
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     */
    private static function rewriteRuleBody(
        string $body,
        array $sectionRootIds,
        array $parentSelectors,
        bool $neutralizeDirect,
        bool &$changed,
        array &$removals,
        ?string &$error,
    ): ?string {
        $length = strlen($body);
        $state = CssSyntaxScanner::state();
        $start = 0;
        $out = '';
        for ($offset = 0; $offset < $length;) {
            $topLevel = CssSyntaxScanner::isTopLevel($state);
            $byte = $body[$offset];
            if ($topLevel && $byte === '}') {
                $error = "unexpected nested-rule closer at byte {$offset}";
                return null;
            }
            if ($topLevel && $byte === ';') {
                $segment = substr($body, $start, $offset - $start);
                if ($neutralizeDirect) {
                    $rewritten = self::rewriteRootDeclaration($segment, $removals, $error);
                    if ($rewritten === null) {
                        return null;
                    }
                    $out .= $rewritten['text'] . ';';
                    $changed = $changed || $rewritten['changed'];
                } else {
                    $out .= $segment . ';';
                }
                $start = $offset + 1;
                $offset++;
                continue;
            }
            if ($topLevel && $byte === '{') {
                $close = self::matchingBrace($body, $offset, $error);
                if ($close === null) {
                    return null;
                }
                $prelude = substr($body, $start, $offset - $start);
                if (self::isCssTrivia($prelude)) {
                    $error = "nested rule has no selector at byte {$offset}";
                    return null;
                }
                $nestedBody = substr($body, $offset + 1, $close - $offset - 1);
                $nestedAtRule = self::atRuleName($prelude);
                if ($nestedAtRule !== null && in_array(
                    $nestedAtRule,
                    ['media', 'supports', 'container', 'layer', 'scope', 'document', 'starting-style'],
                    true,
                )) {
                    $nestedChanged = false;
                    $nestedBody = self::rewriteRuleBody(
                        $nestedBody,
                        $sectionRootIds,
                        $parentSelectors,
                        $neutralizeDirect,
                        $nestedChanged,
                        $removals,
                        $error,
                    );
                    if ($nestedBody === null) {
                        return null;
                    }
                    $changed = $changed || $nestedChanged;
                    $out .= $prelude . '{' . $nestedBody . '}';
                } elseif ($nestedAtRule !== null) {
                    $out .= $prelude . '{' . $nestedBody . '}';
                } else {
                    $nestedRule = self::rewriteQualifiedRule(
                        $prelude,
                        $nestedBody,
                        $sectionRootIds,
                        $removals,
                        $error,
                        $parentSelectors,
                    );
                    if ($nestedRule === null) {
                        return null;
                    }
                    $changed = $changed || $nestedRule !== $prelude . '{' . $nestedBody . '}';
                    $out .= $nestedRule;
                }
                $offset = $close + 1;
                $start = $offset;
                $state = CssSyntaxScanner::state();
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
        $tail = substr($body, $start);
        if (!$neutralizeDirect) {
            return $out . $tail;
        }
        $rewritten = self::rewriteRootDeclaration($tail, $removals, $error);
        if ($rewritten === null) {
            return null;
        }
        $changed = $changed || $rewritten['changed'];
        return $out . $rewritten['text'];
    }

    /**
     * @param list<array{authored_value:string,delivered_value:string,disposition:string}> $removals
     * @return array{text:string,changed:bool}|null
     */
    private static function rewriteRootDeclaration(
        string $segment,
        array &$removals,
        ?string &$error,
    ): ?array
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
        $property = self::decodeCssIdentifierText((string) $property);
        $property = $property === null ? '' : strtolower($property);
        if (!in_array($property, ['padding', 'padding-inline', 'padding-left', 'padding-right'], true)) {
            return ['text' => $segment, 'changed' => false];
        }

        [$leading] = self::leadingTriviaAndRest($segment);
        if ($property !== 'padding') {
            return ['text' => $leading, 'changed' => true];
        }

        $padding = self::paddingComponents(substr($segment, $colon + 1), $error);
        if ($padding === null) {
            return null;
        }
        if (self::hasOpaquePaddingComponent($padding['values'])) {
            $removals[] = [
                'authored_value' => trim($segment) . ';',
                'delivered_value' => 'removed',
                'disposition' => 'removed_opaque_padding_shorthand',
            ];
            return ['text' => $leading, 'changed' => true];
        }
        $top = $padding['values'][0];
        $bottom = match (count($padding['values'])) {
            1, 2 => $padding['values'][0],
            3, 4 => $padding['values'][2],
        };
        $important = $padding['important'];
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

    /** @return array{values:list<string>,important:string}|null */
    private static function paddingComponents(string $raw, ?string &$error): ?array
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
        return ['values' => $values, 'important' => $important];
    }

    /** @param list<string> $values */
    private static function hasOpaquePaddingComponent(array $values): bool
    {
        foreach ($values as $value) {
            $function = self::paddingComponentFunctionName($value);
            if ($function !== null && !in_array($function, ['calc', 'min', 'max', 'clamp'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function paddingComponentFunctionName(string $value): ?string
    {
        $value = ltrim($value, " \t\r\n\f");
        $identifier = self::cssIdentifierAt($value, 0);
        if ($identifier === null) {
            return null;
        }
        $open = self::skipSelectorTrivia($value, $identifier['end']);
        return ($value[$open] ?? '') === '('
            ? strtolower($identifier['decoded'])
            : null;
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

    private static function warningSourceSpelling(string $value): string
    {
        return str_replace(
            ["\r\n", "\r", "\n", "\f"],
            ['\\n', '\\r', '\\n', '\\f'],
            $value,
        );
    }

    /**
     * Design sources of the pages that still carry a design, for callers that
     * only need the artifacts and not which page each one belongs to.
     *
     * @return list<string>
     */
    private static function deliveredDesignSources(Project $project): array
    {
        return array_keys(self::deliveredDesignPages($project));
    }

    /**
     * Design source of every page that still carries a design, keyed by source
     * and sorted by it, with the semantic page slug — the `post_name` the
     * content plugin creates the page with, and so the key its scope class is
     * built from — as the value.
     *
     * @return array<string,string> design source path => semantic page slug
     */
    private static function deliveredDesignPages(Project $project): array
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
            $sources["design/{$artifactSlug}.html"] = $slug;
        }
        ksort($sources, SORT_STRING);
        return $sources;
    }

    /**
     * The browser heading baseline, covering every page that carries a design.
     *
     * Pages routed through the legacy tail carry no design CSS and depend on
     * theme.json for their headings, so the reset never reaches them.
     *
     * @param list<string> $pageSlugs
     */
    public static function headingBaselineCss(array $pageSlugs): string
    {
        if ($pageSlugs === []) {
            return '';
        }
        $scopes = implode(
            ', ',
            array_map(
                static fn (string $slug): string => '.' . PageScope::bodyClass($slug),
                $pageSlugs,
            ),
        );

        return "/* Headings the design page never styles render at the browser default. */\n"
            . ":where({$scopes}) :is(h1, h2, h3, h4, h5, h6) {\n"
            . self::HEADING_BASELINE_DECLARATIONS . "\n}\n";
    }

    /**
     * Scope one page's authored CSS to that page, keeping the unscoped bytes
     * and warning when the rewrite cannot be proven safe: a page that renders
     * with a sibling's rules is still better than a page with no CSS at all.
     *
     * @param list<string> $warnings
     */
    private static function scopeChunkToPage(
        string $css,
        string $pageSlug,
        string $source,
        array &$warnings,
    ): string {
        $error = null;
        $scoped = self::scopeRuleList($css, PageScope::bodyClass($pageSlug), $error);
        if ($scoped !== null) {
            return $scoped;
        }
        $warnings[] = sprintf(
            'source=%s; authored_value=%s; delivered_value=%s; disposition=%s',
            $source,
            json_encode(
                'page CSS scoped to the ' . $pageSlug . ' page',
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            'delivered site-wide',
            'kept unscoped page CSS (' . ($error ?? 'unrewritable stylesheet') . ')',
        );
        return $css;
    }

    /**
     * Rewrite a stylesheet so every qualified rule only matches inside one
     * page, recursing through grouping at-rules.
     *
     * `:where()` weighs nothing, so a scoped branch keeps exactly the
     * specificity the design wrote and the authored cascade survives intact.
     * Rules whose subject is the document root stay global: they carry the
     * custom properties the rest of the stylesheet reads. Non-grouping at-rules
     * — `@keyframes` above all, whose `from`/`to` are not element selectors —
     * are carried verbatim. Returns null on syntax this bounded rewriter cannot
     * prove safe.
     */
    private static function scopeRuleList(
        string $css,
        string $scopeClass,
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
                    if (self::isGroupingAtRule($atRule)) {
                        $body = self::scopeRuleList($body, $scopeClass, $error);
                        if ($body === null) {
                            return null;
                        }
                    }
                    $out .= $prelude . '{' . $body . '}';
                } else {
                    $branches = self::splitSelectorList($prelude, $error);
                    if ($branches === null) {
                        return null;
                    }
                    $scoped = [];
                    foreach ($branches as $branch) {
                        $scoped[] = self::scopeSelectorBranch($branch, $scopeClass);
                    }
                    $out .= implode(',', $scoped) . '{' . $body . '}';
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

    /**
     * The scope class lands on `<body>`, so a rule whose subject is the body
     * takes it on the compound itself; everything else takes it as an
     * ancestor. `html` and `:root` are left alone — a page chunk that sets a
     * custom property there is speaking for the whole document.
     */
    private static function scopeSelectorBranch(string $branch, string $scopeClass): string
    {
        [$trivia, $rest] = self::leadingTriviaAndRest($branch);
        $selector = rtrim($rest);
        $trailing = substr($rest, strlen($selector));
        if ($selector === '' || preg_match('/\A(?:html|:root)(?![\w-])/i', $selector) === 1) {
            return $branch;
        }
        if (preg_match('/\Abody(?![\w-])/i', $selector) === 1) {
            return $trivia . 'body:where(.' . $scopeClass . ')'
                . substr($selector, 4) . $trailing;
        }
        return $trivia . ':where(.' . $scopeClass . ') ' . $selector . $trailing;
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

        // A stray `}` here would leave the appendix with a dangling open rule
        // that swallows whatever is appended to style.css after it (the
        // custom-motion block ships later in the pipeline).
        if (!CssChecks::braceDepthBalanced($stripped)) {
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
        $resource = CssChecks::resourceLoadingProblem($stripped);
        if ($resource !== null) {
            $problems[] = $resource;
        }
        if (preg_match('/--motion-[\w-]+\s*:/i', $stripped) === 1) {
            $problems[] = 'motion custom properties are profile-owned and cannot be overridden';
        }
        foreach (CssChecks::scanDeclarations($stripped) as $declaration) {
            if (self::declarationTargetsShape($declaration)
                && CssChecks::isShapeAffectingDeclaration(
                    $declaration['property'],
                    $declaration['value'],
                )
            ) {
                $problems[] = 'contained-image/button corner declarations are shape-owned by the design direction';
                break;
            }
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
        foreach (CssChecks::hiddenContentProblems($stripped) as $problem) {
            $problems[] = $problem;
        }
        foreach (CssChecks::disallowedAtRules($stripped, ['media']) as $problem) {
            $problems[] = $problem;
        }

        // Every style rule's selector must be scoped under a documented class.
        $allowed = implode('|', array_map(
            static fn (string $c): string => preg_quote($c, '/'),
            array_keys(self::CLASSES)
        ));
        $isScoped = static fn (string $selector): bool =>
            preg_match('/^\.(?:' . $allowed . ')(?![\w-])/', $selector) === 1;
        foreach (CssChecks::unscopedSelectors($stripped, $isScoped) as $selector) {
            $problems[] = "selector not scoped under a documented utility class: {$selector}";
        }

        return $problems;
    }

    /**
     * Salvage pass for CSS that failed validate(): remove each declaration
     * that carries a declaration-level offence (raw color literal, resource-
     * loading function, --motion-* override, shape-owned corner radius,
     * content-hiding value) and keep the rest. Only rule bodies are touched —
     * selectors, @media preludes and brace structure pass through, so
     * structural problems deliberately survive into the re-validation and
     * still reject the whole appendix. A quote/comment/function-aware shared
     * scanner supplies exact source spans, so untouched declarations and
     * declaration-looking text values stay byte-identical. Pure — unit-testable.
     *
     * @return array{0: string, 1: string[]} [salvaged CSS, dropped-declaration notes]
     */
    public static function dropOffendingDeclarations(string $css): array
    {
        $problems = [];
        foreach (CssChecks::scanDeclarations($css) as $declaration) {
            $problem = self::declarationProblem(
                $declaration['raw'],
                self::declarationTargetsShape($declaration),
            );
            if ($problem !== null) {
                $problems[$declaration['start']] = $problem;
            }
        }
        [$salvaged, $droppedRows] = CssChecks::dropDeclarations(
            $css,
            static fn (array $declaration): bool => isset($problems[$declaration['start']]),
        );
        $dropped = array_map(
            static fn (array $declaration): string => trim((string) preg_replace(
                '/\s+/',
                ' ',
                $declaration['raw'],
            )) . ' (' . $problems[$declaration['start']] . ')',
            $droppedRows,
        );
        return [$salvaged, array_values($dropped)];
    }

    /**
     * The declaration-level offence in one `property: value` declaration, or
     * null when it is clean. Mirrors the declaration-level checks in
     * validate(); anything unparsable is dropped too — the salvage pass fails
     * closed.
     */
    private static function declarationProblem(string $declaration, bool $targetsShape = false): ?string
    {
        if (preg_match('/^\s*([-\w]+)\s*:\s*(\S[\s\S]*)$/', $declaration, $m) !== 1) {
            return 'not a single property: value declaration';
        }
        $property = strtolower($m[1]);
        $value = $m[2];
        if (str_starts_with($property, '--motion-')) {
            return 'motion custom properties are profile-owned';
        }
        if ($targetsShape && CssChecks::isShapeAffectingDeclaration($property, $value)) {
            return 'contained-image/button corner is shape-owned by the design direction';
        }
        if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $value) === 1
            || preg_match('/\b(?:rgba?|hsla?)\s*\(/i', $value) === 1
            || self::rawNamedColorProblems("{$property}: {$value}") !== []
        ) {
            return 'raw color literal';
        }
        if (CssChecks::resourceLoadingProblem($value) !== null) {
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

    /**
     * @param array{context:string,kind:string} $declaration
     */
    private static function declarationTargetsShape(array $declaration): bool
    {
        return $declaration['kind'] === 'style'
            && CssChecks::selectorTargetsShape($declaration['context']);
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
}
