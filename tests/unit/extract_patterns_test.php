<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BuildReport;
use Automattic\SiteBuild\LinkTargets;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\Steps\ExtractPatternsStep;

/**
 * Seeds a one-page project the step can run against: the plan, the assembled
 * page, and a stylesheet. $sections is a list of ['slug' => …, 'markup' => …].
 */
function extract_patterns_seed(\Automattic\SiteBuild\Project $project, array $sections = null): void
{
    $sections ??= [
        ['slug' => 'hero',    'markup' => sp_columns(1)],
        ['slug' => 'service', 'markup' => sp_columns(3)],
        ['slug' => 'cta',     'markup' => sp_columns(1)],
    ];
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'menu_order' => 0, 'parent' => null,
        'sections' => array_map(
            static fn (array $s, int $i): array => [
                'slug' => $s['slug'],
                'type' => 'content',
                'role' => \Automattic\SiteBuild\SectionRole::forPosition($i, count($sections)),
            ],
            $sections,
            array_keys($sections),
        ),
    ]]]);
    $project->writeJson('plugin/pages.json', ['pages' => [
        ['slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0, 'parent' => null],
    ]]);
    $project->writeText('plugin/pages/home.html', implode("\n", array_column($sections, 'markup')));
    $project->writeText('theme/style.css', 'body{color:#fff}');
    $project->writeJson('theme/theme.json', ['version' => 3]);
}

/** Everything this step writes, for byte-identical comparison across runs. */
function extract_patterns_snapshot(\Automattic\SiteBuild\Project $project): array
{
    $out = ['patterns.json' => $project->readText('patterns.json')];
    foreach (glob($project->themePath('patterns/*.php')) ?: [] as $f) {
        $out[basename($f)] = (string) file_get_contents($f);
    }
    ksort($out);
    return $out;
}

/** What GenerateImagesStep does to page markup before postImages re-runs us. */
function extract_patterns_apply_cover_contrast_rewrite(\Automattic\SiteBuild\Project $project): void
{
    $project->writeText('plugin/pages/home.html', str_replace(
        'theme:./assets/',
        '/wp-content/themes/' . $project->slug() . '/assets/',
        $project->readText('plugin/pages/home.html'),
    ));
}

/** A section group carrying (or not carrying) a background attribute. */
function sp_bg(string $bg): string
{
    $attrs = $bg === '' ? '{}' : '{"backgroundColor":"' . $bg . '"}';
    return "<!-- wp:group {$attrs} --><div class=\"wp-block-group\">"
         . '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
         . '<!-- wp:paragraph --><p>Body copy that is long enough to score.</p><!-- /wp:paragraph -->'
         . '</div><!-- /wp:group -->';
}

/** A grid whose generated heading is deliberately unsafe for PHP docblocks. */
function extract_patterns_grid_with_heading(string $heading): string
{
    return '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>' . $heading . '</h2><!-- /wp:heading -->'
        . sp_columns(3)
        . '</div><!-- /wp:group -->';
}

/** @return list<?string> top-level block background values, in source order */
function extract_patterns_backgrounds(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $values = [];
    foreach ($doc->indices() as $index) {
        if ($doc->parent($index) !== null) {
            continue;
        }
        $attrs = $doc->attrs($index) ?? [];
        $background = $attrs['backgroundColor'] ?? ($attrs['style']['color']['background'] ?? null);
        $values[] = is_string($background) && $background !== '' ? $background : null;
    }
    return $values;
}

/** One saved core/buttons block with stable copy for survival/removal assertions. */
function extract_patterns_buttons(string $copy): string
{
    return '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link wp-element-button" href="/contact/">' . $copy . '</a>'
        . '</div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->';
}

/** @param list<string> $columns */
function extract_patterns_columns(array $columns): string
{
    $markup = '';
    foreach ($columns as $column) {
        $markup .= '<!-- wp:column --><div class="wp-block-column">'
            . $column
            . '</div><!-- /wp:column -->';
    }
    return '<!-- wp:columns --><div class="wp-block-columns">'
        . $markup
        . '</div><!-- /wp:columns -->';
}

/** One section wrapper with caller-authored content blocks. */
function extract_patterns_group(string $content): string
{
    return '<!-- wp:group --><div class="wp-block-group">'
        . $content
        . '</div><!-- /wp:group -->';
}

/** Read the emitted section pattern selected for one normalized label. */
function extract_patterns_for_label(Project $project, string $label): string
{
    foreach ($project->readJson('patterns.json')['patterns'] as $pattern) {
        if (($pattern['label'] ?? null) === $label) {
            return $project->readText('theme/patterns/' . $pattern['slug'] . '.php');
        }
    }
    throw new RuntimeException("No emitted pattern for label '{$label}'");
}

/** @return array{band:int,in_card:int} core/buttons counts by column ancestry */
function extract_patterns_button_levels(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $counts = ['band' => 0, 'in_card' => 0];
    foreach ($doc->indices() as $index) {
        if (!in_array($doc->name($index), ['buttons', 'core/buttons'], true)) {
            continue;
        }
        $insideColumn = false;
        for ($parent = $doc->parent($index); $parent !== null; $parent = $doc->parent($parent)) {
            if (in_array($doc->name($parent), ['column', 'core/column'], true)) {
                $insideColumn = true;
                break;
            }
        }
        $counts[$insideColumn ? 'in_card' : 'band']++;
    }
    return $counts;
}

test('extract-patterns freezes its step declaration contract', function (): void {
    $step = new ExtractPatternsStep();
    $declaration = $step->declaration();

    assert_eq('extract-patterns', $step->id());
    assert_eq('extract-patterns', $declaration->id);
    assert_eq([
        'plugin/pages/*',
        'plugin/pages.json',
        'pages.json',
        'theme/style.css',
        'theme/theme.json',
    ], $declaration->reads);
    assert_eq(['theme/patterns/*', 'patterns.json', 'warnings.json'], $declaration->writes);
    assert_eq(false, $declaration->concurrent);
});

