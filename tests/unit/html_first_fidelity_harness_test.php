<?php
declare(strict_types=1);

use Automattic\SiteBuild\Verification\HtmlFirstFidelityReport;
use Automattic\SiteBuild\Verification\HtmlFirstFidelityRunner;
use Automattic\SiteBuild\Verification\HtmlFirstFidelityPublisher;
use Automattic\SiteBuild\Verification\HtmlFirstFidelityFrozenGitTree;
use Automattic\SiteBuild\Narrator;

require_once __DIR__ . '/../../bin/html-first-fidelity/Harness.php';

test('html-first fidelity harness freezes exact projects and one re-run command', function () {
    assert_eq('php bin/html-first-fidelity.php', HtmlFirstFidelityReport::RERUN_COMMAND);
    assert_eq([
        'silver-summit',
        'swift-grove',
        'sunny-ember',
        'calm-lantern',
        'azure-garden',
        'amber-ember',
    ], HtmlFirstFidelityReport::SLUGS);
    assert_eq(
        '5d1b8bf549000334778648c1dc7ec543d640c963',
        HtmlFirstFidelityRunner::TREATMENT_TRANSFORMER_COMMIT,
    );
    assert_eq(
        '9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290',
        HtmlFirstFidelityRunner::TREATMENT_TRANSFORMER_TREE_SHA256,
    );
    assert_eq(
        '/Users/matt/projects/a8c/blocks-engine-wt-support-css/php-transformer'
            . '@5d1b8bf549000334778648c1dc7ec543d640c963'
            . '#tree-sha256=9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290',
        HtmlFirstFidelityRunner::TREATMENT_TRANSFORMER_REFERENCE,
    );
    assert_eq([
        'transformer_label' => 'frozen source archive',
        'transformer_reference' => '/Users/matt/projects/a8c/blocks-engine-wt-support-css/php-transformer'
            . '@5d1b8bf549000334778648c1dc7ec543d640c963'
            . '#tree-sha256=9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290',
    ], HtmlFirstFidelityRunner::treatmentTransformerProvenance([
        'commit' => '5d1b8bf549000334778648c1dc7ec543d640c963',
        'tree_sha256' => '9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290',
        'reference' => '/Users/matt/projects/a8c/blocks-engine-wt-support-css/php-transformer'
            . '@5d1b8bf549000334778648c1dc7ec543d640c963'
            . '#tree-sha256=9c69c95f77b1edf6ec9b3b68b78d35045f27cb6e2f1f6ac9064e3fbf98313290',
    ]));
});

