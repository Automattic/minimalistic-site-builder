<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;

require_once __DIR__ . '/../FakeImageClient.php';

/** @return array<string,mixed> */
function slice5_image_reference(): array
{
    return [
        'url' => 'https://reference.example',
        'page_type' => 'landing',
        'owner_type' => 'other',
        'style' => 'Bold, high-contrast, playful',
        'colors' => [['hex' => '#ff90e8', 'name' => 'Candy pink', 'role' => 'accent']],
        'sections' => [[
            'category' => 'feature',
            'description' => 'Full-bleed color field with oversized headline',
        ]],
    ];
}

test('image composer puts reference style immediately before transparent', function () {
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(ImagePromptComposer::class, 'compose'))->getParameters(),
    );
    $reference = array_search('referenceStyle', $parameters, true);
    $transparent = array_search('transparent', $parameters, true);
    assert_true(
        $reference !== false && $transparent !== false && $reference + 1 === $transparent,
        'referenceStyle must immediately precede transparent',
    );
});

test('image composer folds reference character after the shared grade', function () {
    $prompt = ImagePromptComposer::compose(
        subject: 'a tray of macarons',
        pageContext: 'hero',
        style: 'photorealistic',
        imageGrade: 'warm morning light, shallow depth of field',
        referenceStyle: 'Bold, high-contrast, playful',
    );

    $grade = strpos($prompt, 'Art direction for all site imagery');
    $reference = strpos($prompt, 'Visual reference character');
    assert_true($grade !== false && $reference !== false && $grade < $reference);
    assert_contains('Bold, high-contrast, playful', $prompt);
});

test('transparent image skips reference character exactly like the grade', function () {
    $prompt = ImagePromptComposer::compose(
        subject: 'a leaf ornament',
        pageContext: 'divider',
        style: 'flat-design',
        imageGrade: 'warm morning light',
        referenceStyle: 'Bold and playful',
        transparent: true,
    );
    assert_eq(false, str_contains($prompt, 'Bold and playful'));
    assert_eq(false, str_contains($prompt, 'warm morning light'));
});

test('no-reference image prompt remains byte-identical to the pre-slice prompt', function () {
    $prompt = ImagePromptComposer::compose(
        subject: 'a tray of macarons',
        pageContext: 'hero',
        style: 'photorealistic',
        siteContext: 'A neighborhood bakery selling sourdough and pastries.',
        imageGrade: 'warm morning light',
    );
    $explicitEmpty = ImagePromptComposer::compose(
        subject: 'a tray of macarons',
        pageContext: 'hero',
        style: 'photorealistic',
        siteContext: 'A neighborhood bakery selling sourdough and pastries.',
        imageGrade: 'warm morning light',
        referenceStyle: '',
    );
    assert_eq($prompt, $explicitEmpty, 'explicit empty style must add zero prompt bytes');
    assert_eq(
        '1a4e7acf41652f225ef3233cc3390d99a11a6a1a45699493113f1f19991e066d',
        hash('sha256', $prompt),
        'empty reference style must add zero prompt bytes',
    );
});

test('generate-images declares and sends the sanitized reference style', function () {
    with_project('builder_slice5_images_', function ($project): void {
        $project->writeJson('images.json', [
            [
                'filename' => 'hero.jpg',
                'src' => 'theme:./assets/hero.jpg',
                'subject' => 'a tray of macarons',
                'pageContext' => 'homepage hero',
                'style' => 'photorealistic',
                'aspectRatio' => 'landscape',
                'status' => 'pending',
            ],
            [
                'filename' => 'ornament.png',
                'src' => 'theme:./assets/ornament.png',
                'subject' => 'a leaf ornament',
                'pageContext' => 'section divider',
                'style' => 'flat-design',
                'aspectRatio' => 'landscape',
                'status' => 'pending',
            ],
        ]);
        $project->writeJson('designDirection.json', ['image_grade' => 'warm morning light']);
        $project->writeJson('inspiration.json', [
            'urls' => ['https://reference.example'],
            'references' => [slice5_image_reference()],
        ]);

        $images = new FakeImageClient();
        $step = new GenerateImagesStep($images);
        assert_true(in_array('inspiration.json', $step->declaration()->reads, true));
        $step->run($project);

        assert_eq(2, count($images->calls));
        assert_contains('Bold, high-contrast, playful', $images->calls[0]['prompt']);
        assert_eq(false, str_contains($images->calls[1]['prompt'], 'Bold, high-contrast, playful'));
        assert_eq(false, str_contains($images->calls[1]['prompt'], 'warm morning light'));
    });
});

test('disabled generate-images neither declares nor consumes inspiration', function () {
    with_project('builder_slice7_images_disabled_', function ($project): void {
        $project->writeJson('images.json', [[
            'filename' => 'hero.jpg',
            'src' => 'theme:./assets/hero.jpg',
            'subject' => 'a tray of macarons',
            'pageContext' => 'homepage hero',
            'style' => 'photorealistic',
            'aspectRatio' => 'landscape',
            'status' => 'pending',
        ]]);
        $project->writeJson('designDirection.json', ['image_grade' => 'warm morning light']);
        $project->writeJson('inspiration.json', [
            'urls' => ['https://reference.example'],
            'references' => [slice5_image_reference()],
        ]);

        $images = new FakeImageClient();
        $step = new GenerateImagesStep($images, useInspiration: false);
        assert_eq(false, in_array('inspiration.json', $step->declaration()->reads, true));
        $step->run($project);

        assert_eq(1, count($images->calls));
        assert_eq(false, str_contains($images->calls[0]['prompt'], 'Bold, high-contrast, playful'));
        assert_contains('warm morning light', $images->calls[0]['prompt']);
    });
});

test('safety-filter repair keeps the sanitized reference style', function () {
    with_project('builder_slice5_image_repair_', function ($project): void {
        $project->writeJson('images.json', [[
            'filename' => 'hero.jpg',
            'src' => 'theme:./assets/hero.jpg',
            'subject' => 'a tray of macarons',
            'pageContext' => 'homepage hero',
            'style' => 'photorealistic',
            'aspectRatio' => 'landscape',
            'status' => 'pending',
        ]]);
        $project->writeJson('inspiration.json', [
            'urls' => ['https://reference.example'],
            'references' => [slice5_image_reference()],
        ]);

        $images = new FakeImageClient();
        $images->filterPromptSubstrings = ['a tray of macarons'];
        $llm = new FakeLlm();
        $llm->queueText('a repaired pastry still life');
        (new GenerateImagesStep($images, $llm))->run($project);

        assert_eq(2, count($images->calls), 'initial filtered attempt + repaired attempt');
        assert_contains('a repaired pastry still life', $images->calls[1]['prompt']);
        assert_contains('Bold, high-contrast, playful', $images->calls[1]['prompt']);
    });
});
