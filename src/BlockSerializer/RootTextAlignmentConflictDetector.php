<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;
use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Parser\FreeformNode;

/**
 * Find reading-copy roots whose saved HTML, comment attributes, and effective
 * inline CSS disagree or whose inline alignment has no durable mirror.
 *
 * The frozen serializer is intentionally an oracle port and can choose the
 * class-derived alignment while dropping the inline declaration that actually
 * won in the authored browser output. PhpBlockFixer uses this preflight at its
 * per-file isolation boundary: the file is reported as failed and its input
 * bytes survive for a later repair pass instead of silently changing layout.
 */
final class RootTextAlignmentConflictDetector
{
    /** @return list<string> actionable conflict descriptions */
    public function detect(string $markup): array
    {
        try {
            $document = DefaultParser::parse($markup);
            $conflicts = [];
            $index = 0;
            foreach ($document->nodes() as $node) {
                if ($node instanceof FreeformNode) {
                    if (JsString::trim($node->content) !== '') {
                        $index++;
                    }
                    continue;
                }
                if (!$node instanceof BlockNode) {
                    continue;
                }
                $this->collect($node, (string) $index, $conflicts);
                $index++;
            }
            return $conflicts;
        } catch (\Throwable) {
            // Generated markup that defeats this advisory preflight stays in
            // the normal transformer path, whose per-file transaction owns
            // validation failures. The detector itself must never abort the
            // build over model-authored bytes.
            return [];
        }
    }

    /** @param list<string> $conflicts */
    private function collect(
        BlockNode $block,
        string $path,
        array &$conflicts,
    ): void
    {
        $conflict = self::conflict($block, $path);
        if ($conflict !== null) {
            $conflicts[] = $conflict;
        }
        foreach ($block->innerBlocks as $index => $child) {
            $this->collect($child, $path . '/' . $index, $conflicts);
        }
    }