test('html-first fidelity implementation matches frozen report schema enums', function () {
    $schema = json_decode(
        (string) file_get_contents(__DIR__ . '/../../schemas/html-first-fidelity-report.schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    assert_eq(HtmlFirstFidelityReport::RERUN_COMMAND, $schema['properties']['rerun_command']['const']);
    assert_eq(HtmlFirstFidelityReport::SLUGS, $schema['$defs']['project']['properties']['slug']['enum']);
    assert_eq(HtmlFirstFidelityReport::ENGINE_MARKERS, $schema['$defs']['engineMarkers']['propertyNames']['enum']);
});

test('html-first fidelity design hashes cover only direct HTML and site CSS inputs', function () {
    with_temp_dir('html-fidelity-hashes-', function (string $dir) {
        mkdir($dir . '/design');
        file_put_contents($dir . '/design/home.html', '<main>Home</main>');
        file_put_contents($dir . '/design/about.html', '<main>About</main>');
        file_put_contents($dir . '/design/site.css', 'main{display:block}');
        file_put_contents($dir . '/design/ignored.json', '{}');
        mkdir($dir . '/design/nested');
        file_put_contents($dir . '/design/nested/ignored.html', '<p>nested</p>');

        $hashes = HtmlFirstFidelityReport::designHashes($dir);
        assert_eq(['design/about.html', 'design/home.html', 'design/site.css'], array_keys($hashes));
        assert_eq(hash('sha256', '<main>Home</main>'), $hashes['design/home.html']);
    });
});

test('html-first fidelity measurements implement frozen markup and layout metrics', function () {
    $markup = <<<'HTML'
<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->
<div class="wp-block-buttons has-custom-gap"></div>
<div class="wp-block-buttons"><a>kept</a></div>
<mark>missing style</mark>
<mark style="color:red">missing property</mark>
<mark style="color:red;background-color: transparent">carried transparent</mark>
<!-- wp:group {"align":"wide"} --><div class="blocks-engine-control blocks-engine-css-owned-layout"></div>
<div class='blocks-engine-control-deadbeef0000-1 blocks-engine-css-owned-layout marker-suffix'></div>
<div class='blocks-engine-control-cafebabe0000-2'></div>
<mark style="background-color:transparent;--blocks-engine-richtext-marker:blocks-engine-richtext-deadbeef0000-4">matched marker</mark>
<span data-blocks-engine-richtext-marker="blocks-engine-richtext-cafebabe0000-5">unmatched marker</span>
HTML;
    $css = <<<'CSS'
/* .blocks-engine-css-owned-layout { fake: comment } */
@media (min-width: 40rem) {
  .blocks-engine-control:hover, .blocks-engine-control-deadbeef0000-1:focus, .other { display: flex; }
}
.fake { content: ".blocks-engine-css-owned-layout"; }
:where(mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-deadbeef0000-4"],span[data-blocks-engine-richtext-marker="blocks-engine-richtext-deadbeef0000-4"]){color:red}
CSS;
    $metrics = HtmlFirstFidelityReport::measureBytes($markup, $css, [
        'settings' => ['layout' => ['contentSize' => '720px', 'wideSize' => '1200']],
    ]);

    assert_eq(2, $metrics['empty_buttons']);
    assert_eq(2, $metrics['marks_without_background_color']);
    assert_eq(1, $metrics['align_wide']);
    assert_eq(['value' => '720px', 'unitless' => false], $metrics['layout']['content_size']);
    assert_eq(['value' => '1200', 'unitless' => true], $metrics['layout']['wide_size']);
    assert_eq([
        'blocks-engine-css-owned-layout' => 2,
        'blocks-engine-control' => 1,
        'richtext-marker' => 1,
    ], $metrics['unmatched_engine_markers']);
    assert_eq(4, $metrics['unmatched_engine_marker_occurrences']);
});

test('html-first fidelity CSS selector match ignores comments strings at-rules and class prefixes', function () {
    assert_true(HtmlFirstFidelityReport::cssHasClassSelector(
        '@media screen { .richtext-marker[data-x="{"] { color: red; } }',
        'richtext-marker',
    ));
    assert_true(!HtmlFirstFidelityReport::cssHasClassSelector(
        '/* .richtext-marker{} */ @supports selector(.richtext-marker) { .other { content: ".richtext-marker"; } } .richtext-marker-more{}',
        'richtext-marker',
    ));
});

test('html-first fidelity CSS selector scan resets after semicolon at-rules', function () {
    $control = 'blocks-engine-control-abc123def456-7';
    $richtext = 'blocks-engine-richtext-abc123def456-9';
    foreach ([
        '@charset "UTF-8";',
        '@import url("base.css");',
        '@layer reset;',
    ] as $atRule) {
        $css = $atRule
            . '.' . $control . ':hover{color:red}'
            . ':where(mark[style*="--blocks-engine-richtext-marker:' . $richtext . '"]){color:blue}';
        assert_true(HtmlFirstFidelityReport::cssHasClassSelector($css, $control), $atRule . ' must not hide control selector');
        assert_true(
            HtmlFirstFidelityReport::cssHasRichTextMarkerSelector($css, $richtext),
            $atRule . ' must not hide richtext selector',
        );
    }
});

test('html-first fidelity groups hashed controls and both richtext marker carriers', function () {
    $markup = <<<'HTML'
<div class="blocks-engine-control-abc123def456-7"></div>
<div class="blocks-engine-control-abc123def456-7 blocks-engine-control-missing00000-8"></div>
<mark style="color:red;--blocks-engine-richtext-marker:blocks-engine-richtext-abc123def456-9">one</mark>
<span data-blocks-engine-richtext-marker="blocks-engine-richtext-abc123def456-9">two</span>
<span data-blocks-engine-richtext-marker="blocks-engine-richtext-missing00000-10" style="--blocks-engine-richtext-marker:blocks-engine-richtext-missing00000-10">three</span>
HTML;
    $css = <<<'CSS'
.blocks-engine-control-abc123def456-7:hover{color:red}
:where(mark[style*="--blocks-engine-richtext-marker:blocks-engine-richtext-abc123def456-9"],span[data-blocks-engine-richtext-marker="blocks-engine-richtext-abc123def456-9"]){color:blue}
CSS;

    assert_eq([
        'blocks-engine-control' => 1,
        'richtext-marker' => 2,
    ], HtmlFirstFidelityReport::unmatchedEngineMarkers($markup, $css));
});

test('html-first fidelity delta uses treatment minus control', function () {
    $control = [
        'empty_buttons' => 6,
        'marks_without_background_color' => 5,
        'align_wide' => 4,
        'unmatched_engine_marker_occurrences' => 3,
    ];
    $treatment = [
        'empty_buttons' => 1,
        'marks_without_background_color' => 2,
        'align_wide' => 4,
        'unmatched_engine_marker_occurrences' => 8,
    ];
    assert_eq([
        'empty_buttons' => -5,
        'marks_without_background_color' => -3,
        'align_wide' => 0,
        'unmatched_engine_marker_occurrences' => 5,
    ], HtmlFirstFidelityReport::delta($control, $treatment));
});

test('html-first fidelity cleanup throws when preserved treatment project cannot be restored', function () {
    with_temp_dir('html-fidelity-cleanup-', function (string $dir) {
        $repo = $dir . '/repo';
        $temporary = $dir . '/run';
        mkdir($repo);
        mkdir($temporary);
        $backup = $temporary . '/preserved-project';
        mkdir($backup);
        file_put_contents($backup . '/proof.txt', 'preserved');

        $runner = new HtmlFirstFidelityRunner($repo);
        $properties = [
            'tempRoot' => $temporary,
            'treatmentProjectBackups' => ['silver-summit' => $backup],
        ];
        foreach ($properties as $name => $value) {
            $property = new ReflectionProperty($runner, $name);
            $property->setAccessible(true);
            $property->setValue($runner, $value);
        }

        Narrator::setEnabled(false);
        try {
            $error = assert_throws(fn () => $runner->cleanup());
        } finally {
            Narrator::reset();
        }
        assert_contains('restoration failed', $error->getMessage());
        assert_true(is_file($backup . '/proof.txt'), 'failed restore retains preserved backup bytes');
        assert_true(is_dir($temporary), 'failed restore retains backup root for recovery');
    });
});

test('html-first fidelity frozen Git archive ignores mutable live HEAD and worktree bytes', function () {
    with_temp_dir('html-fidelity-frozen-git-', function (string $dir) {
        $repository = $dir . '/overlay-repository';
        mkdir($repository);
        mkdir($repository . '/php-transformer');

        $run = static function (array $command, string $cwd): string {
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, $cwd);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start test Git command.');
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException('Test Git command failed: ' . trim((string) $stderr));
            }
            return trim((string) $stdout);
        };

        $run(['git', 'init', '--quiet'], $repository);
        $run(['git', 'config', 'user.name', 'Harness Test'], $repository);
        $run(['git', 'config', 'user.email', 'harness@example.com'], $repository);
        file_put_contents($repository . '/php-transformer/value.txt', 'frozen bytes');
        $run(['git', 'add', 'php-transformer/value.txt'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'frozen'], $repository);
        $frozenSha = $run(['git', 'rev-parse', 'HEAD'], $repository);

        file_put_contents($repository . '/php-transformer/value.txt', 'new HEAD bytes');
        $run(['git', 'add', 'php-transformer/value.txt'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'new head'], $repository);
        $mutableHead = $run(['git', 'rev-parse', 'HEAD'], $repository);
        file_put_contents($repository . '/php-transformer/value.txt', 'dirty live worktree bytes');

        $referencePath = $repository . '/php-transformer';
        $frozen = HtmlFirstFidelityFrozenGitTree::install(
            repository: $repository,
            commit: $frozenSha,
            subdirectory: 'php-transformer',
            workRoot: $dir . '/archive-work',
            target: $dir . '/installed-transformer',
            referencePath: $referencePath,
        );

        assert_true($mutableHead !== $frozenSha);
        assert_eq('dirty live worktree bytes', file_get_contents($repository . '/php-transformer/value.txt'));
        assert_eq('frozen bytes', file_get_contents($dir . '/installed-transformer/value.txt'));
        assert_eq($frozenSha, $frozen['commit']);
        assert_contains($referencePath . '@' . $frozenSha . '#tree-sha256=', $frozen['reference']);

        remove_tree($repository . '/php-transformer');
        assert_true(!file_exists($repository . '/php-transformer'));
        $withoutLiveSubtree = HtmlFirstFidelityFrozenGitTree::install(
            repository: $repository,
            commit: $frozenSha,
            subdirectory: 'php-transformer',
            workRoot: $dir . '/archive-without-live-subtree',
            target: $dir . '/installed-without-live-subtree',
            referencePath: $referencePath,
        );
        assert_eq('frozen bytes', file_get_contents($dir . '/installed-without-live-subtree/value.txt'));
        assert_eq($frozen, $withoutLiveSubtree);
        assert_throws(fn () => HtmlFirstFidelityFrozenGitTree::install(
            repository: $repository,
            commit: str_repeat('0', 40),
            subdirectory: 'php-transformer',
            workRoot: $dir . '/missing-work',
            target: $dir . '/missing-target',
            referencePath: $referencePath,
        ));
    });
});

test('html-first fidelity routes report gallery and all 18 shots into one sibling staging tree', function () {
    with_temp_dir('html-fidelity-paths-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        $publisher = new HtmlFirstFidelityPublisher();
        $staging = $publisher->createStaging($live, 'paths');
        try {
            assert_eq(dirname($live), dirname($staging));
            assert_true($live !== $staging);
            $shots = [];
            foreach (HtmlFirstFidelityReport::SLUGS as $slug) {
                $paths = HtmlFirstFidelityPublisher::artifactPaths($staging, $slug);
                assert_eq($staging . '/report.json', $paths['report']);
                assert_eq($staging . '/index.html', $paths['index']);
                foreach (['design', 'control', 'treatment'] as $side) {
                    assert_true(str_starts_with($paths[$side], $staging . '/shots/'));
                    $shots[] = $paths[$side];
                }
            }
            assert_eq(18, count(array_unique($shots)));
        } finally {
            $publisher->discard($staging);
        }
    });
});

