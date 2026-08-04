<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;
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
 * problem is recorded in warnings.json (see Project::addWarnings) and the
 * theme is delivered anyway.
 */
final class ValidateThemeStep implements Step
{
    private const LOG_FILE = 'validate-theme.log';

    /** The header behavior artifact is deliberately closed: no model-owned extension bag. */
    private const HEADER_FIELDS = [
        'behavior',
        'mode',
        'transition',
        'topSurface',
        'scrolledSurface',
        'foreground',
    ];

    private const HEADER_BEHAVIORS = ['static', 'sticky-soft', 'overlay-to-solid'];

    private const HEADER_MODES = ['stacked', 'overlay'];

    private const HEADER_TRANSITIONS = ['smooth', 'instant'];

    private const HEADER_ASSETS = [
        'theme/assets/header/header.css',
        'theme/assets/header/header.js',
    ];

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
                'pages.json',
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
                'plugin/pages/*',
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
            ThemeValidator::layoutWarnings($project),
            ThemeValidator::spacingWarnings($project),
            ThemeValidator::typographyWarnings($project),
            ThemeValidator::planWarnings($project),
            PresetReferences::problems($project),
            self::headerBehaviorProblems($project),
        );
        $problems = array_values(array_unique($problems));

        if ($problems !== []) {
            $project->addWarnings($this->id(), $problems);
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
     * Advisory validation of the resolved two-state header contract. Nothing
     * here mutates the already-usable theme: every residual is delivered and
     * recorded with enough file/value/disposition context for a repair pass.
     *
     * @return list<string>
     */
    private static function headerBehaviorProblems(Project $project): array
    {
        $problems = [];
        $artifact = self::readHeaderArtifact($project, $problems);
        if ($artifact === null) {
            return $problems;
        }

        $keys = array_keys($artifact);
        $missing = array_values(array_diff(self::HEADER_FIELDS, $keys));
        $extra = array_values(array_diff($keys, self::HEADER_FIELDS));
        if ($missing !== [] || $extra !== []) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                'fields=' . self::value($keys),
                'unchanged',
                'artifact is not closed; missing=' . self::value($missing)
                    . ', extra=' . self::value($extra)
                    . ', expected exactly=' . self::value(self::HEADER_FIELDS),
            );
            // Missing fields make every derived class/surface check
            // speculative. Extra fields are independently actionable but do
            // not prevent validating the complete known contract.
            if ($missing !== []) {
                return $problems;
            }
        }

        $typesValid = true;
        foreach (self::HEADER_FIELDS as $field) {
            if (!is_string($artifact[$field])) {
                $typesValid = false;
                $problems[] = self::headerProblem(
                    'headerBehavior.json',
                    "{$field}=" . self::value($artifact[$field]),
                    'unchanged',
                    "field '{$field}' must be a string",
                );
            } elseif (trim($artifact[$field]) === '') {
                $typesValid = false;
                $problems[] = self::headerProblem(
                    'headerBehavior.json',
                    "{$field}=" . self::value($artifact[$field]),
                    'unchanged',
                    "field '{$field}' must be non-empty",
                );
            }
        }
        if (!$typesValid) {
            return $problems;
        }

        /** @var array{behavior:string,mode:string,transition:string,topSurface:string,scrolledSurface:string,foreground:string} $artifact */
        if ($missing === [] && $extra === []) {
            try {
                HeaderBehavior::validateArtifact($artifact);
            } catch (\InvalidArgumentException $error) {
                $problems[] = self::headerProblem(
                    'headerBehavior.json',
                    self::value($artifact),
                    'unchanged',
                    'closed header behavior contract is invalid: ' . $error->getMessage(),
                );
            }
        }

        $enumsValid = true;
        foreach ([
            'behavior' => self::HEADER_BEHAVIORS,
            'mode' => self::HEADER_MODES,
            'transition' => self::HEADER_TRANSITIONS,
        ] as $field => $allowed) {
            if (in_array($artifact[$field], $allowed, true)) {
                continue;
            }
            $enumsValid = false;
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                "{$field}=" . self::value($artifact[$field]),
                'unchanged',
                "unsupported {$field}; expected one of " . self::value($allowed),
            );
        }
        if (!$enumsValid) {
            return $problems;
        }

        $behavior = $artifact['behavior'];
        $mode = $artifact['mode'];
        $expectedMode = $behavior === 'overlay-to-solid' ? 'overlay' : 'stacked';
        if ($mode !== $expectedMode) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                'behavior=' . self::value($behavior) . ', mode=' . self::value($mode),
                'unchanged',
                "behavior/mode mismatch; '{$behavior}' requires mode '{$expectedMode}'",
            );
        }
        if ($behavior === 'static' && $artifact['scrolledSurface'] !== $artifact['topSurface']) {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                'behavior="static", topSurface=' . self::value($artifact['topSurface'])
                    . ', scrolledSurface=' . self::value($artifact['scrolledSurface']),
                'unchanged',
                'static behavior requires identical top and scrolled surfaces',
            );
        }
        if ($mode === 'overlay' && $artifact['topSurface'] !== 'transparent') {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                'mode="overlay", topSurface=' . self::value($artifact['topSurface']),
                'unchanged',
                'overlay mode requires topSurface="transparent"',
            );
        } elseif ($mode === 'stacked' && $artifact['topSurface'] === 'transparent') {
            $problems[] = self::headerProblem(
                'headerBehavior.json',
                'mode="stacked", topSurface="transparent"',
                'unchanged',
                'stacked mode requires an opaque palette topSurface',
            );
        }

        $theme = json_decode($project->readText('theme/theme.json'), true);
        $palette = is_array($theme) ? ContrastFixStep::paletteMap($theme) : [];
        foreach (['topSurface', 'scrolledSurface', 'foreground'] as $field) {
            $slug = $artifact[$field];
            if ($field === 'topSurface' && $slug === 'transparent') {
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
        $required = self::expectedInnerClasses($artifact);

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
        if (preg_match('/\bstyle\s*=\s*(["\'])[^"\']*\bposition\s*:/i', $doc->ownHtml($top))) {
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
            if (preg_match('/\bposition\s*:\s*(?:sticky|fixed)\b/i', $doc->ownHtml($i), $match)) {
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
     * @param array{behavior:string,mode:string,transition:string,topSurface:string,scrolledSurface:string,foreground:string} $artifact
     * @return list<string>
     */
    private static function expectedInnerClasses(array $artifact): array
    {
        if ($artifact['behavior'] === 'static') {
            return [];
        }
        $classes = [
            'header-behavior-' . $artifact['behavior'],
            'header-start-' . $artifact['topSurface'],
            'header-scrolled-' . $artifact['scrolledSurface'],
            'header-foreground-' . $artifact['foreground'],
        ];
        if ($artifact['transition'] === 'instant') {
            $classes[] = 'header-transition-instant';
        }
        return $classes;
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
