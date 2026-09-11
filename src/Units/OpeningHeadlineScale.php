<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;

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
 * Three forms can supply the display size. This pass reduces each form:
 *
 * - An explicit `"fontSize":"display"`.
 * - A `has-display-font-size` class in `className` or the saved HTML.
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
        $theme = self::theme($themeJson);
        $bareIsDisplay = self::h1ElementUsesDisplay($theme);
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
            // A preset class can supply the size without a fontSize attribute.
            $current ??= self::presetFromClasses($document, $index, $attrs, $theme);
            if ($current === null && !$bareIsDisplay) {
                continue;
            }
            if ($current !== null && $current !== self::DISPLAY_SLUG) {
                continue;
            }
            $attrs['fontSize'] = self::SECTION_TITLE_SLUG;
            $displayClass = 'has-' . self::DISPLAY_SLUG . '-font-size';
            if (is_string($attrs['className'] ?? null)) {
                $classes = self::classTokens($attrs['className']);
                $kept = array_values(array_filter(
                    $classes,
                    static fn (string $class): bool => $class !== $displayClass,
                ));
                if ($classes !== $kept) {
                    if ($kept === []) {
                        unset($attrs['className']);
                    } else {
                        $attrs['className'] = implode(' ', $kept);
                    }
                }
            }
            $document->setAttrs($index, $attrs);
            // Remove the token from both sources so the block fixer cannot restore it.
            // The block fixer adds the section-title class from the fontSize attribute.
            $document->removeClassTokenInOwnHtml($index, $displayClass);
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

    /** @return list<string> */
    private static function classTokens(string $classes): array
    {
        return preg_split('/[\x20\t\r\n\f]+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Read the preset classes from the comment and the root element. */
    private static function presetFromClasses(BlockMarkup $document, int $index, array $attrs, ?array $theme): ?string
    {
        $root = HtmlFragment::parse($document->ownHtml($index))->root()->elementChildren()[0] ?? null;
        $classes = array_merge(
            self::classTokens(is_string($attrs['className'] ?? null) ? $attrs['className'] : ''),
            self::classTokens($root?->attribute('class') ?? ''),
        );
        $preset = null;
        // The theme emits preset rules in this order. The last rule that matches takes effect.
        foreach ((array) ($theme['settings']['typography']['fontSizes'] ?? []) as $entry) {
            $slug = is_array($entry) ? ($entry['slug'] ?? null) : null;
            if (is_string($slug) && $slug !== '' && in_array('has-' . $slug . '-font-size', $classes, true)) {
                $preset = $slug;
            }
        }
        return $preset;
    }

    private static function theme(string|array|null $themeJson): ?array
    {
        if (is_array($themeJson)) {
            return $themeJson;
        }
        if (is_string($themeJson) && trim($themeJson) !== '') {
            $decoded = json_decode($themeJson, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Check whether the H1 element style supplies the display preset.
     * Accept both WordPress forms of a preset reference.
     */
    private static function h1ElementUsesDisplay(?array $theme): bool
    {
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
