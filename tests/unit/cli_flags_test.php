<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Tests\FakeLlm;

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
        $auditLines = array_values(array_filter(
            $output,
            static fn (string $line): bool => str_starts_with($line, 'Transport: '),
        ));
        assert_eq(1, count($auditLines), $text);
        assert_contains('claude-cli', $auditLines[0]);
        assert_contains('(subscription)', $auditLines[0]);
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
    assert_contains('[--list-steps]', $text);
    assert_contains('[--step=step-id]', $text);
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

/** @return array{graph:string,steps:list<array{id:string,label:string,members:list<string>}>} */
function stage5_list_steps(array $args): array
{
    return with_temp_dir('list-steps-harness-', function (string $dir) use ($args): array {
        $binary = $dir . '/claude';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        $stderr = $dir . '/stderr.log';
        $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
        $command = 'SITE_BUILD_LLM=claude-cli LLM_PROVIDER=anthropic PATH=' . escapeshellarg($path) . ' '
            . php_child_command(repo_path('bin/build.php'), array_merge(['--list-steps'], $args))
            . ' 2>' . escapeshellarg($stderr);

        $stdout = [];
        $exit = 0;
        exec($command, $stdout, $exit);
        $raw = implode("\n", $stdout);
        $errors = is_file($stderr) ? (string) file_get_contents($stderr) : '';

        assert_eq(0, $exit, $errors . $raw);
        assert_true(!file_exists($binary . '.count'), 'step enumeration must not spawn the harness');
        $decoded = json_decode($raw, true);
        assert_true(is_array($decoded), 'stdout must contain only valid JSON: ' . $raw);
        assert_true(isset($decoded['graph']) && is_string($decoded['graph']), $raw);
        assert_true(isset($decoded['steps']) && is_array($decoded['steps']), $raw);
        return $decoded;
    });
}

/** @return list<string> */
function stage5_expected_step_ids(bool $htmlFirst): array
{
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(Package::promptsDir());
    $composition = $htmlFirst
        ? StepComposition::htmlFirst($llm, $renderer)
        : StepComposition::blocks($llm, $renderer);
    return (new Pipeline($composition->steps(), $composition->seeds()))->stepIds();
}

/** @return list<string> */
function stage5_expected_stop_ids(bool $htmlFirst): array
{
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(Package::promptsDir());
    $composition = $htmlFirst
        ? StepComposition::htmlFirst($llm, $renderer)
        : StepComposition::blocks($llm, $renderer);
    return (new Pipeline($composition->steps(), $composition->seeds()))->stopIds();
}

/** @return list<string> */
function stage5_valid_ids_for(string $flag): array
{
    $unknown = '__stage5_unknown_step__';
    $slug = 'zz-stage5-valid-' . $flag . '-' . getmypid() . '-' . uniqid();
    $projectDir = repo_path('projects/' . $slug);
    if ($flag !== 'until') {
        mkdir($projectDir, 0775, true);
        file_put_contents($projectDir . '/meta.json', (string) json_encode([
            'prompt'           => 'a test site',
            'provisional_slug' => $slug,
            'multi_page'       => false,
            'graph'            => 'blocks',
        ]));
    }

    try {
        return with_temp_dir('valid-steps-harness-', function (string $dir) use ($flag, $unknown, $slug): array {
            $binary = $dir . '/claude';
            assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
            assert_true(chmod($binary, 0755));
            $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
            $args = ['a test site', '--provider=anthropic', '--blocks-first', '--no-serve'];
            if ($flag !== 'until') {
                $args[] = '--slug=' . $slug;
            }
            $args[] = '--' . $flag . '=' . $unknown;
            $command = 'SITE_BUILD_LLM=claude-cli LLM_PROVIDER=anthropic PATH=' . escapeshellarg($path) . ' '
                . php_child_command(repo_path('bin/build.php'), $args);

            $output = [];
            $exit = 0;
            exec($command . ' 2>&1', $output, $exit);
            $header = "Unknown --{$flag} step '{$unknown}'. Valid steps:";
            $headerIndex = array_search($header, $output, true);

            assert_eq(1, $exit, implode("\n", $output));
            assert_true($headerIndex !== false, implode("\n", $output));
            assert_true(!file_exists($binary . '.count'), 'validation must happen before step execution');
            return array_values(array_map('trim', array_slice($output, $headerIndex + 1)));
        });
    } finally {
        remove_tree($projectDir);
    }
}

/** @return array{exit:int,output:string} */
function stage5_run_build(array $args): array
{
    return with_temp_dir('stage5-build-harness-', function (string $dir) use ($args): array {
        $binary = $dir . '/claude';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));
        $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
        $command = 'SITE_BUILD_LLM=claude-cli LLM_PROVIDER=anthropic PATH=' . escapeshellarg($path) . ' '
            . php_child_command(repo_path('bin/build.php'), $args);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        assert_true(!file_exists($binary . '.count'), 'deterministic test steps must not spawn the harness');
        return ['exit' => $exit, 'output' => implode("\n", $output)];
    });
}

