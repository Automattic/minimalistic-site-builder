<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\IslandEditableLeaves;
use Automattic\SiteBuild\Steps\IslandPagesStep;

function editable_leaves_part(string $inner): string
{
    $html = '';
    with_project('island-editable', function ($project) use ($inner, &$html): void {
        ip_project($project, ['home' => ip_doc('<section id="hero">' . $inner . '</section>')]);
        (new IslandPagesStep())->run($project);
        $html = ip_part_text($project, 'home', 'hero');
    });
    return $html;
}

test('a heading with an authored class round-trips with wp-block-heading and the class', function () {
    $part = editable_leaves_part('<h2 class="sec-title">Title</h2>');
    assert_contains('<!-- wp:heading {"className":"sec-title"} -->', $part);
    assert_contains('<h2 class="wp-block-heading sec-title">Title</h2>', $part);
    assert_contains('<!-- /wp:heading -->', $part);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<h2 class="wp-block-heading sec-title">Title</h2>',
        $saves->save('core/heading', ['content' => 'Title', 'level' => 2, 'className' => 'sec-title'], ''),
        'emitted heading markup matches the registry save shape',
    );
});

test('a paragraph with inline strong and a is wrapped', function () {
    $part = editable_leaves_part('<p>Read the <strong>brief</strong> and <a href="/work/">work</a>.</p>');
    assert_contains('<!-- wp:paragraph -->', $part);
    assert_contains('<p>Read the <strong>brief</strong> and <a href="/work/">work</a>.</p>', $part);
    assert_contains('<!-- /wp:paragraph -->', $part);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<p>Read the <strong>brief</strong> and <a href="/work/">work</a>.</p>',
        $saves->save('core/paragraph', [
            'content' => 'Read the <strong>brief</strong> and <a href="/work/">work</a>.',
        ], ''),
        'emitted paragraph markup matches the registry save shape',
    );
});

test('a paragraph containing a div is left inert', function () {
    $html = IslandEditableLeaves::wrap('<p>Before<div>nested</div>after</p>');
    assert_true(!str_contains($html, '<!-- wp:paragraph'), 'nested block-level content stays inert');
    assert_eq('<p>Before<div>nested</div>after</p>', $html);
});

test('serializer round-trips interleaved heading inner blocks inside core/html', function () {
    $input = "<!-- wp:html -->\n"
        . '<section id="hero"><!-- wp:heading {"level":1} -->' . "\n"
        . '<h1 class="wp-block-heading">Hi</h1>' . "\n"
        . '<!-- /wp:heading --><!-- wp:paragraph -->' . "\n"
        . '<p>Body</p>' . "\n"
        . '<!-- /wp:paragraph --></section>' . "\n"
        . '<!-- /wp:html -->';
    $out = (new Serializer())->transform($input)->html;
    assert_contains('<!-- wp:html -->', $out);
    assert_contains('<!-- wp:heading {"level":1} -->', $out);
    assert_contains('<h1 class="wp-block-heading">Hi</h1>', $out);
    assert_contains('<!-- wp:paragraph -->', $out);
    assert_contains('<p>Body</p>', $out);
    assert_contains('id="hero"', $out);
});

test('serializer keeps a core/html island with no inner blocks', function () {
    $input = "<!-- wp:html -->\n<section id=\"hero\"><h1>Hi</h1></section>\n<!-- /wp:html -->";
    $out = (new Serializer())->transform($input)->html;
    assert_contains('<!-- wp:html -->', $out);
    assert_contains('<section id="hero"><h1>Hi</h1></section>', $out);
    assert_true(!str_contains($out, '<!-- wp:heading'));
});

test('wrapped island parts survive a serializer pass', function () {
    $part = editable_leaves_part('<h2 class="sec-title">Title</h2><p>Copy</p>');
    $out = (new Serializer())->transform($part)->html;
    assert_contains('<!-- wp:html -->', $out);
    assert_contains('<!-- wp:heading {"className":"sec-title"} -->', $out);
    assert_contains('<h2 class="wp-block-heading sec-title">Title</h2>', $out);
    assert_contains('<!-- wp:paragraph -->', $out);
    assert_contains('<p>Copy</p>', $out);
});

test('an element with an unsupported inline style is left inert', function () {
    $part = editable_leaves_part('<p style="color:red">Hot</p>');
    assert_true(!str_contains($part, '<!-- wp:paragraph'), 'inline style the block cannot save stays inert');
    assert_contains('style="color:red"', $part);
    assert_contains('Hot', $part);
});
