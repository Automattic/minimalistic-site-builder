<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;

/**
 * Keeps the `display` preset for the one headline it was sized for: the front
 * page's hero H1.
 *
 * An inner page opens with a section like any other, so `SectionsStep` sends
 * it to `SectionUnit` and prompts/section.md — never to `HeroUnit`, and never
 * through `HeroHeadlineFit`, which is the only pass that bounds a display
 * headline against its measure and a line target. Section index 0 of EVERY
 * page is stamped `Role: hero` by `SectionRole`, so the model is told it is
 * writing a hero and reaches for the masthead token the same way the front
 * hero does. Nothing downstream disagreed.
 *
 * The cohort showed both halves of the result (BIGR-1015). tbilisi23 rendered
 * its `/menu/` and `/about/` H1s at the full 96px display maximum — four
 * wrapped lines and 430px of heading on the about page — while the front
 * hero, the one headline that IS bounded, was pinned to 37px. The inner
 * pages outscaled the masthead by 2.6x, and 4 of the 8 cohort sites with
 * inner pages had the same inversion.
 *
 * So an opening that is not the front hero is held to `section-title`, the
 * step below the masthead. That is not a new opinion: `PageOpeningFallback`
 * already writes exactly that preset when generation fails, against
 * `HeroFallback`'s `display`. This pass only makes the delivered path agree
 * with the fallback path.
 *
 * Two shapes deliver the masthead scale, and both are demoted:
 *
 * - An explicit `"fontSize":"display"`.
 * - No size at all. `ThemeJsonStep` sets `styles.elements.h1` to the display
 *   preset, so a bare H1 inherits the masthead token with nothing in the
 *   markup to show for it — the `/about/` case. The theme is consulted for
 *   this, never assumed: a theme whose h1 element resolves elsewhere leaves
 *   its bare headings alone.
 *
 * A heading the model sized below `display` is left exactly as it was, and so
 * is one pinned to an explicit `style.typography.fontSize` — this pass lowers
 * a ceiling, it does not re-litigate a choice that already clears it.
 */
final class OpeningHeadlineScale
{
    /** The masthead preset. Only the front hero's H1 may carry it. */
    private const DISPLAY_SLUG = 'display';

    /** The step below it, and what an inner page opening gets instead. */
    private const SECTION_TITLE_SLUG = 'section-title';

    /**
     * @param string $part the section key, for the repair report
     * @param string|array<mixed>|null $themeJson
     * @param list<array<string,string>> $repairs
     */
    public static function enforce(
        string $markup,
        string $part,
        string|array|null $themeJson,
        array &$repairs = [],
    ): string {
        $document = BlockMarkup::parse($markup);
        if ($document->hasMismatchedDelimiters() || $document->hasMalformedDelimiters()) {
            return $markup;
        }
        $bareIsDisplay = self::h1ElementUsesDisplay($themeJson);
        $changed = false;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'heading' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            if ((int) ($attrs['level'] ?? 2) !== 1) {
                continue;
            }
            // An explicit size is a decision of its own; leave it.
            if (isset($attrs['style']['typography']['fontSize'])) {
                continue;
            }
            $current = $attrs['fontSize'] ?? null;
            $current = is_string($current) && $current !== '' ? $current : null;
            if ($current === null && !$bareIsDisplay) {
                continue;
            }
            if ($current !== null && $current !== self::DISPLAY_SLUG) {
                continue;
            }
            $attrs['fontSize'] = self::SECTION_TITLE_SLUG;
            $document->setAttrs($index, $attrs);
            // The preset class must follow the preset attr: WordPress renders
            // `.has-display-font-size` with !important, so a stale token would
            // beat the new one. The class can also be absent entirely (the
            // bare-H1 case) — the block fixer writes the matching token back
            // from the attribute, so removing a token that is not there is
            // the whole job here.
            $document->removeClassTokenInOwnHtml($index, 'has-' . self::DISPLAY_SLUG . '-font-size');
            $repairs[] = [
                'part' => $part,
                'block' => 'heading.level-1',
                'authored' => $current ?? 'no preset (inherits the display element style)',
                'delivered' => self::SECTION_TITLE_SLUG,
                'note' => 'the display preset is the front hero masthead; an inner page opening '
                    . 'that keeps it renders larger than the headline it sits under',
            ];
            $changed = true;
        }
        return $changed ? $document->render() : $markup;
    }

    /**
     * Whether a bare H1 inherits the display preset from the theme's own h1
     * element style. Both spellings WordPress accepts count.
     *
     * @param string|array<mixed>|null $themeJson
     */
    private static function h1ElementUsesDisplay(string|array|null $themeJson): bool
    {
        $theme = is_array($themeJson) ? $themeJson : null;
        if (is_string($themeJson) && trim($themeJson) !== '') {
            $decoded = json_decode($themeJson, true);
            $theme = is_array($decoded) ? $decoded : null;
        }
        if ($theme === null) {
            return false;
        }
        $size = $theme['styles']['elements']['h1']['typography']['fontSize'] ?? null;
        if (!is_string($size)) {
            return false;
        }
        return $size === 'var:preset|font-size|' . self::DISPLAY_SLUG
            || $size === 'var(--wp--preset--font-size--' . self::DISPLAY_SLUG . ')';
    }
}
