<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Repairs the legacy Jetpack submit-button shape found in generated forms.
 *
 * An element-less jetpack/button defaults to an anchor. Inside a
 * jetpack/contact-form that turns the apparent submit control into a link to
 * `#`, so the form silently does nothing. Keep the authored block and styling,
 * but make that missing intent explicit. Current generation uses core/button;
 * this pass is a bounded safety net for model drift and older generated markup.
 */
final class JetpackFormFixer
{
    /**
     * @return array{markup:string, notes:list<string>}
     */
    public static function fix(string $markup): array
    {
        if (!str_contains($markup, 'wp:jetpack/contact-form')
            || !str_contains($markup, 'wp:jetpack/button')) {
            return ['markup' => $markup, 'notes' => []];
        }

        $blocks = BlockMarkup::parse($markup);
        $notes = [];
        foreach ($blocks->indices() as $index) {
            if ($blocks->name($index) !== 'jetpack/button'
                || !$blocks->isStructurallySafe($index)
                || !self::isInsideContactForm($blocks, $index)) {
                continue;
            }

            $attrs = $blocks->attrs($index) ?? [];
            if (isset($attrs['element'])) {
                continue;
            }

            $attrs['element'] = 'button';
            $blocks->setAttrs($index, $attrs);
            $notes[] = 'wp:jetpack/button inside wp:jetpack/contact-form had no element; set element to button';
        }

        return ['markup' => $blocks->render(), 'notes' => $notes];
    }

    private static function isInsideContactForm(BlockMarkup $blocks, int $index): bool
    {
        for ($parent = $blocks->parent($index); $parent !== null; $parent = $blocks->parent($parent)) {
            if (!$blocks->isStructurallySafe($parent)) {
                return false;
            }
            if ($blocks->name($parent) === 'jetpack/contact-form') {
                return true;
            }
        }
        return false;
    }
}
