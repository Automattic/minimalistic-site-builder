<?php
declare(strict_types=1);

use Automattic\SiteBuild\SurfaceMarkup;
use Automattic\SiteBuild\Steps\MotionSanityStep;

function surface_band(string $class, string $content = '<!-- wp:paragraph --><p>Keep this content.</p><!-- /wp:paragraph -->'): string
{
    return '<!-- wp:group ' . json_encode(['className' => $class]) . ' --><section class="wp-block-group '
        . $class . '">' . $content . '</section><!-- /wp:group -->';
}

test('Surface budget keeps one optional section and preserves its plain siblings', function () {
    $used = false;
    $plain = surface_band('story');
    assert_eq($plain, SurfaceMarkup::sanitize($plain, 'paper', true, $used)['markup']);
    assert_true(!$used);
    $marked = surface_band('surface--paper story');
    assert_eq($marked, SurfaceMarkup::sanitize($marked, 'paper', true, $used)['markup']);
    assert_true($used);
    $extra = SurfaceMarkup::sanitize(surface_band('surface--paper extra'), 'paper', true, $used);
    assert_true(!str_contains($extra['markup'], 'surface--'));
    assert_contains('<p>Keep this content.</p>', $extra['markup']);
    assert_contains('authored=surface--paper; delivered=removed', implode(' ', $extra['warnings']));
    assert_contains('one section on this page', implode(' ', $extra['warnings']));
    assert_eq($extra['markup'], SurfaceMarkup::sanitize($extra['markup'], 'paper', true, $used)['markup']);
});

test('Surface rejects uncommitted, hero, nested, and functional section textures', function () {
    $nested = surface_band('plain', surface_band('surface--paper child'));
    foreach ([
        [surface_band('surface--paper'), 'none', true],
        [surface_band('surface--film'), 'paper', true],
        [surface_band('surface--paper'), 'paper', false],
        [$nested, 'paper', true],
        [surface_band('surface--paper device--stamp'), 'paper', true],
        [surface_band('surface--paper', '<table><tr><td>Keep this cell.</td></tr></table>'), 'paper', true],
        [surface_band('surface--paper', '<form><input name="name"></form>'), 'paper', true],
        ['<!-- wp:cover {"className":"surface--paper"} --><div class="surface--paper">Photo</div><!-- /wp:cover -->', 'paper', true],
    ] as [$markup, $surface, $eligible]) {
        $used = false;
        $out = SurfaceMarkup::sanitize($markup, $surface, $eligible, $used);
        assert_true(!str_contains($out['markup'], 'surface--'), $markup);
        assert_true(!$used);
        assert_true($out['warnings'] !== []);
        assert_eq($out['markup'], SurfaceMarkup::sanitize($out['markup'], $surface, $eligible, $used)['markup']);
    }
});

test('Surface removes only the invalid child marker and leaves siblings byte for byte intact', function () {
    $sibling = '<!-- wp:paragraph {"className":"other"} --><p class="other">Keep &amp; preserve.</p><!-- /wp:paragraph -->';
    $markup = surface_band('surface--paper', $sibling . surface_band('surface--noise') . $sibling);
    $used = false;
    $out = SurfaceMarkup::sanitize($markup, 'paper', true, $used);
    assert_eq(2, substr_count($out['markup'], $sibling));
    assert_eq(2, substr_count($out['markup'], 'surface--paper'));
    assert_true(!str_contains($out['markup'], 'surface--noise'));
    assert_contains('path=group[', implode(' ', $out['warnings']));
});

test('Surface checks HTML-only classes, raw HTML, and malformed blocks', function () {
    foreach ([
        '<!-- wp:group --><div class=\'wp-block-group surface--paper\'>Text</div><!-- /wp:group -->',
        '<div class="surface--paper">Raw text</div>',
        '<!-- wp:group {"className":"surface--paper"} --><div class="surface--paper">Unclosed text',
    ] as $markup) {
        $used = false;
        $out = SurfaceMarkup::sanitize($markup, 'none', true, $used);
        assert_true(!str_contains($out['markup'], 'surface--'));
        assert_contains('text', strtolower($out['markup']));
        assert_true($out['warnings'] !== []);
    }
    $used = false;
    $markup = '<!-- wp:group --><section class="surface--paper">Story</section><!-- /wp:group -->';
    assert_eq($markup, SurfaceMarkup::sanitize($markup, 'paper', true, $used)['markup']);
    assert_true($used);
});

