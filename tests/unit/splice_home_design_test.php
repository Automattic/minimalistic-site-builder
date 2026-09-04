<?php
declare(strict_types=1);

use Automattic\SiteBuild\Html;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;

function splice_home_preview(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<style>:root{--ink:#231f20}</style></head><body class="fold-shell">'
        . '<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>'
        . '<main><section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section></main>'
        . '</body></html>';
}

function splice_home_body(): string
{
    return '<main><section id="story"><h2>HOME-BODY-STORY-5D2E</h2></section>'
        . '<section id="visit"><p>HOME-BODY-VISIT-6F70</p></section></main>'
        . '<footer class="site-footer"><p>HOME-BODY-FOOTER-2B19</p></footer>';
}

function splice_home_body_with_nested_attributions(): string
{
    return '<main>'
        . '<section id="story"><h2>NESTED-BODY-STORY-A17C</h2>'
        . '<blockquote><p>First testimonial.</p><footer>ATTRIBUTION-ONE-6B31</footer></blockquote></section>'
        . '<section id="work"><h2>NESTED-BODY-WORK-C842</h2>'
        . '<article><p>Second case study.</p><footer>ATTRIBUTION-TWO-935D</footer></article></section>'
        . '<section id="contact"><h2>NESTED-BODY-CONTACT-D054</h2></section>'
        . '</main><footer class="site-footer"><p>NESTED-PAGE-FOOTER-E7A9</p></footer>';
}

/** @return array{0:Project,1:string} */
function splice_home_fixture(?string $preview = null, ?string $body = null): array
{
    $tmp = sys_get_temp_dir() . '/builder_splice_home_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    if ($preview !== null) {
        $project->writeText('design/preview.html', $preview);
    }
    if ($body !== null) {
        $project->writeText('design/home-body.html', $body);
    }
    return [$project, $tmp];
}

function splice_home_run(Project $project): SpliceHomeDesignStep
{
    $step = new SpliceHomeDesignStep();
    $step->run($project);
    return $step;
}

