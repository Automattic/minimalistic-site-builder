<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Surface;
use Automattic\SiteBuild\Warnings;

/**
 * After assemble and finalize: walk designDirection.json against what shipped.
 * Deterministic repairs stay small (stamp the assigned card class, strip
 * illegal motion). Everything else is a warning. Never aborts the build.
 */
final class DirectionFidelityStep implements Step
{
    private const REPORT_FILE = 'direction-fidelity.txt';

    public function id(): string
    {
        return 'direction-fidelity';
    }

    public function label(): string
    {
        return 'Audit direction fidelity';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'designDirection.json',
                'theme/theme.json',
                'theme/functions.php',
                'plugin/pages.json',
                'plugin/pages/*',
            ],
            writes: [
                'plugin/pages/*',
                'logs/' . self::REPORT_FILE,
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $direction = DesignDirectionStep::dataFor($project);
        $warnings = [];
        $repairs = [];

        if ($direction === []) {
            $project->writeText('logs/' . self::REPORT_FILE, "No designDirection.json; fidelity walk skipped.\n");
            return;
        }

        $theme = $project->exists('theme/theme.json') ? $project->readJson('theme/theme.json') : [];
        $functions = $project->exists('theme/functions.php') ? $project->readText('theme/functions.php') : '';
        $home = self::homeMarkup($project);

        self::auditType($direction, $theme, $warnings);
        self::auditPalette($direction, $theme, $warnings);
        self::auditSurface($direction, $functions, $warnings);
        self::auditCanvas($direction, $home['markup'], $warnings);

        foreach (self::pageMarkups($project) as $file => $markup) {
            $changed = false;
            [$markup, $deviceRepairs] = self::stampDevice($markup, $direction, $file);
            array_push($repairs, ...$deviceRepairs);
            $changed = $deviceRepairs !== [];
            if ($file === $home['file']) {
                [$markup, $cardRepairs] = self::repairCardStyle($markup, $direction);
                array_push($repairs, ...$cardRepairs);
                [$markup, $motionRepairs] = self::repairMotion($markup, $direction);
                array_push($repairs, ...$motionRepairs);
                $changed = $changed || $cardRepairs !== [] || $motionRepairs !== [];
                $home['markup'] = $markup;
            }
            if ($changed) {
                $project->writeText($file, $markup);
            }
        }

        self::auditDevice($direction, $functions, $home['markup'], $warnings);
        self::auditCardStyle($direction, $home['markup'], $warnings);
        self::auditMotionShown($direction, $home['markup'], $warnings);

        $report = [
            'Successful deterministic repairs: ' . count($repairs),
        ];
        foreach ($repairs as $repair) {
            $report[] = '- ' . $repair;
        }
        $report[] = 'Durable degradations: ' . count($warnings);
        foreach ($warnings as $warning) {
            $report[] = '- ' . $warning;
        }
        $project->writeText('logs/' . self::REPORT_FILE, implode("\n", $report) . "\n");
        $project->addWarnings($this->id(), $warnings);

        if ($repairs !== []) {
            Narrator::write('  [direction-fidelity] repaired ' . count($repairs)
                . " generated fidelity defect(s)\n");
        }
        if ($warnings !== []) {
            Narrator::write('  [direction-fidelity] warning: delivered through ' . count($warnings)
                . " broken direction promise(s) (recorded in warnings.json)\n");
        }
    }

    /**
     * @return array{file:?string,markup:string}
     */
    public static function homeMarkup(Project $project): array
    {
        if (!$project->exists('plugin/pages.json')) {
            return ['file' => null, 'markup' => ''];
        }
        $manifest = $project->readJson('plugin/pages.json');
        foreach (is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [] as $page) {
            if (!is_array($page) || empty($page['front'])) {
                continue;
            }
            $slug = is_string($page['slug'] ?? null) ? $page['slug'] : '';
            if ($slug === '' || !$project->exists("plugin/pages/{$slug}.html")) {
                continue;
            }
            return [
                'file' => "plugin/pages/{$slug}.html",
                'markup' => $project->readText("plugin/pages/{$slug}.html"),
            ];
        }
        return ['file' => null, 'markup' => ''];
    }

