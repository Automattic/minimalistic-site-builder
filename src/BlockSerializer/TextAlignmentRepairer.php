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
 * Best-effort, root-scoped repair for reading-copy alignment classes.
 *
 * This deliberately operates after the primary block-fixer transaction: the
 * frozen serializer remains unchanged, while FixBlocksStep can heal the small
 * set of final HTML-only alignment losses it observes. Each target block is
 * transformed to a fixed point in isolation, verified to differ only by the
 * canonical comment attribute and rendered root class, then spliced into an
 * atomically staged whole-file replacement. Unrelated siblings never enter a
 * serializer pass.
 */
final class TextAlignmentRepairer
{
    private const MAX_PASSES = 5;

    public function __construct(
        private ?TemplateTransformer $transformer = null,
        private ?DroppedContentDetector $drops = null,
        private ?StagedFileWriter $writer = null,
    ) {
        $this->transformer ??= new Serializer();
        $this->drops ??= new DroppedContentDetector();
        $this->writer ??= new NativeStagedFileWriter();
    }

    /**
     * @param list<array{0:string,1:AlignmentClassLoss}> $losses
     * @return list<string> one human-readable row per verified repair
     */
    public function repair(string $themeDir, array $losses): array
    {
        $targetsByFile = [];
        $ambiguousTargets = [];
        foreach ($losses as [$file, $loss]) {
            if (!$loss->authoredClassOnSavedRoot
                || !$loss->authoredClassIsSafeRootTextAlignment
                || !in_array($loss->blockName, ['core/paragraph', 'core/heading'], true)
                || $loss->deliveredClasses !== []
                || preg_match('/^has-text-align-(left|center|right)$/', $loss->authoredClass, $match) !== 1
            ) {
                continue;
            }
            $key = $file . "\0" . $loss->blockPath;
            if (isset($ambiguousTargets[$key])) {
                continue;
            }
            $existing = $targetsByFile[$file][$loss->blockPath] ?? null;
            if ($existing !== null && $existing !== $match[1]) {
                unset($targetsByFile[$file][$loss->blockPath]);
                $ambiguousTargets[$key] = true;
                continue;
            }
            $targetsByFile[$file][$loss->blockPath] = $match[1];
        }

        $themeDir = rtrim($themeDir, DIRECTORY_SEPARATOR);
        $resolvedThemeDir = realpath($themeDir);
        if ($resolvedThemeDir === false || !is_dir($resolvedThemeDir)) {
            throw new \RuntimeException("Alignment-repair theme directory does not exist: {$themeDir}");
        }
        $prepared = [];
        foreach ($targetsByFile as $file => $targets) {
            $target = self::resolveThemeFile($resolvedThemeDir, $file);
            $delivered = @file_get_contents($target);
            if ($delivered === false) {
                throw new \RuntimeException("Could not read alignment-repair input: {$target}");
            }
            $blocks = $this->blocksByPath($delivered);
            if ($blocks === null) {
                continue;
            }

            $splices = [];
            $rows = [];
            foreach ($targets as $blockPath => $direction) {
                $block = $blocks[$blockPath] ?? null;
                if ($block === null || $block->innerBlocks !== []) {
                    continue;
                }
                try {
                    $repaired = $this->repairBlock($block, $direction);
                } catch (\Throwable) {
                    // Generated markup can defeat this optional repair. Keep
                    // the delivered bytes and let the residual loss warning
                    // queue it for the later AI repair pass.
                    continue;
                }
                if ($repaired === null) {
                    continue;
                }
                $splices[] = [$block->sourceStart, $block->sourceEnd, $repaired];
                $rows[] = "{$file} block {$blockPath}: \"has-text-align-{$direction}\" "
                    . 're-expressed as style.typography.textAlign';
            }
            if ($splices === []) {
                continue;
            }

            usort($splices, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
            $repairedFile = $delivered;
            foreach ($splices as [$start, $end, $replacement]) {
                $repairedFile = substr($repairedFile, 0, $start)
                    . $replacement
                    . substr($repairedFile, $end);
            }
            $prepared[] = [
                'target' => $target,
                'content' => $repairedFile,
                'rows' => $rows,
            ];
        }

        $staged = [];
        try {
            foreach ($prepared as $file) {
                $temporary = $this->writer->stage($file['target'], $file['content']);
                $staged[] = [
                    'temporary' => $temporary,
                    'target' => $file['target'],
                    'rows' => $file['rows'],
                ];
            }
        } catch (\Throwable $error) {
            foreach ($staged as $file) {
                $this->writer->discard($file['temporary']);
            }
            throw new \RuntimeException(
                'Could not stage text-alignment repair output: ' . $error->getMessage(),
                0,
                $error,
            );
        }

        $rows = [];
        foreach ($staged as $index => $file) {
            try {
                $this->writer->replace($file['temporary'], $file['target']);
            } catch (\Throwable $error) {
                for ($remaining = $index; $remaining < count($staged); $remaining++) {
                    $this->writer->discard($staged[$remaining]['temporary']);
                }
                throw new \RuntimeException(
                    'Could not commit text-alignment repair output: ' . $error->getMessage(),
                    0,
                    $error,
                );
            }
            array_push($rows, ...$file['rows']);
        }
        return $rows;
    }

    private function repairBlock(BlockNode $block, string $direction): ?string
    {
        if ($block->void
            || !in_array($block->name, ['core/paragraph', 'core/heading'], true)
            || $block->attributes === null
            || $block->closingDelimiter !== self::canonicalClosing($block)
        ) {
            return null;
        }
        if (!self::commentAllowsTextAlignment($block->attributes, $direction)) {
            return null;
        }
        $deliveredRoot = self::savedRoot($block->name, $block->innerHTML);
        if ($deliveredRoot === null
            || !self::hasCanonicalSavedRootCloser($block->innerHTML, $deliveredRoot)
        ) {
            return null;
        }
        foreach (preg_split(
            '/[\x20\t\r\n\f]+/',
            $deliveredRoot->attribute('class') ?? '',
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [] as $class) {
            if (preg_match('/^has-text-align-(?:left|center|right)$/', $class) === 1) {
                return null;
            }
        }
        $inline = TextAlignmentCss::effectiveInline($deliveredRoot);
        if ($inline !== null && (!$inline['safe'] || $inline['value'] !== $direction)) {
            return null;
        }
        // Parser nodes are also the immutable before-state used by the strict
        // postcondition below. Deep-clone the typed JSON tree before adding
        // textAlign; cloning only JsonObject's outer shell would still share
        // its nested style/typography objects.
        $attributes = self::attributesWithTextAlignment($block->attributes, $direction);
        if ($attributes === null) {
            return null;
        }

        $shortName = str_starts_with($block->name, 'core/')
            ? substr($block->name, strlen('core/'))
            : $block->name;
        $opening = '<!-- wp:' . $shortName . ' '
            . JsJsonEncoder::serializeAttributes($attributes) . ' -->';
        $relativeOpeningStart = $block->openingStart - $block->sourceStart;
        $relativeOpeningEnd = $block->openingEnd - $block->sourceStart;
        $patched = substr($block->rawSource, 0, $relativeOpeningStart)
            . $opening
            . substr($block->rawSource, $relativeOpeningEnd);

        $current = $patched;
        $converged = false;
        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $result = $this->transformer->transform($current);
            // Paragraph save can transiently produce the wrapper shape that
            // its built-in post-pass collapses. Permit only that reviewed
            // internal repair; the final strict comparison below still proves
            // no delivered change beyond alignment.
            if (array_filter(
                $result->repairs,
                static fn (Repair $repair): bool => $repair->code !== 'nested-paragraph',
            ) !== []) {
                return null;
            }
            if ($result->html === $current) {
                $converged = true;
                break;
            }
            $current = $result->html;
        }
        if (!$converged
            || $this->drops->detect($block->rawSource, $current) !== []
            || !$this->isOnlyAlignmentChange($block, $current, $direction)
        ) {
            return null;
        }
        return $current;
    }

    private function isOnlyAlignmentChange(
        BlockNode $before,
        string $candidate,
        string $direction,
    ): bool {
        $after = $this->soleBlock($candidate);
        if ($after === null
            || $after->name !== $before->name
            || $after->void !== $before->void
            || $after->innerBlocks !== []
            || $before->closingDelimiter !== self::canonicalClosing($before)
            || $after->closingDelimiter !== self::canonicalClosing($after)
        ) {
            return false;
        }

        $expectedAttributes = self::attributesWithTextAlignment($before->attributes, $direction);
        if ($expectedAttributes === null
            || $after->attributes === null
            || JsJsonEncoder::stringify($after->attributes)
                !== JsJsonEncoder::stringify($expectedAttributes)
            || $after->rawAttributes !== JsJsonEncoder::serializeAttributes($after->attributes)
            || $after->openingDelimiter !== self::canonicalOpening($after)
        ) {
            return false;
        }

        $beforeRoot = self::savedRoot($before->name, $before->innerHTML);
        $afterRoot = self::savedRoot($after->name, $after->innerHTML);
        if ($beforeRoot === null
            || $afterRoot === null
            || !self::hasCanonicalSavedRootCloser($before->innerHTML, $beforeRoot)
            || !self::hasCanonicalSavedRootCloser($after->innerHTML, $afterRoot)
            || $beforeRoot->tagName() !== $afterRoot->tagName()
            || $beforeRoot->rawInnerHtml() !== $afterRoot->rawInnerHtml()
            || self::rootEnvelope($before->innerHTML, $beforeRoot)
                !== self::rootEnvelope($after->innerHTML, $afterRoot)
        ) {
            return false;
        }
        $class = 'has-text-align-' . $direction;
        if (self::classCount($beforeRoot, $class) !== 0
            || self::classCount($afterRoot, $class) !== 1
        ) {
            return false;
        }
        return self::attributesWithoutClass($afterRoot, $class) === $beforeRoot->attributes();
    }

    private static function canonicalOpening(BlockNode $block): string
    {
        $shortName = str_starts_with($block->name, 'core/')
            ? substr($block->name, strlen('core/'))
            : $block->name;
        return '<!-- wp:' . $shortName . ' '
            . JsJsonEncoder::serializeAttributes($block->attributes ?? new JsonObject())
            . ' -->';
    }

    private static function canonicalClosing(BlockNode $block): string
    {
        $shortName = str_starts_with($block->name, 'core/')
            ? substr($block->name, strlen('core/'))
            : $block->name;
        return '<!-- /wp:' . $shortName . ' -->';
    }

    private static function hasCanonicalSavedRootCloser(
        string $content,
        HtmlNode $root,
    ): bool {
        $tag = $root->tagName();
        if ($tag === null) {
            return false;
        }
        return substr(
            $content,
            $root->innerEndOffset(),
            $root->endOffset() - $root->innerEndOffset(),
        ) === '</' . $tag . '>';
    }

    /** @return array{0:string,1:string,2:string} */
    private static function rootEnvelope(string $content, HtmlNode $root): array
    {
        return [
            substr($content, 0, $root->startOffset()),
            substr(
                $content,
                $root->innerEndOffset(),
                $root->endOffset() - $root->innerEndOffset(),
            ),
            substr($content, $root->endOffset()),
        ];
    }

    private static function attributesWithTextAlignment(
        ?JsonObject $source,
        string $direction,
    ): ?JsonObject
    {
        // Deep clone: the parsed before-state remains immutable evidence for
        // the strict postcondition, including JSON object/array identity.
        $attributes = JsonValue::parse(JsJsonEncoder::stringify(
            $source ?? new JsonObject(),
        ) ?? '{}');
        if (!$attributes instanceof JsonObject) {
            return null;
        }
        $style = $attributes->get('style');
        if ($style !== null && !$style instanceof JsonObject) {
            return null;
        }
        $typography = $style?->get('typography');
        if ($typography !== null && !$typography instanceof JsonObject) {
            return null;
        }
        if ($typography?->has('textAlign') === true) {
            $existing = $typography->get('textAlign');
            if ($existing === null || !in_array(
                $existing->toNative(),
                [null, '', false, 0.0],
                true,
            )) {
                return null;
            }
        }
        if ($style === null) {
            $style = new JsonObject();
            $attributes->set('style', $style);
        }
        if ($typography === null) {
            $typography = new JsonObject();
            $style->set('typography', $typography);
        }
        $typography->set('textAlign', new JsonString($direction));
        return $attributes;
    }

    private static function commentAllowsTextAlignment(
        ?JsonObject $attributes,
        string $direction,
    ): bool {
        if ($attributes === null) {
            return false;
        }
        // A built-in fixed-point output should not retain the legacy key.
        // Never manufacture a second canonical signal beside customized or
        // otherwise unreviewed delivered comment state.
        if ($attributes->has('textAlign')) {
            return false;
        }
        $className = $attributes->get('className');
        if ($className !== null) {
            if (!$className instanceof JsonString
                || preg_match(
                    '/(?:^|\s)has-text-align-(?:left|center|right)(?:\s|$)/',
                    $className->toNative(),
                ) === 1
            ) {
                return false;
            }
        }
        $align = $attributes->get('align');
        if ($align === null) {
            return true;
        }
        if (!$align instanceof JsonString) {
            return false;
        }
        $value = $align->toNative();
        return in_array($value, ['', 'wide', 'full', $direction], true);
    }

    private static function resolveThemeFile(string $themeDir, string $file): string
    {
        if (preg_match('~\A(?:templates|parts|pages)/[^/\\\\]+\.html\z~D', $file) !== 1) {
            throw new \RuntimeException("Invalid alignment-repair theme path: {$file}");
        }
        $candidate = $themeDir . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException("Alignment-repair input does not exist: {$candidate}");
        }
        if (!str_starts_with($resolved, $themeDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Alignment-repair path escapes the theme: {$file}");
        }
        return $resolved;
    }

    /** @return list<array{name:string,value:string,hasValue:bool}> */
    private static function attributesWithoutClass(HtmlNode $node, string $removed): array
    {
        $attributes = $node->attributes();
        foreach ($attributes as $index => $attribute) {
            if ($attribute['name'] !== 'class') {
                continue;
            }
            $classes = preg_split(
                '/[\x20\t\r\n\f]+/',
                $attribute['value'],
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            $classes = array_values(array_filter(
                $classes,
                static fn (string $class): bool => $class !== $removed,
            ));
            if ($classes === []) {
                array_splice($attributes, $index, 1);
            } else {
                $attributes[$index]['value'] = implode(' ', $classes);
            }
            break;
        }
        return $attributes;
    }

    private static function classCount(HtmlNode $node, string $expected): int
    {
        return count(array_filter(
            preg_split(
                '/[\x20\t\r\n\f]+/',
                $node->attribute('class') ?? '',
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [],
            static fn (string $class): bool => $class === $expected,
        ));
    }

    private static function savedRoot(string $blockName, string $content): ?HtmlNode
    {
        $root = null;
        foreach (HtmlFragment::parse($content)->root()->children() as $child) {
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
        if ($blockName === 'core/paragraph') {
            return $root->tagName() === 'p' ? $root : null;
        }
        return $blockName === 'core/heading'
            && preg_match('/^h[1-6]$/', $root->tagName() ?? '') === 1
            ? $root
            : null;
    }

    /** @return array<string,BlockNode>|null */
    private function blocksByPath(string $markup): ?array
    {
        try {
            $document = DefaultParser::parse($markup);
        } catch (\Throwable) {
            return null;
        }
        $blocks = [];
        $index = 0;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode) {
                if (JsString::trim($node->content) !== '') {
                    $index++;
                }
                continue;
            }
            if ($node instanceof BlockNode) {
                self::collectBlock($node, (string) $index, $blocks);
                $index++;
            }
        }
        return $blocks;
    }

    /** @param array<string,BlockNode> $blocks */
    private static function collectBlock(BlockNode $block, string $path, array &$blocks): void
    {
        $blocks[$path] = $block;
        foreach ($block->innerBlocks as $index => $child) {
            self::collectBlock($child, $path . '/' . $index, $blocks);
        }
    }

    private function soleBlock(string $markup): ?BlockNode
    {
        try {
            $document = DefaultParser::parse($markup);
        } catch (\Throwable) {
            return null;
        }
        $block = null;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode && JsString::trim($node->content) === '') {
                continue;
            }
            if (!$node instanceof BlockNode || $block !== null) {
                return null;
            }
            $block = $node;
        }
        return $block;
    }
}
