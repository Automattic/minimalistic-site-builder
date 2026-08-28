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

test('a figure with an img wraps as core/image matching the registry save', function () {
    $html = IslandEditableLeaves::wrap('<figure class="hero-media"><img src="theme:hero.jpg" alt="Hero"></figure>');
    assert_contains('<!-- wp:image {"className":"hero-media"} -->', $html);
    assert_contains('<figure class="wp-block-image hero-media"><img src="theme:hero.jpg" alt="Hero"/></figure>', $html);
    assert_contains('<!-- /wp:image -->', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<figure class="wp-block-image hero-media"><img src="theme:hero.jpg" alt="Hero"/></figure>',
        $saves->save('core/image', ['url' => 'theme:hero.jpg', 'alt' => 'Hero', 'className' => 'hero-media'], ''),
        'emitted figure markup matches the registry save shape',
    );
});

test('a figure with img and figcaption wraps with wp-element-caption', function () {
    $html = IslandEditableLeaves::wrap(
        '<figure><img src="x.jpg" alt="A"><figcaption>Cap</figcaption></figure>'
    );
    assert_contains('<!-- wp:image -->', $html);
    assert_contains('<figcaption class="wp-element-caption">Cap</figcaption>', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<figure class="wp-block-image"><img src="x.jpg" alt="A"/><figcaption class="wp-element-caption">Cap</figcaption></figure>',
        $saves->save('core/image', ['url' => 'x.jpg', 'alt' => 'A', 'caption' => 'Cap'], ''),
        'emitted caption markup matches the registry save shape',
    );
});

test('a bare img gets a layout-transparent wp-block-image wrapper', function () {
    $html = IslandEditableLeaves::wrap('<img src="theme:hero.jpg" alt="Hero" loading="lazy">');
    assert_contains('<!-- wp:image {"className":"island-bare-image"} -->', $html);
    assert_contains(
        '<figure class="wp-block-image island-bare-image"><img src="theme:hero.jpg" alt="Hero"/></figure>',
        $html,
    );
    assert_contains('<!-- /wp:image -->', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<figure class="wp-block-image island-bare-image"><img src="theme:hero.jpg" alt="Hero"/></figure>',
        $saves->save('core/image', [
            'url' => 'theme:hero.jpg',
            'alt' => 'Hero',
            'className' => IslandEditableLeaves::BARE_WRAPPER_CLASS,
        ], ''),
        'emitted bare-image markup matches the registry save shape',
    );
});

test('a figure with a span sibling stays inert', function () {
    $html = IslandEditableLeaves::wrap(
        '<figure><span class="frame"></span><img src="x.jpg" alt="A"></figure>'
    );
    assert_true(!str_contains($html, '<!-- wp:image'), 'unsupported figure siblings stay inert');
    assert_contains('<span class="frame"></span>', $html);
    assert_contains('<img src="x.jpg" alt="A">', $html);
});

test('a page whose CSS contains a child combinator on img leaves bare images inert and warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<figure class="ok"><img src="in-figure.jpg" alt="F"></figure><img src="bare.jpg" alt="B">',
        '.hero-media > img { width: 100%; }',
        'design/home.html',
        'page home island hero',
        $warnings,
    );
    assert_contains('<!-- wp:image {"className":"ok"} -->', $html, 'figures still wrap when combinators are present');
    assert_true(!str_contains($html, 'island-bare-image'), 'bare image is not wrapped');
    assert_contains('<img src="bare.jpg" alt="B">', $html);
    assert_eq(1, count($warnings), 'one combinator warning for the page');
    assert_contains('combinator targeting img', $warnings[0]);
    assert_contains('design/home.html', $warnings[0]);
    assert_contains('delivered bare images inert', $warnings[0]);
});

test('island-pages writes the bare-image rule before the page-styles tail', function () {
    with_project('island-editable', function ($project) {
        ip_project($project, ['home' => ip_doc('<section id="hero"><img src="x.jpg" alt="A"></section>')]);
        $marker = '/* Wrap at spaces only — never split a word mid-token. */';
        $project->writeText('theme/style.css', "/* Theme Name: T */\n\n{$marker}\n.word-wrap{}\n");
        (new IslandPagesStep())->run($project);
        $style = $project->readText('theme/style.css');
        $ruleAt = strpos($style, '.island-bare-image');
        $markAt = strpos($style, $marker);
        assert_true($ruleAt !== false, 'bare-image rule is present');
        assert_true($markAt !== false && $ruleAt < $markAt, 'rule sits before the page-styles tail so a resume cannot strip it');
        assert_contains('display: contents', $style);
    });
});

test('serializer round-trips interleaved image inner blocks inside core/html', function () {
    $input = "<!-- wp:html -->\n"
        . '<section id="hero"><!-- wp:image {"className":"island-bare-image"} -->' . "\n"
        . '<figure class="wp-block-image island-bare-image"><img src="theme:hero.jpg" alt="Hero"/></figure>' . "\n"
        . '<!-- /wp:image --></section>' . "\n"
        . '<!-- /wp:html -->';
    $out = (new Serializer())->transform($input)->html;
    assert_contains('<!-- wp:html -->', $out);
    assert_contains('<!-- wp:image {"className":"island-bare-image"} -->', $out);
    assert_contains('<figure class="wp-block-image island-bare-image"><img src="theme:hero.jpg" alt="Hero"/></figure>', $out);
    assert_contains('id="hero"', $out);
});
