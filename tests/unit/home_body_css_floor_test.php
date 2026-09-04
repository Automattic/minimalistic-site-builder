<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;

test('unstyledClasses detects a home-body class with no matching rule', function () {
    $html = '<style data-page-css>.card{display:grid}</style>'
        . '<main><section class="card work-grid"><h2>Work</h2></section></main>'
        . '<footer><p>F</p></footer>';
    $css = '.card{display:grid}';
    assert_eq(['work-grid'], InnerPagesDesignStep::unstyledClasses($html, $css));
});

test('unstyledClasses is empty when every used class has a rule', function () {
    $html = '<style data-page-css>.card{display:grid}.work-grid{gap:1rem}</style>'
        . '<main><section class="card work-grid"><h2>Work</h2></section></main>'
        . '<footer><p>F</p></footer>';
    $css = InnerPagesDesignStep::pageCssFromFragment($html);
    assert_eq([], InnerPagesDesignStep::unstyledClasses($html, $css));
});

test('styled home-body classes do not trigger css-coverage repair or warning', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome visitors'),
    ]);
    $html = '<style data-page-css>.work-grid{display:grid}</style>'
        . '<main><section class="work-grid"><h2>Work</h2></section></main>'
        . '<footer><p>F</p></footer>';
    $llm->queueText($html);
    $llm->queueText('CSS-FLOOR-STYLED-SENTINEL');

    try {
        inner_pages_run($project, $llm);
        assert_eq($html, $project->readText('design/home-body.html'));
        assert_eq(0, $llm->completeCalls, 'fully styled home-body must not consume serial repair');
        assert_true(!$project->exists('warnings.json'));
        assert_eq('CSS-FLOOR-STYLED-SENTINEL', $llm->complete('sentinel probe'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('home-body css-coverage repair styles missing classes and does not warn', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome visitors'),
    ]);
    $llm->queueText(
        '<main><section class="work-grid"><h2>Selected work</h2></section></main>'
        . '<footer><p>F</p></footer>'
    );
    $repaired = '<style data-page-css>.work-grid{display:grid;gap:1.5rem}</style>'
        . '<main><section class="work-grid"><h2>Selected work</h2></section></main>'
        . '<footer><p>F</p></footer>';
    $llm->queueText($repaired);

    try {
        inner_pages_run($project, $llm);
        assert_eq($repaired, $project->readText('design/home-body.html'));
        assert_eq(1, $llm->completeCalls, 'unstyled classes consume one coverage repair');
        assert_true(!$project->exists('warnings.json'));
        assert_true(!$project->exists('design/home-body.failed'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('home-body css-coverage residual warning names the unstyled classes and does not throw', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome visitors'),
    ]);
    $llm->queueText(
        '<main><section class="work-grid mystery-band"><h2>Selected work</h2></section></main>'
        . '<footer><p>F</p></footer>'
    );
    // Repair returns the same unstyled markup — floor must warn, not throw.
    $llm->queueText(
        '<style data-page-css>.unrelated{color:red}</style>'
        . '<main><section class="work-grid mystery-band"><h2>Selected work</h2></section></main>'
        . '<footer><p>F</p></footer>'
    );

    try {
        inner_pages_run($project, $llm);
        $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
        assert_contains('unstyled_classes=', $warnings);
        assert_contains('work-grid', $warnings);
        assert_contains('mystery-band', $warnings);
        assert_true($project->exists('design/home-body.html'));
        assert_true(!$project->exists('design/home-body.failed'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('home-body css-coverage repair failure warns and still delivers the fragment', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome visitors'),
    ]);
    $authored = '<main><section class="work-grid"><h2>Selected work</h2></section></main>'
        . '<footer><p>F</p></footer>';
    $llm->queueText($authored);

    try {
        inner_pages_run($project, $llm);
        assert_eq($authored, $project->readText('design/home-body.html'));
        $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
        assert_contains('css-coverage repair unavailable', $warnings);
        assert_contains('unstyled_classes=work-grid', $warnings);
        assert_true(!$project->exists('design/home-body.failed'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('splice-home-design places home-body page CSS immediately before main', function () {
    $preview = splice_home_preview();
    $body = '<style data-page-css>.work-grid{display:grid;gap:1.5rem}</style>'
        . "\n" . splice_home_body();
    [$project, $tmp] = splice_home_fixture($preview, $body);
    try {
        splice_home_run($project);
        $home = $project->readText('design/home.html');
        $site = ':root{--ink:#231f20}';
        assert_contains('.work-grid{display:grid;gap:1.5rem}', $home);
        assert_contains($site, $home);
        assert_true(
            preg_match(
                '/<style\b[^>]*\bdata-page-css\b[^>]*>.*?<\/style>\s*<main\b/is',
                $home,
            ) === 1,
            'page CSS sits immediately before <main>',
        );
        assert_true(
            preg_match('/<head\b[^>]*>.*?data-page-css.*?<\/head>/is', $home) !== 1,
            'page CSS must not land in the document head',
        );
        assert_contains('HOME-BODY-STORY-5D2E', $home);
        assert_true(!$project->exists('warnings.json'), 'valid styled splice needs no warning');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
