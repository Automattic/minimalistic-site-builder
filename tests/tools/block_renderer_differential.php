<?php
declare(strict_types=1);

/**
 * Development-only renderer differential.
 *
 * Usage:
 *   php tests/tools/block_renderer_differential.php
 *   php tests/tools/block_renderer_differential.php --only=id-substring
 *   php tests/tools/block_renderer_differential.php --update-snapshot
 *
 * This is intentionally outside tests/unit: it requires Node and the checked
 * out node_modules oracle, while the runtime and normal PHP test suite do not.
 * The checked snapshot lets the PHP-only suite replay the same probes without
 * either runtime dependency. Snapshot writes always require the explicit
 * --update-snapshot flag and a complete, unfiltered, passing differential.
 */

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;

require_once __DIR__ . '/../../autoload.php';

const RENDERER_SNAPSHOT_SCHEMA_VERSION = 1;
const RENDERER_SNAPSHOT_GENERATOR_VERSION = '1.0.0';

/** @return array<string,mixed> */
function readJsonObject(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("Expected a JSON object in {$path}");
    }
    return $decoded;
}

function sha256File(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("Unable to hash {$path}");
    }
    return $hash;
}

/** @param array<string,mixed> $snapshot */
function encodeSnapshot(array $snapshot): string
{
    return json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
}

/** @return array<string,mixed> */
function block(string $name, array $attributes = [], array $innerBlocks = []): array
{
    return ['name' => $name, 'attributes' => $attributes, 'innerBlocks' => $innerBlocks];
}

/** @return array<string,mixed> */
function probe(string $id, string $name, array $attributes = [], array $innerBlocks = []): array
{
    return ['id' => $id] + block($name, $attributes, $innerBlocks);
}

$paragraph = static fn (string $content = 'Inner'): array => block('core/paragraph', ['content' => $content]);
$listItem = static fn (string $content): array => block('core/list-item', ['content' => $content]);

