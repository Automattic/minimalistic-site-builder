<?php
declare(strict_types=1);

use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\InitialImagePolicy;
use Automattic\SiteBuild\ImagePlaceholder;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;

require_once __DIR__ . '/../FakeImageClient.php';

function initial_image_pages(): array
{
    return ['pages' => [
        ['slug' => 'welcome', 'front' => true, 'sections' => [
            ['slug' => 'opening', 'role' => 'hero'], ['slug' => 'gallery', 'role' => 'content'],
        ]],
        ['slug' => 'about', 'front' => false, 'sections' => [
            ['slug' => 'introduction', 'role' => 'hero'], ['slug' => 'team', 'role' => 'content'],
        ]],
        ['slug' => 'contact', 'front' => false, 'sections' => [
            ['slug' => 'opening', 'role' => 'hero'], ['slug' => 'map', 'role' => 'content'],
        ]],
    ]];
}

test('initial image policy uses page identity and section roles, with any eligible shared use winning', function () {
    $policy = new InitialImagePolicy(initial_image_pages());
    foreach (['parts/page-welcome--gallery.html', 'parts/page-about--introduction.html', 'parts/header.html', 'parts/footer.html'] as $source) {
        assert_true($policy->shouldGenerate(['sources' => [$source]]), $source);
    }
    assert_true($policy->shouldGenerate(['role' => 'site-logo', 'sources' => []]));
    assert_true($policy->shouldGenerate(['sources' => ['parts/page-about--team.html', 'parts/page-welcome--gallery.html']]));
    assert_true(!$policy->shouldGenerate(['sources' => ['parts/page-about--team.html'], 'filename' => 'hero.jpg', 'pageContext' => 'hero']));
    assert_true(!$policy->shouldGenerate(['sources' => ['parts/page-contact--map.html']]));
    assert_true((new InitialImagePolicy(null))->shouldGenerate(['sources' => ['parts/gallery.html']]), 'theme-only compositions are a single homepage');
});

test('a source the plan does not name is generated and flagged, never quietly deferred', function () {
    $policy = new InitialImagePolicy(initial_image_pages());
    // Assembly rewrites provenance: assemble-pages inlines the page parts and
    // deletes them, so a resumed build re-collects against templates/*.html.
    // Deferring those would gray out the homepage the policy means to protect.
    foreach ([['templates/page.html'], ['parts/unknown.html'], []] as $sources) {
        assert_true($policy->shouldGenerate(['sources' => $sources]), implode(',', $sources));
        assert_true($policy->unplaced(['sources' => $sources]), implode(',', $sources));
    }
    assert_true(!$policy->unplaced(['sources' => ['parts/page-about--team.html']]), 'planned but ineligible');
    assert_true(!$policy->unplaced(['sources' => ['parts/header.html']]));
    // A composition the policy does not govern has nothing to report.
    assert_true(!(new InitialImagePolicy(null))->unplaced(['sources' => []]));
    assert_true(!(new InitialImagePolicy(initial_image_pages(), true))->unplaced(['sources' => []]));
    assert_true(!$policy->unplaced(['role' => 'site-logo', 'sources' => []]));
});

test('generateAll overrides the policy so a build can be brought back to full imagery', function () {
    $policy = new InitialImagePolicy(initial_image_pages(), true);
    foreach ([['parts/page-about--team.html'], ['parts/page-contact--map.html'], []] as $sources) {
        assert_true($policy->shouldGenerate(['sources' => $sources]), implode(',', $sources));
    }
});

test('initial image placeholders are valid neutral rasters in every supported shape and filename format', function () {
    foreach (['1:1', '2:3', '3:4', '4:3', '3:2', '4:5', '9:16', '16:9', '21:9'] as $ratio) {
        foreach (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'] as $extension => $mime) {
            $bytes = ImagePlaceholder::bytes(['filename' => 'image.' . $extension, 'aspectRatio' => $ratio]);
            assert_eq($mime, GeminiImage::mimeFromBytes($bytes));
            $size = getimagesizefromstring($bytes);
            [$width, $height] = array_map('intval', explode(':', $ratio));
            assert_eq($width / $height, $size[0] / $size[1], $ratio);
        }
    }
});

