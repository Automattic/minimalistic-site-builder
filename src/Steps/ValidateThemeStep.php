<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\DesignFloor;
use Automattic\SiteBuild\DirectionFidelity;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\PresetReferences;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Final deterministic validation pass for contracts that downstream
 * serializers can invalidate: block structure, layout normalization, preset
 * references, and page-owned vertical rhythm.
 *
 * Not a gate: by the time this runs the theme is fully built, and rejecting
 * it over a residual defect would leave the user with no site at all. Every
 * problem is recorded in warnings.json (see Project::replaceWarnings) and the
 * theme is delivered anyway.
 */
final class ValidateThemeStep implements Step
{
    private const LOG_FILE = 'validate-theme.log';

    private const HEADER_ASSETS = [
        'theme/assets/header/header.css',
        'theme/assets/header/header.js',
    ];

    public function __construct(private bool $htmlFirst = false) {}

    public function id(): string
    {
        return 'validate-theme';
    }

    public function label(): string
    {
        return 'Validate final theme';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                // layoutWarnings dry-runs the build's normalization, which
                // consults the design's stylesheet for roots that own their
                // width. Only the HTML-first graph has one to read.
                ...($this->htmlFirst ? ['design/site.css'] : []),
                'pages.json',
                'aboveFold.json',
                'headerBehavior.json',
                'theme/style.css',
                'theme/theme.json',
                'theme/functions.php',
                'theme/assets/header/*',
                'theme/templates/index.html',
                'theme/templates/page.html',
                'theme/parts/header.html',
                'theme/parts/footer.html',
                'theme/parts/*',
                'theme/templates/*',
                'theme/patterns/*',
                'plugin/pages/*',
                'plugin/pages.json',
                'designDirection.json',
            ],
            writes: [
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $problems = array_merge(
            ThemeValidator::validate($project),
            ThemeValidator::layoutWarnings($project, $this->htmlFirst),
            ThemeValidator::spacingWarnings($project),
            ThemeValidator::typographyWarnings($project),
            ThemeValidator::planWarnings($project),
            ThemeValidator::aboveFoldWarnings($project),
            // The direction is a set of promises made before generation that
            // every later step reads and nothing checks afterwards. This pass
            // already reads the same artifacts one line from here.
            DirectionFidelity::problems($project, $this->htmlFirst),
            PresetReferences::problems($project),
            self::styleElementProblems($project),
            self::headerBehaviorProblems($project),
            self::designFloorProblems($project),
        );
        $problems = array_values(array_unique($problems));

        // postImages runs this step a second time, after cover-contrast and
        // extract-patterns have rewritten the very bytes the in-graph run
        // judged. This pass re-checks all of them, so it owns the step's rows
        // outright: merging would keep a residual on record that the final
        // theme no longer has, and clearing on an empty set is what makes the
        // "passed" log below true.
        $project->replaceWarnings($this->id(), $problems);

        if ($problems !== []) {
            $report = 'Final theme validation found ' . count($problems)
                . " problem(s); theme delivered anyway, problems recorded in warnings.json:\n- "
                . implode("\n- ", $problems) . "\n";
            $project->writeText('logs/' . self::LOG_FILE, $report);
            Narrator::write('  [validate-theme] warning: ' . count($problems)
                . ' problem(s) recorded in warnings.json; see logs/' . self::LOG_FILE . "\n");
            return;
        }

        $project->writeText('logs/' . self::LOG_FILE, "Final theme validation passed.\n");
        Narrator::write("  final theme validation passed\n");
    }

    /**
     * Advisory design-floor scan of assembled plugin pages and theme.json.
     * Report only — never mutates generated markup. Findings are warnings,
     * never build failures.
     *
     * @return list<string>
     */
    private static function designFloorProblems(Project $project): array
    {
        $theme = [];
        if ($project->exists('theme/theme.json')) {
            $decoded = json_decode($project->readText('theme/theme.json'), true);
            $theme = is_array($decoded) ? $decoded : [];
        }

        $problems = [];
        foreach (glob($project->pluginPath('pages') . '/*.html') ?: [] as $abs) {
            $rel = 'plugin/pages/' . basename($abs);
            foreach (DesignFloor::check($project->readText($rel), []) as $finding) {
                $problems[] = DesignFloor::warningRow($rel, $finding);
            }
        }
        foreach (DesignFloor::check('', $theme) as $finding) {
            $problems[] = DesignFloor::warningRow('theme/theme.json', $finding);
        }
        return $problems;
    }