$cases = [
    probe('paragraph/default', 'core/paragraph', ['content' => 'Hello <strong>world</strong>']),
    probe('paragraph/literal-nbsp', 'core/paragraph', ['content' => "A\u{00A0}B"]),
    probe('paragraph/drop-cap', 'core/paragraph', ['content' => 'Drop', 'dropCap' => true]),
    probe('paragraph/drop-cap-centered', 'core/paragraph', ['content' => 'Drop', 'dropCap' => true, 'style' => ['typography' => ['textAlign' => 'center']]]),
    probe('paragraph/direction-border', 'core/paragraph', [
        'content' => 'RTL', 'direction' => 'rtl', 'borderColor' => 'contrast',
        'style' => ['border' => ['color' => '#123', 'style' => 'dashed', 'width' => '2px', 'radius' => '5px']],
    ]),
    probe('paragraph/support-composite', 'core/paragraph', [
        'content' => 'Supports', 'align' => 'right', 'anchor' => 'hello', 'className' => 'custom custom',
        'textColor' => 'contrast', 'backgroundColor' => 'base', 'fontSize' => 'large',
        'style' => [
            'color' => ['text' => '#123456', 'background' => '#abcdef'],
            'spacing' => ['margin' => ['top' => 'var:preset|spacing|20', 'bottom' => '2rem'], 'padding' => '3px'],
            'typography' => ['fontSize' => '21px', 'lineHeight' => '1.4', 'textAlign' => 'right'],
        ],
    ]),
    probe('group/default', 'core/group', [], [$paragraph()]),
    probe('group/tag-supports', 'core/group', [
        'tagName' => 'section', 'align' => 'wide', 'anchor' => 'region', 'ariaLabel' => 'Region',
        'className' => 'featured', 'backgroundColor' => 'base', 'textColor' => 'contrast',
        'style' => [
            'border' => ['color' => '#abc', 'width' => '2px', 'radius' => ['topLeft' => '4px']],
            'spacing' => ['padding' => ['top' => '1rem', 'left' => '2rem']],
            'dimensions' => ['minHeight' => '10rem'],
        ],
    ], [$paragraph('Group')]),
    probe('group/background-position-supports', 'core/group', [
        'style' => [
            'background' => [
                'backgroundImage' => ['url' => 'https://example.com/a b.jpg'],
                'backgroundPosition' => '25% 75%', 'backgroundRepeat' => 'no-repeat',
                'backgroundSize' => 'cover', 'backgroundAttachment' => 'fixed',
            ],
            'position' => ['type' => 'sticky', 'top' => 'var:preset|spacing|20'],
        ],
    ], [$paragraph('Background')]),
    probe('group/js-number-css', 'core/group', ['style' => [
        'dimensions' => ['minHeight' => 33.33333333333333],
        'typography' => ['lineHeight' => 1.2345678901234567],
    ]], [$paragraph('Numbers')]),
    probe('column/numeric-width', 'core/column', ['width' => 33.33333333333333, 'verticalAlignment' => 'center'], [$paragraph()]),
    probe('column/percent-width', 'core/column', ['width' => '33.33333333333333%', 'verticalAlignment' => 'bottom'], [$paragraph()]),
    probe('column/unit-width-supports', 'core/column', ['width' => '12rem', 'className' => 'unit', 'style' => ['spacing' => ['padding' => '1px']]], [$paragraph()]),
    probe('columns/default', 'core/columns', [], [block('core/column', [], [$paragraph()])]),
    probe('columns/no-stack-align', 'core/columns', ['isStackedOnMobile' => false, 'verticalAlignment' => 'center', 'align' => 'full'], [block('core/column', [], [$paragraph()])]),
    probe('heading/level-supports', 'core/heading', ['content' => 'Heading', 'level' => 4, 'anchor' => 'heading', 'textAlign' => 'center', 'style' => ['typography' => ['textAlign' => 'right']]]),

    probe('image/default', 'core/image', ['url' => 'https://example.com/a.jpg', 'alt' => 'A & B']),
    probe('image/link-caption', 'core/image', [
        'url' => 'https://example.com/a.jpg', 'alt' => 'alt', 'id' => 42, 'sizeSlug' => 'large',
        'href' => 'https://example.com/', 'linkTarget' => '_blank', 'rel' => 'noreferrer noopener',
        'linkClass' => 'linked', 'caption' => 'A <em>caption</em>', 'title' => 'Title',
    ]),
    probe('image/border-resize-focal', 'core/image', [
        'url' => '/a.jpg', 'align' => 'none', 'width' => 320, 'height' => '180px',
        'aspectRatio' => '16/9', 'scale' => 'cover', 'focalPoint' => ['x' => .2, 'y' => .75],
        'borderColor' => 'contrast', 'style' => [
            'border' => ['color' => '#010203', 'style' => 'solid', 'width' => '3px', 'radius' => '4px'],
            'shadow' => '0 1px 2px #000',
        ],
    ]),
    probe('separator/default', 'core/separator'),
    probe('separator/colors-opacity', 'core/separator', ['backgroundColor' => 'contrast', 'opacity' => 'css', 'className' => 'is-style-wide', 'style' => ['color' => ['background' => '#f00']]]),

    probe('buttons/default', 'core/buttons', [], [block('core/button', ['text' => 'Go'])]),
    probe('buttons/layout-font', 'core/buttons', ['fontSize' => 'large', 'layout' => ['type' => 'flex', 'justifyContent' => 'center', 'orientation' => 'vertical']], [block('core/button', ['text' => 'Go'])]),
    probe('button/link', 'core/button', ['text' => 'Go', 'url' => '/go', 'title' => 'Go now', 'linkTarget' => '_blank', 'rel' => 'nofollow']),
    probe('button/button-tag', 'core/button', ['text' => 'Submit', 'tagName' => 'button', 'type' => 'submit']),
    probe('button/width', 'core/button', ['text' => 'Wide', 'width' => 75]),
    probe('button/supports', 'core/button', [
        'text' => 'Styled', 'url' => '#', 'backgroundColor' => 'base', 'textColor' => 'contrast',
        'borderColor' => 'accent', 'fontFamily' => 'heading', 'fontSize' => 'medium',
        'style' => [
            'border' => ['radius' => 0, 'width' => '2px', 'style' => 'solid'],
            'spacing' => ['padding' => ['top' => '1em', 'right' => '2em']],
            'typography' => ['fontWeight' => '700', 'writingMode' => 'vertical-rl'],
            'shadow' => '1px 2px 3px #000',
        ],
    ]),
    probe('spacer/default', 'core/spacer'),
    probe('spacer/preset-width', 'core/spacer', ['height' => 'var:preset|spacing|40', 'width' => '25%', 'style' => ['layout' => ['selfStretch' => 'fixed']]]),
    probe('spacer/fill', 'core/spacer', ['height' => '20px', 'style' => ['layout' => ['selfStretch' => 'fill']]]),

    probe('list/unordered', 'core/list', [], [$listItem('One'), $listItem('Two')]),
    probe('list/ordered-reversed-start', 'core/list', ['ordered' => true, 'reversed' => true, 'start' => 4, 'type' => 'upper-roman'], [$listItem('One'), $listItem('Two')]),
    probe('list-item/nested', 'core/list-item', ['content' => 'Outer'], [block('core/list', [], [$listItem('Inner')])]),

    probe('cover/image', 'core/cover', ['url' => '/cover.jpg', 'alt' => 'Cover', 'id' => 8, 'sizeSlug' => 'large'], [$paragraph('Cover')]),
    probe('cover/image-focal-position', 'core/cover', [
        'url' => '/cover.jpg', 'focalPoint' => ['x' => .12, 'y' => .88], 'contentPosition' => 'bottom right',
        'minHeight' => 480, 'minHeightUnit' => 'px', 'isDark' => false,
    ], [$paragraph('Cover')]),
    probe('cover/parallax', 'core/cover', ['url' => '/cover.jpg', 'alt' => 'Parallax', 'hasParallax' => true, 'focalPoint' => ['x' => .1, 'y' => .2]], [$paragraph()]),
    probe('cover/repeat', 'core/cover', ['url' => '/pattern.png', 'isRepeated' => true, 'focalPoint' => ['x' => 1, 'y' => 0]], [$paragraph()]),
    probe('cover/video', 'core/cover', ['backgroundType' => 'video', 'url' => '/movie.mp4', 'poster' => '/poster.jpg', 'focalPoint' => ['x' => .2, 'y' => .3]], [$paragraph()]),
    probe('cover/embed-video', 'core/cover', ['backgroundType' => 'embed-video', 'url' => 'https://youtu.be/abc'], [$paragraph()]),
    probe('cover/dim-zero', 'core/cover', ['url' => '/cover.jpg', 'dimRatio' => 0, 'overlayColor' => 'base'], [$paragraph()]),
    probe('cover/dim-zero-preset-gradient', 'core/cover', [
        'url' => '/cover.jpg', 'dimRatio' => 0, 'gradient' => 'vivid-cyan-blue-to-vivid-purple',
    ], [$paragraph()]),
    probe('cover/dim-zero-custom-gradient', 'core/cover', [
        'url' => '/cover.jpg', 'dimRatio' => 0, 'overlayColor' => 'base',
        'customGradient' => 'linear-gradient(red, blue)',
    ], [$paragraph()]),
    probe('cover/dim-custom-gradient', 'core/cover', ['url' => '/cover.jpg', 'dimRatio' => 70, 'customOverlayColor' => '#123456', 'customGradient' => 'linear-gradient(red, blue)'], [$paragraph()]),
    probe('cover/preset-gradient-no-image', 'core/cover', ['gradient' => 'vivid-cyan-blue-to-vivid-purple', 'dimRatio' => 50], [$paragraph()]),
    probe('cover/featured-image', 'core/cover', ['url' => '/ignored.jpg', 'useFeaturedImage' => true], [$paragraph()]),
    probe('cover/aspect-border-supports', 'core/cover', [
        'url' => '/cover.jpg', 'anchor' => 'cover', 'align' => 'full', 'borderColor' => 'base',
        'style' => [
            'dimensions' => ['aspectRatio' => '16/9'],
            'border' => ['color' => '#123', 'radius' => '8px', 'width' => '1px'],
            'spacing' => ['padding' => '2rem'],
        ],
    ], [$paragraph()]),

    probe('media-text/image-left', 'core/media-text', ['mediaType' => 'image', 'mediaUrl' => '/media.jpg', 'mediaId' => 10, 'mediaAlt' => 'Media'], [$paragraph()]),
    probe('media-text/image-right-link-focal', 'core/media-text', [
        'mediaType' => 'image', 'mediaUrl' => '/media.jpg', 'mediaPosition' => 'right', 'mediaWidth' => 35,
        'mediaId' => 10, 'mediaSizeSlug' => 'large', 'mediaAlt' => 'Media', 'imageFill' => true,
        'focalPoint' => ['x' => .25, 'y' => .75], 'href' => '/target', 'linkTarget' => '_blank',
        'rel' => 'noopener', 'linkClass' => 'media-link', 'verticalAlignment' => 'bottom',
        'isStackedOnMobile' => false,
    ], [$paragraph()]),
    probe('media-text/video-left', 'core/media-text', ['mediaType' => 'video', 'mediaUrl' => '/media.mp4', 'mediaWidth' => 60], [$paragraph()]),
    probe('media-text/video-no-url', 'core/media-text', ['mediaType' => 'video'], [$paragraph()]),
    probe('media-text/fractional-width', 'core/media-text', [
        'mediaType' => 'image', 'mediaUrl' => '/media.jpg', 'mediaWidth' => 33.33333333333333,
    ], [$paragraph()]),

    probe('quote/default', 'core/quote', [], [$paragraph('Quote')]),
    probe('quote/citation-align', 'core/quote', ['citation' => 'Author', 'textAlign' => 'right'], [$paragraph('Quote')]),
    probe('pullquote/default', 'core/pullquote', ['value' => 'Quote']),
    probe('pullquote/citation-align', 'core/pullquote', ['value' => 'Quote', 'citation' => '<em>Author</em>', 'textAlign' => 'center']),
    probe('gallery/default', 'core/gallery', [], [block('core/image', ['url' => '/1.jpg'])]),
    probe('gallery/columns-no-crop-caption', 'core/gallery', ['columns' => 3, 'imageCrop' => false, 'caption' => 'Gallery <em>caption</em>'], [block('core/image', ['url' => '/1.jpg'])]),

    probe('table/body', 'core/table', ['body' => [['cells' => [
        ['tag' => 'td', 'content' => 'A'], ['tag' => 'td', 'content' => 'B'],
    ]]]]),
    probe('table/all-sections-caption-align', 'core/table', [
        'hasFixedLayout' => false, 'caption' => 'Table caption', 'textColor' => 'contrast',
        'borderColor' => 'base', 'style' => ['color' => ['background' => '#eee'], 'border' => ['width' => '1px']],
        'head' => [['cells' => [['tag' => 'th', 'content' => 'H', 'scope' => 'col', 'align' => 'center', 'colspan' => 2]]]],
        'body' => [['cells' => [['tag' => 'td', 'content' => 'B', 'align' => 'right', 'rowspan' => 2]]]],
        'foot' => [['cells' => [['tag' => 'td', 'content' => 'F']]]],
    ]),
    probe('table/root-typography-spacing', 'core/table', [
        'fontFamily' => 'heading', 'fontSize' => 'large',
        'style' => [
            'spacing' => ['margin' => ['top' => '2rem'], 'padding' => '4px'],
            'typography' => ['fontWeight' => '700', 'lineHeight' => '1.5'],
        ],
        'body' => [['cells' => [['tag' => 'td', 'content' => 'A']]]],
    ]),
    probe('table/empty', 'core/table'),

    probe('embed/default', 'core/embed', ['url' => 'https://example.com/embed']),
    probe('embed/provider-caption', 'core/embed', ['url' => 'https://youtube.com/watch?v=abc', 'type' => 'video', 'providerNameSlug' => 'youtube', 'caption' => 'Video caption', 'responsive' => true]),
    probe('embed/no-url', 'core/embed'),

    probe('social-links/default', 'core/social-links', [], [block('core/social-link', ['service' => 'wordpress', 'url' => 'https://wordpress.org'])]),
    probe('social-links/variants', 'core/social-links', [
        'size' => 'has-large-icon-size', 'showLabels' => true, 'iconColorValue' => '#fff',
        'iconBackgroundColorValue' => '#000', 'className' => 'is-style-pill-shape',
    ], [block('core/social-link', ['service' => 'wordpress', 'url' => 'https://wordpress.org'])]),

    probe('html/raw', 'core/html', ['content' => '<div data-x="&">raw</div>']),
    probe('navigation/no-ref', 'core/navigation', [], [block('core/navigation-link', ['label' => 'Ignored'], [$paragraph('Nested')])]),
    probe('navigation/ref', 'core/navigation', ['ref' => 123], [block('core/navigation-link', [], [$paragraph('Nested')])]),
    probe('navigation-link/inner', 'core/navigation-link', ['label' => 'Ignored'], [$paragraph('Nested')]),
    probe('dynamic/post-content', 'core/post-content'),
    probe('dynamic/page-list', 'core/page-list'),
    probe('dynamic/site-logo', 'core/site-logo'),
    probe('dynamic/site-tagline', 'core/site-tagline'),
    probe('dynamic/site-title', 'core/site-title', ['level' => 3]),
    probe('dynamic/template-part', 'core/template-part', ['slug' => 'header']),
    probe('dynamic/social-link', 'core/social-link', ['service' => 'wordpress', 'url' => 'https://wordpress.org']),
];

