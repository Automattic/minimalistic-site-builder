<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Units\GeneratedMarkup;

function collect_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_ci_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    return [$project, $tmp];
}

function collect_stage_markup(string $surface = 'base'): string
{
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    return '<!-- wp:group {"className":"has-stage-texture-backdrop","backgroundColor":"' . $surface
        . '","style":{"background":{"backgroundImage":{"url":"' . $source
        . '"},"backgroundPosition":"0% 0%","backgroundSize":"420px","backgroundRepeat":"repeat",'
        . '"backgroundAttachment":"fixed"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-stage-texture-backdrop has-' . $surface
        . '-background-color has-background" style="background-image:url(' . $source
        . ');background-position:0% 0%;background-size:420px;background-repeat:repeat;'
        . 'background-attachment:fixed"><!-- wp:site-title /--></div><!-- /wp:group -->';
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

test('collect-images synthesizes the code-owned stage-texture spec from style references (BIGR-776)', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('theme/theme.json', [
        'settings' => ['color' => ['palette' => [
            ['slug' => 'base', 'color' => '#F4EFE7'],
            ['slug' => 'contrast', 'color' => '#211D19'],
        ]]],
    ]);
    $styled = collect_stage_markup();
    $project->writeText('theme/parts/header.html', $styled);
    $project->writeText('theme/parts/page-home--hero.html', $styled);
    $project->writeText('theme/parts/footer.html', '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->');

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('stage_backdrop-texture.jpg', $images[0]['filename']);
    assert_eq('theme:./assets/stage_backdrop-texture.jpg', $images[0]['src']);
    assert_eq('square', $images[0]['aspectRatio']);
    assert_eq('pending', $images[0]['status']);
    assert_eq(CollectImagesStep::STAGE_TEXTURE_PURPOSE, $images[0]['purpose']);
    assert_eq('#F4EFE7', $images[0]['targetColor']);
    assert_contains('seamless', $images[0]['subject']);
    assert_contains('no lettering', $images[0]['subject']);
    assert_contains('#F4EFE7', $images[0]['subject']);
    assert_eq(['parts/header.html', 'parts/page-home--hero.html'], $images[0]['sources']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps ordinary similarly named media independent from the code-owned stage tile (BIGR-776)', function () {
    [$project, $tmp] = collect_fixture();
    $styled = collect_stage_markup();
    $collision = '<!-- wp:media-text {"mediaUrl":"theme:./assets/stage_backdrop-texture.jpg",'
        . '"mediaType":"image"} --><div class="wp-block-media-text"><figure><img '
        . 'src="theme:./assets/stage_backdrop-texture.jpg" '
        . 'alt="AI_IMAGE: A high-contrast portrait with lettering | gallery card | photorealistic | portrait"/>'
        . '</figure><div class="wp-block-media-text__content"></div></div><!-- /wp:media-text -->';
    $project->writeText('theme/parts/header.html', $styled);
    $project->writeText('theme/parts/content.html', $collision);

    $project->writeText('theme/parts/page-home--hero.html', $styled);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
    ]]]]);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images));
    $ordinary = array_values(array_filter(
        $images,
        static fn (array $image): bool => ($image['purpose'] ?? '') !== CollectImagesStep::STAGE_TEXTURE_PURPOSE,
    ));
    $stage = array_values(array_filter(
        $images,
        static fn (array $image): bool => ($image['purpose'] ?? '') === CollectImagesStep::STAGE_TEXTURE_PURPOSE,
    ));
    assert_eq(1, count($ordinary));
    assert_contains('portrait with lettering', $ordinary[0]['subject']);
    assert_true($ordinary[0]['filename'] !== 'stage_backdrop-texture.jpg');
    assert_eq(['parts/content.html'], $ordinary[0]['sources']);
    assert_eq(1, count($stage));
    assert_eq('stage_backdrop-texture.jpg', $stage[0]['filename']);
    assert_contains('extremely low contrast', $stage[0]['subject']);
    assert_eq(['parts/header.html', 'parts/page-home--hero.html'], $stage[0]['sources']);
    $content = $project->readText('theme/parts/content.html');
    assert_contains($ordinary[0]['src'], $content, 'ordinary reserved-name media is deterministically renamed');
    assert_eq(2, substr_count($content, $ordinary[0]['src']), 'mediaUrl and saved img stay synchronized');
    assert_true(!str_contains($content, GeneratedMarkup::STAGE_TEXTURE_ASSET));
    assert_true(!$project->exists('warnings.json'), 'a semantics-preserving independent filename needs no warning');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images renames only the innermost media owner of a reserved placeholder (BIGR-776)', function () {
    [$project, $tmp] = collect_fixture();
    $reserved = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $nested = '<!-- wp:cover {"url":"' . $reserved . '","dimRatio":20} -->'
        . '<div class="wp-block-cover outer-sentinel">'
        . '<!-- wp:media-text {"mediaUrl":"' . $reserved . '","mediaType":"image"} -->'
        . '<div class="wp-block-media-text inner-sentinel"><figure><img src="' . $reserved . '" '
        . 'alt="AI_IMAGE: A hand-thrown ceramic portrait | feature row | photorealistic | portrait"/>'
        . '</figure><div class="wp-block-media-text__content">'
        . '<!-- wp:paragraph --><p>Keep nested copy.</p><!-- /wp:paragraph -->'
        . '</div></div><!-- /wp:media-text -->'
        . '</div><!-- /wp:cover -->';
    $project->writeText('theme/parts/content.html', $nested);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    $replacement = $images[0]['src'];
    assert_true($replacement !== $reserved);
    $content = $project->readText('theme/parts/content.html');
    assert_contains(
        '<!-- wp:cover {"url":"' . $reserved . '","dimRatio":20} -->',
        $content,
        'a descendant placeholder cannot claim its ancestor Cover background',
    );
    assert_contains('"mediaUrl":"' . $replacement . '"', $content);
    assert_contains('src="' . $replacement . '"', $content);
    assert_eq(2, substr_count($content, $replacement), 'inner comment attrs and saved img stay aligned');
    assert_eq(1, substr_count($content, $reserved), 'only the unrelated outer source remains reserved');
    assert_contains('outer-sentinel', $content);
    assert_contains('Keep nested copy.', $content);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images atomically renames a reserved placeholder across a crossed closer (BIGR-776)', function () {
    [$project, $tmp] = collect_fixture();
    $reserved = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $crossed = '<!-- wp:media-text {"mediaUrl":"' . $reserved . '","mediaType":"image"} -->'
        . '<div class="wp-block-media-text unsafe-sentinel"><figure><img src="' . $reserved . '" '
        . 'alt="AI_IMAGE: A hand-carved timber portrait | feature row | photorealistic | portrait"/>'
        . '</figure><div class="wp-block-media-text__content">Keep unsafe copy.</div></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:paragraph --><p>Keep following sibling.</p><!-- /wp:paragraph -->';
    $project->writeText('theme/parts/content.html', $crossed);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    $replacement = $images[0]['src'];
    $delivered = $project->readText('theme/parts/content.html');
    assert_true($replacement !== $reserved);
    assert_eq(2, substr_count($delivered, $replacement), 'unsafe opener attrs and exact img stay aligned');
    assert_true(!str_contains($delivered, $reserved));
    assert_contains('<!-- /wp:cover -->', $delivered, 'the crossed closer is retained byte-for-byte');
    assert_contains('Keep unsafe copy.', $delivered);
    assert_contains(
        '<!-- wp:paragraph --><p>Keep following sibling.</p><!-- /wp:paragraph -->',
        $delivered,
        'a sibling outside the isolated edit remains byte-for-byte intact',
    );
    assert_true(!CollectImagesStep::containsReservedOrdinaryAiPlaceholder($delivered));
    assert_true(!$project->exists('warnings.json'), 'a complete local repair needs no warning');

    $beforeImages = $project->readJson('images.json');
    (new CollectImagesStep())->run($project);
    assert_eq($delivered, $project->readText('theme/parts/content.html'), 'repair reaches a markup fixed point');
    assert_eq($beforeImages, $project->readJson('images.json'), 'repair reaches an artifact fixed point');
    assert_true(!$project->exists('warnings.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images retains and reports an unsynchronizable reserved media owner (BIGR-776)', function () {
    [$project, $tmp] = collect_fixture();
    $reserved = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $stage = collect_stage_markup();
    $unsynchronizable = '<!-- wp:image {"id":7} -->'
        . '<figure class="wp-block-image"><img src="' . $reserved . '" '
        . 'alt="AI_IMAGE: A reserved-name portrait | card | photorealistic | portrait"/></figure>'
        . '<!-- /wp:image -->';
    $project->writeText('theme/parts/header.html', $stage);
    $project->writeText('theme/parts/content.html', $unsynchronizable);

    assert_true(CollectImagesStep::containsReservedOrdinaryAiPlaceholder($unsynchronizable));
    (new CollectImagesStep())->run($project);

    $delivered = $project->readText('theme/parts/content.html');
    assert_eq($unsynchronizable, $delivered, 'the img is retained when its parsed owner cannot be synchronized');
    assert_true(CollectImagesStep::containsReservedOrdinaryAiPlaceholder($delivered));
    $images = $project->readJson('images.json');
    $stageSpecs = array_values(array_filter(
        $images,
        static fn (array $image): bool => ($image['purpose'] ?? '') === CollectImagesStep::STAGE_TEXTURE_PURPOSE,
    ));
    assert_eq([], $stageSpecs, 'a residual ordinary placeholder suppresses the code-owned stage mapping');
    assert_eq([], $images, 'an entirely unrewritten replacement cannot trigger an unused image call');
    $warning = implode("\n", $project->readJson('warnings.json')['collect-images'] ?? []);
    assert_contains("file=\"theme/parts/content.html\"", $warning);
    assert_contains('block=', $warning);
    assert_contains('authored source="theme:./assets/stage_backdrop-texture.jpg"', $warning);
    assert_contains('delivered source="theme:./assets/stage_backdrop-texture.jpg"', $warning);
    assert_contains('disposition=', $warning);
    assert_contains('stage texture mapping suppressed', $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});