test('html-first fidelity pre-publish failure discards staging and preserves live sentinel bytes', function () {
    with_temp_dir('html-fidelity-prepublish-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        mkdir($live);
        file_put_contents($live . '/sentinel.txt', "old-live\n");
        $before = hash_file('sha256', $live . '/sentinel.txt');

        $publisher = new HtmlFirstFidelityPublisher();
        $staging = $publisher->createStaging($live, 'failed-build');
        file_put_contents($staging . '/report.json', '{"partial":true}');
        $publisher->discard($staging); // Runner cleanup after any pre-publish build failure.

        assert_eq($before, hash_file('sha256', $live . '/sentinel.txt'));
        assert_true(!is_file($live . '/report.json'), 'partial report never enters live tree');
        assert_true(!file_exists($staging), 'failed generation staging tree removed');
    });
});

test('html-first fidelity existing publication invokes one swap and leaves no mixed generation', function () {
    with_temp_dir('html-fidelity-swap-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        mkdir($live);
        file_put_contents($live . '/old-only.txt', 'old');

        $swapCalls = 0;
        $renameCalls = 0;
        $publisher = new HtmlFirstFidelityPublisher(
            function (string $staging, string $destination) use (&$swapCalls): bool {
                $swapCalls++;
                $holding = dirname($staging) . '/swap-holding';
                return rename($staging, $holding)
                    && rename($destination, $staging)
                    && rename($holding, $destination);
            },
            function (string $from, string $to) use (&$renameCalls): bool {
                $renameCalls++;
                return rename($from, $to);
            },
        );
        $staging = $publisher->createStaging($live, 'complete');
        file_put_contents($staging . '/report.json', '{"generation":"new"}');
        file_put_contents($staging . '/index.html', '<p>new</p>');
        file_put_contents($staging . '/shots/complete.png', 'png');

        $publication = $publisher->publish($staging, $live);

        assert_eq(HtmlFirstFidelityPublisher::EXISTING_LIVE_SWAP, $publication);
        assert_eq(
            "Publication: existing-live renamex_np(staging, live, RENAME_SWAP)\n",
            HtmlFirstFidelityPublisher::publicationNarration($publication),
        );
        assert_eq(1, $swapCalls);
        assert_eq(0, $renameCalls);
        assert_eq('{"generation":"new"}', file_get_contents($live . '/report.json'));
        assert_eq('<p>new</p>', file_get_contents($live . '/index.html'));
        assert_eq('png', file_get_contents($live . '/shots/complete.png'));
        assert_true(!file_exists($live . '/old-only.txt'), 'old generation cannot mix with new tree');
        assert_true(!file_exists($staging), 'old live tree removed after exchange');
    });
});

