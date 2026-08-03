<?php
declare(strict_types=1);

use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\FooterMarkup;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Units\HeaderUnit;

/** @return array<string,mixed> */
function template_part_unit_input(): array
{
    return [
        'site_spec'        => '{"name":"PART-SPEC-SENTINEL"}',
        'language'         => 'part-language-sentinel',
        'theme_json'       => '{"part-theme-sentinel":true}',
        'design_direction' => 'PART-DIRECTION-SENTINEL',
        'outline'          => '1. PART-OUTLINE-SENTINEL (hero)',
        'site_pages'       => '- "Home" — / (front page): PART-PAGES-SENTINEL',
        'final_section_brief' => 'PART-FINAL-SECTION-SENTINEL',
        'composition_archetype' => 'photographic-split',
        'page_count' => 1,
    ];
}

test('HeaderUnit generates a constrained header from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"tagName":"header","align":"full"} -->'
        . '<header class="wp-block-group"><!-- wp:site-title /--></header><!-- /wp:group -->'
    );
    $unit = new HeaderUnit($llm, new PromptRenderer(repo_path('prompts')));

    $markup = $unit->generate(array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule'   => '- PART-NAV-SENTINEL',
        'archetype_assignment' => 'PART-ARCHETYPE-SENTINEL',
    ]));

    $prompt = $llm->calls[0]['prompt'];
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-HERO-SENTINEL', 'PART-PAGES-SENTINEL', 'PART-NAV-SENTINEL', 'PART-ARCHETYPE-SENTINEL'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_eq('header', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_contains('"layout":{"type":"constrained"}', $markup);
    assert_contains('"align":"full"', $markup);
    assert_true(!str_contains($markup, '"tagName":"header"'), 'template part owns the header landmark');
    assert_true(!str_contains($markup, '<header'), 'literal root header is rewritten');
    assert_true(!str_contains($markup, '</header>'), 'literal root header closer is rewritten');
    assert_contains('<div class="wp-block-group', $markup);
});

test('FooterUnit generates a constrained footer from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">'
        . '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="nested">Nested</footer><!-- /wp:group -->'
        . '</footer><!-- /wp:group -->'
    );
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));

    $markup = $unit->generate(template_part_unit_input());

    $prompt = $llm->calls[0]['prompt'];
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-PAGES-SENTINEL', 'PART-FINAL-SECTION-SENTINEL', 'photographic-split'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_contains('This site is ONE page: NEVER use `wp:page-list`', $prompt);
    assert_contains('AI_IMAGE: subject | page-context | style | aspect-ratio', $prompt);
    assert_eq('footer', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_true(!str_contains($markup, '"tagName":"footer"'), 'template part owns the footer landmark');
    assert_true(!str_contains($markup, '<footer'), 'literal root footer is rewritten');
    assert_true(!str_contains($markup, '</footer>'), 'literal root footer closer is rewritten');
    assert_contains('<div class="wp-block-group', $markup);
    assert_contains('<div class="nested">Nested</div>', $markup);
    assert_contains('"backgroundColor":"contrast"', $markup);
    assert_contains('has-contrast-background-color', $markup);
    assert_contains('"layout":{"type":"constrained"}', $markup);
});

test('FooterUnit renders exactly one reviewed recipe and image instructions only when needed', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $markers = [
        'typographic-billboard' => 'ONE viewport-filling brand line',
        'photographic-split' => 'deliberately unequal 60/40 or 65/35',
        'image-plinth' => 'Treat ONE foreground wp:image as the focal object',
        'conversion-panel' => 'Build a bold, offset invitation',
        'editorial-colophon' => 'final plate of a book or',
        'split-ledger' => 'Build a strong 65/35 or 70/30 split',
    ];

    assert_eq(FooterComposition::ARCHETYPES, array_keys($markers));
    foreach ($markers as $archetype => $marker) {
        $prompt = (new FooterUnit(new FakeLlm(), $renderer))->request(array_merge(
            template_part_unit_input(),
            ['composition_archetype' => $archetype]
        ))['prompt'];

        assert_contains("ASSIGNED FOOTER COMPOSITION for this build: **{$archetype}**", $prompt);
        assert_contains(
            'ASSIGNED FOOTER SURFACE: **' . FooterComposition::surface($archetype) . '**',
            $prompt
        );
        assert_contains($marker, $prompt);
        foreach ($markers as $otherArchetype => $otherMarker) {
            if ($otherArchetype !== $archetype) {
                assert_true(
                    !str_contains($prompt, $otherMarker),
                    "{$otherArchetype} recipe is absent when {$archetype} is assigned"
                );
            }
        }
        assert_eq(
            FooterComposition::usesGeneratedImage($archetype),
            str_contains($prompt, 'AI_IMAGE: subject | page-context | style | aspect-ratio'),
            "{$archetype} receives the right image-instruction contract"
        );
        assert_eq(
            FooterComposition::usesGeneratedImage($archetype),
            str_contains($prompt, 'never `portrait`'),
            "{$archetype} image recipe forbids portrait footer images"
        );
        assert_contains(
            'FIT-TEXT IDENTITY LINE',
            $prompt,
            "{$archetype} receives the shared fit-text identity device"
        );
        assert_contains('"fitText":true', $prompt);
    }
});

