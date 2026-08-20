<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\OverlayKit;
use Automattic\SiteBuild\Surface;
use Automattic\SiteBuild\PageScope;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\ShapeMarkup;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Final step (deterministic): write theme/functions.php.
 *
 * Input:  designDirection.json (the committed motion profile),
 *         headerBehavior.json (the resolved header treatment), and the trusted
 *         kits scaffold-theme copied into theme/assets/.
 * Output: theme/functions.php — a fixed, deterministic loader this step alone
 *         owns; no model output is ever written into it (telex-style split:
 *         canonical wiring here, generated modules in their own files). It
 *         - enqueues style.css: WordPress does NOT load a block theme's
 *           style.css automatically (it only reads the header for metadata),
 *           so the utility CSS shipped there — .equal-cards and the
 *           page-styles layout appendix — would silently never apply
 *           without an explicit enqueue. Also registered as an editor style so
 *           the editor previews match the front end.
 *         - when the motion profile isn't `none`, enqueues the static motion
 *           kit: motion.css, the ONE committed profile stylesheet, and
 *           motion.js (in <head>, so its motion-js scope exists before first
 *           paint — no flash of content that then hides to reveal). The
 *           hand-authored profile remains authoritative; generated style.css
 *           cannot replace its choreography or timings.
 *         - prunes the motion kit to what the theme ships: unused profile
 *           files always; the whole kit when the profile is `none`.
 *         - independently enqueues the adaptive-header kit for a non-static
 *           resolved behavior, even when site motion is `none`; static headers
 *           prune the whole kit. The script is head-loaded so its fixed-overlay
 *           enhancement scope is present before paint.
 *         - for a rounded shape commitment (`soft`/`round`), writes and
 *           enqueues the build-owned shape kit (assets/shape/shape.css) that
 *           rounds contained media surfaces theme.json cannot reach — the
 *           media half of core/media-text and the core/cover canvas — while
 *           its selectors keep alignfull media square; `sharp` ships no kit.
 *         - require_once's the generated fonts.php (written by the fonts-php
 *           step) when present, guarded so a fontless theme stays valid.
 */
final class FinalizeThemeStep implements Step
{
    public function id(): string
    {
        return 'finalize-theme';
    }

    public function label(): string
    {
        return 'Finalize theme (functions.php)';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'designDirection.json',
                'headerBehavior.json',
                'theme/theme.json',
                'theme/parts/header.html',
                'theme/assets/motion/*',
                'theme/assets/header/*',
            ],
            writes: [
                'theme/functions.php',
                'theme/parts/header.html',
                'theme/assets/motion/*',
                'theme/assets/header/*',
                // Every overlay kit this theme can ship, so the declaration
                // moves with the catalog instead of being restated per kit.
                ...array_map(
                    static fn (OverlayKit $kit): string => $kit->declaredWrites(),
                    self::overlayKits(),
                ),
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        // Every read that can fail happens before the first write. A corrupt
        // theme.json is fatal (AGENTS.md:53 puts a corrupt required artifact in
        // the fatal list), and discovering that halfway through would leave the
        // theme half-written — a pruned kit with no functions.php naming it.
        $shape = DesignDirectionStep::shapeFor($project);
        $surface = DesignDirectionStep::surfaceFor($project);
        $surfaceCss = Surface::kitCss($surface, self::paletteBase($project));

        $profile = DesignDirectionStep::motionProfileFor($project);
        $motion = $profile !== 'none' && $project->exists('theme/assets/motion/motion.css')
            ? $profile
            : null;
        [$headerBehavior, $headerWarnings] = self::headerBehaviorFor($project);
        if ($headerWarnings !== []) {
            // A degraded artifact may arrive AFTER HeaderHeroStep rewrote the
            // header part for overlay (transparent root + light foreground).
            // Pruning the kit while keeping that markup ships an invisible
            // header, so the part is solidified and the rewrite recorded.
            self::solidifyDegradedOverlayHeader($project, $headerWarnings);
        }
        $header = $headerBehavior !== 'static';
        if ($header) {
            self::assertHeaderKit($project);
        }
        self::pruneMotionKit($project, $motion);
        self::pruneHeaderKit($project, $header);
        $overlays = [];
        if (self::writeOverlayKit($project, self::shapeKit(), ShapeMarkup::kitCss($shape))) {
            $overlays[] = self::shapeKit();
        }
        if (self::writeOverlayKit($project, self::surfaceKit(), $surfaceCss)) {
            $overlays[] = self::surfaceKit();
            array_push($headerWarnings, ...self::claimedPseudoElementWarnings($project, $surface));
        }
        if ($headerWarnings !== []) {
            $project->addWarnings($this->id(), $headerWarnings);
        }
        $project->writeText(
            'theme/functions.php',
            self::functionsPhp($project->slug(), $motion, $header, $overlays),
        );
        Narrator::write($motion === null
            ? "  motion: none (kit not shipped)\n"
            : "  motion: '{$motion}' profile enqueued\n");
        Narrator::write($header
            ? "  header: '{$headerBehavior}' state kit enqueued\n"
            : "  header: static (kit not shipped)\n");
        Narrator::write($surfaceCss !== null
            ? "  surface: '{$surface}' overlay enqueued\n"
            : "  surface: {$surface} (kit not shipped)\n");
        Narrator::write($overlays !== []
            ? "  shape: '{$shape}' corner kit enqueued\n"
            : '  shape: ' . ($shape ?? 'none committed') . " (kit not shipped)\n");
    }

