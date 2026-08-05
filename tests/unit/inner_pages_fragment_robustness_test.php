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

test('inner-pages-design strips surrounding prose without consuming repair completions', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('about', 'About', 'Explain the studio'),
        inner_page('contact', 'Contact', 'Give practical contact details'),
    ]);
    $homeBody = '<main><section id="story"><h2>HOME-PREAMBLE</h2></section></main>'
        . '<footer><p>HOME-FOOTER</p></footer>';
    $about = '<style data-page-css>.about{color:var(--ink)}</style>'
        . "\n<main id=\"about-main\"><p>INNER-PREAMBLE</p></main>";
    $contact = '<main id="contact-main"><p>TRAILING-PROSE</p></main>';
    $llm->queueText(
        "I'll continue the established editorial system.\n\n" . $homeBody,
    );
    $llm->queueText("Here is the completed inner page.\n\n" . $about);
    $llm->queueText($contact . "\n\nThat completes it.");
    $llm->queueText('PREAMBLE-REPAIR-QUEUE-SENTINEL');

    try {
        inner_pages_run($project, $llm);

        assert_eq(0, $llm->completeCalls, 'prose stripping must not consume serial repair completion');
        assert_eq($homeBody, $project->readText('design/home-body.html'));
        assert_eq($about, $project->readText('design/about.html'));
        assert_eq($contact, $project->readText('design/contact.html'));
        assert_true(!$project->exists('design/home-body.failed'));
        assert_true(!$project->exists('design/about.failed'));
        assert_true(!$project->exists('design/contact.failed'));
        $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
        assert_eq(3, substr_count($warnings, 'preamble removed'));
        assert_eq(3, substr_count($warnings, 'disposition deterministically repaired'));
        assert_eq('PREAMBLE-REPAIR-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('stripSurroundingProse preserves element-bearing prefixes and existing failure path', function () {
    $guarded = '<section>real authored content</section>'
        . '<main id="guarded-main"><p>Keep main too.</p></main>';
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('guarded', 'Guarded', 'Never drop authored roots'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText($guarded);
    $llm->queueText('<div>Still no main after repair</div>');

    try {
        inner_pages_run($project, $llm);

        assert_eq(1, $llm->completeCalls, 'element-bearing prefix must use existing repair path');
        assert_true(!$project->exists('design/guarded.html'));
        assert_true($project->exists('design/guarded.failed'));
        $repairCall = $llm->calls[array_key_last($llm->calls)];
        assert_contains($guarded, $repairCall['prompt'], 'repair prompt must retain every authored byte');

        $strip = new ReflectionMethod(InnerPagesDesignStep::class, 'stripSurroundingProse');
        $strip->setAccessible(true);
        assert_eq($guarded, $strip->invoke(null, $guarded));
        assert_contains('real authored content', (string) $strip->invoke(null, $guarded));
    } finally {
        inner_pages_cleanup($tmp);
    }
});

test('stripSurroundingProse keeps clean fragments byte-exact and valid through the step', function () {
    $inner = '<style data-page-css>.card::before{content:"é < >";}</style>'
        . "\n<main id=\"clean-main\"><article><p>Keep exact bytes.</p></article></main>";
    $homeBody = '<main><section id="clean-story"><h2>Clean home</h2></section></main>'
        . "\n<footer><p>Clean footer</p></footer>";
    $strip = new ReflectionMethod(InnerPagesDesignStep::class, 'stripSurroundingProse');
    $strip->setAccessible(true);

    $strippedInner = (string) $strip->invoke(null, $inner);
    $strippedHome = (string) $strip->invoke(null, $homeBody);
    assert_eq($inner, $strippedInner);
    assert_eq($inner, $strip->invoke(null, $strippedInner));
    assert_eq($homeBody, $strippedHome);
    assert_eq($homeBody, $strip->invoke(null, $strippedHome));

    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('clean', 'Clean', 'Keep exact bytes'),
    ]);
    $llm->queueText($homeBody);
    $llm->queueText($inner);
    $llm->queueText('CLEAN-REPAIR-QUEUE-SENTINEL');

    try {
        inner_pages_run($project, $llm);

        assert_eq(0, $llm->completeCalls, 'clean fragments must validate without repair');
        assert_eq($homeBody, $project->readText('design/home-body.html'));
        assert_eq($inner, $project->readText('design/clean.html'));
        assert_eq('CLEAN-REPAIR-QUEUE-SENTINEL', $llm->complete('sentinel probe'));
    } finally {
        inner_pages_cleanup($tmp);
    }
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

test('balanceFragment refuses to round-trip raw-text bodies that contain markup', function () {
    // libxml deletes `</tag>`-like sequences inside style/script bodies. Such a
    // fragment must fall through unchanged (to the LLM repair path) rather than
    // be silently corrupted into a structurally-valid-but-lossy fragment.
    $svg = '<style data-page-css>.h{background:url("data:image/svg+xml;utf8,'
        . '<svg viewBox=\'0 0 9 9\'><circle r=\'5\'/></svg>")}</style><main><section>X';
    assert_eq($svg, InnerPagesDesignStep::balanceFragment($svg));

    $content = '<style data-page-css>.x::before{content:"</main>";}</style><main><div>Y';
    assert_eq($content, InnerPagesDesignStep::balanceFragment($content));

    // Plain CSS without markup still salvages (the common, safe case).
    $plain = '<style data-page-css>.card{color:red}</style><main><div>Z';
    assert_eq(
        '<style data-page-css>.card{color:red}</style><main><div>Z</div></main>',
        InnerPagesDesignStep::balanceFragment($plain),
    );
});

test('inner-pages-design continues a truncated repair instead of masking it by balancing', function () {
    [$project, $llm, $tmp] = inner_pages_fixture([
        inner_page('home', 'Home', 'Welcome'),
        inner_page('repair', 'Repair', 'Recover truncated tail'),
    ]);
    $llm->queueText(inner_pages_home_body());
    $llm->queueText('<section>Initial response has no main</section>');
    // Cleanly truncated (unclosed <main>, max_tokens): balancing must NOT be
    // used to declare it closed, or the authored tail is dropped.
    $llm->queueText(
        '<main><section id="a">A</section><section id="b">B',
        'max_tokens',
    );
    $llm->queueText('</section></main>', 'stop');
    $llm->queueText('CONTINUATION-QUEUE-SENTINEL');

    inner_pages_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'truncated repair must fetch its continuation');
    assert_eq(
        '<main><section id="a">A</section><section id="b">B</section></main>',
        $project->readText('design/repair.html'),
    );
    assert_true(!$project->exists('design/repair.failed'));
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
