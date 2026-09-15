<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\NormalizeInputsStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Patterns\ResolveMediaStep;
use Automattic\SiteBuild\Project;

/**
 * @param array<string, string> $pages   Markup by slug.
 * @param array<string, mixed>  $facts
 */
function media_project(array $pages, array $facts = [], string $theme = 'twentytwentyfive', array $inventory = []): Project
{
    $dir = sys_get_temp_dir() . '/resolve-media-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    $project = new Project($dir, basename($dir));
    $project->writeJson(PatternArtifacts::NORMALIZED, [
        'version' => NormalizeInputsStep::INPUTS_VERSION,
        'theme' => $theme,
        'facts' => $facts,
        'inventory' => $inventory,
    ]);

    foreach ($pages as $slug => $markup) {
        $project->writeJson(
            rtrim(PatternArtifacts::PAGES, '*') . $slug . '.json',
            ['slug' => $slug, 'content' => $markup],
        );
    }

    return $project;
}

function media_image(string $src, string $extra = ''): string
{
    return '<!-- wp:image ' . ($extra !== '' ? $extra . ' ' : '') . '-->
<figure class="wp-block-image"><img src="' . $src . '" alt=""/></figure>
<!-- /wp:image -->';
}

function media_of(Project $project): array
{
    return $project->readJson(PatternArtifacts::MEDIA);
}

/**
 * A pattern from a deployed theme points at the theme's own images, and the
 * destination already has them. Listing those for import would have the host
 * fetch files it is already serving.
 */
test('an image the theme ships needs no import', function () {
    $project = media_project([
        'home' => media_image('/wp-content/themes/twentytwentyfive/assets/images/a.webp'),
    ]);

    (new ResolveMediaStep())->run($project);

    assert_eq([], media_of($project)['images']);
});

/**
 * The theme is found in the path rather than under a fixed prefix, because a
 * host serves its themes wherever it serves them and the inventory was built
 * against that.
 */
test('a theme served from somewhere else is still the theme', function () {
    $project = media_project([
        'home' => media_image('https://cdn.example.com/t/themes/twentytwentyfive/assets/a.webp'),
    ]);

    (new ResolveMediaStep())->run($project);

    assert_eq([], media_of($project)['images']);
});

test('an image from elsewhere is listed for the host to import', function () {
    $project = media_project(['home' => media_image('https://example.com/photo.jpg')]);

    (new ResolveMediaStep())->run($project);

    $images = media_of($project)['images'];
    assert_eq(1, count($images));
    assert_eq('https://example.com/photo.jpg', $images[0]['source']);
    assert_eq(['home'], $images[0]['pages']);
});

test('one image used on two pages is imported once and names both', function () {
    $project = media_project([
        'home' => media_image('https://example.com/photo.jpg'),
        'venue' => media_image('https://example.com/photo.jpg'),
    ]);

    (new ResolveMediaStep())->run($project);

    $images = media_of($project)['images'];
    assert_eq(1, count($images));
    assert_eq(['home', 'venue'], $images[0]['pages']);
});

/**
 * Observed: an inventory built outside WordPress emitted `/assets/images/...`
 * because nothing told it where the theme lives. It applied, it verified, and
 * every image on the published site was broken.
 */
test('a source that is neither the theme\'s nor importable stops the build', function () {
    $project = media_project(['home' => media_image('/assets/images/orphan.webp')]);

    $thrown = assert_throws(static fn () => (new ResolveMediaStep())->run($project));

    assert_contains('orphan.webp', $thrown->getMessage());
    assert_contains('render broken', $thrown->getMessage());
});

/**
 * A cover names its image in the block attributes as well as in the markup,
 * and both have to end up pointing at the import.
 */
test('a source named only in the block attributes is still found', function () {
    $project = media_project([
        'home' => '<!-- wp:cover {"url":"https://example.com/bg.jpg","dimRatio":50} -->
<div class="wp-block-cover"><span class="wp-block-cover__background"></span></div>
<!-- /wp:cover -->',
    ]);

    (new ResolveMediaStep())->run($project);

    assert_eq('https://example.com/bg.jpg', media_of($project)['images'][0]['source']);
});

/**
 * `personalize-content` leaves the avatar binding alone because an attachment
 * id only exists on the destination site. This is where its source becomes
 * something the host can import.
 */
test('the avatar binding becomes an import with the site\'s own image', function () {
    $project = media_project(
        ['home' => media_image(
            '/wp-content/themes/twentytwentyfive/assets/images/a.webp',
            '{"className":"ai-bind-avatar"}',
        )],
        ['avatar' => ['url' => 'https://example.com/me.jpg', 'alt' => 'The owner']],
    );

    (new ResolveMediaStep())->run($project);

    $images = media_of($project)['images'];
    assert_eq(1, count($images));
    assert_eq('avatar', $images[0]['role']);
    assert_eq('The owner', $images[0]['alt']);
});

test('a pattern wanting an avatar the site has no image for is reported', function () {
    $project = media_project([
        'home' => media_image(
            '/wp-content/themes/twentytwentyfive/assets/images/a.webp',
            '{"className":"ai-bind-avatar"}',
        ),
    ]);

    (new ResolveMediaStep())->run($project);

    assert_eq([], media_of($project)['images']);
    assert_contains(
        'no image for',
        (string) json_encode($project->readJson('warnings.json')['resolve-media']),
    );
});

/**
 * A theme ships its calls to action pointing at `#`. Resolving one means
 * guessing which page a button meant, and guessing sends "Register" to the
 * venue page. Saying how many there are does not.
 */
test('links still pointing at a placeholder are counted, by page', function () {
    $project = media_project([
        'home' => '<!-- wp:paragraph --><p><a href="#">Register</a> <a href="/agenda">Agenda</a></p><!-- /wp:paragraph -->',
        'venue' => '<!-- wp:paragraph --><p><a href="https://maps.example/x">Map</a></p><!-- /wp:paragraph -->',
    ]);

    (new ResolveMediaStep())->run($project);

    $warnings = (string) json_encode($project->readJson('warnings.json')['resolve-media']);
    assert_contains('home (1)', $warnings);
    assert_eq(false, str_contains($warnings, 'venue'), 'a link that goes somewhere is not a placeholder');
});

test('a build with no page written stops rather than writing an empty manifest', function () {
    $project = media_project([]);

    $thrown = assert_throws(static fn () => (new ResolveMediaStep())->run($project));

    assert_contains('no media to account for', $thrown->getMessage());
});

test('inputs from an older contract are refused rather than half-read', function () {
    $project = media_project(['home' => media_image('https://example.com/a.jpg')]);
    $project->writeJson(PatternArtifacts::NORMALIZED, ['version' => 0]);

    $thrown = assert_throws(static fn () => (new ResolveMediaStep())->run($project));

    assert_contains('version', $thrown->getMessage());
});