test('html-first fidelity absent publication uses one rename and no swap', function () {
    with_temp_dir('html-fidelity-rename-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        $swapCalls = 0;
        $renameCalls = 0;
        $publisher = new HtmlFirstFidelityPublisher(
            function () use (&$swapCalls): bool {
                $swapCalls++;
                return false;
            },
            function (string $from, string $to) use (&$renameCalls): bool {
                $renameCalls++;
                return rename($from, $to);
            },
        );
        $staging = $publisher->createStaging($live, 'first');
        file_put_contents($staging . '/report.json', 'complete');

        $publication = $publisher->publish($staging, $live);

        assert_eq(HtmlFirstFidelityPublisher::ABSENT_LIVE_RENAME, $publication);
        assert_eq(
            "Publication: absent-live atomic rename\n",
            HtmlFirstFidelityPublisher::publicationNarration($publication),
        );
        assert_eq(0, $swapCalls);
        assert_eq(1, $renameCalls);
        assert_eq('complete', file_get_contents($live . '/report.json'));
        assert_true(!file_exists($staging));
    });
});

test('html-first fidelity failed exchange preserves old live tree and removes staging', function () {
    with_temp_dir('html-fidelity-swap-fail-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        mkdir($live);
        file_put_contents($live . '/sentinel.txt', 'unchanged');
        $publisher = new HtmlFirstFidelityPublisher(static fn (): bool => false);
        $staging = $publisher->createStaging($live, 'failed-swap');
        file_put_contents($staging . '/report.json', 'new-but-unpublished');

        assert_throws(fn () => $publisher->publish($staging, $live));

        assert_eq('unchanged', file_get_contents($live . '/sentinel.txt'));
        assert_true(!is_file($live . '/report.json'));
        assert_true(!file_exists($staging));
    });
});

