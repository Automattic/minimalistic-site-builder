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
require __DIR__ . '/SpecRenderer.php';

use Automattic\SiteBuild\BlockSerializer\Attributes\AttributeNormalizer;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\ParagraphFixer;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\Tools\SpecRenderer;

$registry = new BlockRegistry();
$normalizer = new AttributeNormalizer($registry, new SaveStrategyRegistry($registry));
$renderer = new SpecRenderer($registry);
$serializer = new Serializer($registry);
$paragraphs = new ParagraphFixer();

/** Reduce a parsed block to {name, attrs, innerBlocks}; the markup is discarded. */
$toSpec = function (BlockNode $node, string $path) use (&$toSpec, $normalizer, $renderer): array {
    $inner = [];
    foreach ($node->innerBlocks as $i => $child) {
        $inner[] = $toSpec($child, $path . '/' . $i);
    }
    // normalize() sources this block's attributes from its children's markup,
    // so the children must already be rebuilt from their own specs.
    $innerHtml = implode("\n\n", array_map(fn ($c) => $renderer->block($c), $inner));
    $block = $normalizer->normalize($node, $innerHtml, $path);
    return [
        'name' => $node->name,
        'attrs' => $block->attributes,
        'typed' => $block->typedAttributes,
        'innerBlocks' => $inner,
    ];
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
        $specs = [];
        foreach ($doc->nodes() as $i => $node) {
            if (!$node instanceof BlockNode) { continue; }
            $specs[] = $toSpec($node, (string) $i);
        }
        $rebuilt = $renderer->document($specs);
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
