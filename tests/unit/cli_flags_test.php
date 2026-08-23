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
 * which is how a flag gets tested against a hostile SITE_BUILD_HTML_FIRST.
 *
 * @param list<string>          $args
 * @param array<string, string> $env
 * @return list<string>
 */
function build_cli_graph_output(array $args, array $env = []): array
{
    // A stub key keeps resolve_llm() from exiting before the validator is reached.
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
    return $output;
}

function build_cli_graph_ids(array $args, array $env = []): array
{
    $output = build_cli_graph_output($args, $env);
    $header = "Unknown --until step '__no-such-step__'. Valid steps:";
    $headerIndex = array_search($header, $output, true);
    assert_true($headerIndex !== false, implode("\n", $output));

    return array_values(array_map('trim', array_slice($output, $headerIndex + 1)));
}

test('X-G18 transport audit appears exactly once before step validation output', function () {
    $output = build_cli_graph_output(['--html-first']);
    $header = "Unknown --until step '__no-such-step__'. Valid steps:";
    $headerIndex = array_search($header, $output, true);
    assert_true($headerIndex !== false, implode("\n", $output));

    $auditIndexes = [];
    foreach ($output as $index => $line) {
        if (str_starts_with($line, 'Transport: ')) {
            $auditIndexes[] = $index;
        }
    }
    assert_eq(1, count($auditIndexes), implode("\n", $output));
    assert_true($auditIndexes[0] < $headerIndex, implode("\n", $output));
});

test('X-G5 --transport resolves a fake harness without a prompt or subprocess spawn', function () {
    with_temp_dir('transport-flag-', function (string $dir): void {
        $binary = $dir . '/claude';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
        $command = 'SITE_BUILD_LLM=claude-cli LLM_PROVIDER=anthropic PATH=' . escapeshellarg($path) . ' '
            . php_child_command(repo_path('bin/build.php'), ['--transport']);

        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        assert_eq(0, $exit, $text);
        assert_eq(1, count(array_filter($output, static fn (string $line): bool => str_starts_with(
            $line,
            'Transport: claude-cli (subscription)',
        ))), $text);
        assert_true(!file_exists($binary . '.count'), 'the fake harness must never be spawned');
    });
});

test('X-G6 --transport reports an unavailable transport and exits non-zero', function () {
    with_temp_dir('transport-unavailable-', function (string $dir): void {
        $command = 'SITE_BUILD_LLM=codex-cli LLM_PROVIDER=openai PATH=' . escapeshellarg($dir) . ' '
            . php_child_command(repo_path('bin/build.php'), ['--transport']);

        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        assert_true($exit !== 0, $text);
        assert_contains("SITE_BUILD_LLM=codex-cli but 'codex' is not on PATH.", $text);
    });
});

test('X-G13 build CLI usage lists the transport and graph-selection flags', function () {
    $output = [];
    $exit = 0;
    exec(php_child_command(repo_path('bin/build.php')) . ' 2>&1', $output, $exit);
    $text = implode("\n", $output);

    assert_eq(1, $exit, $text);
    assert_contains('Usage: php bin/build.php', $text);
    assert_contains('[--transport]', $text);
    assert_contains('[--html-first|--blocks-first]', $text);
});

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

test('--blocks-first overrides SITE_BUILD_HTML_FIRST=1', function () {
    $ids = build_cli_graph_ids(['--blocks-first'], ['SITE_BUILD_HTML_FIRST' => '1']);
    $seen = implode(',', $ids);

    assert_true(in_array('sections', $ids, true), $seen);
    assert_true(!in_array('transform-site', $ids, true), $seen);
});

test('--html-first overrides SITE_BUILD_HTML_FIRST=0', function () {
    $ids = build_cli_graph_ids(['--html-first'], ['SITE_BUILD_HTML_FIRST' => '0']);
    $seen = implode(',', $ids);

    assert_true(in_array('transform-site', $ids, true), $seen);
    assert_true(!in_array('sections', $ids, true), $seen);
});

test('without either flag SITE_BUILD_HTML_FIRST still picks the graph', function () {
    $on = build_cli_graph_ids([], ['SITE_BUILD_HTML_FIRST' => '1']);
    assert_true(in_array('transform-site', $on, true), implode(',', $on));

    $off = build_cli_graph_ids([], ['SITE_BUILD_HTML_FIRST' => '0']);
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
        '--html-first and --blocks-first are mutually exclusive; pass one.',
        implode("\n", $output)
    );
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
            ['SITE_BUILD_HTML_FIRST' => '0']
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
            ['SITE_BUILD_HTML_FIRST' => '0']
        );
        $seen = implode(',', $ids);
        assert_true(in_array('transform-site', $ids, true), $seen);
        assert_true(!in_array('sections', $ids, true), $seen);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
});
