<?php
declare(strict_types=1);

use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\Steps\ValidateThemeStep;

/** @return array{0:Automattic\SiteBuild\Project,1:string} */
function final_validation_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_final_validation_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson(
        'theme/theme.json',
        ThemeJsonStep::normalizeSpacingSettings([
            'version' => 3,
            'settings' => ['color' => ['palette' => [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#777777'],
            ]]],
        ])
    );
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'static',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeText('theme/functions.php', "<?php\n");
    $template = '<!-- wp:template-part {"slug":"header"} /-->'
        . '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->'
        . '<!-- wp:template-part {"slug":"footer"} /-->';
    $project->writeText('theme/templates/index.html', $template);
    $project->writeText('theme/templates/page.html', $template);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group header-archetype--standard-row has-contrast-color has-base-background-color has-text-color has-background">'
            . '<!-- wp:site-title /--></div><!-- /wp:group -->',
    );
    $project->writeText('theme/parts/footer.html', '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->');
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'title' => 'Home',
            'layout_archetype' => 'mixed-width-editorial',
            'background' => 'contrast',
            'vertical_density' => 'standard',
            'primary_action' => null,
        ]],
    ]];
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage"></div><!-- /wp:group -->';
    $hero = SectionRhythm::rewrite([[
        'slug' => 'hero',
        'markup' => $hero,
        'density' => 'standard',
        'background' => 'contrast',
    ]])['markups'][0];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('focal-subject-stage'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'validate-step', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'standard-row',
    );
    $final = AboveFoldContract::finalizeMarkup($delivery, $pages, [
        'part_keys' => ['header', 'page-home--hero'],
        'opening_overlay_support' => ['page-home--hero' => false],
        'primary_action_delivered' => true,
    ]);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $final);
    $project->writeText('plugin/pages/home.html', $hero . "\n");
    return [$project, $tmp];
}

test('validate-theme declaration rejects an incomplete theme graph', function () {
    assert_eq([
        'pages.json',
        'aboveFold.json',
        'headerBehavior.json',
        'theme/style.css',
        'theme/theme.json',
        'theme/functions.php',
        'theme/assets/header/*',
        'theme/templates/index.html',
        'theme/templates/page.html',
        'theme/parts/header.html',
        'theme/parts/footer.html',
        'theme/parts/*',
        'theme/templates/*',
        'plugin/pages/*',
    ], (new ValidateThemeStep())->declaration()->reads);

    assert_throws(
        fn () => new Pipeline(
            [new ScaffoldThemeStep(), new ValidateThemeStep()],
            seeds: ['pages.json'],
        ),
        'a scaffold alone does not provide theme.json, templates, or parts',
    );
});

