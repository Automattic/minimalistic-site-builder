<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\TemplateTransformer;
use Automattic\SiteBuild\BlockSerializer\TransformResult;
use Automattic\SiteBuild\PhpBlockFixer;

/** Deterministic injectable transform with a reviewable call log. */
final class PhpBlockFixerTestTransformer implements TemplateTransformer
{
    /** @var list<string> */
    public array $calls = [];

    /** @param Closure(string,int):TransformResult $transform */
    public function __construct(private readonly Closure $transform)
    {
    }

    public function transform(string $html): TransformResult
    {
        $this->calls[] = $html;
        return ($this->transform)($html, count($this->calls));
    }
}

/**
 * In-memory staging with real, all-at-once target replacement.
 *
 * This deliberately never writes a target from stage(). replace() performs one
 * complete file_put_contents(), so the tests can distinguish staging from the
 * commit boundary and inspect every byte left after an injected failure.
 */
final class PhpBlockFixerTestWriter implements StagedFileWriter
{
    public int $stageCalls = 0;
    public int $replaceCalls = 0;

    /** @var list<string> */
    public array $discarded = [];

    /** @var array<string,string> */
    private array $staged = [];

    public function __construct(
        private readonly ?int $failStageAt = null,
        private readonly ?int $failReplaceAt = null,
    ) {
    }

    public function stage(string $target, string $content): string
    {
        $this->stageCalls++;
        if ($this->stageCalls === $this->failStageAt) {
            throw new RuntimeException('injected stage failure');
        }

        $path = $target . '.php-block-fixer-test-stage-' . $this->stageCalls;
        $this->staged[$path] = $content;
        return $path;
    }

    public function replace(string $staged, string $target): void
    {
        $this->replaceCalls++;
        if ($this->replaceCalls === $this->failReplaceAt) {
            throw new RuntimeException('injected replace failure');
        }
        if (!array_key_exists($staged, $this->staged)) {
            throw new RuntimeException('test writer received an unknown staged path');
        }
        $written = file_put_contents($target, $this->staged[$staged]);
        if ($written !== strlen($this->staged[$staged])) {
            throw new RuntimeException('test writer could not write complete target bytes');
        }
        unset($this->staged[$staged]);
    }

    public function discard(string $staged): void
    {
        $this->discarded[] = $staged;
        unset($this->staged[$staged]);
    }

    public function pendingCount(): int
    {
        return count($this->staged);
    }
}

/** @param array<string,string> $files relative theme path => bytes */
function php_block_fixer_test_theme(array $files = []): string
{
    $root = sys_get_temp_dir() . '/php-block-fixer-' . bin2hex(random_bytes(8));
    $theme = $root . '/theme';
    if (!mkdir($theme, 0775, true) && !is_dir($theme)) {
        throw new RuntimeException('Could not create PHP block fixer test theme');
    }
    foreach ($files as $relative => $content) {
        $path = $theme . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Could not create PHP block fixer fixture directory');
        }
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException('Could not write PHP block fixer fixture');
        }
    }
    return $theme;
}

function php_block_fixer_test_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            php_block_fixer_test_remove($path . '/' . $name);
        }
    }
    @rmdir($path);
}

function php_block_fixer_test_exception(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected operation to throw');
}

