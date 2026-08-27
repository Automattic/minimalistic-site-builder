<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\Gates;

/** A minimal valid band root for gate tests. */
function tree_gates_band(array $overrides = [], array $inner = []): array
{
    return [
        'version' => 1,
        'epoch'   => 'fp',
        'blocks'  => [array_merge([
            'name'        => 'core/group',
            'attributes'  => array_merge(['align' => 'full', 'layout' => ['type' => 'constrained']], $overrides),
            'innerBlocks' => $inner,
        ])],
    ];
}

test('Gates localTreeCheck rejects markup keys, bad names, and a stale epoch', function () {
    $issues = Gates::localTreeCheck([
        'version' => 1,
        'epoch'   => 'fp',
        'blocks'  => [['name' => 'core/group', 'innerHTML' => '<div>']],
    ], 'fp');
    $messages = implode(' | ', array_column($issues, 'message'));
    assert_contains('compiler output and never appears in a tree (R1)', $messages);

    $issues = Gates::localTreeCheck(['version' => 1, 'epoch' => 'old', 'blocks' => [['name' => 'core/group']]], 'fp');
    assert_eq(1, count($issues));
    assert_eq('/epoch', $issues[0]['path']);

    assert_eq('/blocks/0/name', Gates::localTreeCheck([
        'version' => 1, 'epoch' => 'fp', 'blocks' => [['name' => 'NotABlock']],
    ], 'fp')[0]['path']);

    assert_eq('', Gates::localTreeCheck('nope', 'fp')[0]['path']);
    assert_eq([], Gates::localTreeCheck(tree_gates_band(), 'fp'));
});

test('Gates screenBandRoot pins one full-bleed core/group with a declared layout', function () {
    assert_eq([], Gates::screenBandRoot(tree_gates_band()));

    $two = tree_gates_band();
    $two['blocks'][] = $two['blocks'][0];
    $failures = Gates::screenBandRoot($two);
    assert_true(count($failures) === 1 && str_contains($failures[0]['message'], 'exactly ONE root core/group (got 2 roots)'));

    $clamped = tree_gates_band();
    unset($clamped['blocks'][0]['attributes']['align']);
    $failures = Gates::screenBandRoot($clamped);
    assert_eq('/blocks/0/attributes/align', $failures[0]['path']);

    $noLayout = tree_gates_band();
    unset($noLayout['blocks'][0]['attributes']['layout']);
    $failures = Gates::screenBandRoot($noLayout);
    assert_eq('/blocks/0/attributes/layout', $failures[0]['path']);
});

test('Gates screenTreeLiterals fails only where a preset exists to spend through', function () {
    // Hex colour anywhere in attributes fails.
    $hex = tree_gates_band(['style' => ['color' => ['background' => '#ff0000']]]);
    $failures = Gates::screenTreeLiterals($hex);
    assert_true(count($failures) === 1 && str_contains($failures[0]['message'], 'hex colour literal'));

    // Absolute spacing under style fails; em and preset refs pass.
    $spacing = tree_gates_band(['style' => ['spacing' => ['padding' => ['top' => '24px']]]]);
    $failures = Gates::screenTreeLiterals($spacing);
    assert_true(count($failures) === 1 && str_contains($failures[0]['message'], 'under style.spacing'));

    assert_eq([], Gates::screenTreeLiterals(tree_gates_band([
        'style' => [
            'spacing'    => ['padding' => ['top' => 'var:preset|spacing|50']],
            'typography' => ['letterSpacing' => '0.05em'],
        ],
    ])));

    // fontSize literal under style fails; a width outside style passes (image geometry).
    $fontSize = tree_gates_band([], [[
        'name'       => 'core/paragraph',
        'attributes' => ['style' => ['typography' => ['fontSize' => '18px']], 'content' => 'x'],
    ]]);
    $failures = Gates::screenTreeLiterals($fontSize);
    assert_true(count($failures) === 1 && str_contains($failures[0]['message'], 'as a font size'));

    assert_eq([], Gates::screenTreeLiterals(tree_gates_band([], [[
        'name'       => 'core/image',
        'attributes' => ['width' => '300px', 'aspectRatio' => '4/3'],
    ]])));
});