    /**
     * Advisory validation of the resolved two-state header contract. Nothing
     * here mutates the already-usable theme: every residual is delivered and
     * recorded with enough file/value/disposition context for a repair pass.
     *
     * The artifact gate is HeaderBehavior::validateArtifact — the exact call
     * AssemblePagesStep and FinalizeThemeStep run — so this validator can
     * never disagree with the consumers about which artifacts are acceptable.
     * When the consumers degrade to a static header (missing, malformed, or
     * rejected artifact), the theme is judged against that pruned static
     * expectation rather than the artifact's claimed behavior; anything else
     * would direct a repair pass to re-add wiring the consumers removed on
     * purpose. Each artifact defect yields exactly one problem row.
     *
     * @return list<string>
     */
    private static function headerBehaviorProblems(Project $project): array
    {
        $problems = [];
        $decoded = self::readHeaderArtifact($project, $problems);
        if ($decoded === null) {
            self::checkDegradedStaticResidue($project, $problems);
            return $problems;
        }

        try {
            $artifact = HeaderBehavior::validateArtifact($decoded);
        } catch (\InvalidArgumentException $error) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                self::value($decoded),
                'static',
                'closed header behavior contract is invalid: ' . $error->getMessage(),
            );
            self::checkDegradedStaticResidue($project, $problems);
            return $problems;
        }

        $behavior = $artifact['behavior'];

        if (!$project->exists('theme/theme.json')) {
            // ThemeValidator reports the structural absence itself; this row
            // records that the palette-dependent header checks could not run.
            // Project::readText throws on a missing file, and this advisory
            // step must never abort over one.
            $problems[] = self::headerProblem(
                'theme/theme.json',
                '<missing>',
                '<missing>',
                'palette membership and contrast checks skipped: theme/theme.json is absent',
            );
        } else {
            $theme = json_decode($project->readText('theme/theme.json'), true);
            $palette = is_array($theme) ? ContrastFixStep::paletteMap($theme) : [];
            foreach (['topSurface', 'scrolledSurface', 'foreground'] as $field) {
                $slug = $artifact[$field];
                if ($field === 'topSurface' && $slug === HeaderBehavior::TRANSPARENT) {
                    continue;
                }
                if (!array_key_exists($slug, $palette)) {
                    $problems[] = self::headerProblem(
                        'headerBehavior.json',
                        "{$field}=" . self::value($slug),
                        'unchanged',
                        "{$field} must name a theme/theme.json palette slug",
                    );
                }
            }
            self::checkHeaderContrast($artifact, $palette, 'topSurface', $problems);
            self::checkHeaderContrast($artifact, $palette, 'scrolledSurface', $problems);
            self::checkHeaderTransition($artifact, $palette, $problems);
        }

        self::checkInnerHeader($project, $artifact, $problems);
        self::checkOuterTemplate(
            $project,
            'theme/templates/page.html',
            self::classTokens(AssemblePagesStep::pageHeaderClassName($behavior)),
            $problems,
        );
        self::checkOuterTemplate(
            $project,
            'theme/templates/index.html',
            self::classTokens(AssemblePagesStep::indexHeaderClassName($behavior)),
            $problems,
        );
        self::checkHeaderAssets($project, $behavior, $problems);

        return $problems;
    }

    /**
     * Decode generated JSON without turning malformed model-derived content
     * into a fatal final gate. File read failures remain genuine I/O errors.
     *
     * @param list<string> $problems
     * @return array<mixed>|null
     */
    private static function readHeaderArtifact(Project $project, array &$problems): ?array
    {
        if (!$project->exists('headerBehavior.json')) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                '<missing>',
                '<missing>',
                'required resolved header behavior artifact is absent',
            );
            return null;
        }
        try {
            $decoded = json_decode($project->readText('headerBehavior.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                '<invalid JSON: ' . $error->getMessage() . '>',
                'unchanged',
                'malformed generated header behavior could not be validated',
            );
            return null;
        }
        if (!is_array($decoded)) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                self::value($decoded),
                'unchanged',
                'header behavior artifact must be a JSON object',
            );
            return null;
        }
        return $decoded;
    }

    /**
     * The delivered expectation once the consumers degrade: AssemblePagesStep
     * and FinalizeThemeStep answer a missing, malformed, or rejected artifact
     * by assembling the static templates and pruning the adaptive-header kit.
     * Hold the theme to that same static expectation — no retained inner
     * state classes, no outer shell classes, no state assets or enqueues.
     *
     * @param list<string> $problems
     */
    private static function checkDegradedStaticResidue(Project $project, array &$problems): void
    {
        $rel = 'theme/parts/header.html';
        if ($project->exists($rel)) {
            $doc = BlockMarkup::parse($project->readText($rel));
            $top = $doc->topLevel();
            if ($top !== null) {
                $attrs = $doc->attrs($top) ?? [];
                $attributeTokens = self::classTokens(
                    is_string($attrs['className'] ?? null) ? $attrs['className'] : null,
                );
                $state = array_values(array_unique(array_merge(
                    self::innerStateTokens($attributeTokens),
                    self::innerStateTokens(self::htmlClassTokens($doc->ownHtml($top))),
                )));
                foreach ($state as $class) {
                    $problems[] = self::headerProblem(
                        $rel,
                        "state class='{$class}'",
                        'unchanged',
                        'static behavior must not retain header state classes',
                    );
                }
            }
        }
        self::checkOuterTemplate(
            $project,
            'theme/templates/page.html',
            self::classTokens(AssemblePagesStep::pageHeaderClassName(HeaderBehavior::STATIC)),
            $problems,
        );
        self::checkOuterTemplate(
            $project,
            'theme/templates/index.html',
            self::classTokens(AssemblePagesStep::indexHeaderClassName(HeaderBehavior::STATIC)),
            $problems,
        );
        self::checkHeaderAssets($project, HeaderBehavior::STATIC, $problems);
    }

    /**
     * Model-authored <style> elements are stripped at markup intake
     * (MarkupSanitizer); one surviving in a delivered template or part means
     * the markup reached the theme without passing that choke point, and a
     * single rule such as `.site-header-shell{position:fixed}` would override
     * the trusted header shell's position ownership.
     *
     * @return list<string>
     */
    private static function styleElementProblems(Project $project): array
    {
        $problems = [];
        foreach ($project->themeFiles() as $rel) {
            $count = preg_match_all('/<style\b/i', $project->readText('theme/' . $rel));
            if ($count > 0) {
                $problems[] = "theme/{$rel}: contains {$count} <style> element(s) — delivered CSS belongs to "
                    . 'the trusted theme assets, not generated markup; disposition: remove the <style> element(s)';
            }
        }
        return $problems;
    }

    /**
     * @param array{behavior:string,mode:string,transition:string,topSurface:string,scrolledSurface:string,foreground:string} $artifact
     * @param array<string,string> $palette
     * @param list<string> $problems
     */
    private static function checkHeaderContrast(
        array $artifact,
        array $palette,
        string $surfaceField,
        array &$problems,
    ): void {
        $surface = $artifact[$surfaceField];
        if ($surface === 'transparent') {
            $foreground = $artifact['foreground'];
            $fg = isset($palette[$foreground]) ? ContrastMath::hexToRgb($palette[$foreground]) : null;
            if ($fg === null) {
                return;
            }
            $ratio = ContrastMath::ratio($fg, HeaderBehavior::OVERLAY_WORST_CASE_RGB);
            if ($ratio < ContrastMath::NORMAL_TEXT) {
                $problems[] = self::headerProblem(
                    'headerBehavior.json',
                    sprintf(
                        'foreground=%s on trusted 60%% overlay scrim worst case #666666 (contrast %.2f:1)',
                        self::value($foreground),
                        $ratio,
                    ),
                    'unchanged',
                    sprintf(
                        'top header state is below the %.1f:1 normal-text contrast minimum',
                        ContrastMath::NORMAL_TEXT,
                    ),
                );
            }
            return;
        }
        $foreground = $artifact['foreground'];
        $fg = isset($palette[$foreground]) ? ContrastMath::hexToRgb($palette[$foreground]) : null;
        $bg = isset($palette[$surface]) ? ContrastMath::hexToRgb($palette[$surface]) : null;
        if ($fg === null || $bg === null) {
            return; // Palette validity/membership is reported by its owning checks.
        }
        $ratio = ContrastMath::ratio($fg, $bg);
        if ($ratio >= ContrastMath::NORMAL_TEXT) {
            return;
        }
        $problems[] = self::headerProblem(
            'headerBehavior.json',
            sprintf(
                'foreground=%s on %s=%s (contrast %.2f:1)',
                self::value($foreground),
                $surfaceField,
                self::value($surface),
                $ratio,
            ),
            'unchanged',
            sprintf(
                '%s header state is below the %.1f:1 normal-text contrast minimum',
                $surfaceField === 'topSurface' ? 'top' : 'scrolled',
                ContrastMath::NORMAL_TEXT,
            ),
        );
    }

    /**
     * Endpoint contrast is not sufficient for an animated color change: an
     * sRGB interpolation can pass close to the fixed foreground even when
     * both endpoint surfaces independently meet the contrast minimum.
     *
     * @param array{behavior:string,mode:string,transition:string,topSurface:string,scrolledSurface:string,foreground:string} $artifact
     * @param array<string,string> $palette
     * @param list<string> $problems
     */
    private static function checkHeaderTransition(
        array $artifact,
        array $palette,
        array &$problems,
    ): void {
        if ($artifact['transition'] !== HeaderBehavior::TRANSITION_SMOOTH
            || $artifact['behavior'] === HeaderBehavior::STATIC) {
            return;
        }

        $foregroundSlug = $artifact['foreground'];
        $foreground = isset($palette[$foregroundSlug])
            ? ContrastMath::hexToRgb($palette[$foregroundSlug])
            : null;
        $topSlug = $artifact['topSurface'];
        $top = $topSlug === HeaderBehavior::TRANSPARENT
            ? HeaderBehavior::OVERLAY_WORST_CASE_RGB
            : (isset($palette[$topSlug]) ? ContrastMath::hexToRgb($palette[$topSlug]) : null);
        $scrolledSlug = $artifact['scrolledSurface'];
        $scrolled = isset($palette[$scrolledSlug])
            ? ContrastMath::hexToRgb($palette[$scrolledSlug])
            : null;
        if ($foreground === null || $top === null || $scrolled === null) {
            return; // Palette validity/membership is reported by its owning checks.
        }
        if (ContrastMath::ratio($foreground, $top) < ContrastMath::NORMAL_TEXT
            || ContrastMath::ratio($foreground, $scrolled) < ContrastMath::NORMAL_TEXT
            || HeaderBehavior::transitionIsSafe($foreground, $top, $scrolled)) {
            return;
        }

        $problems[] = self::headerProblem(
            'headerBehavior.json',
            'transition="smooth", foreground=' . self::value($foregroundSlug)
                . ', topSurface=' . self::value($topSlug)
                . ', scrolledSurface=' . self::value($scrolledSlug),
            'unchanged',
            'smooth background-color interpolation crosses an unreadable contrast midpoint; '
                . 'use same-side surfaces or transition="instant"',
        );
    }

    /**
     * @param array{behavior:string,mode:string,transition:string,topSurface:string,scrolledSurface:string,foreground:string} $artifact
     * @param list<string> $problems
     */
    private static function checkInnerHeader(Project $project, array $artifact, array &$problems): void
    {
        $rel = 'theme/parts/header.html';
        if (!$project->exists($rel)) {
            return; // ThemeValidator already reports the absent required part.
        }
        $doc = BlockMarkup::parse($project->readText($rel));
        $top = $doc->topLevel();
        if ($top === null) {
            if ($artifact['behavior'] !== 'static') {
                $problems[] = self::headerProblem(
                    $rel,
                    '<no top-level block>',
                    'unchanged',
                    'non-static header has no top-level block for its behavior classes',
                );
            }
            return;
        }

        $attrs = $doc->attrs($top) ?? [];
        $attributeTokens = self::classTokens(is_string($attrs['className'] ?? null) ? $attrs['className'] : null);
        $htmlTokens = self::htmlClassTokens($doc->ownHtml($top));
        $actualState = array_values(array_unique(array_merge(
            self::innerStateTokens($attributeTokens),
            self::innerStateTokens($htmlTokens),
        )));
        $required = HeaderBehavior::rootClasses($artifact);

        foreach ($required as $class) {
            if (in_array($class, $attributeTokens, true) && in_array($class, $htmlTokens, true)) {
                continue;
            }
            $problems[] = self::headerProblem(
                $rel,
                'className=' . self::value($attributeTokens) . ', saved wrapper classes=' . self::value($htmlTokens),
                'unchanged',
                "required inner header class '{$class}' is missing from block attributes or saved HTML",
            );
        }
        foreach (array_diff($actualState, $required) as $class) {
            $problems[] = self::headerProblem(
                $rel,
                "state class='{$class}'",
                'unchanged',
                $artifact['behavior'] === 'static'
                    ? 'static behavior must not retain header state classes'
                    : 'inner header state class does not match headerBehavior.json',
            );
        }

        $topSurface = $artifact['topSurface'];
        $actualBackground = $attrs['backgroundColor'] ?? null;
        if ($topSurface === HeaderBehavior::TRANSPARENT) {
            if ($actualBackground !== null
                || isset($attrs['gradient'])
                || isset($attrs['style']['color']['background'])) {
                $problems[] = self::headerProblem(
                    $rel,
                    'root background=' . self::value([
                        'backgroundColor' => $actualBackground,
                        'gradient' => $attrs['gradient'] ?? null,
                        'custom' => $attrs['style']['color']['background'] ?? null,
                    ]),
                    'unchanged',
                    'transparent topSurface requires no model-authored root background',
                );
            }
            $savedBackgrounds = array_values(array_filter(
                $htmlTokens,
                static fn (string $token): bool => $token === 'has-background'
                    || (bool) preg_match('/^has-.+-background-color$/', $token),
            ));
            if ($savedBackgrounds !== []) {
                $problems[] = self::headerProblem(
                    $rel,
                    'saved wrapper background classes=' . self::value($savedBackgrounds),
                    'unchanged',
                    'transparent topSurface must not retain saved opaque background classes',
                );
            }
        } else {
            if ($actualBackground !== $topSurface
                || isset($attrs['gradient'])
                || isset($attrs['style']['color']['background'])) {
                $problems[] = self::headerProblem(
                    $rel,
                    'root background=' . self::value([
                        'backgroundColor' => $actualBackground,
                        'gradient' => $attrs['gradient'] ?? null,
                        'custom' => $attrs['style']['color']['background'] ?? null,
                    ]),
                    'unchanged',
                    'root backgroundColor must equal topSurface=' . self::value($topSurface)
                        . ' without a competing gradient or custom background',
                );
            }
            $expectedBackgroundClass = "has-{$topSurface}-background-color";
            if (!in_array($expectedBackgroundClass, $htmlTokens, true)
                || !in_array('has-background', $htmlTokens, true)) {
                $problems[] = self::headerProblem(
                    $rel,
                    'saved wrapper classes=' . self::value($htmlTokens),
                    'unchanged',
                    "opaque topSurface must render '{$expectedBackgroundClass}' and 'has-background'",
                );
            }
            $staleBackgrounds = array_values(array_filter(
                $htmlTokens,
                static fn (string $token): bool => (bool) preg_match('/^has-.+-background-color$/', $token)
                    && $token !== $expectedBackgroundClass,
            ));
            if ($staleBackgrounds !== []) {
                $problems[] = self::headerProblem(
                    $rel,
                    'stale saved wrapper background classes=' . self::value($staleBackgrounds),
                    'unchanged',
                    'saved root background classes must agree with the resolved topSurface',
                );
            }
        }

        $foreground = $artifact['foreground'];
        if (($attrs['textColor'] ?? null) !== $foreground || isset($attrs['style']['color']['text'])) {
            $problems[] = self::headerProblem(
                $rel,
                'root text=' . self::value([
                    'textColor' => $attrs['textColor'] ?? null,
                    'custom' => $attrs['style']['color']['text'] ?? null,
                ]),
                'unchanged',
                'root textColor must equal foreground=' . self::value($foreground)
                    . ' without a competing custom text color',
            );
        }
        $expectedForegroundClass = "has-{$foreground}-color";
        if (!in_array($expectedForegroundClass, $htmlTokens, true)
            || !in_array('has-text-color', $htmlTokens, true)) {
            $problems[] = self::headerProblem(
                $rel,
                'saved wrapper classes=' . self::value($htmlTokens),
                'unchanged',
                "foreground must render '{$expectedForegroundClass}' and 'has-text-color'",
            );
        }

        if (in_array('header-overlay', $attributeTokens, true) || in_array('header-overlay', $htmlTokens, true)) {
            $problems[] = self::headerProblem(
                $rel,
                "legacy class='header-overlay'",
                'unchanged',
                'legacy inner overlay positioning conflicts with outer site-header-shell ownership; class must be absent',
            );
        }
        if (isset($attrs['style']['position'])) {
            $problems[] = self::headerProblem(
                $rel,
                'style.position=' . self::value($attrs['style']['position']),
                'unchanged',
                'positioning belongs to the outer template-part shell; inner header position must be absent',
            );
        }
        if (self::hasInlinePositionDeclaration($doc->ownHtml($top))) {
            $problems[] = self::headerProblem(
                $rel,
                'saved wrapper contains inline position CSS',
                'unchanged',
                'positioning belongs to the outer template-part shell; inner saved HTML position must be absent',
            );
        }

        foreach ($doc->indices() as $i) {
            if ($i === $top) {
                continue;
            }
            $position = ($doc->attrs($i) ?? [])['style']['position'] ?? null;
            $type = is_array($position) ? strtolower(trim((string) ($position['type'] ?? ''))) : '';
            if (in_array($type, ['sticky', 'fixed'], true)) {
                $problems[] = self::headerProblem(
                    $rel,
                    'wp:' . $doc->name($i) . "[{$i}] style.position=" . self::value($position),
                    'unchanged',
                    'descendant sticky/fixed positioning is unsupported inside the persistent header shell',
                );
            }
            if (preg_match('/(?<![-\w])position\s*:\s*(?:sticky|fixed)\b/i', $doc->ownHtml($i), $match)) {
                $problems[] = self::headerProblem(
                    $rel,
                    'wp:' . $doc->name($i) . "[{$i}] saved wrapper contains " . self::value($match[0]),
                    'unchanged',
                    'descendant saved HTML retains unsupported sticky/fixed positioning inside the header shell',
                );
            }
        }
    }

    /**
     * @param list<string> $expected
     * @param list<string> $problems
     */
    private static function checkOuterTemplate(
        Project $project,
        string $rel,
        array $expected,
        array &$problems,
    ): void {
        if (!$project->exists($rel)) {
            return; // ThemeValidator reports required template absence.
        }
        $doc = BlockMarkup::parse($project->readText($rel));
        $actual = null;
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'template-part') {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            if (($attrs['slug'] ?? null) !== 'header') {
                continue;
            }
            $actual = self::classTokens(is_string($attrs['className'] ?? null) ? $attrs['className'] : null);
            break;
        }
        if ($actual === null) {
            $problems[] = self::headerProblem(
                $rel,
                '<no core/template-part with slug="header">',
                'unchanged',
                'template must reference the header part so the resolved behavior can render',
            );
            return;
        }

        foreach (array_diff($expected, $actual) as $class) {
            $problems[] = self::headerProblem(
                $rel,
                'header template-part className=' . self::value($actual),
                'unchanged',
                "required outer header class '{$class}' is missing",
            );
        }
        $knownActual = array_values(array_filter(
            $actual,
            static fn (string $class): bool => $class === 'site-header-shell'
                || str_starts_with($class, 'site-header-shell--'),
        ));
        foreach (array_diff($knownActual, $expected) as $class) {
            $problems[] = self::headerProblem(
                $rel,
                "header template-part class='{$class}'",
                'unchanged',
                $expected === []
                    ? 'static behavior must not retain outer header state classes'
                    : 'outer header class does not match the resolved behavior for this template',
            );
        }
    }

    /** @param list<string> $problems */
    private static function checkHeaderAssets(Project $project, string $behavior, array &$problems): void
    {
        $active = $behavior !== 'static';
        foreach (self::HEADER_ASSETS as $rel) {
            $exists = is_file($project->path($rel));
            if ($active && !$exists) {
                $problems[] = self::headerProblem(
                    $rel,
                    '<missing>',
                    '<missing>',
                    "non-static header behavior '{$behavior}' requires this state asset",
                );
            } elseif (!$active && $exists) {
                $problems[] = self::headerProblem(
                    $rel,
                    '<present>',
                    '<present>',
                    'static behavior must not ship unused header state assets',
                );
            }
        }

        $functionsRel = 'theme/functions.php';
        if (!$project->exists($functionsRel)) {
            if ($active) {
                $problems[] = self::headerProblem(
                    $functionsRel,
                    '<missing>',
                    '<missing>',
                    'non-static header state assets cannot be enqueued without functions.php',
                );
            }
            return;
        }
        $functions = $project->readText($functionsRel);
        foreach ([
            'header.css' => '/wp_enqueue_style\s*\([^;]*assets\/header\/header\.css/s',
            'header.js' => '/wp_enqueue_script\s*\([^;]*assets\/header\/header\.js/s',
        ] as $asset => $pattern) {
            $wired = preg_match($pattern, $functions) === 1;
            if ($active && !$wired) {
                $problems[] = self::headerProblem(
                    $functionsRel,
                    "enqueue for assets/header/{$asset}=<missing>",
                    '<missing>',
                    "non-static header behavior '{$behavior}' requires this asset enqueue",
                );
            } elseif (!$active && $wired) {
                $problems[] = self::headerProblem(
                    $functionsRel,
                    "enqueue for assets/header/{$asset}=<present>",
                    '<present>',
                    'static behavior must not enqueue header state assets',
                );
            }
        }
    }

    /**
     * Whether an inline style attribute in the given HTML carries a CSS
     * `position` declaration. The attribute value is matched with its own
     * closing quote, so an apostrophe inside a double-quoted value (a quoted
     * font-family, say) cannot end the scan early; and the property boundary
     * excludes hyphen-prefixed properties, so `background-position` is not a
     * false positive while `position` first in the list, after `;`, or after
     * whitespace still matches.
     */
    private static function hasInlinePositionDeclaration(string $html): bool
    {
        if (!preg_match_all('/\bstyle\s*=\s*(["\'])((?:(?!\1).)*)\1/is', $html, $matches)) {
            return false;
        }
        foreach ($matches[2] as $css) {
            if (preg_match('/(?<![-\w])position\s*:/i', $css)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $tokens @return list<string> */
    private static function innerStateTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $class): bool => str_starts_with($class, 'header-behavior-')
                || str_starts_with($class, 'header-start-')
                || str_starts_with($class, 'header-scrolled-')
                || str_starts_with($class, 'header-foreground-')
                || str_starts_with($class, 'header-top-')
                || $class === 'header-transition-instant',
        ));
    }

    /** @return list<string> */
    private static function classTokens(?string $className): array
    {
        if ($className === null || trim($className) === '') {
            return [];
        }
        return preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<string> */
    private static function htmlClassTokens(string $html): array
    {
        $tokens = [];
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $className) {
                $tokens = array_merge($tokens, self::classTokens(html_entity_decode($className, ENT_QUOTES)));
            }
        }
        return array_values(array_unique($tokens));
    }

    private static function headerProblem(
        string $file,
        string $authored,
        string $delivered,
        string $disposition,
    ): string {
        return "header behavior contract: file={$file}; authored={$authored}; delivered={$delivered}; "
            . "disposition={$disposition}";
    }

    private static function value(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        return $encoded === false ? '<unrepresentable>' : $encoded;
    }
}