test('html-first fidelity committed swap stays successful when old-tree deletion fails', function () {
    with_temp_dir('html-fidelity-old-retained-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        mkdir($live);
        file_put_contents($live . '/old-only.txt', 'old generation');
        $publisher = new HtmlFirstFidelityPublisher(
            function (string $staging, string $destination): bool {
                $holding = dirname($staging) . '/swap-holding';
                return rename($staging, $holding)
                    && rename($destination, $staging)
                    && rename($holding, $destination);
            },
            null,
            static function (): void {
                throw new RuntimeException('injected old-tree deletion failure');
            },
        );
        $staging = $publisher->createStaging($live, 'retain-old');
        file_put_contents($staging . '/report.json', 'new complete report');
        file_put_contents($staging . '/index.html', 'new complete gallery');

        $narration = fopen('php://memory', 'w+');
        Narrator::setStream($narration);
        try {
            $publication = $publisher->publish($staging, $live);
            rewind($narration);
            $warning = stream_get_contents($narration);
        } finally {
            Narrator::reset();
            fclose($narration);
        }

        assert_eq(HtmlFirstFidelityPublisher::EXISTING_LIVE_SWAP, $publication);
        assert_eq('new complete report', file_get_contents($live . '/report.json'));
        assert_eq('new complete gallery', file_get_contents($live . '/index.html'));
        assert_true(!is_file($live . '/old-only.txt'), 'committed live tree contains no old generation bytes');
        assert_eq('old generation', file_get_contents($staging . '/old-only.txt'));
        assert_contains('Manual cleanup required', (string) $warning);
        assert_contains($staging, (string) $warning);

        (new HtmlFirstFidelityPublisher())->discard($staging);
    });
});

