<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\AlignmentClassLoss;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\TemplateTransformer;
use Automattic\SiteBuild\BlockSerializer\TextAlignmentRepairer;
use Automattic\SiteBuild\BlockSerializer\TransformResult;
use Automattic\SiteBuild\PhpBlockFixer;

/** In-memory staged content with an observable commit boundary. */
final class TextAlignmentRepairerTestWriter implements StagedFileWriter
{
    public int $stages = 0;
    public int $replacements = 0;

    /** @var list<string> */
    public array $discarded = [];

    /** @var array<string,string> */
    private array $pending = [];

    public function __construct(
        private readonly ?int $failStageAt = null,
        private readonly ?int $failReplaceAt = null,
    ) {}

    public function stage(string $target, string $content): string
    {
        $this->stages++;
        if ($this->stages === $this->failStageAt) {
            throw new RuntimeException('injected alignment stage failure');
        }
        $staged = $target . '.alignment-stage-' . $this->stages;
        $this->pending[$staged] = $content;
        return $staged;
    }

    public function replace(string $staged, string $target): void
    {
        $this->replacements++;
        if ($this->replacements === $this->failReplaceAt) {
            throw new RuntimeException('injected alignment replace failure');
        }
        if (!array_key_exists($staged, $this->pending)) {
            throw new RuntimeException('unknown alignment stage');
        }
        file_put_contents($target, $this->pending[$staged]);
        unset($this->pending[$staged]);
    }

    public function discard(string $staged): void
    {
        $this->discarded[] = $staged;
        unset($this->pending[$staged]);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }
}

/** @return array{0:string,1:string,2:AlignmentClassLoss} */
function text_alignment_repairer_fixture(string $base): array
{
    mkdir($base . '/parts', 0775, true);
    $heading = '<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1"}}} -->' . "\n"
        . '<h2 class="wp-block-heading" style="line-height:1.1">Target</h2>' . "\n"
        . '<!-- /wp:heading -->';
    $sibling = '<!-- wp:paragraph --><p data-proof="keep" style="opacity:0.4">Sibling</p>'
        . '<!-- /wp:paragraph -->';
    file_put_contents($base . '/parts/mixed.html', $heading . "\n\n" . $sibling);
    return [
        $heading,
        $sibling,
        new AlignmentClassLoss(
            blockPath: '0',
            blockName: 'core/heading',
            authoredClass: 'has-text-align-center',
            deliveredClasses: [],
            authoredClassOnSavedRoot: true,
            authoredClassIsSafeRootTextAlignment: true,
        ),
    ];
}

function text_alignment_repairer_class_only_transformer(): TemplateTransformer
{
    return new class implements TemplateTransformer {
        public int $calls = 0;

        public function transform(string $html): TransformResult
        {
            $this->calls++;
            return new TransformResult(str_replace(
                'class="wp-block-heading"',
                'class="wp-block-heading has-text-align-center"',
                $html,
            ));
        }
    };
}

test('TextAlignmentRepairer stages a fixed-point target repair without serializing its sibling', function () {
    with_temp_dir('text-alignment-repairer-', function (string $base): void {
        [, $sibling, $loss] = text_alignment_repairer_fixture($base);
        $writer = new TextAlignmentRepairerTestWriter();
        $fixer = new PhpBlockFixer(writer: $writer);

        $rows = $fixer->repairTextAlignmentLosses($base, [['parts/mixed.html', $loss]]);
        $fixed = (string) file_get_contents($base . '/parts/mixed.html');

        assert_eq(1, $writer->stages, 'the complete containing file is staged once');
        assert_eq(1, $writer->replacements, 'the staged file is committed once');
        assert_contains('style.typography.textAlign', implode("\n", $rows));
        assert_contains('"textAlign":"center"', $fixed);
        assert_contains('wp-block-heading has-text-align-center', $fixed);
        $siblingStart = strpos($fixed, '<!-- wp:paragraph -->');
        assert_true($siblingStart !== false);
        assert_eq(
            $sibling,
            substr($fixed, $siblingStart),
            'the unrelated sibling remains byte-for-byte identical',
        );

        $first = $fixed;
        assert_eq([], $fixer->repairTextAlignmentLosses($base, [['parts/mixed.html', $loss]]));
        assert_eq($first, file_get_contents($base . '/parts/mixed.html'));
        assert_eq(1, $writer->stages, 'a fixed-point retry stages nothing');
        assert_eq(1, $writer->replacements, 'a fixed-point retry commits nothing');
    });
});