    /**
     * Write the build-owned corner-language stylesheet for a rounded shape
     * commitment: contained media surfaces theme.json cannot reach (the media
     * half of core/media-text, the core/cover canvas). `sharp` and an absent
     * commitment ship no kit — those surfaces are square by default — and any
     * kit left by an earlier finalize run is pruned so the shape cannot go
     * stale.
     */
    /**
     * The corner-language kit. Kits are described here rather than spelled out
     * at each of their four use sites (declaration, write, enqueue, editor
     * mirror), so adding the next CSS commitment is one entry instead of
     * another copy of this wiring.
     */
    /**
     * The surface overlay claims `body::before`, so if the generated
     * stylesheet was already using it, something lost its layer. Silence there
     * would mean a design's own decoration vanishing with nothing said.
     *
     * @return list<string>
     */
    private static function claimedPseudoElementWarnings(Project $project, string $surface): array
    {
        if (!$project->exists('theme/style.css')) {
            return [];
        }
        $css = $project->readText('theme/style.css');
        if (preg_match('/\bbody\s*::?before\b/i', $css) !== 1) {
            return [];
        }
        return ["file='theme/style.css'; path=\"body::before\"; authored=generated design rule;"
            . " delivered=overridden; disposition the '{$surface}' surface overlay claims body::before"
            . ' and resets it, so a generated rule on the same pseudo-element no longer renders'];
    }

