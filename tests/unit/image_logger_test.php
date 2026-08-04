<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageLogger;

/** slug() makes an asset filename safe and keeps dots/dashes. */
test('image-logger slug keeps dots and dashes, lowercases the rest', function () {
    assert_eq('hero.jpg', ImageLogger::slug('hero.jpg'));
    assert_eq('about-portrait.jpg', ImageLogger::slug('About Portrait.JPG'));
    assert_eq('image', ImageLogger::slug('   '));
});

/** A successful request renders prompt, model, aspect ratio, spec and output. */
test('image-logger format renders the full prompt, model and params on success', function () {
    $out = ImageLogger::format('hero.jpg', [
        'model'             => 'gemini-3.1-flash-image',
        'prompt'            => 'A bakery at dawn. Style: photorealistic',
        'aspect_ratio'      => '16:9',
        'sample_image_size' => '2K',
        'subject'           => 'A bakery at dawn',
        'page_context'      => 'full-bleed hero with text overlay',
        'style'             => 'photorealistic',
        'image_grade'       => 'warm kodachrome color, soft golden light',
    ], ['path' => 'theme/assets/hero.jpg', 'bytes' => 123456]);

    assert_contains('IMAGE REQUEST LOG', $out);
    assert_contains('File         : hero.jpg', $out);
    assert_contains('Model        : gemini-3.1-flash-image', $out);
    assert_contains('Aspect ratio : 16:9', $out);
    assert_contains('Sample size  : 2K', $out);
    assert_contains('Status       : OK', $out);
    assert_contains('Output       : theme/assets/hero.jpg (123456 bytes)', $out);
    assert_contains('A bakery at dawn', $out);                    // subject
    assert_contains('full-bleed hero with text overlay', $out);   // page context
    assert_contains('IMAGE GRADE', $out);
    assert_contains('warm kodachrome color, soft golden light', $out);
    assert_contains('A bakery at dawn. Style: photorealistic', $out); // full prompt
});

/** A failed request is tagged FAILED and still logs the full prompt, then the error. */
test('image-logger format shows the prompt and the error on failure', function () {
    $out = ImageLogger::format('hero.jpg', [
        'model'  => 'gemini-3.1-flash-image',
        'prompt' => 'A bakery at dawn',
    ], [], 'Image proxy HTTP 500: boom');

    assert_contains('Status       : FAILED', $out);
    assert_contains('PROMPT', $out);
    assert_contains('A bakery at dawn', $out);
    assert_contains('ERROR', $out);
    assert_contains('Image proxy HTTP 500: boom', $out);
    assert_true(strpos($out, 'PROMPT') < strpos($out, 'ERROR'), 'prompt section precedes the error');
    assert_true(!str_contains($out, 'Output       :'), 'no output line on failure');
});

/** setDir(null) makes logging a no-op — nothing is written. */
test('image-logger is a no-op with no directory set', function () {
    ImageLogger::setDir(null);
    ImageLogger::log('hero.jpg', ['prompt' => 'x'], ['path' => 'y']); // must not throw
    assert_true(true, 'no-op did not throw');
});

/** log() writes a numbered per-request file into the target directory. */
test('image-logger writes a numbered transcript file into the dir', function () {
    $dir = sys_get_temp_dir() . '/builder_imglog_' . uniqid();
    ImageLogger::setDir($dir);

    ImageLogger::log('hero.jpg', [
        'model'  => 'gemini-3.1-flash-image',
        'prompt' => 'A bakery at dawn',
    ], ['path' => 'theme/assets/hero.jpg', 'bytes' => 42]);
    ImageLogger::log('about.jpg', [
        'model'  => 'gemini-3.1-flash-image',
        'prompt' => 'A quiet studio',
    ], [], 'boom');

    $files = glob($dir . '/*.log') ?: [];
    sort($files);
    assert_eq(2, count($files), 'two transcripts written');
    assert_eq('01-hero.jpg.log', basename($files[0]));
    assert_eq('02-about.jpg-failed.log', basename($files[1]));
    assert_contains('A bakery at dawn', file_get_contents($files[0]));

    ImageLogger::setDir(null);
    exec('rm -rf ' . escapeshellarg($dir));
});