test('FooterUnit caps portrait image placeholders to square in alt and mirrored block JSON', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"base"} --><div class="wp-block-group">'
        . '<!-- wp:image {"sizeSlug":"large","alt":"AI_IMAGE: A carpenter\'s clay pitcher | footer plinth | photorealistic | portrait"} -->'
        . '<figure class="wp-block-image size-large">'
        . '<img src="theme:./assets/footer-pitcher.jpg" alt="AI_IMAGE: A carpenter\'s clay pitcher | footer plinth | photorealistic | portrait"/>'
        . '</figure><!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/footer-room.jpg" alt="AI_IMAGE: A dining room | footer image field | photorealistic | landscape"/>'
        . '</figure><!-- /wp:image --></div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'image-plinth']);

    $notes = [];
    $once = $unit->finish($raw, $input, $notes);

    assert_true(!str_contains($once, 'portrait'), 'no portrait placeholder survives');
    assert_eq(2, substr_count($once, 'photorealistic | square"'), 'HTML alt and mirrored JSON alt are both capped');
    assert_contains('| photorealistic | landscape', $once);
    $joined = implode("\n", $notes);
    assert_contains('authored aspect-ratio=portrait', $joined);
    assert_contains('delivered=square', $joined);
    assert_contains('disposition=', $joined);
    assert_eq(2, count(array_filter($notes, fn ($n) => str_contains($n, 'AI_IMAGE placeholder'))), 'alt and mirrored JSON each record their rewrite');
    assert_eq($once, $unit->finish($once, $input), 'portrait capping reaches a fixed point');
});

test('portrait cap covers card-portrait and tall numeric ratios, leaves wide ones alone', function () {
    $markup = '<img src="theme:./assets/a.jpg" alt="AI_IMAGE: A potter | footer | photorealistic | card-portrait"/>'
        . '<img src="theme:./assets/b.jpg" alt="AI_IMAGE: A kiln | footer | photorealistic | 9:16"/>'
        . '<img src="theme:./assets/c.jpg" alt="AI_IMAGE: A studio | footer | photorealistic | 16:9"/>'
        . '<img src="theme:./assets/d.jpg" alt="AI_IMAGE: A wheel | footer | photorealistic | card-landscape"/>';

    $notes = [];
    $out = FooterMarkup::withoutPortraitImagePlaceholders($markup, $notes);

    assert_eq(2, substr_count($out, '| square"'), 'card-portrait and 9:16 are both capped');
    assert_contains('| 16:9', $out);
    assert_contains('| card-landscape', $out);
    $joined = implode("\n", $notes);
    assert_contains('authored aspect-ratio=card-portrait', $joined);
    assert_contains('authored aspect-ratio=9:16', $joined);
    assert_eq(2, count($notes), 'wide ratios record no rewrite');
    assert_eq($out, FooterMarkup::withoutPortraitImagePlaceholders($out), 'capping reaches a fixed point');
});

test('portrait cap covers recovered AI_IMAGE source forms before collection', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group --><div class="wp-block-group">'
        . '<img src="AI_IMAGE:A potter|ratio:card-portrait|role:footer" alt=""/>'
        . '<img src=\' AI_IMAGE:A kiln|role:footer|ratio:9:16\' alt=""/>'
        . '<!-- wp:cover {"url":"AI_IMAGE:A studio|ratio:3:4|role:footer"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
        . '<img src=AI_IMAGE:A-vase|ratio:portrait|role:footer alt=""/>'
        . '<img src="AI_IMAGE:A loom|footer context|photorealistic|portrait" alt=""/>'
        . '<img src="AI_IMAGE:A mural|portrait|photorealistic|square" alt=""/>'
        . '<img src="AI_IMAGE:A wheel|ratio:16:9|role:footer" alt=""/>'
        . '</div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'image-plinth']);

    $notes = [];
    $once = $unit->finish($raw, $input, $notes);

    assert_contains('AI_IMAGE:A potter|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A kiln|role:footer|ratio:square', $once);
    assert_contains('AI_IMAGE:A studio|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A-vase|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A loom|footer context|photorealistic|square', $once);
    assert_contains('AI_IMAGE:A mural|portrait|photorealistic|square', $once);
    assert_contains('AI_IMAGE:A wheel|ratio:16:9|role:footer', $once);
    $joined = implode("\n", $notes);
    foreach (['card-portrait', '9:16', '3:4', 'portrait'] as $authored) {
        assert_contains("authored aspect-ratio={$authored}", $joined);
    }
    assert_eq(5, count(array_filter(
        $notes,
        static fn (string $note): bool => str_contains($note, 'AI_IMAGE placeholder'),
    )), 'each recovered portrait source records one actionable rewrite');
    assert_eq($once, $unit->finish($once, $input), 'source-form capping reaches a fixed point');
});