test('PhpBlockFixer reaches a fixed point, reports exact drops and repairs, and is a no-op on retry', function () {
    $original = '<!-- wp:paragraph --><p class="keep lost" style=\'padding-top:4rem;color:red\'>dirty'
        . '<span class=\'lost lost\' style="padding-top:4rem"></span></p><!-- /wp:paragraph -->';
    $middle = '<!-- wp:paragraph --><p class="keep">clean </p><!-- /wp:paragraph -->';
    $canonical = '<!-- wp:paragraph --><p class="keep">clean</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme(['parts/content.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static function (string $html) use ($original, $middle, $canonical): TransformResult {
            return match ($html) {
                $original => new TransformResult($middle, [
                    new Repair('custom-class-recovered', '0'),
                    new Repair('custom-class-recovered', '0'),
                ]),
                $middle => new TransformResult($canonical, [
                    new Repair('custom-class-recovered', '0'),
                    new Repair('anchor-recovered', '0'),
                ]),
                $canonical => new TransformResult($canonical),
                default => throw new RuntimeException('unexpected transform input'),
            };
        }
    );
    $fixer = new PhpBlockFixer($transformer, writer: $writer);

    try {
        $first = $fixer->fix($theme);

        assert_eq($canonical, file_get_contents($theme . '/parts/content.html'));
        assert_eq(3, count($transformer->calls), 'two changing passes plus one stability observation');
        assert_eq(1, $writer->stageCalls);
        assert_eq(1, $writer->replaceCalls);
        assert_eq(0, $writer->pendingCount());
        assert_contains(
            '[fix-templates] 1/1 file(s) re-serialized, 2 issue(s) fixed, '
                . '3 style/class value(s) dropped across 1 theme(s).',
            $first
        );
        assert_contains('  FIXED  parts/content.html', $first);
        assert_contains('DROPPED style `padding-top:4rem` (x2)', $first, 'both HTML quote styles are counted');
        assert_contains('DROPPED style `color:red`', $first);
        assert_contains('DROPPED class `lost` (x3)', $first);
        assert_eq(1, substr_count($first, 'REPAIR custom-class-recovered at 0'), 'repairs deduplicate across passes');
        assert_eq(1, substr_count($first, 'REPAIR anchor-recovered at 0'));

        $second = $fixer->fix($theme);

        assert_eq($canonical, file_get_contents($theme . '/parts/content.html'));
        assert_eq(4, count($transformer->calls), 'a second public call observes stability in one pass');
        assert_eq(1, $writer->stageCalls, 'a no-op must not stage a replacement');
        assert_eq(1, $writer->replaceCalls, 'a no-op must not commit a replacement');
        assert_eq(
            "[fix-templates] 0/1 file(s) re-serialized, 0 issue(s) fixed, "
                . "0 style/class value(s) dropped across 1 theme(s).\n"
                . '  ok     parts/content.html',
            $second,
            'the second invocation emits no new drop or repair row'
        );
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer skips non-block HTML and does not invoke transformation or writes', function () {
    $theme = php_block_fixer_test_theme(['templates/plain.html' => "<main>Freeform only</main>\n"]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static fn (string $html): TransformResult => throw new RuntimeException('skip file was transformed')
    );

    try {
        $report = (new PhpBlockFixer($transformer, writer: $writer))->fix($theme);

        assert_eq([], $transformer->calls);
        assert_eq(0, $writer->stageCalls);
        assert_eq("<main>Freeform only</main>\n", file_get_contents($theme . '/templates/plain.html'));
        assert_eq(
            "[fix-templates] 0/0 file(s) re-serialized, 0 issue(s) fixed, "
                . "0 style/class value(s) dropped across 1 theme(s).\n"
                . '  skip   templates/plain.html',
            $report
        );
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer reports an empty theme without requiring templates or parts directories', function () {
    $theme = php_block_fixer_test_theme();
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static fn (string $html): TransformResult => throw new RuntimeException('empty theme was transformed')
    );

    try {
        assert_eq(
            '[fix-templates] 0/0 file(s) re-serialized, 0 issue(s) fixed, '
                . '0 style/class value(s) dropped across 1 theme(s).',
            (new PhpBlockFixer($transformer, writer: $writer))->fix($theme)
        );
        assert_eq([], $transformer->calls);
        assert_eq(0, $writer->stageCalls);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer discovers only immediate lowercase HTML files in deterministic relative-path order', function () {
    $partsBlock = '<!-- wp:paragraph --><p>parts</p><!-- /wp:paragraph -->';
    $templateBlock = '<!-- wp:paragraph --><p>template</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'templates/a.html' => $templateBlock,
        'parts/z.html' => $partsBlock,
        'parts/nested/ignored.html' => '<!-- wp:paragraph --><p>nested</p><!-- /wp:paragraph -->',
        'parts/upper.HTML' => '<!-- wp:paragraph --><p>upper</p><!-- /wp:paragraph -->',
        'templates/m.html' => '<main>skip</main>',
    ]);
    $transformer = new PhpBlockFixerTestTransformer(
        static fn (string $html): TransformResult => new TransformResult($html)
    );

    try {
        $report = (new PhpBlockFixer($transformer, writer: new PhpBlockFixerTestWriter()))->fix($theme);

        assert_eq([$partsBlock, $templateBlock], $transformer->calls);
        $partsAt = strpos($report, 'parts/z.html');
        $templateAt = strpos($report, 'templates/a.html');
        $skipAt = strpos($report, 'templates/m.html');
        assert_true(is_int($partsAt) && is_int($templateAt) && is_int($skipAt));
        assert_true($partsAt < $templateAt && $templateAt < $skipAt, 'report paths use normalized lexical order');
        assert_true(!str_contains($report, 'nested/ignored.html'));
        assert_true(!str_contains($report, 'upper.HTML'));
        assert_contains('[fix-templates] 0/2 file(s) re-serialized', $report, 'skips do not count as eligible');
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer rejects an empty, missing, or non-directory theme path', function () {
    $missing = sys_get_temp_dir() . '/missing-php-block-fixer-' . bin2hex(random_bytes(8));
    $file = tempnam(sys_get_temp_dir(), 'php-block-fixer-file-');
    if ($file === false) {
        throw new RuntimeException('Could not create non-directory fixture');
    }
    $fixer = new PhpBlockFixer(
        new PhpBlockFixerTestTransformer(static fn (string $html): TransformResult => new TransformResult($html)),
        writer: new PhpBlockFixerTestWriter(),
    );

    try {
        $emptyError = php_block_fixer_test_exception(static fn () => $fixer->fix(''));
        $missingError = php_block_fixer_test_exception(static fn () => $fixer->fix($missing));
        $fileError = php_block_fixer_test_exception(static fn () => $fixer->fix($file));

        assert_true($emptyError instanceof RuntimeException);
        assert_contains('does not exist', $emptyError->getMessage());
        assert_true($missingError instanceof RuntimeException);
        assert_contains('does not exist', $missingError->getMessage());
        assert_true($fileError instanceof RuntimeException);
        assert_contains('not a directory', $fileError->getMessage());
    } finally {
        @unlink($file);
    }
});