test('TextAlignmentRepairer discards prior stages and writes nothing when a later stage fails', function () {
    with_temp_dir('text-alignment-repairer-stage-', function (string $base): void {
        [, , $loss] = text_alignment_repairer_fixture($base);
        $firstPath = $base . '/parts/mixed.html';
        $secondPath = $base . '/parts/second.html';
        $original = (string) file_get_contents($firstPath);
        file_put_contents($secondPath, $original);
        $writer = new TextAlignmentRepairerTestWriter(failStageAt: 2);
        $repairer = new TextAlignmentRepairer(writer: $writer);

        $error = assert_throws(
            static fn () => $repairer->repair($base, [
                ['parts/mixed.html', $loss],
                ['parts/second.html', $loss],
            ]),
        );
        assert_contains('Could not stage text-alignment repair output', $error->getMessage());
        assert_eq(2, $writer->stages);
        assert_eq(0, $writer->replacements);
        assert_eq(1, count($writer->discarded), 'the first completed stage is discarded');
        assert_eq(0, $writer->pendingCount());
        assert_eq($original, file_get_contents($firstPath));
        assert_eq($original, file_get_contents($secondPath));
    });
});

test('TextAlignmentRepairer leaves only complete bytes after a mid-commit failure', function () {
    with_temp_dir('text-alignment-repairer-replace-', function (string $base): void {
        [, , $loss] = text_alignment_repairer_fixture($base);
        $paths = [
            'mixed' => $base . '/parts/mixed.html',
            'second' => $base . '/parts/second.html',
            'third' => $base . '/parts/third.html',
        ];
        $original = (string) file_get_contents($paths['mixed']);
        file_put_contents($paths['second'], $original);
        file_put_contents($paths['third'], $original);
        $writer = new TextAlignmentRepairerTestWriter(failReplaceAt: 2);
        $repairer = new TextAlignmentRepairer(writer: $writer);

        $error = assert_throws(
            static fn () => $repairer->repair($base, [
                ['parts/mixed.html', $loss],
                ['parts/second.html', $loss],
                ['parts/third.html', $loss],
            ]),
        );

        assert_contains('Could not commit text-alignment repair output', $error->getMessage());
        assert_eq(3, $writer->stages, 'every complete file stages before commit begins');
        assert_eq(2, $writer->replacements);
        assert_eq(2, count($writer->discarded), 'failed and later stages are discarded');
        assert_eq(0, $writer->pendingCount());
        assert_contains(
            '"textAlign":"center"',
            (string) file_get_contents($paths['mixed']),
            'the earlier atomic replacement remains complete',
        );
        assert_eq($original, file_get_contents($paths['second']));
        assert_eq($original, file_get_contents($paths['third']));
    });
});

test('TextAlignmentRepairer abandons a non-converging target without staging', function () {
    with_temp_dir('text-alignment-repairer-non-converging-', function (string $base): void {
        [, , $loss] = text_alignment_repairer_fixture($base);
        $path = $base . '/parts/mixed.html';
        $original = (string) file_get_contents($path);
        $transformer = new class implements TemplateTransformer {
            public int $calls = 0;

            public function transform(string $html): TransformResult
            {
                $this->calls++;
                return new TransformResult($html . "\n");
            }
        };
        $writer = new TextAlignmentRepairerTestWriter();
        $repairer = new TextAlignmentRepairer($transformer, writer: $writer);

        assert_eq([], $repairer->repair($base, [['parts/mixed.html', $loss]]));
        assert_eq(5, $transformer->calls, 'the fixed-point attempt is bounded');
        assert_eq($original, file_get_contents($path));
        assert_eq(0, $writer->stages);
        assert_eq(0, $writer->replacements);
    });
});