test('validate-theme passes and logs a completed contract-valid theme', function () {
    [$project, $tmp] = final_validation_project();
    try {
        (new ValidateThemeStep())->run($project);
        assert_contains('passed', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme records problems as warnings and still delivers the theme', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/bad.html',
        '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing--xl"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );

    try {
        (new ValidateThemeStep())->run($project);

        $log = $project->readText('logs/validate-theme.log');
        assert_contains('theme delivered anyway', $log);
        assert_contains('malformed preset reference', $log);
        assert_contains('var:preset|spacing--xl', $log);

        $warnings = $project->readJson('warnings.json');
        assert_true(isset($warnings['validate-theme']), 'warnings.json groups problems by step id');
        assert_contains('var:preset|spacing--xl', implode("\n", $warnings['validate-theme']));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('addWarnings accumulates across steps and dedupes within a step', function () {
    [$project, $tmp] = final_validation_project();
    try {
        $project->addWarnings('validate-theme', ['a button link has no href']);
        $project->addWarnings('validate-theme', ['a button link has no href', 'a link has an empty href']);
        $project->addWarnings('fix-blocks', ['dropped vertical rhythm CSS']);
        $project->addWarnings('fix-blocks', []);

        assert_eq([
            'validate-theme' => ['a button link has no href', 'a link has an empty href'],
            'fix-blocks' => ['dropped vertical rhythm CSS'],
        ], $project->readJson('warnings.json'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme also runs the typography and plan validators', function () {
    [$project, $tmp] = final_validation_project();
    // Hardcoded font size → typographyWarnings; an interior page opening at
    // homepage-hero scale → planWarnings. Both were orphaned in bin/eval.php
    // before — the final gate must run every advisory validator.
    $project->writeText(
        'theme/parts/hardcoded.html',
        '<!-- wp:paragraph {"fontSize":"1.25rem"} --><p>Sized</p><!-- /wp:paragraph -->'
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'hero', 'layout_archetype' => 'full-bleed-cover'],
        ]],
        ['slug' => 'about', 'front' => false, 'sections' => [
            ['slug' => 'about-hero', 'layout_archetype' => 'full-bleed-cover'],
        ]],
    ]]);

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('hardcoded font-size values bypass the fontSizes scale', $joined);
        assert_contains("interior page 'about' opens with a full-bleed-cover section", $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme records footer interaction residuals and still delivers', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:paragraph --><p><a href="#">Social</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:list --><ul class="wp-block-list"></ul><!-- /wp:list -->'
    );

    try {
        (new ValidateThemeStep())->run($project);

        assert_true($project->exists('theme/parts/footer.html'), 'advisory validation never removes the footer');
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('authored href="#" -> delivered href="#"', $joined);
        assert_contains('wp:list[1]', $joined);
        assert_contains('disposition:', $joined);
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme accepts a fully wired sticky header behavior contract', function () {
    [$project, $tmp] = final_validation_project();
    $classes = 'header-archetype--standard-row header-behavior-sticky-soft header-start-base header-scrolled-base header-foreground-contrast';
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","className":"' . $classes
            . '","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background '
            . $classes . '"><!-- wp:site-title /--></div>'
            . '<!-- /wp:group -->'
    );
    $header = '<!-- wp:template-part {"slug":"header","tagName":"header",'
        . '"className":"site-header-shell site-header-shell--sticky-soft"} /-->';
    $footer = '<!-- wp:template-part {"slug":"footer"} /-->';
    $project->writeText('theme/templates/page.html', $header . '<!-- wp:post-content /-->' . $footer);
    $project->writeText('theme/templates/index.html', $header . '<!-- wp:post-content /-->' . $footer);
    $project->writeText('theme/assets/header/header.css', ".site-header-shell { position: sticky; }\n");
    $project->writeText('theme/assets/header/header.js', "document.documentElement.classList.add('header-state-js');\n");
    $project->writeText(
        'theme/functions.php',
        "<?php\nwp_enqueue_style('demo-header', get_theme_file_uri('assets/header/header.css'));\n"
            . "wp_enqueue_script('demo-header', get_theme_file_uri('assets/header/header.js'));\n"
    );

    try {
        (new ValidateThemeStep())->run($project);
        assert_contains('passed', $project->readText('logs/validate-theme.log'));
        assert_true(!$project->exists('warnings.json'), 'valid sticky contract adds no warnings');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme warns and continues when headerBehavior JSON is malformed', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText('headerBehavior.json', '{"behavior":');

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('header behavior contract: file=headerBehavior.json', $joined);
        assert_contains('authored=<invalid JSON:', $joined);
        assert_contains('delivered=unchanged', $joined);
        assert_contains('disposition=malformed generated header behavior', $joined);
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme warns and continues when headerBehavior JSON is a valid non-object scalar', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText('headerBehavior.json', '"static"');

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('header behavior contract: file=headerBehavior.json', $joined);
        assert_contains('authored="static"', $joined);
        assert_contains('disposition=header behavior artifact must be a JSON object', $joined);
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme completes and warns when theme/theme.json is absent', function () {
    [$project, $tmp] = final_validation_project();
    unlink($project->path('theme/theme.json'));

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('missing theme/theme.json', $joined);
        assert_contains('palette membership and contrast checks skipped', $joined);
        assert_true(
            !str_contains($joined, 'must name a theme/theme.json palette slug'),
            'palette membership cannot be judged against an absent palette',
        );
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme matches inline position declarations without background-position false positives', function () {
    [$project, $tmp] = final_validation_project();
    $write = static function (string $style) use ($project): void {
        $attr = $style === '' ? '' : ' style="' . $style . '"';
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background"'
                . $attr . '><!-- wp:site-title /--></div><!-- /wp:group -->',
        );
    };
    $run = static function () use ($project): string {
        $project->writeText('warnings.json', "{}\n");
        (new ValidateThemeStep())->run($project);
        return implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
    };

    try {
        $write('background-position: center top');
        assert_true(
            !str_contains($run(), 'saved wrapper contains inline position CSS'),
            'background-position is not a position declaration',
        );

        $write("font-family: 'Foo'; position: absolute");
        assert_contains(
            'saved wrapper contains inline position CSS',
            $run(),
            'an apostrophe inside the quoted value must not hide the declaration',
        );

        $write('position: sticky');
        assert_contains('saved wrapper contains inline position CSS', $run());

        $write('color: red');
        assert_true(
            !str_contains($run(), 'saved wrapper contains inline position CSS'),
            'no position declaration, no warning',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme flags residual style elements in delivered theme markup', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background">'
            . '<style>.site-header-shell{position:fixed}</style>'
            . '<!-- wp:site-title /--></div><!-- /wp:group -->',
    );

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('theme/parts/header.html: contains 1 <style> element(s)', $joined);
        assert_contains('disposition: remove the <style> element(s)', $joined);
        assert_contains('theme delivered anyway', $project->readText('logs/validate-theme.log'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme reports residual header contrast, class, position, template, and asset defects', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'primary',
        'scrolledSurface' => 'primary',
        'foreground' => 'primary',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"header-behavior-static header-overlay","style":{"position":{"type":"sticky","top":"0px"}}} -->'
            . '<div class="wp-block-group header-behavior-static header-overlay" style="position:sticky"><!-- wp:site-title /--></div>'
            . '<!-- /wp:group -->'
    );

    try {
        (new ValidateThemeStep())->run($project);

        $warnings = $project->readJson('warnings.json')['validate-theme'] ?? [];
        $joined = implode("\n", $warnings);
        assert_contains('top header state is below the 4.5:1', $joined);
        assert_contains('scrolled header state is below the 4.5:1', $joined);
        assert_contains("required inner header class 'header-behavior-sticky-soft'", $joined);
        assert_contains("state class='header-behavior-static'", $joined);
        assert_contains("legacy class='header-overlay'", $joined);
        assert_contains('style.position=', $joined);
        assert_contains('saved wrapper contains inline position CSS', $joined);
        assert_contains("required outer header class 'site-header-shell'", $joined);
        assert_contains('file=theme/assets/header/header.css', $joined);
        assert_contains('file=theme/assets/header/header.js', $joined);
        assert_contains('enqueue for assets/header/header.css=<missing>', $joined);
        assert_contains('enqueue for assets/header/header.js=<missing>', $joined);
        foreach ($warnings as $warning) {
            if (!str_starts_with($warning, 'header behavior contract:')) {
                continue;
            }
            assert_contains('file=', $warning);
            assert_contains('authored=', $warning);
            assert_contains('delivered=', $warning);
            assert_contains('disposition=', $warning);
        }
        assert_true($project->exists('theme/parts/header.html'), 'advisory validation does not mutate the header');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme mirrors consumer degradation for a rejected artifact without a wiring cascade', function () {
    [$project, $tmp] = final_validation_project();
    // One extra key makes HeaderBehavior::validateArtifact throw — the exact
    // gate AssemblePagesStep and FinalizeThemeStep run before they assemble
    // static templates and prune the adaptive kit. final_validation_project()
    // is that statically-assembled theme, so the validator must report the
    // artifact defect once and not demand the sticky wiring the consumers
    // removed on purpose.
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'contrast',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
        'modelNote' => 'invented extension',
    ]);

    try {
        (new ValidateThemeStep())->run($project);

        $warnings = $project->readJson('warnings.json')['validate-theme'] ?? [];
        $artifactProblems = array_values(array_filter(
            $warnings,
            static fn (string $w): bool => str_starts_with($w, 'header behavior contract:'),
        ));
        assert_eq(1, count($artifactProblems), implode("\n", $warnings));
        assert_contains('closed header behavior contract is invalid', $artifactProblems[0]);
        assert_contains('must contain exactly', $artifactProblems[0]);
        assert_contains('delivered=static', $artifactProblems[0]);

        $joined = implode("\n", $warnings);
        assert_true(
            !str_contains($joined, 'required outer header class'),
            'no cascade against the intentionally static templates',
        );
        assert_true(
            !str_contains($joined, 'requires this asset enqueue'),
            'no cascade against the intentionally pruned kit',
        );
        assert_true(
            !str_contains($joined, 'required inner header class'),
            'no cascade against the static inner header',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme reports a treatment relationship violation as one artifact problem', function () {
    [$project, $tmp] = final_validation_project();
    // A static behavior can never carry a non-solid treatment — the exact
    // relationship rule HeaderBehavior::validateArtifact enforces. Like any
    // other rejected artifact, the consumers degraded to static, so the
    // validator must report the defect once and judge the theme against the
    // static/pruned expectation without a wiring cascade.
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'static',
        'mode' => 'stacked',
        'transition' => 'instant',
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => 'glass',
        'scrolledTreatment' => 'solid',
    ]);

    try {
        (new ValidateThemeStep())->run($project);

        $warnings = $project->readJson('warnings.json')['validate-theme'] ?? [];
        $artifactProblems = array_values(array_filter(
            $warnings,
            static fn (string $w): bool => str_starts_with($w, 'header behavior contract:'),
        ));
        assert_eq(1, count($artifactProblems), implode("\n", $warnings));
        assert_contains('closed header behavior contract is invalid', $artifactProblems[0]);
        assert_contains('static behavior requires solid top and scrolled treatments', $artifactProblems[0]);
        assert_contains('delivered=static', $artifactProblems[0]);

        $joined = implode("\n", $warnings);
        assert_true(
            !str_contains($joined, 'required outer header class'),
            'no cascade against the intentionally static templates',
        );
        assert_true(
            !str_contains($joined, 'requires this asset enqueue'),
            'no cascade against the intentionally pruned kit',
        );
        assert_true(
            !str_contains($joined, 'required inner header class'),
            'no cascade against the static inner header',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme flags sticky residue the consumers would have pruned for a rejected artifact', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'contrast',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
        'modelNote' => 'invented extension',
    ]);
    // Leftover adaptive wiring that the consumers' static degrade should have
    // removed is real residue against the pruned expectation.
    $project->writeText('theme/assets/header/header.css', "/* stale */\n");
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"backgroundColor":"base","textColor":"contrast",'
            . '"className":"header-behavior-sticky-soft","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group has-contrast-color has-base-background-color has-text-color has-background '
            . 'header-behavior-sticky-soft"><!-- wp:site-title /--></div><!-- /wp:group -->',
    );

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('static behavior must not retain header state classes', $joined);
        assert_contains('static behavior must not ship unused header state assets', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme rejects state assets and classes left behind for static behavior', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"header-behavior-sticky-soft","style":{"position":{"type":"sticky"}}} -->'
            . '<div class="wp-block-group header-behavior-sticky-soft" style="position:sticky"><!-- wp:site-title /--></div>'
            . '<!-- /wp:group -->'
    );
    $project->writeText('theme/templates/page.html', '<!-- wp:post-content /-->');
    $project->writeText('theme/assets/header/header.css', "/* stale */\n");
    $project->writeText('theme/assets/header/header.js', "// stale\n");
    $project->writeText(
        'theme/functions.php',
        "<?php\nwp_enqueue_style('demo-header', get_theme_file_uri('assets/header/header.css'));\n"
            . "wp_enqueue_script('demo-header', get_theme_file_uri('assets/header/header.js'));\n"
    );

    try {
        (new ValidateThemeStep())->run($project);

        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('static behavior must not retain header state classes', $joined);
        assert_contains('style.position=', $joined);
        assert_contains('saved wrapper contains inline position CSS', $joined);
        assert_contains('<no core/template-part with slug="header">', $joined);
        assert_contains('static behavior must not ship unused header state assets', $joined);
        assert_contains('static behavior must not enqueue header state assets', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme rejects palette slugs that the trusted header CSS does not implement', function () {
    [$project, $tmp] = final_validation_project();
    $theme = $project->readJson('theme/theme.json');
    $theme['settings']['color']['palette'][] = [
        'slug' => 'custom-panel',
        'name' => 'Custom panel',
        'color' => '#eeeeee',
    ];
    $project->writeJson('theme/theme.json', $theme);
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'custom-panel',
        'foreground' => 'contrast',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);

    try {
        (new ValidateThemeStep())->run($project);
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('closed header behavior contract is invalid', $joined);
        assert_contains('scrolled header surface must be an opaque palette slug', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme checks delivered root colors and nested persistent positioning', function () {
    [$project, $tmp] = final_validation_project();
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"backgroundColor":"contrast","textColor":"contrast"} -->'
            . '<div class="wp-block-group has-contrast-color has-contrast-background-color has-text-color has-background">'
            . '<!-- wp:group {"style":{"position":{"type":"sticky","top":"0px"}}} -->'
            . '<div class="wp-block-group" style="position:sticky"><!-- wp:site-title /--></div>'
            . '<!-- /wp:group -->'
            . '<!-- wp:group {"style":{"position":{"type":"relative"}}} -->'
            . '<div class="wp-block-group" style="position:relative"></div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->',
    );

    try {
        (new ValidateThemeStep())->run($project);
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('root backgroundColor must equal topSurface="base"', $joined);
        assert_contains("wp:group[1] style.position=", $joined);
        assert_contains('descendant sticky/fixed positioning is unsupported', $joined);
        assert_contains('saved wrapper contains "position:sticky"', $joined);
        assert_true(!str_contains($joined, 'position:relative'), 'local descendant relative positioning remains valid');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme checks overlay foreground against the trusted scrim worst case', function () {
    [$project, $tmp] = final_validation_project();
    $classes = 'header-behavior-overlay-to-solid header-start-transparent '
        . 'header-scrolled-contrast header-foreground-primary';
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'overlay-to-solid',
        'mode' => 'overlay',
        'transition' => 'smooth',
        'topSurface' => 'transparent',
        'scrolledSurface' => 'contrast',
        'foreground' => 'primary',
        'topTreatment' => 'transparent',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"textColor":"primary","className":"' . $classes . '"} -->'
            . '<div class="wp-block-group has-primary-color has-text-color ' . $classes . '">'
            . '<!-- wp:site-title /--></div><!-- /wp:group -->',
    );

    try {
        (new ValidateThemeStep())->run($project);
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('trusted 60% overlay scrim worst case #666666', $joined);
        assert_contains('top header state is below the 4.5:1', $joined);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('validate-theme rejects a smooth surface change with an unreadable midpoint', function () {
    [$project, $tmp] = final_validation_project();
    $theme = $project->readJson('theme/theme.json');
    $theme['settings']['color']['palette'][1]['color'] = '#000000';
    $theme['settings']['color']['palette'][2]['color'] = '#767676';
    $project->writeJson('theme/theme.json', $theme);

    $classes = 'header-behavior-sticky-soft header-start-base '
        . 'header-scrolled-contrast header-foreground-primary';
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'sticky-soft',
        'mode' => 'stacked',
        'transition' => 'smooth',
        'topSurface' => 'base',
        'scrolledSurface' => 'contrast',
        'foreground' => 'primary',
        'topTreatment' => 'solid',
        'scrolledTreatment' => 'solid',
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"backgroundColor":"base","textColor":"primary","className":"' . $classes . '"} -->'
            . '<div class="wp-block-group has-primary-color has-base-background-color has-text-color has-background '
            . $classes . '"><!-- wp:site-title /--></div><!-- /wp:group -->',
    );

    try {
        (new ValidateThemeStep())->run($project);
        $joined = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_contains('smooth background-color interpolation crosses an unreadable contrast midpoint', $joined);
        assert_contains('topSurface="base", scrolledSurface="contrast"', $joined);
        assert_true(!str_contains($joined, 'top header state is below'), 'both endpoints independently pass');
        assert_true(!str_contains($joined, 'scrolled header state is below'), 'both endpoints independently pass');

        $artifact = $project->readJson('headerBehavior.json');
        $artifact['transition'] = 'instant';
        $project->writeJson('headerBehavior.json', $artifact);
        $project->writeText('warnings.json', "{}\n");
        (new ValidateThemeStep())->run($project);
        $instant = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_true(
            !str_contains($instant, 'unreadable contrast midpoint'),
            'instant changes have no interpolated midpoint',
        );

        $theme = $project->readJson('theme/theme.json');
        $theme['settings']['color']['palette'][0]['color'] = '#FFFF00';
        $theme['settings']['color']['palette'][1]['color'] = '#000000';
        $theme['settings']['color']['palette'][2]['color'] = '#00FFFF';
        $project->writeJson('theme/theme.json', $theme);
        $safeClasses = 'header-behavior-sticky-soft header-start-base '
            . 'header-scrolled-primary header-foreground-contrast';
        $project->writeJson('headerBehavior.json', [
            'behavior' => 'sticky-soft',
            'mode' => 'stacked',
            'transition' => 'smooth',
            'topSurface' => 'base',
            'scrolledSurface' => 'primary',
            'foreground' => 'contrast',
            'topTreatment' => 'solid',
            'scrolledTreatment' => 'solid',
        ]);
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","className":"'
                . $safeClasses . '"} -->'
                . '<div class="wp-block-group has-contrast-color has-base-background-color has-text-color '
                . 'has-background ' . $safeClasses . '"><!-- wp:site-title /--></div><!-- /wp:group -->',
        );
        $project->writeText('warnings.json', "{}\n");
        (new ValidateThemeStep())->run($project);
        $safe = implode("\n", $project->readJson('warnings.json')['validate-theme'] ?? []);
        assert_true(
            !str_contains($safe, 'unreadable contrast midpoint'),
            'safe mixed-channel smooth transitions do not create false warnings',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
