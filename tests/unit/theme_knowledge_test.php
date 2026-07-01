<?php
declare(strict_types=1);

/**
 * Unit tests for ThemeKnowledge — the read-only loader that feeds the section
 * prompts the design playbooks + few-shot examples from the themer repo.
 *
 * The pure helpers (extractMarkup, stripPlaybookFrontMatter) are tested with
 * inline fixtures so they never depend on the corpus. The end-to-end load tests
 * use the configured THEMER_ROOT; when that checkout is absent they assert the
 * graceful-empty contract instead.
 */

/** Resolve the themer checkout the same way bootstrap does; '' if absent. */
function tk_root(): string
{
    $root = Env::get('THEMER_ROOT', '/home/matias/dev/a8c/themer') ?? '';
    return is_dir($root) ? $root : '';
}

// --- Graceful degradation: no root -> every method returns "" ---------------

test('knowledge with a missing root returns empty for everything', function () {
    $tk = new ThemeKnowledge('/no/such/themer/repo');
    assert_eq('', $tk->playbook('header'));
    assert_eq('', $tk->playbook('hero'));
    assert_eq('', $tk->examples('header', 2));
    assert_eq('', $tk->examples('footer'));
});

test('knowledge with a null root returns empty for everything', function () {
    $tk = new ThemeKnowledge(null);
    assert_eq('', $tk->playbook('header'));
    assert_eq('', $tk->examples('hero', 2));
});

// --- extractMarkup (.php pattern files) — pure, corpus-independent -----------

test('extractMarkup strips a PHP header comment and starts at the first wp block', function () {
    $php = "<?php\n/**\n * Title: Default Header\n */\ndeclare( strict_types = 1 );\n?>\n\n"
        . "<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->\n";
    $out = ThemeKnowledge::extractMarkup($php, 'themes/x/patterns/header.php');
    assert_true(str_starts_with($out, '<!-- wp:group'), 'starts at the first wp block');
    assert_true(!str_contains($out, '<?php'), 'no PHP survives');
    assert_true(!str_contains($out, 'Title:'), 'header comment dropped');
});

test('extractMarkup drops inline PHP tags from a dynamic pattern', function () {
    $php = "<?php /* header */ ?>\n<!-- wp:heading -->"
        . "<h2><?php esc_html_e('Hi', 'x'); ?></h2><!-- /wp:heading -->";
    $out = ThemeKnowledge::extractMarkup($php, 'p.php');
    assert_true(!str_contains($out, '<?php'), 'inline PHP removed');
    assert_contains('<!-- wp:heading -->', $out);
});

test('extractMarkup returns empty when no block markup is present', function () {
    assert_eq('', ThemeKnowledge::extractMarkup("<?php return; ?>\n", 'p.php'));
});

test('extractMarkup refuses output that still holds an unterminated PHP tag', function () {
    // No closing tag -> the segment strip cannot match -> must not leak PHP.
    $php = "<!-- wp:paragraph --><p><?php echo 'x';</p><!-- /wp:paragraph -->";
    assert_eq('', ThemeKnowledge::extractMarkup($php, 'p.php'));
});

test('extractMarkup passes through an .html template part unchanged', function () {
    $html = "<!-- wp:site-title /-->\n";
    assert_eq('<!-- wp:site-title /-->', ThemeKnowledge::extractMarkup($html, 'parts/header.html'));
});

// --- stripPlaybookFrontMatter — pure ----------------------------------------

test('stripPlaybookFrontMatter drops the H1 and provenance blockquote', function () {
    $md = "# Header — builder playbook\n> Distilled from 108 themes.\n\n## Recipes\nbody";
    $out = ThemeKnowledge::stripPlaybookFrontMatter($md);
    assert_true(str_starts_with($out, '## Recipes'), 'front matter stripped');
    assert_true(!str_contains($out, 'playbook'), 'H1 removed');
});

test('stripPlaybookFrontMatter keeps body when there is no H1', function () {
    $md = "## Recipes\nbody";
    assert_eq("## Recipes\nbody", ThemeKnowledge::stripPlaybookFrontMatter($md));
});

// --- End-to-end against the real corpus (skipped if absent) -----------------

test('playbook(header) loads non-empty from the themer repo', function () {
    $root = tk_root();
    if ($root === '') {
        return; // themer checkout not present in this environment
    }
    $tk = new ThemeKnowledge($root);
    $pb = $tk->playbook('header');
    assert_true($pb !== '', 'header playbook non-empty');
    // Front matter stripped: no leading H1.
    assert_true(!str_starts_with($pb, '# '), 'H1 stripped from playbook');
});

test('examples(header, 2) yields two EXAMPLE blocks and no raw PHP', function () {
    $root = tk_root();
    if ($root === '') {
        return;
    }
    $tk = new ThemeKnowledge($root);
    $ex = $tk->examples('header', 2);
    assert_true($ex !== '', 'examples non-empty');
    assert_eq(2, substr_count($ex, '<!-- EXAMPLE:'), 'exactly two example blocks');
    assert_true(!str_contains($ex, '<?php'), 'no raw PHP leaks into the prompt');
    assert_contains('wp:', $ex);
});

test('examples for an unknown section type returns empty', function () {
    $root = tk_root();
    if ($root === '') {
        return;
    }
    $tk = new ThemeKnowledge($root);
    assert_eq('', $tk->examples('no-such-type', 2));
});

test('tronar header pattern extracts to clean block markup', function () {
    $root = tk_root();
    $path = $root . '/themes/tronar/patterns/header.php';
    if ($root === '' || !is_file($path)) {
        return;
    }
    $out = ThemeKnowledge::extractMarkup((string) file_get_contents($path), $path);
    assert_true(str_starts_with($out, '<!-- wp:group'), 'starts with wp:group');
    assert_true(!str_contains($out, '<?php'), 'no PHP');
});