    /**
     * @return array<string,string> project-relative path => markup
     */
    public static function pageMarkups(Project $project): array
    {
        $pages = [];
        if (!$project->exists('plugin/pages.json')) {
            return $pages;
        }
        $manifest = $project->readJson('plugin/pages.json');
        foreach (is_array($manifest['pages'] ?? null) ? $manifest['pages'] : [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = is_string($page['slug'] ?? null) ? $page['slug'] : '';
            if ($slug === '' || !$project->exists("plugin/pages/{$slug}.html")) {
                continue;
            }
            $pages["plugin/pages/{$slug}.html"] = $project->readText("plugin/pages/{$slug}.html");
        }
        return $pages;
    }

    /**
     * Put the committed device class on the first non-hero full-bleed band
     * when the model forgot. One band per page.
     *
     * @param array<mixed> $direction
     * @return array{0:string,1:list<string>}
     */
    public static function stampDevice(string $markup, array $direction, string $file): array
    {
        $class = self::deviceClass($direction['device'] ?? null);
        if ($class === null || str_contains($markup, $class)) {
            return [$markup, []];
        }

        $stamped = false;
        $rewritten = preg_replace_callback(
            '/("className"\s*:\s*")([^"]*)(")/',
            static function (array $match) use ($class, &$stamped): string {
                if ($stamped || str_contains($match[2], 'hero-composition') || !str_contains($match[2], 'alignfull')) {
                    return $match[0];
                }
                $stamped = true;
                return $match[1] . trim($match[2] . ' ' . $class) . $match[3];
            },
            $markup,
        );
        if (!is_string($rewritten) || !$stamped) {
            return [$markup, []];
        }
        $stampedHtml = false;
        $rewritten = preg_replace_callback(
            '/(\bclass=")([^"]*)(")/',
            static function (array $match) use ($class, &$stampedHtml): string {
                if ($stampedHtml || str_contains($match[2], 'hero-composition') || !str_contains($match[2], 'alignfull')) {
                    return $match[0];
                }
                if (str_contains($match[2], $class)) {
                    $stampedHtml = true;
                    return $match[0];
                }
                $stampedHtml = true;
                return $match[1] . trim($match[2] . ' ' . $class) . $match[3];
            },
            $rewritten,
        );
        if (!is_string($rewritten)) {
            return [$markup, []];
        }
        return [$rewritten, [
            "file='{$file}'; path=\"device\"; authored=missing; delivered={$class}; "
            . 'disposition stamped the committed device on the first non-hero full-bleed band',
        ]];
    }

    /**
     * @param array<mixed> $direction
     * @param array<mixed> $theme
     * @param list<string> $warnings
     */
    public static function auditType(array $direction, array $theme, array &$warnings): void
    {
        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        $families = [];
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null)) {
                $families[$entry['slug']] = (string) ($entry['fontFamily'] ?? '');
            }
        }
        foreach (['heading', 'body', 'accent'] as $slot) {
            $family = is_string($type[$slot]['family'] ?? null) ? trim($type[$slot]['family']) : '';
            if ($family === '') {
                continue;
            }
            $stack = $families[$slot] ?? '';
            if ($stack === '') {
                $warnings[] = "file='theme/theme.json'; path=\"type.{$slot}.family\"; authored="
                    . Warnings::value($family) . '; delivered=removed; disposition=theme.json has no '
                    . $slot . ' family slug';
                continue;
            }
            $primary = trim(explode(',', $stack, 2)[0], " \t\"'");
            if (strcasecmp($primary, $family) !== 0) {
                $warnings[] = "file='theme/theme.json'; path=\"type.{$slot}.family\"; authored="
                    . Warnings::value($family) . '; delivered=' . Warnings::value($primary)
                    . '; disposition=shipped family does not match the direction';
            }
        }
    }

    /**
     * @param array<mixed> $direction
     * @param array<mixed> $theme
     * @param list<string> $warnings
     */
    public static function auditPalette(array $direction, array $theme, array &$warnings): void
    {
        $palette = is_array($direction['palette'] ?? null) ? $direction['palette'] : [];
        $shipped = [];
        foreach ($theme['settings']['color']['palette'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null) && is_string($entry['color'] ?? null)) {
                $shipped[$entry['slug']] = strtoupper(trim($entry['color']));
            }
        }
        foreach ($palette as $role => $hex) {
            if (!is_string($hex) || !is_string($role)) {
                continue;
            }
            $want = strtoupper(trim($hex));
            $got = $shipped[$role] ?? '';
            if ($got === '') {
                $warnings[] = "file='theme/theme.json'; path=\"palette.{$role}\"; authored="
                    . Warnings::value($want) . '; delivered=removed; disposition=slug missing from theme.json';
                continue;
            }
            if ($got !== $want) {
                $warnings[] = "file='theme/theme.json'; path=\"palette.{$role}\"; authored="
                    . Warnings::value($want) . '; delivered=' . Warnings::value($got)
                    . '; disposition=shipped hex does not match the direction';
            }
        }
    }

    /**
     * @param array<mixed> $direction
     * @param list<string> $warnings
     */
    public static function auditSurface(array $direction, string $functions, array &$warnings): void
    {
        $surface = self::surfaceValue($direction['surface'] ?? null);
        if ($surface === 'none') {
            if (str_contains($functions, '-surface') && str_contains($functions, 'assets/surface/surface.css')) {
                $warnings[] = "file='theme/functions.php'; path=\"surface\"; authored=\"none\"; "
                    . 'delivered=surface overlay enqueued; disposition=kit shipped without a surface commitment';
            }
            return;
        }
        if (!str_contains($functions, 'assets/surface/surface.css')) {
            $warnings[] = "file='theme/functions.php'; path=\"surface\"; authored="
                . Warnings::value($surface) . '; delivered=removed; disposition=surface kit was not enqueued';
        }
    }

    /**
     * @param array<mixed> $direction
     * @param list<string> $warnings
     */
    public static function auditDevice(array $direction, string $functions, string $markup, array &$warnings): void
    {
        $class = self::deviceClass($direction['device'] ?? null);
        $device = $class === null ? 'none' : substr($class, strlen('device--'));
        if ($device === 'none' || $class === null) {
            return;
        }
        if (!str_contains($functions, 'assets/device/device.css')) {
            $warnings[] = "file='theme/functions.php'; path=\"device\"; authored="
                . Warnings::value($device) . '; delivered=removed; disposition=device kit was not enqueued';
        }
        if ($markup !== '' && !str_contains($markup, $class)) {
            $warnings[] = "file='plugin/pages'; path=\"device\"; authored="
                . Warnings::value($class) . '; delivered=removed; disposition=committed device class is not on the home page';
        }
    }

    /**
     * @param array<mixed> $direction
     * @param list<string> $warnings
     */
    public static function auditCanvas(array $direction, string $markup, array &$warnings): void
    {
        $canvas = strtolower(trim((string) ($direction['canvas'] ?? '')));
        if ($canvas !== 'framed' || $markup === '') {
            return;
        }
        $parts = preg_split('/hero-composition--[\w-]+/', $markup, 2);
        $belowHero = is_array($parts) && isset($parts[1]) ? $parts[1] : $markup;
        if (preg_match('/"align"\s*:\s*"full"|class="[^"]*\balignfull\b/', $belowHero) === 1) {
            $warnings[] = 'file=\'plugin/pages\'; path="canvas"; authored="framed"; delivered=align:full '
                . 'below the hero; disposition=framed mat was broken by a full-bleed band';
        }
    }

    /**
     * @param array<mixed> $direction
     * @return array{0:string,1:list<string>}
     */
    public static function repairCardStyle(string $markup, array $direction): array
    {
        $assigned = DesignDirectionStep::normalizeCardStyle($direction['card_style'] ?? null);
        $target = 'card-style--' . $assigned;
        $repairs = [];
        if (str_contains($markup, $target)) {
            return [$markup, $repairs];
        }
        $rewritten = preg_replace(
            '/card-style--(?:flush|framed|overlap|borderless)/',
            $target,
            $markup,
            -1,
            $count,
        );
        if (is_string($rewritten) && $count > 0) {
            $repairs[] = "file='plugin/pages'; path=\"card_style\"; authored other card-style marker; "
                . "delivered {$target}; disposition rewrote {$count} marker(s) to the assigned construction";
            return [$rewritten, $repairs];
        }

        $hooks = [$target];
        if (in_array($assigned, ['flush', 'overlap'], true)) {
            $hooks[] = 'card-flush';
        }
        [$stamped, $n] = self::stampClassesOnImageCards($markup, $hooks);
        if ($n > 0) {
            $repairs[] = "file='plugin/pages'; path=\"card_style\"; authored=missing; delivered {$target}; "
                . "disposition stamped the assigned construction on {$n} image card(s)";
            return [$stamped, $repairs];
        }

        return [$markup, $repairs];
    }

    /**
     * @param array<mixed> $direction
     * @param list<string> $warnings
     */
    public static function auditCardStyle(array $direction, string $markup, array &$warnings): void
    {
        if ($markup === '') {
            return;
        }
        $assigned = DesignDirectionStep::normalizeCardStyle($direction['card_style'] ?? null);
        $target = 'card-style--' . $assigned;
        $hasImageCards = preg_match('/<!-- wp:image\b/', $markup) === 1
            && preg_match('/card-body|card-flush|<!-- wp:group\b/', $markup) === 1;
        if ($hasImageCards && !str_contains($markup, $target)) {
            $warnings[] = "file='plugin/pages'; path=\"card_style\"; authored="
                . Warnings::value($assigned) . '; delivered=removed; disposition=image cards exist '
                . "but none carry {$target}";
        }
    }

    /**
     * @param array<mixed> $direction
     * @return array{0:string,1:list<string>}
     */
    public static function repairMotion(string $markup, array $direction): array
    {
        $profile = is_string($direction['motion'] ?? null)
            ? strtolower(trim($direction['motion']))
            : 'none';
        if (!in_array($profile, Motion::PROFILES, true)) {
            $profile = 'none';
        }
        $allowed = Motion::allowedClasses($profile);
        $repairs = [];
        $rewritten = preg_replace_callback(
            '/("className"\s*:\s*"|class=")([^"]*)(")/',
            static function (array $match) use ($allowed, &$repairs): string {
                $tokens = preg_split('/\s+/', trim($match[2])) ?: [];
                $kept = [];
                $stripped = [];
                foreach ($tokens as $token) {
                    if ($token === '') {
                        continue;
                    }
                    if (Motion::looksLikeMotionClass($token) && !in_array($token, $allowed, true)) {
                        $stripped[] = $token;
                        continue;
                    }
                    $kept[] = $token;
                }
                if ($stripped === []) {
                    return $match[0];
                }
                $repairs[] = 'file=\'plugin/pages\'; path="motion"; authored='
                    . Warnings::value($stripped) . '; delivered=removed; disposition=stripped '
                    . 'classes the committed profile does not allow';
                return $match[1] . implode(' ', $kept) . $match[3];
            },
            $markup,
        );
        $rewritten = is_string($rewritten) ? $rewritten : $markup;

        $note = strtolower((string) ($direction['motion_note'] ?? ''));
        $wantsLift = str_contains($note, 'hover-lift')
            || str_contains($note, 'buttons press')
            || str_contains($note, 'labels press')
            || str_contains($note, 'press on');
        if ($wantsLift && in_array('hover-lift', $allowed, true) && !str_contains($rewritten, 'hover-lift')) {
            [$stamped, $n] = self::stampClassesOnImageCards($rewritten, ['hover-lift']);
            if ($n > 0) {
                $repairs[] = "file='plugin/pages'; path=\"motion_note\"; authored=hover-lift; delivered=missing; "
                    . "disposition stamped hover-lift on {$n} image card(s)";
                $rewritten = $stamped;
            }
        }

        return [$rewritten, $repairs];
    }

    /**
     * Put keepable classes on groups that look like image cards (an image
     * plus a heading, paragraph, or card-body). Skips the hero.
     *
     * @param list<string> $classes
     * @return array{0:string,1:int}
     */
    public static function stampClassesOnImageCards(string $markup, array $classes): array
    {
        $classes = array_values(array_filter($classes, static fn (string $c): bool => $c !== ''));
        if ($classes === [] || $markup === '') {
            return [$markup, 0];
        }

        $doc = BlockMarkup::parse($markup);
        $stamped = 0;
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'group' || !$doc->isStructurallySafe($i)) {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $class = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
            if (preg_match('/\bhero-(?:composition|media|inner|copy|figure|actions)\b/', $class) === 1) {
                continue;
            }
            $already = true;
            foreach ($classes as $token) {
                if (!preg_match('/\b' . preg_quote($token, '/') . '\b/', $class)
                    && !preg_match('/\b' . preg_quote($token, '/') . '\b/', $doc->ownHtml($i))
                ) {
                    $already = false;
                    break;
                }
            }
            if ($already || !self::looksLikeImageCard($doc, $i)) {
                continue;
            }
            $attrs['className'] = trim($class . ' ' . implode(' ', $classes));
            $doc->setAttrs($i, $attrs);
            $own = $doc->ownHtml($i);
            if (preg_match('/\bclass="([^"]*)"/', $own, $match) === 1) {
                $htmlTokens = preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $anchor = $htmlTokens[0] ?? '';
                $missing = [];
                foreach ($classes as $token) {
                    if ($token !== '' && !in_array($token, $htmlTokens, true)) {
                        $missing[] = $token;
                    }
                }
                if ($anchor !== '' && $missing !== []) {
                    $doc->replaceClassTokenInOwnHtml($i, $anchor, $anchor . ' ' . implode(' ', $missing));
                }
            }
            $stamped++;
        }

        return [$stamped > 0 ? $doc->render() : $markup, $stamped];
    }

    private static function looksLikeImageCard(BlockMarkup $doc, int $i): bool
    {
        $hasImage = false;
        $hasText = false;
        foreach ($doc->children($i) as $child) {
            $name = $doc->name($child);
            if ($name === 'image') {
                $hasImage = true;
            }
            if (in_array($name, ['heading', 'paragraph'], true)) {
                $hasText = true;
            }
            if ($name === 'group') {
                $childClass = (string) (($doc->attrs($child) ?? [])['className'] ?? '');
                if (str_contains($childClass, 'card-body')) {
                    $hasText = true;
                }
                foreach ($doc->children($child) as $grand) {
                    if (in_array($doc->name($grand), ['heading', 'paragraph'], true)) {
                        $hasText = true;
                    }
                    if ($doc->name($grand) === 'image') {
                        $hasImage = true;
                    }
                }
            }
        }
        return $hasImage && $hasText;
    }

    public static function deviceClass(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $device = strtolower(trim($raw));
        if (!in_array($device, ['hairline-rule', 'section-numeral', 'stamp'], true)) {
            return null;
        }
        return 'device--' . $device;
    }

    public static function surfaceValue(mixed $raw): string
    {
        if (!is_string($raw)) {
            return 'none';
        }
        $surface = strtolower(trim($raw));
        return in_array($surface, ['none', 'paper', 'concrete', 'film', 'fabric'], true)
            ? $surface
            : 'none';
    }

    /**
     * @param array<mixed> $direction
     * @param list<string> $warnings
     */
    public static function auditMotionShown(array $direction, string $markup, array &$warnings): void
    {
        $profile = is_string($direction['motion'] ?? null)
            ? strtolower(trim($direction['motion']))
            : '';
        if (!in_array($profile, Motion::PROFILES, true) || $markup === '') {
            return;
        }
        $hasMotion = false;
        foreach (Motion::kitClasses() as $class) {
            if (preg_match('/\b' . preg_quote($class, '/') . '\b/', $markup) === 1) {
                $hasMotion = true;
                break;
            }
        }
        if (!in_array($profile, ['none', 'minimal'], true) && !$hasMotion) {
            $warnings[] = "file='plugin/pages'; path=\"motion\"; authored="
                . Warnings::value($profile) . '; delivered=none; disposition=profile claimed motion '
                . 'but the home page has zero kit classes';
        }
    }
}
