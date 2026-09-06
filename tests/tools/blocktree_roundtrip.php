<?php
declare(strict_types=1);

/**
 * Does the model need to write markup at all?
 *
 * For every block document given, this reduces each block to a spec of
 * (name, attributes, children) — throwing the authored markup away — then
 * rebuilds the document from that spec alone and diffs it against the input.
 * A file that comes back byte-identical never needed the model to emit HTML.
 *
 * Usage: php tests/tools/blocktree_roundtrip.php <file.html|directory> [...]
 */

require __DIR__ . '/../../autoload.php';

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeNormalizer;
use Automattic\SiteBuild\BlockSerializer\CommentSerializer;
use Automattic\SiteBuild\BlockSerializer\NormalizedBlock;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\ParagraphFixer;
use Automattic\SiteBuild\BlockSerializer\Serializer;

$registry = new BlockRegistry();
$saves = new SaveStrategyRegistry($registry);
$normalizer = new AttributeNormalizer($registry, $saves);
$comments = new CommentSerializer($registry);
$serializer = new Serializer($registry);
$paragraphs = new ParagraphFixer();

/** Reduce a parsed block to {name, attrs, children}; the markup is discarded. */
$toSpec = function (BlockNode $node, string $path) use (&$toSpec, &$render, $normalizer): array {
    $inner = [];
    foreach ($node->innerBlocks as $i => $child) {
        $inner[] = $toSpec($child, $path . '/' . $i);
    }
    // normalize() sources this block's attributes from its children's markup,
    // so the children must already be rebuilt from their own specs.
    $innerHtml = implode("\n\n", array_map($render, $inner));
    $block = $normalizer->normalize($node, $innerHtml, $path);
    return [
        'name' => $node->name,
        'typed' => $block->typedAttributes,
        'attrs' => $block->attributes,
        'children' => $inner,
    ];
};

/** Rebuild markup from the spec alone. No source bytes are consulted. */
$render = function (array $spec) use (&$render, $saves, $comments): string {
    $inner = implode("\n\n", array_map($render, $spec['children']));
    $content = $saves->save($spec['name'], $spec['attrs'], $inner, '');
    $delimAttrs = $comments->attributes(new NormalizedBlock($spec['name'], $spec['typed'], $spec['attrs']));
    return $comments->delimit($spec['name'], $delimAttrs, $content);
};

// Arguments are files, or directories to walk for block documents.
// --show prints the rebuilt document instead of comparing.
$args = array_slice($argv, 1);
$show = in_array('--show', $args, true);
$args = array_values(array_filter($args, fn ($a) => $a !== '--show'));
$files = [];
foreach ($args as $arg) {
    if (is_file($arg)) { $files[] = $arg; continue; }
    if (!is_dir($arg)) { fwrite(STDERR, "no existe: {$arg}\n"); exit(2); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arg));
    foreach ($it as $entry) {
        if ($entry->isFile() && str_ends_with($entry->getFilename(), '.html')
            && str_contains((string) file_get_contents($entry->getPathname()), '<!-- wp:')) {
            $files[] = $entry->getPathname();
        }
    }
}
sort($files);
$pass = 0; $fail = 0; $failed = [];

foreach ($files as $file) {
    $input = file_get_contents($file);
    // Compare against the pipeline's own fixed point, not raw authored bytes.
    $expected = $serializer->transform($input)->html;

    try {
        // Same pre-pass the Serializer runs before parsing.
        $doc = DefaultParser::parse($paragraphs->fix($input)->html);
        $out = [];
        foreach ($doc->nodes() as $i => $node) {
            if (!$node instanceof BlockNode) { continue; }
            $out[] = $render($toSpec($node, (string) $i));
        }
        // The Serializer normalizes its own output the same way; sourcing a
        // paragraph's content yields the whole <p>, which nests on re-render.
        $rebuilt = $paragraphs->fix(implode("\n\n", $out))->html;
    } catch (\Throwable $e) {
        $fail++; $failed[$file] = 'excepción: ' . $e->getMessage();
        continue;
    }

    if ($show) { echo $rebuilt, "\n"; continue; }
    if ($rebuilt === $expected) {
        $pass++;
    } else {
        $fail++;
        $failed[$file] = sprintf('%d bytes esperados, %d reconstruidos', strlen($expected), strlen($rebuilt));
    }
}

printf("%d archivos: %d idénticos, %d con diferencia\n", count($files), $pass, $fail);
foreach ($failed as $f => $why) {
    printf("  FALLA %-58s %s\n", substr($f, -58), $why);
}
exit($fail === 0 ? 0 : 1);