test('Surface leaves an image background plain and preserves the image', function () {
    $markup = '<!-- wp:group {"className":"surface--paper","style":{"background":{"backgroundImage":{"url":"image.jpg"}}}} -->'
        . '<div class="surface--paper" style="background-image:url(image.jpg)">Story</div><!-- /wp:group -->';
    $used = false;
    $out = SurfaceMarkup::sanitize($markup, 'paper', true, $used);
    assert_true(!str_contains($out['markup'], 'surface--'));
    assert_contains('background-image:url(image.jpg)', $out['markup']);
    assert_true(!$used);
});

test('Surface budget runs on both graphs and resets for each page', function () {
    foreach ([false, true] as $htmlFirst) {
        with_project('surface_budget_', function ($project) use ($htmlFirst): void {
            $project->writeJson('designDirection.json', ['surface' => 'paper', 'surface_reason' => 'The story reproduces a paper field journal.', 'motion' => 'none']);
            $pages = [];
            foreach (['home', 'about'] as $slug) {
                $sections = [];
                foreach (['hero', 'story', 'extra'] as $section) {
                    $sections[] = ['slug' => $section];
                    $project->writeText("theme/parts/page-{$slug}--{$section}.html", surface_band('surface--paper'));
                }
                $pages[] = ['slug' => $slug, 'front' => $slug === 'home', 'sections' => $sections];
            }
            $project->writeJson('pages.json', ['pages' => $pages]);
            $project->writeText('theme/parts/header.html', surface_band('surface--paper'));
            quietly(fn () => (new MotionSanityStep($htmlFirst))->run($project));
            foreach (['home', 'about'] as $slug) {
                assert_true(!str_contains($project->readText("theme/parts/page-{$slug}--hero.html"), 'surface--'));
                assert_contains('surface--paper', $project->readText("theme/parts/page-{$slug}--story.html"));
                assert_true(!str_contains($project->readText("theme/parts/page-{$slug}--extra.html"), 'surface--'));
            }
            assert_true(!str_contains($project->readText('theme/parts/header.html'), 'surface--'));
            $warnings = $project->readJson('warnings.json')['motion-sanity'];
            assert_contains('file=theme/parts/page-home--extra.html; path=', implode(' ', $warnings));
            assert_contains('authored=surface--paper; delivered=removed; disposition=', implode(' ', $warnings));
            $before = $project->readText('theme/parts/page-home--story.html');
            quietly(fn () => (new MotionSanityStep($htmlFirst))->run($project));
            assert_eq($before, $project->readText('theme/parts/page-home--story.html'));
            assert_eq($warnings, $project->readJson('warnings.json')['motion-sanity']);
        });
    }
});

test('Surface cannot move its marker onto raw HTML before the planned section', function () {
    $markup = '<div class="surface--paper outside">Outside content</div>' . surface_band('surface--paper');
    $used = false;
    $out = SurfaceMarkup::sanitize($markup, 'paper', true, $used);
    assert_true(!str_contains($out['markup'], 'surface--'));
    assert_contains('Outside content', $out['markup']);
    assert_contains('Keep this content.', $out['markup']);
    assert_true(!$used);
});

test('Surface preserves quoted attributes when it removes a class', function () {
    $markup = '<!-- wp:group --><section title=\'Keep class="surface--paper" as text\' class="surface--paper plain">Story</section><!-- /wp:group -->';
    $used = false;
    $out = SurfaceMarkup::sanitize($markup, 'none', true, $used);
    assert_contains('title=\'Keep class="surface--paper" as text\'', $out['markup']);
    assert_contains('class="plain"', $out['markup']);
    assert_true(!$used);
});