test('a section containing a PHP open tag is rejected', function (): void {
    $escaped = '<!-- wp:code --><pre><code>&lt;?php phpinfo(); ?&gt;</code></pre><!-- /wp:code -->';
    assert_true(!ExtractPatternsStep::isEligible('<?php echo 1; ?>', []));
    assert_true(!ExtractPatternsStep::isEligible('<?= 1 ?>', []));
    assert_true(ExtractPatternsStep::isEligible($escaped, []), 'escaped entity text is safe');
});

test('eligibility accepts core and registered plugin blocks but rejects unregistered blocks', function (): void {
    $core = '<!-- wp:group --><div></div><!-- /wp:group -->';
    $companion = '<!-- wp:blocks-engine/description-list --><dl></dl><!-- /wp:blocks-engine/description-list -->';
    $unknown = '<!-- wp:vendor/dead-widget --><div></div><!-- /wp:vendor/dead-widget -->';

    assert_true(ExtractPatternsStep::isEligible($core, []));
    assert_true(ExtractPatternsStep::isEligible($companion, ['blocks-engine/description-list' => true]));
    assert_true(!ExtractPatternsStep::isEligible($companion, []));
    assert_true(!ExtractPatternsStep::isEligible($unknown, ['blocks-engine/description-list' => true]));
});

test('eligibility rejects core html embed and shortcode under both names', function (): void {
    foreach (['html', 'embed', 'shortcode', 'core/html', 'core/embed', 'core/shortcode'] as $name) {
        $markup = "<!-- wp:{$name} --><div>payload</div><!-- /wp:{$name} -->";
        assert_true(!ExtractPatternsStep::isEligible($markup, []), $name);
    }
});

test('a key whose only candidate is ineligible is dropped without reaching an empty winner list', function (): void {
    with_project('builder_extract_inelig_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero',  'markup' => sp_columns(3)],
            ['slug' => 'specs', 'markup' => '<!-- wp:group --><div><?php echo 1; ?></div><!-- /wp:group -->'],
        ]);

        (new ExtractPatternsStep())->run($project);

        $manifest = $project->readJson('patterns.json');
        assert_true($manifest['dropped'] !== [], 'the drop is recorded, never silent');
        assert_true(!in_array('specs-stack', array_column($manifest['patterns'], 'slug'), true));
        $warnings = $project->readText('warnings.json');
        assert_contains('plugin/pages/home.html', $warnings);
        assert_contains('specs', $warnings);
        assert_contains('disposition', $warnings);
    });
});

test('image rewrite handles both asset URL forms in block JSON and inner HTML', function (): void {
    $cases = [
        [
            '<!-- wp:cover {"url":"theme:./assets/a-1234abcd.jpg"} -->'
                . '<div><img src="theme:./assets/a-1234abcd.jpg"/></div><!-- /wp:cover -->',
            'a-1234abcd.jpg',
        ],
        [
            '<!-- wp:cover {"url":"/wp-content/themes/demo/assets/b-99887766.jpg"} -->'
                . '<div><img src="/wp-content/themes/demo/assets/b-99887766.jpg"/></div><!-- /wp:cover -->',
            'b-99887766.jpg',
        ],
    ];

    foreach ($cases as [$input, $filename]) {
        $output = ExtractPatternsStep::rewriteAssets($input);
        $expected = "<?php echo esc_url( get_theme_file_uri( 'assets/{$filename}' ) ); ?>";
        assert_eq(2, substr_count($output, $expected), 'JSON attribute and inner HTML both rewrite');
        assert_true(!str_contains($output, 'theme:./assets/'), 'placeholder form gone');
        assert_true(!str_contains($output, '/wp-content/themes/'), 'hardcoded slug gone');
    }
});

test('the id scan ignores hex colors and reads selector positions', function (): void {
    $css = 'a{color:#fff} #hero > .x,#cta:hover{padding:0}'
        . '.x{background:#abc;border-color:#123456}@media(min-width:1px){#inside{display:block}}';
    $ids = ExtractPatternsStep::idSelectorsIn($css);

    assert_true(isset($ids['hero']) && isset($ids['cta']) && isset($ids['inside']));
    assert_true(!isset($ids['fff']) && !isset($ids['abc']) && !isset($ids['123456']));
});

test('an anchor targeted by style.css survives and an untargeted anchor is stripped', function (): void {
    $markup = '<!-- wp:group {"anchor":"hero"} --><section id="hero"></section><!-- /wp:group -->';
    $kept = ExtractPatternsStep::rewriteAnchors($markup, ['hero' => true]);
    $stripped = ExtractPatternsStep::rewriteAnchors($markup, []);

    assert_contains('"anchor":"hero"', $kept);
    assert_contains('id="hero"', $kept);
    assert_true(!str_contains($stripped, '"anchor":"hero"'));
    assert_true(!str_contains($stripped, 'id="hero"'));
});

test('same-node unused cover anchor keeps a PHP theme-asset url unescaped', function (): void {
    with_project('builder_extract_same_node_php_', function (Project $project): void {
        $asset = 'hero-1234abcd.jpg';
        $php = "<?php echo esc_url( get_theme_file_uri( 'assets/{$asset}' ) ); ?>";
        $markup = '<!-- wp:cover {"url":"theme:./assets/' . $asset . '","anchor":"hero"} -->'
            . '<div class="wp-block-cover" id="hero">'
            . '<img class="wp-block-cover__image-background" src="theme:./assets/' . $asset . '" alt=""/>'
            . '</div><!-- /wp:cover -->';
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => $markup],
            ['slug' => 'cta', 'markup' => sp_columns(1)],
        ]);

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'hero');
        assert_contains($php, $pattern);
        assert_true(!str_contains($pattern, '\\u003C'), 'JSON_HEX_TAG must not escape the PHP url');
        assert_true(!str_contains($pattern, '"anchor":"hero"'));
        assert_true(!str_contains($pattern, 'id="hero"'));
    });
});

