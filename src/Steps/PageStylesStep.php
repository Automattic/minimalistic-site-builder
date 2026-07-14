<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;

/**
 * Step (LLM): generate the CSS for the layout utility classes the sections used.
 *
 * Input:  designDirection.md + theme/theme.json + the final section markup
 *         (theme/parts/*.html, theme/templates/*.html — i.e. AFTER fix-blocks).
 * Output: a small plain-CSS appendix appended to theme/style.css.
 *
 * prompts/section.md documents a fixed vocabulary of utility classes (CLASSES
 * below) that sections MAY reference via "className" — devices like overlap,
 * masonry, and hover treatments that block attributes alone cannot express.
 * Class names on group/columns blocks survive the block-fixer's re-serialization,
 * and style.css is never touched by the fixer, so this pairing is the one
 * `<style>`-free channel for real CSS. This step runs after fix-blocks, scans
 * the final markup for which documented classes actually appear, and asks the
 * model to implement exactly those, tuned to the design direction.
 *
 * The model's CSS is validated (validate()) before writing: every selector must
 * be scoped under a documented class, colors must come from theme preset custom
 * properties, and only @media at-rules are allowed. Rejected CSS is logged and
 * the appendix is skipped rather than failing the build — a utility class
 * without its CSS still renders as a plain block, so degrading (loudly) beats
 * losing a finished build at its final step over decorative styling.
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
        'hover-lift'   => 'a transition — duration calc(var(--motion-duration, 500ms) / 2), easing var(--motion-ease, ease), so hover timing follows the motion profile — then on :hover a small translateY lift and a shadow',
        'hover-reveal' => 'the container crops (overflow:hidden; position:relative); its img scales slightly and dims on hover, with the same calc(var(--motion-duration, 500ms) / 2) / var(--motion-ease, ease) transition timing; captions/text remain visible at rest — do not set opacity:0, visibility:hidden, or display:none on children because Gutenberg wraps images in figure elements',
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

    public function run(Project $project): void
    {
        $used = self::usedClasses($project);
        if ($used === []) {
            echo "  no layout utility classes referenced; nothing to style\n";
            return;
        }

        $profile = DesignDirectionStep::motionProfileFor($project);
        $rendered = $this->renderer->render('page-styles.md', [
            'design_direction' => DesignDirectionStep::readFor($project),
            'theme_json'       => $project->readText('theme/theme.json'),
            'used_classes'     => self::classList($used),
            'motion_tuning'    => $profile === 'none' ? '' : "\n" . self::motionTuningBrief($profile) . "\n",
        ]);
        $css = self::stripFences(trim(
            $this->llm->complete($rendered, $this->withOptions(['log_label' => $this->id()]))
        ));

        // The optional :root --motion-* override rides the same response but
        // lives under its own contract: numeric bounds instead of selector
        // scoping. Validate the two pieces independently — a rejected tuning
        // block silently falls back to the profile defaults, and a rejected
        // class appendix doesn't take a valid tuning down with it.
        [$rootBlocks, $css] = self::splitRootBlocks($css);
        $log = [];
        $override = null;
        if ($rootBlocks !== []) {
            $overrideProblems = self::validateMotionOverride($rootBlocks, $profile);
            if ($overrideProblems === []) {
                $override = implode("\n", $rootBlocks);
            } else {
                $log[] = "DROPPED MOTION OVERRIDE (profile defaults apply):\n"
                    . implode("\n", $rootBlocks)
                    . "\n\nPROBLEMS:\n- " . implode("\n- ", $overrideProblems);
            }
        }

        $problems = $css === '' ? [] : self::validate($css);
        if ($problems !== []) {
            $log[] = "REJECTED CSS:\n{$css}\n\nPROBLEMS:\n- " . implode("\n- ", $problems);
            echo '  page-styles: CSS rejected (' . count($problems)
                . ' problem(s)); appendix skipped — see logs/' . self::LOG_FILE . "\n";
            $css = '';
        }
        if ($log !== []) {
            file_put_contents($project->logPath(self::LOG_FILE), implode("\n\n", $log) . "\n");
        }

        $appendix = trim(($override ?? '') . "\n\n" . $css);
        if ($appendix === '') {
            return;
        }
        $project->writeText(
            'theme/style.css',
            rtrim($project->readText('theme/style.css')) . "\n\n" . self::MARKER . "\n" . $appendix . "\n"
        );
        if ($css !== '') {
            echo '  styled: ' . implode(', ', $used) . "\n";
        }
        if ($override !== null) {
            echo "  motion tuning: --motion-* override accepted\n";
        }
    }

    /**
     * The MOTION TUNING prompt block offered when the site ships the motion
     * kit: one optional :root override of the profile variables, bounded by
     * the exact ranges validateMotionOverride() enforces.
     */
    public static function motionTuningBrief(string $profile): string
    {
        [$durMin, $durMax] = Motion::DURATION_MS;
        [$distMin, $distMax] = Motion::DISTANCE_PX;
        [$stagMin, $stagMax] = Motion::STAGGER_MS;
        return "MOTION TUNING (optional): the theme ships a static motion kit driven by CSS custom properties, "
            . "currently set by the '{$profile}' motion profile. You MAY additionally emit ONE `:root { … }` block "
            . "tuning some of these variables to the design direction's mood — or omit it to keep the profile defaults:\n"
            . "- `--motion-duration`: {$durMin}ms–{$durMax}ms\n"
            . "- `--motion-distance`: {$distMin}px–{$distMax}px\n"
            . "- `--motion-stagger`: {$stagMin}ms–{$stagMax}ms\n"
            . '- `--motion-ease`: one of ' . implode(', ', Motion::EASING_ALLOWLIST) . "\n"
            . 'Only `--motion-*` declarations may appear in that block; an out-of-range value drops the whole block.';
    }

    /**
     * Pull every top-level `:root { … }` block out of the CSS, returning the
     * blocks and the remaining stylesheet. Pure — unit-testable.
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function splitRootBlocks(string $css): array
    {
        $blocks = [];
        $rest = (string) preg_replace_callback(
            '/:root\s*\{[^{}]*\}/',
            static function (array $m) use (&$blocks): string {
                $blocks[] = trim($m[0]);
                return '';
            },
            $css
        );
        return [$blocks, trim($rest)];
    }

    /**
     * Validate the :root motion-override block(s) the model may emit alongside
     * the class appendix: exactly one block, only --motion-* declarations,
     * every value inside the Motion bounds, easing from the allowlist — and
     * none at all when the profile is `none` (no kit ships to tune). Returns
     * problem strings; empty = valid. Pure — unit-testable.
     *
     * @param list<string> $blocks
     * @return string[]
     */
    public static function validateMotionOverride(array $blocks, string $profile = 'calm'): array
    {
        if ($blocks === []) {
            return ['no :root block'];
        }
        if ($profile === 'none') {
            return ['motion profile is none — no motion kit ships to tune'];
        }
        if (count($blocks) > 1) {
            return ['more than one :root block'];
        }

        if (preg_match('/\{([^{}]*)\}/', $blocks[0], $m) !== 1) {
            return ['unparseable :root block'];
        }
        $body = (string) preg_replace('~/\*.*?\*/~s', '', $m[1]);

        $problems = [];
        $declarations = 0;
        foreach (array_filter(array_map('trim', explode(';', $body))) as $declaration) {
            if (preg_match('/^--motion-(duration|distance|stagger|ease)\s*:\s*(.+)$/s', $declaration, $d) !== 1) {
                $problems[] = "only --motion-duration/-distance/-stagger/-ease may be overridden: {$declaration}";
                continue;
            }
            $declarations++;
            $value = trim($d[2]);
            $problem = match ($d[1]) {
                'duration' => self::rangeProblem($value, 'ms', Motion::DURATION_MS),
                'distance' => self::rangeProblem($value, 'px', Motion::DISTANCE_PX),
                'stagger'  => self::rangeProblem($value, 'ms', Motion::STAGGER_MS),
                'ease'     => self::easingProblem($value),
            };
            if ($problem !== null) {
                $problems[] = "--motion-{$d[1]}: {$problem}";
            }
        }
        if ($declarations === 0 && $problems === []) {
            $problems[] = 'empty :root block';
        }
        return $problems;
    }

    /** Bounds-check one length/time value ("600ms", "0.6s", "24px"). */
    private static function rangeProblem(string $value, string $unit, array $range): ?string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s|px)$/i', $value, $m) !== 1) {
            return "'{$value}' is not a plain {$unit} value";
        }
        $number = (float) $m[1];
        $valueUnit = strtolower($m[2]);
        if ($unit === 'ms' && $valueUnit === 's') {
            $number *= 1000;
        } elseif ($valueUnit !== $unit) {
            return "'{$value}' is not a plain {$unit} value";
        }
        [$min, $max] = $range;
        return $number < $min || $number > $max
            ? "'{$value}' is outside {$min}{$unit}–{$max}{$unit}"
            : null;
    }

    /** Easing must come from the fixed allowlist (whitespace-insensitive). */
    private static function easingProblem(string $value): ?string
    {
        $normalized = strtolower((string) preg_replace('/\s+/', '', $value));
        foreach (Motion::EASING_ALLOWLIST as $easing) {
            if ($normalized === strtolower(str_replace(' ', '', $easing))) {
                return null;
            }
        }
        return "'{$value}' is not on the easing allowlist";
    }

    /**
     * Which documented utility classes the built theme actually references,
     * scanning the final parts and templates.
     *
     * @return string[]
     */
    public static function usedClasses(Project $project): array
    {
        $markup = '';
        foreach (['parts', 'templates'] as $dir) {
            foreach (glob($project->themePath($dir) . '/*.html') ?: [] as $file) {
                $markup .= "\n" . (string) file_get_contents($file);
            }
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
        if (preg_match('/\burl\s*\(/i', $stripped) === 1) {
            $problems[] = 'url() is not allowed';
        }
        if (preg_match('/(?<![-\w])opacity\s*:\s*0(?:\.0+)?\s*(?:!important\s*)?(?:;|$)/i', $stripped) === 1) {
            $problems[] = 'opacity:0 hides generated content';
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
