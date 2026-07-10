<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

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

    (new FixBlocksStep($fake))->run($project);

    assert_eq(1, count($fake->calls), 'fix() called once');
    assert_eq($project->themePath(), $fake->calls[0], 'given the theme dir');
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