test('link rewrite keeps self-contained targets and neutralizes outside fragments in HTML and block JSON', function (): void {
    $markup = '<!-- wp:group {"anchor":"book"} --><section id="book">'
        . '<a href="#book">stay</a><a href="#pricing">leave</a>'
        . '<a href="/contact/">page</a><a href="mailto:a@b.co">mail</a>'
        . '<!-- wp:navigation-link {"url":"#pricing"} /-->'
        . '</section><!-- /wp:group -->';
    $output = ExtractPatternsStep::rewriteLinks($markup, ['/contact/' => true]);

    assert_contains('href="#book"', $output);
    assert_contains('href="#"', $output);
    assert_contains('href="/contact/"', $output);
    assert_contains('mailto:a@b.co', $output);
    assert_contains('"url":"#"', $output, 'block-JSON targets use the shared allTargets domain');
});

test('link rewrite neutralizes javascript data and vbscript destinations', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<a href="javascript:alert(1)">js</a>'
        . '<a href="DATA:text/html,hi">data</a>'
        . '<a href="vbscript:msgbox(1)">vb</a>'
        . '<a href="https://example.com">ok</a>'
        . '<a href="tel:+15551212">call</a>'
        . '<!-- wp:navigation-link {"url":"javascript:alert(2)"} /-->'
        . '<!-- wp:image {"href":"javascript:alert(3)"} /-->'
        . '<!-- wp:file {"href":"DATA:text/html,file","textLinkHref":"javascript:alert(4)"} /-->'
        . '<!-- wp:media-text {"href":"vbscript:msgbox(2)"} /-->'
        . '</div><!-- /wp:group -->';
    $output = ExtractPatternsStep::rewriteLinks($markup, []);

    assert_true(!str_contains(strtolower($output), 'javascript:'));
    assert_true(!str_contains(strtolower($output), 'data:'));
    assert_true(!str_contains(strtolower($output), 'vbscript:'));
    assert_contains('href="https://example.com"', $output);
    assert_contains('href="tel:+15551212"', $output);
    assert_contains('"url":"#"', $output);
    assert_contains('<!-- wp:image {"href":"#"} /-->', $output);
    assert_contains('<!-- wp:file {"href":"#","textLinkHref":"#"} /-->', $output);
    assert_contains('<!-- wp:media-text {"href":"#"} /-->', $output);
    assert_eq(['#', '#', '#'], LinkTargets::hrefAttrsIn($output));
    assert_eq(['#'], LinkTargets::textLinkHrefAttrsIn($output));
    assert_true(!in_array('javascript:alert(3)', LinkTargets::allTargets($output), true));
    assert_true(!in_array('javascript:alert(4)', LinkTargets::allTargets($output), true));
});

test('link rewrite collects and rewrites JSON href without HTML href or JSON url', function (): void {
    $cases = [
        'image' => '<!-- wp:image {"href":"javascript:alert(1)"} --><figure class="wp-block-image"></figure><!-- /wp:image -->',
        'file' => '<!-- wp:file {"href":"DATA:text/html,hi"} --><div class="wp-block-file"></div><!-- /wp:file -->',
        'media-text' => '<!-- wp:media-text {"href":"vbscript:msgbox(1)"} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->',
    ];
    foreach ($cases as $name => $markup) {
        assert_eq([], LinkTargets::hrefsIn($markup), $name . ' has no HTML href');
        assert_eq([], LinkTargets::urlAttrsIn($markup), $name . ' has no JSON url');
        $targets = LinkTargets::allTargets($markup);
        assert_true($targets !== [], $name . ' JSON href is visible to collectors');
        $output = ExtractPatternsStep::rewriteLinks($markup, []);
        assert_contains('"href":"#"', $output, $name);
        assert_true(!str_contains(strtolower($output), 'javascript:'), $name);
        assert_true(!str_contains(strtolower($output), 'data:'), $name);
        assert_true(!str_contains(strtolower($output), 'vbscript:'), $name);
        assert_eq(['#'], LinkTargets::hrefAttrsIn($output), $name);
    }
});

test('link rewrite collects and rewrites JSON textLinkHref on core/file', function (): void {
    $markup = '<!-- wp:file {"href":"/file.pdf","textLinkHref":"javascript:alert(1)"} --><div class="wp-block-file"></div><!-- /wp:file -->';
    assert_eq([], LinkTargets::hrefsIn($markup), 'no HTML href');
    assert_eq([], LinkTargets::urlAttrsIn($markup), 'no JSON url');
    assert_eq(['/file.pdf'], LinkTargets::hrefAttrsIn($markup));
    assert_eq(['javascript:alert(1)'], LinkTargets::textLinkHrefAttrsIn($markup));
    assert_true(in_array('javascript:alert(1)', LinkTargets::allTargets($markup), true));
    $output = ExtractPatternsStep::rewriteLinks($markup, []);
    assert_contains('"href":"/file.pdf"', $output, 'safe JSON href is kept');
    assert_contains('"textLinkHref":"#"', $output);
    assert_true(!str_contains(strtolower($output), 'javascript:'));
    assert_eq(['#'], LinkTargets::textLinkHrefAttrsIn($output));
    assert_true(!in_array('javascript:alert(1)', LinkTargets::allTargets($output), true));
});