test('FooterUnit rejects an unknown composition before generation', function () {
    $llm = new FakeLlm();
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));

    assert_throws(
        fn () => $unit->request(array_merge(
            template_part_unit_input(),
            ['composition_archetype' => 'generic-columns']
        )),
        'unknown footer archetype'
    );
    assert_eq([], $llm->calls, 'invalid adapter input never reaches the model');
});

test('FooterUnit derives navigation from typed page count and rejects invalid counts', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $multi = $unit->request(array_merge(template_part_unit_input(), ['page_count' => 2]))['prompt'];

    assert_contains('A compact `wp:page-list` is permitted', $multi);
    assert_true(!str_contains($multi, 'This site is ONE page'));
    assert_throws(
        fn () => $unit->request(array_merge(template_part_unit_input(), ['page_count' => '2'])),
        "unit input 'page_count' must be an integer"
    );
    assert_throws(
        fn () => $unit->request(array_merge(template_part_unit_input(), ['page_count' => 0])),
        'footer page_count must be at least 1'
    );
});

test('FooterUnit repairs a wrong root surface and stale saved classes to the assigned surface', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"tagName":"section","backgroundColor":"primary","gradient":"sunset","className":"keep has-primary-background-color has-sunset-gradient-background has-background-gradient","style":{"background":{"backgroundImage":{"url":"theme:./assets/footer.jpg"}},"color":{"background":"#ffffff","gradient":"linear-gradient(90deg, red, blue)"},"spacing":{"padding":{"top":"var:preset|spacing|sm"}},"css":"& { background:red !important; }","variation":"section-1"}} -->'
        . '<section class="wp-block-group keep has-primary-background-color has-sunset-gradient-background has-background-gradient has-background" style="padding-top:1px;background:linear-gradient(90deg, red, blue);color:red;background-image:url(theme:./assets/footer.jpg)">Footer</section>'
        . '<!-- /wp:group -->';
    $input = template_part_unit_input(); // photographic-split maps to contrast.

    $notes = [];
    $once = $unit->finish($raw, $input, $notes);
    assert_contains('"backgroundColor":"contrast"', $once);
    assert_contains('"tagName":"section"', $once);
    assert_contains('"className":"keep"', $once);
    assert_contains('"spacing":{"padding":{"top":"var:preset|spacing|sm"}}', $once);
    assert_contains('<section class="wp-block-group keep has-contrast-background-color has-background"', $once);
    assert_contains('style="padding-top:1px;color:red"', $once);
    assert_true(!str_contains($once, 'has-primary-background-color'));
    assert_true(!str_contains($once, 'has-sunset-gradient-background'));
    assert_true(!str_contains($once, 'has-background-gradient'));
    assert_true(!str_contains($once, '"gradient":'));
    assert_true(!str_contains($once, '"background":'));
    assert_true(!str_contains($once, 'background-image:'));
    assert_true(!str_contains($once, 'background:linear-gradient'));
    assert_true(!str_contains($once, '"css":'));
    assert_true(!str_contains($once, '"variation":'));
    assert_eq(2, count($notes), 'each opaque root style removal is durable');
    assert_contains("file='parts/footer.html'", implode("\n", $notes));
    assert_contains("authored style.css=", implode("\n", $notes));
    assert_contains("authored style.variation=", implode("\n", $notes));
    assert_contains('delivered=removed', implode("\n", $notes));
    assert_contains('disposition=', implode("\n", $notes));
    assert_eq($once, $unit->finish($once, $input), 'surface repair reaches a fixed point');

    $tmp = sys_get_temp_dir() . '/builder_footer_surface_' . bin2hex(random_bytes(6));
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/parts/footer.html', $once);
        (new PhpBlockFixer())->fix($project->themePath());
        $fixed = $project->readText('theme/parts/footer.html');
        assert_contains('"backgroundColor":"contrast"', $fixed);
        assert_contains('has-contrast-background-color', $fixed);
        assert_true(!str_contains($fixed, 'has-primary-background-color'));
        assert_true(!str_contains($fixed, '"css":'));
        assert_true(!str_contains($fixed, '"variation":'));
        assert_true(!str_contains($fixed, 'background:red'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    $base = $unit->finish(
        '<!-- wp:group --><div class="wp-block-group">Footer</div><!-- /wp:group -->',
        array_merge($input, ['composition_archetype' => 'typographic-billboard'])
    );
    assert_contains('"backgroundColor":"base"', $base);
    assert_contains('has-base-background-color', $base);
});

