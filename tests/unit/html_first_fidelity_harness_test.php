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
    assert_true(!HtmlFirstFidelityRunner::auditMarkMetricRequested([]));
    assert_true(HtmlFirstFidelityRunner::auditMarkMetricRequested(['--audit-mark-metric']));
    assert_throws(fn () => HtmlFirstFidelityRunner::auditMarkMetricRequested(['--unknown']));
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
    assert_eq([
        'site_builder_ref',
        'site_builder_sha',
        'transformer_label',
        'transformer_reference',
        'transformer_version',
        'transformer_installed_tree_sha256',
    ], $schema['$defs']['provenanceSide']['required']);
    assert_eq([
        'site_builder_ref',
        'site_builder_sha',
        'transformer_label',
        'transformer_reference',
        'transformer_version',
        'transformer_commit_sha',
        'transformer_git_subtree_oid',
        'transformer_installed_tree_sha256',
    ], $schema['$defs']['treatmentProvenanceSide']['required']);
    assert_eq(
        HtmlFirstFidelityReport::MARK_METRIC_CORRECTED_DEFINITION,
        $schema['$defs']['metrics']['properties']['marks_without_background_color']['description'],
    );
    assert_eq(
        HtmlFirstFidelityReport::MARK_METRIC_LEGACY_DEFINITION,
        $schema['$defs']['markMetricTransitionAudit']['properties']['legacy_definition']['const'],
    );
    assert_eq(
        HtmlFirstFidelityReport::MARK_METRIC_CORRECTED_DEFINITION,
        $schema['$defs']['markMetricTransitionAudit']['properties']['corrected_definition']['const'],
    );
});

test('html-first fidelity Composer update follows each worktree manifest without transformer override', function () {
    $command = HtmlFirstFidelityRunner::composerUpdateCommand();
    assert_eq([
        'composer',
        'update',
        '--no-interaction',
        '--no-progress',
        '--prefer-dist',
    ], $command);
    $serialized = implode(' ', $command);
    assert_true(!str_contains($serialized, '--with'));
    assert_true(!str_contains($serialized, 'blocks-engine-php-transformer'));
    assert_true(!str_contains($serialized, '0.4.15'));
    assert_true(!defined(HtmlFirstFidelityRunner::class . '::CONTROL_TRANSFORMER'));
});

test('html-first fidelity installed byte tree hash uses stable relative path byte map', function () {
    with_temp_dir('html-fidelity-installed-tree-', function (string $dir) {
        $first = $dir . '/first';
        $second = $dir . '/second';
        mkdir($first . '/nested', 0777, true);
        mkdir($second . '/nested', 0777, true);
        file_put_contents($first . '/root.php', '<?php return 1;');
        file_put_contents($first . '/nested/value.txt', 'same bytes');
        file_put_contents($second . '/nested/value.txt', 'same bytes');
        file_put_contents($second . '/root.php', '<?php return 1;');

        $firstHash = HtmlFirstFidelityFrozenGitTree::installedTreeSha256($first);
        $secondHash = HtmlFirstFidelityFrozenGitTree::installedTreeSha256($second);
        assert_eq($firstHash, $secondHash);
        assert_true(preg_match('/^[0-9a-f]{64}$/', $firstHash) === 1);

        file_put_contents($second . '/nested/value.txt', 'changed bytes');
        assert_true($firstHash !== HtmlFirstFidelityFrozenGitTree::installedTreeSha256($second));
    });
});

