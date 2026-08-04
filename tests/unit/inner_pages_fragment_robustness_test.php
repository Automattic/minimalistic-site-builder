<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\InnerPagesDesignStep;

test('inner-pages-design balances primary inner-page and home-body fragments without repair calls', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('about', 'About', 'Explain the studio'),
    ]);
    $llm->queueText(
        '<main><section id="story"><h2>HOME-BALANCED</h2></section></main>'
        . '<footer><div>HOME-FOOTER</footer>',
    );
    $llm->queueText(
        '<main id="about-main"><div class="card"><p>INNER-BALANCED</p></main>',
    );
    $llm->queueText('REPAIR-QUEUE-SENTINEL');

    inner_pages_run($project, $llm);

    assert_eq(0, $llm->completeCalls, 'balancing must not consume serial repair completion');
    assert_eq(
        '<main><section id="story"><h2>HOME-BALANCED</h2></section></main>'
            . '<footer><div>HOME-FOOTER</div></footer>',
        $project->readText('design/home-body.html'),
    );
    assert_eq(
        '<main id="about-main"><div class="card"><p>INNER-BALANCED</p></div></main>',
        $project->readText('design/about.html'),
    );
    assert_true(!$project->exists('design/home-body.failed'));
    assert_true(!$project->exists('design/about.failed'));
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_eq(2, substr_count($warnings, 'disposition deterministically repaired'));
    assert_contains('design/home-body.html', $warnings);
    assert_contains('design/about.html', $warnings);
    assert_eq('REPAIR-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    inner_pages_cleanup($tmp);
});

test('inner-pages-design leaves no-main fragments on existing repair and failed-marker path', function () {
    $documentOnly = '<!doctype html><html><body><p>NO-MAIN</p></body></html>';
    assert_eq('', InnerPagesDesignStep::balanceFragment(''));
    assert_true(
        !str_contains(InnerPagesDesignStep::balanceFragment($documentOnly), '<main'),
        'balancing must not invent a main root',
    );

    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('broken', 'Broken', 'Keep existing fallback'),
    ]);
    $homeBody = inner_pages_home_body('FRONT-PAGE-RETAINED');
    $llm->queueText($homeBody);
    $llm->queueText($documentOnly);
    $llm->queueText('<div>Still no main after repair</div>');

    inner_pages_run($project, $llm);

    assert_eq(1, $llm->completeCalls, 'unsalvageable fragment still receives one serial repair');
    assert_eq($homeBody, $project->readText('design/home-body.html'));
    assert_true(!$project->exists('design/home-body.failed'));
    assert_true(!$project->exists('design/broken.html'));
    assert_true($project->exists('design/broken.failed'));
    assert_eq('home', $project->readJson('design/page-artifact-map.json')['home'] ?? null);
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_contains('design/broken.failed', $warnings);
    assert_contains('disposition removed after one semantic repair', $warnings);
    inner_pages_cleanup($tmp);
});

test('balanceFragment keeps valid page CSS and main bytes exact and idempotent', function () {
    $valid = '<style data-page-css>.card::before{content:"é < >";}</style>'
        . "\n<main id=\"valid-main\"><article><h1>Exact</h1><p>Keep bytes.</p></article></main>";
    $balanced = InnerPagesDesignStep::balanceFragment($valid);
    assert_eq($valid, $balanced);
    assert_eq($balanced, InnerPagesDesignStep::balanceFragment($balanced));

    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('valid', 'Valid', 'Keep exact bytes'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText($balanced);
    $llm->queueText('VALID-QUEUE-SENTINEL');

    inner_pages_run($project, $llm);

    assert_eq(0, $llm->completeCalls, 'valid balanced output must revalidate without repair');
    assert_eq($valid, $project->readText('design/valid.html'));
    assert_eq('VALID-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    inner_pages_cleanup($tmp);
});

test('balanceFragment refuses sentinel escape that would drop authored sibling content', function () {
    $escaped = '<main><p>A</p></main></site-build-fragment-root><p>B</p>';

    assert_eq($escaped, InnerPagesDesignStep::balanceFragment($escaped));
});

test('balanceFragment preserves invalid UTF-8 bytes without DOM transcoding', function () {
    $invalidUtf8 = "<main><div>A\xFFB</main>";

    assert_eq(
        bin2hex($invalidUtf8),
        bin2hex(InnerPagesDesignStep::balanceFragment($invalidUtf8)),
    );
});

test('inner-pages-design balances truncated semantic repair before requesting continuation', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('repair', 'Repair', 'Balance semantic repair'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<section>Initial response has no main</section>');
    $llm->queueText(
        '<main><article><p>REPAIR-BALANCED</p></main>',
        'max_tokens',
    );
    $llm->queueText('CONTINUATION-QUEUE-SENTINEL', 'stop');

    inner_pages_run($project, $llm);

    assert_eq(1, $llm->completeCalls, 'balanced repair must not request a continuation');
    assert_eq(
        '<main><article><p>REPAIR-BALANCED</p></article></main>',
        $project->readText('design/repair.html'),
    );
    assert_true(!$project->exists('design/repair.failed'));
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_contains('context page repair semantic repair', $warnings);
    assert_contains('disposition deterministically repaired', $warnings);
    assert_eq('CONTINUATION-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    inner_pages_cleanup($tmp);
});

test('inner-pages-design sanitizes truncated repair before accepting balanced closure', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('repair', 'Repair', 'Retain safe continuation'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<section>Initial response has no main</section>');
    $llm->queueText('<main><script>ONLY-UNSAFE</script>', 'max_tokens');
    $llm->queueText('<p>SAFE-CONTINUATION</p></main>', 'stop');
    $llm->queueText('INNER-REPAIR-QUEUE-SENTINEL');

    inner_pages_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'sanitized partial must request its safe continuation');
    assert_eq(
        '<main><p>SAFE-CONTINUATION</p></main>',
        $project->readText('design/repair.html'),
    );
    $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
    assert_eq(1, substr_count($warnings, 'ONLY-UNSAFE'), 'probe warnings must stay throwaway');
    assert_eq('INNER-REPAIR-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    inner_pages_cleanup($tmp);
});

test('section-mode home sanitizes truncated repair before accepting balanced closure', function () {
    $previousMode = getenv('SITE_BUILD_GEN_UNIT');
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
    ]);
    putenv('SITE_BUILD_GEN_UNIT=section');
    try {
        $llm->queueText('<section>Initial response has no home roots</section>');
        $llm->queueText(
            '<main><script>HOME-ONLY-UNSAFE</script></main><footer><div>FOOTER',
            'max_tokens',
        );
        $llm->queueText('<p>HOME-SAFE-CONTINUATION</p></div></footer>', 'stop');
        $llm->queueText('HOME-REPAIR-QUEUE-SENTINEL');

        inner_pages_run($project, $llm);

        assert_eq(2, $llm->completeCalls, 'sanitized home partial must request safe continuation');
        assert_eq(
            '<main></main><footer><div>FOOTER<p>HOME-SAFE-CONTINUATION</p></div></footer>',
            $project->readText('design/home-body.html'),
        );
        $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
        assert_eq(1, substr_count($warnings, 'HOME-ONLY-UNSAFE'), 'probe warnings must stay throwaway');
        assert_eq('HOME-REPAIR-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    } finally {
        $previousMode === false
            ? putenv('SITE_BUILD_GEN_UNIT')
            : putenv('SITE_BUILD_GEN_UNIT=' . $previousMode);
        inner_pages_cleanup($tmp);
    }
});