test('link rewrite collects and rewrites JSON textLinkHref without HTML href or JSON url', function (): void {
    $markup = '<!-- wp:file {"textLinkHref":"javascript:alert(1)"} --><div class="wp-block-file"></div><!-- /wp:file -->';
    assert_eq([], LinkTargets::hrefsIn($markup), 'file textLinkHref has no HTML href');
    assert_eq([], LinkTargets::urlAttrsIn($markup), 'file textLinkHref has no JSON url');
    assert_eq([], LinkTargets::hrefAttrsIn($markup), 'file textLinkHref is not JSON href');
    assert_eq(['javascript:alert(1)'], LinkTargets::textLinkHrefAttrsIn($markup));
    assert_true(in_array('javascript:alert(1)', LinkTargets::allTargets($markup), true));
    $output = ExtractPatternsStep::rewriteLinks($markup, []);
    assert_contains('"textLinkHref":"#"', $output);
    assert_true(!str_contains(strtolower($output), 'javascript:'));
    assert_eq(['#'], LinkTargets::textLinkHrefAttrsIn($output));
    assert_true(!in_array('javascript:alert(1)', LinkTargets::allTargets($output), true));
});


test('link rewrite hashes JSON unicode and entity-encoded javascript', function (): void {
    $cases = [
        'image-unicode' => '<!-- wp:image {"href":"\u006Aavascript:alert(1)"} /-->',
        'file-entity' => '<!-- wp:file {"href":"javascript&colon;alert(1)"} /-->',
        'file-text-unicode' => '<!-- wp:file {"textLinkHref":"\u006Aavascript:alert(2)"} /-->',
        'file-text-entity' => '<!-- wp:file {"textLinkHref":"javascript&colon;alert(2)"} /-->',
        'nav-unicode' => '<!-- wp:navigation-link {"url":"\u006Aavascript:alert(3)"} /-->',
    ];
    foreach ($cases as $name => $markup) {
        assert_true(LinkTargets::isDangerousScheme(LinkTargets::allTargets($markup)[0] ?? ''), $name . ' is visible as dangerous');
        $output = ExtractPatternsStep::rewriteLinks($markup, []);
        assert_true(!str_contains(strtolower($output), 'javascript'), $name);
        assert_true(!str_contains($output, '\\u006A'), $name);
        assert_true(!str_contains($output, '&colon;'), $name);
        foreach (LinkTargets::allTargets($output) as $target) {
            assert_eq('#', $target, $name);
        }
    }
});

test('link rewrite hashes javascript with tab or space inside the scheme', function (): void {
    $cases = [
        'image-tab' => "<!-- wp:image {\"href\":\"java\tscript:alert(1)\"} /-->",
        'file-space' => '<!-- wp:file {"textLinkHref":"java script:alert(2)"} /-->',
    ];
    foreach ($cases as $name => $markup) {
        assert_true(LinkTargets::isDangerousScheme(LinkTargets::allTargets($markup)[0] ?? ''), $name);
        $output = ExtractPatternsStep::rewriteLinks($markup, []);
        foreach (LinkTargets::allTargets($output) as $target) {
            assert_eq('#', $target, $name);
        }
    }
});

test('a stale pattern file from a prior run is gone after a re-run', function (): void {
    with_project('builder_extract_patterns_', function (Project $project): void {
        extract_patterns_seed($project);
        $project->writeText('theme/patterns/ghost.php', '<?php // stale');
        $project->writeJson('patterns.json', [
            'version' => 2,
            'patterns' => [['slug' => 'ghost', 'kind' => 'section']],
            'starter' => null,
            'dropped' => [],
        ]);

        (new ExtractPatternsStep())->run($project);

        assert_true(!$project->exists('theme/patterns/ghost.php'));
        $slugs = array_column($project->readJson('patterns.json')['patterns'], 'slug');
        assert_true(!in_array('ghost', $slugs, true));
        assert_true(!is_dir($project->themePath('.patterns-next')));
        assert_true(!is_dir($project->themePath('.patterns-prev')));
    });
});

test('a throw before the pattern swap leaves the previous directory and manifest', function (): void {
    with_project('builder_extract_swap_pre_', function (Project $project): void {
        extract_patterns_seed($project);
        $project->writeText('theme/patterns/ghost.php', '<?php // stale');
        $project->writeJson('patterns.json', [
            'version' => 2,
            'patterns' => [['slug' => 'ghost', 'kind' => 'section']],
            'starter' => null,
            'dropped' => [],
        ]);

        $project->writeText('theme/theme.json', '{');
        assert_throws(static fn () => (new ExtractPatternsStep())->run($project));

        assert_true($project->exists('theme/patterns/ghost.php'), 'wipe is not delete-then-write');
        assert_eq('<?php // stale', $project->readText('theme/patterns/ghost.php'));
        assert_eq('ghost', $project->readJson('patterns.json')['patterns'][0]['slug']);
    });
});

test('a failed pattern swap leaves the previous directory and manifest', function (): void {
    with_project('builder_extract_swap_fail_', function (Project $project): void {
        extract_patterns_seed($project);
        $project->writeText('theme/patterns/ghost.php', '<?php // stale');
        $project->writeJson('patterns.json', [
            'version' => 2,
            'patterns' => [['slug' => 'ghost', 'kind' => 'section']],
            'starter' => null,
            'dropped' => [],
        ]);
        $theme = $project->themePath();
        $mode = fileperms($theme) & 0777;
        if (!chmod($theme, 0555) || is_writable($theme)) {
            skip_test('cannot make the theme directory read-only on this platform');
        }
        try {
            assert_throws(static fn () => (new ExtractPatternsStep())->run($project));
        } finally {
            chmod($theme, $mode !== 0 ? $mode : 0775);
        }
        assert_true($project->exists('theme/patterns/ghost.php'), 'live pattern dir survives a failed swap');
        assert_eq('<?php // stale', $project->readText('theme/patterns/ghost.php'));
        assert_eq('ghost', $project->readJson('patterns.json')['patterns'][0]['slug']);
    });
});