    private static function conflict(
        BlockNode $block,
        string $path,
    ): ?string
    {
        if ($block->void || !in_array($block->name, ['core/heading', 'core/paragraph'], true)) {
            return null;
        }
        $root = self::savedRoot($block);
        if ($root === null) {
            $evidence = self::alignmentEvidenceWithoutSavedRoot($block);
            return $evidence === [] ? null : sprintf(
                '%s at %s: text-alignment signal has no sole expected saved root (%s); '
                    . 'original block bytes required',
                $block->name,
                $path,
                implode('; ', $evidence),
            );
        }

        $directions = [];
        $signals = [];
        foreach (preg_split(
            '/[\x20\t\r\n\f]+/',
            $root->attribute('class') ?? '',
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [] as $class) {
            if (preg_match('/^has-text-align-(left|center|right)$/', $class, $match) === 1) {
                $directions[$match[1]] = true;
                $signals[] = [
                    'value' => $match[1],
                    'authored' => 'saved-root class ' . $class,
                ];
            }
        }
        $inline = TextAlignmentCss::effectiveInline($root);
        $commentSignals = self::commentSignals($block);

        if ($block->name === 'core/paragraph') {
            $authoredStyle = $root->attribute('style');
            if ($authoredStyle !== null && $authoredStyle !== '') {
                $projection = TextAlignmentCss::paragraphProjection($authoredStyle);
                if (!$projection['preserves']) {
                    return sprintf(
                        '%s at %s: paragraph style-map projection changes effective text alignment '
                            . '(authored %s; delivered %s; projected style %s); '
                            . 'original block bytes required',
                        $block->name,
                        $path,
                        $projection['authored'],
                        $projection['delivered'],
                        $projection['projected'] === '' ? 'removed' : $projection['projected'],
                    );
                }
                if ($inline !== null) {
                    // ParagraphFixer keeps this projected inline winner. It
                    // remains browser-dominant over stale class/comment
                    // metadata, so those inert disagreements need no warning.
                    return null;
                }
            }
        }

        // BIGR-728 already owns one exact paragraph center/justify signature
        // and records its authored/delivered values after removing the
        // conflicting CSS. Everything else must stop here; a broad paragraph
        // exemption would silently pass HTML-only justify/inherit conflicts.
        if (self::isReviewedParagraphJustifyConflict(
            $block,
            $directions,
            $inline,
            $commentSignals,
        )
        ) {
            return null;
        }
        array_push($signals, ...$commentSignals);
        $nonInlineCount = count($signals);
        if ($inline !== null) {
            // Paragraph serialization retains the authored root declaration,
            // including values outside the frozen block-support vocabulary
            // and !important priority. Compare its normalized value with the
            // other paragraph signals so inline-only/canonical pairs remain
            // usable while contradictory legacy or class state fails closed.
            // Heading serialization instead needs an exact, normal-priority
            // support mirror or it silently drops the browser-effective CSS.
            $durablyMirrored = $block->name === 'core/paragraph'
                || ($inline['safe']
                    && in_array($inline['value'], ['left', 'center', 'right'], true)
                    && !$inline['important']);
            $signals[] = [
                'value' => $durablyMirrored && $inline['value'] !== ''
                    ? $inline['value']
                    : "\0opaque-inline",
                'authored' => 'inline ' . $inline['authored'],
            ];
        }
        if ($signals === []) {
            return null;
        }

        $values = array_values(array_unique(array_column($signals, 'value')));
        $unmirroredInline = $block->name === 'core/heading'
            && $inline !== null
            && $nonInlineCount === 0;
        if (!$unmirroredInline && count($values) === 1) {
            return null;
        }

        $evidence = implode('; ', array_map(
            static fn (array $signal): string => $signal['authored'],
            $signals,
        ));
        return sprintf(
            '%s at %s: %s (%s); original block bytes required',
            $block->name,
            $path,
            $unmirroredInline
                ? 'root inline text alignment has no durable comment/class mirror'
                : 'root text-alignment signals conflict',
            $evidence,
        );
    }

    /** @return list<array{value:string,authored:string}> */
    private static function commentSignals(BlockNode $block): array
    {
        $attributes = $block->attributes;
        if ($attributes === null) {
            return [];
        }
        $signals = [];
        $style = $attributes->get('style');
        $typography = $style instanceof JsonObject ? $style->get('typography') : null;
        $canonical = $typography instanceof JsonObject ? $typography->get('textAlign') : null;
        $hasCanonical = $typography instanceof JsonObject && $typography->has('textAlign');
        if ($block->name === 'core/heading' && $hasCanonical) {
            $value = $canonical instanceof JsonString ? $canonical->toNative() : null;
            $signals[] = [
                'value' => is_string($value)
                    && in_array($value, ['left', 'center', 'right'], true)
                    ? $value
                    : "\0unreviewed-comment-canonical",
                'authored' => 'comment style.typography.textAlign:'
                    . ($canonical === null ? 'missing' : self::commentValue($canonical)),
            ];
        } elseif ($canonical instanceof JsonString && $canonical->toNative() !== '') {
            $value = $canonical->toNative();
            $paragraphAlign = $attributes->get('align');
            if (in_array($value, ['left', 'center', 'right'], true)
                || ($value === 'justify'
                    && $paragraphAlign instanceof JsonString
                    && $paragraphAlign->toNative() === 'center')
            ) {
                // Paragraph support projects only the three direct values.
                // `justify` participates solely in the exact BIGR-728
                // degradation signature below; all other unsupported values
                // are inert metadata and must not create warning noise.
                $signals[] = [
                    'value' => $value,
                    'authored' => 'comment style.typography.textAlign:' . $value,
                ];
            }
        }
        $legacy = $attributes->get('textAlign');
        if ($legacy instanceof JsonString && $legacy->toNative() !== '') {
            $value = $legacy->toNative();
            $registeredAlign = $attributes->get('align');
            $headingAlignSuppressesLegacy = $block->name === 'core/heading'
                && $registeredAlign instanceof JsonString
                && in_array($registeredAlign->toNative(), ['left', 'center', 'right'], true);
            if (!$headingAlignSuppressesLegacy) {
                $signals[] = [
                    'value' => in_array($value, ['left', 'center', 'right'], true)
                        ? $value
                        : "\0unreviewed-comment-legacy",
                    'authored' => 'comment textAlign:' . $value,
                ];
            }
        }
        $align = $attributes->get('align');
        if ($block->name === 'core/paragraph' && $align !== null) {
            $value = $align instanceof JsonString ? $align->toNative() : null;
            if ($value !== '' && !in_array($value, ['wide', 'full'], true)) {
                $signals[] = [
                    'value' => is_string($value)
                        && in_array($value, ['left', 'center', 'right'], true)
                        ? $value
                        : "\0unreviewed-comment-align",
                    'authored' => 'comment align:' . self::commentValue($align),
                ];
            }
        }
        $className = $attributes->get('className');
        if ($className instanceof JsonString) {
            foreach (preg_split(
                '/[\x20\t\r\n\f]+/',
                $className->toNative(),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [] as $class) {
                if (preg_match('/^has-text-align-(left|center|right)$/', $class, $match) === 1) {
                    $signals[] = [
                        'value' => $match[1],
                        'authored' => 'comment className ' . $class,
                    ];
                }
            }
        }
        return $signals;
    }

    /** @return list<string> */
    private static function alignmentEvidenceWithoutSavedRoot(BlockNode $block): array
    {
        $evidence = array_column(self::commentSignals($block), 'authored');
        $fragment = HtmlFragment::parse($block->innerHTML);
        $visit = function (HtmlNode $node) use (&$visit, &$evidence): void {
            if ($node->isElement()) {
                foreach (preg_split(
                    '/[\x20\t\r\n\f]+/',
                    $node->attribute('class') ?? '',
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [] as $class) {
                    if (preg_match('/^has-text-align-(?:left|center|right)$/', $class) === 1) {
                        $evidence[] = 'saved HTML class ' . $class;
                    }
                }
                $inline = TextAlignmentCss::effectiveInline($node);
                if ($inline !== null) {
                    $evidence[] = 'saved HTML inline ' . $inline['authored'];
                }
            }
            foreach ($node->children() as $child) {
                $visit($child);
            }
        };
        $visit($fragment->root());
        return array_values(array_unique($evidence));
    }

    private static function commentValue(JsonValue $value): string
    {
        if ($value instanceof JsonString) {
            return $value->toNative();
        }
        return JsJsonEncoder::stringify($value) ?? get_debug_type($value->toNative());
    }

    /**
     * @param array<string,true> $directions
     * @param array{safe:bool,value:string,authored:string,important:bool}|null $inline
     * @param list<array{value:string,authored:string}> $commentSignals
     */
    private static function isReviewedParagraphJustifyConflict(
        BlockNode $block,
        array $directions,
        ?array $inline,
        array $commentSignals,
    ): bool
    {
        if ($block->name !== 'core/paragraph'
            || array_column($commentSignals, 'value') !== ['justify', 'center']
            || $block->attributes?->has('textAlign') === true
        ) {
            return false;
        }
        $align = $block->attributes?->get('align');
        $style = $block->attributes?->get('style');
        $typography = $style instanceof JsonObject ? $style->get('typography') : null;
        $textAlign = $typography instanceof JsonObject ? $typography->get('textAlign') : null;
        if (!$align instanceof JsonString
            || $align->toNative() !== 'center'
            || !$textAlign instanceof JsonString
            || $textAlign->toNative() !== 'justify'
        ) {
            return false;
        }
        if ($inline !== null
            && $inline['safe']
            && $inline['value'] === 'justify'
            && !$inline['important']
            && array_keys($directions) === ['center']
        ) {
            return true;
        }
        // The reviewed adapter's fixed point has neither rendered signal.
        // At that point the contradictory values are inert comment metadata:
        // visitor output is already usable, and repeating a generic warning
        // would only duplicate the actionable degradation row from the pass
        // that removed the actual class/declaration.
        return $inline === null && $directions === [];
    }

    private static function savedRoot(BlockNode $block): ?HtmlNode
    {
        $root = null;
        foreach (HtmlFragment::parse($block->innerHTML)->root()->children() as $child) {
            if ($child->isComment()
                || ($child->isText() && JsString::trim($child->textContent()) === '')
            ) {
                continue;
            }
            if (!$child->isElement() || $root !== null) {
                return null;
            }
            $root = $child;
        }
        if ($root === null) {
            return null;
        }
        if ($block->name === 'core/paragraph') {
            return $root->tagName() === 'p' ? $root : null;
        }
        return preg_match('/^h[1-6]$/', $root->tagName() ?? '') === 1 ? $root : null;
    }
}
