<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\JsString;
use Automattic\SiteBuild\BlockSerializer\NormalizedBlock;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\Supports\SupportDomainGuard;
use Automattic\SiteBuild\BlockSerializer\Validation\Validator;

/** Implements the reviewed parse/source/repair/overlay/recreate order. */
final class AttributeNormalizer
{
    public function __construct(
        private BlockRegistry $registry,
        private SaveStrategyRegistry $saves,
        private ?AttributeSourcer $sourcer = null,
        private ?Validator $validator = null,
        private ?CompatibilityRepairs $repairs = null,
        private ?DeprecationAdapters $deprecations = null,
        private ?SupportDomainGuard $supportDomain = null,
    ) {
        $this->sourcer ??= new AttributeSourcer();
        $this->validator ??= new Validator();
        $this->repairs ??= new CompatibilityRepairs();
        $this->deprecations ??= new DeprecationAdapters();
        $this->supportDomain ??= new SupportDomainGuard();
    }

    public function normalize(BlockNode $node, string $innerBlocks, string $blockPath): NormalizedBlock
    {
        $definition = $this->registry->block($node->name);
        $schemas = $this->registry->attributes($node->name);
        $repairRows = [];

        // Duplicate comment-JSON keys were deep-merged during tokenization
        // (last-wins would silently drop the earlier declaration's members
        // while the saved HTML still carries them). Surface each merge as a
        // repair row so the fixer report shows what was recovered.
        foreach ($node->mergedAttributeKeyPaths as $mergedPath) {
            $repairRows[] = new \Automattic\SiteBuild\BlockSerializer\Repair(
                'duplicate-attribute-merged:' . $mergedPath,
                $blockPath,
            );
        }

        // Legacy top-level text alignment — models author core's historical
        // {"textAlign":"center"} on blocks whose pinned registry only knows
        // style.typography.textAlign. The heading deprecation adapter can
        // migrate it, but the later raw-comment overlay clobbers the migrated
        // style whenever the block ALSO authored other style keys, and the
        // unmirrored has-text-align-* class is then dropped (audited:
        // lumen10's centered H1 with typography.lineHeight). Fold the legacy
        // key into the authored style up front so sourcing, validation, and
        // the overlay all see one canonical form. Reviewed scope: only the
        // two reading-copy blocks — button and the site-identity blocks keep
        // their own pinned textAlign deprecations, and blocks that genuinely
        // register a top-level textAlign (e.g. core/quote) are left alone.
        // An authored typography.textAlign always wins over the legacy key.
        // A registered align migration or an authored alignment class is
        // stronger evidence too: leave those for the reviewed deprecation
        // chain instead of manufacturing a second, conflicting class from
        // the legacy key. Malformed authored containers stay fail-closed so
        // the fixer's per-file transaction can deliver the original bytes
        // with a warning; never replace authored state with an empty object.
        if ($node->attributes !== null
            && in_array($node->name, ['core/heading', 'core/paragraph'], true)
            && !array_key_exists('textAlign', $schemas)
        ) {
            $legacyAlign = $node->attributes->get('textAlign');
            if ($legacyAlign instanceof JsonString
                && in_array($legacyAlign->toNative(), ['left', 'center', 'right'], true)
            ) {
                $style = $node->attributes->get('style');
                if ($style !== null && !$style instanceof JsonObject) {
                    throw new \RuntimeException(sprintf(
                        'Cannot canonicalize legacy textAlign for %s at %s: authored style %s is not an object',
                        $node->name,
                        $blockPath,
                        JsJsonEncoder::stringify($style) ?? get_debug_type($style->toNative()),
                    ));
                }
                $typography = $style?->get('typography');
                if ($typography !== null && !$typography instanceof JsonObject) {
                    throw new \RuntimeException(sprintf(
                        'Cannot canonicalize legacy textAlign for %s at %s: authored style.typography %s is not an object',
                        $node->name,
                        $blockPath,
                        JsJsonEncoder::stringify($typography) ?? get_debug_type($typography->toNative()),
                    ));
                }

                $registeredAlign = $node->attributes->get('align');
                $hasRegisteredTextAlign = $registeredAlign instanceof JsonString
                    && in_array($registeredAlign->toNative(), ['left', 'center', 'right'], true);
                $className = $node->attributes->get('className');
                $hasAuthoredTextAlignClass = $className instanceof JsonString
                    && preg_match(
                        '/(?:^|\s)has-text-align-(?:left|center|right)(?:\s|$)/',
                        $className->toNative(),
                    ) === 1;

                if ($typography?->has('textAlign') !== true
                    && !$hasRegisteredTextAlign
                    && !$hasAuthoredTextAlignClass
                ) {
                    // Reviewed canonicalization, not a repair: like the
                    // deprecation adapters it replaces, it does not count
                    // toward the fixer's K.
                    if ($style === null) {
                        $style = new JsonObject();
                        $node->attributes->set('style', $style);
                    }
                    if ($typography === null) {
                        $typography = new JsonObject();
                        $style->set('typography', $typography);
                    }
                    $typography->set('textAlign', new JsonString($legacyAlign->toNative()));
                }
                $node->attributes->remove('textAlign');
            }
        }

        // Invented style keys — authored paths that exist neither in the
        // reviewed tree nor in the pinned runtime — are deleted from the raw
        // comment state before sourcing and validation, so the serialized
        // output drops the same bytes. Unknown keys under a carried
        // pinned-unimplemented family (css, elements, layout, …) are kept
        // verbatim in the delimiter and hidden from validation; value-level
        // mismatches on reviewed paths and unknown keys under the remaining
        // fail-closed family (background) still fail in assertSupported().
        if ($node->attributes !== null) {
            foreach ($this->supportDomain->pruneInventedStylePaths($node->name, $node->attributes) as $prunedPath) {
                $repairRows[] = new \Automattic\SiteBuild\BlockSerializer\Repair(
                    'invented-style-pruned:' . $prunedPath,
                    $blockPath,
                );
            }
        }

        // Keep stdClass identity at the sourcing boundary. Rendering receives
        // arrays only after the typed recreation below.
        $comment = [];
        $rawComment = [];
        foreach ($node->attributes?->entries() ?? [] as $entry) {
            $rawComment[$entry['key']] = $entry['value']->toNative();
            if (!array_key_exists($entry['key'], $schemas)) {
                if ($this->deprecations->isReviewedLegacyCommentAttribute(
                    $node->name,
                    $entry['key'],
                )) {
                    continue;
                }
                // Historical Gutenberg versions can leave attributes which
                // are no longer present in the current registered schema.
                // Silently dropping one would disguise an unimplemented
                // deprecation migration (and can lose authored bytes), so the
                // closed PHP domain rejects that signature before staging.
                throw new \RuntimeException(
                    "Unsupported comment attribute '{$entry['key']}' for {$node->name}; "
                    . 'a reviewed deprecation adapter is required'
                );
            }
            $comment[$entry['key']] = $entry['value']->toNative();
        }
        $commentValues = $this->renderValues($comment);
        $rawCommentValues = $this->renderValues($rawComment);
        $this->supportDomain->assertSupported($node->name, $rawCommentValues, $blockPath);
        // normalizeRawBlock() trims every raw innerHTML string before sourcing,
        // validation, deprecation matching, and originalContent assignment.
        $originalContent = JsString::trim($node->innerHTML);
        $attributes = $this->sourcer->source($schemas, $comment, $originalContent);

        $render = fn (array $candidate): string => $this->saves->save(
            $node->name,
            $candidate,
            $innerBlocks,
            $originalContent,
        );
        $expected = $render($this->renderValues($attributes));
        $isValid = $this->validator->isValid($originalContent, $expected);
        if (!$isValid) {
            $fixed = $this->repairs->apply(
                $this->renderValues($attributes),
                is_array($definition['supports'] ?? null) ? $definition['supports'] : [],
                $originalContent,
                $blockPath,
                $render,
            );
            $attributes = $fixed['attributes'];
            $repairRows = array_merge($repairRows, $fixed['repairs']);

            // Gutenberg validates the built-in repair candidate a second
            // time before it considers deprecated versions. Our adapters are
            // closed, signature-specific ports rather than a generic catalog,
            // but this second predicate is still part of their ordering and
            // must run even when no repair field changed.
            $isValid = $this->validator->isValid(
                $originalContent,
                $render($this->renderValues($attributes)),
            );
        }

        $deprecated = $this->deprecations->apply(
            $node->name,
            $this->renderValues($attributes),
            $originalContent,
            $blockPath,
            $rawCommentValues,
            $isValid,
        );
        $attributes = $deprecated['attributes'];
        $repairRows = array_merge($repairRows, $deprecated['repairs']);
        if (($deprecated['matched'] ?? false) === true) {
            // A successful historical adapter supersedes the preliminary
            // current-schema custom-class attempt. Gutenberg uses that attempt
            // only to validate candidates; the reviewed repair contract counts
            // the final compatibility action, not discarded candidate state.
            $repairRows = array_values(array_filter(
                $repairRows,
                static fn ($repair): bool => $repair->code !== 'custom-class-recovery',
            ));
        }

        // The raw authored comment is additively overlaid after validation and
        // deprecation matching. Because this normalizer walks the grammar tree
        // itself, the corresponding node/name check has already succeeded.
        $raw = $node->attributes ?? new JsonObject();
        foreach ($raw->entries() as $entry) {
            $attributes[$entry['key']] = $entry['value'];
        }

        if ($node->name === 'core/media-text') {
            $url = $this->nativeAt($attributes, 'mediaUrl');
            $type = $this->nativeAt($attributes, 'mediaType');
            if ($url && !$type) {
                $source = (string) $url . ' ' . $originalContent;
                $inferred = preg_match('/<\s*video\b|\.(?:mp4|webm|ogv)(?:[?#]|$)/i', $source) === 1
                    ? 'video' : 'image';
                $attributes['mediaType'] = new JsonString($inferred);
                $repairRows[] = new \Automattic\SiteBuild\BlockSerializer\Repair(
                    'media-type-inference',
                    $blockPath,
                );
            }
        }

        // createBlock() retains only registered keys, fills defaults, and
        // emits attributes in final registered order. Raw overlaid values are
        // deliberately not type-rejected again.
        $typed = new JsonObject();
        foreach ($schemas as $key => $schema) {
            if (!is_array($schema)) {
                throw new \RuntimeException("Invalid schema for {$node->name}.{$key}");
            }
            if (array_key_exists($key, $attributes)) {
                $value = $attributes[$key];
                $typed->set($key, $value instanceof \Automattic\SiteBuild\BlockSerializer\Json\JsonValue
                    ? $value : JsonNative::fromSchema($value, $schema));
            } elseif (array_key_exists('default', $schema)) {
                $typed->set($key, JsonNative::fromSchema($schema['default'], $schema));
            }
        }

        $finalAttributes = JsonNative::objectToArray($typed);
        $repairRows = array_merge($repairRows, $this->deprecations->residualParagraphStyleRepairs(
            $node->name,
            $commentValues,
            $originalContent,
            $render($finalAttributes),
            $blockPath,
        ));

        return new NormalizedBlock(
            $node->name,
            $typed,
            $finalAttributes,
            Repair::dedupe($repairRows),
        );
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function renderValues(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            if ($value instanceof \Automattic\SiteBuild\BlockSerializer\Json\JsonValue) {
                $result[$key] = JsonNative::value($value);
            } elseif ($value instanceof \stdClass) {
                $result[$key] = $this->objectToArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function objectToArray(\stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = $value instanceof \stdClass ? $this->objectToArray($value) : $value;
        }
        return $result;
    }

    /** @param array<string,mixed> $attributes */
    private function nativeAt(array $attributes, string $key): mixed
    {
        $value = $attributes[$key] ?? null;
        return $value instanceof \Automattic\SiteBuild\BlockSerializer\Json\JsonValue
            ? JsonNative::value($value) : $value;
    }

}