test('splice-home-design declares exact inputs and deterministic home output', function () {
    $outputs = [];
    for ($run = 0; $run < 2; $run++) {
        [$project, $tmp] = splice_home_fixture(splice_home_preview(), splice_home_body());
        $step = splice_home_run($project);

        assert_eq('splice-home-design', $step->id());
        assert_eq(
            ['design/preview.html', 'design/home-body.html'],
            $step->declaration()->reads,
        );
        assert_eq(['design/home.html', 'warnings.json'], $step->declaration()->writes);
        assert_eq(false, $step->declaration()->concurrent);
        assert_true($project->exists('design/home.html'));
        $outputs[] = $project->readText('design/home.html');

        $dom = Html::loadUtf8Html($outputs[$run], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        assert_true($dom instanceof DOMDocument, 'composed homepage parses');
        $xpath = new DOMXPath($dom);
        assert_eq(1, $xpath->query('//header')->length, 'fold contributes exactly one header');
        assert_eq(1, $xpath->query('//footer')->length, 'home body contributes exactly one footer');
        assert_eq(1, $xpath->query('/html/body/main')->length, 'fold and body share one main');
        assert_eq(1, $xpath->query('/html/body/main/section[@id="hero"]')->length);
        assert_eq(1, $xpath->query('/html/body/main/section[@id="story"]')->length);
        assert_eq(1, $xpath->query('/html/body/main/section[@id="visit"]')->length);
        assert_contains('<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>', $outputs[$run]);
        assert_contains('<section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section>', $outputs[$run]);
        assert_contains('<footer class="site-footer"><p>HOME-BODY-FOOTER-2B19</p></footer>', $outputs[$run]);
        assert_true(!$project->exists('warnings.json'), 'valid splice needs no warning');
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    assert_eq($outputs[0], $outputs[1], 'fixed fold and body compose byte-identically');
});

test('splice-home-design preserves the full body with nested attribution footers', function () {
    [$project, $tmp] = splice_home_fixture(
        splice_home_preview(),
        splice_home_body_with_nested_attributions(),
    );

    try {
        splice_home_run($project);

        $home = $project->readText('design/home.html');
        $warnings = $project->exists('warnings.json')
            ? implode("\n", $project->readJson('warnings.json')['splice-home-design'] ?? [])
            : '';
        assert_true(
            !str_contains($warnings, 'retained header and hero only'),
            'nested attribution footers must not trigger the fold-only fallback warning',
        );
        foreach ([
            'FOLD-HERO-97C4',
            'NESTED-BODY-STORY-A17C',
            'ATTRIBUTION-ONE-6B31',
            'NESTED-BODY-WORK-C842',
            'ATTRIBUTION-TWO-935D',
            'NESTED-BODY-CONTACT-D054',
            'NESTED-PAGE-FOOTER-E7A9',
        ] as $marker) {
            assert_contains($marker, $home, "composed home preserves {$marker}");
        }
        assert_eq('', $warnings, 'valid nested-attribution fixture needs no splice warning');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('splice-home-design keeps malformed footer and main depth guards', function () {
    $bodyParts = new ReflectionMethod(SpliceHomeDesignStep::class, 'bodyParts');
    $bodyParts->setAccessible(true);
    $invalid = [
        'footer in footer' => '<main><p>Body</p></main><footer><footer>Nested</footer></footer>',
        'footer in address' => '<main><address><footer>Nested</footer></address></main><footer>Page</footer>',
        'two top-level footers' => '<main><p>Body</p></main><footer>One</footer><footer>Two</footer>',
        'nested main' => '<main><section><main>Nested</main></section></main><footer>Page</footer>',
    ];

    foreach ($invalid as $case => $html) {
        assert_eq(null, $bodyParts->invoke(null, $html), "{$case} remains malformed");
    }
});

test('splice and home-body validation agree on footer and main depth', function () {
    $bodyParts = new ReflectionMethod(SpliceHomeDesignStep::class, 'bodyParts');
    $bodyParts->setAccessible(true);
    $isValidHomeBody = new ReflectionMethod(InnerPagesDesignStep::class, 'isValidHomeBodyFragment');
    $isValidHomeBody->setAccessible(true);
    $cases = [
        'idiomatic nested attribution' => splice_home_body_with_nested_attributions(),
        'footer in footer' => '<main><p>Body</p></main><footer><footer>Nested</footer></footer>',
        'footer in address' => '<main><address><footer>Nested</footer></address></main><footer>Page</footer>',
        'two top-level footers' => '<main><p>Body</p></main><footer>One</footer><footer>Two</footer>',
        'nested main' => '<main><section><main>Nested</main></section></main><footer>Page</footer>',
    ];

    foreach ($cases as $case => $html) {
        $spliceAccepts = $bodyParts->invoke(null, $html) !== null;
        $generationAccepts = $isValidHomeBody->invoke(null, $html);
        assert_eq($generationAccepts, $spliceAccepts, "{$case} acceptance must match generation");
    }
});

test('splice-home-design keeps fold-only degradation for empty and malformed bodies', function () {
    $cases = [
        'empty' => '',
        'malformed' => '<main><section>UNCLOSED-BODY',
    ];

    foreach ($cases as $case => $body) {
        [$project, $tmp] = splice_home_fixture(splice_home_preview(), $body);
        try {
            splice_home_run($project);

            assert_eq(splice_home_preview(), $project->readText('design/home.html'), "{$case} keeps fold bytes");
            $warnings = implode(
                "\n",
                $project->readJson('warnings.json')['splice-home-design'] ?? [],
            );
            assert_contains('home-body missing, empty, or malformed', $warnings, "{$case} warning has cause");
            assert_contains('retained header and hero only', $warnings, "{$case} warning has disposition");
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

test('splice-home-design degrades every generated-content boundary and never drops home', function () {
    $cases = [
        'missing header' => [
            str_replace(
                '<header class="site-header"><nav>FOLD-HEADER-38A1</nav></header>',
                '',
                splice_home_preview(),
            ),
            splice_home_body(),
            null,
            'HOME-BODY-STORY-5D2E',
        ],
        'missing hero' => [
            str_replace(
                '<section id="hero" class="hero"><h1>FOLD-HERO-97C4</h1></section>',
                '<section id="intro">NO-HERO</section>',
                splice_home_preview(),
            ),
            splice_home_body(),
            null,
            'HOME-BODY-STORY-5D2E',
        ],
        'empty home body' => [splice_home_preview(), '', null, 'FOLD-HERO-97C4'],
        'failed home body' => [splice_home_preview(), null, 'failed', 'FOLD-HERO-97C4'],
        'malformed fold' => ['<html><body><header>MALFORMED-FOLD', splice_home_body(), null, 'HOME-BODY-STORY-5D2E'],
    ];

    foreach ($cases as $name => [$preview, $body, $failed, $survivor]) {
        [$project, $tmp] = splice_home_fixture($preview, $body);
        if ($failed !== null) {
            $project->writeText('design/home-body.failed', "HOME-BODY-FAILED\n");
        }

        $caught = null;
        try {
            splice_home_run($project);
        } catch (Throwable $error) {
            $caught = $error;
        }

        assert_eq(null, $caught, "{$name} never throws");
        assert_true($project->exists('design/home.html'), "{$name} still writes front page");
        $home = $project->readText('design/home.html');
        assert_true(trim($home) !== '', "{$name} writes non-empty front page");
        assert_contains($survivor, $home, "{$name} keeps safest usable unit");
        $warnings = implode("\n", $project->readJson('warnings.json')['splice-home-design'] ?? []);
        foreach (['design/home.html', 'authored_value', 'delivered_value', 'disposition'] as $needle) {
            assert_contains($needle, $warnings, "{$name} warning carries {$needle}");
        }
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('splice keeps the site <style> byte-identical to site.css and page-scopes the body CSS', function () {
    // The home body may now ship its own CSS. It must land in a
    // `<style data-page-css>` block and NEVER be merged into the preview's
    // <style>: that block is the SITE stylesheet, mirrored byte for byte by
    // design/site.css, and PageStylesStep treats a page artifact's unattributed
    // <style> as site CSS. Merging would push the body's class vocabulary
    // outside PageScope::bodyClass() scoping — free to collide with every other
    // page — and ship the same rules twice. A real build did exactly that:
    // site <style> came out at 10,381B against a 4,529B site.css, the
    // difference being the 5,850B page block duplicated.
    $siteCss = ':root{--ink:#231f20}';
    $bodyCss = '.work-grid{display:grid;gap:2rem}.section__title{font-size:2rem}';
    [$project, $tmp] = splice_home_fixture(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<style>' . $siteCss . '</style></head><body>'
        . '<header class="site-header"><nav>NAV</nav></header>'
        . '<main><section id="hero"><h1>HERO</h1></section></main>'
        . '</body></html>',
        '<style data-page-css>' . $bodyCss . '</style>'
        . '<main><section id="work" class="work-grid"><h2 class="section__title">Work</h2></section></main>'
        . '<footer><p>Footer</p></footer>',
    );
    $project->writeText('design/site.css', $siteCss);

    quietly(fn () => splice_home_run($project));

    $home = $project->readText('design/home.html');
    preg_match_all('#<style([^>]*)>(.*?)</style>#si', $home, $blocks, PREG_SET_ORDER);

    $site = [];
    $page = [];
    foreach ($blocks as $block) {
        if (preg_match('/data-page-css/i', $block[1])) {
            $page[] = $block[2];
        } else {
            $site[] = $block[2];
        }
    }

    assert_eq(1, count($site), 'exactly one site <style> survives the splice');
    assert_eq(
        $project->readText('design/site.css'),
        $site[0],
        "design/home.html's unattributed <style> === design/site.css, byte for byte",
    );
    assert_eq(1, count($page), 'the body CSS lands in exactly one data-page-css block');
    assert_contains('.work-grid', $page[0], 'the body CSS is carried, not dropped');
    assert_eq(
        1,
        substr_count($home, $bodyCss),
        'body CSS must appear once (data-page-css only), not also in the site block',
    );
    assert_true(
        !str_contains($site[0], '.work-grid'),
        'the body CSS must NOT be duplicated into the site stylesheet',
    );
    assert_true(
        preg_match(
            '/<style\b[^>]*\bdata-page-css\b[^>]*>.*?<\/style>\s*<main\b/is',
            $home,
        ) === 1,
        'page CSS sits immediately before <main>, like an inner page',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});
