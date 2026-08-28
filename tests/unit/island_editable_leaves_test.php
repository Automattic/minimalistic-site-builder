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

test('a figure with a span sibling stays inert and warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<figure><span class="frame"></span><img src="x.jpg" alt="A"></figure>',
        '',
        'design/home.html',
        'page home island hero',
        $warnings,
    );
    assert_true(!str_contains($html, '<!-- wp:image'), 'unsupported figure siblings stay inert');
    assert_contains('<span class="frame"></span>', $html);
    assert_contains('<img src="x.jpg" alt="A">', $html);
    assert_eq(1, count($warnings));
    assert_contains('authored <figure>', $warnings[0]);
    assert_contains('unsupported figure siblings', $warnings[0]);
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

test('a flat ul becomes core/list with core/list-item children', function () {
    $html = IslandEditableLeaves::wrap('<ul><li>One</li><li>Two <strong>b</strong></li></ul>');
    assert_contains('<!-- wp:list -->', $html);
    assert_contains('<!-- wp:list-item -->', $html);
    assert_contains('<ul class="wp-block-list">', $html);
    assert_contains('<li>One</li>', $html);
    assert_contains('<li>Two <strong>b</strong></li>', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<ul class="wp-block-list"><li>One</li></ul>',
        $saves->save('core/list', [], '<li>One</li>'),
        'emitted list markup matches the registry save shape',
    );
});

test('a nested list nests as a list-item containing a list', function () {
    $html = IslandEditableLeaves::wrap('<ul><li>Parent<ul><li>Child</li></ul></li></ul>');
    assert_contains('<!-- wp:list -->', $html);
    assert_contains('<li>Parent<!-- wp:list -->', $html);
    assert_contains('<li>Child</li>', $html);
    assert_eq(2, substr_count($html, '<!-- wp:list -->'));
    assert_eq(2, substr_count($html, '<!-- wp:list-item -->'));
});

test('a table with thead and caption round-trips as core/table', function () {
    $html = IslandEditableLeaves::wrap(
        '<table><caption>Cap</caption><thead><tr><th>H</th></tr></thead><tbody><tr><td>A</td></tr></tbody></table>'
    );
    assert_contains('<!-- wp:table {"hasFixedLayout":false,"className":"island-bare-table"} -->', $html);
    assert_contains('<figure class="wp-block-table island-bare-table">', $html);
    assert_contains('<thead><tr><th>H</th></tr>', $html);
    assert_contains('<tbody><tr><td>A</td></tr>', $html);
    assert_contains('<figcaption class="wp-element-caption">Cap</figcaption>', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<figure class="wp-block-table island-bare-table"><table><thead><tr><th>H</th></tr></thead>'
        . '<tbody><tr><td>A</td></tr></tbody></table><figcaption class="wp-element-caption">Cap</figcaption></figure>',
        $saves->save('core/table', [
            'hasFixedLayout' => false,
            'className' => 'island-bare-table',
            'caption' => 'Cap',
            'head' => [['cells' => [['content' => 'H', 'tag' => 'th']]]],
            'body' => [['cells' => [['content' => 'A', 'tag' => 'td']]]],
        ], ''),
        'emitted table markup matches the registry save shape',
    );
});

test('a table keeps authored class on the table and row text boundaries', function () {
    $html = IslandEditableLeaves::wrap(
        '<table class="hours"><thead><tr><th>Days</th><th>Open</th></tr></thead>'
        . '<tbody><tr><th>Wed</th><td>7am</td></tr></tbody></table>'
    );
    assert_contains('<!-- wp:table {"hasFixedLayout":false,"className":"island-bare-table hours"} -->', $html);
    assert_contains('<figure class="wp-block-table island-bare-table hours">', $html);
    assert_contains('<table class="hours">', $html);
    $stripped = preg_replace('/<!--\s*\/?wp:[a-z-]+[^>]*-->/', '', $html) ?? $html;
    $norm = preg_replace('/\s+/u', ' ', trim(strip_tags($stripped))) ?? '';
    assert_contains('Open Wed', $norm);
    assert_contains('DaysOpen', $norm);
});

test('a pretty-printed table wraps and keeps row text boundaries', function () {
    $html = IslandEditableLeaves::wrap(
        "<table class=\"hours\">\n<thead>\n<tr><th scope=\"col\">Days</th><th scope=\"col\">Counter open</th></tr>\n</thead>\n"
        . "<tbody>\n<tr><th scope=\"row\">Wed</th><td>7am</td></tr>\n</tbody>\n</table>"
    );
    assert_contains('<!-- wp:table {"hasFixedLayout":false,"className":"island-bare-table hours"} -->', $html);
    assert_contains('scope="row"', $html);
    assert_contains('<table class="hours">', $html);
    $stripped = preg_replace('/<!--\s*\/?wp:[a-z-]+[^>]*-->/', '', $html) ?? $html;
    $norm = preg_replace('/\s+/u', ' ', trim(strip_tags($stripped))) ?? '';
    assert_contains('open Wed', $norm);
});

test('a table with cells on their own lines keeps cell text boundaries', function () {
    $html = IslandEditableLeaves::wrap(
        "<table class=\"coffee-table\">\n<thead>\n<tr>\n<th scope=\"col\">Drink</th>\n"
        . "<th scope=\"col\">How</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>Espresso</td>\n"
        . "<td>A shot.</td>\n</tr>\n</tbody>\n</table>"
    );
    assert_contains('<!-- wp:table', $html);
    $stripped = preg_replace('/<!--\s*\/?wp:[a-z-]+[^>]*-->/', '', $html) ?? $html;
    $norm = preg_replace('/\s+/u', ' ', trim(strip_tags($stripped))) ?? '';
    assert_contains('Drink How', $norm);
    assert_contains('Espresso A shot.', $norm);
});

