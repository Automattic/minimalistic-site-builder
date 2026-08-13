<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Device;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\ShapeMarkup;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Surface;
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
                'theme/assets/shape/*',
                'theme/assets/surface/*',
                'theme/assets/device/*',
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
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
        $shape = DesignDirectionStep::shapeFor($project);
        $shapeKit = self::writeShapeKit($project, $shape);
        $surface = DesignDirectionStep::surfaceFor($project);
        $surfaceKit = self::writeOverlayKit(
            $project,
            'surface',
            Surface::kitCss($surface, self::paletteBase($project)),
        );
        $device = DesignDirectionStep::deviceFor($project);
        $deviceKit = self::writeOverlayKit(
            $project,
            'device',
            Device::kitCss($device),
        );
        if ($headerWarnings !== []) {
            $project->addWarnings($this->id(), $headerWarnings);
        }
        $project->writeText(
            'theme/functions.php',
            self::functionsPhp($project->slug(), $motion, $header, $shapeKit, $surfaceKit, $deviceKit),
        );
        Narrator::write($motion === null
            ? "  motion: none (kit not shipped)\n"
            : "  motion: '{$motion}' profile enqueued\n");
        Narrator::write($header
            ? "  header: '{$headerBehavior}' state kit enqueued\n"
            : "  header: static (kit not shipped)\n");
        Narrator::write($shapeKit
            ? "  shape: '{$shape}' corner kit enqueued\n"
            : '  shape: ' . ($shape ?? 'none committed') . " (kit not shipped)\n");
        Narrator::write($surfaceKit
            ? "  surface: '{$surface}' overlay enqueued\n"
            : "  surface: {$surface} (kit not shipped)\n");
        Narrator::write($deviceKit
            ? "  device: '{$device}' utility enqueued\n"
            : "  device: {$device} (kit not shipped)\n");
    }

    /**
     * Write the build-owned corner-language stylesheet for a rounded shape
     * commitment: contained media surfaces theme.json cannot reach (the media
     * half of core/media-text, the core/cover canvas). `sharp` and an absent
     * commitment ship no kit — those surfaces are square by default — and any
     * kit left by an earlier finalize run is pruned so the shape cannot go
     * stale.
     */
    private static function writeShapeKit(Project $project, ?string $shape): bool
    {
        $css = ShapeMarkup::kitCss($shape);
        if ($css !== null) {
            $project->writeText('theme/assets/shape/shape.css', $css);
            return true;
        }
        $file = $project->themePath('assets/shape/shape.css');
        if (is_file($file)) {
            unlink($file);
        }
        @rmdir($project->themePath('assets/shape'));
        @rmdir($project->themePath('assets'));
        return false;
    }

    /**
     * Write or prune a build-owned overlay/utility sheet (surface grain,
     * signature device). Same shape as the corner kit: one CSS file, gone
     * when the commitment is `none`.
     */
    private static function writeOverlayKit(Project $project, string $folder, ?string $css): bool
    {
        $rel = "theme/assets/{$folder}/{$folder}.css";
        if ($css !== null) {
            $project->writeText($rel, $css);
            return true;
        }
        $file = $project->themePath("assets/{$folder}/{$folder}.css");
        if (is_file($file)) {
            unlink($file);
        }
        @rmdir($project->themePath("assets/{$folder}"));
        @rmdir($project->themePath('assets'));
        return false;
    }

    /** Page-background hex from the delivered theme, or null. */
    private static function paletteBase(Project $project): ?string
    {
        if (!$project->exists('theme/theme.json')) {
            return null;
        }
        try {
            $theme = $project->readJson('theme/theme.json');
        } catch (\RuntimeException) {
            return null;
        }
        foreach ($theme['settings']['color']['palette'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? '') === 'base' && is_string($entry['color'] ?? null)) {
                return $entry['color'];
            }
        }
        return null;
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

    private static function functionsPhp(
        string $slug,
        ?string $motion,
        bool $header,
        bool $shapeKit,
        bool $surfaceKit = false,
        bool $deviceKit = false,
    ): string {
        $slug = ProjectStore::slugify($slug);

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

        $shapeEnqueues = '';
        $editorStyleList = ['style.css'];
        if ($shapeKit) {
            $editorStyleList[] = 'assets/shape/shape.css';
            $shapeEnqueues = <<<PHP

                // Committed corner language for contained media surfaces theme.json
                // cannot reach (media-text halves, contained covers). Loads after
                // generated style.css so the commitment outranks generated utilities.
                wp_enqueue_style('{$slug}-shape', get_theme_file_uri('assets/shape/shape.css'), array('{$slug}-style'), \$ver);
            PHP;
        }
        $surfaceEnqueues = '';
        if ($surfaceKit) {
            $editorStyleList[] = 'assets/surface/surface.css';
            $surfaceEnqueues = <<<PHP

                // Committed page surface: a fixed overlay, never on a scrolling
                // container. Loads after generated style.css.
                wp_enqueue_style('{$slug}-surface', get_theme_file_uri('assets/surface/surface.css'), array('{$slug}-style'), \$ver);
            PHP;
        }
        $deviceEnqueues = '';
        if ($deviceKit) {
            $editorStyleList[] = 'assets/device/device.css';
            $deviceEnqueues = <<<PHP

                // Committed one-band CSS device. Loads after generated style.css.
                wp_enqueue_style('{$slug}-device', get_theme_file_uri('assets/device/device.css'), array('{$slug}-style'), \$ver);
            PHP;
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
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), {$styleDeps}, \$ver);{$shapeEnqueues}{$surfaceEnqueues}{$deviceEnqueues}{$headerEnqueues}
            });

            // Mirror the theme stylesheets into the editor so previews match the front end.
            add_action('after_setup_theme', function () {
                {$editorStyles}
            });

            // Google Fonts loading lives in its own generated module.
            if (is_readable(__DIR__ . '/fonts.php')) {
                require_once __DIR__ . '/fonts.php';
            }

            PHP;
    }
}