test('FooterUnit wraps multiple generated roots in one assigned-surface group without losing content', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"primary"} -->'
        . '<div class="wp-block-group has-primary-background-color has-background">Identity</div>'
        . '<!-- /wp:group -->'
        . '<!-- wp:paragraph --><p>Credit survives.</p><!-- /wp:paragraph -->';
    $input = template_part_unit_input();

    $once = $unit->finish($raw, $input);

    assert_true(str_starts_with($once, '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} -->'));
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $once);
    assert_contains('Identity', $once);
    assert_contains('Credit survives.', $once);
    assert_eq($once, $unit->finish($once, $input), 'multiple-root repair reaches a fixed point');
});

test('FooterUnit wraps a single non-group root instead of discarding it for the fallback', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:paragraph --><p>Credit survives.</p><!-- /wp:paragraph -->';
    $input = template_part_unit_input(); // photographic-split maps to contrast.

    $once = $unit->finish($raw, $input);

    assert_true(str_starts_with($once, '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} -->'));
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $once);
    assert_contains('Credit survives.', $once);
    assert_eq($once, $unit->finish($once, $input), 'single non-group-root repair reaches a fixed point');
});

test('FooterUnit preserves a fit-text billboard heading through finish and block re-serialization', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"base","align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull has-base-background-color has-background">'
        . '<!-- wp:heading {"align":"full","fitText":true} -->'
        . '<h2 class="wp-block-heading alignfull has-fit-text">Hola</h2>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'typographic-billboard']);

    $once = $unit->finish($raw, $input);

    assert_contains('"fitText":true', $once);
    assert_contains('has-fit-text', $once);
    assert_contains('"backgroundColor":"base"', $once);
    assert_eq($once, $unit->finish($once, $input), 'fit-text footer reaches a fixed point');

    $tmp = sys_get_temp_dir() . '/builder_footer_fit_text_' . bin2hex(random_bytes(6));
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/parts/footer.html', $once);
        (new PhpBlockFixer())->fix($project->themePath());
        $fixed = $project->readText('theme/parts/footer.html');
        assert_contains('"fitText":true', $fixed);
        assert_contains('has-fit-text', $fixed);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FooterUnit removes a malformed root style attribute instead of discarding usable content', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group -->'
        . '<div class="wp-block-group" style="color:red;background:url(theme:./asset.jpg">Footer stays.</div>'
        . '<!-- /wp:group -->';
    $notes = [];

    $out = $unit->finish($raw, template_part_unit_input(), $notes);

    assert_contains('Footer stays.', $out);
    assert_true(!str_contains($out, 'style='));
    assert_contains('saved HTML style attribute', implode("\n", $notes));
    assert_contains('delivered=removed', implode("\n", $notes));
});

test('FooterUnit cleans a root wrapper after an HTML comment and handles unquoted attributes', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"primary","className":"keep&#32;has-primary-background-color"} -->'
        . '<!--keep-note--><div data-note=\' class="decoy" style="color:blue"\' '
        . 'class="wp-block-group&#32;has-primary-background-color&#32;has-background" class="ignored-duplicate" '
        . 'style=background:red style="background:blue;padding-top:1px">'
        . 'Footer stays.</div><!-- /wp:group -->';

    $out = $unit->finish($raw, template_part_unit_input());

    assert_contains('<!--keep-note-->', $out);
    assert_contains('data-note=\' class="decoy" style="color:blue"\'', $out);
    assert_contains('Footer stays.', $out);
    assert_contains('"className":"keep"', $out);
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $out);
    assert_true(!str_contains($out, 'ignored-duplicate'));
    assert_true(!str_contains($out, 'has-primary-background-color'));
    assert_true(!str_contains($out, '&#32;'));
    assert_true(!str_contains($out, ' style=background:red'));
    assert_contains('style="padding-top:1px"', $out);
    assert_true(!str_contains($out, 'background:blue'));
    assert_true(!str_contains($out, '"backgroundColor":"primary"'));
    assert_contains('"backgroundColor":"contrast"', $out);
});