/** @return array{exit:int,output:string,model_calls:int,step_count:int} */
function stage6_run_build_without_image_token(array $args): array
{
    return with_temp_dir('stage6-no-image-token-', function (string $dir) use ($args): array {
        $binary = $dir . '/claude';
        assert_true(copy(dirname(__DIR__) . '/fixtures/fake-harness/spawn-counter.sh', $binary));
        assert_true(chmod($binary, 0755));

        // bootstrap.php loads the repository .env. Run through a tiny child
        // wrapper that removes only the image token from both environment
        // sources, so this test remains valid on provisioned checkouts.
        $wrapper = $dir . '/without-image-token.php';
        $bootstrap = var_export(repo_path('src/bootstrap.php'), true);
        $build = var_export(repo_path('bin/build.php'), true);
        file_put_contents($wrapper, <<<PHP
<?php
require_once {$bootstrap};
putenv('GOOGLE_VERTEX_API_TOKEN');
\$env = new ReflectionClass(Automattic\\SiteBuild\\Env::class);
\$vars = \$env->getProperty('vars');
\$vars->setAccessible(true);
\$loaded = \$vars->getValue();
unset(\$loaded['GOOGLE_VERTEX_API_TOKEN']);
\$vars->setValue(null, \$loaded);
// build.php runs its CLI body only when it is the entry script. This wrapper
// exists to run exactly that body with a doctored env, so it says so.
\$_SERVER['SCRIPT_FILENAME'] = {$build};
require {$build};
PHP);

        $path = $dir . PATH_SEPARATOR . (string) getenv('PATH');
        $command = 'SITE_BUILD_LLM=claude-cli LLM_PROVIDER=anthropic PATH=' . escapeshellarg($path) . ' '
            . php_child_command($wrapper, $args);
        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);
        $counter = $binary . '.count';
        $modelCalls = is_file($counter)
            ? count(file($counter, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : 0;

        return [
            'exit' => $exit,
            'output' => $text,
            'model_calls' => $modelCalls,
            'step_count' => substr_count($text, '  → '),
        ];
    });
}

/** @return array<string,string> relative path => bytes */
function stage5_tree_snapshot(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);
    return $files;
}

test('O-G5 --list-steps emits JSON without a prompt, model call, or subprocess spawn', function () {
    $manifest = stage5_list_steps(['--blocks-first']);
    assert_eq('blocks', $manifest['graph']);
    assert_true($manifest['steps'] !== []);
});

test('O-G6 --list-steps emits the blocks graph top-level ids and one concurrent group', function () {
    $manifest = stage5_list_steps(['--blocks-first']);
    $ids = array_column($manifest['steps'], 'id');

    assert_eq(27, count($ids));
    assert_eq(stage5_expected_step_ids(false), $ids);
    assert_eq(1, count(array_keys($ids, 'theme-json+page-plan', true)));
    $group = $manifest['steps'][array_search('theme-json+page-plan', $ids, true)];
    assert_eq(['theme-json', 'page-plan'], $group['members']);
    assert_true(is_string($group['label']) && $group['label'] !== '');
    assert_true(!in_array('theme-json', $ids, true));
    assert_true(!in_array('page-plan', $ids, true));
});

test('O-G7 --list-steps honors both graph flags and matches each graph stepIds', function () {
    $html = stage5_list_steps(['--html-first']);
    $blocks = stage5_list_steps(['--blocks-first']);
    $htmlIds = array_column($html['steps'], 'id');
    $blocksIds = array_column($blocks['steps'], 'id');

    assert_eq('html-first', $html['graph']);
    assert_eq('blocks', $blocks['graph']);
    assert_eq(31, count($htmlIds));
    assert_eq(27, count($blocksIds));
    assert_eq(stage5_expected_step_ids(true), $htmlIds);
    assert_eq(stage5_expected_step_ids(false), $blocksIds);
    assert_true($htmlIds !== $blocksIds);
});

test('--list-steps uses an existing slug recorded graph without requiring a prompt', function () {
    $slug = 'zz-stage5-list-recorded-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    mkdir($dir, 0775, true);
    file_put_contents($dir . '/meta.json', (string) json_encode([
        'prompt'           => 'a recorded project',
        'provisional_slug' => $slug,
        'multi_page'       => false,
        'graph'            => 'html-first',
    ]));
    try {
        $manifest = stage5_list_steps(['--slug=' . $slug]);
        assert_eq('html-first', $manifest['graph']);
        assert_eq(stage5_expected_step_ids(true), array_column($manifest['steps'], 'id'));
    } finally {
        remove_tree($dir);
    }
});

test('O-G8 every listed id is accepted by --step, --from, and --until validation', function () {
    $listed = array_column(stage5_list_steps(['--blocks-first'])['steps'], 'id');
    foreach (['step', 'from', 'until'] as $flag) {
        $valid = stage5_valid_ids_for($flag);
        foreach ($listed as $id) {
            assert_true(in_array($id, $valid, true), "--{$flag} rejected listed id {$id}");
        }
    }
});