test('PhpBlockFixer permits convergence on pass five', function () {
    $states = array_map(
        static fn (int $number): string => '<!-- wp:paragraph --><p>state-' . $number . '</p><!-- /wp:paragraph -->',
        range(0, 4),
    );
    $theme = php_block_fixer_test_theme(['parts/state.html' => $states[0]]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static function (string $html) use ($states): TransformResult {
            $at = array_search($html, $states, true);
            if (!is_int($at)) {
                throw new RuntimeException('unknown convergence state');
            }
            return new TransformResult($states[min($at + 1, 4)]);
        }
    );

    try {
        (new PhpBlockFixer($transformer, writer: $writer))->fix($theme);
        assert_eq(5, count($transformer->calls));
        assert_eq($states[4], file_get_contents($theme . '/parts/state.html'));
        assert_eq(1, $writer->replaceCalls);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer fails closed when pass five still changes bytes', function () {
    $initial = '<!-- wp:paragraph --><p>state-0</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme(['parts/state.html' => $initial]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static function (string $html): TransformResult {
            if (preg_match('/state-(\d+)/', $html, $match) !== 1) {
                throw new RuntimeException('unknown non-convergence state');
            }
            return new TransformResult(str_replace($match[0], 'state-' . ((int) $match[1] + 1), $html));
        }
    );

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer($transformer, writer: $writer))->fix($theme)
        );

        assert_true($error instanceof RuntimeException);
        assert_contains('did not converge within 5 passes for parts/state.html', $error->getMessage());
        assert_eq(5, count($transformer->calls));
        assert_eq(0, $writer->stageCalls, 'non-convergence is detected before staging');
        assert_eq($initial, file_get_contents($theme . '/parts/state.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer leaves every file unchanged when a later transformation throws', function () {
    $aOriginal = '<!-- wp:paragraph --><p>dirty-a</p><!-- /wp:paragraph -->';
    $aCanonical = '<!-- wp:paragraph --><p>clean-a</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:paragraph --><p>dirty-b</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static function (string $html) use ($aOriginal, $aCanonical, $bOriginal): TransformResult {
            return match ($html) {
                $aOriginal => new TransformResult($aCanonical),
                $aCanonical => new TransformResult($aCanonical),
                $bOriginal => throw new RuntimeException('injected renderer failure'),
                default => throw new RuntimeException('unexpected transform input'),
            };
        }
    );

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer($transformer, writer: $writer))->fix($theme)
        );

        assert_true($error instanceof RuntimeException);
        assert_contains('Block transformation failed for parts/b.html on pass 1', $error->getMessage());
        assert_contains('injected renderer failure', $error->getMessage());
        assert_eq(0, $writer->stageCalls, 'all transformations must finish before the first stage');
        assert_eq(0, $writer->replaceCalls);
        assert_eq($aOriginal, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer preflights the real registry guard before staging any file', function () {
    $aOriginal = '<!-- wp:paragraph --><p>Supported</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer(writer: $writer))->fix($theme)
        );

        assert_contains(
            "Registered block 'core/query' is outside the supported PHP domain",
            $error->getMessage(),
        );
        assert_eq(0, $writer->stageCalls, 'registry preflight must finish before the first stage');
        assert_eq(0, $writer->replaceCalls);
        assert_eq($aOriginal, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer rejects an unknown deprecation signature before staging any file', function () {
    $aOriginal = '<!-- wp:paragraph --><p>Supported</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:paragraph {"customTextColor":"#ff0000"} -->'
        . '<p style="color:#ff0000">Legacy</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer(writer: $writer))->fix($theme)
        );

        assert_contains(
            "Unsupported comment attribute 'customTextColor' for core/paragraph",
            $error->getMessage(),
        );
        assert_contains('a reviewed deprecation adapter is required', $error->getMessage());
        assert_eq(0, $writer->stageCalls, 'deprecation preflight must finish before the first stage');
        assert_eq(0, $writer->replaceCalls);
        assert_eq($aOriginal, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer rejects a current-key historical style signature before staging', function () {
    $aOriginal = '<!-- wp:paragraph --><p>Supported</p><!-- /wp:paragraph -->';
    // An invalid paragraph whose root is not a <p> is outside the reviewed
    // selector-less carryover (paragraph-inline-color-carryover golden), so
    // its authored inline color still fails closed before staging.
    $bOriginal = '<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.12em"}}} -->'
        . '<div style="letter-spacing:0.12em;color:var(--wp--preset--color--accent)">'
        . 'Legacy</div><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer(writer: $writer))->fix($theme)
        );

        assert_contains(
            'Unsupported deprecated core/paragraph style signature at 0: color',
            $error->getMessage(),
        );
        assert_eq(0, $writer->stageCalls);
        assert_eq(0, $writer->replaceCalls);
        assert_eq($aOriginal, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer rejects an unsupported block-support family before staging', function () {
    $original = '<!-- wp:group {"style":{"filter":{"blur":"2px"}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/unsupported.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer(writer: $writer))->fix($theme)
        );

        assert_contains(
            "Unsupported block-support path 'style.filter' for core/group at 0",
            $error->getMessage(),
        );
        assert_eq(0, $writer->stageCalls);
        assert_eq(0, $writer->replaceCalls);
        assert_eq($original, file_get_contents($theme . '/parts/unsupported.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer rejects nested style and layout variants before staging', function () {
    $cases = [
        // color.duotone is a real pinned support this pipeline does not
        // implement, so it must fail closed rather than be pruned.
        '<!-- wp:group {"style":{"color":{"duotone":"var:preset|duotone|dark"}}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->',
        // Unreviewed elements paths are carried per block, but real
        // unimplemented style families like per-block CSS still fail closed.
        '<!-- wp:group {"style":{"css":"color:red"}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->',
        '<!-- wp:group {"layout":{"type":"grid"}} -->'
            . '<div class="wp-block-group"></div><!-- /wp:group -->',
    ];

    foreach ($cases as $original) {
        $theme = php_block_fixer_test_theme(['parts/unsupported.html' => $original]);
        $writer = new PhpBlockFixerTestWriter();
        try {
            php_block_fixer_test_exception(
                static fn () => (new PhpBlockFixer(writer: $writer))->fix($theme)
            );
            assert_eq(0, $writer->stageCalls);
            assert_eq(0, $writer->replaceCalls);
            assert_eq($original, file_get_contents($theme . '/parts/unsupported.html'));
        } finally {
            php_block_fixer_test_remove(dirname($theme));
        }
    }
});

test('PhpBlockFixer prunes invented style keys, reports each removal, and converges', function () {
    $original = '<!-- wp:group {"style":{"spacing":'
        . '{"mediaPadding":{"top":"0","right":"0","bottom":"0","left":"0"},"margin":{"top":"2rem"}},'
        . '"glow":"6px"}} -->'
        . '<div class="wp-block-group" style="margin-top:2rem"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/invented.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);
        $fixed = file_get_contents($theme . '/parts/invented.html');

        assert_true(is_string($fixed));
        assert_true(!str_contains($fixed, 'mediaPadding'), 'the invented spacing key is removed from output');
        assert_true(!str_contains($fixed, 'glow'), 'the invented root key is removed from output');
        assert_contains('"margin":{"top":"2rem"}', $fixed, 'known sibling style state survives pruning');
        assert_contains('  FIXED  parts/invented.html', $report);
        assert_contains('REPAIR invented-style-pruned:spacing.mediaPadding at 0', $report);
        assert_contains('REPAIR invented-style-pruned:glow at 0', $report);

        $second = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($theme);
        assert_contains('  ok     parts/invented.html', $second, 'pruned output is a fixed point');
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer removes an entirely invented style attribute and its empty parents', function () {
    $original = '<!-- wp:group {"style":{"spacing":{"mediaPadding":{"top":"0"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/invented.html' => $original]);

    try {
        $report = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($theme);
        $fixed = file_get_contents($theme . '/parts/invented.html');

        assert_true(is_string($fixed));
        assert_true(!str_contains($fixed, '"style"'), 'an emptied style object is not serialized');
        assert_contains('REPAIR invented-style-pruned:spacing.mediaPadding at 0', $report);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer discards prior stages and writes nothing when a later stage fails', function () {
    $aOriginal = '<!-- wp:paragraph --><p>dirty-a</p><!-- /wp:paragraph -->';
    $aCanonical = '<!-- wp:paragraph --><p>clean-a</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:paragraph --><p>dirty-b</p><!-- /wp:paragraph -->';
    $bCanonical = '<!-- wp:paragraph --><p>clean-b</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter(failStageAt: 2);
    $mapping = [
        $aOriginal => $aCanonical,
        $aCanonical => $aCanonical,
        $bOriginal => $bCanonical,
        $bCanonical => $bCanonical,
    ];
    $transformer = new PhpBlockFixerTestTransformer(
        static fn (string $html): TransformResult => new TransformResult($mapping[$html])
    );

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer($transformer, writer: $writer))->fix($theme)
        );

        assert_true($error instanceof RuntimeException);
        assert_contains('Could not stage block-fixer output: injected stage failure', $error->getMessage());
        assert_eq(2, $writer->stageCalls);
        assert_eq(0, $writer->replaceCalls, 'commit cannot begin after any staging failure');
        assert_eq(1, count($writer->discarded), 'the first completed stage is discarded');
        assert_eq(0, $writer->pendingCount());
        assert_eq($aOriginal, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});