// One deliberately dense support vector per static renderer. createBlock()
// applies the pinned block schema first, so unsupported values remain inert;
// supported values expose both effective filter order and skip-serialization
// behavior without maintaining a second hand-written supports table here.
$supportAttributes = [
    'align' => 'full', 'anchor' => 'support-anchor', 'ariaLabel' => 'Support region',
    'className' => 'support-probe support-probe', 'backgroundColor' => 'base',
    'textColor' => 'contrast', 'gradient' => 'vivid-cyan-blue-to-vivid-purple',
    'borderColor' => 'accent', 'fontFamily' => 'heading', 'fontSize' => 'large',
    'style' => [
        'background' => [
            'backgroundImage' => ['url' => 'https://example.com/a b.jpg'],
            'backgroundPosition' => '25% 75%', 'backgroundRepeat' => 'no-repeat',
            'backgroundSize' => 'cover',
        ],
        'border' => ['color' => '#123456', 'style' => 'solid', 'width' => '2px', 'radius' => '6px'],
        'color' => ['text' => '#111111', 'background' => '#eeeeee', 'gradient' => 'linear-gradient(red,blue)'],
        'dimensions' => ['minHeight' => '10rem', 'width' => '80%', 'aspectRatio' => '16/9'],
        'elements' => ['link' => ['color' => ['text' => '#ff0000']]],
        'shadow' => '0 1px 3px #000',
        'spacing' => ['margin' => ['top' => '1rem', 'bottom' => '2rem'], 'padding' => ['left' => '3rem', 'right' => '4rem']],
        'typography' => [
            'fontSize' => '22px', 'fontWeight' => '700', 'letterSpacing' => '.1em',
            'lineHeight' => '1.4', 'textAlign' => 'right', 'textTransform' => 'uppercase',
            'writingMode' => 'vertical-rl',
        ],
    ],
];
$supportSeeds = [
    'core/paragraph' => [['content' => 'Support paragraph'], []],
    'core/group' => [[], [$paragraph()]],
    'core/column' => [[], [$paragraph()]],
    'core/columns' => [[], [block('core/column', [], [$paragraph()])]],
    'core/heading' => [['content' => 'Support heading'], []],
    'core/image' => [['url' => '/support.jpg', 'alt' => 'Support'], []],
    'core/separator' => [[], []],
    'core/buttons' => [[], [block('core/button', ['text' => 'Support'])]],
    'core/button' => [['text' => 'Support', 'url' => '#support'], []],
    'core/spacer' => [[], []],
    'core/list' => [[], [$listItem('Support')]],
    'core/list-item' => [['content' => 'Support'], []],
    'core/cover' => [['url' => '/support.jpg'], [$paragraph()]],
    'core/media-text' => [['mediaType' => 'image', 'mediaUrl' => '/support.jpg'], [$paragraph()]],
    'core/quote' => [['citation' => 'Support'], [$paragraph()]],
    'core/pullquote' => [['value' => 'Support', 'citation' => 'Citation'], []],
    'core/gallery' => [[], [block('core/image', ['url' => '/support.jpg'])]],
    'core/table' => [['body' => [['cells' => [['tag' => 'td', 'content' => 'Support']]]]], []],
    'core/embed' => [['url' => 'https://example.com/support'], []],
    'core/social-links' => [[], [block('core/social-link', ['service' => 'wordpress', 'url' => 'https://wordpress.org'])]],
];
foreach ($supportSeeds as $name => [$specific, $children]) {
    $cases[] = probe(
        'supports/' . substr($name, strlen('core/')),
        $name,
        array_replace_recursive($supportAttributes, $specific),
        $children,
    );
}

