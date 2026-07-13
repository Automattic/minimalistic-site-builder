<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\ThemeValidator;

/**
 * Unit tests for NodeBlockFixer::summaryLine and FixBlocksStep → BlockFixer.
 */

test('summaryLine returns the [fix-templates] summary, ignoring the verbose report', function () {
    $stdout = "  FIXED  parts/header.html\n"
        . "         - core/button: Expected attribute `class` ...\n"
        . "  ok     parts/footer.html\n"
        . "\n[fix-templates] 7/11 file(s) re-serialized, 14 issue(s) fixed across 1 theme(s).";

    assert_eq(
        '[fix-templates] 7/11 file(s) re-serialized, 14 issue(s) fixed across 1 theme(s).',
        NodeBlockFixer::summaryLine($stdout)
    );
});

test('summaryLine falls back when no summary line is present', function () {
    assert_eq('block-fixer: no files changed', NodeBlockFixer::summaryLine("   \n  noise\n"));
});

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

test('FixBlocksStep logs and fails when block repair drops vertical rhythm CSS', function () {
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

        assert_true($thrown instanceof RuntimeException, 'vertical rhythm loss must fail the step');
        assert_contains('padding-top:8rem', $thrown->getMessage());
        $log = $project->readText('logs/fix-blocks.log');
        assert_contains('DROPPED style `padding-top:8rem`', $log);
        assert_contains('[rhythm] build rejected', $log, 'failure reason is logged before the exception');
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
        assert_true(!str_contains($log, '[rhythm] build rejected'), 'unrelated loss stays a warning');
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
            return '[fix-templates] second pass re-serialized normalized group';
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

    $cmd = 'node ' . escapeshellarg(repo_path('bin/block-fixer/fix-templates.js')) . ' ' . escapeshellarg($theme) . ' 2>&1';
    exec($cmd, $out, $exit);
    $stdout = implode("\n", $out);

    $fixed = (string) file_get_contents($theme . '/parts/cards.html');
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_eq(0, $exit, $stdout);
    assert_contains('card-media', $fixed);
    assert_contains('0 style/class value(s) dropped', $stdout);
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

    $cmd = 'node ' . escapeshellarg(repo_path('bin/block-fixer/fix-templates.js')) . ' ' . escapeshellarg($theme) . ' 2>&1';
    exec($cmd, $out, $exit);
    $stdout = implode("\n", $out);

    exec('rm -rf ' . escapeshellarg($tmp));

    assert_eq(0, $exit, $stdout);
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

    $cmd = 'node ' . escapeshellarg(repo_path('bin/block-fixer/fix-templates.js')) . ' ' . escapeshellarg($theme) . ' 2>&1';
    exec($cmd, $out, $exit);
    $stdout = implode("\n", $out);
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_eq(0, $exit, $stdout);
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

    $cmd = 'node ' . escapeshellarg(repo_path('bin/block-fixer/fix-templates.js')) . ' ' . escapeshellarg($theme) . ' 2>&1';
    exec($cmd, $out, $exit);
    $stdout = implode("\n", $out);
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_eq(0, $exit, $stdout);
    assert_contains('DROPPED style `padding-top:8rem`', $stdout);
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

    $cmd = 'node ' . escapeshellarg(repo_path('bin/block-fixer/fix-templates.js')) . ' ' . escapeshellarg($theme) . ' 2>&1';
    exec($cmd, $out, $exit);

    $fixed = (string) file_get_contents($theme . '/parts/hero.html');
    exec('rm -rf ' . escapeshellarg($tmp));

    assert_eq(0, $exit, implode("\n", $out));
    assert_contains('"mediaType":"image"', $fixed);
    assert_contains('<img src="theme:./assets/hero.jpg"', $fixed);
});
