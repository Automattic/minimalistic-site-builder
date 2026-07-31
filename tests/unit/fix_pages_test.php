<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockSerializer\DroppedValue;
use Automattic\SiteBuild\BlockSerializer\FileReport;
use Automattic\SiteBuild\BlockSerializer\FixerReport;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ReportingBlockFixer;
use Automattic\SiteBuild\Steps\FixPagesStep;

test('FixPagesStep declares the assembled pages it reads and rewrites', function () {
    $decl = (new FixPagesStep(new PhpBlockFixer()))->declaration();
    assert_eq('fix-pages', $decl->id);
    assert_true(in_array('plugin/pages/*', $decl->reads, true), 'reads the assembled pages');
    assert_true(in_array('plugin/pages/*', $decl->writes, true), 'writes the repaired pages back');
    assert_true(in_array('warnings.json', $decl->writes, true), 'records durable warnings');
});

test('FixPagesStep repairs a block issue that only exists in the assembled page', function () {
    // A media-text whose mediaType the model never set: each section part was
    // fixed alone, but the assembled document still carries the defect. The
    // page-level fixer supplies "mediaType":"image" (the same repair the
    // per-part fixer applies) so the final markup validates.
    $tmp = sys_get_temp_dir() . '/fix-pages-repair-' . uniqid();
    $project = new Project($tmp);
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:media-text {"align":"wide","mediaPosition":"right","mediaWidth":58,"verticalAlignment":"center"} -->'
            . '<div class="wp-block-media-text alignwide has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-center" style="grid-template-columns:auto 58%"><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><figure class="wp-block-media-text__media"><img src="theme:./assets/hero.jpg" alt="AI_IMAGE: Hero | context | photorealistic | landscape"/></figure></div>'
            . '<!-- /wp:media-text -->',
    );

    try {
        (new FixPagesStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('plugin/pages/home.html');
        assert_contains('"mediaType":"image"', $fixed, 'the page-level fixer repaired the assembled document');
        assert_contains('theme:./assets/hero.jpg', $fixed, 'the image survives the repair');
        assert_true(!$project->exists('warnings.json'), 'a clean repair records no warning');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixPagesStep leaves a well-formed page unchanged', function () {
    $tmp = sys_get_temp_dir() . '/fix-pages-noop-' . uniqid();
    $project = new Project($tmp);
    $canonical = '<!-- wp:paragraph -->' . "\n" . '<p>Fresh loaves every morning.</p>' . "\n" . '<!-- /wp:paragraph -->';
    $project->writeText('plugin/pages/home.html', $canonical);

    try {
        (new FixPagesStep(new PhpBlockFixer()))->run($project);

        assert_eq($canonical, $project->readText('plugin/pages/home.html'), 'a canonical page is delivered byte-for-byte');
        assert_true(!$project->exists('warnings.json'), 'no warning for an unchanged page');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixPagesStep runs the fixer over the plugin directory, not the theme', function () {
    $seen = null;
    $fake = new class($seen) implements BlockFixer {
        public function __construct(public ?string &$seen) {}
        public function fix(string $themeDir): string
        {
            $this->seen = $themeDir;
            return '[fix-templates] noop';
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-pages-dir-' . uniqid();
    $project = new Project($tmp);
    $project->writeText('plugin/pages/home.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

    try {
        (new FixPagesStep($fake))->run($project);
        assert_eq($project->pluginPath(), $seen, 'the fixer discovers plugin/pages/*, not the theme parts');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixPagesStep degrades a per-page failure to a warning and keeps the other pages fixed', function () {
    // The fixer repairs one page and abandons another. The failed page keeps
    // its pre-fix markup with a durable warning; its healthy sibling keeps the
    // repair; no exception escapes.
    $fixer = new class implements ReportingBlockFixer {
        public function fix(string $themeDir): string
        {
            throw new LogicException('FixPagesStep must consume the typed fixer contract');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            file_put_contents(
                $themeDir . '/pages/a.html',
                '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">A fixed</div><!-- /wp:group -->',
            );
            // b.html is left exactly as it was on disk (abandoned transform).
            return new FixerReport([
                new FileReport('pages/a.html', 'fixed'),
                new FileReport('pages/b.html', 'failed', error: "Registered block 'core/query' unsupported"),
            ]);
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-pages-degrade-' . uniqid();
    $project = new Project($tmp);
    $bOriginal = '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->';
    $project->writeText('plugin/pages/a.html', '<!-- wp:group {"align":"full"} --><div>');
    $project->writeText('plugin/pages/b.html', $bOriginal);

    try {
        $thrown = null;
        try {
            (new FixPagesStep($fixer))->run($project);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        assert_eq(null, $thrown, 'a per-page failure is a warning, not a build failure');

        assert_contains('A fixed', $project->readText('plugin/pages/a.html'), 'the healthy page keeps its repair');
        assert_eq($bOriginal, $project->readText('plugin/pages/b.html'), 'the failed page keeps its pre-fix markup');

        $warnings = $project->readJson('warnings.json')['fix-pages'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('left pages/b.html unmodified', $joined);
        assert_contains("core/query", $joined);
        assert_contains('pre-fix page', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixPagesStep keeps the pre-fix page when the fixer would drop authored content', function () {
    $fixer = new class implements ReportingBlockFixer {
        public function fix(string $themeDir): string
        {
            throw new LogicException('FixPagesStep must consume the typed fixer contract');
        }

        public function fixReport(string $themeDir): FixerReport
        {
            // Re-serialize the page but lose its authored spacing.
            file_put_contents(
                $themeDir . '/pages/home.html',
                '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->',
            );
            return new FixerReport([
                new FileReport('pages/home.html', 'fixed', [new DroppedValue('style', 'padding-top:8rem')]),
            ]);
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-pages-drop-' . uniqid();
    $project = new Project($tmp);
    $original = '<!-- wp:group {"style":{"spacing":{"padding":{"top":"8rem"}}}} --><div class="wp-block-group" style="padding-top:8rem"></div><!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $original);

    try {
        (new FixPagesStep($fixer))->run($project);

        assert_eq($original, $project->readText('plugin/pages/home.html'), 'a page that would lose content is kept pre-fix');
        $warnings = $project->readJson('warnings.json')['fix-pages'] ?? [];
        assert_contains('would drop style `padding-top:8rem`', implode("\n", $warnings));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FixPagesStep degrades an operational fixer crash without discarding any page', function () {
    $fake = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            throw new RuntimeException('injected staging I/O failure');
        }
    };

    $tmp = sys_get_temp_dir() . '/fix-pages-crash-' . uniqid();
    $project = new Project($tmp);
    $home = '<!-- wp:paragraph --><p>Home copy</p><!-- /wp:paragraph -->';
    $about = '<!-- wp:paragraph --><p>About copy</p><!-- /wp:paragraph -->';
    $project->writeText('plugin/pages/home.html', $home);
    $project->writeText('plugin/pages/about.html', $about);

    try {
        $thrown = null;
        try {
            (new FixPagesStep($fake))->run($project);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        // These are the final shippable pages; an operational fixer crash must
        // not cost the user the whole build.
        assert_eq(null, $thrown, 'an operational crash on the final pages degrades, not fails');
        assert_eq($home, $project->readText('plugin/pages/home.html'), 'every page is kept as-is');
        assert_eq($about, $project->readText('plugin/pages/about.html'));
        $warnings = $project->readJson('warnings.json')['fix-pages'] ?? [];
        assert_contains('injected staging I/O failure', implode("\n", $warnings));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