test('TextAlignmentRepairer rejects an unrelated empty-object to empty-array mutation', function () {
    with_temp_dir('text-alignment-repairer-json-identity-', function (string $base): void {
        mkdir($base . '/parts', 0775, true);
        $path = $base . '/parts/metadata.html';
        $original = '<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1"}},"metadata":{}} -->'
            . "\n" . '<h2 class="wp-block-heading" style="line-height:1.1">Target</h2>'
            . "\n" . '<!-- /wp:heading -->';
        file_put_contents($path, $original);
        $loss = new AlignmentClassLoss(
            blockPath: '0',
            blockName: 'core/heading',
            authoredClass: 'has-text-align-center',
            deliveredClasses: [],
            authoredClassOnSavedRoot: true,
            authoredClassIsSafeRootTextAlignment: true,
        );
        $transformer = new class implements TemplateTransformer {
            public int $calls = 0;

            public function transform(string $html): TransformResult
            {
                $this->calls++;
                return new TransformResult(str_replace(
                    ['"metadata":{}', 'class="wp-block-heading"'],
                    ['"metadata":[]', 'class="wp-block-heading has-text-align-center"'],
                    $html,
                ));
            }
        };
        $writer = new TextAlignmentRepairerTestWriter();
        $repairer = new TextAlignmentRepairer($transformer, writer: $writer);

        assert_eq([], $repairer->repair($base, [['parts/metadata.html', $loss]]));
        assert_eq(2, $transformer->calls, 'the candidate converges before strict comparison');
        assert_eq($original, file_get_contents($path));
        assert_eq(0, $writer->stages);
        assert_eq(0, $writer->replacements);
    });
});

test('TextAlignmentRepairer revalidates delivered inline alignment before trusting a loss DTO', function () {
    foreach (['text-align:right', 'all:unset'] as $style) {
        with_temp_dir('text-alignment-repairer-delivered-css-', function (string $base) use ($style): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/conflict.html';
            $original = '<!-- wp:heading --><!-- note --><h2 class="wp-block-heading" style="'
                . $style . '">Target</h2><!-- /wp:heading -->';
            file_put_contents($path, $original);
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $writer = new TextAlignmentRepairerTestWriter();

            assert_eq(
                [],
                (new TextAlignmentRepairer(writer: $writer))->repair(
                    $base,
                    [['parts/conflict.html', $loss]],
                ),
            );
            assert_eq($original, file_get_contents($path));
            assert_eq(0, $writer->stages);
            assert_eq(0, $writer->replacements);
        });
    }
});

test('TextAlignmentRepairer rejects conflicting delivered comment alignment signals', function () {
    foreach ([
        '{"textAlign":"right"}',
        '{"className":"has-text-align-right"}',
        '{"align":"right"}',
        '{"align":"baseline"}',
        '{"align":"none"}',
        '{"align":false}',
    ] as $comment) {
        with_temp_dir('text-alignment-repairer-delivered-comment-', function (string $base) use ($comment): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/conflict.html';
            $original = '<!-- wp:heading ' . $comment . ' -->'
                . '<h2 class="wp-block-heading">Target</h2><!-- /wp:heading -->';
            file_put_contents($path, $original);
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $writer = new TextAlignmentRepairerTestWriter();

            assert_eq(
                [],
                (new TextAlignmentRepairer(writer: $writer))->repair(
                    $base,
                    [['parts/conflict.html', $loss]],
                ),
            );
            assert_eq($original, file_get_contents($path));
            assert_eq(0, $writer->stages);
            assert_eq(0, $writer->replacements);
        });
    }
});