test('PhpBlockFixer leaves only complete original or canonical bytes after mid-commit failure and retry converges', function () {
    $originals = [];
    $canonicals = [];
    $files = [];
    foreach (['a', 'b', 'c'] as $name) {
        $originals[$name] = '<!-- wp:paragraph --><p>dirty-' . $name . '</p><!-- /wp:paragraph -->';
        $canonicals[$name] = '<!-- wp:paragraph --><p>clean-' . $name . '</p><!-- /wp:paragraph -->';
        $files['parts/' . $name . '.html'] = $originals[$name];
    }
    $theme = php_block_fixer_test_theme($files);
    $mapping = [];
    foreach ($originals as $name => $original) {
        $mapping[$original] = $canonicals[$name];
        $mapping[$canonicals[$name]] = $canonicals[$name];
    }
    $transform = static fn (string $html): TransformResult => new TransformResult($mapping[$html]);
    $failingWriter = new PhpBlockFixerTestWriter(failReplaceAt: 2);

    try {
        $error = php_block_fixer_test_exception(
            static fn () => (new PhpBlockFixer(
                new PhpBlockFixerTestTransformer($transform),
                writer: $failingWriter,
            ))->fix($theme)
        );

        assert_true($error instanceof RuntimeException);
        assert_contains('Could not commit block-fixer output: injected replace failure', $error->getMessage());
        assert_eq(3, $failingWriter->stageCalls, 'all files stage before commit starts');
        assert_eq(2, $failingWriter->replaceCalls);
        assert_eq(2, count($failingWriter->discarded), 'failed and not-yet-committed stages are discarded');
        assert_eq(0, $failingWriter->pendingCount());
        assert_eq($canonicals['a'], file_get_contents($theme . '/parts/a.html'), 'earlier atomic replacement is complete');
        assert_eq($originals['b'], file_get_contents($theme . '/parts/b.html'), 'failed target retains complete original bytes');
        assert_eq($originals['c'], file_get_contents($theme . '/parts/c.html'), 'later target retains complete original bytes');

        $retryWriter = new PhpBlockFixerTestWriter();
        $retryReport = (new PhpBlockFixer(
            new PhpBlockFixerTestTransformer($transform),
            writer: $retryWriter,
        ))->fix($theme);

        assert_contains('[fix-templates] 2/3 file(s) re-serialized', $retryReport);
        assert_contains('  ok     parts/a.html', $retryReport);
        assert_contains('  FIXED  parts/b.html', $retryReport);
        assert_contains('  FIXED  parts/c.html', $retryReport);
        assert_eq(2, $retryWriter->stageCalls);
        assert_eq(2, $retryWriter->replaceCalls);
        foreach (['a', 'b', 'c'] as $name) {
            assert_eq($canonicals[$name], file_get_contents($theme . '/parts/' . $name . '.html'));
        }

        $stableReport = (new PhpBlockFixer(
            new PhpBlockFixerTestTransformer($transform),
            writer: new PhpBlockFixerTestWriter(),
        ))->fix($theme);
        assert_contains('[fix-templates] 0/3 file(s) re-serialized', $stableReport);
    } finally {
        php_block_fixer_test_remove(dirname($theme));
    }
});