test('first-run manifest failure does not leave new pattern files', function (): void {
    with_project('builder_extract_first_run_fail_', function (Project $project): void {
        $live = $project->themePath('patterns');
        assert_true(!is_dir($live), 'first run has no live pattern dir');
        $blocker = $project->path('patterns.json');
        if (is_file($blocker)) {
            unlink($blocker);
        }
        mkdir($blocker);
        file_put_contents($blocker . '/keep', 'old');

        $method = new \ReflectionMethod(ExtractPatternsStep::class, 'replacePatternOutputs');
        $method->setAccessible(true);
        $step = new ExtractPatternsStep();
        assert_throws(static fn () => $method->invoke($step, $project, [
            'hero.php' => "<?php\n// new\n",
        ], [
            'version' => 2,
            'patterns' => [['slug' => 'hero', 'kind' => 'section']],
            'starter' => null,
            'dropped' => [],
        ]));

        assert_true(!$project->exists('theme/patterns/hero.php'), 'new files do not stay when the manifest cannot land');
        assert_true(is_dir($blocker), 'old manifest path is untouched');
        assert_true(!is_dir($project->themePath('.patterns-next')));
    });
});

test('pattern headers use only the closed label and shape vocabulary', function (): void {
    with_project('builder_extract_header_', function (Project $project): void {
        extract_patterns_seed($project, [[
            'slug' => 'hero',
            'markup' => extract_patterns_grid_with_heading("Spike\n&\nFur */ generated title"),
        ]]);

        (new ExtractPatternsStep())->run($project);

        $pattern = $project->readText('theme/patterns/hero-grid.php');
        $header = strstr($pattern, '?>', true);
        assert_true(is_string($header), 'pattern has a closed PHP header');
        assert_contains('Title:', $header);
        assert_contains('Hero', $header);
        assert_contains('grid', strtolower($header));
        assert_contains('Slug: demo/hero-grid', $header);
        assert_contains('Categories: demo-sections', $header);
        assert_contains('Viewport Width: 1400', $header);
        assert_true(!str_contains($header, 'Spike'));
        assert_true(!str_contains($header, 'generated title'));
        assert_contains('Spike', $pattern, 'winner copy remains seeded verbatim outside the PHP header');
    });
});

test('patterns.json matches its frozen contract', function (): void {
    with_project('builder_extract_manifest_', function (Project $project): void {
        extract_patterns_seed($project);
        (new ExtractPatternsStep())->run($project);
        $manifest = $project->readJson('patterns.json');

        assert_eq(2, $manifest['version']);
        assert_eq(['version', 'patterns', 'starter', 'dropped'], array_keys($manifest));
        assert_true($manifest['patterns'] !== []);
        $pattern = $manifest['patterns'][0];
        assert_eq([
            'slug', 'kind', 'label', 'shape', 'title', 'categories', 'source', 'score', 'contains', 'alternates',
        ], array_keys($pattern));
        assert_eq('section', $pattern['kind']);
        assert_eq(['page', 'section', 'index'], array_keys($pattern['source']));
        assert_eq([
            'total', 'completeness', 'repetition', 'copy_fit', 'fidelity', 'self_containment',
        ], array_keys($pattern['score']));
        assert_eq(['headings', 'media', 'actions', 'items'], array_keys($pattern['contains']));
        assert_eq(['slug', 'sections'], array_keys($manifest['starter']));
        assert_eq([], $manifest['dropped']);
        assert_true($project->exists('logs/extract-patterns.log'));
        assert_contains('winner', strtolower($project->readText('logs/extract-patterns.log')));
    });
});

test('same-key candidates emit one winner and record the loser as an alternate', function (): void {
    with_project('builder_extract_dedupe_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            ['slug' => 'service', 'markup' => sp_columns(3)],
            ['slug' => 'services-grid', 'markup' => sp_columns(3)],
            ['slug' => 'cta', 'markup' => sp_columns(1)],
        ]);

        (new ExtractPatternsStep())->run($project);

        $patterns = $project->readJson('patterns.json')['patterns'];
        $services = array_values(array_filter(
            $patterns,
            static fn (array $pattern): bool => $pattern['slug'] === 'service-grid',
        ));
        assert_eq(1, count($services));
        assert_eq(1, count($services[0]['alternates']));
        assert_eq(['page', 'section', 'total'], array_keys($services[0]['alternates'][0]));
    });
});

test('band CTA removal preserves buttons nested inside a column and leaves page markup unchanged', function (): void {
    with_project('builder_extract_cta_level_', function (Project $project): void {
        $service = extract_patterns_group(
            '<!-- wp:heading --><h2>Services</h2><!-- /wp:heading -->'
            . extract_patterns_buttons('BAND ACTION')
            . extract_patterns_columns([
                '<!-- wp:paragraph --><p>Card copy</p><!-- /wp:paragraph -->'
                . extract_patterns_buttons('CARD ACTION'),
            ]),
        );
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            ['slug' => 'service', 'markup' => $service],
        ]);
        $pageBefore = $project->readText('plugin/pages/home.html');

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'service');
        assert_eq(['band' => 0, 'in_card' => 1], extract_patterns_button_levels($pattern));
        assert_true(!str_contains($pattern, 'BAND ACTION'));
        assert_contains('CARD ACTION', $pattern);
        assert_eq($pageBefore, $project->readText('plugin/pages/home.html'));
    });
});

test('band CTA nested in media-text is removed without deleting sibling content', function (): void {
    with_project('builder_extract_cta_media_', function (Project $project): void {
        $process = extract_patterns_group(
            '<!-- wp:media-text --><div class="wp-block-media-text">'
            . '<!-- wp:heading --><h2>PROCESS HEADING</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>PROCESS PARAGRAPH</p><!-- /wp:paragraph -->'
            . '<!-- wp:list --><ul><li>PROCESS LIST</li></ul><!-- /wp:list -->'
            . extract_patterns_buttons('PROCESS ACTION')
            . '</div><!-- /wp:media-text -->',
        );
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            ['slug' => 'process', 'markup' => $process],
        ]);

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'process');
        assert_eq(['band' => 0, 'in_card' => 0], extract_patterns_button_levels($pattern));
        assert_contains('PROCESS HEADING', $pattern);
        assert_contains('PROCESS PARAGRAPH', $pattern);
        assert_contains('PROCESS LIST', $pattern);
        assert_true(!str_contains($pattern, 'PROCESS ACTION'));
    });
});

