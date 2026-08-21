<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @param list<array<string,mixed>> $pages
 * @return array{0:Project,1:string}
 */
function review_page_gen_fixture(array $pages): array
{
    $tmp = sys_get_temp_dir() . '/builder_page_gen_review_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Review Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeText('design/site.css', ':root{--ink:#18222d}');
    $project->writeText(
        'design/preview.html',
        '<!doctype html><html><head><style>:root{--ink:#18222d}</style></head>'
            . '<body><header>REVIEW-FOLD-HEADER</header>'
            . '<main><section id="hero">REVIEW-FOLD-HERO</section></main></body></html>',
    );
    return [$project, $tmp];
}

function review_page_gen_step(FakeLlm $llm): InnerPagesDesignStep
{
    return new InnerPagesDesignStep($llm, new PromptRenderer(repo_path('prompts')));
}

function review_page_gen_home(string $content = 'Home content'): string
{
    return '<main><section><h2>' . $content . '</h2></section></main>'
        . '<footer><p>Site footer</p></footer>';
}

function review_page_gen_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

test('page artifact allocator reserves the complete semantic set in either ordering', function () {
    [$project, $tmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
        ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Reserved first'],
        ['slug' => 'home-2', 'title' => 'Home 2', 'purpose' => 'Semantic suffix after reserved'],
        ['slug' => 'site-2', 'title' => 'Site 2', 'purpose' => 'Semantic suffix before reserved'],
        ['slug' => 'site', 'title' => 'Site', 'purpose' => 'Reserved last'],
    ]);
    $expected = [
        'landing' => 'home',
        'home' => 'home-3',
        'home-2' => 'home-2',
        'site-2' => 'site-2',
        'site' => 'site-3',
    ];

    for ($run = 1; $run <= 2; $run++) {
        $llm = new FakeLlm();
        foreach ([
            review_page_gen_home("Home run {$run}"),
            '<main><h1>Reserved home</h1></main>',
            '<main><h1>Semantic home suffix</h1></main>',
            '<main><h1>Semantic site suffix</h1></main>',
            '<main><h1>Reserved site</h1></main>',
        ] as $response) {
            $llm->queueText($response);
        }
        review_page_gen_step($llm)->run($project);
        assert_eq($expected, $project->readJson('design/page-artifact-map.json'));
    }

    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach (['home', 'home-3', 'site', 'site-3', 'disposition renamed'] as $needle) {
        assert_contains($needle, $warnings);
    }
    review_page_gen_cleanup($tmp);
});

test('home-body hero-id validation ignores quoted decoys and detects the real id attribute', function () {
    [$fakeProject, $fakeTmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $fakeOnly = '<main><section data-note="quoted id=hero" id="story"><h2>Story</h2></section></main>'
        . '<footer><p>Footer</p></footer>';
    $fakeLlm = new FakeLlm();
    $fakeLlm->queueText($fakeOnly);
    review_page_gen_step($fakeLlm)->run($fakeProject);
    assert_eq($fakeOnly, $fakeProject->readText('design/home-body.html'));
    assert_eq(0, $fakeLlm->completeCalls, 'quoted fake id never triggers semantic repair');
    review_page_gen_cleanup($fakeTmp);

    [$realProject, $realTmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $hiddenHero = '<main><section data-note="quoted id=story" id="hero"><h2>Hero repeat</h2></section></main>'
        . '<footer><p>Footer</p></footer>';
    $realLlm = new FakeLlm();
    $realLlm->queueText($hiddenHero);
    $realLlm->queueText($hiddenHero);
    review_page_gen_step($realLlm)->run($realProject);
    assert_true(!$realProject->exists('design/home-body.html'));
    assert_true($realProject->exists('design/home-body.failed'));
    assert_eq(1, $realLlm->completeCalls, 'real hero id receives one semantic repair');
    review_page_gen_cleanup($realTmp);
});

test('single-page batch failure marks home unavailable and preserves splice fallback', function () {
    [$project, $tmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $project->writeText('design/home-body.html', 'STALE-HOME-BODY');
    $llm = new FakeLlm();
    $llm->failPromptSubstrings = ['Design the finished homepage content below the fold'];

    review_page_gen_step($llm)->run($project);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(0, $llm->completeCalls, 'batch failure never enters serial generation');
    assert_true(!$project->exists('design/home-body.html'));
    assert_true($project->exists('design/home-body.failed'));
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach (['design/home-body.html', 'FakeLlm', 'delivered', 'design/home-body.failed', 'disposition'] as $needle) {
        assert_contains($needle, $warnings);
    }

    (new SpliceHomeDesignStep())->run($project);
    $home = $project->readText('design/home.html');
    assert_contains('REVIEW-FOLD-HEADER', $home);
    assert_contains('REVIEW-FOLD-HERO', $home);
    review_page_gen_cleanup($tmp);
});

test('multi-page batch failure marks every unavailable physical unit without serial calls', function () {
    [$project, $tmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
        ['slug' => 'about', 'title' => 'About', 'purpose' => 'About page'],
        ['slug' => 'contact', 'title' => 'Contact', 'purpose' => 'Contact page'],
    ]);
    foreach (['home-body', 'about', 'contact'] as $slug) {
        $project->writeText("design/{$slug}.html", "STALE-{$slug}");
    }
    $llm = new FakeLlm();
    $llm->failPromptSubstrings = ['Contact page'];

    review_page_gen_step($llm)->run($project);

    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(0, $llm->completeCalls, 'batch failure never enters per-unit semantic repair');
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach (['home-body', 'about', 'contact'] as $slug) {
        assert_true(!$project->exists("design/{$slug}.html"), "{$slug} stale HTML cleared");
        assert_true($project->exists("design/{$slug}.failed"), "{$slug} failure marker written");
        assert_contains("design/{$slug}.html", $warnings);
        assert_contains("design/{$slug}.failed", $warnings);
    }
    assert_contains('FakeLlm', $warnings);
    assert_contains('disposition', $warnings);
    review_page_gen_cleanup($tmp);
});

test('home-body requires a bare main and warns when repair removes authored attributes', function () {
    [$project, $tmp] = review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $authored = '<main class="authored-home-main"><section><h2>Story</h2></section></main>'
        . '<footer><p>Footer</p></footer>';
    $repaired = review_page_gen_home('Repaired bare main');
    $llm = new FakeLlm();
    $llm->queueText($authored);
    $llm->queueText($repaired);

    review_page_gen_step($llm)->run($project);

    assert_eq($repaired, $project->readText('design/home-body.html'));
    assert_eq(1, $llm->completeCalls, 'attributed main gets one semantic repair');
    assert_contains('bare <main> with no attributes', strtolower($llm->calls[0]['prompt']));
    assert_contains('bare <main> with no attributes', strtolower($llm->calls[1]['prompt']));
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach (['authored-home-main', 'delivered design/home-body.html', 'disposition replaced'] as $needle) {
        assert_contains($needle, $warnings);
    }
    review_page_gen_cleanup($tmp);
});
