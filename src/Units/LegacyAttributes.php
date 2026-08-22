<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;

/**
 * Post-generation legacy-attribute pass, run at the markup intake — BEFORE
 * the serializer. The serializer's reviewed migrations recover legacy
 * alignment from saved has-text-align-* classes, which attribute-light
 * generated markup no longer carries; converting the comment attributes here
 * makes the intent survive on JSON alone. Only deterministic, current-schema
 * conversions are applied — anything unrecognized is left for the block
 * fixer's preservation path and its durable warning.
 *
 * Also verifies the one required HTML-sourced field the pipeline consumes
 * before serialization: an image block without an <img src> can never be
 * delivered or collected, so it is reported at intake.
 */
final class LegacyAttributes
{
    /**
     * Blocks whose legacy top-level textAlign moves to
     * style.typography.textAlign, in BlockMarkup's delimiter spelling
     * (core/ is implied by the comment grammar).
     */
    private const TEXT_ALIGN_BLOCKS = ['heading', 'paragraph', 'site-title', 'site-tagline'];

    private const TEXT_ALIGN_VALUES = ['left', 'center', 'right', 'justify'];

    /**
     * @return array{markup:string,conversions:list<string>,notes:list<string>}
     *         conversions are lossless schema rewrites; notes are lossy drops
     *         and missing required sources
     */
    public static function normalize(string $markup): array
    {
        $doc = BlockMarkup::parse($markup);
        $conversions = [];
        $notes = [];

        foreach ($doc->indices() as $i) {
            $name = $doc->name($i);
            $attrs = $doc->attrs($i);

            if ($name === 'image'
                && preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(?!\1)/i', $doc->ownHtml($i)) !== 1) {
                $notes[] = "core/image block {$i} has no img src; its required HTML source is missing";
            }

            if (!is_array($attrs)) {
                continue;
            }

            if (in_array($name, self::TEXT_ALIGN_BLOCKS, true)) {
                $attrs = self::convertTextAlign($doc, $i, $name, $attrs, $conversions);
            }
            if ($name === 'button' && array_key_exists('textAlign', $attrs)) {
                // The pinned button migration drops alignment: it has no
                // current-schema home on core/button.
                $value = $attrs['textAlign'];
                unset($attrs['textAlign']);
                $doc->setAttrs($i, $attrs);
                if (is_string($value) && in_array($value, self::TEXT_ALIGN_VALUES, true)) {
                    $doc->removeClassTokenInOwnHtml($i, 'has-text-align-' . $value);
                }
                $notes[] = "dropped legacy 'textAlign' from core/button (no current-schema equivalent)";
            }

            $attrs = self::convertCustomStyleAttrs($doc, $i, $name, $attrs, $conversions);
        }

        return ['markup' => $doc->render(), 'conversions' => $conversions, 'notes' => $notes];
    }

    /**
     * @param array<string,mixed> $attrs
     * @param list<string> $conversions
     * @return array<string,mixed>
     */
    private static function convertTextAlign(
        BlockMarkup $doc,
        int $i,
        string $name,
        array $attrs,
        array &$conversions,
    ): array {
        $value = $attrs['textAlign'] ?? null;
        if (!is_string($value) || !in_array($value, self::TEXT_ALIGN_VALUES, true)) {
            return $attrs;
        }
        unset($attrs['textAlign']);

        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : [];
        if (!isset($typography['textAlign'])) {
            // An authored current-schema alignment wins; the legacy key is
            // only ever dropped, never merged over it.
            $typography['textAlign'] = $value;
        }
        $style['typography'] = $typography;
        $attrs['style'] = $style;

        // The current save derives alignment from style, so the legacy class
        // must not linger as a stray custom class.
        $class = 'has-text-align-' . $value;
        if (is_string($attrs['className'] ?? null)) {
            $kept = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                static fn (string $token): bool => $token !== $class,
            ));
            if ($kept === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $kept);
            }
        }
        $doc->removeClassTokenInOwnHtml($i, $class);
        $doc->setAttrs($i, $attrs);
        $conversions[] = "converted legacy 'textAlign' to style.typography.textAlign on core/{$name}";
        return $attrs;
    }

    /**
     * customTextColor / customBackgroundColor / customFontSize: the classic
     * pre-style-object legacy trio, each with an exact current style path.
     *
     * @param array<string,mixed> $attrs
     * @param list<string> $conversions
     * @return array<string,mixed>
     */
    private static function convertCustomStyleAttrs(
        BlockMarkup $doc,
        int $i,
        string $name,
        array $attrs,
        array &$conversions,
    ): array {
        $map = [
            'customTextColor' => ['color', 'text', null],
            'customBackgroundColor' => ['color', 'background', null],
            'customFontSize' => ['typography', 'fontSize', 'px'],
        ];
        $changed = false;
        foreach ($map as $legacy => [$family, $key, $unit]) {
            if (!array_key_exists($legacy, $attrs)) {
                continue;
            }
            $value = $attrs[$legacy];
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue;
            }
            unset($attrs[$legacy]);
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
            $group = is_array($style[$family] ?? null) ? $style[$family] : [];
            if (!isset($group[$key])) {
                $group[$key] = is_string($value) ? $value : $value . $unit;
            }
            $style[$family] = $group;
            $attrs['style'] = $style;
            $changed = true;
            $label = str_contains($name, '/') ? $name : "core/{$name}";
            $conversions[] = "converted legacy '{$legacy}' to style.{$family}.{$key} on {$label}";
        }
        if ($changed) {
            $doc->setAttrs($i, $attrs);
        }
        return $attrs;
    }
}