test('band CTA removal prunes its empty container chain but keeps the section wrapper and sibling bytes', function (): void {
    with_project('builder_extract_cta_empty_chain_', function (Project $project): void {
        $sibling = '<!-- wp:paragraph --><p>SURVIVING SIBLING BYTE SENTINEL</p><!-- /wp:paragraph -->';
        $ctaOnlyChain = extract_patterns_group(
            extract_patterns_group(extract_patterns_buttons('NESTED BAND ACTION')),
        );
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            ['slug' => 'testimonial', 'markup' => extract_patterns_group($sibling . $ctaOnlyChain)],
        ]);
        $pageBefore = $project->readText('plugin/pages/home.html');

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'testimonial');
        assert_true(!str_contains($pattern, 'NESTED BAND ACTION'));
        assert_contains($sibling, $pattern, 'surviving sibling bytes remain exact');
        $document = BlockMarkup::parse($pattern);
        $groups = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => in_array($document->name($index), ['group', 'core/group'], true),
        ));
        assert_eq(1, count($groups), 'only top-level section group remains after upward pruning');
        assert_eq(null, $document->parent($groups[0]), 'section wrapper remains top-level');
        assert_true(!str_contains($pattern, '<div class="wp-block-group"></div>'), 'no empty group remains');
        assert_eq($pageBefore, $project->readText('plugin/pages/home.html'));
    });
});

test('only cta closing and contact labels retain band-level buttons', function (): void {
    with_project('builder_extract_cta_exempt_', function (Project $project): void {
        $sections = [];
        foreach (['hero', 'cta', 'closing', 'contact', 'service'] as $label) {
            $sections[] = [
                'slug' => $label,
                'markup' => extract_patterns_group(
                    '<!-- wp:paragraph --><p>' . strtoupper($label) . ' COPY</p><!-- /wp:paragraph -->'
                    . extract_patterns_buttons(strtoupper($label) . ' ACTION'),
                ),
            ];
        }
        extract_patterns_seed($project, $sections);

        (new ExtractPatternsStep())->run($project);

        $expected = ['cta' => 1, 'closing' => 1, 'contact' => 1, 'hero' => 0, 'service' => 0];
        foreach ($expected as $label => $bandButtons) {
            $counts = extract_patterns_button_levels(extract_patterns_for_label($project, $label));
            assert_eq($bandButtons, $counts['band'], $label);
        }
        assert_eq(['cta', 'closing', 'contact'], array_keys(array_filter(
            $expected,
            static fn (int $count): bool => $count === 1,
        )));
    });
});

test('buttons-only non-exempt pattern keeps its CTA and records actionable warning context', function (): void {
    with_project('builder_extract_cta_nonempty_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            ['slug' => 'service', 'markup' => extract_patterns_group(extract_patterns_buttons('ONLY ACTION'))],
        ]);

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'service');
        assert_eq(['band' => 1, 'in_card' => 0], extract_patterns_button_levels($pattern));
        assert_contains('ONLY ACTION', $pattern);
        $warnings = strtolower($project->readText('warnings.json'));
        foreach ([
            'theme/patterns/service-stack.php',
            'block path',
            'authored value',
            'core/buttons',
            'delivered value',
            'retained',
            'disposition',
        ] as $context) {
            assert_contains($context, $warnings, $context);
        }
    });
});

test('NBSP-only wrapper content cannot turn CTA removal into a blank pattern', function (): void {
    with_project('builder_extract_cta_nbsp_nonempty_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
            [
                'slug' => 'service',
                'markup' => extract_patterns_group(
                    "&nbsp;\u{00A0}" . extract_patterns_buttons('NBSP ONLY ACTION'),
                ),
            ],
        ]);
        $pageBefore = $project->readText('plugin/pages/home.html');

        (new ExtractPatternsStep())->run($project);

        $pattern = extract_patterns_for_label($project, 'service');
        assert_eq(['band' => 1, 'in_card' => 0], extract_patterns_button_levels($pattern));
        assert_contains('NBSP ONLY ACTION', $pattern, 'CTA retained instead of shipping blank wrapper');
        $warnings = strtolower($project->readText('warnings.json'));
        foreach ([
            'theme/patterns/service-stack.php',
            'block path',
            'authored value',
            'core/buttons',
            'delivered value',
            'retained',
            'disposition',
        ] as $context) {
            assert_contains($context, $warnings, $context);
        }
        assert_eq($pageBefore, $project->readText('plugin/pages/home.html'));
    });
});