test('TextAlignmentRepairer accepts only reviewed non-conflicting comment align values', function () {
    foreach ([
        '{}',
        '{"align":""}',
        '{"align":"wide"}',
        '{"align":"full"}',
        '{"align":"center"}',
    ] as $comment) {
        with_temp_dir('text-alignment-repairer-comment-align-', function (string $base) use ($comment): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/alignment.html';
            file_put_contents(
                $path,
                '<!-- wp:heading ' . $comment . ' -->'
                    . '<h2 class="wp-block-heading">Target</h2><!-- /wp:heading -->',
            );
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $transformer = text_alignment_repairer_class_only_transformer();
            $writer = new TextAlignmentRepairerTestWriter();

            $rows = (new TextAlignmentRepairer($transformer, writer: $writer))->repair(
                $base,
                [['parts/alignment.html', $loss]],
            );

            assert_eq(1, count($rows), "reviewed align value in {$comment} is repairable");
            assert_eq(2, $transformer->calls, 'the repaired target reaches a fixed point');
            assert_eq(1, $writer->stages);
            assert_eq(1, $writer->replacements);
            assert_contains(
                'has-text-align-center',
                (string) file_get_contents($path),
            );
        });
    }
});

test('TextAlignmentRepairer overwrites only inert canonical comment textAlign values', function () {
    foreach (['null', '""', 'false', '0'] as $inert) {
        with_temp_dir('text-alignment-repairer-inert-text-align-', function (string $base) use ($inert): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/alignment.html';
            file_put_contents(
                $path,
                '<!-- wp:heading {"style":{"typography":{"textAlign":' . $inert
                    . ',"lineHeight":"1.1"}}} -->'
                    . '<h2 class="wp-block-heading" style="line-height:1.1">Target</h2>'
                    . '<!-- /wp:heading -->',
            );
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $transformer = text_alignment_repairer_class_only_transformer();
            $writer = new TextAlignmentRepairerTestWriter();

            $rows = (new TextAlignmentRepairer($transformer, writer: $writer))->repair(
                $base,
                [['parts/alignment.html', $loss]],
            );

            assert_eq(1, count($rows), "inert textAlign {$inert} is replaceable");
            assert_eq(2, $transformer->calls);
            assert_eq(1, $writer->stages);
            assert_eq(1, $writer->replacements);
            assert_contains(
                '"textAlign":"center"',
                (string) file_get_contents($path),
            );
        });
    }

    foreach (['"center"', '"right"', 'true', '1', '{}', '[]'] as $meaningful) {
        with_temp_dir('text-alignment-repairer-meaningful-text-align-', function (string $base) use ($meaningful): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/alignment.html';
            $original = '<!-- wp:heading {"style":{"typography":{"textAlign":'
                . $meaningful . '}}} -->'
                . '<h2 class="wp-block-heading">Target</h2><!-- /wp:heading -->';
            file_put_contents($path, $original);
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $transformer = text_alignment_repairer_class_only_transformer();
            $writer = new TextAlignmentRepairerTestWriter();

            assert_eq(
                [],
                (new TextAlignmentRepairer($transformer, writer: $writer))->repair(
                    $base,
                    [['parts/alignment.html', $loss]],
                ),
                "meaningful textAlign {$meaningful} is preserved",
            );
            assert_eq(0, $transformer->calls);
            assert_eq($original, file_get_contents($path));
            assert_eq(0, $writer->stages);
            assert_eq(0, $writer->replacements);
        });
    }
});

