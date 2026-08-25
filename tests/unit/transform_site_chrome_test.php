<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('transform-site missing chrome degrades when contact grounding empties the generated shell', function () {
    $tmp = sys_get_temp_dir() . '/builder_transform_chrome_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $siteSpec = [
        'name' => 'Northstar Studio',
        'language' => 'English',
        'pages' => [['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome']],
    ];
    $project->writeJson('siteSpec.json', $siteSpec);
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
    $project->writeJson('designDirection.json', test_design_direction());
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [],
    ]];
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group --><div class="wp-block-group">fake-footer@example.com</div><!-- /wp:group -->',
    );
    $step = new TransformSiteStep($llm, new PromptRenderer(repo_path('prompts')));
    $outputs = [];
    $fallbackCodes = [];
    $repairOutcomes = [];
    $warnings = [];
    $method = new ReflectionMethod($step, 'generateMissingChrome');
    $arguments = [
        $project,
        $pages,
        $siteSpec,
        ['footer'],
        ['footer'],
        &$outputs,
        &$fallbackCodes,
        &$repairOutcomes,
        &$warnings,
    ];
    $method->invokeArgs($step, $arguments);

    $footer = $outputs['theme/parts/footer.html'] ?? '';
    assert_contains('wp:site-title', $footer, 'empty grounded shell receives deterministic chrome');
    assert_true(!str_contains($footer, 'fake-footer@example.com'));
    $warningText = implode("\n", array_values(array_filter($warnings, 'is_string')));
    assert_contains('fake-footer@example.com', $warningText);
    assert_contains('deterministic minimal shell delivered', $warningText);
    assert_eq(count($warnings), count(array_filter($warnings, 'is_string')), 'warnings.json rows remain strings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('transform-site scrubs every transformed output at its delivery boundary', function () {
    $safe = '<!-- wp:paragraph --><p>Safe sibling.</p><!-- /wp:paragraph -->';
    $invented = '<!-- wp:paragraph --><p>Visit us in Boston or email fake@example.com.</p>'
        . '<!-- /wp:paragraph -->';
    $outputs = [
        'theme/parts/page-home--safe.html' => $safe . $invented,
        'theme/parts/footer.html' => '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>Call 2075550199</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->',
    ];
    $fragments = [
        'page:home:safe' => [
            'source' => 'design/home.html',
            'selector' => 'main > section:nth-of-type(1)',
            'output' => 'theme/parts/page-home--safe.html',
        ],
        'chrome:footer' => [
            'source' => 'design/home.html',
            'selector' => 'footer:nth-of-type(1)',
            'output' => 'theme/parts/footer.html',
        ],
    ];
    $dropped = [];
    $fallbackCodes = [];
    $repairOutcomes = [];
    $droppedFragments = [];
    $warnings = [];
    $method = new ReflectionMethod(TransformSiteStep::class, 'scrubUngroundedContactOutputs');
    $arguments = [
        &$outputs,
        [],
        $fragments,
        &$dropped,
        &$fallbackCodes,
        &$repairOutcomes,
        &$droppedFragments,
        &$warnings,
    ];
    $method->invokeArgs(null, $arguments);

    assert_eq($safe, $outputs['theme/parts/page-home--safe.html']);
    assert_true(!isset($outputs['theme/parts/footer.html']), 'an output emptied by grounding is regenerated later');
    $warningText = implode("\n", $warnings);
    foreach (['Boston', 'fake@example.com', '2075550199'] as $authored) {
        assert_contains($authored, $warningText);
    }
    assert_eq(['chrome:footer' => true], $dropped);
    assert_eq(['ungrounded_contact_fact'], $fallbackCodes);
    assert_eq('ungrounded_contact_fact', $repairOutcomes[0]['diagnostic_code'] ?? null);
    assert_eq($repairOutcomes, $droppedFragments, 'the transform report keeps the exact contact drop');
});

test('transform-site reconciles all generated page parts against final delivery', function () {
    $tmp = sys_get_temp_dir() . '/builder_transform_stale_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $stale = 'theme/parts/page-home--about.html';
    $survivor = 'theme/parts/page-home--safe.html';
    $project->writeText($stale, '<!-- wp:paragraph --><p>fake@example.com</p><!-- /wp:paragraph -->');
    $project->writeText($survivor, '<!-- wp:paragraph --><p>old safe bytes</p><!-- /wp:paragraph -->');
    $project->writeText(
        'theme/parts/page-home--legacy.html',
        '<!-- wp:paragraph --><p>orphan fake@example.com</p><!-- /wp:paragraph -->',
    );
    $pages = [[
        'slug' => 'home',
        'sections' => [['slug' => 'safe']],
    ]];

    $method = new ReflectionMethod(\Automattic\SiteBuild\Steps\SectionsStep::class, 'reconcilePagePartFiles');
    $method->invoke(null, $project, $pages);

    assert_true(!$project->exists($stale), 'the exact stale dropped artifact is removed');
    assert_true(!$project->exists('theme/parts/page-home--legacy.html'), 'an unplanned orphan is also removed');
    assert_contains('old safe bytes', $project->readText($survivor), 'a delivered sibling is not touched');
    $quarantined = glob($project->logPath('stale-parts') . '/*.stale*') ?: [];
    assert_eq(2, count($quarantined), 'removed delivery artifacts remain recoverable outside the theme');
    assert_contains('fake@example.com', implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        $quarantined,
    )));
    exec('rm -rf ' . escapeshellarg($tmp));
});