test('page starter applies shared CTA stripping and keeps exact band and in-card counts', function (): void {
    with_project('builder_extract_cta_starter_', function (Project $project): void {
        $paragraph = '<!-- wp:paragraph --><p>Useful copy</p><!-- /wp:paragraph -->';
        extract_patterns_seed($project, [
            [
                'slug' => 'hero',
                'markup' => extract_patterns_group(extract_patterns_columns([
                    $paragraph . extract_patterns_buttons('HERO CARD ACTION'),
                    $paragraph,
                ])),
            ],
            [
                'slug' => 'service',
                'markup' => extract_patterns_group(
                    extract_patterns_columns([
                        $paragraph . extract_patterns_buttons('SERVICE CARD ACTION'),
                        $paragraph,
                    ])
                    . extract_patterns_buttons('SERVICE BAND ACTION'),
                ),
            ],
            [
                'slug' => 'testimonial',
                'markup' => extract_patterns_group(
                    extract_patterns_columns([$paragraph, $paragraph, $paragraph])
                    . extract_patterns_buttons('TESTIMONIAL BAND ACTION'),
                ),
            ],
            [
                'slug' => 'process',
                'markup' => extract_patterns_group(
                    extract_patterns_columns([$paragraph, $paragraph, $paragraph])
                    . extract_patterns_buttons('PROCESS BAND ACTION'),
                ),
            ],
            [
                'slug' => 'cta',
                'markup' => extract_patterns_group($paragraph . extract_patterns_buttons('CTA BAND ACTION')),
            ],
        ]);

        (new ExtractPatternsStep())->run($project);

        $starter = $project->readText('theme/patterns/page-starter.php');
        assert_eq(['band' => 1, 'in_card' => 2], extract_patterns_button_levels($starter));
        assert_contains('HERO CARD ACTION', $starter);
        assert_contains('SERVICE CARD ACTION', $starter);
        assert_contains('CTA BAND ACTION', $starter);
        assert_true(!str_contains($starter, 'SERVICE BAND ACTION'));
        assert_true(!str_contains($starter, 'TESTIMONIAL BAND ACTION'));
        assert_true(!str_contains($starter, 'PROCESS BAND ACTION'));
    });
});

test('starter greedily alternates adjacent non-empty backgrounds when possible', function (): void {
    with_project('builder_extract_starter_bg_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero',    'markup' => sp_bg('contrast')],
            ['slug' => 'about',   'markup' => sp_bg('contrast')],
            ['slug' => 'service', 'markup' => sp_bg('')],
            ['slug' => 'cta',     'markup' => sp_bg('')],
        ]);

        (new ExtractPatternsStep())->run($project);

        $manifest = $project->readJson('patterns.json');
        assert_eq([
            'hero-stack', 'service-stack', 'about-stack', 'cta-stack',
        ], $manifest['starter']['sections']);
        $starter = $project->readText('theme/patterns/page-starter.php');
        assert_eq(['contrast', null, 'contrast', null], extract_patterns_backgrounds($starter));
        assert_true(!str_contains($starter, '<!-- wp:pattern'), 'starter inlines winners');
    });
});

test('starter emits plain source role order when no section carries a background', function (): void {
    with_project('builder_extract_starter_plain_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero',  'markup' => sp_bg('')],
            ['slug' => 'zeta',  'markup' => sp_bg('')],
            ['slug' => 'alpha', 'markup' => sp_bg('')],
            ['slug' => 'beta',  'markup' => sp_bg('')],
            ['slug' => 'cta',   'markup' => sp_bg('')],
        ]);

        (new ExtractPatternsStep())->run($project);

        assert_eq([
            'hero-stack', 'zeta-stack', 'alpha-stack', 'beta-stack', 'cta-stack',
        ], $project->readJson('patterns.json')['starter']['sections']);
    });
});

test('starter is omitted with a warning when no hero winner exists', function (): void {
    with_project('builder_extract_no_hero_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero',  'markup' => '<!-- wp:group --><div><?php ?></div><!-- /wp:group -->'],
            ['slug' => 'about', 'markup' => sp_columns(3)],
        ]);

        (new ExtractPatternsStep())->run($project);

        assert_true(!$project->exists('theme/patterns/page-starter.php'));
        assert_eq(null, $project->readJson('patterns.json')['starter']);
        assert_contains('starter', strtolower($project->readText('warnings.json')));
    });
});

test('drift warning fires when top-level count differs from planned count', function (): void {
    with_project('builder_extract_drift_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero',  'markup' => sp_columns(3)],
            ['slug' => 'about', 'markup' => sp_columns(3)],
        ]);
        $project->writeText(
            'plugin/pages/home.html',
            $project->readText('plugin/pages/home.html') . sp_columns(2),
        );

        (new ExtractPatternsStep())->run($project);

        assert_contains('top-level', strtolower($project->readText('warnings.json')));
    });
});

test('healthy shape-only HTML-first sections do not trigger a drift warning', function (): void {
    with_project('builder_extract_shape_only_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'section-1', 'markup' => sp_columns(1)],
            ['slug' => 'section-2', 'markup' => sp_columns(2)],
            ['slug' => 'section-3', 'markup' => sp_columns(3)],
            ['slug' => 'section-4', 'markup' => sp_columns(1)],
            ['slug' => 'section-5', 'markup' => sp_columns(2)],
        ]);

        (new ExtractPatternsStep())->run($project);

        $warnings = $project->exists('warnings.json') ? strtolower($project->readText('warnings.json')) : '';
        assert_true(!str_contains($warnings, 'top-level'));
    });
});

test('a site with no eligible section writes no patterns directory and completes', function (): void {
    with_project('builder_extract_zero_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'a', 'markup' => '<!-- wp:group --><div><?php ?></div><!-- /wp:group -->'],
            ['slug' => 'b', 'markup' => '<!-- wp:group --><div><?= 1 ?></div><!-- /wp:group -->'],
        ]);

        (new ExtractPatternsStep())->run($project);

        assert_true(!$project->exists('theme/patterns'));
        assert_eq([], $project->readJson('patterns.json')['patterns']);
        assert_contains('extract-patterns', $project->readText('warnings.json'));
    });
});

test('a page with malformed delimiters is skipped with a warning and completes', function (): void {
    with_project('builder_extract_malformed_', function (Project $project): void {
        extract_patterns_seed($project, [[
            'slug' => 'hero',
            'markup' => '<!-- wp:group --><div><!-- wp:paragraph --><p>unfinished</p>',
        ]]);

        (new ExtractPatternsStep())->run($project);

        assert_eq([], $project->readJson('patterns.json')['patterns']);
        assert_true(!$project->exists('theme/patterns'));
        assert_contains('structural', strtolower($project->readText('warnings.json')));
    });
});

