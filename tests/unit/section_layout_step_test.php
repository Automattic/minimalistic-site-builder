<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\SectionLayoutStep;
use Automattic\SiteBuild\Steps\SectionRhythmStep;

/** @param array<mixed> $attrs */
function section_layout_group(array $attrs, string $inner, string $wrapperAttrs = ''): string
{
    return BlockMarkup::serializeComment('group', $attrs, false)
        . '<div class="wp-block-group"' . $wrapperAttrs . '>' . $inner . '</div><!-- /wp:group -->';
}

/** @return array{0:Project,1:string} */
function section_layout_project(array $pages): array
{
    $tmp = sys_get_temp_dir() . '/builder_section_layout_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('pages.json', ['pages' => $pages]);
    return [$project, $tmp];
}

/** @return array<mixed> */
function section_layout_root_attrs(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $root = $doc->topLevel();
    if ($root === null) {
        throw new RuntimeException('fixture has no top-level block');
    }
    return $doc->attrs($root) ?? [];
}

/** @return array<mixed> */
function section_layout_first_attrs(string $markup, string $name): array
{
    $doc = BlockMarkup::parse($markup);
    foreach ($doc->indices() as $i) {
        if ($doc->name($i) === $name) {
            return $doc->attrs($i) ?? [];
        }
    }
    throw new RuntimeException("fixture has no {$name} block");
}

/** @return array{attrs:array{top:mixed,bottom:mixed},html:list<string>} */
function section_layout_block_axis(string $markup): array
{
    $doc = BlockMarkup::parse($markup);
    $root = $doc->topLevel();
    if ($root === null) {
        throw new RuntimeException('fixture has no top-level block');
    }
    $padding = ($doc->attrs($root) ?? [])['style']['spacing']['padding'] ?? [];
    preg_match_all(
        '/padding-(?:top|bottom)\s*:\s*[^;"\']+/i',
        $doc->ownHtml($root),
        $matches,
    );
    return [
        'attrs' => ['top' => $padding['top'] ?? null, 'bottom' => $padding['bottom'] ?? null],
        'html' => $matches[0] ?? [],
    ];
}

