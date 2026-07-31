<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockSerializer\DroppedValue;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ReportingBlockFixer;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\ThemeValidator;

test('FixBlocksStep delegates repair to the injected BlockFixer', function () {
    $fake = new class implements BlockFixer {
        /** @var string[] */
        public array $calls = [];
        public function fix(string $themeDir): string
        {
            $this->calls[] = $themeDir;
            return '[fix-templates] 0/0 file(s) re-serialized';
        }
    };

    $tmp = sys_get_temp_dir() . '/sb-' . uniqid();
    mkdir($tmp . '/theme', 0775, true);
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull"></div><!-- /wp:group -->'
    );

    (new FixBlocksStep($fake))->run($project);

    assert_eq(1, count($fake->calls), 'an already-normalized file does not trigger a follow-up fix()');
    assert_eq($project->themePath(), $fake->calls[0], 'given the theme dir');
});

test('FixBlocksStep declares its durable warnings artifact', function () {
    $writes = (new FixBlocksStep(new PhpBlockFixer()))->declaration()->writes;
    assert_true(in_array('warnings.json', $writes, true));
});

test('FixBlocksStep classifies only dropped styles that affect vertical rhythm', function () {
    $report = implode("\n", [
        '         ! DROPPED style `padding:4rem 2rem` — not mirrored',
        '         ! DROPPED style `padding-top:4rem` — not mirrored',
        '         ! DROPPED style `padding-block-end:3rem` — not mirrored',
        '         ! DROPPED style `margin:0 auto` — not mirrored',
        '         ! DROPPED style `margin-bottom:2rem` — not mirrored',
        '         ! DROPPED style `margin-block-start:1rem` — not mirrored',
        '         ! DROPPED style `gap:2rem` — not mirrored',
        '         ! DROPPED style `row-gap:1rem` — not mirrored',
        '         ! DROPPED style `column-gap:3rem` — not mirrored',
        '         ! DROPPED style `height:200px` — not mirrored',
        '         ! DROPPED style `object-fit:cover` — not mirrored',
        '         ! DROPPED style `width:100%` — not mirrored',
        '         ! DROPPED style `padding-left:2rem` — not mirrored',
        '         ! DROPPED style `margin-right:auto` — not mirrored',
    ]);

    assert_eq([
        'padding:4rem 2rem',
        'padding-top:4rem',
        'padding-block-end:3rem',
        'margin:0 auto',
        'margin-bottom:2rem',
        'margin-block-start:1rem',
        'gap:2rem',
        'row-gap:1rem',
        'column-gap:3rem',
    ], FixBlocksStep::droppedVerticalRhythmStyles($report));
});

test('the rhythm gate ignores dropped declarations whose value was never valid CSS', function () {
    // A bare preset slug (`margin-top:sm`) renders as nothing in a browser, so
    // dropping it loses no rendered rhythm. CSS-wide keywords and real lengths
    // still count.
    $report = implode("\n", [
        '         ! DROPPED style `margin-top:sm` — not mirrored',
        '         ! DROPPED style `padding-bottom:md` — not mirrored',
        '         ! DROPPED style `margin-bottom:auto` — not mirrored',
        '         ! DROPPED style `padding-top:2rem` — not mirrored',
    ]);
    assert_eq([
        'margin-bottom:auto',
        'padding-top:2rem',
    ], FixBlocksStep::droppedVerticalRhythmStyles($report));
});