test('html-first fidelity control provenance follows actual future Composer metadata and installed bytes', function () {
    with_temp_dir('html-fidelity-control-provenance-', function (string $dir) {
        $metadataPath = $dir . '/vendor/composer/installed.json';
        $transformerPath = $dir . '/vendor/automattic/blocks-engine-php-transformer';
        mkdir(dirname($metadataPath), 0777, true);
        mkdir($transformerPath, 0777, true);
        file_put_contents($transformerPath . '/Transformer.php', '<?php return "future";');
        file_put_contents($metadataPath, json_encode([
            'packages' => [[
                'name' => 'automattic/blocks-engine-php-transformer',
                'version' => '9.9.9.0',
                'pretty_version' => 'v9.9.9-future',
            ]],
        ], JSON_THROW_ON_ERROR));

        $provenance = HtmlFirstFidelityRunner::installedComposerTransformerProvenance(
            'control transformer',
            $metadataPath,
            $transformerPath,
        );
        assert_eq('v9.9.9-future', $provenance['transformer_version']);
        assert_eq('v9.9.9-future Composer install', $provenance['transformer_label']);
        assert_eq(
            'composer:automattic/blocks-engine-php-transformer@v9.9.9-future',
            $provenance['transformer_reference'],
        );
        assert_eq(
            HtmlFirstFidelityFrozenGitTree::installedTreeSha256($transformerPath),
            $provenance['transformer_installed_tree_sha256'],
        );

        file_put_contents($metadataPath, json_encode([[
            'name' => 'automattic/blocks-engine-php-transformer',
            'version' => '10.0.0.0-next',
        ]], JSON_THROW_ON_ERROR));
        $future = HtmlFirstFidelityRunner::installedComposerTransformerProvenance(
            'control transformer',
            $metadataPath,
            $transformerPath,
        );
        assert_eq('10.0.0.0-next', $future['transformer_version']);
        assert_eq('10.0.0.0-next Composer install', $future['transformer_label']);
        assert_eq(
            'composer:automattic/blocks-engine-php-transformer@10.0.0.0-next',
            $future['transformer_reference'],
        );
        assert_eq(
            $provenance['transformer_installed_tree_sha256'],
            $future['transformer_installed_tree_sha256'],
        );
    });
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

test('html-first fidelity mark metric honors inline and broad carried background rules', function () {
    $markup = <<<'HTML'
<mark style="background-color:#ffe100">authored inline background</mark>
<mark style="color:red;--blocks-engine-richtext-marker:blocks-engine-richtext-broad000000-1">broad reset</mark>
HTML;
    $css = <<<'CSS'
@charset "UTF-8";
@import url("base.css");
@layer engine;
/* :where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:red} */
.fake{content:":where(mark)[style*=\"--blocks-engine-richtext-marker:\"]{background-color:red}"}
:where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}
CSS;

    assert_eq(
        ['legacy' => 1, 'corrected' => 0],
        HtmlFirstFidelityReport::markMetricCounts($markup, $css),
    );
    $carriedOnly = '<mark style="--blocks-engine-richtext-marker:blocks-engine-richtext-broad000000-1">broad reset</mark>';
    assert_eq(
        ['legacy' => 1, 'corrected' => 1],
        HtmlFirstFidelityReport::markMetricCounts(
            $carriedOnly,
            '@charset "UTF-8";@import url("base.css");@layer engine;'
                . '/* :where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:red} */'
                . '.fake{content:":where(mark)[style*=\"--blocks-engine-richtext-marker:\"]{background-color:red}"}',
        ),
    );
    assert_eq(
        ['legacy' => 1, 'corrected' => 1],
        HtmlFirstFidelityReport::markMetricCounts(
            $carriedOnly,
            ':where(mark)[style*="--blocks-engine-richtext-marker:"]{color:red}',
        ),
    );
});

test('html-first fidelity mark metric requires matching marker selector background declaration', function () {
    $matching = 'blocks-engine-richtext-matching0000-1';
    $colorOnly = 'blocks-engine-richtext-coloronly000-2';
    $unmatched = 'blocks-engine-richtext-unmatched000-3';
    $plain = 'blocks-engine-richtext-plain000000-4';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $matching . '">matching</mark>'
        . '<mark style="--blocks-engine-richtext-marker:' . $colorOnly . '">color only</mark>'
        . '<mark style="--blocks-engine-richtext-marker:' . $unmatched . '">unmatched</mark>'
        . '<mark>plain mark</mark>'
        . '<mark style="--blocks-engine-richtext-marker:' . $plain . '">global mark rule only</mark>';
    $css = ':where(mark[style*="--blocks-engine-richtext-marker:' . $matching . '"])'
        . '{background-color:#f00}'
        . ':where(mark[style*="--blocks-engine-richtext-marker:' . $colorOnly . '"])'
        . '{color:#f00}'
        . ':where(mark[style*="--blocks-engine-richtext-marker:' . $unmatched . '-other"])'
        . '{background-color:#0f0}'
        . 'mark{background-color:#00f}';

    assert_eq(
        ['legacy' => 5, 'corrected' => 4],
        HtmlFirstFidelityReport::markMetricCounts($markup, $css),
    );
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $matching));
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $colorOnly));
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $unmatched));
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $plain));
    $projected = ':where(mark[style*="--blocks-engine-richtext-marker:' . $matching . '"],'
        . 'span[data-blocks-engine-richtext-marker="' . $matching . '"])'
        . ':not(.blocks-engine-specificity-class-deadbeef0000-7)'
        . ':not(#blocks-engine-specificity-id-deadbeef0000-8):hover{background-color:#f00}';
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($projected, $matching));
});