test('section layout constrains every planned section root and leaves nested layout alone', function () {
    [$project, $tmp] = section_layout_project([
        ['slug' => 'home', 'sections' => [
            ['slug' => 'hero', 'background' => 'base', 'vertical_density' => 'standard'],
            ['slug' => 'story', 'background' => 'base', 'vertical_density' => 'compact'],
        ]],
        ['slug' => 'about', 'sections' => [
            ['slug' => 'team', 'background' => 'contrast', 'vertical_density' => 'standard'],
        ]],
    ]);
    try {
        $nested = section_layout_group(['layout' => ['type' => 'flex']], 'Nested');
        $project->writeText(
            'theme/parts/page-home--hero.html',
            section_layout_group(['layout' => ['type' => 'flex']], $nested),
        );
        $project->writeText('theme/parts/page-home--story.html', section_layout_group([], 'Story'));
        $project->writeText(
            'theme/parts/page-about--team.html',
            section_layout_group(['layout' => ['type' => 'constrained', 'justifyContent' => 'right']], 'Team'),
        );

        $step = new SectionLayoutStep();
        assert_eq('section-layout', $step->id());
        assert_eq(['pages.json', 'theme/parts/*', 'design/site.css'], $step->declaration()->reads);
        assert_eq(['theme/parts/*', 'warnings.json'], $step->declaration()->writes);
        $step->run($project);

        foreach (['page-home--hero', 'page-home--story', 'page-about--team'] as $part) {
            $markup = $project->readText("theme/parts/{$part}.html");
            $attrs = section_layout_root_attrs($markup);
            assert_eq(['type' => 'constrained'], $attrs['layout'], "{$part} root is constrained");
            $doc = BlockMarkup::parse($markup);
            $root = $doc->topLevel();
            assert_true($root !== null);
            assert_true(
                !str_contains($doc->ownHtml($root), 'is-layout-constrained'),
                'SectionLayout owns comment attrs; FixBlocks owns derived wrapper classes',
            );
        }
        assert_contains(
            $nested,
            $project->readText('theme/parts/page-home--hero.html'),
            'nested layout is outside the section-root ownership boundary',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout structurally replaces a shorthand-only gutter after section rhythm', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [[
            'slug' => 'story', 'background' => 'base', 'vertical_density' => 'compact',
        ]],
    ]]);
    try {
        $project->writeText(
            'theme/parts/page-home--story.html',
            section_layout_group([], '<!-- wp:paragraph --><p>Story</p><!-- /wp:paragraph -->', ' style="padding:6rem 2rem"'),
        );

        (new SectionRhythmStep())->run($project);
        $rhythmMarkup = $project->readText('theme/parts/page-home--story.html');
        assert_true(!str_contains($rhythmMarkup, 'padding:6rem 2rem'), 'rhythm removes the authored shorthand');

        (new SectionLayoutStep())->run($project);
        $layoutMarkup = $project->readText('theme/parts/page-home--story.html');
        assert_eq(
            ['type' => 'constrained'],
            section_layout_root_attrs($layoutMarkup)['layout'] ?? null,
            'content width comes from the theme layout contract, not authored padding',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout bleeds only a direct cover and never nested or ordinary images', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'visual', 'background' => 'image', 'vertical_density' => 'standard'],
            ['slug' => 'ambiguous', 'background' => 'image', 'vertical_density' => 'standard'],
            ['slug' => 'nested', 'background' => 'image', 'vertical_density' => 'standard'],
            ['slug' => 'text', 'background' => 'base', 'vertical_density' => 'compact'],
        ],
    ]]);
    try {
        $coverInner = '<!-- wp:paragraph {"className":"cover-copy"} --><p class="cover-copy">Visual</p><!-- /wp:paragraph -->';
        $cover = '<!-- wp:cover {"dimRatio":40,"className":"authored-cover"} -->'
            . '<div class="wp-block-cover authored-cover"><div class="wp-block-cover__inner-container">'
            . $coverInner
            . '</div></div><!-- /wp:cover -->';
        $image = '<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="visual.jpg" alt=""></figure><!-- /wp:image -->';
        $paragraph = '<!-- wp:paragraph --><p>Sibling text</p><!-- /wp:paragraph -->';
        $project->writeText('theme/parts/page-home--visual.html', section_layout_group([], $cover . $paragraph . $image));
        $project->writeText('theme/parts/page-home--ambiguous.html', section_layout_group([], $cover . $cover));
        $project->writeText(
            'theme/parts/page-home--nested.html',
            section_layout_group([], section_layout_group([], $cover)),
        );
        $project->writeText('theme/parts/page-home--text.html', section_layout_group([], $paragraph));

        (new SectionLayoutStep())->run($project);

        $visual = $project->readText('theme/parts/page-home--visual.html');
        $visualCoverAttrs = section_layout_first_attrs($visual, 'cover');
        assert_eq('full', $visualCoverAttrs['align'] ?? null);
        assert_eq(
            ['type' => 'constrained'],
            $visualCoverAttrs['layout'] ?? null,
            'full-bleed cover inner content uses the theme content column',
        );
        assert_eq(
            'authored-cover is-layout-constrained',
            $visualCoverAttrs['className'] ?? null,
            'cover carries the serializer class bridge for its existing inner container',
        );
        assert_contains(
            '<div class="wp-block-cover__inner-container">' . $coverInner . '</div>',
            $visual,
            'cover inner content stays byte-for-byte authored',
        );
        assert_contains($paragraph, $visual, 'sibling paragraph stays byte-for-byte authored');
        assert_contains($image, $visual, 'sibling image stays byte-for-byte authored');
        assert_true(!isset(section_layout_first_attrs($visual, 'paragraph')['align']), 'sibling text stays constrained');
        assert_true(!isset(section_layout_first_attrs($visual, 'image')['align']), 'ordinary direct image stays inset');

        $ambiguous = BlockMarkup::parse($project->readText('theme/parts/page-home--ambiguous.html'));
        $ambiguousRoot = $ambiguous->topLevel();
        assert_true($ambiguousRoot !== null);
        $directCovers = array_values(array_filter(
            $ambiguous->children($ambiguousRoot),
            static fn (int $i): bool => $ambiguous->name($i) === 'cover',
        ));
        assert_eq(2, count($directCovers));
        foreach ($directCovers as $coverIndex) {
            assert_true(
                !isset(($ambiguous->attrs($coverIndex) ?? [])['align']),
                'multiple direct covers are ambiguous, so none bleeds',
            );
        }

        $nested = $project->readText('theme/parts/page-home--nested.html');
        assert_true(!isset(section_layout_first_attrs($nested, 'cover')['align']), 'nested cover is not a section-root visual');
        assert_contains($cover, $nested, 'nested cover stays byte-for-byte authored');
        assert_true(
            !str_contains($project->readText('theme/parts/page-home--text.html'), '"align":"full"'),
            'text-only section has no full-bleed opt-in',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout promotes only direct CSS background bands without max-width to align:full', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            '.statement{background:var(--primary);padding:4rem 0}'
                . '.accent{background-color:#123456}'
                . '.bounded{background:#fff;max-width:40rem}'
                . '.nested-band{background:#000}',
        );
        $project->writeText(
            'theme/parts/page-home--band.html',
            section_layout_group(
                ['tagName' => 'section'],
                section_layout_group(['className' => 'statement'], 'Statement')
                    . section_layout_group(['className' => 'accent'], 'Accent')
                    . section_layout_group(['className' => 'bounded'], 'Bounded')
                    . section_layout_group(
                        ['className' => 'wrapper'],
                        section_layout_group(['className' => 'nested-band'], 'Nested'),
                    ),
            ),
        );

        $step = new SectionLayoutStep();
        $step->run($project);
        $once = $project->readText('theme/parts/page-home--band.html');
        $doc = BlockMarkup::parse($once);
        $attrsByClass = [];
        foreach ($doc->indices() as $index) {
            $attrs = $doc->attrs($index) ?? [];
            $className = $attrs['className'] ?? '';
            if (!is_string($className)) {
                continue;
            }
            foreach (preg_split('/[\x20\t\r\n\f]+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                $attrsByClass[$class] = $attrs;
            }
        }

        assert_eq('full', $attrsByClass['statement']['align'] ?? null, 'background shorthand band bleeds');
        assert_eq('full', $attrsByClass['accent']['align'] ?? null, 'background-color band bleeds');
        assert_true(!isset($attrsByClass['bounded']['align']), 'a max-width keeps the band constrained');
        assert_true(!isset($attrsByClass['nested-band']['align']), 'nested bands stay outside the section-root boundary');

        $step->run($project);
        assert_eq($once, $project->readText('theme/parts/page-home--band.html'), 'promotion reaches a fixed point');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout removes only root inline padding and reaches a fixed point', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [[
            'slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard',
        ]],
    ]]);
    try {
        $nested = section_layout_group(
            ['style' => ['spacing' => ['padding' => ['left' => '1rem', 'right' => '2rem']]]],
            'Nested',
            ' style="padding-inline:1rem 2rem;padding-left:1rem;padding-right:2rem"',
        );
        $project->writeText(
            'theme/parts/page-home--story.html',
            section_layout_group(
                ['style' => ['spacing' => ['padding' => [
                    'top' => '3rem', 'right' => '4rem', 'bottom' => '5rem', 'left' => '6rem',
                ]]]],
                $nested,
                ' style="color:red;padding-inline:6rem 4rem;padding-left:6rem;padding-right:4rem;padding-top:3rem;padding-bottom:5rem"',
            ),
        );

        $step = new SectionLayoutStep();
        $step->run($project);
        $once = $project->readText('theme/parts/page-home--story.html');
        $attrs = section_layout_root_attrs($once);
        assert_eq('3rem', $attrs['style']['spacing']['padding']['top'] ?? null);
        assert_eq('5rem', $attrs['style']['spacing']['padding']['bottom'] ?? null);
        assert_true(!isset($attrs['style']['spacing']['padding']['left']));
        assert_true(!isset($attrs['style']['spacing']['padding']['right']));
        $doc = BlockMarkup::parse($once);
        $rootHtml = $doc->ownHtml((int) $doc->topLevel());
        foreach (['padding-inline', 'padding-left', 'padding-right'] as $property) {
            assert_true(!str_contains($rootHtml, $property), "root {$property} removed");
        }
        assert_contains('color:red', $rootHtml);
        assert_contains('padding-top:3rem', $rootHtml);
        assert_contains('padding-bottom:5rem', $rootHtml);
        assert_contains($nested, $once, 'inner-element padding stays byte-for-byte authored');

        $step->run($project);
        assert_eq($once, $project->readText('theme/parts/page-home--story.html'));
        assert_true(!$project->exists('warnings.json'), 'successful deterministic normalization is not a warning');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout leaves section rhythm block-axis bytes unchanged', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [[
            'slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard',
        ]],
    ]]);
    try {
        $project->writeText(
            'theme/parts/page-home--story.html',
            section_layout_group([], 'Story', ' style="padding:8rem 2rem"'),
        );
        (new SectionRhythmStep())->run($project);
        $rhythmOnly = $project->readText('theme/parts/page-home--story.html');
        $blockAxis = section_layout_block_axis($rhythmOnly);

        (new SectionLayoutStep())->run($project);
        $both = $project->readText('theme/parts/page-home--story.html');
        assert_eq($blockAxis, section_layout_block_axis($both), 'layout step cannot edit top/bottom attrs or CSS');

        (new SectionRhythmStep())->run($project);
        assert_eq($both, $project->readText('theme/parts/page-home--story.html'), 'rhythm stays byte-stable after layout');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout isolates malformed pages with one durable fixed-point warning', function () {
    [$project, $tmp] = section_layout_project([
        ['slug' => 'home', 'sections' => [[
            'slug' => 'bad', 'background' => 'base', 'vertical_density' => 'standard',
        ]]],
        ['slug' => 'about', 'sections' => [[
            'slug' => 'healthy', 'background' => 'base', 'vertical_density' => 'standard',
        ]]],
    ]);
    try {
        $bad = '<!-- wp:paragraph --><p>Authored fallback</p><!-- /wp:paragraph -->';
        $project->writeText('theme/parts/page-home--bad.html', $bad);
        $project->writeText('theme/parts/page-about--healthy.html', section_layout_group([], 'Healthy'));

        $step = new SectionLayoutStep();
        $step->run($project);

        assert_eq($bad, $project->readText('theme/parts/page-home--bad.html'));
        assert_eq(
            ['type' => 'constrained'],
            section_layout_root_attrs($project->readText('theme/parts/page-about--healthy.html'))['layout'] ?? null,
            'healthy sibling page still normalizes',
        );
        $warnings = $project->readJson('warnings.json');
        assert_eq(['section-layout'], array_keys($warnings));
        assert_eq(1, count($warnings['section-layout']));
        $warning = $warnings['section-layout'][0];
        assert_contains('theme/parts/page-home--bad.html', $warning, 'warning locates the delivered file');
        assert_contains('wp:group', $warning, 'warning states the authored root contract');
        assert_contains('delivered', $warning, 'warning states the disposition');

        $healthy = $project->readText('theme/parts/page-about--healthy.html');
        $step->run($project);
        assert_eq($bad, $project->readText('theme/parts/page-home--bad.html'));
        assert_eq($healthy, $project->readText('theme/parts/page-about--healthy.html'));
        assert_eq($warnings, $project->readJson('warnings.json'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout wide-class parser counts only var(--wide-size) horizontal measures', function () {
    assert_eq(['shell'], SectionLayoutStep::wideClassTokens('.shell{max-width:var(--wide-size)}'));
    assert_eq([], SectionLayoutStep::wideClassTokens('.x{max-width:var(--content-size)}'));
    assert_eq([], SectionLayoutStep::wideClassTokens('.shared-shell{max-width:72rem}'));
    assert_eq([], SectionLayoutStep::wideClassTokens('.shell{max-width'));
    assert_eq(
        ['wrap'],
        SectionLayoutStep::wideClassTokens(':root{--wide-size:1280px}.wrap{width:min(100%,var(--wide-size))}'),
        'width:min(...) referencing the wide token counts',
    );
});

test('section layout promotes a root whose wrapper bears a wide-size class to align:wide', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', ':root{--wide-size:1280px}.wrap{max-width:var(--wide-size)}');
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group wrap">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );
        (new SectionLayoutStep())->run($project);
        $attrs = section_layout_root_attrs($project->readText('theme/parts/page-home--band.html'));
        assert_eq('wide', $attrs['align'] ?? null, 'measure-bearing root reaches align:wide');
        assert_eq(['type' => 'constrained'], $attrs['layout'] ?? null, 'root stays constrained');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout aligns the inner wrapper that bears the wide measure and leaves the root constrained', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', ':root{--wide-size:1280px}.shell{max-width:var(--wide-size)}');
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"className":"shell"} --><div class="wp-block-group shell">'
                . '<!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );
        (new SectionLayoutStep())->run($project);
        $markup = $project->readText('theme/parts/page-home--band.html');
        assert_true(!isset(section_layout_root_attrs($markup)['align']), 'root without the measure stays constrained');
        $doc = BlockMarkup::parse($markup);
        $root = (int) $doc->topLevel();
        $inner = array_values(array_filter(
            $doc->children($root),
            static fn (int $i): bool => $doc->name($i) === 'group',
        ));
        assert_eq(1, count($inner));
        assert_eq('wide', ($doc->attrs($inner[0]) ?? [])['align'] ?? null, 'inner .shell reaches align:wide');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout leaves sections constrained when no class bears the wide measure', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'read', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', ':root{--content-size:800px;--wide-size:1280px}.reading{max-width:var(--content-size)}');
        $project->writeText(
            'theme/parts/page-home--read.html',
            '<!-- wp:group --><div class="wp-block-group reading">'
                . '<!-- wp:paragraph --><p>Read</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );
        (new SectionLayoutStep())->run($project);
        $attrs = section_layout_root_attrs($project->readText('theme/parts/page-home--read.html'));
        assert_true(!isset($attrs['align']), 'content-tier class does not opt into wide');
        assert_eq(['type' => 'constrained'], $attrs['layout'] ?? null);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout reaches a byte-stable fixed point after promoting a wide wrapper', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', ':root{--wide-size:1280px}.wrap{max-width:var(--wide-size)}');
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group wrap">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );
        $step = new SectionLayoutStep();
        $step->run($project);
        $once = $project->readText('theme/parts/page-home--band.html');
        assert_contains('"align":"wide"', $once);
        $step->run($project);
        assert_eq($once, $project->readText('theme/parts/page-home--band.html'), 'second pass is byte-stable');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout isolates a malformed page while still promoting healthy wide siblings', function () {
    [$project, $tmp] = section_layout_project([
        ['slug' => 'home', 'sections' => [['slug' => 'bad', 'background' => 'base', 'vertical_density' => 'standard']]],
        ['slug' => 'about', 'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']]],
    ]);
    try {
        $project->writeText('design/site.css', ':root{--wide-size:1280px}.wrap{max-width:var(--wide-size)}');
        $bad = '<!-- wp:paragraph --><p>Authored fallback</p><!-- /wp:paragraph -->';
        $project->writeText('theme/parts/page-home--bad.html', $bad);
        $project->writeText(
            'theme/parts/page-about--band.html',
            '<!-- wp:group --><div class="wp-block-group wrap">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );
        (new SectionLayoutStep())->run($project);
        assert_eq($bad, $project->readText('theme/parts/page-home--bad.html'), 'malformed page keeps authored bytes');
        assert_eq(
            'wide',
            section_layout_root_attrs($project->readText('theme/parts/page-about--band.html'))['align'] ?? null,
            'healthy sibling still reaches align:wide',
        );
        $warnings = $project->readJson('warnings.json');
        assert_eq(1, count($warnings['section-layout']), 'exactly one durable degradation');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