$only = null;
$updateSnapshot = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--only=')) {
        if ($only !== null) {
            fwrite(STDERR, "--only may be supplied only once\n");
            exit(2);
        }
        $only = substr($argument, strlen('--only='));
        continue;
    }
    if ($argument === '--update-snapshot') {
        $updateSnapshot = true;
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}
if ($updateSnapshot && $only !== null) {
    fwrite(STDERR, "--update-snapshot requires the complete probe set and cannot be combined with --only\n");
    exit(2);
}
if ($only !== null && $only !== '') {
    $cases = array_values(array_filter(
        $cases,
        static fn (array $case): bool => str_contains((string) $case['id'], $only),
    ));
    if ($cases === []) {
        fwrite(STDERR, "No renderer probes matched --only={$only}\n");
        exit(2);
    }
}

$repositoryRoot = dirname(__DIR__, 2);
$oracleAdapterPath = __DIR__ . '/block_renderer_oracle.js';
$oracleManifestPath = $repositoryRoot . '/tests/fixtures/block-fixer/oracle-manifest.json';
$snapshotPath = $repositoryRoot . '/tests/fixtures/block-fixer/renderer-probes.json';
$registeredRuntimePath = $repositoryRoot . '/tests/fixtures/block-fixer/registered-runtime.json';
$packageLockPath = $repositoryRoot . '/package-lock.json';
$supportedBlocksPath = $repositoryRoot . '/src/BlockSerializer/Registry/supported-blocks.php';
$generatedRegistryPath = $repositoryRoot . '/src/BlockSerializer/Registry/generated-registry.php';

