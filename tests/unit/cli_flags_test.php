<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;

test('split_csv_flag keeps a single value', function () {
    assert_eq(['Home'], split_csv_flag('Home'));
});

test('split_csv_flag trims the spacing around each item', function () {
    assert_eq(
        ['Home', 'Menu', 'About Us'],
        split_csv_flag('  Home ,Menu ,  About Us  ')
    );
});

test('split_csv_flag drops the blanks left by stray commas', function () {
    assert_eq(['Home', 'Menu'], split_csv_flag('Home, Menu,'));
    assert_eq(['Home', 'Menu'], split_csv_flag(',Home,, ,Menu, '));
});

test('split_csv_flag returns a list, so the first --pages title is always the homepage', function () {
    $pages = split_csv_flag(', ,Menu,About');
    assert_eq('Menu', $pages[0]);
    assert_eq([0, 1], array_keys($pages));
});

test('split_csv_flag gives an empty list when every item is blank', function () {
    assert_eq([], split_csv_flag(' , , '));
    assert_eq([], split_csv_flag(''));
});

test('require_multi_page_for_pages accepts a page list alongside --multi-page', function () {
    require_multi_page_for_pages('Home, Menu', true);
    require_multi_page_for_pages(null, true);
    require_multi_page_for_pages(null, false);
    assert_true(true, 'no contradiction to report');
});

test('require_multi_page_for_pages rejects a page list without --multi-page', function () {
    $e = assert_throws(static fn () => require_multi_page_for_pages('Home, Menu', false));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    // The CLI prints the message verbatim, so it is the user-facing error text.
    assert_eq('--pages requires --multi-page.', $e->getMessage());
});

test('require_multi_page_for_pages rejects even an all-blank page list without --multi-page', function () {
    // The contradiction is in the flags, not in what the list happens to hold.
    assert_throws(static fn () => require_multi_page_for_pages(' , ', false));
});

test('normalize_provider passes null through when the flag is absent', function () {
    assert_eq(null, normalize_provider(null));
});

test('normalize_provider lowercases and trims a configured provider', function () {
    assert_eq('anthropic', normalize_provider('  Anthropic '));
});

test('normalize_provider accepts every provider config/models.json declares', function () {
    foreach (ModelConfig::providerNames() as $name) {
        assert_eq($name, normalize_provider($name));
    }
});

test('normalize_provider rejects an unknown provider and lists the known ones', function () {
    $e = assert_throws(static fn () => normalize_provider('Gemini'));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    assert_eq(
        "Unknown --provider 'gemini'. Known: " . implode(', ', ModelConfig::providerNames()),
        $e->getMessage()
    );
});

test('normalize_provider rejects an empty provider rather than silently ignoring it', function () {
    assert_throws(static fn () => normalize_provider(''));
    assert_throws(static fn () => normalize_provider('   '));
});

test('build CLI preserves design-constraint error precedence over provider validation', function () {
    $command = php_child_command(repo_path('bin/build.php'), [
        'demo',
        '--provider=not-configured',
        '--max-hero-images=not-an-integer',
        '--no-serve',
    ]);
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    assert_eq(1, $exit);
    assert_eq('--max-hero-images must be an integer from 1 through 2.', implode("\n", $output));
});

/**
 * The step ids the build CLI would have run, read back from --until's
 * validator. That list IS the selected graph, so it distinguishes the two
 * paths instead of merely proving a flag parsed. $env prefixes the child,
 * which is how a flag gets tested against a hostile SITE_BUILD_GRAPH.
 *
 * @param list<string>          $args
 * @param array<string, string> $env
 * @return list<string>
 */
function build_cli_graph_ids(array $args, array $env = []): array
{
    // A stub key keeps make_llm() from exiting before the validator is reached.
    // The run stops on the unknown --until id, so nothing is ever sent.
    $env += ['ANTHROPIC_API_KEY' => 'test-key'];
    $prefix = '';
    foreach ($env as $key => $value) {
        $prefix .= $key . '=' . escapeshellarg($value) . ' ';
    }
    $command = $prefix . php_child_command(repo_path('bin/build.php'), array_merge(
        ['demo', '--provider=anthropic', '--no-serve', '--until=__no-such-step__'],
        $args,
    ));

    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    assert_eq(1, $exit, implode("\n", $output));
    assert_eq("Unknown --until step '__no-such-step__'. Valid steps:", $output[0] ?? '');

    return array_values(array_map('trim', array_slice($output, 1)));
}

