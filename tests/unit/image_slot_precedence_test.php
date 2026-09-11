<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageQa;
use Automattic\SiteBuild\ImageSlot;
use Automattic\SiteBuild\PreparedImageBatch;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

require_once __DIR__ . '/prepared_image_batch_test.php';

test('the live Tbilisi13 cover keeps its slot inside an ancestor card class', function () {
    with_project('builder_cover_precedence_', function ($project) {
        prepared_image_fixture($project);
        $project->writeJson('pages.json', ['pages' => [['slug' => 'about', 'sections' => [['slug' => 'hero', 'role' => 'hero']]]]]);
        $project->writeJson('plugin/pages.json', ['pages' => [['slug' => 'about']]]);
        $markup = file_get_contents(__DIR__ . '/../fixtures/image-slots/tbilisi13-about-hero.html');
        $project->writeText('plugin/pages/about.html', $markup);
        $spec = ['filename' => 'tbilisi-tavern-cellar-interior.jpg',
            'src' => 'theme:./assets/tbilisi-tavern-cellar-interior.jpg',
            'url' => '/wp-content/themes/tbilisi13/assets/tbilisi-tavern-cellar-interior.jpg',
            'aspectRatio' => 'ultrawide', 'sources' => ['plugin/pages/about.html']];
        $annotated = ImageSlot::annotate($project, [$spec]);
        assert_eq('cover', $annotated[0]['image_slot']);
        assert_eq(true, $annotated[0]['hero_slot']);
        assert_eq(true, ImageQa::applies($annotated[0]));
        foreach (['landscape', 'square', 'portrait'] as $crop) {
            $request = GenerateImagesStep::generationSpec($annotated[0], '', '', $crop);
            assert_eq('16:9', $request['aspect_ratio']);
            assert_eq('2K', $request['sample_image_size']);
        }
        assert_eq($annotated, ImageSlot::annotate($project, $annotated));
        assert_eq($markup, $project->readText('plugin/pages/about.html'));
    });
});

test('full-width images keep their slot while nested card images keep their small crop', function () {
    with_project('builder_card_precedence_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('plugin/pages/home.html', '<!-- wp:group {"className":"item-pattern--card"} --><div>'
            . '<!-- wp:image {"align":"full","className":"card-media"} --><figure><img src="theme:./assets/wide.jpg"></figure><!-- /wp:image -->'
            . '<!-- wp:group {"className":"card-media"} --><div><!-- wp:image --><figure><img src="theme:./assets/card.jpg"></figure><!-- /wp:image --></div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->');
        $specs = array_map(static fn ($name) => ['filename' => $name . '.jpg', 'src' => 'theme:./assets/' . $name . '.jpg',
            'aspectRatio' => 'square', 'sources' => ['plugin/pages/home.html']], ['wide', 'card']);
        $annotated = ImageSlot::annotate($project, $specs);
        assert_eq(['full-width', 'card'], array_column($annotated, 'image_slot'));
        assert_eq([true, false], array_map([ImageQa::class, 'applies'], $annotated));
        foreach (['square' => '1:1', 'landscape' => '3:2'] as $crop => $cardRatio) {
            $wide = GenerateImagesStep::generationSpec($annotated[0], '', '', $crop);
            $card = GenerateImagesStep::generationSpec($annotated[1], '', '', $crop);
            assert_eq('16:9', $wide['aspect_ratio']);
            assert_eq('2K', $wide['sample_image_size']);
            assert_eq($cardRatio, $card['aspect_ratio']);
            assert_eq('1K', $card['sample_image_size']);
        }
    });
});

test('site logos keep a square provider request under every site crop', function () {
    with_project('builder_logo_precedence_', function ($project) {
        prepared_image_fixture($project);
        foreach (['landscape', 'portrait', 'square', 'panoramic', 'mixed'] as $crop) {
            $direction = $project->readJson('designDirection.json');
            $direction['image_crop'] = $crop;
            $project->writeJson('designDirection.json', $direction);
            $batch = PreparedImageBatch::fromProject($project);
            $spec = $batch->specs()[3];
            $spec['aspectRatio'] = 'square';
            $request = $batch->requests()[3];
            assert_eq('1:1', $request['aspect_ratio']);
            assert_eq('1K', $request['sample_image_size']);
            assert_eq('image/png', $request['mime']);
            assert_true(!str_contains($request['prompt'], 'Site-wide crop direction:'));
            assert_eq(false, ImageQa::applies($spec));
            $direct = GenerateImagesStep::generationSpec($spec, '', '', $crop);
            assert_eq('1:1', $direct['aspect_ratio']);
            assert_eq('1K', $direct['sample_image_size']);
        }
    });
});