test('O-G8c widening preserves every old stopIds target for --from and --until', function () {
    $oldTargets = stage5_expected_stop_ids(false);
    foreach (['from', 'until'] as $flag) {
        $valid = stage5_valid_ids_for($flag);
        foreach ($oldTargets as $id) {
            assert_true(in_array($id, $valid, true), "--{$flag} lost old target {$id}");
        }
    }
});

test('O-G8b --step has the same deterministic outcome as matching --from and --until', function () {
    $slug = 'zz-stage5-step-sugar-' . getmypid() . '-' . uniqid();
    $dir = repo_path('projects/' . $slug);
    $seed = static function () use ($slug, $dir): void {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', (string) json_encode([
            'prompt'           => 'a test site',
            'provisional_slug' => $slug,
            'multi_page'       => false,
            'graph'            => 'blocks',
        ]));
    };

    try {
        $seed();
        $range = stage5_run_build([
            '--slug=' . $slug,
            '--from=scaffold-theme',
            '--until=scaffold-theme',
            '--blocks-first',
            '--no-serve',
        ]);
        assert_eq(0, $range['exit'], $range['output']);
        $rangeTheme = stage5_tree_snapshot($dir . '/theme');

        remove_tree($dir);
        $seed();
        $step = stage5_run_build([
            '--slug=' . $slug,
            '--step=scaffold-theme',
            '--blocks-first',
            '--no-serve',
        ]);
        assert_eq(0, $step['exit'], $step['output']);
        assert_eq($rangeTheme, stage5_tree_snapshot($dir . '/theme'));
    } finally {
        remove_tree($dir);
    }
});

test('O-G8b --step refuses combination with --from or --until', function () {
    foreach (['--from=scaffold-theme', '--until=scaffold-theme'] as $conflict) {
        $result = stage5_run_build(['--step=scaffold-theme', $conflict]);
        assert_true($result['exit'] !== 0, $result['output']);
        assert_contains('--step', $result['output']);
        assert_contains(strtok($conflict, '='), $result['output']);
        assert_contains('mutually exclusive', $result['output']);
    }
});

test('I-G7 --with-images without its credential fails before any model call or step', function () {
    $slug = 'zz-stage6-missing-image-token-' . getmypid() . '-' . uniqid();
    $projectDir = repo_path('projects/' . $slug);
    try {
        $result = stage6_run_build_without_image_token([
            'a test site',
            '--slug=' . $slug,
            '--with-images',
            '--blocks-first',
            '--no-serve',
        ]);

        assert_true($result['exit'] !== 0, $result['output']);
        assert_contains('Missing required env var: GOOGLE_VERTEX_API_TOKEN', $result['output']);
        assert_eq(0, $result['model_calls'], 'no harness model call ran');
        assert_eq(0, $result['step_count'], 'no pipeline or post-image step started');
        assert_true(!is_dir($projectDir), 'preflight did not create a project');
    } finally {
        remove_tree($projectDir);
    }
});

test('I-G8 --with-images refuses both bounded build forms', function () {
    $slug = 'zz-stage6-image-conflict-' . getmypid() . '-' . uniqid();
    $projectDir = repo_path('projects/' . $slug);
    try {
        foreach (['--step=scaffold-theme', '--until=scaffold-theme'] as $bounded) {
            $args = [
                'a test site',
                '--slug=' . $slug,
                '--with-images',
                $bounded,
                '--blocks-first',
                '--no-serve',
            ];
            $result = stage5_run_build($args);
            assert_true($result['exit'] !== 0, $result['output']);
            assert_contains('--with-images', $result['output']);
            assert_contains(strtok($bounded, '='), $result['output']);
            assert_contains('mutually exclusive', $result['output']);
        }
    } finally {
        remove_tree($projectDir);
    }
});

test('I-G10 multi-page metadata survives a later --step call', function () {
    $slug = 'zz-stage6-multi-page-resume-' . getmypid() . '-' . uniqid();
    $projectDir = repo_path('projects/' . $slug);
    try {
        $create = stage5_run_build([
            'a test site',
            '--slug=' . $slug,
            '--multi-page',
            '--until=scaffold-theme',
            '--blocks-first',
            '--no-serve',
        ]);
        assert_eq(0, $create['exit'], $create['output']);
        assert_eq(true, json_decode((string) file_get_contents($projectDir . '/meta.json'), true)['multi_page'] ?? null);

        $resume = stage5_run_build([
            '--slug=' . $slug,
            '--step=scaffold-plugin',
            '--blocks-first',
            '--no-serve',
        ]);
        assert_eq(0, $resume['exit'], $resume['output']);
        assert_eq(true, json_decode((string) file_get_contents($projectDir . '/meta.json'), true)['multi_page'] ?? null);
    } finally {
        remove_tree($projectDir);
    }
});