test('html-first fidelity mark metric rejects trailing selector context', function () {
    $marker = 'blocks-engine-richtext-context0000-1';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $marker . '">context</mark>';
    $css = ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"])'
        . ' .child{background-color:red}';

    assert_eq(
        ['legacy' => 1, 'corrected' => 1],
        HtmlFirstFidelityReport::markMetricCounts($markup, $css),
    );
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $marker));
    foreach ([' > .child', ' + .child', ' ~ .child'] as $trailingContext) {
        assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule(
            ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"])'
                . $trailingContext . '{background-color:red}',
            $marker,
        ));
    }
});

test('html-first fidelity mark metric accepts projected selectors in top-level lists only', function () {
    $marker = 'blocks-engine-richtext-list0000000-1';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $marker . '">list</mark>';
    $projected = ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"],'
        . 'span[data-blocks-engine-richtext-marker="' . $marker . '"])';
    $valid = '.other,' . $projected . '{background-color:red}';

    assert_eq(
        ['legacy' => 1, 'corrected' => 0],
        HtmlFirstFidelityReport::markMetricCounts($markup, $valid),
    );
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($valid, $marker));

    foreach ([
        ':not(.other,' . $projected . '){background-color:red}',
        '.fake[data-label=", ' . $projected . '"]{background-color:red}',
        '.fake\\, ' . $projected . '{background-color:red}',
    ] as $misleading) {
        assert_eq(
            ['legacy' => 1, 'corrected' => 1],
            HtmlFirstFidelityReport::markMetricCounts($markup, $misleading),
        );
        assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($misleading, $marker));
    }
});

test('html-first fidelity projected selectors allow repeated supported dynamic states', function () {
    $marker = 'blocks-engine-richtext-states00000-1';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $marker . '">states</mark>';
    $base = ':where(mark[style*="--blocks-engine-richtext-marker:' . $marker . '"])';
    $supported = $base . ':hover:focus{background-color:red}';

    assert_eq(
        ['legacy' => 1, 'corrected' => 0],
        HtmlFirstFidelityReport::markMetricCounts($markup, $supported),
    );
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($supported, $marker));
    $commentSeparated = $base . ':hover/**/:focus{background-color:red}';
    assert_eq(
        ['legacy' => 1, 'corrected' => 0],
        HtmlFirstFidelityReport::markMetricCounts($markup, $commentSeparated),
    );
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($commentSeparated, $marker));
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule(
        $base . ':hover:focus-visible{background-color:red}',
        $marker,
    ));
});

test('html-first fidelity mark metric rejects negated carried selector false positives', function () {
    $marker = 'blocks-engine-richtext-negated0000-1';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $marker . '">negated</mark>';
    $css = ':not(:where(mark)[style*="--blocks-engine-richtext-marker:"])'
        . '{background-color:red}';

    assert_eq(
        ['legacy' => 1, 'corrected' => 1],
        HtmlFirstFidelityReport::markMetricCounts($markup, $css),
    );
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $marker));
    assert_true(!HtmlFirstFidelityReport::cssHasMarkBackgroundRule(
        'body:has(:where(mark)[style*="--blocks-engine-richtext-marker:"]){background-color:red}',
        $marker,
    ));
});