test('FixBlocksStep warns but does not fail when block repair drops vertical rhythm CSS', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return "  FIXED  parts/section.html\n"
                . "         ! DROPPED style `padding-top:8rem` — not mirrored in the block comment JSON attributes\n"
                . '[fix-templates] 1/1 file(s) re-serialized, 1 style/class value(s) dropped';
        }
    };

    $tmp = sys_get_temp_dir() . '/builder_fix_rhythm_' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
    );

    try {
        $thrown = null;
        try {
            (new FixBlocksStep($fake))->run($project);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        // A cosmetic spacing regression in one theme must not cost the user the
        // whole build — the loss is flagged, the build continues.
        assert_eq(null, $thrown, 'vertical rhythm loss is a warning, not a build failure');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('DROPPED style `padding-top:8rem`', $log);
        assert_contains('[rhythm] WARNING', $log, 'the loss is recorded as a prominent warning');
        $warnings = $project->readJson('warnings.json');
        assert_contains(
            'dropped vertical rhythm CSS `padding-top:8rem`',
            implode("\n", $warnings['fix-blocks'] ?? []),
            'the non-fatal defect is durable, not only present in the human log',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep records a heading that lost its centering', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-alignment-' . uniqid();
    $project = new Project($tmp);
    // A legacy top-level textAlign plus an inline colour no attribute backs:
    // the deprecated save cannot match either, so the recovered alignment class
    // is dropped and the heading renders left-aligned instead of centred.
    $project->writeText(
        'theme/parts/signature.html',
        '<!-- wp:heading {"level":2,"textAlign":"center","fontFamily":"heading",'
            . '"style":{"elements":{"heading":{"color":{"text":"var:preset|color|base"}}}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center has-heading-font-family" '
            . 'style="color:var(--wp--preset--color--base)">Signature Flavours</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/signature.html');
        assert_contains('Signature Flavours', $fixed, 'the heading keeps its content');
        assert_true(
            !str_contains($fixed, 'has-text-align-center'),
            'the alignment class is genuinely gone from the delivered markup'
        );

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('has-text-align-center', $joined, 'the lost alignment reaches warnings.json');
        assert_contains(
            'parts/signature.html block 0 (core/heading)',
            $joined,
            'the row locates the block that lost it',
        );
        assert_contains('authored "has-text-align-center"', $joined);
        assert_contains('delivered removed', $joined);
        assert_contains('disposition: authored class removed', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('a paragraph opacity repair does not hide a heading alignment loss in the same file', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-mixed-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/mixed.html',
        '<!-- wp:paragraph --><p style="opacity:0.4">Readable</p><!-- /wp:paragraph -->'
            . '<!-- wp:heading {"level":2,"textAlign":"center","fontFamily":"heading",'
            . '"style":{"elements":{"heading":{"color":{"text":"var:preset|color|base"}}}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center has-heading-font-family" '
            . 'style="color:var(--wp--preset--color--base)">Still centred</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_eq(2, count($warnings), 'the opacity and heading losses each keep one warning');
        $joined = implode("\n", $warnings);
        assert_contains('parts/mixed.html block 0: core/paragraph style "opacity"', $joined);
        assert_contains('parts/mixed.html block 1 (core/heading)', $joined);
        assert_contains('alignment class "has-text-align-center"', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep reports vertical alignment losses with their nested block paths', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            file_put_contents(
                $themeDir . '/parts/columns.html',
                '<!-- wp:columns {"verticalAlignment":"center"} -->'
                    . '<div class="wp-block-columns">'
                    . '<!-- wp:column {"verticalAlignment":"bottom"} -->'
                    . '<div class="wp-block-column"></div><!-- /wp:column -->'
                    . '</div><!-- /wp:columns -->',
            );
            return '[fix-templates] simulated vertical alignment loss';
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-blocks-vertical-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/columns.html',
        '<!-- wp:columns {"verticalAlignment":"center"} -->'
            . '<div class="wp-block-columns are-vertically-aligned-center">'
            . '<!-- wp:column {"verticalAlignment":"bottom"} -->'
            . '<div class="wp-block-column is-vertically-aligned-bottom"></div>'
            . '<!-- /wp:column -->'
            . '</div><!-- /wp:columns -->',
    );

    try {
        (new FixBlocksStep($fake))->run($project);

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_eq(2, count($warnings));
        $joined = implode("\n", $warnings);
        assert_contains(
            'parts/columns.html block 0 (core/columns): alignment class '
                . '"are-vertically-aligned-center"',
            $joined,
        );
        assert_contains(
            'parts/columns.html block 0/0 (core/column): alignment class '
                . '"is-vertically-aligned-bottom"',
            $joined,
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep does not warn when a later fixer pass restores an alignment class', function () {
    $fixer = new class implements ReportingBlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            throw new LogicException('FixBlocksStep must consume the typed fixer contract');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            $this->calls++;
            $path = $themeDir . '/parts/section.html';
            if ($this->calls === 1) {
                file_put_contents(
                    $path,
                    '<!-- wp:group {"align":"wide"} -->'
                        . '<div class="wp-block-group"></div><!-- /wp:group -->',
                );
                return new FixerReport([
                    new FileReport(
                        'parts/section.html',
                        'fixed',
                        [new DroppedValue('class', 'alignwide')],
                    ),
                ]);
            }

            file_put_contents(
                $path,
                '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->'
                    . '<div class="wp-block-group alignwide"></div><!-- /wp:group -->',
            );
            return new FixerReport([new FileReport('parts/section.html', 'fixed')]);
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-blocks-restored-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"align":"wide"} --><div class="wp-block-group alignwide">',
    );

    try {
        (new FixBlocksStep($fixer))->run($project);

        assert_eq(2, $fixer->calls, 'post-repair layout normalization triggers the final pass');
        assert_contains('alignwide', $project->readText('theme/parts/section.html'));
        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'alignment class'),
            'an intermediate report cannot describe a class present in final output',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep degrades reviewed paragraph styles and records actionable warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_paragraph_styles_' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/aligned-opacity.html',
        '<!-- wp:paragraph {"align":"center"} -->'
            . '<p class="has-text-align-center" style="opacity:0.35 ; ">Readable</p>'
            . '<!-- /wp:paragraph -->',
    );
    $project->writeText(
        'theme/parts/hidden-opacity.html',
        '<!-- wp:paragraph --><p title="1 &gt; 0" style="opacity:0;">Still present</p><!-- /wp:paragraph -->',
    );
    $project->writeText(
        'theme/parts/conflicting-align.html',
        '<!-- wp:paragraph {"align":"center","style":{"typography":{"textAlign":"justify"}}} -->'
            . '<p class="has-text-align-center" style="text-align:justify">Conflicting but readable</p>'
            . '<!-- /wp:paragraph -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        foreach ([
            'aligned-opacity.html' => 'Readable',
            'conflicting-align.html' => 'Conflicting but readable',
            'hidden-opacity.html' => 'Still present',
        ] as $file => $copy) {
            $fixed = $project->readText('theme/parts/' . $file);
            assert_contains($copy, $fixed, "{$file} keeps its content");
            assert_true(!str_contains($fixed, 'opacity:'), "{$file} has no unsupported opacity");
        }

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_eq(3, count($warnings), 'each reviewed degradation gets one repairable warning');
        $joined = implode("\n", $warnings);
        assert_contains('parts/aligned-opacity.html block 0', $joined);
        assert_contains('parts/conflicting-align.html block 0', $joined);
        assert_contains('parts/hidden-opacity.html block 0', $joined);
        assert_contains('style "opacity"', $joined);
        assert_contains('style "text-align"', $joined);
        assert_contains('core/paragraph has no opacity save consumer', $joined);
        assert_contains('center and justify alignment has no unambiguous winner', $joined);
        assert_contains('authored "0"', $joined, 'the hidden-content value is retained for repair');
        assert_contains(
            'title="1 &gt; 0"',
            $project->readText('theme/parts/hidden-opacity.html'),
            'unrelated root attributes survive in WordPress-safe serialized form',
        );

        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('REPAIR paragraph-style-degraded:', $log);
        assert_contains('[paragraph-styles] WARNING: 3 unsupported style(s) degraded', $log);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep isolates unreviewed validation failures per file with a durable warning', function () {
    $cases = [
        'unsupported-block' => '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->',
        'unreviewed-style' => '<!-- wp:paragraph {"align":"center"} -->'
            . '<p class="has-text-align-center" style="transform:scale(.9)">Scaled</p>'
            . '<!-- /wp:paragraph -->',
        'unknown-comment-attribute' => '<!-- wp:paragraph {"customTextColor":"#f00"} -->'
            . '<p style="color:#f00">Legacy</p><!-- /wp:paragraph -->',
        'malformed-style' => '<!-- wp:paragraph --><p style="opacity">Copy</p><!-- /wp:paragraph -->',
        'commented-opacity' => '<!-- wp:paragraph --><p style="opacity/**/:0">Copy</p><!-- /wp:paragraph -->',
        'escaped-opacity' => '<!-- wp:paragraph --><p style="op\\61 city:0">Copy</p><!-- /wp:paragraph -->',
        'empty-opacity' => '<!-- wp:paragraph --><p style="opacity:">Copy</p><!-- /wp:paragraph -->',
        'unbalanced-opacity' =>
            '<!-- wp:paragraph --><p style="opacity:calc(0">Copy</p><!-- /wp:paragraph -->',
        'unterminated-opacity-comment' =>
            '<!-- wp:paragraph --><p style="opacity:0/*">Copy</p><!-- /wp:paragraph -->',
        'empty-opacity-after-comment' =>
            '<!-- wp:paragraph --><p style="opacity:/**/">Copy</p><!-- /wp:paragraph -->',
        'complex-style-carryover' =>
            '<!-- wp:paragraph --><p style="--label:&quot;a; b:c&quot;;opacity:0">Copy</p>'
            . '<!-- /wp:paragraph -->',
        'wrong-opacity-root' =>
            '<!-- wp:paragraph --><div style="opacity:0">Copy</div><!-- /wp:paragraph -->',
        'opacity-extra-sibling' =>
            '<!-- wp:paragraph --><p style="opacity:0">One</p><span>Two</span><!-- /wp:paragraph -->',
        'opacity-leading-text' =>
            '<!-- wp:paragraph -->Leading<p style="opacity:0">Copy</p><!-- /wp:paragraph -->',
        'ambiguous-align-class' =>
            '<!-- wp:paragraph {"align":"center","style":{"typography":{"textAlign":"justify"}}} -->'
            . '<p class="has-text-align-center has-text-align-right" style="text-align:justify">'
            . 'Copy</p><!-- /wp:paragraph -->',
        'conflicting-align-extra-attribute' =>
            '<!-- wp:paragraph {"align":"center","style":{"typography":{"textAlign":"justify"}}} -->'
            . '<p lang="fr" class="has-text-align-center" style="text-align:justify">'
            . 'Copie</p><!-- /wp:paragraph -->',
    ];

    foreach ($cases as $name => $original) {
        $tmp = sys_get_temp_dir() . '/builder_fix_strict_' . $name . '_' . uniqid();
        $project = new Project($tmp);
        $project->writeText('theme/parts/section.html', $original);
        try {
            // Unreviewed signatures stay outside the transformation domain,
            // but the failure is isolated to the file: its pre-fixer bytes
            // are delivered untouched with a durable warnings.json record,
            // and the build continues.
            (new FixBlocksStep(new PhpBlockFixer()))->run($project);
            assert_eq(
                $original,
                $project->readText('theme/parts/section.html'),
                "{$name} must deliver its pre-fixer bytes untouched",
            );
            $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
            assert_contains(
                'left parts/section.html unmodified',
                $joined,
                "{$name} must be recorded as a delivered-through defect",
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

test('FixBlocksStep keeps operational fixer failures fatal', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            throw new RuntimeException('injected staging I/O failure');
        }
    };

    $tmp = sys_get_temp_dir() . '/builder_fix_operational_failure_' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->',
    );

    try {
        assert_throws(static fn () => (new FixBlocksStep($fake))->run($project));
        assert_true(
            !$project->exists('warnings.json'),
            'an infrastructure failure is not mislabeled as a content warning',
        );
        assert_contains('injected staging I/O failure', $project->readText('logs/fix-blocks.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep still normalizes siblings when one file fails validation', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_strict_rollback_' . uniqid();
    $project = new Project($tmp);
    $layoutOriginal = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    $unsupportedOriginal = '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->';
    $project->writeText('theme/parts/a-layout.html', $layoutOriginal);
    $project->writeText('theme/parts/b-unsupported.html', $unsupportedOriginal);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        // The unsupported file is delivered untouched with a durable record;
        // its sibling's width-contract repair still lands.
        assert_eq($unsupportedOriginal, $project->readText('theme/parts/b-unsupported.html'));
        assert_contains('"layout"', $project->readText('theme/parts/a-layout.html'));
        $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('left parts/b-unsupported.html unmodified', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep restores step-entry bytes when layout normalization and validation fail in one file', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_compound_failure_' . uniqid();
    $project = new Project($tmp);
    $failedOriginal = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull">'
        . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->'
        . '</div><!-- /wp:group -->';
    $siblingOriginal = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    // Use header.html so the final constrained-part reassertion is also proven
    // not to mutate a file whose complete step transaction was abandoned.
    $project->writeText('theme/parts/header.html', $failedOriginal);
    $project->writeText('theme/parts/b-sibling.html', $siblingOriginal);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        assert_eq(
            $failedOriginal,
            $project->readText('theme/parts/header.html'),
            'the failed file rolls LayoutFixer back too, not merely PhpBlockFixer',
        );
        assert_contains(
            '"layout":{"type":"constrained"}',
            $project->readText('theme/parts/b-sibling.html'),
            'a healthy sibling keeps its successful layout and serialization work',
        );
        $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('parts/header.html', $joined);
        assert_contains("Registered block 'core/query'", $joined);
        assert_contains('pre-step markup delivered byte-for-byte', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep restores step-entry bytes when the typed follow-up fixer pass fails', function () {
    $fixer = new class implements ReportingBlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            throw new LogicException('FixBlocksStep must consume the typed fixer contract');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            $this->calls++;
            $path = $themeDir . '/parts/section.html';
            if ($this->calls === 1) {
                file_put_contents(
                    $path,
                    '<!-- wp:group {"align":"full"} -->'
                        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->',
                );
                return new FixerReport([new FileReport('parts/section.html', 'fixed')]);
            }
            return new FixerReport([
                new FileReport(
                    'parts/section.html',
                    'failed',
                    error: 'follow-up serializer rejected the normalized layout',
                ),
            ]);
        }
    };

    $tmp = sys_get_temp_dir() . '/builder_fix_typed_followup_' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:group {"align":"full"} --><div>';
    $project->writeText('theme/parts/section.html', $original);

    try {
        (new FixBlocksStep($fixer))->run($project);

        assert_eq(2, $fixer->calls);
        assert_eq($original, $project->readText('theme/parts/section.html'));
        $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('follow-up serializer rejected the normalized layout', $joined);
        assert_contains('pre-step markup delivered byte-for-byte', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep rolls the first fixer pass back when its strict follow-up fails', function () {
    $fake = new class implements BlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            $this->calls++;
            if ($this->calls === 1) {
                file_put_contents(
                    $themeDir . '/parts/section.html',
                    '<!-- wp:group {"align":"full"} -->'
                        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->',
                );
                return '[fix-templates] first pass repaired malformed group';
            }
            throw new RuntimeException('injected strict follow-up failure');
        }
    };

    $tmp = sys_get_temp_dir() . '/builder_fix_followup_rollback_' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:group {"align":"full"} --><div>';
    $project->writeText('theme/parts/section.html', $original);

    try {
        assert_throws(static fn () => (new FixBlocksStep($fake))->run($project));
        assert_eq(2, $fake->calls);
        assert_eq($original, $project->readText('theme/parts/section.html'));
        assert_true(!$project->exists('warnings.json'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep does not fail for unrelated dropped image styles', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return "  FIXED  parts/section.html\n"
                . "         ! DROPPED style `height:200px` — not mirrored\n"
                . "         ! DROPPED style `object-fit:cover` — not mirrored\n"
                . "         ! DROPPED style `width:100%` — not mirrored\n"
                . '[fix-templates] 1/1 file(s) re-serialized, 3 style/class value(s) dropped';
        }
    };

    $tmp = sys_get_temp_dir() . '/builder_fix_non_rhythm_' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
    );

    try {
        (new FixBlocksStep($fake))->run($project);
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('DROPPED style `height:200px`', $log);
        assert_true(!str_contains($log, '[rhythm]'), 'unrelated loss does not trigger the rhythm warning');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep normalizes markup exposed by structural repair and re-serializes it', function () {
    $fake = new class implements BlockFixer {
        public int $calls = 0;

        public function fix(string $themeDir): string
        {
            $this->calls++;
            $path = $themeDir . '/parts/section.html';
            $markup = (string) file_get_contents($path);

            if ($this->calls === 1) {
                // Faithfully model Node balancing the minimal malformed group.
                file_put_contents(
                    $path,
                    "<!-- wp:group {\"align\":\"full\"} -->\n"
                    . "<div class=\"wp-block-group alignfull\"></div>\n"
                    . "<!-- /wp:group -->"
                );
                return '[fix-templates] first pass repaired malformed group';
            }

            assert_contains('"layout":{"type":"constrained"}', $markup);
            return "  FIXED  parts/section with space.html\n"
                . '         - REPAIR paragraph-style-degraded:'
                . '{"property":"opacity","authored":"0.4","delivered":null,'
                . '"disposition":"removed; core/paragraph has no opacity save consumer",'
                . '"reviewed":true} at 0'
                . "\n[fix-templates] second pass re-serialized normalized group";
        }
    };

    $tmp = sys_get_temp_dir() . '/sb-' . uniqid();
    mkdir($tmp . '/theme/parts', 0775, true);
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:group {"align":"full"} --><div>'
    );

    try {
        (new FixBlocksStep($fake))->run($project);

        assert_eq(2, $fake->calls, 'post-repair layout change triggers one follow-up fix()');
        assert_eq([], ThemeValidator::layoutWarnings($project));
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('first pass repaired malformed group', $log);
        assert_contains('second pass re-serialized normalized group', $log);
        assert_contains('post-repair normalization required a second block-fixer pass', $log);
        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_contains(
            'parts/section with space.html block 0',
            implode("\n", $warnings),
            'an indented follow-up report keeps its file attribution',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('block fixer keeps the card-media class hook the card recipe relies on', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    // The card recipe from prompts/section.md: cropping comes from the theme's
    // .card-media CSS via a className hook, with no inline CSS to strip.
    $part = <<<'HTML'
<!-- wp:image {"sizeSlug":"large","className":"card-media"} -->
<figure class="wp-block-image size-large card-media"><img src="theme:./assets/card.jpg" alt="AI_IMAGE: A card photo | card in a grid | photorealistic | landscape"/></figure>
<!-- /wp:image -->
HTML;
    file_put_contents($theme . '/parts/cards.html', $part);

    $stdout = (new PhpBlockFixer())->fix($theme);

    $fixed = (string) file_get_contents($theme . '/parts/cards.html');
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('card-media', $fixed);
    assert_contains('0 style/class value(s) dropped', $stdout);
});

test('FixBlocksStep preserves rhythm from attrs missing only their final root closer', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $project = new Project($tmp);
    $project->writeJson('theme/theme.json', [
        'version' => 3,
        'settings' => ['layout' => ['contentSize' => '860px']],
    ]);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:heading {"textAlign":"center","level":2,"fontFamily":"heading","fontSize":"section-title","style":{"typography":{"fontWeight":"400"},"spacing":{"margin":{"top":"var:preset|spacing|sm"}}} -->'
        . '<h2 class="wp-block-heading has-text-align-center has-heading-font-family has-section-title-font-size" style="margin-top:var(--wp--preset--spacing--sm);font-weight:400">Title</h2>'
        . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        $fixed = $project->readText('theme/parts/section.html');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('margin-top:var(--wp--preset--spacing--sm)', $fixed);
        assert_contains('font-weight:400', $fixed);
        assert_true(!str_contains($log, 'DROPPED style `margin-top'), $log);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep canonicalizes matching malformed rendered preset variables before drop detection', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $project = new Project($tmp);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeText(
        'theme/parts/section.html',
        '<!-- wp:columns {"style":{"spacing":{"margin":{"top":"var:preset|spacing|lg"}}}} -->'
        . '<div class="wp-block-columns" style="margin-top:var(--wp--spacing--lg)"></div>'
        . '<!-- /wp:columns -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        $fixed = $project->readText('theme/parts/section.html');
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('margin-top:var(--wp--preset--spacing--lg)', $fixed);
        assert_true(!str_contains($log, 'DROPPED style `margin-top'), $log);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('block fixer reports inline styles it drops during re-serialization', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    // Sizing present ONLY in the HTML — re-serialization from the (empty)
    // attributes deletes it; the fixer must say so instead of losing it silently.
    $part = <<<'HTML'
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="theme:./assets/card.jpg" alt="AI_IMAGE: A card photo | card in a grid | photorealistic | landscape" style="height:200px;object-fit:cover;width:100%"/></figure>
<!-- /wp:image -->
HTML;
    file_put_contents($theme . '/parts/cards.html', $part);

    $stdout = (new PhpBlockFixer())->fix($theme);

    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('DROPPED style `height:200px`', $stdout);
    assert_contains('DROPPED style `object-fit:cover`', $stdout);
    assert_contains('DROPPED style `width:100%`', $stdout);
    // The summary line carries the dropped count for the step summary.
    assert_contains('3 style/class value(s) dropped', $stdout);
});

test('block fixer does not report semantic styles dropped for colon whitespace normalization', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    $part = <<<'HTML'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|lg"}}}} -->
<div class="wp-block-group" style="padding-top: var(--wp--preset--spacing--lg)"></div>
<!-- /wp:group -->
HTML;
    file_put_contents($theme . '/parts/section.html', $part);

    $stdout = (new PhpBlockFixer())->fix($theme);
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('0 style/class value(s) dropped', $stdout);
    assert_true(!str_contains($stdout, 'DROPPED style `padding-top'), $stdout);
});

test('block fixer reports vertical styles dropped from single-quoted HTML attributes', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    $part = <<<'HTML'
<!-- wp:group -->
<div class='wp-block-group' style='padding-top:8rem'></div>
<!-- /wp:group -->
HTML;
    file_put_contents($theme . '/parts/section.html', $part);

    $stdout = (new PhpBlockFixer())->fix($theme);
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('DROPPED style `padding-top:8rem`', $stdout);
});

test('block fixer drops nothing after the rhythm pass replaces mirrored root spacing', function () {
    // A model that ignores the "no root padding" instruction mirrors its
    // padding into inline CSS. The rhythm pass replaces both spellings, so the
    // fixer's re-serialization must not report the old values as dropped —
    // otherwise the deterministic repair itself would fail the build.
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    $authored = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding:12rem 2rem;margin:0 auto">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $result = SectionRhythm::rewrite([[
        'slug' => 'story', 'markup' => $authored, 'density' => 'standard', 'background' => 'base',
    ]]);
    file_put_contents($theme . '/parts/section-story.html', $result['markups'][0]);

    $stdout = (new PhpBlockFixer())->fix($theme);
    $fixed = (string) file_get_contents($theme . '/parts/section-story.html');
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_true(!str_contains($stdout, 'DROPPED style'), $stdout);
    assert_eq([], FixBlocksStep::droppedVerticalRhythmStyles($stdout), 'the rhythm gate must not fire');
    assert_contains('padding-right:2rem', $fixed, 'Gutenberg restores promoted side padding');
    assert_contains('padding-left:2rem', $fixed, 'Gutenberg restores promoted side padding');
    assert_contains('margin-right:auto', $fixed, 'Gutenberg restores promoted auto centering');
    assert_contains('margin-left:auto', $fixed, 'Gutenberg restores promoted auto centering');

    $again = SectionRhythm::rewrite([[
        'slug' => 'story', 'markup' => $fixed, 'density' => 'standard', 'background' => 'base',
    ]]);
    assert_eq($fixed, $again['markups'][0], 'canonical fixer output is rhythm-idempotent');
    assert_eq([], $again['notes']);
});

test('block fixer preserves media-text images when mediaType is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);
    mkdir($theme . '/templates', 0777, true);

    $part = <<<'HTML'
<!-- wp:media-text {"align":"wide","mediaPosition":"right","mediaWidth":58,"verticalAlignment":"center"} -->
<div class="wp-block-media-text alignwide has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-center" style="grid-template-columns:auto 58%"><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><figure class="wp-block-media-text__media"><img src="theme:./assets/hero.jpg" alt="AI_IMAGE: Hero | context | photorealistic | landscape"/></figure></div>
<!-- /wp:media-text -->
HTML;
    file_put_contents($theme . '/parts/hero.html', $part);

    (new PhpBlockFixer())->fix($theme);

    $fixed = (string) file_get_contents($theme . '/parts/hero.html');
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_contains('"mediaType":"image"', $fixed);
    assert_contains('<img src="theme:./assets/hero.jpg"', $fixed);
});

test('attribute-light section markup converges to the same save markup without losing design data', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    $attributes = '{"anchor":"story","className":"reveal","backgroundColor":"contrast","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|lg","bottom":"var:preset|spacing|lg"}}},"layout":{"type":"constrained"}}';
    $headingAttributes = '{"level":2,"textColor":"accent","fontFamily":"heading","fontSize":"section-title"}';
    $imageAttributes = '{"sizeSlug":"large","className":"card-media"}';
    $image = '<img src="theme:./assets/story.jpg" alt="AI_IMAGE: Hands shaping clay at a studio table | story card | photorealistic | landscape"/>';
    $richText = 'See <a href="/work/" style="text-decoration-thickness:3px">selected work</a>.';

    $canonical = <<<HTML
<!-- wp:group {$attributes} -->
<div id="story" class="wp-block-group reveal has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--lg);padding-bottom:var(--wp--preset--spacing--lg)">
<!-- wp:heading {$headingAttributes} -->
<h2 class="wp-block-heading has-accent-color has-text-color has-heading-font-family has-section-title-font-size">A material practice</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>{$richText}</p>
<!-- /wp:paragraph -->
<!-- wp:image {$imageAttributes} -->
<figure class="wp-block-image size-large card-media">{$image}</figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
HTML;

    $lean = <<<HTML
<!-- wp:group {$attributes} -->
<div class="reveal">
<!-- wp:heading {$headingAttributes} -->
<h2>A material practice</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>{$richText}</p>
<!-- /wp:paragraph -->
<!-- wp:image {$imageAttributes} -->
<figure class="card-media">{$image}</figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
HTML;

    file_put_contents($theme . '/parts/canonical.html', $canonical);
    file_put_contents($theme . '/parts/lean.html', $lean);

    try {
        $report = (new PhpBlockFixer())->fix($theme);
        $fixedCanonical = (string) file_get_contents($theme . '/parts/canonical.html');
        $fixedLean = (string) file_get_contents($theme . '/parts/lean.html');

        assert_eq($fixedCanonical, $fixedLean, 'lean and verbose inputs converge byte-for-byte');
        assert_contains('class="wp-block-group reveal', $fixedLean, 'explicit className hook survives');
        assert_contains('id="story"', $fixedLean, 'the comment anchor regenerates the wrapper id');
        assert_contains('padding-top:var(--wp--preset--spacing--lg)', $fixedLean, 'attribute styling is regenerated');
        assert_contains(
            '<a href="/work/" style="text-decoration-thickness:3px">selected work</a>',
            $fixedLean,
            'RichText inline styling survives',
        );
        assert_contains('AI_IMAGE:', $fixedLean, 'semantic image attributes survive');
        assert_contains('0 style/class value(s) dropped', $report);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('attribute-light cover markup remains collectable before canonical serialization', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_blocks_' . uniqid();
    $theme = $tmp . '/theme';
    mkdir($theme . '/parts', 0777, true);

    $markup = <<<'HTML'
<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
<div>
<!-- wp:cover {"url":"theme:./assets/hero-mountain-dawn.jpg","dimRatio":50,"align":"full","minHeight":80,"minHeightUnit":"vh"} -->
<div>
<img alt="AI_IMAGE: A misty mountain range at dawn with calm sky behind the headline | full-bleed hero section | photorealistic | landscape" src="theme:./assets/hero-mountain-dawn.jpg"/>
<div>
<!-- wp:heading {"level":1,"style":{"typography":{"textAlign":"center"}},"textColor":"base","fontFamily":"heading","fontSize":"display"} -->
<h1>Into the High Country</h1>
<!-- /wp:heading -->
</div>
</div>
<!-- /wp:cover -->
</div>
<!-- /wp:group -->
HTML;

    $images = CollectImagesStep::parsePlaceholders($markup);
    assert_eq(1, count($images), 'classless cover image remains visible to image collection');
    assert_eq('hero-mountain-dawn.jpg', $images[0]['filename']);

    file_put_contents($theme . '/parts/hero.html', $markup);
    try {
        $report = (new PhpBlockFixer())->fix($theme);
        $fixed = (string) file_get_contents($theme . '/parts/hero.html');

        assert_contains('class="wp-block-cover alignfull"', $fixed, 'cover save classes are regenerated');
        assert_contains('style="min-height:80vh"', $fixed, 'cover dimensions are regenerated');
        assert_contains('Into the High Country', $fixed, 'nested RichText content survives');
        assert_contains('has-text-align-center', $fixed, 'current-schema textAlign regenerates centering');
        assert_contains('theme:./assets/hero-mountain-dawn.jpg', $fixed, 'cover URL survives');
        assert_contains('0 style/class value(s) dropped', $report);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
