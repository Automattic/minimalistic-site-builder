<?php
declare(strict_types=1);

use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\ImageCrop;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

test('image resolution keeps narrow Lumen and tiled Tbilisi footer slots at 1K', function () {
    $cases = [
        ['filename' => 'footer-annealed-glass-lamp-bench.jpg', 'aspectRatio' => 'card-landscape',
            'pageContext' => 'narrow accompanying photograph in an unequal two-part closing band of a studio website'],
        ['filename' => 'footer-clay-pot-supper.jpg', 'aspectRatio' => 'card-landscape',
            'pageContext' => 'flat mosaic tile in a footer band, butted edge to edge with adjacent color tiles'],
    ];
    $method = new ReflectionMethod(GenerateImagesStep::class, 'generationSpec');
    $method->setAccessible(true);
    foreach ($cases as $spec) {
        $spec['subject'] = 'A lamp on a bench';
        foreach (['landscape', 'panoramic'] as $crop) {
            $request = $method->invoke(null, $spec, '', '', $crop);
            assert_eq('1K', $request['sample_image_size']);
            assert_eq(ImageCrop::generationRatio($crop, $spec['aspectRatio'], $spec['pageContext']), $request['aspect_ratio']);
            assert_contains($spec['subject'], $request['prompt']);
        }
    }
});

test('image resolution preserves wide heroes and explicit full-width slots', function () {
    foreach (['16:9', '21:9'] as $ratio) {
        foreach ([
            ['filename' => 'hero.jpg', 'pageContext' => 'compact editorial photo'],
            ['filename' => 'screen.jpg', 'pageContext' => 'hero application screen'],
            ['filename' => 'footer.jpg', 'pageContext' => 'full-width footer photograph'],
            ['filename' => 'cover.jpg', 'pageContext' => 'full-frame backdrop, small subjects'],
            ['filename' => 'story.jpg', 'pageContext' => 'wide feature photograph beside story copy'],
            ['filename' => 'unknown.jpg'],
        ] as $spec) {
            assert_eq('2K', GeminiImage::sampleImageSizeForSlot($spec, $ratio));
        }
    }
});

test('image resolution never increases an existing 1K request', function () {
    foreach (['1:1', '4:3', '3:4', '9:16'] as $ratio) {
        assert_eq('1K', GeminiImage::sampleImageSizeForSlot(['filename' => 'hero.jpg', 'pageContext' => 'full-width background'], $ratio));
    }
    assert_eq('1K', GeminiImage::sampleImageSizeForSlot(['filename' => 'hero.png'], '21:9', true));
});
