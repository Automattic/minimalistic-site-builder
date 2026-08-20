<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Steps\DesignDirectionStep;

/**
 * Walk the committed design direction against what the build actually shipped.
 *
 * Nothing else compares the two. The direction is a set of promises made
 * before generation, and every step downstream reads it, but no pass asks
 * afterwards whether the delivered theme kept them — so a site could ship with
 * a `framed` canvas broken by a full-bleed band, or a motion profile that
 * claimed movement and produced none, and the build would report success.
 *
 * Report only. These are advisory checks that run inside ValidateThemeStep,
 * the pass already documented as the final non-gating validator, and each
 * finding names the file, the promise, and what was delivered so a repair pass
 * can act on it. Repairing here would be the wrong rung: by this point the
 * theme is fully built, and every artifact these checks read is owned by an
 * earlier step that can fix the cause instead of the symptom — motion classes
 * by MotionSanityStep, palette and family drift by ThemeJsonStep.
 */
final class DirectionFidelity
{
    /**
     * Every broken promise, across every generated page.
     *
     * Two of these checks are blocks-path only, and $htmlFirst turns them off:
     *
     * - `card-style--*` is a prompts/section.md marker, and section.md feeds
     *   only SectionsStep. HTML-first authors its own markup and expresses the
     *   card construction in the design CSS, so the class is legitimately
     *   absent there.
     * - the motion kit classes are placed by the same blocks prompts and
     *   policed by MotionSanityStep, which skips the HTML-first graph outright
     *   ("new CSS path does not use legacy motion fixup"). A page with no kit
     *   classes on that path is correct, not a broken promise.
     *
     * Asking either question on HTML-first accuses a build that kept the
     * promise by a different mechanism, which is worse than not asking.
     *
     * @return list<string>
     */
    public static function problems(Project $project, bool $htmlFirst = false): array
    {
        $direction = DesignDirectionStep::dataFor($project);
        if ($direction === []) {
            return [];
        }
        $theme = $project->exists('theme/theme.json') ? $project->readJson('theme/theme.json') : [];

        $problems = self::typeProblems($direction, $theme);
        foreach (self::pageMarkups($project) as $file => $markup) {
            if (trim($markup) === '') {
                continue;
            }
            array_push($problems, ...self::canvasProblems($direction, $markup, $file));
            if ($htmlFirst) {
                continue;
            }
            array_push($problems, ...self::cardStyleProblems($direction, $markup, $file));
            array_push($problems, ...self::motionProblems($direction, $markup, $file));
        }
        return array_values(array_unique($problems));
    }