test('html-first fidelity CSS lexer preserves comment tokens inside strings', function () {
    $marker = 'blocks-engine-richtext-comment0000-1';
    $markup = '<mark style="--blocks-engine-richtext-marker:' . $marker . '">reset</mark>';
    $css = '.fake{content:"/*"}'
        . ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent}'
        . '.later{/* real */color:red}';

    assert_eq(
        ['legacy' => 1, 'corrected' => 0],
        HtmlFirstFidelityReport::markMetricCounts($markup, $css),
    );
    assert_true(HtmlFirstFidelityReport::cssHasMarkBackgroundRule($css, $marker));
});

test('html-first fidelity empty unmatched marker map serializes as JSON object', function () {
    $metrics = HtmlFirstFidelityReport::measureBytes('<p>clean</p>', '', ['settings' => ['layout' => []]]);
    assert_true(is_object($metrics['unmatched_engine_markers']));
    assert_eq('{}', json_encode($metrics['unmatched_engine_markers'], JSON_THROW_ON_ERROR));
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

test('html-first fidelity schema validates default and optional mark transition audit reports', function () {
    $schema = json_decode(
        (string) file_get_contents(__DIR__ . '/../../schemas/html-first-fidelity-report.schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $default = html_first_fidelity_report_fixture();
    html_first_fidelity_validate_report($default, $schema);
    assert_true(!array_key_exists('mark_metric_transition_audit', $default));

    $rows = [];
    foreach (HtmlFirstFidelityReport::SLUGS as $index => $slug) {
        $rows[] = [
            'slug' => $slug,
            'control' => ['legacy' => $index + 1, 'corrected' => $index],
            'treatment' => ['legacy' => $index + 2, 'corrected' => $index + 1],
        ];
    }
    $stillDefault = HtmlFirstFidelityReport::withMarkMetricTransitionAudit($default, $rows, false);
    assert_eq($default, $stillDefault);

    $audited = HtmlFirstFidelityReport::withMarkMetricTransitionAudit($default, $rows, true);
    html_first_fidelity_validate_report($audited, $schema);
    $audit = $audited['mark_metric_transition_audit'];
    assert_eq(HtmlFirstFidelityReport::MARK_METRIC_LEGACY_DEFINITION, $audit['legacy_definition']);
    assert_eq(HtmlFirstFidelityReport::MARK_METRIC_CORRECTED_DEFINITION, $audit['corrected_definition']);
    assert_eq([
        'control' => ['legacy' => 21, 'corrected' => 15],
        'treatment' => ['legacy' => 27, 'corrected' => 21],
    ], $audit['totals']);
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

test('html-first fidelity overlay HEAD movement changes installed bytes and provenance', function () {
    with_temp_dir('html-fidelity-runtime-git-', function (string $dir) {
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
        file_put_contents($repository . '/php-transformer/VERSION', "0.4.17\n");
        file_put_contents($repository . '/php-transformer/value.txt', 'first HEAD bytes');
        $run(['git', 'add', 'php-transformer'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'first'], $repository);

        $referencePath = $repository . '/php-transformer';
        $first = HtmlFirstFidelityFrozenGitTree::installRevision(
            side: 'treatment transformer',
            repository: $repository,
            revision: 'HEAD',
            subdirectory: 'php-transformer',
            workRoot: $dir . '/first-archive',
            target: $dir . '/first-installed',
            referencePath: $referencePath,
        );

        file_put_contents($repository . '/php-transformer/value.txt', 'second HEAD bytes');
        $run(['git', 'add', 'php-transformer/value.txt'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'second'], $repository);
        file_put_contents($repository . '/php-transformer/value.txt', 'dirty live worktree bytes');

        $second = HtmlFirstFidelityFrozenGitTree::installRevision(
            side: 'treatment transformer',
            repository: $repository,
            revision: 'HEAD',
            subdirectory: 'php-transformer',
            workRoot: $dir . '/second-archive',
            target: $dir . '/second-installed',
            referencePath: $referencePath,
        );

        assert_eq('dirty live worktree bytes', file_get_contents($repository . '/php-transformer/value.txt'));
        assert_eq('first HEAD bytes', file_get_contents($dir . '/first-installed/value.txt'));
        assert_eq('second HEAD bytes', file_get_contents($dir . '/second-installed/value.txt'));
        assert_true($first['commit_sha'] !== $second['commit_sha']);
        assert_true($first['git_subtree_oid'] !== $second['git_subtree_oid']);
        assert_true($first['installed_tree_sha256'] !== $second['installed_tree_sha256']);
        assert_true($first['reference'] !== $second['reference']);
        assert_eq('0.4.17', $second['version']);
        assert_eq(
            $run(['git', 'rev-parse', $second['commit_sha'] . ':php-transformer'], $repository),
            $second['git_subtree_oid'],
        );

        remove_tree($repository . '/php-transformer');
        assert_true(!file_exists($repository . '/php-transformer'));
        $withoutLiveSubtree = HtmlFirstFidelityFrozenGitTree::installRevision(
            side: 'treatment transformer',
            repository: $repository,
            revision: 'HEAD',
            subdirectory: 'php-transformer',
            workRoot: $dir . '/archive-without-live-subtree',
            target: $dir . '/installed-without-live-subtree',
            referencePath: $referencePath,
        );
        assert_eq('second HEAD bytes', file_get_contents($dir . '/installed-without-live-subtree/value.txt'));
        assert_eq($second, $withoutLiveSubtree);
        $error = assert_throws(fn () => HtmlFirstFidelityFrozenGitTree::installRevision(
            side: 'treatment transformer',
            repository: $repository,
            revision: 'missing-overlay-revision',
            subdirectory: 'php-transformer',
            workRoot: $dir . '/missing-work',
            target: $dir . '/missing-target',
            referencePath: $referencePath,
        ));
        assert_contains('side=treatment transformer', $error->getMessage());
        assert_contains("path={$repository}", $error->getMessage());
        assert_contains('revision=missing-overlay-revision', $error->getMessage());
        assert_contains('value=commit', $error->getMessage());
    });
});

test('html-first fidelity rejects mismatched recorded transformer commit and tree provenance', function () {
    $path = '/tmp/overlay/php-transformer';
    $commit = str_repeat('a', 40);
    $tree = str_repeat('b', 40);
    $installed = str_repeat('c', 64);
    $trusted = [
        'commit_sha' => $commit,
        'git_subtree_oid' => $tree,
        'installed_tree_sha256' => $installed,
        'version' => '0.4.17',
        'reference' => HtmlFirstFidelityFrozenGitTree::reference($path, $commit, $tree, $installed),
    ];
    $recorded = [
        'transformer_version' => '0.4.17',
        'transformer_commit_sha' => $commit,
        'transformer_git_subtree_oid' => $tree,
        'transformer_installed_tree_sha256' => $installed,
    ];
    $provenance = HtmlFirstFidelityRunner::treatmentTransformerProvenance(
        $trusted,
        $recorded,
        'treatment transformer',
        $path,
        'HEAD',
    );
    assert_eq('v0.4.17 runtime HEAD archive', $provenance['transformer_label']);
    assert_eq($trusted['reference'], $provenance['transformer_reference']);
    assert_eq($recorded, array_intersect_key($provenance, $recorded));

    foreach ([
        'transformer_version' => '9.9.9',
        'transformer_commit_sha' => str_repeat('d', 40),
        'transformer_git_subtree_oid' => str_repeat('e', 40),
        'transformer_installed_tree_sha256' => str_repeat('f', 64),
    ] as $field => $wrong) {
        $mismatch = $recorded;
        $mismatch[$field] = $wrong;
        $error = assert_throws(fn () => HtmlFirstFidelityRunner::treatmentTransformerProvenance(
            $trusted,
            $mismatch,
            'treatment transformer',
            $path,
            'HEAD',
        ));
        assert_contains('side=treatment transformer', $error->getMessage());
        assert_contains("path={$path}", $error->getMessage());
        assert_contains('revision=HEAD', $error->getMessage());
        assert_contains("value={$field}", $error->getMessage());
    }
});

test('html-first fidelity detached site-builder HEAD gets explicit label', function () {
    with_temp_dir('html-fidelity-detached-git-', function (string $repository) {
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
        file_put_contents($repository . '/proof.txt', 'proof');
        $run(['git', 'add', 'proof.txt'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'proof'], $repository);
        $sha = $run(['git', 'rev-parse', 'HEAD'], $repository);
        $run(['git', 'checkout', '--quiet', '--detach', $sha], $repository);

        $resolved = HtmlFirstFidelityFrozenGitTree::resolveRepositoryRevision(
            'treatment site-builder',
            $repository,
            'HEAD',
        );
        assert_eq($sha, $resolved['site_builder_sha']);
        assert_eq('detached HEAD @ ' . substr($sha, 0, 12), $resolved['site_builder_ref']);
        assert_true($resolved['site_builder_ref'] !== '');

        $error = assert_throws(fn () => HtmlFirstFidelityFrozenGitTree::resolveRepositoryRevision(
            'control site-builder',
            $repository,
            'missing-control-ref',
        ));
        assert_contains('side=control site-builder', $error->getMessage());
        assert_contains("path={$repository}", $error->getMessage());
        assert_contains('revision=missing-control-ref', $error->getMessage());
        assert_contains('value=commit', $error->getMessage());
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

/** @return array<string,mixed> */
function html_first_fidelity_report_fixture(): array
{
    $sha = str_repeat('a', 40);
    $subtree = str_repeat('c', 40);
    $sha256 = str_repeat('b', 64);
    $hashes = ['design/home.html' => $sha256, 'design/site.css' => $sha256];
    $metrics = [
        'empty_buttons' => 0,
        'marks_without_background_color' => 0,
        'align_wide' => 0,
        'layout' => [
            'content_size' => ['value' => '700px', 'unitless' => false],
            'wide_size' => ['value' => null, 'unitless' => false],
        ],
        'unmatched_engine_markers' => (object) [],
        'unmatched_engine_marker_occurrences' => 0,
    ];
    $counts = HtmlFirstFidelityReport::countTotals($metrics);
    $projects = [];
    foreach (HtmlFirstFidelityReport::SLUGS as $slug) {
        $projects[] = [
            'slug' => $slug,
            'design_inputs' => [
                'control_before' => $hashes,
                'treatment_before' => $hashes,
                'control_after' => $hashes,
                'treatment_after' => $hashes,
                'identical_before' => true,
                'control_unchanged' => true,
                'treatment_unchanged' => true,
            ],
            'control' => ['resume' => ['exit_code' => 0, 'llm_requests' => 0], 'metrics' => $metrics],
            'treatment' => ['resume' => ['exit_code' => 0, 'llm_requests' => 0], 'metrics' => $metrics],
            'delta' => HtmlFirstFidelityReport::delta($counts, $counts),
        ];
    }
    return [
        'schema_version' => 1,
        'generated_at' => '2026-08-14T12:00:00+00:00',
        'rerun_command' => HtmlFirstFidelityReport::RERUN_COMMAND,
        'provenance' => [
            'source_projects' => '/source/projects',
            'control' => [
                'site_builder_ref' => 'origin/trunk',
                'site_builder_sha' => $sha,
                'transformer_label' => 'v9.9.9-future Composer install',
                'transformer_reference' => 'composer:automattic/blocks-engine-php-transformer@v9.9.9-future',
                'transformer_version' => 'v9.9.9-future',
                'transformer_installed_tree_sha256' => $sha256,
            ],
            'treatment' => [
                'site_builder_ref' => 'integration/html-first-fidelity',
                'site_builder_sha' => $sha,
                'transformer_label' => 'v0.4.17 runtime HEAD archive',
                'transformer_reference' => HtmlFirstFidelityFrozenGitTree::reference(
                    '/overlay/php-transformer',
                    $sha,
                    $subtree,
                    $sha256,
                ),
                'transformer_version' => '0.4.17',
                'transformer_commit_sha' => $sha,
                'transformer_git_subtree_oid' => $subtree,
                'transformer_installed_tree_sha256' => $sha256,
            ],
        ],
        'projects' => $projects,
        'totals' => ['control' => $counts, 'treatment' => $counts, 'delta' => HtmlFirstFidelityReport::delta($counts, $counts)],
    ];
}

/** @param array<string,mixed> $report @param array<string,mixed> $schema */
function html_first_fidelity_validate_report(array $report, array $schema): void
{
    $decoded = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    html_first_fidelity_validate_schema($decoded, $schema, $schema, '$');
}

/** @param array<string,mixed> $schema @param array<string,mixed> $root */
function html_first_fidelity_validate_schema(mixed $value, array $schema, array $root, string $path): void
{
    if (isset($schema['$ref'])) {
        $resolved = $root;
        foreach (explode('/', substr((string) $schema['$ref'], 2)) as $part) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($resolved) || !array_key_exists($key, $resolved)) {
                throw new RuntimeException("Unresolved schema reference at {$path}: {$schema['$ref']}");
            }
            $resolved = $resolved[$key];
        }
        if (!is_array($resolved)) {
            throw new RuntimeException("Invalid schema reference target at {$path}");
        }
        html_first_fidelity_validate_schema($value, $resolved, $root, $path);
        return;
    }
    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        throw new RuntimeException("Schema const mismatch at {$path}");
    }
    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        throw new RuntimeException("Schema enum mismatch at {$path}");
    }
    if (isset($schema['type'])) {
        $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
        $matches = false;
        foreach ($types as $type) {
            $matches = $matches || match ($type) {
                'object' => is_object($value),
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
        }
        if (!$matches) {
            throw new RuntimeException("Schema type mismatch at {$path}");
        }
    }
    if (is_string($value)) {
        if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
            throw new RuntimeException("Schema minLength mismatch at {$path}");
        }
        if (isset($schema['pattern']) && preg_match('~' . $schema['pattern'] . '~D', $value) !== 1) {
            throw new RuntimeException("Schema pattern mismatch at {$path}");
        }
        if (($schema['format'] ?? null) === 'date-time' && strtotime($value) === false) {
            throw new RuntimeException("Schema date-time mismatch at {$path}");
        }
    }
    if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
        throw new RuntimeException("Schema minimum mismatch at {$path}");
    }
    if (is_array($value)) {
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            throw new RuntimeException("Schema minItems mismatch at {$path}");
        }
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            throw new RuntimeException("Schema maxItems mismatch at {$path}");
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                html_first_fidelity_validate_schema($item, $schema['items'], $root, "{$path}[{$index}]");
            }
        }
    }
    if (!is_object($value)) {
        return;
    }
    $properties = get_object_vars($value);
    if (isset($schema['minProperties']) && count($properties) < $schema['minProperties']) {
        throw new RuntimeException("Schema minProperties mismatch at {$path}");
    }
    foreach ($schema['required'] ?? [] as $required) {
        if (!array_key_exists($required, $properties)) {
            throw new RuntimeException("Schema required property missing at {$path}.{$required}");
        }
    }
    foreach ($properties as $name => $propertyValue) {
        if (isset($schema['propertyNames']) && is_array($schema['propertyNames'])) {
            html_first_fidelity_validate_schema($name, $schema['propertyNames'], $root, "{$path}.{$name}#name");
        }
        if (isset($schema['properties'][$name]) && is_array($schema['properties'][$name])) {
            html_first_fidelity_validate_schema($propertyValue, $schema['properties'][$name], $root, "{$path}.{$name}");
            continue;
        }
        if (($schema['additionalProperties'] ?? null) === false) {
            throw new RuntimeException("Schema additional property at {$path}.{$name}");
        }
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            html_first_fidelity_validate_schema($propertyValue, $schema['additionalProperties'], $root, "{$path}.{$name}");
        }
    }
}