$command = ['node', __DIR__ . '/block_renderer_oracle.js'];
$pipes = [];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, $repositoryRoot);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to launch Node oracle\n");
    exit(2);
}
fwrite($pipes[0], json_encode(['cases' => $cases], JSON_THROW_ON_ERROR));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0) {
    fwrite(STDERR, "Node oracle failed ({$exit}):\n{$stderr}");
    exit(2);
}

$oracle = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($oracle) || !isset($oracle['runtime'], $oracle['cases'])
    || !is_array($oracle['runtime']) || !is_array($oracle['cases'])) {
    fwrite(STDERR, "Node oracle returned an invalid response\n");
    exit(2);
}

$oracleManifest = readJsonObject($oracleManifestPath);
$pinnedRuntime = $oracleManifest['fingerprint']['node'] ?? null;
if (!is_array($pinnedRuntime) || $oracle['runtime'] !== $pinnedRuntime) {
    fwrite(STDERR, "Node oracle runtime does not match the pinned fingerprint\n");
    fwrite(STDERR, '  PINNED: ' . json_encode($pinnedRuntime, JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDERR, '  ACTUAL: ' . json_encode($oracle['runtime'], JSON_UNESCAPED_SLASHES) . "\n");
    exit(2);
}
$pinnedLockHash = $oracleManifest['fingerprint']['packageLockSha256'] ?? null;
if (!is_string($pinnedLockHash) || sha256File($packageLockPath) !== $pinnedLockHash) {
    fwrite(STDERR, "package-lock.json does not match the pinned oracle fingerprint\n");
    exit(2);
}
$implementationSources = $oracleManifest['fingerprint']['implementationSources'] ?? null;
if (!is_array($implementationSources)) {
    fwrite(STDERR, "Pinned oracle fingerprint has no implementation source hashes\n");
    exit(2);
}
foreach ($implementationSources as $relativePath => $expectedHash) {
    if (!is_string($relativePath) || !is_string($expectedHash)
        || sha256File($repositoryRoot . '/' . $relativePath) !== $expectedHash) {
        fwrite(STDERR, "Oracle implementation source does not match its fingerprint: {$relativePath}\n");
        exit(2);
    }
}
$pinnedRegistry = $oracleManifest['registry'] ?? null;
if (!is_array($pinnedRegistry)
    || ($pinnedRegistry['runtimeJsonSha256'] ?? null) !== sha256File($registeredRuntimePath)
    || ($pinnedRegistry['generatedPhpSha256'] ?? null) !== sha256File($generatedRegistryPath)) {
    fwrite(STDERR, "PHP/Node registry snapshots do not match the pinned oracle manifest\n");
    exit(2);
}

$registry = new BlockRegistry();
$saves = new SaveStrategyRegistry($registry);
$passed = 0;
$failed = 0;
foreach ($oracle['cases'] as $case) {
    $id = (string) $case['id'];
    if (isset($case['error'])) {
        echo "ERROR {$id}: {$case['error']}\n";
        $failed++;
        continue;
    }
    try {
        $actual = $saves->save(
            $case['name'],
            $case['attributes'],
            $case['innerSerialized'],
        );
    } catch (Throwable $error) {
        echo "ERROR {$id}: PHP " . $error::class . ': ' . $error->getMessage() . "\n";
        $failed++;
        continue;
    }
    if ($actual === $case['expected']) {
        echo "PASS  {$id}\n";
        $passed++;
        continue;
    }
    echo "FAIL  {$id}\n";
    echo '  NODE: ' . json_encode($case['expected'], JSON_UNESCAPED_SLASHES) . "\n";
    echo '  PHP:  ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

$supportedStaticBlocks = array_values(array_filter(
    $registry->supportedNames(),
    static fn (string $name): bool => $registry->strategy($name) === SaveStrategy::STATIC_RENDERER,
));
$probedStaticBlocks = [];
$staticRendererCases = 0;
foreach ($oracle['cases'] as $case) {
    if (isset($case['error']) || !isset($case['name']) || !is_string($case['name'])) {
        continue;
    }
    if (in_array($case['name'], $supportedStaticBlocks, true)) {
        $probedStaticBlocks[] = $case['name'];
        $staticRendererCases++;
    }
}
$probedStaticBlocks = array_values(array_unique($probedStaticBlocks));
sort($probedStaticBlocks, SORT_STRING);
$uncoveredStaticBlocks = array_values(array_diff($supportedStaticBlocks, $probedStaticBlocks));
sort($uncoveredStaticBlocks, SORT_STRING);
if ($only === null && $uncoveredStaticBlocks !== []) {
    echo 'FAIL  renderer/static-coverage: ' . implode(', ', $uncoveredStaticBlocks) . "\n";
    $failed++;
}

// Missing/unregistered blocks cannot be constructed with createBlock(), but
// their reviewed strategy is still an explicit byte-preservation check.
$missing = $saves->save('vendor/unregistered', [], '', '<p>original</p>');
if ($missing === '<p>original</p>') {
    echo "PASS  missing/original-content\n";
    $passed++;
} else {
    echo "FAIL  missing/original-content\n";
    $failed++;
}

if ($only === null && $failed === 0) {
    $snapshot = [
        'schemaVersion' => RENDERER_SNAPSHOT_SCHEMA_VERSION,
        'provenance' => [
            'generator' => [
                'version' => RENDERER_SNAPSHOT_GENERATOR_VERSION,
                'path' => 'tests/tools/block_renderer_differential.php',
                'sha256' => sha256File(__FILE__),
            ],
            'oracleAdapter' => [
                'path' => 'tests/tools/block_renderer_oracle.js',
                'sha256' => sha256File($oracleAdapterPath),
            ],
            'oracleManifest' => [
                'path' => 'tests/fixtures/block-fixer/oracle-manifest.json',
                'sha256' => sha256File($oracleManifestPath),
            ],
            'packageLock' => [
                'path' => 'package-lock.json',
                'sha256' => sha256File($packageLockPath),
            ],
            'supportedBlocks' => [
                'path' => 'src/BlockSerializer/Registry/supported-blocks.php',
                'sha256' => sha256File($supportedBlocksPath),
            ],
            'registeredRuntime' => [
                'path' => 'tests/fixtures/block-fixer/registered-runtime.json',
                'sha256' => sha256File($registeredRuntimePath),
            ],
            'generatedRegistry' => [
                'path' => 'src/BlockSerializer/Registry/generated-registry.php',
                'sha256' => sha256File($generatedRegistryPath),
            ],
            'runtime' => $oracle['runtime'],
            'oracleFingerprint' => $oracleManifest['fingerprint'],
        ],
        'coverage' => [
            'oracleCases' => count($oracle['cases']),
            'staticRendererCases' => $staticRendererCases,
            'supportedStaticBlocks' => $supportedStaticBlocks,
            'probedStaticBlocks' => $probedStaticBlocks,
            'uncoveredStaticBlocks' => $uncoveredStaticBlocks,
        ],
        'cases' => $oracle['cases'],
    ];
    $snapshotBytes = encodeSnapshot($snapshot);
    if ($updateSnapshot) {
        if (file_put_contents($snapshotPath, $snapshotBytes) === false) {
            fwrite(STDERR, "Unable to write {$snapshotPath}\n");
            exit(2);
        }
        echo "UPDATED renderer-probes.json\n";
    } else {
        $checkedSnapshot = file_get_contents($snapshotPath);
        if ($checkedSnapshot === false || $checkedSnapshot !== $snapshotBytes) {
            echo "FAIL  renderer/snapshot-drift (run with --update-snapshot after reviewing the oracle)\n";
            $failed++;
        } else {
            echo "PASS  renderer/snapshot\n";
            $passed++;
        }
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