test('TextAlignmentRepairer rejects theme-path escapes before staging or touching outside bytes', function () {
    with_temp_dir('text-alignment-repairer-path-', function (string $scope): void {
        $theme = $scope . '/theme';
        [, , $loss] = text_alignment_repairer_fixture($theme);
        $sentinel = $scope . '/outside.html';
        $outside = '<!-- wp:heading --><h2 class="wp-block-heading">Outside</h2><!-- /wp:heading -->';
        file_put_contents($sentinel, $outside);
        $link = $theme . '/parts/outside-link.html';
        assert_true(symlink($sentinel, $link), 'the outside-path fixture symlink is created');

        foreach (['../outside.html', $sentinel, 'parts/outside-link.html'] as $file) {
            $writer = new TextAlignmentRepairerTestWriter();
            $error = assert_throws(
                static fn () => (new TextAlignmentRepairer(writer: $writer))->repair(
                    $theme,
                    [[$file, $loss]],
                ),
            );

            assert_true($error instanceof RuntimeException);
            assert_contains('alignment-repair', strtolower($error->getMessage()));
            assert_eq(0, $writer->stages, "{$file} cannot reach staging");
            assert_eq(0, $writer->replacements, "{$file} cannot reach replacement");
            assert_eq(0, $writer->pendingCount());
            assert_eq($outside, file_get_contents($sentinel), "{$file} leaves outside bytes untouched");
        }
    });
});

test('TextAlignmentRepairer rejects unsafe raw JSON spelling even when typed attributes match', function () {
    with_temp_dir('text-alignment-repairer-raw-json-', function (string $base): void {
        mkdir($base . '/parts', 0775, true);
        $path = $base . '/parts/metadata.html';
        $safeLessThan = '\\u003c';
        $original = '<!-- wp:heading {"style":{"typography":{"lineHeight":"1.1"}},'
            . '"metadata":{"name":"' . $safeLessThan . 'unsafe"}} -->'
            . "\n" . '<h2 class="wp-block-heading" style="line-height:1.1">Target</h2>'
            . "\n" . '<!-- /wp:heading -->';
        file_put_contents($path, $original);
        $loss = new AlignmentClassLoss(
            blockPath: '0',
            blockName: 'core/heading',
            authoredClass: 'has-text-align-center',
            deliveredClasses: [],
            authoredClassOnSavedRoot: true,
            authoredClassIsSafeRootTextAlignment: true,
        );
        $transformer = new class implements TemplateTransformer {
            public int $calls = 0;

            public function transform(string $html): TransformResult
            {
                $this->calls++;
                return new TransformResult(str_replace(
                    ['\\u003cunsafe', 'class="wp-block-heading"'],
                    ['<unsafe', 'class="wp-block-heading has-text-align-center"'],
                    $html,
                ));
            }
        };
        $writer = new TextAlignmentRepairerTestWriter();
        $repairer = new TextAlignmentRepairer($transformer, writer: $writer);

        assert_eq([], $repairer->repair($base, [['parts/metadata.html', $loss]]));
        assert_eq(2, $transformer->calls, 'the unsafe candidate converges before strict comparison');
        assert_eq($original, file_get_contents($path));
        assert_eq(0, $writer->stages);
        assert_eq(0, $writer->replacements);
    });
});

test('TextAlignmentRepairer requires canonical matching input delimiters and saved-root closers', function () {
    $cases = [
        'malformed opener JSON' => '<!-- wp:heading {"style":} -->'
            . '<h2 class="wp-block-heading">Target</h2><!-- /wp:heading -->',
        'noncanonical Gutenberg closer' => '<!-- wp:heading -->'
            . '<h2 class="wp-block-heading">Target</h2><!-- /wp:heading  -->',
        'mismatched Gutenberg closer' => '<!-- wp:heading -->'
            . '<h2 class="wp-block-heading">Target</h2><!-- /wp:paragraph -->',
        'missing saved-root closer' => '<!-- wp:heading -->'
            . '<h2 class="wp-block-heading">Target<!-- /wp:heading -->',
        'noncanonical saved-root closer' => '<!-- wp:heading -->'
            . '<h2 class="wp-block-heading">Target</H2><!-- /wp:heading -->',
        'mismatched saved-root closer' => '<!-- wp:heading -->'
            . '<h2 class="wp-block-heading">Target</h3><!-- /wp:heading -->',
    ];

    foreach ($cases as $label => $original) {
        with_temp_dir('text-alignment-repairer-input-structure-', function (string $base) use ($label, $original): void {
            mkdir($base . '/parts', 0775, true);
            $path = $base . '/parts/structure.html';
            file_put_contents($path, $original);
            $loss = new AlignmentClassLoss(
                blockPath: '0',
                blockName: 'core/heading',
                authoredClass: 'has-text-align-center',
                deliveredClasses: [],
                authoredClassOnSavedRoot: true,
                authoredClassIsSafeRootTextAlignment: true,
            );
            $transformer = text_alignment_repairer_class_only_transformer();
            $writer = new TextAlignmentRepairerTestWriter();

            assert_eq(
                [],
                (new TextAlignmentRepairer($transformer, writer: $writer))->repair(
                    $base,
                    [['parts/structure.html', $loss]],
                ),
                "{$label} is not repaired",
            );
            assert_eq(0, $transformer->calls, "{$label} fails before transformation");
            assert_eq($original, file_get_contents($path));
            assert_eq(0, $writer->stages);
            assert_eq(0, $writer->replacements);
        });
    }
});