test('Gates screenTreeInk fails under 3:1 and advises between 3 and 4.5', function () {
    $palette = [
        ['slug' => 'base', 'color' => '#000000'],
        ['slug' => 'contrast', 'color' => '#ffffff'],
        ['slug' => 'accent', 'color' => '#333333'],
        ['slug' => 'mid', 'color' => '#8a8a8a'],
    ];
    // #333333 on #000000 reads ~1.66:1 — a hard failure.
    $failing = tree_gates_band(['backgroundColor' => 'base', 'textColor' => 'contrast'], [[
        'name'       => 'core/paragraph',
        'attributes' => ['content' => 'Hello', 'textColor' => 'accent'],
    ]]);
    $result = Gates::screenTreeInk($failing, $palette);
    assert_eq(1, count($result['failures']));
    assert_eq('/blocks/0/innerBlocks/0', $result['failures'][0]['path']);
    assert_contains('under the 3:1 floor', $result['failures'][0]['message']);

    // #8a8a8a on #ffffff reads ~3.45:1 — legible but muddy, advisory only.
    $muddy = tree_gates_band(['backgroundColor' => 'contrast', 'textColor' => 'base'], [[
        'name'       => 'core/paragraph',
        'attributes' => ['content' => 'Hello', 'textColor' => 'mid'],
    ]]);
    $result = Gates::screenTreeInk($muddy, $palette);
    assert_eq([], $result['failures']);
    assert_eq(1, count($result['advisories']));
    assert_contains('legible but muddy', $result['advisories'][0]['message']);

    // No palette: the screen no-ops rather than guessing.
    assert_eq(['failures' => [], 'advisories' => []], Gates::screenTreeInk($failing, []));
});

test('Gates substituteInk swaps a failing declared ink for the closest compliant slug, in place', function () {
    $palette = [
        ['slug' => 'base', 'color' => '#000000'],
        ['slug' => 'contrast', 'color' => '#ffffff'],
        ['slug' => 'accent', 'color' => '#333333'],
    ];
    $tree = tree_gates_band(['backgroundColor' => 'base', 'textColor' => 'contrast'], [[
        'name'       => 'core/paragraph',
        'attributes' => ['content' => 'Hello', 'textColor' => 'accent'],
    ]]);
    $changes = Gates::substituteInk($tree, $palette);
    assert_eq([['path' => '/blocks/0/innerBlocks/0', 'from' => 'accent', 'to' => 'contrast']], $changes);
    assert_eq('contrast', $tree['blocks'][0]['innerBlocks'][0]['attributes']['textColor']);

    // Inherited (undeclared) inks are left alone.
    $inherited = tree_gates_band(['backgroundColor' => 'base', 'textColor' => 'accent'], [[
        'name'       => 'core/paragraph',
        'attributes' => ['content' => 'Hello'],
    ]]);
    assert_eq([], Gates::substituteInk($inherited, $palette));
});

test('Gates screenImageGeometry demands geometry only on image-intent nodes', function () {
    $tree = tree_gates_band([], [
        [
            'name'       => 'core/image',
            'attributes' => ['width' => '100%', 'metadata' => ['imageIntent' => 'a moody bakery counter']],
        ],
        [
            'name'       => 'core/image',
            'attributes' => [],
        ],
    ]);
    $failures = Gates::screenImageGeometry($tree);
    assert_eq(1, count($failures));
    assert_eq('/blocks/0/innerBlocks/0/attributes/aspectRatio', $failures[0]['path']);
});

test('Gates screenTreeDiagnostics defers allowed unknowns and fails hard warnings', function () {
    $deferred = Gates::screenTreeDiagnostics(['diagnostics' => [
        ['code' => 'E_UNKNOWN_BLOCK', 'severity' => 'error', 'path' => '/blocks/0', 'message' => 'Block "agent/x" is not registered on this instance.'],
        ['code' => 'W_STATIC_NEEDS_HARNESS', 'severity' => 'warning', 'path' => '/blocks/0', 'message' => 'static'],
    ]], ['agent/x']);
    assert_eq('pass', $deferred['status']);
    assert_eq(['agent/x'], $deferred['deferred']);

    $failed = Gates::screenTreeDiagnostics(['diagnostics' => [
        ['code' => 'W_ATTR_UNKNOWN', 'severity' => 'warning', 'path' => '/blocks/0/attributes/x', 'message' => 'unknown'],
    ]]);
    assert_eq('fail', $failed['status']);
    assert_eq('W_ATTR_UNKNOWN', $failed['failures'][0]['code']);
});

test('Gates screenContentParity maps content loss and round-trip failures', function () {
    $failures = Gates::screenContentParity([
        'all_valid'    => false,
        'invalid'      => [['path' => '/0', 'name' => 'core/quote']],
        'content_lost' => [['path' => '/0/attributes/value', 'message' => 'authored content lost']],
    ]);
    assert_eq(2, count($failures));
    assert_eq('content_lost', $failures[0]['code']);
    assert_eq('compile_invalid', $failures[1]['code']);
    assert_contains('core/quote', $failures[1]['message']);
    assert_eq([], Gates::screenContentParity(['all_valid' => true, 'invalid' => [], 'content_lost' => []]));
});

