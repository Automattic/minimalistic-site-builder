<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @param list<array<string,mixed>> $pages
 * @return array{0:Project,1:string}
 */
function second_review_page_gen_fixture(array $pages): array
{
    $tmp = sys_get_temp_dir() . '/builder_page_gen_second_review_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', [
        'name' => 'Second Review Studio',
        'language' => 'English',
        'pages' => $pages,
    ]);
    $project->writeText('design/site.css', ':root{--ink:#18222d}');
    $project->writeText(
        'design/preview.html',
        '<!doctype html><html><head><style>:root{--ink:#18222d}</style></head>'
            . '<body><header>Header</header><main><section id="hero">Hero</section></main>'
            . '</body></html>',
    );
    return [$project, $tmp];
}

function second_review_page_gen_step(FakeLlm $llm): InnerPagesDesignStep
{
    return new InnerPagesDesignStep($llm, new PromptRenderer(repo_path('prompts')));
}

function second_review_page_gen_home(string $marker): string
{
    return '<main><section><h2>' . $marker . '</h2></section></main>'
        . '<footer><p>FRONT-HOME-BODY-FOOTER</p></footer>';
}

function second_review_page_gen_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

test('internal home-body physical reservation cannot be claimed by a semantic inner page', function () {
    [$project, $tmp] = second_review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
        ['slug' => 'home-body', 'title' => 'Home body', 'purpose' => 'Internal-name collision'],
        ['slug' => 'home-body-2', 'title' => 'Home body 2', 'purpose' => 'Semantic suffix'],
    ]);
    $expectedMap = [
        'landing' => 'home',
        'home-body' => 'home-body-3',
        'home-body-2' => 'home-body-2',
    ];

    for ($run = 1; $run <= 2; $run++) {
        $front = second_review_page_gen_home("FRONT-RUN-{$run}");
        $llm = new FakeLlm();
        $llm->queueText($front);
        $llm->queueText('<main><h1>INNER-HOME-BODY-PAGE</h1></main>');
        $llm->queueText('<main><h1>INNER-HOME-BODY-2-PAGE</h1></main>');

        second_review_page_gen_step($llm)->run($project);

        assert_eq($expectedMap, $project->readJson('design/page-artifact-map.json'));
        assert_eq($front, $project->readText('design/home-body.html'));
        assert_contains('FRONT-HOME-BODY-FOOTER', $project->readText('design/home-body.html'));
        assert_contains('INNER-HOME-BODY-PAGE', $project->readText('design/home-body-3.html'));
        assert_contains('INNER-HOME-BODY-2-PAGE', $project->readText('design/home-body-2.html'));
    }

    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    foreach ([
        'home-body',
        'home-body-3',
        'authored',
        'delivered',
        'disposition renamed',
    ] as $needle) {
        assert_contains($needle, $warnings);
    }
    second_review_page_gen_cleanup($tmp);
});

test('home-body accepts quoted hero decoys when no element has a live hero id', function () {
    [$project, $tmp] = second_review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $fakeOnly = '<main><div data-note="quoted id=hero"><h2>Story</h2></div>'
        . '<article aria-label="another id=hero decoy"><h2>More</h2></article></main>'
        . '<footer><p>Footer</p></footer>';
    $llm = new FakeLlm();
    $llm->queueText($fakeOnly);

    second_review_page_gen_step($llm)->run($project);

    assert_eq($fakeOnly, $project->readText('design/home-body.html'));
    assert_eq(0, $llm->completeCalls);
    second_review_page_gen_cleanup($tmp);
});

test('home-body rejects every h1 and every live hero id regardless of element name', function () {
    [$headingProject, $headingTmp] = second_review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $withHeading = '<main><section><h1>Repeated homepage heading</h1></section></main>'
        . '<footer><p>Footer</p></footer>';
    $headingLlm = new FakeLlm();
    $headingLlm->queueText($withHeading);
    $headingLlm->queueText($withHeading);
    second_review_page_gen_step($headingLlm)->run($headingProject);
    assert_true(!$headingProject->exists('design/home-body.html'));
    assert_true($headingProject->exists('design/home-body.failed'));
    assert_eq(1, $headingLlm->completeCalls);
    assert_contains('do not add a second `h1`', strtolower($headingLlm->calls[0]['prompt']));
    second_review_page_gen_cleanup($headingTmp);

    [$heroProject, $heroTmp] = second_review_page_gen_fixture([
        ['slug' => 'landing', 'title' => 'Landing', 'purpose' => 'Front'],
    ]);
    $divHero = '<main><div data-note="quoted id=story" id="hero"><h2>Repeated hero</h2></div></main>'
        . '<footer><p>Footer</p></footer>';
    $articleHero = '<main><article aria-label="quoted id=story" id="hero"><h2>Still hero</h2></article></main>'
        . '<footer><p>Footer</p></footer>';
    $heroLlm = new FakeLlm();
    $heroLlm->queueText($divHero);
    $heroLlm->queueText($articleHero);
    second_review_page_gen_step($heroLlm)->run($heroProject);
    assert_true(!$heroProject->exists('design/home-body.html'));
    assert_true($heroProject->exists('design/home-body.failed'));
    assert_eq(1, $heroLlm->completeCalls);
    second_review_page_gen_cleanup($heroTmp);
});