    /** Page-background hex from the delivered theme, or null. */
    private static function paletteBase(Project $project): ?string
    {
        if (!$project->exists('theme/theme.json')) {
            return null;
        }
        foreach ($project->readJson('theme/theme.json')['settings']['color']['palette'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? '') === 'base' && is_string($entry['color'] ?? null)) {
                return $entry['color'];
            }
        }
        return null;
    }

    /**
     * Every overlay kit this step knows how to ship, in load order.
     *
     * @return list<OverlayKit>
     */
    public static function overlayKits(): array
    {
        return [self::shapeKit(), self::surfaceKit()];
    }

    public static function surfaceKit(): OverlayKit
    {
        return new OverlayKit(
            'surface',
            "// Committed page surface: a fixed overlay, never on a scrolling\n"
                . '// container. Loads after generated style.css.',
        );
    }

    public static function shapeKit(): OverlayKit
    {
        return new OverlayKit(
            'shape',
            "// Committed corner language for contained media surfaces theme.json\n"
                . "// cannot reach (media-text halves, contained covers). Loads after\n"
                . '// generated style.css so the commitment outranks generated utilities.',
        );
    }

    /**
     * Write a build-owned overlay stylesheet, or prune it when the commitment
     * resolved to nothing.
     *
     * A failed delete is an error rather than a shrug: the build would
     * otherwise report the sheet pruned while it sat there still loading.
     */
    private static function writeOverlayKit(Project $project, OverlayKit $kit, ?string $css): bool
    {
        if ($css !== null) {
            $project->writeText($kit->projectRelPath(), $css);
            return true;
        }
        $file = $project->themePath($kit->themeRelPath());
        if (is_file($file) && !@unlink($file) && is_file($file)) {
            throw new \RuntimeException("Could not remove stale overlay stylesheet: {$file}");
        }
        @rmdir($project->themePath("assets/{$kit->folder}"));
        @rmdir($project->themePath('assets'));
        return false;
    }

    /**
     * Generated behavior metadata degrades to static instead of aborting the
     * build. readText() remains outside the JSON catch: an actual filesystem
     * read failure is infrastructure, not an imperfect generated value.
     *
     * @return array{0:'static'|'sticky-soft'|'overlay-to-solid',1:list<string>}
     */
    private static function headerBehaviorFor(Project $project): array
    {
        if (!$project->exists('headerBehavior.json')) {
            return ['static', [
                "file='" . HeaderBehavior::FILE . "'; block='behavior'; authored=<missing>; "
                . "delivered='static'; disposition=missing generated behavior artifact was downgraded "
                . 'and the adaptive-header kit was removed',
            ]];
        }

        $json = $project->readText('headerBehavior.json');
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return ['static', [
                "file='" . HeaderBehavior::FILE . "'; block='behavior'; authored=<invalid JSON: "
                . $error->getMessage() . ">; delivered='static'; disposition=malformed generated "
                . 'behavior was downgraded and the adaptive-header kit was removed',
            ]];
        }

        try {
            if (!is_array($data)) {
                throw new \InvalidArgumentException('header behavior artifact must be a JSON object');
            }
            $data = HeaderBehavior::validateArtifact($data);
            return [$data['behavior'], []];
        } catch (\InvalidArgumentException $error) {
            return ['static', [
                "file='" . HeaderBehavior::FILE . "'; block='behavior'; authored=" . Warnings::value($data)
                . "; delivered='static'; disposition=invalid closed artifact ("
                . $error->getMessage() . ') was downgraded and the adaptive-header kit was removed',
            ]];
        }
    }

    /**
     * Rewrite an overlay-prepared header part onto a solid readable surface
     * when the behavior artifact degraded to static. Shares the deterministic
     * rewrite with the assemble-pages degrade path so both consumers deliver
     * the identical repaired part.
     *
     * @param list<string> $warnings appended to in place
     */
    private static function solidifyDegradedOverlayHeader(Project $project, array &$warnings): void
    {
        if (!$project->exists('theme/parts/header.html')) {
            return;
        }
        try {
            $palette = $project->exists('theme/theme.json')
                ? ContrastFixStep::paletteMap($project->readJson('theme/theme.json'))
                : [];
        } catch (\RuntimeException) {
            // A corrupt theme.json must not abort the fail-open rewrite; the
            // solidifier falls back to its safe default pair.
            $palette = [];
        }
        $result = AssemblePagesStep::solidifyOverlayPreparedHeader(
            $project->readText('theme/parts/header.html'),
            $palette,
        );
        if ($result === null) {
            return;
        }
        $project->writeText('theme/parts/header.html', $result['markup']);
        $warnings[] = "file='theme/parts/header.html'; block='overlay top state'; authored=transparent start"
            . ($result['previousForeground'] !== '' ? " with '{$result['previousForeground']}' foreground" : '')
            . "; delivered=opaque '{$result['topSurface']}' surface with '{$result['foreground']}' foreground; "
            . 'disposition=overlay-prepared header rewritten to a readable solid surface because the behavior '
            . 'artifact degraded to static';
    }

    /** A non-static resolved behavior contractually requires both trusted files. */
    private static function assertHeaderKit(Project $project): void
    {
        foreach (['header.css', 'header.js'] as $file) {
            $path = $project->themePath('assets/header/' . $file);
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException(
                    "Missing or unreadable trusted header asset for non-static behavior: {$path}"
                );
            }
        }
    }

    /**
     * Trim the scaffolded kit to what this theme uses: with a live profile,
     * drop the three unused profile stylesheets; with none, drop the whole
     * kit so a motionless theme ships no dead assets.
     */
    private static function pruneMotionKit(Project $project, ?string $motion): void
    {
        $dir = $project->themePath('assets/motion');
        if (!is_dir($dir)) {
            return;
        }
        if ($motion === null) {
            foreach (glob("{$dir}/profiles/*.css") ?: [] as $file) {
                unlink($file);
            }
            foreach (["{$dir}/motion.css", "{$dir}/motion.js"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir("{$dir}/profiles");
            @rmdir($dir);
            @rmdir($project->themePath('assets'));
            return;
        }
        foreach (glob("{$dir}/profiles/*.css") ?: [] as $file) {
            if (basename($file) !== "{$motion}.css") {
                unlink($file);
            }
        }
    }

    /** Remove the scaffolded header kit when the resolved behavior is static. */
    private static function pruneHeaderKit(Project $project, bool $keep): void
    {
        $dir = $project->themePath('assets/header');
        if ($keep || !is_dir($dir)) {
            return;
        }
        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($dir);
        @rmdir($project->themePath('assets'));
    }

    /** Put every line of a block at the enqueue body's indentation. */
    private static function indentBlock(string $block): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : '    ' . $line,
            explode("\n", $block),
        ));
    }

    /**
     * @param list<OverlayKit> $overlays the build-owned stylesheets this theme
     *        ships, in load order. Each one enqueues after style.css and is
     *        mirrored into the editor; an empty list is a theme with none.
     */
    private static function functionsPhp(
        string $slug,
        ?string $motion,
        bool $header,
        array $overlays = [],
    ): string {
        $slug = ProjectStore::slugify($slug);
        $scopePrefix = PageScope::CLASS_PREFIX;

        $motionEnqueues = '';
        $styleDeps = 'array()';
        if ($motion !== null) {
            $styleDeps = "array('{$slug}-motion-profile')";
            $motionEnqueues = <<<PHP

                // Static motion kit + the committed '{$motion}' profile. motion.js goes
                // in <head>: it sets the motion-js scope that
                // motion.css hides reveal targets under, and doing that before first
                // paint avoids a visible flash; if it never runs, nothing is hidden.
                wp_enqueue_style('{$slug}-motion', get_theme_file_uri('assets/motion/motion.css'), array(), \$ver);
                wp_enqueue_style('{$slug}-motion-profile', get_theme_file_uri('assets/motion/profiles/{$motion}.css'), array('{$slug}-motion'), \$ver);
                wp_enqueue_script('{$slug}-motion', get_theme_file_uri('assets/motion/motion.js'), array(), \$ver, false);
            PHP;
        }

        // Built line by line rather than through a heredoc: the comment is
        // per-kit data, and a heredoc would de-indent its literal lines while
        // leaving the interpolated ones alone.
        $overlayEnqueues = '';
        $editorStyleList = ['style.css'];
        foreach ($overlays as $overlay) {
            $editorStyleList[] = $overlay->themeRelPath();
            $overlayEnqueues .= "\n" . self::indentBlock($overlay->comment)
                . "\n" . self::indentBlock(sprintf(
                    "wp_enqueue_style('%s', get_theme_file_uri('%s'), array('%s-style'), \$ver);",
                    $overlay->handle($slug),
                    $overlay->themeRelPath(),
                    $slug,
                ));
        }
        $editorStyles = count($editorStyleList) === 1
            ? "add_editor_style('style.css');"
            : "add_editor_style(array('" . implode("', '", $editorStyleList) . "'));";

        $headerEnqueues = '';
        if ($header) {
            $headerEnqueues = <<<PHP

                // Trusted adaptive-header chrome is independent of the site's
                // content-motion profile. CSS loads after generated style.css;
                // header.js runs in <head> so only its owned scope may fix an
                // overlay to the viewport before first paint.
                wp_enqueue_style('{$slug}-header', get_theme_file_uri('assets/header/header.css'), array('{$slug}-style'), \$ver);
                wp_enqueue_script('{$slug}-header', get_theme_file_uri('assets/header/header.js'), array(), \$ver, false);
            PHP;
        }

        return <<<PHP
            <?php
            /**
             * Deterministic theme wiring — written by the build, never by a model.
             * Generated modules (fonts.php) are loaded guardedly at the bottom.
             */
            add_action('wp_enqueue_scripts', function () {
                \$ver = wp_get_theme()->get('Version');{$motionEnqueues}
                // Block themes do not load style.css automatically — without this
                // enqueue its utility CSS (card layouts, layout utilities) never applies.
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), {$styleDeps}, \$ver);{$overlayEnqueues}{$headerEnqueues}
            });

            // Mirror the theme stylesheets into the editor so previews match the front end.
            add_action('after_setup_theme', function () {
                {$editorStyles}
            });

            // Carried design CSS is authored one page at a time; page-styles scopes
            // each page's chunk to this class, so the front end has to publish it.
            add_filter('body_class', function (\$classes) {
                \$id = get_queried_object_id();
                if (!is_singular() || !\$id) {
                    return \$classes;
                }
                \$slug = get_post_field('post_name', \$id);
                if (is_string(\$slug) && \$slug !== '') {
                    \$classes[] = sanitize_html_class('{$scopePrefix}' . \$slug);
                }
                return \$classes;
            });

            // Google Fonts loading lives in its own generated module.
            if (is_readable(__DIR__ . '/fonts.php')) {
                require_once __DIR__ . '/fonts.php';
            }

            PHP;
    }
}