test('TextAlignmentRepairer rejects candidates with noncanonical or mismatched closers', function () {
    $mutations = [
        'missing Gutenberg block closer' => ["\n<!-- /wp:heading -->", ''],
        'noncanonical Gutenberg block closer' => ['<!-- /wp:heading -->', '<!-- /wp:heading  -->'],
        'mismatched Gutenberg block closer' => ['<!-- /wp:heading -->', '<!-- /wp:paragraph -->'],
        'missing saved heading closer' => ['</h2>', ''],
        'noncanonical saved heading closer' => ['</h2>', '</H2>'],
        'mismatched saved heading closer' => ['</h2>', '</h3>'],
    ];

    foreach ($mutations as $label => [$search, $replacement]) {
        with_temp_dir('text-alignment-repairer-output-structure-', function (string $base) use ($label, $search, $replacement): void {
            [, , $loss] = text_alignment_repairer_fixture($base);
            $path = $base . '/parts/mixed.html';
            $original = (string) file_get_contents($path);
            $transformer = new class($search, $replacement) implements TemplateTransformer {
                public int $calls = 0;

                public function __construct(
                    private readonly string $search,
                    private readonly string $replacement,
                ) {}

                public function transform(string $html): TransformResult
                {
                    $this->calls++;
                    $candidate = str_replace(
                        'class="wp-block-heading"',
                        'class="wp-block-heading has-text-align-center"',
                        $html,
                    );
                    $candidate = str_replace($this->search, $this->replacement, $candidate);
                    return new TransformResult($candidate);
                }
            };
            $writer = new TextAlignmentRepairerTestWriter();
            $repairer = new TextAlignmentRepairer($transformer, writer: $writer);

            assert_eq(
                [],
                $repairer->repair($base, [['parts/mixed.html', $loss]]),
                "a candidate with {$label} is not accepted",
            );
            assert_eq(2, $transformer->calls, "the {$label} candidate converges before validation");
            assert_eq($original, file_get_contents($path));
            assert_eq(0, $writer->stages);
            assert_eq(0, $writer->replacements);
        });
    }
});

test('TextAlignmentRepairer does not choose between two lost root alignment classes', function () {
    with_temp_dir('text-alignment-repairer-ambiguous-', function (string $base): void {
        [, , $center] = text_alignment_repairer_fixture($base);
        $path = $base . '/parts/mixed.html';
        $original = (string) file_get_contents($path);
        $right = new AlignmentClassLoss(
            blockPath: '0',
            blockName: 'core/heading',
            authoredClass: 'has-text-align-right',
            deliveredClasses: [],
            authoredClassOnSavedRoot: true,
            authoredClassIsSafeRootTextAlignment: true,
        );
        $writer = new TextAlignmentRepairerTestWriter();

        assert_eq(
            [],
            (new TextAlignmentRepairer(writer: $writer))->repair(
                $base,
                [['parts/mixed.html', $center], ['parts/mixed.html', $right]],
            ),
        );
        assert_eq($original, file_get_contents($path));
        assert_eq(0, $writer->stages);
        assert_eq(0, $writer->replacements);
    });
});
