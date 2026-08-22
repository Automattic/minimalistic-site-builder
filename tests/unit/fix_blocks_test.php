<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\DroppedValue;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ReportingBlockFixer;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\ThemeValidator;

final class FixBlocksAlignmentFailWriter implements StagedFileWriter
{
    private NativeStagedFileWriter $inner;
    private int $stageCalls = 0;
    private int $replaceCalls = 0;

    public function __construct(
        private readonly ?int $failStageAt = null,
        private readonly ?int $failReplaceAt = null,
    ) {
        $this->inner = new NativeStagedFileWriter();
    }

    public function stage(string $target, string $content): string
    {
        $this->stageCalls++;
        if ($this->stageCalls === $this->failStageAt) {
            throw new RuntimeException('injected FixBlocks alignment stage failure');
        }
        return $this->inner->stage($target, $content);
    }

    public function replace(string $staged, string $target): void
    {
        $this->replaceCalls++;
        if ($this->replaceCalls === $this->failReplaceAt) {
            throw new RuntimeException('injected FixBlocks alignment replace failure');
        }
        $this->inner->replace($staged, $target);
    }

    public function discard(string $staged): void
    {
        $this->inner->discard($staged);
    }
}

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

test('FixBlocksStep keeps a CSS-owned header nav on its authored inline axis', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] 0/0 file(s) re-serialized';
        }
    };
    $tmp = sys_get_temp_dir() . '/fix-blocks-css-owned-header-' . uniqid();
    $project = new Project($tmp);
    $css = ':root{--wide-size:1280px}header nav{width:100%;max-width:var(--wide-size);display:flex;flex-direction:row}';
    $header = '<!-- wp:group {"tagName":"nav","className":"blocks-engine-css-owned-layout blocks-engine-css-owned-flow"} -->'
        . '<nav class="wp-block-group blocks-engine-css-owned-layout blocks-engine-css-owned-flow">'
        . '<!-- wp:paragraph --><p>Brand</p><!-- /wp:paragraph -->'
        . '<!-- wp:list --><ul class="wp-block-list"><li>About</li></ul><!-- /wp:list -->'
        . '</nav><!-- /wp:group -->';
    $project->writeText('design/site.css', $css);
    $project->writeText('theme/parts/header.html', $header);

    try {
        (new FixBlocksStep($fake, htmlFirst: true))->run($project);

        assert_eq($css, $project->readText('design/site.css'), 'carried inline-axis CSS stays byte-for-byte intact');
        $delivered = $project->readText('theme/parts/header.html');
        assert_contains('blocks-engine-css-owned-layout blocks-engine-css-owned-flow', $delivered);
        assert_contains('<p>Brand</p>', $delivered);
        assert_contains('<li>About</li>', $delivered);
        assert_true(
            !str_contains($delivered, '"layout":{"type":"constrained"}'),
            'delivered nav must not ask core to re-cap its children at contentSize',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep promotes the real footer wide carrier inside its constrained root', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-wide-footer-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'design/site.css',
        ':root{--wide-size:1280px}.shell{width:100%;max-width:var(--wide-size);margin:0 auto}',
    );
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:group {"tagName":"footer","style":{"spacing":{"padding":{"top":"clamp(40px,6vw,72px)","right":"0","bottom":"clamp(28px,4vw,40px)","left":"0"}}},"layout":{"type":"constrained"}} -->'
            . '<footer class="wp-block-group"><!-- wp:group {"className":"shell"} -->'
            . '<div class="wp-block-group shell"><!-- wp:group {"className":"shell"} -->'
            . '<div class="wp-block-group shell"><!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --></div>'
            . '<!-- /wp:group --></div><!-- /wp:group --></footer><!-- /wp:group -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer(), htmlFirst: true))->run($project);

        $doc = BlockMarkup::parse($project->readText('theme/parts/footer.html'));
        $root = $doc->topLevel();
        assert_true($root !== null, 'footer fixture keeps one root');
        assert_eq(['type' => 'constrained'], ($doc->attrs($root) ?? [])['layout'] ?? null);
        $shells = array_values(array_filter(
            $doc->indices(),
            static fn (int $index): bool => in_array(
                'shell',
                preg_split('/\s+/', trim((string) (($doc->attrs($index) ?? [])['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                true,
            ),
        ));
        assert_eq(2, count($shells), 'fixture carries nested wide-size subjects');
        assert_eq('wide', ($doc->attrs($shells[0]) ?? [])['align'] ?? null, 'outermost wide carrier promoted');
        assert_contains('alignwide', $doc->ownHtml($shells[0]), 'serialized outermost carrier stays wide');
        assert_true(!isset(($doc->attrs($shells[1]) ?? [])['align']), 'nested matching carrier stays untouched');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep declares its durable warnings artifact', function () {
    $writes = (new FixBlocksStep(new PhpBlockFixer()))->declaration()->writes;
    assert_true(in_array('warnings.json', $writes, true));
    assert_true(in_array('theme/pages/*', $writes, true));
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

test('FixBlocksStep preserves a heading centering that lives only in the saved HTML', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-alignment-' . uniqid();
    $project = new Project($tmp);
    // An alignment class that lives ONLY in the saved HTML while the comment
    // attrs carry an authored style: before BIGR-779 the deprecation
    // adapter's migration was clobbered by the raw style overlay and the
    // class was dropped with a warning. The up-front canonicalization now
    // folds the class into style.typography.textAlign, so the delivered
    // markup keeps the centering.
    $project->writeText(
        'theme/parts/signature.html',
        '<!-- wp:heading {"level":2,"style":{"typography":{"lineHeight":"1.1"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" '
            . 'style="line-height:1.1">Signature Flavours</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/signature.html');
        assert_contains('Signature Flavours', $fixed, 'the heading keeps its content');
        assert_contains(
            'has-text-align-center',
            $fixed,
            'the alignment class survives in the delivered markup',
        );
        assert_contains(
            '"textAlign":"center"',
            $fixed,
            'the alignment is mirrored into the comment JSON so it is canonical',
        );
        assert_contains('"lineHeight":"1.1"', $fixed, 'the authored style keys survive alongside it');

        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'alignment class'),
            'a preserved alignment cannot be warned about as lost',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep restores its complete snapshot when alignment repair I/O fails', function () {
    $markup = '<!-- wp:heading {"level":2,"style":{"typography":{'
        . '"lineHeight":"1.1"}}} --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="line-height:1.1">Signature Flavours</h2><!-- /wp:heading -->';
    foreach ([
        'stage' => [1, new FixBlocksAlignmentFailWriter(failStageAt: 2)],
        'replace' => [2, new FixBlocksAlignmentFailWriter(failReplaceAt: 4)],
    ] as $mode => [$fileCount, $writer]) {
        $tmp = sys_get_temp_dir() . '/fix-blocks-alignment-' . $mode . '-failure-' . uniqid();
        $project = new Project($tmp);
        $files = [];
        for ($index = 0; $index < $fileCount; $index++) {
            $relative = 'parts/signature-' . $index . '.html';
            $files[] = $relative;
            $project->writeText('theme/' . $relative, $markup);
        }

        try {
            $error = assert_throws(
                static fn () => (new FixBlocksStep(new PhpBlockFixer(writer: $writer)))
                    ->run($project),
            );
            assert_contains("alignment {$mode} failure", $error->getMessage());
            foreach ($files as $relative) {
                assert_eq(
                    $markup,
                    $project->readText('theme/' . $relative),
                    "{$mode} failure restores {$relative} to step-entry bytes",
                );
            }
            assert_contains(
                'text-alignment repair transaction failed; restored step-entry bytes',
                $project->readText('logs/fix-blocks.log'),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

test('FixBlocksStep keeps repaired block attributes safely escaped inside their comment', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-safe-alignment-comment-' . uniqid();
    $project = new Project($tmp);
    $safeName = '\\u002d\\u002d\\u003e\\u003cscript\\u003ealert(1)'
        . '\\u003c/script\\u003e\\u003c!\\u002d\\u002d';
    $project->writeText(
        'theme/parts/safe-comment.html',
        '<!-- wp:heading {"level":2,"style":{"typography":{"lineHeight":"1.1"}},'
            . '"metadata":{"name":"' . $safeName . '"}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" '
            . 'style="line-height:1.1">Safe title</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/safe-comment.html');
        assert_contains(
            '"name":"' . $safeName . '"',
            $fixed,
            'decoded metadata is re-escaped before returning to a block comment',
        );
        assert_true(
            !str_contains($fixed, '<script>'),
            'comment data cannot terminate the delimiter and become live markup',
        );
        assert_contains('"textAlign":"center"', $fixed, 'the safe repair reaches canonical attrs');
        assert_contains(
            'has-text-align-center',
            $fixed,
            'the re-serialized heading proves the repaired delimiter remained parseable',
        );

        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'alignment class'),
            'a safely completed repair leaves no false residual warning',
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        $fixedAgain = $project->readText('theme/parts/safe-comment.html');
        assert_eq(
            $fixed,
            $fixedAgain,
            'the safely escaped repair is the byte-identical fixed point on a second run',
        );
        assert_true(
            !str_contains($fixedAgain, '<script>'),
            'the fixed-point retry cannot turn comment data into executable markup',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep warns instead of choosing between conflicting root alignment signals', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-conflicting-root-alignment-' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-center" style="text-align:right">'
        . 'Conflicting title</h2>'
        . '<!-- /wp:heading -->';
    $project->writeText(
        'theme/parts/conflict.html',
        $original,
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/conflict.html');
        assert_eq(
            $original,
            $fixed,
            'an ambiguous block is delivered byte-for-byte instead of choosing the losing class',
        );
        assert_contains('text-align:right', $fixed, 'the browser-winning inline alignment survives');
        assert_contains('has-text-align-center', $fixed, 'the authored conflict remains observable');

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('left parts/conflict.html unmodified', $joined);
        assert_contains('has-text-align-center', $joined, 'the warning retains the class signal');
        assert_contains('right', $joined, 'the warning retains the conflicting inline direction');
        assert_contains('pre-step markup delivered byte-for-byte', $joined);
        assert_true(
            !str_contains($project->readText('logs/fix-blocks.log'), '[alignment] REPAIR:'),
            'a conflicting root inline declaration is not reported as repaired',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep preserves paragraph bytes when duplicate style collapse would change alignment', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-paragraph-cascade-' . uniqid();
    $project = new Project($tmp);
    $styles = [
        'priority.html' => 'text-align:right!important;text-align:center',
        'reset-priority.html' => 'all:unset!important;all:initial',
        'reset-reorder.html' => 'all:unset;text-align:right;all:initial',
        'align-reorder.html' => 'text-align:right;all:unset;text-align:center',
        'same-value-priority.html' => 'text-align:right!important;color:red;text-align:right',
    ];
    $originals = [];
    foreach ($styles as $file => $style) {
        $originals[$file] = '<!-- wp:paragraph --><p style="' . $style . '">Copy</p>'
            . '<!-- /wp:paragraph -->';
        $project->writeText('theme/parts/' . $file, $originals[$file]);
    }

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        foreach ($originals as $file => $original) {
            assert_eq(
                $original,
                $project->readText('theme/parts/' . $file),
                "{$file} is delivered byte-for-byte",
            );
            assert_contains("left parts/{$file} unmodified", $warnings);
        }
        assert_contains('style-map projection changes effective text alignment', $warnings);
        assert_contains('pre-step markup delivered byte-for-byte', $warnings);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep preserves inline heading semantics without a durable frozen mirror', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-inline-heading-semantics-' . uniqid();
    $project = new Project($tmp);
    $cases = [
        'align.html' => '<!-- wp:heading {"align":"right"} -->'
            . '<h2 style="text-align:right">Align is not heading text alignment</h2>'
            . '<!-- /wp:heading -->',
        'uppercase.html' => '<!-- wp:heading {"textAlign":"RIGHT"} -->'
            . '<h2 style="text-align:right">Enum spelling is not reviewed</h2>'
            . '<!-- /wp:heading -->',
        'important.html' => '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:center!\\69mportant">Priority matters</h2>'
            . '<!-- /wp:heading -->',
        'legacy-align.html' => '<!-- wp:heading {"textAlign":"left","align":"center"} -->'
            . '<h2 class="wp-block-heading" style="text-align:left">Legacy is suppressed</h2>'
            . '<!-- /wp:heading -->',
        'ambiguous-root.html' => '<!-- wp:heading -->lead'
            . '<h2 style="text-align:right">No sole saved root</h2><!-- /wp:heading -->',
        'invalid-canonical.html' => '<!-- wp:heading {"style":{"typography":{'
            . '"textAlign":true}}} --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:center">Invalid canonical state</h2><!-- /wp:heading -->',
        'nested-css.html' => '<!-- wp:heading {"style":{"typography":{'
            . '"textAlign":"center"}}} --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:right;x{text-align:center}">Nested CSS is not inline</h2>'
            . '<!-- /wp:heading -->',
        'comment-priority.html' => '<!-- wp:heading {"style":{"typography":{'
            . '"textAlign":"right"}}} --><h2 class="wp-block-heading has-text-align-right" '
            . 'style="text-align:right!im/**/portant;text-align:center">'
            . 'A comment cannot join priority tokens</h2><!-- /wp:heading -->',
    ];
    foreach ($cases as $file => $markup) {
        $project->writeText('theme/parts/' . $file, $markup);
    }

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        foreach ($cases as $file => $markup) {
            assert_eq(
                $markup,
                $project->readText('theme/parts/' . $file),
                "{$file} keeps the browser-effective inline bytes",
            );
        }
        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        foreach (array_keys($cases) as $file) {
            assert_contains("left parts/{$file} unmodified", $warnings);
        }
        assert_contains('text-align:right', $warnings);
        assert_contains('text-align:left', $warnings);
        assert_contains('center!\\69mportant', $warnings);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep isolates a conflicting optional theme page instead of aborting', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-page-alignment-' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading has-text-align-center" style="text-align:right">'
        . 'Page title</h2><!-- /wp:heading -->'
        . '<!-- wp:image {"style":{"border":{"radius":"99px"}}} -->'
        . '<figure class="wp-block-image"><img src="photo.jpg" alt=""/></figure>'
        . '<!-- /wp:image -->';
    $project->writeJson('designDirection.json', ['shape' => 'soft']);
    $project->writeText('theme/pages/conflict.html', $original);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        assert_eq($original, $project->readText('theme/pages/conflict.html'));
        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('left pages/conflict.html unmodified', $warnings);
        assert_contains('pre-step markup delivered byte-for-byte', $warnings);
        assert_true(
            !str_contains($warnings, 'corner property'),
            'optional pages never visited by ShapeMarkup get no false rollback warning',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('an unsafe descendant baseline cannot be upgraded by a later safe-root baseline', function () {
    $transformer = new class implements \Automattic\SiteBuild\BlockSerializer\TemplateTransformer {
        private \Automattic\SiteBuild\BlockSerializer\Serializer $serializer;

        public function __construct()
        {
            $this->serializer = new \Automattic\SiteBuild\BlockSerializer\Serializer();
        }

        public function transform(string $html): \Automattic\SiteBuild\BlockSerializer\TransformResult
        {
            if (str_contains($html, '"textAlign":"right"')) {
                return $this->serializer->transform($html);
            }

            if (!str_contains($html, '<!-- /wp:group -->')) {
                $repaired = str_replace(
                    '<p>Copy <span class="has-text-align-right">child</span></p>',
                    '<p class="has-text-align-right">Copy <span>child</span></p>',
                    $html,
                );
                $repaired .= '</div><!-- /wp:group -->';
                return new \Automattic\SiteBuild\BlockSerializer\TransformResult($repaired);
            }

            if (str_contains($html, '"layout":{"type":"constrained"}')) {
                return new \Automattic\SiteBuild\BlockSerializer\TransformResult(
                    str_replace(
                        'class="has-text-align-right"',
                        'class="has-text-align-center"',
                        $html,
                    ),
                );
            }

            return new \Automattic\SiteBuild\BlockSerializer\TransformResult($html);
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-blocks-two-baseline-provenance-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/provenance.html',
        '<!-- wp:group {"align":"wide"} --><div class="wp-block-group alignwide">'
            . '<!-- wp:paragraph --><p>Copy <span class="has-text-align-right">child</span></p>'
            . '<!-- /wp:paragraph -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer($transformer)))->run($project);

        $fixed = $project->readText('theme/parts/provenance.html');
        assert_true(
            !str_contains($fixed, '"textAlign":"right"'),
            'later root provenance cannot make an originally descendant class repairable',
        );
        assert_true(
            !str_contains($fixed, '<p class="has-text-align-right">'),
            'the final paragraph root is not assigned the descendant alignment',
        );
        assert_contains(
            '<p class="has-text-align-center">',
            $fixed,
            'a later root replacement remains separate from descendant delivery evidence',
        );

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joined = implode("\n", $warnings);
        $alignmentRows = array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains(
                $warning,
                'parts/provenance.html block 0/0 (core/paragraph): alignment class',
            ),
        ));
        assert_eq(1, count($alignmentRows), 'one authored token produces one repair-queue row');
        assert_contains('parts/provenance.html block 0/0 (core/paragraph)', $joined);
        assert_contains('alignment class "has-text-align-right" could not be preserved', $joined);
        assert_contains('owned descendant', $alignmentRows[0]);
        assert_contains('delivered removed', $joined);
        assert_true(
            !str_contains($project->readText('logs/fix-blocks.log'), '[alignment] REPAIR:'),
            'mixed descendant/root provenance remains a warning instead of becoming a repair',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('a later root baseline is not repair proof when the authored tree was non-comparable', function () {
    $transformer = new class implements \Automattic\SiteBuild\BlockSerializer\TemplateTransformer {
        private \Automattic\SiteBuild\BlockSerializer\Serializer $serializer;

        public function __construct()
        {
            $this->serializer = new \Automattic\SiteBuild\BlockSerializer\Serializer();
        }

        public function transform(string $html): \Automattic\SiteBuild\BlockSerializer\TransformResult
        {
            if (str_contains($html, '"textAlign":"right"')) {
                return $this->serializer->transform($html);
            }

            if (str_contains($html, '<!-- wp:html')) {
                return new \Automattic\SiteBuild\BlockSerializer\TransformResult(
                    '<!-- wp:group {"align":"wide"} -->' . "\n"
                        . '<div class="wp-block-group alignwide">' . "\n"
                        . '<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1"}}} -->'
                        . "\n"
                        . '<h2 class="wp-block-heading has-text-align-right" '
                        . 'style="line-height:1.1">Copy</h2>' . "\n"
                        . '<!-- /wp:heading -->' . "\n"
                        . '</div>' . "\n"
                        . '<!-- /wp:group -->',
                );
            }

            if (str_contains($html, '"layout":{"type":"constrained"}')) {
                return new \Automattic\SiteBuild\BlockSerializer\TransformResult(
                    str_replace(' has-text-align-right', '', $html),
                );
            }

            return new \Automattic\SiteBuild\BlockSerializer\TransformResult($html);
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-blocks-non-comparable-provenance-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/non-comparable.html',
        '<!-- wp:html --><div>Copy <span class="has-text-align-right">child</span></div>'
            . '<!-- /wp:html -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer($transformer)))->run($project);

        $fixed = $project->readText('theme/parts/non-comparable.html');
        assert_true(
            !str_contains($fixed, '"textAlign":"right"'),
            'a later safe-looking root cannot replace missing authored-root proof',
        );
        assert_true(
            !str_contains($fixed, '<h2 class="wp-block-heading has-text-align-right"'),
            'the structurally synthesized heading is not assigned the earlier descendant class',
        );

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('parts/non-comparable.html block 0/0 (core/heading)', $joined);
        assert_contains('alignment class "has-text-align-right" could not be preserved', $joined);
        assert_contains('delivered removed', $joined);
        assert_true(
            !str_contains($project->readText('logs/fix-blocks.log'), '[alignment] REPAIR:'),
            'later-only root evidence remains a warning rather than authorizing promotion',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep preserves an ambiguous paragraph with descendant alignment', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-descendant-alignment-' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:paragraph --><p>Before <div class="has-text-align-right">Child</div> After</p>'
        . '<!-- /wp:paragraph -->';
    $project->writeText(
        'theme/parts/descendant.html',
        $original,
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/descendant.html');
        assert_eq($original, $fixed, 'ambiguous root bytes are delivered without transformation');

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('left parts/descendant.html unmodified', $joined);
        assert_contains('has no sole expected saved root', $joined);
        assert_contains('has-text-align-right', $joined, 'the warning retains the descendant signal');
        assert_contains('pre-step markup delivered byte-for-byte', $joined);
        assert_true(
            !str_contains($project->readText('logs/fix-blocks.log'), '[alignment] REPAIR:'),
            'an ambiguous descendant class is not reported as a root alignment repair',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep repairs a lost root class even when the same class survives on a descendant', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-root-descendant-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/root-descendant.html',
        '<!-- wp:heading {"level":2,"style":{"typography":{"lineHeight":"1.1"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" style="line-height:1.1">'
            . 'Root <span class="has-text-align-center">inner</span> tail</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/root-descendant.html');
        assert_contains('"textAlign":"center"', $fixed);
        assert_contains(
            '<h2 class="wp-block-heading has-text-align-center" style="line-height:1.1">',
            $fixed,
            'the saved root regains its alignment instead of borrowing descendant survival',
        );
        assert_contains(
            '<span class="has-text-align-center">inner</span>',
            $fixed,
            'the already-surviving descendant remains byte-identical',
        );
        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(!str_contains(implode("\n", $warnings), 'alignment class'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep does not warn when a descendant class survives a new matching root class', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-surviving-descendant-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/surviving-descendant.html',
        '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->'
            . '<h2 class="wp-block-heading">Root '
            . '<span class="has-text-align-center">inner</span></h2><!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/surviving-descendant.html');
        assert_contains('<h2 class="wp-block-heading has-text-align-center">', $fixed);
        assert_contains('<span class="has-text-align-center">inner</span>', $fixed);
        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'alignment class'),
            'a surviving same-scope descendant is not misreported as removed',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep does not run its serializer over custom fixer output or siblings', function () {
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            $path = $themeDir . '/parts/mixed-custom.html';
            $markup = (string) file_get_contents($path);
            file_put_contents(
                $path,
                str_replace(
                    'wp-block-heading has-text-align-center',
                    'wp-block-heading',
                    $markup,
                ),
            );
            return '[custom-fixer] removed heading class only';
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-blocks-sibling-transaction-' . uniqid();
    $project = new Project($tmp);
    $sibling = '<!-- wp:paragraph --><p data-proof="keep" style="opacity:0.4">Sibling</p>'
        . '<!-- /wp:paragraph -->';
    $project->writeText(
        'theme/parts/mixed-custom.html',
        '<!-- wp:heading {"level":2,"style":{"typography":{"lineHeight":"1.1"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" '
            . 'style="line-height:1.1">Target</h2><!-- /wp:heading -->'
            . $sibling,
    );

    try {
        (new FixBlocksStep($fixer))->run($project);

        $fixed = $project->readText('theme/parts/mixed-custom.html');
        assert_true(
            !str_contains($fixed, '"textAlign":"center"'),
            'a custom fixer loss is not passed through an unreported second serializer',
        );
        assert_true(
            !str_contains($fixed, 'has-text-align-center'),
            'the custom fixer output remains the delivered transaction',
        );
        $siblingStart = strpos($fixed, '<!-- wp:paragraph -->');
        assert_true($siblingStart !== false, 'the unrelated sibling remains present');
        assert_eq(
            $sibling,
            substr($fixed, $siblingStart),
            'the custom fixer transaction leaves the unrelated sibling byte-for-byte intact',
        );

        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'opacity'),
            'preserved sibling styling needs no degradation warning',
        );
        $joined = implode("\n", $warnings);
        assert_contains('parts/mixed-custom.html block 0 (core/heading)', $joined);
        assert_contains('alignment class "has-text-align-center" could not be preserved', $joined);
        assert_contains('delivered removed', $joined, 'the custom fixer loss remains actionable');
        $log = $project->readText('logs/fix-blocks.log');
        assert_true(
            !str_contains($log, '[alignment] REPAIR:'),
            'a legacy custom fixer does not trigger an unreported repair pass',
        );
        assert_contains('[alignment] WARNING:', $log, 'the custom fixer loss remains reported');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep preserves a paragraph alignment authored only as an HTML class (BIGR-779)', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-kicker-alignment-' . uniqid();
    $project = new Project($tmp);
    // The cohort shape: a section kicker paragraph whose centering exists
    // only as a class on the <p>, with no comment JSON at all (pulso
    // schedule kicker) and one whose comment carries unrelated style keys
    // (atlas eyebrows). Both must deliver centred, and a right-aligned
    // price (tbilisi) must deliver right-aligned.
    $project->writeText(
        'theme/parts/kickers.html',
        '<!-- wp:paragraph --><p class="has-text-align-center">Night one &amp; two</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.1em"}}} -->'
            . '<p class="has-text-align-center" style="letter-spacing:0.1em">Free for 30 days</p>'
            . '<!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p class="has-text-align-right">14 GEL</p><!-- /wp:paragraph -->'
            // The dominant cohort shape (pulso schedule kicker): the model
            // mirrors the alignment as the registered top-level align key,
            // which save() derives no class from, alongside other style keys
            // that clobber the adapter migration.
            . '<!-- wp:paragraph {"align":"center","textColor":"secondary","style":{"typography":{"letterSpacing":"0.18em","textTransform":"uppercase","fontWeight":"500"}}} -->'
            . '<p class="has-text-align-center has-secondary-color has-text-color" '
            . 'style="font-weight:500;letter-spacing:0.18em;text-transform:uppercase">Hornstull / Art District</p>'
            . '<!-- /wp:paragraph -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/kickers.html');
        preg_match_all('/<p[^>]*class="[^"]*has-text-align-center/', $fixed, $centred);
        assert_eq(3, count($centred[0]), 'all three centred kickers render centred');
        preg_match_all('/<p[^>]*class="[^"]*has-text-align-right/', $fixed, $righted);
        assert_eq(1, count($righted[0]), 'the right-aligned price renders right-aligned');
        assert_contains('"letterSpacing":"0.1em"', $fixed, 'authored sibling style keys survive');
        assert_contains('"letterSpacing":"0.18em"', $fixed, 'the align:center kicker keeps its styles');
        assert_contains('has-secondary-color', $fixed, 'preset color classes survive the fold');

        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_true(
            !str_contains(implode("\n", $warnings), 'alignment class'),
            'no alignment-loss warning remains for preserved classes',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep preserves durable paragraph inline alignment through a fixed point', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-paragraph-inline-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/inline-alignment.html',
        '<!-- wp:paragraph {"style":{"typography":{"textAlign":"start"}}} -->'
            . '<p style="text-align:start">Start-aligned copy</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p style="text-align:justify">Justified copy</p>'
            . '<!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"align":"wide"} -->'
            . '<p class="alignwide" style="text-align:center">Wide centred copy</p>'
            . '<!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"align":"full"} -->'
            . '<p class="alignfull" style="text-align:right">Full right copy</p>'
            . '<!-- /wp:paragraph -->'
            . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center">'
            . 'Repair another block</h2><!-- /wp:heading -->',
    );

    try {
        $step = new FixBlocksStep(new PhpBlockFixer());
        $step->run($project);

        $fixed = $project->readText('theme/parts/inline-alignment.html');
        assert_contains('style="text-align:start"', $fixed);
        assert_contains('style="text-align:justify"', $fixed);
        assert_contains('class="alignwide" style="text-align:center"', $fixed);
        assert_contains('class="alignfull" style="text-align:right"', $fixed);
        assert_contains('has-text-align-center', $fixed, 'the independent heading is repaired');
        $warnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_eq([], $warnings, 'durable inline paragraph values need no repair warning');

        $step->run($project);
        assert_eq(
            $fixed,
            $project->readText('theme/parts/inline-alignment.html'),
            'a second pass preserves the exact delivered bytes',
        );
        $rerunWarnings = $project->exists('warnings.json')
            ? ($project->readJson('warnings.json')['fix-blocks'] ?? [])
            : [];
        assert_eq($warnings, $rerunWarnings, 'the fixed point adds no warning noise');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('a paragraph opacity repair leaves a co-located preserved heading alignment unwarned', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-mixed-alignment-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'theme/parts/mixed.html',
        '<!-- wp:paragraph --><p style="opacity:0.4">Readable</p><!-- /wp:paragraph -->'
            . '<!-- wp:heading {"level":2,"style":{"typography":{"lineHeight":"1.1"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" '
            . 'style="line-height:1.1">Still centred</h2>'
            . '<!-- /wp:heading -->',
    );

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/mixed.html');
        assert_contains('has-text-align-center', $fixed, 'the heading stays centred');

        $warnings = $project->readJson('warnings.json')['fix-blocks'] ?? [];
        assert_eq(1, count($warnings), 'only the opacity degradation warns');
        $joined = implode("\n", $warnings);
        assert_contains('parts/mixed.html block 0: core/paragraph style "opacity"', $joined);
        assert_true(
            !str_contains($joined, 'alignment class'),
            'the preserved centering produces no loss warning',
        );
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

        $fixedPoint = [];
        foreach (['aligned-opacity.html', 'conflicting-align.html', 'hidden-opacity.html'] as $file) {
            $fixedPoint[$file] = $project->readText('theme/parts/' . $file);
        }
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        foreach ($fixedPoint as $file => $bytes) {
            assert_eq($bytes, $project->readText('theme/parts/' . $file));
        }
        assert_eq(
            $warnings,
            $project->readJson('warnings.json')['fix-blocks'] ?? [],
            'a fixed-point rerun adds no generic failed-file warning',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep isolates malformed legacy alignment containers with actionable warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_legacy_alignment_shape_' . uniqid();
    $project = new Project($tmp);
    $badStyle = '<!-- wp:heading {"textAlign":"center","style":"keep-style"} -->'
        . '<h2 class="wp-block-heading has-text-align-center">Title</h2>'
        . '<!-- /wp:heading -->';
    $badTypography = '<!-- wp:paragraph {"textAlign":"center",'
        . '"style":{"typography":"keep-typography"}} -->'
        . '<p class="has-text-align-center">Copy</p><!-- /wp:paragraph -->';
    $safe = '<!-- wp:heading {"textAlign":"center","level":2,'
        . '"style":{"typography":{"lineHeight":"1.1"}}} -->'
        . '<h2 class="wp-block-heading has-text-align-center" style="line-height:1.1">'
        . 'Safe sibling</h2><!-- /wp:heading -->';
    $project->writeText('theme/parts/a-bad-style.html', $badStyle);
    $project->writeText('theme/parts/b-bad-typography.html', $badTypography);
    $project->writeText('theme/parts/c-safe.html', $safe);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        // Delimiter line breaks are the fixer's normal formatting; what must
        // survive intact is every attribute, class, and byte of content.
        assert_eq(
            str_replace("\n", '', $badStyle),
            str_replace("\n", '', $project->readText('theme/parts/a-bad-style.html')),
            'a malformed style container is delivered with its content intact',
        );
        assert_eq(
            str_replace("\n", '', $badTypography),
            str_replace("\n", '', $project->readText('theme/parts/b-bad-typography.html')),
            'a malformed typography container is delivered with its content intact',
        );
        assert_contains(
            '"typography":{"lineHeight":"1.1","textAlign":"center"}',
            $project->readText('theme/parts/c-safe.html'),
            'a valid sibling still receives the safe legacy alignment fold',
        );

        $joined = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('preserved core/heading', $joined);
        assert_contains('at parts/a-bad-style.html', $joined);
        assert_contains('core/heading at 0: authored style "keep-style" is not an object', $joined);
        assert_contains('preserved core/paragraph', $joined);
        assert_contains('at parts/b-bad-typography.html', $joined);
        assert_contains(
            'core/paragraph at 0: authored style.typography "keep-typography" is not an object',
            $joined,
        );
        assert_contains('its authored bytes were delivered while sibling blocks were still normalized', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep isolates unreviewed validation failures per block with a durable warning', function () {
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
            // but the failure is isolated to the BLOCK: its authored bytes
            // are delivered (modulo delimiter newlines) with a durable
            // warnings.json record naming the block, and the build continues.
            (new FixBlocksStep(new PhpBlockFixer()))->run($project);
            assert_eq(
                str_replace("\n", '', $original),
                str_replace("\n", '', $project->readText('theme/parts/section.html')),
                "{$name} must deliver its authored bytes",
            );
            $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
            assert_contains(
                'preserved core/',
                $joined,
                "{$name} must be recorded as a preserved block",
            );
            assert_contains('parts/section.html', $joined);
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

        // The unsupported block is delivered verbatim with a durable record;
        // its sibling's width-contract repair still lands.
        assert_eq(
            str_replace("\n", '', $unsupportedOriginal),
            str_replace("\n", '', $project->readText('theme/parts/b-unsupported.html')),
        );
        assert_contains('"layout"', $project->readText('theme/parts/a-layout.html'));
        $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('preserved core/query', $joined);
        assert_contains('parts/b-unsupported.html', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixBlocksStep keeps layout work and preserves only the unsupported child block', function () {
    $tmp = sys_get_temp_dir() . '/builder_fix_compound_failure_' . uniqid();
    $project = new Project($tmp);
    $mixedOriginal = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull">'
        . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->'
        . '</div><!-- /wp:group -->';
    $siblingOriginal = '<!-- wp:group {"align":"full"} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    // header.html also exercises the final constrained-part reassertion on a
    // file that now carries a preserved child.
    $project->writeText('theme/parts/header.html', $mixedOriginal);
    $project->writeText('theme/parts/b-sibling.html', $siblingOriginal);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $header = $project->readText('theme/parts/header.html');
        assert_contains(
            '"layout"',
            $header,
            'the parent group keeps its layout normalization and re-serialization',
        );
        assert_contains(
            '<div class="wp-block-query"></div>',
            $header,
            'the unsupported child is preserved inside the normalized parent',
        );
        assert_contains(
            '"layout":{"type":"constrained"}',
            $project->readText('theme/parts/b-sibling.html'),
            'a healthy sibling keeps its successful layout and serialization work',
        );
        $joined = implode(' ', $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('preserved core/query', $joined);
        assert_contains('parts/header.html', $joined);
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
