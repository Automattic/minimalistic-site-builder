<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;

function collect_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_ci_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    return [$project, $tmp];
}

test('collect-images parses img alt placeholders into specs', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/hero-dawn.jpg" '
        . 'alt="AI_IMAGE: A misty valley at dawn, soft light | full-bleed hero with text overlay | photorealistic | landscape"/></figure><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-dawn.jpg', $images[0]['filename']);
    assert_eq('theme:./assets/hero-dawn.jpg', $images[0]['src']);
    assert_eq('landscape', $images[0]['aspectRatio']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('pending', $images[0]['status']);
    assert_contains('misty valley at dawn', $images[0]['subject']);
    assert_eq('full-bleed hero with text overlay', $images[0]['pageContext']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images dedupes the same asset across files and records sources', function () {
    [$project, $tmp] = collect_fixture();
    $tag = '<img src="theme:./assets/logo.jpg" alt="AI_IMAGE: A clean wordmark | site logo in the header | minimalist | square"/>';
    $project->writeText('theme/parts/header.html', $tag);
    $project->writeText('theme/parts/footer.html', $tag);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq(2, count($images[0]['sources']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images collects .png placeholders (transparent-background assets)', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/footer.html',
        '<img src="theme:./assets/grapevine-flourish.png" '
        . 'alt="AI_IMAGE: A small grapevine flourish, thin gold linework | decorative accent under a subheading | illustration | landscape"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('grapevine-flourish.png', $images[0]['filename']);
    assert_eq('theme:./assets/grapevine-flourish.png', $images[0]['src']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers an AI_IMAGE spec left in a cover url', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:dense fog over the dunes at golden hour|ratio:21:9|role:hero","dimRatio":40} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    // Recovery immediately installs a serializer-stable canonical path.
    assert_eq('theme:./assets/' . $images[0]['filename'], $images[0]['src']);
    assert_contains('dense fog over the dunes', $images[0]['subject']);
    assert_eq('21:9', $images[0]['aspectRatio']);
    assert_eq('.jpg', substr($images[0]['filename'], -4));
    $markup = $project->readText('theme/parts/hero.html');
    assert_contains($images[0]['src'], $markup);
    assert_true(!str_contains($markup, '"url":"AI_IMAGE:'), 'raw prompt removed from cover url');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images caps recovered footer source ratios after semantic decoding', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/footer.html',
        '<img src="AI_IMAGE:A potter|ratio:card-portrait|role:footer" alt=""/>'
        . '<!-- wp:cover {"url":"AI_IMAGE:A loom\u007cratio:9:16\u007crole:footer"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
        . '<img src="AI_IMAGE:A wheel|ratio:16:9|role:footer" alt=""/>'
    );
    $project->writeText('theme/parts/hero.html',
        '<img src="AI_IMAGE:A statue|ratio:card-portrait|role:hero" alt=""/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(4, count($images));
    $ratios = array_column($images, 'aspectRatio', 'subject');
    assert_eq('square', $ratios['A potter']);
    assert_eq('square', $ratios['A loom']);
    assert_eq('16:9', $ratios['A wheel'], 'wide footer image remains authored');
    assert_eq('card-portrait', $ratios['A statue'], 'non-footer portrait remains authored');
    $markup = $project->readText('theme/parts/footer.html');
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'recovered source prompts are replaced');

    $warnings = $project->readJson('warnings.json')['collect-images'] ?? [];
    assert_eq(2, count($warnings));
    $joined = implode("\n", $warnings);
    assert_contains("file='theme/parts/footer.html'", $joined);
    assert_contains('authored aspect-ratio="card-portrait"', $joined);
    assert_contains('authored aspect-ratio="9:16"', $joined);
    assert_contains('delivered aspect-ratio="square"', $joined);
    assert_contains('disposition=', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images gives a recovered structured trailing ratio precedence over prose keywords', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:an ultrawide landscape at dawn|portrait feature context|photorealistic|square"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('square', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images normalizes a recovered numeric trailing ratio before heuristic terms', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/feature.html',
        '<img src="AI_IMAGE:a square ceramic tile|landscape feature context|photorealistic|2:1"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    // 2:1 is not a direct Gemini shape; it maps to the nearest supported ratio.
    assert_eq('16:9', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images decodes serializer-equivalent cover values into one canonical image', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:coffee \u0026 croissant at dawn|ratio:21:9|role:hero"} -->'
        . '<img class="wp-block-cover__image-background" alt="" '
        . 'src="AI_IMAGE:coffee &amp; croissant at dawn|ratio:21:9|role:hero"/>'
        . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    // JSON \u0026 and HTML &amp; represent the same prompt — one image/path.
    assert_eq(1, count($images));
    assert_eq('21:9', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/hero.html');
    assert_eq(2, substr_count($markup, $images[0]['src']));
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'both malformed source contexts normalized');

    // Once canonicalized, the normal content-image manifest path sees it.
    assert_eq([[
        'filename' => $images[0]['filename'],
        'title' => 'coffee & croissant at dawn',
    ]], AssemblePagesStep::contentImages(['home' => $markup], $images));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers a bare img src placeholder with no ratio', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/band.html',
        '<img src="AI_IMAGE:roasted coffee beans on a wooden table"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_contains('roasted coffee beans', $images[0]['subject']);
    // Defaults to landscape when no ratio is named.
    assert_eq('landscape', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/band.html');
    assert_contains($images[0]['src'], $markup);
    assert_true(!str_contains($markup, 'src="AI_IMAGE:'), 'bare src normalized');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers a comment-wrapped JSON-object src placeholder', function () {
    [$project, $tmp] = collect_fixture();
    // The model sometimes emits the object form of the spec, wrapped in an HTML
    // comment inside src. Nothing in the recovery net matched this, so the
    // placeholder shipped as-is: no image generated, a raw comment left as the
    // source. Recover the prompt as the subject and the aspect from the
    // dimensions (900x1100 maps to the now-supported, closer 4:5 canvas).
    $project->writeText('theme/parts/origins.html',
        '<img src="<!-- AI_IMAGE: {&quot;prompt&quot;:&quot;coffee beans in a shallow metal tray&quot;,'
        . '&quot;alt&quot;:&quot;Granos de cafe en bandeja metalica&quot;,'
        . '&quot;width&quot;:900,&quot;height&quot;:1100} -->" alt="Granos de cafe en bandeja metalica"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_contains('coffee beans in a shallow metal tray', $images[0]['subject']);
    assert_eq('4:5', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/origins.html');
    assert_contains('src="' . $images[0]['src'] . '"', $markup);
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'comment-wrapped src normalized');
    assert_true(!str_contains($markup, '<!--'), 'wrapping comment dropped');
    assert_eq([], \Automattic\SiteBuild\ThemeValidator::unresolvedImageSourceProblems($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers a comment-wrapped spec in a cover url field', function () {
    [$project, $tmp] = collect_fixture();
    // Same wrapper creativity as the src= shape above, but landing in the
    // cover's JSON "url" field. The syntax patterns capture whole source
    // values, so a wrapper proven in one syntax is covered in all of them
    // without a new rule.
    $project->writeText('theme/parts/band.html',
        '<!-- wp:cover {"url":"<!-- AI_IMAGE: misty harbor at dawn | full-bleed band | photorealistic | landscape -->"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_contains('misty harbor at dawn', $images[0]['subject']);
    assert_eq('landscape', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/band.html');
    assert_contains('"url":"' . $images[0]['src'] . '"', $markup);
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'comment-wrapped url normalized');
    assert_eq([], \Automattic\SiteBuild\ThemeValidator::unresolvedImageSourceProblems($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers every source shape the validator flags', function () {
    [$project, $tmp] = collect_fixture();
    // Leading whitespace inside the quoted values, plus an unquoted src —
    // shapes ThemeValidator::unresolvedImageSourceProblems() detects, so
    // recovery must repair them too or an images build dies at the
    // generate-images gate after paying for every other image.
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":" AI_IMAGE:dense fog over the dunes|ratio:21:9"} -->'
        . '<div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" alt="" src=" AI_IMAGE:dense fog over the dunes|ratio:21:9"/>'
        . '</div><!-- /wp:cover -->'
    );
    $project->writeText('theme/parts/band.html', '<img src=AI_IMAGE:beans alt=""/>');

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images));
    $bySubject = array_column($images, null, 'subject');
    $hero = $project->readText('theme/parts/hero.html');
    // The whitespace-padded url and src decode to one prompt and one path.
    assert_eq(2, substr_count($hero, $bySubject['dense fog over the dunes']['src']));
    $band = $project->readText('theme/parts/band.html');
    // The unquoted src gains the quotes the model omitted.
    assert_contains('src="' . $bySubject['beans']['src'] . '"', $band);
    foreach (['hero', 'band'] as $part) {
        assert_true(
            !str_contains($project->readText("theme/parts/{$part}.html"), 'AI_IMAGE:'),
            "{$part} fully normalized"
        );
    }
    assert_eq([], \Automattic\SiteBuild\ThemeValidator::unresolvedImageSourceProblems($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images points a recovered cover url at its canonical inner img asset', function () {
    [$project, $tmp] = collect_fixture();
    // Half-canonical cover: the url carries the raw prompt while the inner img
    // already follows the documented form. The url must adopt the img's asset
    // instead of synthesizing a second image for the same background.
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:fog over dunes at dawn|ratio:16:9"} -->'
        . '<div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: fog over dunes at dawn | full-bleed hero | photorealistic | landscape"/>'
        . '</div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero.jpg', $images[0]['filename']);
    // The canonical spec's rich fields win over the recovery fallback.
    assert_eq('full-bleed hero', $images[0]['pageContext']);
    $markup = $project->readText('theme/parts/hero.html');
    assert_contains('"url":"theme:./assets/hero.jpg"', $markup);
    assert_eq(2, substr_count($markup, 'theme:./assets/hero.jpg'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images ignores plain images with no AI_IMAGE marker', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/plain-image.html',
        '<img src="theme:./assets/x.jpg" alt="just a normal alt"/>'
    );

    (new CollectImagesStep())->run($project);

    assert_eq([], $project->readJson('images.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps subject pipes and parses the three trailing fields', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--combo.html',
        '<img src="theme:./assets/combo.jpg" alt="AI_IMAGE: Coffee | tea | pastries on a table | menu item card | flat-design | square"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq('square', $images[0]['aspectRatio']);
    assert_eq('flat-design', $images[0]['style']);
    assert_eq('menu item card', $images[0]['pageContext']);
    // Only the three trailing fields are popped; the subject keeps its pipes.
    assert_eq('Coffee | tea | pastries on a table', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images collects an assigned short AI_IMAGE alt from a theme part', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-contact--details.html',
        '<img src="theme:./assets/plate-iv-abcd1234.jpg" alt="AI_IMAGE: a studio desk"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('plate-iv-abcd1234.jpg', $images[0]['filename']);
    assert_eq('a studio desk', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images renames a cross-part filename collision with a different subject (BIGR-793)', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--about.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: An aged stone doorway at dusk, door slightly ajar | about band photo | photorealistic | landscape"/></figure><!-- /wp:image -->'
    );
    $project->writeText('theme/parts/page-home--visit.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: A weathered wooden door with iron studs in a plaster wall | location card photo | photorealistic | card-portrait"/></figure><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images), 'the collision yields two independent specs');
    $byName = array_column($images, null, 'filename');
    assert_true(isset($byName['street-doorway.jpg']), 'first claimant keeps the name');
    $variant = null;
    foreach ($images as $img) {
        if ($img['filename'] !== 'street-doorway.jpg') {
            $variant = $img;
        }
    }
    assert_true($variant !== null && str_starts_with($variant['filename'], 'street-doorway-'), 'newcomer renamed to a variant');
    assert_contains('iron studs', $variant['subject']);
    // The later part's markup follows its renamed asset.
    $visit = $project->readText('theme/parts/page-home--visit.html');
    assert_contains($variant['filename'], $visit);
    assert_true(!str_contains($visit, '"theme:./assets/street-doorway.jpg"'), 'old reference rewritten');
    $warnings = $project->readJson('warnings.json')['collect-images'] ?? [];
    $joined = implode(' ', $warnings);
    foreach ([
        "file='theme/parts/page-home--visit.html'",
        'block=',
        'authored asset="street-doorway.jpg"',
        'delivered asset=',
        'disposition=',
    ] as $context) {
        assert_contains($context, $joined);
    }

    // Deterministic and fixed-point safe.
    assert_eq($variant['filename'], CollectImagesStep::variantFilename('street-doorway.jpg', $variant['subject']));
    $firstMarkup = $visit;
    (new CollectImagesStep())->run($project);
    assert_eq($firstMarkup, $project->readText('theme/parts/page-home--visit.html'));
    assert_eq(array_column($images, 'filename'), array_column($project->readJson('images.json'), 'filename'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps canonical fields for an assigned four-field AI_IMAGE alt', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--hero.html',
        '<img src="theme:./assets/canonical-hero.jpg" '
        . 'alt="AI_IMAGE: Dawn over a quiet valley | full-bleed homepage hero | Photorealistic | Landscape"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('Dawn over a quiet valley', $images[0]['subject']);
    assert_eq('full-bleed homepage hero', $images[0]['pageContext']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('landscape', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images isolates different subjects within one part and preserves bare siblings', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--gallery.html',
        '<img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: An aged stone doorway | concept image | photorealistic | landscape"/>'
        . '<img src="theme:./assets/street-doorway.jpg" alt="A reused reference with no placeholder"/>'
        . '<img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: A painted steel doorway | location image | photorealistic | portrait"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images), 'different subjects in one part receive independent specs');
    $variant = $images[1]['filename'];
    $markup = $project->readText('theme/parts/page-home--gallery.html');
    assert_eq(2, substr_count($markup, 'theme:./assets/street-doorway.jpg'), 'first placeholder and bare sibling stay put');
    assert_eq(1, substr_count($markup, 'theme:./assets/' . $variant), 'only the colliding placeholder is renamed');

    (new CollectImagesStep())->run($project);
    assert_eq($markup, $project->readText('theme/parts/page-home--gallery.html'), 'scoped rename reaches a fixed point');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images records one source when canonical and assigned parsing find the same image', function () {
    [$project, $tmp] = collect_fixture();
    $source = 'parts/page-home--hero.html';
    $project->writeText('theme/' . $source,
        '<img src="theme:./assets/shared-hero.jpg" '
        . 'alt="AI_IMAGE: Dawn over a quiet valley | full-bleed homepage hero | photorealistic | square"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq([$source], $images[0]['sources']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images never overwrites an occupied variant filename', function () {
    [$project, $tmp] = collect_fixture();
    $newSubject = 'A painted steel doorway';
    $occupied = CollectImagesStep::variantFilename('street-doorway.jpg', $newSubject);
    $project->writeText('theme/parts/a-original.html',
        '<img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: An aged stone doorway | concept | photorealistic | landscape"/>'
    );
    $project->writeText('theme/parts/b-occupied.html',
        '<img src="theme:./assets/' . $occupied . '" '
        . 'alt="AI_IMAGE: A carved timber gate | archive | photorealistic | landscape"/>'
    );
    $project->writeText('theme/parts/c-new.html',
        '<img src="theme:./assets/street-doorway.jpg" '
        . 'alt="AI_IMAGE: ' . $newSubject . ' | location | photorealistic | portrait"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(3, count($images), 'every subject survives the occupied variant');
    $bySubject = array_column($images, 'filename', 'subject');
    assert_eq($occupied, $bySubject['A carved timber gate'], 'the existing variant claimant is untouched');
    assert_true($bySubject[$newSubject] !== $occupied, 'the newcomer receives another deterministic name');
    assert_contains($bySubject[$newSubject], $project->readText('theme/parts/c-new.html'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images upgrades an earlier assigned image when a later file is canonical', function () {
    [$project, $tmp] = collect_fixture();
    $earlySource = 'parts/page-a--lead.html';
    $laterSource = 'parts/page-z--hero.html';
    $project->writeText('theme/' . $earlySource,
        '<img src="theme:./assets/shared-scene.jpg" alt="AI_IMAGE: generic fallback scene"/>'
    );
    $project->writeText('theme/' . $laterSource,
        '<img src="theme:./assets/shared-scene.jpg" '
        . 'alt="AI_IMAGE: Sunrise over a mountain lake | homepage hero backdrop | cinematic | 21:9"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('Sunrise over a mountain lake', $images[0]['subject']);
    assert_eq('homepage hero backdrop', $images[0]['pageContext']);
    assert_eq('cinematic', $images[0]['style']);
    assert_eq('21:9', $images[0]['aspectRatio']);
    assert_eq([$earlySource, $laterSource], $images[0]['sources']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images retains and warns when a colliding recovered placeholder cannot be isolated', function () {
    [$project, $tmp] = collect_fixture();
    $rawRecovered = '<img src="AI_IMAGE:A painted steel doorway|ratio:portrait|role:location" alt=""/>';
    $recoveredSpec = CollectImagesStep::parsePlaceholders($rawRecovered)[0];
    $project->writeText('theme/parts/a-first.html',
        '<img src="' . $recoveredSpec['src'] . '" '
        . 'alt="AI_IMAGE: An aged stone doorway | concept | photorealistic | landscape"/>'
    );
    $project->writeText('theme/parts/b-recovered.html', $rawRecovered);

    (new CollectImagesStep())->run($project);

    assert_eq(1, count($project->readJson('images.json')), 'the existing manifest row is preserved');
    $markup = $project->readText('theme/parts/b-recovered.html');
    assert_contains($recoveredSpec['src'], $markup, 'the unsafe collision degrades to the existing image');
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'raw source prompt still normalizes safely');
    $warnings = implode(' ', $project->readJson('warnings.json')['collect-images'] ?? []);
    assert_contains("file='theme/parts/b-recovered.html'", $warnings);
    assert_contains('block=', $warnings);
    assert_contains('authored asset=', $warnings);
    assert_contains('delivered asset=', $warnings);
    assert_contains('retained because', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps plain prose assigned image subjects unchanged', function () {
    [$project, $tmp] = collect_fixture();
    $subject = 'Studio flat lay with sketchbooks, pencils, and warm window light';
    $project->writeText('theme/parts/page-work--gallery.html',
        '<img src="theme:./assets/studio-flat-lay.jpg" alt="' . $subject . '"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq($subject, $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images still merges a genuinely shared same-subject asset', function () {
    [$project, $tmp] = collect_fixture();
    $tag = '<img src="theme:./assets/logo-mark.jpg" alt="AI_IMAGE: A clean ceramic mark | site logo | minimalist | square"/>';
    $project->writeText('theme/parts/header.html', $tag);
    $project->writeText('theme/parts/footer.html', $tag);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq(2, count($images[0]['sources']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images removes an image whose asset no placeholder declares (BIGR-787)', function () {
    [$project, $tmp] = collect_fixture();
    // The mangled-marker shape observed in the wild: a well-formed theme: src
    // whose alt is not a parseable AI_IMAGE spec — nothing will generate it.
    $project->writeText('theme/parts/page-home--visit.html',
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/hero-dawn.jpg" '
        . 'alt="AI_IMAGE: A misty valley at dawn | wide feature | photorealistic | landscape"/></figure><!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/bakery-storefront.jpg" '
        . 'alt="AI_IMATE_PLACEHOLDER"/></figure><!-- /wp:image -->'
        . '<!-- wp:paragraph --><p>Copy that stays.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-dawn.jpg', $images[0]['filename']);

    $markup = $project->readText('theme/parts/page-home--visit.html');
    assert_true(!str_contains($markup, 'bakery-storefront'), 'undeclared asset reference removed');
    assert_true(!str_contains($markup, 'AI_IMATE_PLACEHOLDER'), 'mangled alt never ships');
    assert_contains('hero-dawn.jpg', $markup);
    assert_contains('Copy that stays.', $markup);

    $warnings = $project->readJson('warnings.json');
    $joined = implode(' ', $warnings['collect-images'] ?? []);
    assert_contains('bakery-storefront.jpg', $joined);
    assert_contains('delivered removed', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images lets canonical fields win when assigned parsing finds the same filename', function () {
    [$project, $tmp] = collect_fixture();
    $tag = '<img src="theme:./assets/canonical-wins.jpg" '
        . 'alt="AI_IMAGE: Coffee | tea | pastries on a table | menu item card | flat-design | square"/>';
    $project->writeText('theme/parts/page-home--menu.html', $tag);

    $assigned = CollectImagesStep::parseAssignedImages($tag, 'parts/page-home--menu.html');
    assert_eq(1, count($assigned));
    assert_eq('Coffee', $assigned[0]['subject']);

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('canonical-wins.jpg', $images[0]['filename']);
    assert_eq('Coffee | tea | pastries on a table', $images[0]['subject']);
    assert_eq('menu item card', $images[0]['pageContext']);
    assert_eq('flat-design', $images[0]['style']);
    assert_eq('square', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps a cover but strips its undeclared image layer', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--closing.html',
        '<!-- wp:cover {"url":"theme:./assets/ghost-band.jpg","dimRatio":50} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" '
        . 'src="theme:./assets/ghost-band.jpg" alt="AI IMAGE broken"/>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Retained headline</h2><!-- /wp:heading -->'
        . '</div></div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $markup = $project->readText('theme/parts/page-home--closing.html');
    assert_true(!str_contains($markup, 'ghost-band.jpg'), 'undeclared cover asset gone');
    assert_contains('Retained headline', $markup);
    assert_contains('wp:cover', $markup);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images leaves declared cross-part references alone', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/header.html',
        '<img src="theme:./assets/logo.jpg" alt="AI_IMAGE: A clean mark | site logo | minimalist | square"/>'
    );
    // A bare rendered reference to an asset another part declares is legal.
    $project->writeText('theme/parts/footer.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/logo.jpg" alt=""/></figure><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    assert_contains('logo.jpg', $project->readText('theme/parts/footer.html'));
    assert_true(!$project->exists('warnings.json'), 'no warning for a declared cross-part reference');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images takes an orphaned caption with the undeclared image', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--visit.html',
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/ghost.jpg" '
        . 'alt="AI_IMATE_PLACEHOLDER"/></figure><!-- /wp:image -->'
        . "\n\n"
        . '<!-- wp:paragraph {"fontSize": "caption"} -->'
        . '<p>The corner shopfront, mid-morning.</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Ordinary prose stays.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
    );

    (new CollectImagesStep())->run($project);

    $markup = $project->readText('theme/parts/page-home--visit.html');
    assert_true(!str_contains($markup, 'ghost.jpg'), 'undeclared image removed');
    assert_true(!str_contains($markup, 'corner shopfront'), 'caption removed with its image');
    assert_contains('Ordinary prose stays.', $markup);

    $warnings = implode(' ', $project->readJson('warnings.json')['collect-images'] ?? []);
    assert_contains('authored caption="The corner shopfront, mid-morning."', $warnings);
    assert_contains('delivered removed', $warnings);
    assert_contains('orphaned description', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images preserves an undeclared source inside an unclosed image block', function () {
    [$project, $tmp] = collect_fixture();
    $original = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/unsafe.jpg" alt="AI_IMATE_PLACEHOLDER">';
    $project->writeText('theme/parts/page-home--unsafe.html', $original);

    (new CollectImagesStep())->run($project);

    assert_eq($original, $project->readText('theme/parts/page-home--unsafe.html'));
    $warnings = implode(' ', $project->readJson('warnings.json')['collect-images'] ?? []);
    assert_contains('unsafe.jpg', $warnings);
    assert_contains('delivered retained in unsafe markup', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images removes an undeclared CSS-only cover image without losing its copy', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText(
        'theme/parts/page-home--band.html',
        '<!-- wp:cover --><div class="wp-block-cover" '
            . 'style="background-image:url(theme:./assets/ghost-css.jpg);color:white">'
            . '<!-- wp:heading --><h2>Copy survives</h2><!-- /wp:heading -->'
            . '</div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $markup = $project->readText('theme/parts/page-home--band.html');
    assert_true(!str_contains($markup, 'ghost-css.jpg'), 'CSS-only source removed');
    assert_contains('color:white', $markup);
    assert_contains('Copy survives', $markup);
    $warnings = implode(' ', $project->readJson('warnings.json')['collect-images'] ?? []);
    assert_contains('delivered removed', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('unresolvable source scan ignores path-shaped prose with no media reference', function () {
    assert_eq(
        [],
        CollectImagesStep::unresolvableSources(
            '<!-- wp:paragraph --><p>Diagnostic: theme:./assets/missing.jpg</p><!-- /wp:paragraph -->',
            []
        )
    );
});

test('collect-images appends a site-logo spec for a business site', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name'         => 'Hearth & Crumb',
        'site_type'    => 'business storefront',
        'area'         => 'bakery',
        'topic'        => 'artisan bread',
        'visual_vibe'  => 'warm and rustic',
        'persona_name' => '',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery in Portland']);
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:image --><img src="theme:./assets/hero.jpg" alt="AI_IMAGE: loaves | hero | photo | landscape"><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    $byName = [];
    foreach ($project->readJson('images.json') as $row) {
        $byName[$row['filename']] = $row;
    }
    assert_true(isset($byName['hero.jpg']));
    $logo = $byName['site-logo.png'];
    assert_eq('theme:./assets/site-logo.png', $logo['src']);
    assert_eq('square', $logo['aspectRatio']);
    assert_eq('flat', $logo['style']);
    assert_eq('pending', $logo['status']);
    assert_eq('site-logo', $logo['role']);
    assert_eq([], $logo['sources']);
    assert_contains('bakery', $logo['subject']);
    assert_contains('no letters', $logo['subject']);
    assert_true(!str_contains($logo['subject'], 'Hearth'), 'site name stays out of the subject');
    assert_contains('warm and rustic', $logo['subject']);
    assert_eq('site logo and site icon, small square mark in the header', $logo['pageContext']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images appends a site-logo for every non-personal site, whatever its kind', function () {
    foreach ([
        ['name' => 'Stillrange', 'site_type' => 'photography portfolio', 'area' => 'landscape photography', 'persona_name' => ''],
        ['name' => 'Northlight', 'site_type' => 'art gallery', 'area' => 'contemporary painting', 'persona_name' => ''],
        ['name' => 'Riverbank Trust', 'site_type' => 'nonprofit', 'area' => 'river conservation', 'persona_name' => ''],
        ['name' => 'Ledger', 'site_type' => 'product landing page', 'area' => 'bookkeeping software'],
    ] as $spec) {
        [$project, $tmp] = collect_fixture();
        $project->writeJson('siteSpec.json', $spec);
        $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

        (new CollectImagesStep())->run($project);

        $roles = array_column($project->readJson('images.json'), 'role', 'filename');
        assert_eq('site-logo', $roles['site-logo.png'] ?? null, $spec['name'] . ' gets a mark');
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('collect-images does not append a site-logo for a personal site', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name' => 'Ada', 'persona_name' => 'Ada Lovelace',
        'site_type' => 'portfolio', 'area' => 'studio',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'My paintings']);
    $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

    (new CollectImagesStep())->run($project);

    foreach ($project->readJson('images.json') as $row) {
        assert_true(($row['filename'] ?? '') !== 'site-logo.png');
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images logo subject drops identity-bearing area, topic, and vibe', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'site_type'   => 'business storefront',
        'area'        => "Hearth & Crumb's sourdough programme",
        'topic'       => "Hearth & Crumb bakery",
        'visual_vibe' => 'Hearth & Crumb rustic',
        'persona_name'=> '',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
    $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

    (new CollectImagesStep())->run($project);

    $logo = null;
    foreach ($project->readJson('images.json') as $row) {
        if (($row['filename'] ?? '') === 'site-logo.png') {
            $logo = $row;
        }
    }
    assert_true(is_array($logo));
    assert_contains('a business storefront', $logo['subject'], 'the safe site_type is the fallback subject');
    assert_true(!str_contains($logo['subject'], 'Hearth'), 'identity-bearing fields must not steer the mark');
    assert_true(!str_contains($logo['subject'], 'Crumb'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images skips the synthetic mark when site-logo.png was already collected', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb', 'site_type' => 'business storefront',
        'area' => 'bakery', 'persona_name' => '',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'bakery']);
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<img src="theme:./assets/site-logo.png" alt="AI_IMAGE: a sourdough loaf | hero | photo | square">'
    );

    (new CollectImagesStep())->run($project);

    $rows = $project->readJson('images.json');
    assert_eq(1, count($rows));
    assert_eq('site-logo.png', $rows[0]['filename']);
    assert_true(($rows[0]['role'] ?? null) !== 'site-logo');
    $warnings = implode("\n", $project->readJson('warnings.json')['collect-images']);
    assert_contains("file='images.json'", $warnings);
    assert_contains("asset='site-logo.png'", $warnings);
    assert_contains('the reserved site-logo filename was already collected from page markup', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});