test('FooterUnit disables dynamic site-title self-links only for one-page footers', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title {"isLink":true} /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $single = $unit->finish($raw, template_part_unit_input());
    assert_eq(2, substr_count($single, '"isLink":false'), 'every one-page footer identity is non-linked');
    assert_true(!str_contains($single, '"isLink":true'));

    $multi = $unit->finish(
        $raw,
        array_merge(template_part_unit_input(), ['page_count' => 2])
    );
    assert_true(!str_contains($multi, '"isLink":false'));
    assert_contains('"isLink":true', $multi, 'an authored multi-page home link is preserved');
});

test('template-part units reject an unrepaired matching landmark for fallback delivery', function () {
    $footer = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $malformed = '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">Broken</div><!-- /wp:group -->';

    assert_throws(
        fn () => $footer->finish($malformed, template_part_unit_input()),
        'unrepaired nested footer landmark'
    );
});

test('GeneratedMarkup removes every matching landmark and reaches a fixed point', function () {
    $markup = '<!-- wp:group {"tagName":"footer","align":"full","layout":{"type":"flex"}} -->'
        . '<footer class="wp-block-group" data-label="keep > this">'
        . '<!-- wp:paragraph --><p>Keep every child byte.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"tagName":"footer"} --><footer class="nested">Nested</footer><!-- /wp:group -->'
        . '</footer><!-- /wp:group -->';
    $expected = '<!-- wp:group {"align":"full","layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group" data-label="keep > this">'
        . '<!-- wp:paragraph --><p>Keep every child byte.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group --><div class="nested">Nested</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $once = GeneratedMarkup::withoutRedundantLandmark($markup, 'footer');
    assert_eq($expected, $once);
    assert_eq($once, GeneratedMarkup::withoutRedundantLandmark($once, 'footer'));
});

test('GeneratedMarkup normalizes a nested-only landmark under a default div root', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Keep.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"tagName":"footer","className":"nested"} -->'
        . '<footer class="wp-block-group nested">Nested</footer><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $expected = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Keep.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"className":"nested"} -->'
        . '<div class="wp-block-group nested">Nested</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $once = GeneratedMarkup::withoutRedundantLandmark($markup, 'footer');
    assert_eq($expected, $once);
    assert_eq($once, GeneratedMarkup::withoutRedundantLandmark($once, 'footer'));
});

test('GeneratedMarkup leaves mismatched landmark wrapper pairs byte-identical', function () {
    $landmarkToDiv = '<!-- wp:group {"tagName":"footer","className":"keep"} -->'
        . '<footer class="wp-block-group keep">Keep</div><!-- /wp:group -->';
    $divToLandmark = '<!-- wp:group {"tagName":"footer","className":"keep"} -->'
        . '<div class="wp-block-group keep">Keep</footer><!-- /wp:group -->';

    assert_eq(
        $landmarkToDiv,
        GeneratedMarkup::withoutRedundantLandmark($landmarkToDiv, 'footer')
    );
    assert_eq(
        $divToLandmark,
        GeneratedMarkup::withoutRedundantLandmark($divToLandmark, 'footer')
    );
});

test('GeneratedMarkup leaves nonmatching and unknown landmark contexts unchanged', function () {
    $mismatch = '<!-- wp:group {"tagName":"aside"} -->'
        . '<aside class="wp-block-group">Keep</aside><!-- /wp:group -->';
    $nonGroup = '<!-- wp:cover {"tagName":"footer"} -->'
        . '<div class="wp-block-cover">Keep</div><!-- /wp:cover -->';
    $footer = '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">Keep</footer><!-- /wp:group -->';

    assert_eq($mismatch, GeneratedMarkup::withoutRedundantLandmark($mismatch, 'footer'));
    assert_eq($nonGroup, GeneratedMarkup::withoutRedundantLandmark($nonGroup, 'footer'));
    assert_eq($footer, GeneratedMarkup::withoutRedundantLandmark($footer, 'main'));
});

test('GeneratedMarkup removes a matching tagName when generated HTML already uses div', function () {
    $markup = '<!-- wp:group {"tagName":"header","className":"keep"} -->'
        . '<div class="wp-block-group keep">Header</div><!-- /wp:group -->';

    $out = GeneratedMarkup::withoutRedundantLandmark($markup, 'header');

    assert_contains('"className":"keep"', $out);
    assert_true(!str_contains($out, '"tagName"'));
    assert_contains('<div class="wp-block-group keep">Header</div>', $out);
});