test('initial generation delivers homepage and interior heroes, local placeholders elsewhere, in both collectors', function () {
    foreach ([false, true] as $htmlFirst) {
        with_project('initial_images_', function (Project $project) use ($htmlFirst): void {
            $project->writeJson('pages.json', initial_image_pages());
            $project->writeJson('theme/theme.json', ['version' => 3]);
            $slots = [
                'page-welcome--opening' => 'front.jpg',
                'page-welcome--gallery' => 'shared.jpg',
                'page-about--introduction' => 'about.jpg',
                'page-about--team' => 'team.jpg',
                'page-contact--map' => 'map.png',
                'footer' => 'footer.jpg',
            ];
            $subjects = [
                'front.jpg' => 'A quiet landscape beside a lake',
                'shared.jpg' => 'A quiet landscape beside an orchard',
                'about.jpg' => 'A quiet landscape in a valley',
                'team.jpg' => 'A quiet landscape near a mountain',
                'map.png' => 'A quiet landscape on an island',
                'footer.jpg' => 'A quiet landscape beside a beach',
            ];
            foreach ($slots as $part => $filename) {
                $subject = $subjects[$filename];
                $alt = $htmlFirst ? $subject : 'AI_IMAGE: ' . $subject . ' | card | photo | portrait';
                $project->writeText('theme/parts/' . $part . '.html', '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/' . $filename . '" alt="' . $alt . '"/></figure><!-- /wp:image -->');
            }
            $project->writeText('theme/parts/page-contact--opening.html', '<!-- wp:heading --><h2>Contact us</h2><!-- /wp:heading -->');
            $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
            $project->writeText('theme/parts/page-about--team.html', $project->readText('theme/parts/page-about--team.html') . $project->readText('theme/parts/page-welcome--gallery.html'));
            (new CollectImagesStep(htmlFirst: $htmlFirst))->run($project);
            $originalSpecs = array_column($project->readJson('images.json'), null, 'filename');
            (new AssemblePagesStep())->run($project);
            assert_true(!$project->exists('theme/parts/page-about--introduction.html'), 'selection must survive assembly deleting the source parts');
            $client = new FakeImageClient();
            $llm = new FakeLlm();
            $step = new GenerateImagesStep($client, $llm);
            $step->run($project);
            assert_eq(4, count($client->calls), 'homepage images, about hero, and shared footer only');
            assert_eq(2, count($llm->imageCalls), 'only the planned heroes receive QA');
            $specs = array_column($project->readJson('images.json'), null, 'filename');
            foreach (['front.jpg', 'shared.jpg', 'about.jpg', 'footer.jpg'] as $filename) {
                assert_eq('completed', $specs[$filename]['status'], $filename);
            }
            foreach (['team.jpg', 'map.png'] as $filename) {
                assert_eq('placeholder', $specs[$filename]['status'], $filename);
                assert_eq($originalSpecs[$filename]['subject'], $specs[$filename]['subject'], 'keep the authored subject for later generation');
                $bytes = $project->readText('theme/assets/' . $filename);
                assert_eq(ImagePlaceholder::bytes($specs[$filename]), $bytes);
                assert_eq($bytes, $project->readText('plugin/images/' . $filename), 'placeholder is importable by the content plugin');
            }
            $page = $project->readText('plugin/pages/about.html');
            assert_contains('/wp-content/themes/demo/assets/team.jpg', $page);
            assert_contains('/wp-content/themes/demo/assets/shared.jpg', $page);
            assert_true(!str_contains($page, 'theme:./assets/'));
            assert_contains('Contact us', $project->readText('plugin/pages/contact.html'));
            $warnings = implode("\n", $project->readJson('warnings.json')['generate-images']);
            foreach (['theme/assets/team.jpg', 'theme/assets/map.png', 'page-about--team', 'A quiet landscape', 'placeholder'] as $context) {
                assert_contains($context, $warnings);
            }
            assert_true($project->exists(GenerateImagesStep::COMPLETION_ARTIFACT));
            $before = $project->readText('images.json');
            $warningBytes = $project->readText('warnings.json');
            $step->run($project);
            assert_eq(4, count($client->calls), 'resume spends nothing for generated images or placeholders');
            assert_eq($before, $project->readText('images.json'));
            assert_eq($warningBytes, $project->readText('warnings.json'));
            assert_eq($page, $project->readText('plugin/pages/about.html'));
        });
    }
});

test('placeholder-only runs recover missing local assets and preserve previously completed interior images', function () {
    with_project('initial_images_resume_', function (Project $project): void {
        $project->writeJson('pages.json', initial_image_pages());
        $project->writeJson('images.json', [
            ['filename' => 'team.jpg', 'src' => 'theme:./assets/team.jpg', 'subject' => 'Team portrait', 'aspectRatio' => 'square', 'sources' => ['parts/page-about--team.html'], 'status' => 'pending'],
            ['filename' => 'existing.jpg', 'src' => 'theme:./assets/existing.jpg', 'subject' => 'Existing portrait', 'sources' => ['parts/page-about--team.html'], 'status' => 'completed'],
        ]);
        $project->writeText('theme/assets/existing.jpg', 'already generated bytes');
        $client = new FakeImageClient();
        $llm = new FakeLlm();
        $step = new GenerateImagesStep($client, $llm);
        $step->run($project);
        assert_eq([], $client->calls);
        assert_eq([], $client->batches, 'no empty provider batch');
        assert_eq([], $llm->calls);
        assert_eq([], $llm->imageCalls);
        assert_eq('already generated bytes', $project->readText('theme/assets/existing.jpg'));
        assert_eq('completed', $project->readJson('images.json')[1]['status']);
        $expected = $project->readText('theme/assets/team.jpg');
        unlink($project->themePath('assets/team.jpg'));
        $step->run($project);
        assert_eq($expected, $project->readText('theme/assets/team.jpg'), 'a lost placeholder is restored locally');
        assert_eq([], $client->calls);
        assert_true($project->exists(GenerateImagesStep::COMPLETION_ARTIFACT));
    });
});