test('--html-first runs the build on the HTML-first graph', function () {
    $ids = build_cli_graph_ids(['--html-first']);
    $seen = implode(',', $ids);

    assert_true(in_array('design-preview', $ids, true), $seen);
    assert_true(in_array('transform-site', $ids, true), $seen);
    assert_true(!in_array('sections', $ids, true), $seen);
});

test('--blocks-first runs the build on the blocks graph', function () {
    $ids = build_cli_graph_ids(['--blocks-first']);
    $seen = implode(',', $ids);

    assert_true(in_array('sections', $ids, true), $seen);
    assert_true(!in_array('design-preview', $ids, true), $seen);
    assert_true(!in_array('transform-site', $ids, true), $seen);
});

test('--blocks-first overrides SITE_BUILD_GRAPH=html-first', function () {
    $ids = build_cli_graph_ids(['--blocks-first'], ['SITE_BUILD_GRAPH' => 'html-first']);
    $seen = implode(',', $ids);

    assert_true(in_array('sections', $ids, true), $seen);
    assert_true(!in_array('transform-site', $ids, true), $seen);
});

test('--html-first overrides SITE_BUILD_GRAPH=blocks', function () {
    $ids = build_cli_graph_ids(['--html-first'], ['SITE_BUILD_GRAPH' => 'blocks']);
    $seen = implode(',', $ids);

    assert_true(in_array('transform-site', $ids, true), $seen);
    assert_true(!in_array('sections', $ids, true), $seen);
});

test('without a graph flag SITE_BUILD_GRAPH still picks the graph', function () {
    $on = build_cli_graph_ids([], ['SITE_BUILD_GRAPH' => 'html-first']);
    assert_true(in_array('transform-site', $on, true), implode(',', $on));

    $off = build_cli_graph_ids([], ['SITE_BUILD_GRAPH' => 'blocks']);
    assert_true(in_array('sections', $off, true), implode(',', $off));
});

test('--html-first and --blocks-first together are refused', function () {
    $command = php_child_command(repo_path('bin/build.php'), [
        'demo',
        '--html-first',
        '--blocks-first',
        '--no-serve',
    ]);
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    assert_eq(1, $exit);
    assert_eq(
        '--html-first, --blocks-first, and --html-islands are mutually exclusive; pass one.',
        implode("\n", $output)
    );
});

test('--html-islands with another graph flag is refused', function () {
    $command = php_child_command(repo_path('bin/build.php'), [
        'demo',
        '--html-first',
        '--html-islands',
        '--no-serve',
    ]);
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);

    assert_eq(1, $exit);
    assert_eq(
        '--html-first, --blocks-first, and --html-islands are mutually exclusive; pass one.',
        implode("\n", $output)
    );
});

test('leftover SITE_BUILD_HTML_FIRST without SITE_BUILD_GRAPH is refused', function () {
    $command = 'SITE_BUILD_HTML_FIRST=' . escapeshellarg('1')
        . ' SITE_BUILD_GRAPH=' . escapeshellarg('')
        . ' ANTHROPIC_API_KEY=' . escapeshellarg('test-key') . ' '
        . php_child_command(repo_path('bin/build.php'), [
            'demo',
            '--provider=anthropic',
            '--no-serve',
        ]);
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);
    $text = implode("\n", $output);

    assert_eq(1, $exit, $text);
    assert_true(str_contains($text, 'SITE_BUILD_HTML_FIRST'), $text);
    assert_true(str_contains($text, 'SITE_BUILD_GRAPH'), $text);
});

