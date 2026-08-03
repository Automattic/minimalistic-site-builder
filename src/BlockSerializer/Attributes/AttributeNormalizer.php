<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Json\JsonNative;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
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
        $originalContent = $this->jsTrim($node->innerHTML);
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

    private function jsTrim(string $value): string
    {
        // ECMAScript WhiteSpace + LineTerminator characters relevant to HTML.
        return preg_replace(
            '/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u',
            '',
            $value,
        ) ?? trim($value);
    }
}
