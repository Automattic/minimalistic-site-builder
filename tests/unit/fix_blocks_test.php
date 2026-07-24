<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRhythm;
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