test('section cap emits ten sections while component cap emits six source pairs', function (): void {
    with_project('builder_extract_cap_', function (Project $project): void {
        $slugs = [
            'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf',
            'hotel', 'india', 'juliet', 'kilo', 'lima', 'mango',
        ];
        extract_patterns_seed($project, array_map(
            static fn (string $slug): array => ['slug' => $slug, 'markup' => sp_columns(3)],
            $slugs,
        ));

        (new ExtractPatternsStep())->run($project);

        $manifest = $project->readJson('patterns.json');
        assert_eq(10, count(array_filter(
            $manifest['patterns'],
            static fn (array $pattern): bool => $pattern['kind'] === 'section',
        )));
        assert_eq(12, count(array_filter(
            $manifest['patterns'],
            static fn (array $pattern): bool => $pattern['kind'] === 'component',
        )));
        assert_eq(10, count($manifest['dropped']));
        assert_eq(['kind', 'key', 'reason', 'total'], array_keys($manifest['dropped'][0]));
        assert_eq('cap', $manifest['dropped'][0]['reason']);
        $warnings = $project->readText('warnings.json');
        assert_contains($manifest['dropped'][0]['key'], $warnings);
        assert_contains('disposition', $warnings);
    });
});

test('postImages URL rewrite leaves pattern outputs byte-identical', function (): void {
    with_project('builder_extract_idem_', function (Project $project): void {
        $asset = 'hero-1234abcd.jpg';
        $markup = '<!-- wp:cover {"url":"theme:./assets/' . $asset . '"} -->'
            . '<div><img src="theme:./assets/' . $asset . '"/></div><!-- /wp:cover -->';
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => $markup],
            ['slug' => 'cta', 'markup' => sp_columns(1)],
        ]);

        (new ExtractPatternsStep())->run($project);
        $first = extract_patterns_snapshot($project);
        extract_patterns_apply_cover_contrast_rewrite($project);
        (new ExtractPatternsStep())->run($project);
        $second = extract_patterns_snapshot($project);

        assert_eq($first, $second);
    });
});

test('every emitted pattern file is valid PHP', function (): void {
    with_project('builder_extract_php_lint_', function (Project $project): void {
        extract_patterns_seed($project, [
            ['slug' => 'hero', 'markup' => extract_patterns_grid_with_heading('Safe */ copy')],
            ['slug' => 'cta', 'markup' => sp_columns(1)],
        ]);
        (new ExtractPatternsStep())->run($project);

        $files = glob($project->themePath('patterns/*.php')) ?: [];
        assert_true($files !== []);
        foreach ($files as $file) {
            $output = [];
            $status = 1;
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $output, $status);
            assert_eq(0, $status, basename($file) . ': ' . implode("\n", $output));
        }
    });
});

test('BuildReport exposes and renders the pattern kind counters', function (): void {
    $report = new BuildReport('p', 'slug', '/tmp/slug', '2026-08-22T00:00:00+00:00');
    assert_eq(null, $report->patternsLine());

    $report->setPatterns(4, 6, 1);

    assert_eq('Patterns: 4 sections, 6 components, 1 dropped', $report->patternsLine());
    assert_contains('Patterns: 4 sections, 6 components, 1 dropped', $report->render());
});

test('build CLI populates the pattern counter from patterns.json', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/build.php');
    assert_contains('patterns.json', $source);
    assert_contains('$report->setPatterns(', $source);
});

test('cap reserves hero and call-to-action coverage before higher-scoring grid variants', function (): void {
    with_project('builder_extract_cap_roles_', function (Project $project): void {
        $sections = [
            ['slug' => 'hero', 'markup' => sp_columns(1)],
        ];
        foreach ([
            'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot',
            'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima',
        ] as $slug) {
            $sections[] = ['slug' => $slug, 'markup' => sp_columns(3)];
        }
        $sections[] = ['slug' => 'cta', 'markup' => sp_columns(1)];
        extract_patterns_seed($project, $sections);

        (new ExtractPatternsStep())->run($project);

        $manifest = $project->readJson('patterns.json');
        $slugs = array_column($manifest['patterns'], 'slug');
        assert_eq(22, count($slugs));
        assert_true(in_array('hero-stack', $slugs, true), 'hero coverage survives the cap');
        assert_true(in_array('cta-stack', $slugs, true), 'call-to-action coverage survives the cap');
        assert_eq(10, count($manifest['dropped']));
    });
});

test('label normalization distinguishes special es plurals from ordinary s plurals', function (): void {
    assert_eq('class', \Automattic\SiteBuild\SectionPattern::normalizeLabel('classes'));
    assert_eq('process', \Automattic\SiteBuild\SectionPattern::normalizeLabel('processes'));
    assert_eq('box', \Automattic\SiteBuild\SectionPattern::normalizeLabel('boxes'));
    assert_eq('branch', \Automattic\SiteBuild\SectionPattern::normalizeLabel('branches'));
    assert_eq('wish', \Automattic\SiteBuild\SectionPattern::normalizeLabel('wishes'));

    assert_eq('course', \Automattic\SiteBuild\SectionPattern::normalizeLabel('courses'));
    assert_eq('service', \Automattic\SiteBuild\SectionPattern::normalizeLabel('services'));

    assert_eq('process', \Automattic\SiteBuild\SectionPattern::normalizeLabel('process'));
    assert_eq('class', \Automattic\SiteBuild\SectionPattern::normalizeLabel('class'));
    assert_eq('status', \Automattic\SiteBuild\SectionPattern::normalizeLabel('status'));
    assert_eq('analysis', \Automattic\SiteBuild\SectionPattern::normalizeLabel('analysis'));

    assert_eq('news', \Automattic\SiteBuild\SectionPattern::normalizeLabel('news'));
    assert_eq('series', \Automattic\SiteBuild\SectionPattern::normalizeLabel('series'));
    assert_eq('species', \Automattic\SiteBuild\SectionPattern::normalizeLabel('species'));
});
