<?php
declare(strict_types=1);

use Automattic\SiteBuild\UiMockupImage;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;

require_once __DIR__ . '/../FakeImageClient.php';

test('native UI selects supported interfaces and leaves photos and logos to the provider', function () {
    foreach (['week grid' => 'schedule', 'crew roster' => 'roster', 'daily site report form' => 'report', 'client update' => 'summary'] as $subject => $layout) {
        assert_eq($layout, UiMockupImage::layout(['image_kind' => 'ui-mockup', 'subject' => $subject]));
    }
    assert_eq(null, UiMockupImage::layout(['image_kind' => 'photo', 'subject' => 'crew roster']));
    assert_eq(null, UiMockupImage::layout(['image_kind' => 'ui-mockup', 'subject' => 'crew roster', 'role' => 'site-logo']));
    assert_eq(null, UiMockupImage::layout(['image_kind' => 'ui-mockup', 'subject' => 'a map of the world']));
});

test('native UI SVG contains fixed shapes with no text or external references', function () {
    $svg = UiMockupImage::svg(['image_kind' => 'ui-mockup', 'subject' => 'week grid <script>bad()</script>',
        'pageContext' => 'left third kept empty'], '21:9', ['settings' => ['color' => ['palette' => [
            ['slug' => 'accent', 'color' => '#123456'], ['slug' => 'base', 'color' => 'url(https://bad.test)'],
        ]]]]);
    assert_contains('#123456', $svg);
    assert_contains('x="614.4"', $svg);
    foreach (['<text', '<script', '<image', 'href=', 'bad.test', 'week grid'] as $forbidden) {
        assert_true(!str_contains($svg, $forbidden), $forbidden);
    }
    assert_eq($svg, UiMockupImage::svg(['image_kind' => 'ui-mockup', 'subject' => 'week grid',
        'pageContext' => 'left third kept empty'], '21:9', ['settings' => ['color' => ['palette' => [['slug' => 'accent', 'color' => '#123456']]]]]));
});

test('native UI produces a complete raster at the requested ratio', function () {
    if (!class_exists(Imagick::class)) { skip_test('Imagick is unavailable.'); }
    foreach (['schedule' => 'week grid', 'roster' => 'crew roster', 'report' => 'daily report', 'summary' => 'client update'] as $subject) {
        $bytes = UiMockupImage::render(['image_kind' => 'ui-mockup', 'subject' => $subject], '4:3');
        assert_true(is_string($bytes));
        assert_eq('image/jpeg', GeminiImage::mimeFromBytes($bytes));
        $size = getimagesizefromstring($bytes);
        assert_eq(1536, $size[0]);
        assert_eq(1152, $size[1]);
    }
});

test('generate images uses local UI by default and preserves provider siblings and completed assets', function () {
    if (!class_exists(Imagick::class)) { skip_test('Imagick is unavailable.'); }
    $tmp = sys_get_temp_dir() . '/native-ui-' . uniqid();
    $project = (new ProjectStore($tmp))->create('native-ui');
    $specs = [];
    foreach (['schedule' => 'week grid', 'photo' => 'an orchard'] as $name => $subject) {
        $specs[] = ['filename' => $name . '.jpg', 'src' => 'theme:./assets/' . $name . '.jpg',
            'subject' => $subject, 'image_kind' => $name === 'schedule' ? 'ui-mockup' : 'photo',
            'aspectRatio' => 'card-landscape', 'status' => 'pending', 'sources' => []];
    }
    $project->writeJson('images.json', $specs);
    $project->writeJson('designDirection.json', ['image_kind' => 'ui-mockup']);
    $client = new FakeImageClient();
    $step = new GenerateImagesStep($client, inspectImages: false);
    $step->run($project);
    assert_eq(1, count($client->calls));
    assert_eq('native-ui', $project->readJson('images.json')[0]['renderer']);
    assert_eq('completed', $project->readJson('images.json')[1]['status']);
    assert_contains('local layout uses placeholder bars', $project->readText('warnings.json'));
    $first = $project->readText('theme/assets/schedule.jpg');
    $step->run($project);
    assert_eq(1, count($client->calls));
    assert_eq($first, $project->readText('theme/assets/schedule.jpg'));
    remove_tree($tmp);
});

test('native UI preserves unsupported empty-space requests through provider fallback', function () {
    foreach (['horizontal centre band kept empty and low in detail', 'top third kept empty', 'empty bottom third'] as $context) {
        $spec = ['image_kind' => 'ui-mockup', 'subject' => 'week grid', 'pageContext' => $context];
        assert_eq(null, UiMockupImage::layout($spec));
        assert_eq(null, UiMockupImage::render($spec, '21:9'));
    }
    $svg = UiMockupImage::svg(['image_kind' => 'ui-mockup', 'subject' => 'week grid left half kept empty'], '4:5');
    assert_contains('height="1920"', $svg);
    assert_contains('x="921.6"', $svg);
});

test('native report and summary assets retain distinct interface structures', function () {
    $report = UiMockupImage::svg(['image_kind' => 'ui-mockup', 'subject' => 'daily report'], '4:3');
    $summary = UiMockupImage::svg(['image_kind' => 'ui-mockup', 'subject' => 'client update'], '4:3');
    assert_true($report !== $summary);
    assert_contains('stroke-width="5"', $report);
    assert_true(!str_contains($summary, 'stroke-width="5"'));
});