test('html-first fidelity production renamex_np exchange works on disposable macOS trees', function () {
    if (PHP_OS_FAMILY !== 'Darwin') {
        skip_test('renamex_np is a macOS publication primitive');
    }
    with_temp_dir('html-fidelity-real-swap-', function (string $dir) {
        $live = $dir . '/html-first-fidelity';
        mkdir($live);
        file_put_contents($live . '/generation.txt', 'old');
        $publisher = new HtmlFirstFidelityPublisher();
        $staging = $publisher->createStaging($live, 'real-swap');
        file_put_contents($staging . '/generation.txt', 'new');

        $publisher->publish($staging, $live);

        assert_eq('new', file_get_contents($live . '/generation.txt'));
        assert_true(!file_exists($staging));
    });
});

test('html-first fidelity gallery renders aligned external three-column captures', function () {
    $metrics = [
        'empty_buttons' => 1,
        'marks_without_background_color' => 2,
        'align_wide' => 3,
        'layout' => [
            'content_size' => ['value' => '700px', 'unitless' => false],
            'wide_size' => ['value' => 1200, 'unitless' => true],
        ],
        'unmatched_engine_markers' => [],
        'unmatched_engine_marker_occurrences' => 0,
    ];
    $project = [
        'slug' => 'silver-summit',
        'design_inputs' => [
            'identical_before' => true,
            'control_unchanged' => true,
            'treatment_unchanged' => true,
        ],
        'control' => ['resume' => ['exit_code' => 0, 'llm_requests' => 0], 'metrics' => $metrics],
        'treatment' => ['resume' => ['exit_code' => 0, 'llm_requests' => 0], 'metrics' => $metrics],
        'delta' => HtmlFirstFidelityReport::delta(
            HtmlFirstFidelityReport::countTotals($metrics),
            HtmlFirstFidelityReport::countTotals($metrics),
        ),
    ];
    $html = HtmlFirstFidelityReport::renderGallery([
        'projects' => array_fill(0, 6, $project),
        'totals' => [
            'control' => HtmlFirstFidelityReport::countTotals($metrics),
            'treatment' => HtmlFirstFidelityReport::countTotals($metrics),
            'delta' => HtmlFirstFidelityReport::delta([], []),
        ],
    ]);

    assert_contains('grid-template-columns:repeat(3,minmax(0,1fr))', $html);
    assert_contains('.frame{max-height:600px;overflow:hidden auto}', $html);
    assert_contains('shots/silver-summit-design.png', $html);
    assert_contains('shots/silver-summit-control.png', $html);
    assert_contains('shots/silver-summit-treatment.png', $html);
    assert_true(!str_contains($html, 'data:image/'), 'large screenshots stay external');
    assert_contains('unitless', $html);
    assert_contains('PASS · 12 zero-request resumes', $html);
});