test('Gates screenOutline wants exactly one h1 and no level jumps', function () {
    $failures = Gates::screenOutline([
        ['role' => 'heading', 'level' => 2, 'name' => 'Sub'],
    ]);
    assert_eq(2, count($failures));
    assert_contains('expected exactly one h1, got 0', $failures[0]['message']);
    assert_contains('heading level jump: h1 -> h2', $failures[1]['message']);

    assert_eq([], Gates::screenOutline([
        ['role' => 'heading', 'level' => 1, 'name' => 'Top'],
        ['role' => 'heading', 'level' => 2, 'name' => 'Sub'],
        ['role' => 'link', 'name' => 'Home'],
    ]));
});

test('Gates screenBandWidths flags clamped bands and disagreeing template parts', function () {
    $boxTree = [
        ['selector_path' => 'body > header.wp-block-template-part:nth-child(1)', 'block_name' => 'core/template-part', 'box' => ['x' => 0, 'y' => 0, 'w' => 1440, 'h' => 100]],
        ['selector_path' => 'body > footer.wp-block-template-part:nth-child(3)', 'block_name' => 'core/template-part', 'box' => ['x' => 0, 'y' => 500, 'w' => 645, 'h' => 80]],
        ['selector_path' => 'body > main.wp-block-post-content:nth-child(2)', 'block_name' => 'core/post-content', 'box' => ['x' => 0, 'y' => 100, 'w' => 1440, 'h' => 400]],
        ['selector_path' => 'body > main.wp-block-post-content:nth-child(2) > div.wp-block-group:nth-child(1)', 'block_name' => 'core/group', 'box' => ['x' => 0, 'y' => 100, 'w' => 1440, 'h' => 400]],
    ];
    $failures = Gates::screenBandWidths($boxTree, 1440.0);
    assert_eq(2, count($failures));
    assert_contains('spans 645px of a 1440px viewport', $failures[0]['message']);
    assert_contains('template parts disagree on width', $failures[1]['message']);
    // Without a viewport only the disagreement audit runs.
    assert_eq(1, count(Gates::screenBandWidths($boxTree, null)));
});

test('Gates screenBandSeams flags daylight between consecutive bands', function () {
    $postContent = 'body > main.wp-block-post-content:nth-child(2)';
    $boxTree = [
        ['selector_path' => 'body > header.wp-block-template-part:nth-child(1)', 'block_name' => 'core/template-part', 'box' => ['x' => 0, 'y' => 0, 'w' => 1440, 'h' => 100]],
        ['selector_path' => $postContent, 'block_name' => 'core/post-content', 'box' => ['x' => 0, 'y' => 100, 'w' => 1440, 'h' => 500]],
        ['selector_path' => "{$postContent} > div.wp-block-group:nth-child(1)", 'block_name' => 'core/group', 'box' => ['x' => 0, 'y' => 100, 'w' => 1440, 'h' => 200]],
        ['selector_path' => "{$postContent} > div.wp-block-group:nth-child(2)", 'block_name' => 'core/group', 'box' => ['x' => 0, 'y' => 319, 'w' => 1440, 'h' => 100]],
    ];
    $failures = Gates::screenBandSeams($boxTree);
    assert_eq(1, count($failures));
    assert_contains('19px of page background between bands', $failures[0]['message']);

    $flush = $boxTree;
    $flush[3]['box']['y'] = 300;
    assert_eq([], Gates::screenBandSeams($flush));
    // No measured bands: nothing to audit.
    assert_eq([], Gates::screenBandSeams([$boxTree[0]]));
});

test('Gates screenTextContrast fails only the unreadable class', function () {
    $failures = Gates::screenTextContrast([
        ['selector_path' => 'p:nth-child(1)', 'ratio' => 2.74, 'color' => 'rgb(184, 134, 47)', 'background' => 'rgb(255, 248, 238)', 'sample' => '24'],
        ['selector_path' => 'p:nth-child(2)', 'ratio' => 4.1, 'color' => 'rgb(0, 0, 0)', 'background' => 'rgb(140, 140, 140)', 'sample' => 'muddy'],
    ]);
    assert_eq(1, count($failures));
    assert_contains('unreadable text (2.74:1', $failures[0]['message']);
});
