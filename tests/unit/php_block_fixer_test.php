<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\TemplateTransformer;
use Automattic\SiteBuild\BlockSerializer\TransformResult;
use Automattic\SiteBuild\LayoutFixer;
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
        remove_tree(dirname($theme));
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
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer does not apply Serializer-specific alignment preflight to an injected transformer', function () {
    $original = '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->'
        . '<h2 class="has-text-align-center" style="text-align:right">Title</h2>'
        . '<!-- /wp:heading -->';
    $theme = php_block_fixer_test_theme(['parts/content.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();
    $transformer = new PhpBlockFixerTestTransformer(
        static fn (string $html): TransformResult => new TransformResult($html),
    );

    try {
        $report = (new PhpBlockFixer($transformer, writer: $writer))->fix($theme);

        assert_eq([$original], $transformer->calls, 'the injected contract receives its input');
        assert_contains('ok     parts/content.html', $report);
        assert_true(!str_contains($report, 'FAILED'));
        assert_eq(0, $writer->stageCalls, 'an identity transform does not stage output');
        assert_eq($original, file_get_contents($theme . '/parts/content.html'));
    } finally {
        remove_tree(dirname($theme));
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
        remove_tree(dirname($theme));
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
        remove_tree(dirname($theme));
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
        $emptyError = assert_throws(static fn () => $fixer->fix(''));
        $missingError = assert_throws(static fn () => $fixer->fix($missing));
        $fileError = assert_throws(static fn () => $fixer->fix($file));

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
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates a file that still changes bytes on pass five', function () {
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
        $report = (new PhpBlockFixer($transformer, writer: $writer))->fix($theme);

        // Non-convergence abandons THIS file: pre-fixer bytes delivered
        // untouched, a typed FAILED row on the record, nothing staged.
        assert_contains('FAILED parts/state.html', $report);
        assert_contains('did not converge within 5 passes', $report);
        assert_eq(5, count($transformer->calls));
        assert_eq(0, $writer->stageCalls, 'a failed file is never staged');
        assert_eq($initial, file_get_contents($theme . '/parts/state.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates a throwing file and still fixes its siblings', function () {
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
        $report = (new PhpBlockFixer($transformer, writer: $writer))->fix($theme);

        // The throwing file is isolated with its pre-fixer bytes delivered;
        // the sibling's repair still lands — one bad file no longer discards
        // the whole already-paid-for theme.
        assert_contains('FAILED parts/b.html', $report);
        assert_contains('injected renderer failure', $report);
        assert_eq(1, $writer->stageCalls, 'only the fixed sibling is staged');
        assert_eq(1, $writer->replaceCalls);
        assert_eq($aCanonical, file_get_contents($theme . '/parts/a.html'));
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates a file carrying an unsupported registered block', function () {
    $aOriginal = '<!-- wp:paragraph --><p>Supported</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);

        assert_contains('FAILED parts/b.html', $report);
        assert_contains("Registered block 'core/query' is outside the supported PHP domain", $report);
        assert_contains('FIXED  parts/a.html', $report, 'the supported sibling is still re-serialized');
        assert_eq(1, $writer->replaceCalls);
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates a file carrying an unknown deprecation signature', function () {
    $aOriginal = '<!-- wp:paragraph --><p>Supported</p><!-- /wp:paragraph -->';
    $bOriginal = '<!-- wp:paragraph {"customTextColor":"#ff0000"} -->'
        . '<p style="color:#ff0000">Legacy</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme([
        'parts/a.html' => $aOriginal,
        'parts/b.html' => $bOriginal,
    ]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);

        assert_contains('FAILED parts/b.html', $report);
        assert_contains("Unsupported comment attribute 'customTextColor' for core/paragraph", $report);
        assert_contains('a reviewed deprecation adapter is required', $report);
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer re-serializes a navigation-link carrying the transformer anchorClassName', function () {
    // The pinned transformer emits anchorClassName for every classed anchor it
    // promotes into a navigation. WordPress never registered the attribute, so
    // without a reviewed adapter the whole part is delivered byte-for-byte and
    // every other repair the step would have made is lost with it.
    $original = '<!-- wp:navigation {"className":"nav-links","overlayMenu":"mobile"} -->'
        . '<!-- wp:navigation-link {"className":"nav-reserve","label":"Reservations",'
        . '"url":"/visit/","kind":"custom","anchorClassName":"nav-reserve"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        $report = (new PhpBlockFixer())->fix($theme);
        $fixed = file_get_contents($theme . '/parts/header.html');

        assert_contains('FIXED  parts/header.html', $report);
        assert_true(
            !str_contains($report, "Unsupported comment attribute 'anchorClassName'"),
            'the reviewed adapter admits the signature instead of rejecting the file',
        );

        // Non-vacuity: the block must SURVIVE, not be dropped. A serializer that
        // deleted the navigation-link entirely would also stop throwing.
        assert_eq(1, substr_count($fixed, '<!-- wp:navigation-link '), 'the link block survives');
        assert_eq(1, substr_count($fixed, '<!-- wp:navigation '), 'its parent navigation survives');
        assert_contains('"label":"Reservations"', $fixed);
        assert_contains('"url":"/visit/"', $fixed);
        assert_contains('"kind":"custom"', $fixed);
        // The registered className carries the same authored value, so dropping
        // the unregistered anchor alias loses no authored bytes.
        assert_contains('"className":"nav-reserve"', $fixed);
        assert_true(
            !str_contains($fixed, 'anchorClassName'),
            'the unregistered alias is discarded, matching the pinned createBlock path',
        );
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer still rejects an unregistered navigation-link attribute with no reviewed adapter', function () {
    // The anchorClassName adapter is one reviewed key for one block, not a
    // blanket permissive mode; any other unregistered key must still fail closed.
    $original = '<!-- wp:navigation {"className":"nav-links","overlayMenu":"mobile"} -->'
        . '<!-- wp:navigation-link {"label":"Reservations","url":"/visit/",'
        . '"kind":"custom","anchorTarget":"_blank"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        $report = (new PhpBlockFixer())->fix($theme);

        assert_contains('FAILED parts/header.html', $report);
        assert_contains("Unsupported comment attribute 'anchorTarget' for core/navigation-link", $report);
        assert_contains('a reviewed deprecation adapter is required', $report);
        assert_eq($original, file_get_contents($theme . '/parts/header.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

/**
 * Effective overlayMenu of the sole navigation block: the delimiter value when
 * present, otherwise the registered default. The serializer elides a value that
 * equals the default, so an absent key is not an absent behaviour.
 */
function nav_overlay_menu(string $html): string
{
    // Fail loudly rather than reporting the default when the block is missing:
    // a helper that returns 'mobile' for absent markup would let every
    // assert_eq('mobile', ...) below pass against a deleted block or an empty
    // file, which is the vacuity shape this project rejects slices for.
    $count = substr_count($html, '<!-- wp:navigation ');
    if ($count !== 1) {
        throw new RuntimeException("expected exactly 1 navigation block, found {$count}");
    }
    return preg_match('/<!-- wp:navigation [^>]*?"overlayMenu":"([^"]*)"/', $html, $m) === 1
        ? $m[1]
        : 'mobile';
}

/**
 * True when the navigation carries a TOP-LEVEL (registered preset) fontFamily.
 * Parses the delimiter JSON rather than matching a substring: the nested
 * style.typography.fontFamily lives in the same comment, so a regex would
 * report a preset that is not there.
 */
function nav_has_preset_font_family(string $html): bool
{
    if (preg_match('/<!-- wp:navigation (\{.*?\}) -->/s', $html, $m) !== 1) {
        return false;
    }
    $attrs = json_decode($m[1], true);
    if (!is_array($attrs)) {
        throw new RuntimeException('navigation delimiter JSON did not parse: ' . $m[1]);
    }
    return array_key_exists('fontFamily', $attrs);
}

test('PhpBlockFixer converges on the authored overlayMenu across repeated passes', function () {
    // N1. The navigation deprecation supplies overlayMenu:"never" as a migration
    // default. Re-running the fixer must be a no-op: the adapter's own output is
    // already migrated, so re-applying it would overwrite the authored value.
    $original = '<!-- wp:navigation {"className":"nav-links",'
        . '"style":{"typography":{"fontFamily":"var(--heading)"}},"overlayMenu":"mobile"} -->'
        . '<!-- wp:navigation-link {"className":"nav-reserve","label":"Reservations",'
        . '"url":"/visit/","kind":"custom","anchorClassName":"nav-reserve"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        $seen = [];
        for ($pass = 1; $pass <= 3; $pass++) {
            (new PhpBlockFixer())->fix($theme);
            $seen[$pass] = file_get_contents($theme . '/parts/header.html');
            assert_eq(
                'mobile',
                nav_overlay_menu($seen[$pass]),
                "pass {$pass} must keep the authored overlayMenu, not the migration default",
            );
        }
        assert_eq($seen[1], $seen[2], 'pass 2 is byte-stable');
        assert_eq($seen[2], $seen[3], 'pass 3 is byte-stable');
        // The authored custom family must stay where it was authored.
        // Promoting it to the registered preset attribute would make
        // WordPress resolve it as a slug - pinned by the regression test below.
        assert_true(
            !nav_has_preset_font_family($seen[1]),
            'a custom font-family is not promoted to the registered preset attribute',
        );
        assert_contains('"typography":{"fontFamily":"var(\u002d\u002dheading)"', $seen[1]);
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer converges on the authored overlayMenu for LEGACY navigation content', function () {
    // The legacy-signature gate does NOT exclude this adapter's own output:
    // the migration writes the bare slug into the registered top-level
    // fontFamily and leaves style.typography.fontFamily in the pipe form, so
    // the gate matches again on every later pass. The raw-comment fontFamily
    // guard is what makes the legacy path a fixed point. Deleting that guard
    // while keeping the gate must fail this test.
    $original = '<!-- wp:navigation {"className":"nav-links",'
        . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}},'
        . '"overlayMenu":"mobile"} -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        $seen = [];
        for ($pass = 1; $pass <= 3; $pass++) {
            (new PhpBlockFixer())->fix($theme);
            $seen[$pass] = file_get_contents($theme . '/parts/header.html');
            assert_eq(
                'mobile',
                nav_overlay_menu($seen[$pass]),
                "pass {$pass} must keep the authored overlayMenu on legacy content",
            );
        }
        assert_eq($seen[1], $seen[2], 'pass 2 is byte-stable');
        assert_eq($seen[2], $seen[3], 'pass 3 is byte-stable');
        // The migration DID run: legacy content earns the real preset slug.
        assert_true(nav_has_preset_font_family($seen[1]), 'legacy content is migrated');
        assert_contains('"fontFamily":"heading"', $seen[1], 'the slug is the last pipe segment');
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer does not promote a custom navigation font-family to a preset slug', function () {
    // The legacy migration reads `var:preset|font-family|<slug>` and keeps the
    // last pipe segment. A transformer-authored CUSTOM value has no pipe, so the
    // whole CSS value would land in the registered fontFamily attribute.
    // WordPress prefers that attribute over style.typography.fontFamily and
    // kebab-cases it into --wp--preset--font-family--var-heading, which no
    // theme.json defines, so the authored font silently disappears.
    $original = '<!-- wp:navigation {"className":"nav-links",'
        . '"style":{"typography":{"fontFamily":"var(--heading)"}}} -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        (new PhpBlockFixer())->fix($theme);
        $fixed = file_get_contents($theme . '/parts/header.html');

        assert_eq(1, substr_count($fixed, '<!-- wp:navigation '), 'the navigation survives');
        assert_true(
            !nav_has_preset_font_family($fixed),
            'a custom value must not become a registered preset font-family',
        );
        assert_contains('"typography":{"fontFamily":"var(\u002d\u002dheading)"', $fixed);
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer round-trips an authored attribute whose value equals the registered default', function () {
    // N2. overlayMenu's registered default is "mobile". The serializer elides a
    // value equal to the default, so a later pass cannot tell "authored mobile"
    // from "unspecified" — the interaction that broke convergence.
    $authored = '<!-- wp:navigation {"className":"nav-links",'
        . '"style":{"typography":{"fontFamily":"var(--heading)"}},"overlayMenu":"mobile"} -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $authored]);

    try {
        (new PhpBlockFixer())->fix($theme);
        $fixed = file_get_contents($theme . '/parts/header.html');

        assert_eq('mobile', nav_overlay_menu($fixed), 'the authored default-valued attribute survives');
        assert_true(
            !str_contains($fixed, '"overlayMenu":"never"'),
            'the migration default never overwrites an authored value',
        );
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer still applies the navigation migration to un-migrated content', function () {
    // The forcing rule is not deleted. Content that has NOT yet been migrated -
    // style.typography.fontFamily with no top-level fontFamily and no authored
    // overlayMenu - still receives the deprecation's overlay default.
    // GENUINELY LEGACY content: the pinned save form is a pipe-delimited
    // preset reference, not a raw CSS value. Using a custom value here would
    // pass vacuously against the legacy-signature gate.
    $original = '<!-- wp:navigation {"className":"nav-links",'
        . '"style":{"typography":{"fontFamily":"var:preset|font-family|heading"}}} -->'
        . '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->'
        . '<!-- /wp:navigation -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        (new PhpBlockFixer())->fix($theme);
        $fixed = file_get_contents($theme . '/parts/header.html');

        assert_eq('never', nav_overlay_menu($fixed), 'the migration still supplies its overlay default');
        assert_contains('"fontFamily":"heading"', $fixed, 'the preset slug is the last pipe segment');
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer keeps the anchorClassName adapter scoped to core/navigation-link', function () {
    // A sibling block carrying the same key name must still fail closed.
    $original = '<!-- wp:paragraph {"anchorClassName":"brand"} --><p>Brand</p><!-- /wp:paragraph -->';
    $theme = php_block_fixer_test_theme(['parts/header.html' => $original]);

    try {
        $report = (new PhpBlockFixer())->fix($theme);

        assert_contains('FAILED parts/header.html', $report);
        assert_contains("Unsupported comment attribute 'anchorClassName' for core/paragraph", $report);
        assert_eq($original, file_get_contents($theme . '/parts/header.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates a current-key historical style signature to its file', function () {
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
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);

        assert_contains('FAILED parts/b.html', $report);
        assert_contains('Unsupported deprecated core/paragraph style signature at 0: color', $report);
        assert_eq($bOriginal, file_get_contents($theme . '/parts/b.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates an unsupported block-support family to its file', function () {
    // background is the one pinned-unimplemented family that stays
    // fail-closed: StyleEngine consumes style.background wholesale, so an
    // unreviewed shape could change the emitted bytes.
    $original = '<!-- wp:group {"style":{"background":{"backgroundImage":{"id":42}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/unsupported.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();

    try {
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);

        assert_contains('FAILED parts/unsupported.html', $report);
        assert_contains(
            "Unsupported block-support path 'style.background.backgroundImage.id' for core/group at 0",
            $report,
        );
        assert_eq(0, $writer->stageCalls);
        assert_eq($original, file_get_contents($theme . '/parts/unsupported.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer isolates unreviewed layout variants to their file', function () {
    // 'masonry' is an unreviewed layout type outside the guard's allowlist
    // (grid is now supported); the fixer must isolate it to its own file.
    $original = '<!-- wp:group {"layout":{"type":"masonry"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $theme = php_block_fixer_test_theme(['parts/unsupported.html' => $original]);
    $writer = new PhpBlockFixerTestWriter();
    try {
        $report = (new PhpBlockFixer(writer: $writer))->fix($theme);
        assert_contains('FAILED parts/unsupported.html', $report);
        assert_eq(0, $writer->stageCalls);
        assert_eq($original, file_get_contents($theme . '/parts/unsupported.html'));
    } finally {
        remove_tree(dirname($theme));
    }
});

test('PhpBlockFixer carries inert unimplemented style families without failing', function () {
    // Carried pinned-unimplemented families (css, filter, duotone, layout,
    // …) have no save-path consumer, so the pinned runtime keeps them
    // verbatim in the delimiter and the saved markup is unchanged: canonical
    // input is already a fixed point, and nothing fails.
    $cases = [
        '<!-- wp:group {"style":{"color":{"duotone":"var:preset|duotone|dark"}}} -->'
            . "\n" . '<div class="wp-block-group"></div>'
            . "\n" . '<!-- /wp:group -->',
        '<!-- wp:group {"style":{"css":"color:red"}} -->'
            . "\n" . '<div class="wp-block-group"></div>'
            . "\n" . '<!-- /wp:group -->',
        '<!-- wp:group {"style":{"filter":{"blur":"2px"}}} -->'
            . "\n" . '<div class="wp-block-group"></div>'
            . "\n" . '<!-- /wp:group -->',
        // Verbatim tbilisi60 signature: constrained width misplaced under
        // style.layout while the real top-level layout attribute is present.
        '<!-- wp:group {"style":{"layout":{"contentSize":"720px"}},"layout":{"type":"constrained"}} -->'
            . "\n" . '<div class="wp-block-group"></div>'
            . "\n" . '<!-- /wp:group -->',
    ];

    foreach ($cases as $original) {
        $theme = php_block_fixer_test_theme(['parts/carried.html' => $original]);
        try {
            (new PhpBlockFixer())->fix($theme);
            assert_eq(
                $original,
                file_get_contents($theme . '/parts/carried.html'),
                'carried style state keeps its authored bytes'
            );
        } finally {
            remove_tree(dirname($theme));
        }
    }
});

test('PhpBlockFixer deep-merges duplicate comment JSON keys instead of failing the theme', function () {
    // Verbatim naturaleza repro (BIGR-719): the model declared "style" twice.
    // Last-wins parsing kept only the elements object, the saved HTML still
    // carried style="margin-top:0", and the unmirrored declaration hit the
    // deprecation gate as a fatal unknown signature.
    $original = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"0"}}},'
        . '"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}}} -->'
        . "\n" . '<p style="margin-top:0">'
        . '<a href="mailto:reservas@naturalezasabia.com.ar">reservas@naturalezasabia.com.ar</a></p>'
        . "\n" . '<!-- /wp:paragraph -->';
    // The pre-fix behavior, pinned: the same block after the last-wins
    // collapse (only the second "style" survives) is exactly what the parser
    // used to hand the normalizer, and it still fails closed today.
    $collapsed = '<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}}} -->'
        . "\n" . '<p style="margin-top:0">'
        . '<a href="mailto:reservas@naturalezasabia.com.ar">reservas@naturalezasabia.com.ar</a></p>'
        . "\n" . '<!-- /wp:paragraph -->';

    $collapsedTheme = php_block_fixer_test_theme(['parts/page-home--contact-and-location.html' => $collapsed]);
    $theme = php_block_fixer_test_theme(['parts/page-home--contact-and-location.html' => $original]);
    try {
        $collapsedReport = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($collapsedTheme);
        assert_contains('FAILED parts/page-home--contact-and-location.html', $collapsedReport);
        assert_contains('Unsupported deprecated core/paragraph style signature', $collapsedReport);
        assert_contains('margin-top', $collapsedReport);

        $report = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($theme);
        $fixed = file_get_contents($theme . '/parts/page-home--contact-and-location.html');

        assert_true(is_string($fixed));
        assert_eq(1, substr_count($fixed, '"style"'), 'the duplicate style keys collapse into one declaration');
        assert_contains('"spacing":{"margin":{"top":"0"}}', $fixed, 'the first duplicate\'s spacing survives');
        assert_contains(
            '"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}',
            $fixed,
            'the second duplicate\'s link color survives'
        );
        assert_contains('style="margin-top:0"', $fixed, 'the authored inline margin keeps rendering');
        assert_contains('mailto:reservas@naturalezasabia.com.ar', $fixed);
        assert_contains('  FIXED  parts/page-home--contact-and-location.html', $report);
        assert_contains('REPAIR duplicate-attribute-merged:style at 0', $report);
        assert_true(!str_contains($report, 'DROPPED style'), 'no rhythm CSS is dropped by the merge');

        $second = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($theme);
        assert_contains('  ok     parts/page-home--contact-and-location.html', $second, 'merged output is a fixed point');
    } finally {
        remove_tree(dirname($theme));
        remove_tree(dirname($collapsedTheme));
    }
});

test('escaped-equivalent duplicate keys survive LayoutFixer and block re-serialization', function () {
    $original = '<!-- wp:group {"style":{"elements":{"link":{"color":{"text":"var:preset|color|accent"}}}},'
        . '"\u0073tyle":{"color":{"background":"#123456"}},'
        . '"layout":{"type":"flex","justifyContent":"flex-start"}} -->'
        . '<div class="wp-block-group has-background has-link-color" style="background-color:#123456">'
        . '<!-- wp:paragraph --><p><a href="#">Link</a></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $normalized = LayoutFixer::fix($original, LayoutFixer::ROLE_SECTION, 860.0);
    $theme = php_block_fixer_test_theme(['parts/escaped-duplicate.html' => $normalized['markup']]);

    try {
        assert_contains('attributes declared "style" more than once', implode("\n", $normalized['notes']));
        assert_contains('"justifyContent":"left"', $normalized['markup'], 'a later rule dirties the merged node');

        $report = (new PhpBlockFixer(writer: new PhpBlockFixerTestWriter()))->fix($theme);
        $fixed = file_get_contents($theme . '/parts/escaped-duplicate.html');

        assert_true(is_string($fixed));
        assert_contains(
            '"elements":{"link":{"color":{"text":"var:preset|color|accent"}}}',
            $fixed,
            'the first raw spelling survives'
        );
        assert_contains('"color":{"background":"#123456"}', $fixed, 'the escaped spelling survives');
        assert_contains('0 style/class value(s) dropped', $report);
    } finally {
        remove_tree(dirname($theme));
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
        remove_tree(dirname($theme));
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
        remove_tree(dirname($theme));
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
        $error = assert_throws(
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
        remove_tree(dirname($theme));
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
        $error = assert_throws(
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
        remove_tree(dirname($theme));
    }
});