    /**
     * Whether headings actually render with the committed heading family.
     *
     * Checking that theme.json declares a `heading` slug is not the same
     * question: a heading element whose typography points at the body family
     * declares one family and renders another, and the declaration check
     * passes. So this resolves what the heading elements and the site title
     * are wired to and compares that.
     *
     * @param array<mixed> $direction
     * @param array<mixed> $theme
     * @return list<string>
     */
    public static function typeProblems(array $direction, array $theme): array
    {
        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        $committed = is_string($type['heading']['family'] ?? null) ? trim($type['heading']['family']) : '';
        if ($committed === '') {
            return [];
        }

        $slugs = [];
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null)) {
                $slugs[$entry['slug']] = FontCatalog::primaryFamily((string) ($entry['fontFamily'] ?? '')) ?? '';
            }
        }
        if (($slugs['heading'] ?? '') === '') {
            return ["file='theme/theme.json'; path=\"type.heading.family\"; authored="
                . Warnings::value($committed)
                . '; delivered=removed; disposition=theme.json declares no heading family slug'];
        }

        $problems = [];
        foreach (self::headingRenderPaths($theme) as $label => $slug) {
            $rendered = $slugs[$slug] ?? '';
            if ($rendered === '' || strcasecmp($rendered, $committed) === 0) {
                continue;
            }
            $problems[] = "file='theme/theme.json'; path=\"{$label}\"; authored="
                . Warnings::value($committed) . '; delivered=' . Warnings::value($rendered)
                . '; disposition=renders with the ' . $slug . ' family, not the committed heading family';
        }
        return $problems;
    }

    /**
     * The font-family slug each heading surface actually resolves to.
     *
     * An element with no explicit typography inherits the heading wiring the
     * scaffold set, so only an explicit preset reference can redirect it.
     *
     * @param array<mixed> $theme
     * @return array<string,string> readable path => font-family slug
     */
    private static function headingRenderPaths(array $theme): array
    {
        $paths = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'heading'] as $element) {
            $slug = self::familySlug($theme['styles']['elements'][$element]['typography']['fontFamily'] ?? null);
            if ($slug !== null) {
                $paths["styles.elements.{$element}"] = $slug;
            }
        }
        $siteTitle = self::familySlug(
            $theme['styles']['blocks']['core/site-title']['typography']['fontFamily'] ?? null,
        );
        if ($siteTitle !== null) {
            $paths['styles.blocks.core/site-title'] = $siteTitle;
        }
        return $paths;
    }

    /** The preset slug a theme.json fontFamily value points at, if it is a reference. */
    private static function familySlug(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/var:preset\|font-family\|([\w-]+)/', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/var\(--wp--preset--font-family--([\w-]+)\)/', $value, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * A `framed` canvas promises a visible mat around every band below the
     * fold. The page-opening hero is exempt — design-direction.md states it
     * runs edge-to-edge on every canvas — so the walk starts at the first
     * top-level block after it, and a full-bleed hero is not a defect.
     *
     * @param array<mixed> $direction
     * @return list<string>
     */
    public static function canvasProblems(array $direction, string $markup, string $file): array
    {
        if (strtolower(trim((string) ($direction['canvas'] ?? ''))) !== 'framed') {
            return [];
        }
        $document = BlockMarkup::parse($markup);
        $topLevel = array_values(array_filter(
            $document->indices(),
            static fn (int $i): bool => $document->parent($i) === null,
        ));
        $problems = [];
        foreach (array_slice($topLevel, 1) as $i) {
            if (!self::isFullBleed($document, $i)) {
                continue;
            }
            $problems[] = "file='{$file}'; path=\"canvas\"; authored=\"framed\"; delivered=align:full on "
                . $document->name($i) . '; disposition=framed mat was broken by a full-bleed band below the hero';
        }
        return $problems;
    }

    /**
     * Full-bleed as the pipeline actually writes it.
     *
     * The serializer emits the alignment as the `align` attribute; a
     * `className` carrying `alignfull` is the saved-HTML form. Both count.
     */
    private static function isFullBleed(BlockMarkup $document, int $i): bool
    {
        $attrs = $document->attrs($i) ?? [];
        if (($attrs['align'] ?? null) === 'full') {
            return true;
        }
        $className = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
        return preg_match('/\balignfull\b/', $className) === 1
            || preg_match('/class\s*=\s*(["\'])[^"\']*\balignfull\b/i', $document->ownHtml($i)) === 1;
    }

    /**
     * @param array<mixed> $direction
     * @return list<string>
     */
    public static function cardStyleProblems(array $direction, string $markup, string $file): array
    {
        $assigned = DesignDirectionStep::normalizeCardStyle($direction['card_style'] ?? null);
        $target = 'card-style--' . $assigned;
        $hasImageCards = preg_match('/<!-- wp:image\b/', $markup) === 1
            && preg_match('/card-body|card-flush|<!-- wp:group\b/', $markup) === 1;
        if (!$hasImageCards || str_contains($markup, $target)) {
            return [];
        }
        return ["file='{$file}'; path=\"card_style\"; authored=" . Warnings::value($assigned)
            . "; delivered=removed; disposition=image cards exist but none carry {$target}"];
    }

    /**
     * A profile that claims movement and ships none is a broken promise. Read
     * from parsed class attributes rather than the raw bytes, so a class name
     * printed inside a code sample is not mistaken for a placed one.
     *
     * @param array<mixed> $direction
     * @return list<string>
     */
    public static function motionProblems(array $direction, string $markup, string $file): array
    {
        $profile = is_string($direction['motion'] ?? null) ? strtolower(trim($direction['motion'])) : '';
        if (!in_array($profile, Motion::PROFILES, true) || in_array($profile, ['none', 'minimal'], true)) {
            return [];
        }
        $document = BlockMarkup::parse($markup);
        $kit = Motion::kitClasses();
        foreach ($document->indices() as $i) {
            $attrs = $document->attrs($i) ?? [];
            $tokens = is_string($attrs['className'] ?? null)
                ? (preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                : [];
            if (array_intersect($tokens, $kit) !== []) {
                return [];
            }
            if (preg_match('/class\s*=\s*(["\'])([^"\']*)\1/i', $document->ownHtml($i), $m) === 1) {
                $html = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (array_intersect($html, $kit) !== []) {
                    return [];
                }
            }
        }
        return ["file='{$file}'; path=\"motion\"; authored=" . Warnings::value($profile)
            . '; delivered=none; disposition=profile claimed motion but the page carries zero kit classes'];
    }

    /**
     * Every generated page, not just the front one — inner pages drift too,
     * and a warning that does not say which page cannot be acted on.
     *
     * @return array<string,string> project-relative path => markup
     */
    public static function pageMarkups(Project $project): array
    {
        if (!$project->exists('plugin/pages.json')) {
            return [];
        }
        $pages = [];
        foreach ((array) ($project->readJson('plugin/pages.json')['pages'] ?? []) as $page) {
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
}