test('--html-islands is refused as not yet implemented', function () {
    $command = 'ANTHROPIC_API_KEY=' . escapeshellarg('test-key') . ' '
        . php_child_command(repo_path('bin/build.php'), [
            'demo',
            '--provider=anthropic',
            '--html-islands',
            '--no-serve',
        ]);
    $output = [];
    $exit = 0;
    exec($command . ' 2>&1', $output, $exit);
    $text = implode("\n", $output);

    assert_eq(1, $exit, $text);
    assert_true(str_contains($text, 'html-islands graph is not yet implemented'), $text);
});

test('a --from resume runs the graph meta.json recorded, not the ambient one', function () {
    // The rule itself is unit-tested on StepComposition; this covers the wiring,
    // which is where deleting one putenv would leave every other gate green.
    $slug = 'zz-resume-graph-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/meta.json', (string) json_encode([
        'prompt'           => 'a cozy neighborhood bakery',
        'provisional_slug' => $slug,
        'multi_page'       => false,
        'graph'            => 'html-first',
    ]));

    try {
        // No flag, and the env explicitly names the OTHER graph: the record wins.
        $ids = build_cli_graph_ids(
            ['--slug=' . $slug, '--from=transform-site'],
            ['SITE_BUILD_GRAPH' => 'blocks']
        );
        $seen = implode(',', $ids);
        assert_true(in_array('transform-site', $ids, true), $seen);
        assert_true(!in_array('sections', $ids, true), $seen);

        // A flag contradicting the record is refused, not honored: section-rhythm
        // exists in both graphs, so nothing else would catch the crossed resume.
        $command = 'ANTHROPIC_API_KEY=' . escapeshellarg('test-key') . ' '
            . php_child_command(repo_path('bin/build.php'), [
                'demo',
                '--provider=anthropic',
                '--no-serve',
                '--slug=' . $slug,
                '--from=section-rhythm',
                '--blocks-first',
            ]);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        assert_eq(1, $exit, $text);
        assert_true(str_contains($text, 'built on the html-first graph'), $text);
        assert_true(str_contains($text, 'blocks-first was passed'), $text);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('a --from resume on a blocks project ignores a hostile html-first env', function () {
    $slug = 'zz-resume-blocks-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/meta.json', (string) json_encode([
        'prompt'           => 'a cozy neighborhood bakery',
        'provisional_slug' => $slug,
        'multi_page'       => false,
        'graph'            => 'blocks',
    ]));

    try {
        $ids = build_cli_graph_ids(
            ['--slug=' . $slug, '--from=sections'],
            ['SITE_BUILD_GRAPH' => 'html-first']
        );
        $seen = implode(',', $ids);
        assert_true(in_array('sections', $ids, true), $seen);
        assert_true(!in_array('transform-site', $ids, true), $seen);
        assert_true(!in_array('design-preview', $ids, true), $seen);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('a --from resume of an unknown recorded graph names it and refuses', function () {
    $slug = 'zz-resume-unknown-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/meta.json', (string) json_encode([
        'prompt'           => 'a cozy neighborhood bakery',
        'provisional_slug' => $slug,
        'multi_page'       => false,
        'graph'            => 'retired-graph',
    ]));

    try {
        $command = 'ANTHROPIC_API_KEY=' . escapeshellarg('test-key') . ' '
            . php_child_command(repo_path('bin/build.php'), [
                'demo',
                '--provider=anthropic',
                '--no-serve',
                '--slug=' . $slug,
                '--from=section-rhythm',
            ]);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        assert_eq(1, $exit, $text);
        assert_true(str_contains($text, 'retired-graph'), $text);
        assert_true(str_contains($text, 'does not recognize'), $text);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('a --from resume on a project with no recorded graph still honors the flag', function () {
    // Projects created before builds recorded the graph must keep resuming.
    $slug = 'zz-resume-unrecorded-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/meta.json', (string) json_encode([
        'prompt'           => 'a cozy neighborhood bakery',
        'provisional_slug' => $slug,
        'multi_page'       => false,
    ]));

    try {
        $ids = build_cli_graph_ids(
            ['--slug=' . $slug, '--from=transform-site', '--html-first'],
            ['SITE_BUILD_GRAPH' => 'blocks']
        );
        $seen = implode(',', $ids);
        assert_true(in_array('transform-site', $ids, true), $seen);
        assert_true(!in_array('sections', $ids, true), $seen);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});