test('a table with th scope=row in tbody wraps as core/table', function () {
    $html = IslandEditableLeaves::wrap(
        '<table><thead><tr><th scope="col">Days</th><th scope="col">Open</th></tr></thead>'
        . '<tbody><tr><th scope="row">Wed</th><td>7am</td></tr></tbody></table>'
    );
    assert_contains('<!-- wp:table', $html);
    assert_contains('<th scope="row">Wed</th>', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_contains(
        '<th scope="row">Wed – Fri</th>',
        $saves->save('core/table', [
            'hasFixedLayout' => false,
            'head' => [['cells' => [
                ['content' => 'Days', 'tag' => 'th', 'scope' => 'col'],
                ['content' => 'Open', 'tag' => 'th', 'scope' => 'col'],
            ]]],
            'body' => [['cells' => [
                ['content' => 'Wed – Fri', 'tag' => 'th', 'scope' => 'row'],
                ['content' => '7am', 'tag' => 'td'],
            ]]],
        ], ''),
    );
});

test('a table with a class on tr stays inert and warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<table><tr class="odd"><td>A</td></tr></table>',
        '',
        'design/home.html',
        'page home island copy',
        $warnings,
    );
    assert_true(!str_contains($html, '<!-- wp:table'), 'unrepresentable row class leaves the table inert');
    assert_contains('<tr class="odd">', $html);
    assert_eq(1, count($warnings), 'inert table is warned, not silent');
    assert_contains('authored <table>', $warnings[0]);
    assert_contains('delivered inert', $warnings[0]);
    assert_contains('disposition skipped', $warnings[0]);
});

test('a table with colspan and rowspan wraps as core/table', function () {
    $html = IslandEditableLeaves::wrap(
        '<table><thead><tr><th scope="col" colspan="2">H</th></tr></thead>'
        . '<tbody><tr><th scope="row" rowspan="2">A</th><td>B</td></tr></tbody></table>'
    );
    assert_contains('<!-- wp:table', $html);
    assert_contains('colspan="2"', $html);
    assert_contains('rowspan="2"', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_contains(
        'colspan="2"',
        $saves->save('core/table', [
            'hasFixedLayout' => false,
            'head' => [['cells' => [['content' => 'H', 'tag' => 'th', 'scope' => 'col', 'colspan' => '2']]]],
            'body' => [['cells' => [
                ['content' => 'A', 'tag' => 'th', 'scope' => 'row', 'rowspan' => '2'],
                ['content' => 'B', 'tag' => 'td'],
            ]]],
        ], ''),
    );
});

test('a blockquote with cite becomes core/quote with attribution', function () {
    $html = IslandEditableLeaves::wrap('<blockquote><p>Hello</p><cite>Ada</cite></blockquote>');
    assert_contains('<!-- wp:quote -->', $html);
    assert_contains('<blockquote class="wp-block-quote">', $html);
    assert_contains('<!-- wp:paragraph -->', $html);
    assert_contains('<p>Hello</p>', $html);
    assert_contains('<cite>Ada</cite>', $html);
    $saves = new SaveStrategyRegistry(new BlockRegistry());
    assert_eq(
        '<blockquote class="wp-block-quote"><p>Hello</p><cite>Ada</cite></blockquote>',
        $saves->save('core/quote', ['citation' => 'Ada'], '<p>Hello</p>'),
        'emitted quote markup matches the registry save shape',
    );
});

test('a quote with a class on cite stays inert and warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<blockquote><p>Hello</p><cite class="by">Ada</cite></blockquote>',
        '',
        'design/home.html',
        'page home island copy',
        $warnings,
    );
    assert_true(!str_contains($html, '<!-- wp:quote'), 'unrepresentable cite class leaves the quote inert');
    assert_contains('<cite class="by">Ada</cite>', $html);
    assert_eq(1, count($warnings));
    assert_contains('authored <blockquote>', $warnings[0]);
    assert_contains('unrepresentable quote structure', $warnings[0]);
});

test('an inert list with nested headings warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<ul class="tenets"><li><h3>Title</h3><p>Body</p></li></ul>',
        '',
        'design/about.html',
        'page about island tenets',
        $warnings,
    );
    assert_true(!str_contains($html, '<!-- wp:list'), 'list with heading children stays inert');
    assert_contains('<!-- wp:heading', $html);
    assert_eq(1, count($warnings));
    assert_contains('authored <ul>', $warnings[0]);
    assert_contains('unsupported list-item inner', $warnings[0]);
});

test('a page whose CSS contains a child combinator on table leaves tables inert and warns', function () {
    $warnings = [];
    $html = IslandEditableLeaves::wrap(
        '<table><tr><td>A</td></tr></table>',
        '.copy > table { width: 100%; }',
        'design/home.html',
        'page home island copy',
        $warnings,
    );
    assert_true(!str_contains($html, '<!-- wp:table'), 'bare table is not wrapped');
    assert_contains('<table><tr><td>A</td></tr></table>', $html);
    assert_eq(1, count($warnings), 'one combinator warning for the page');
    assert_contains('combinator targeting table', $warnings[0]);
    assert_contains('delivered bare tables inert', $warnings[0]);
});
