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

/** @param array<mixed> $attrs */
function section_layout_section(array $attrs, string $inner, string $wrapperClasses = ''): string
{
    return BlockMarkup::serializeComment('group', $attrs, false)
        . '<section class="wp-block-group' . $wrapperClasses . '">' . $inner . '</section><!-- /wp:group -->';
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

/** @return array<mixed> */
function section_layout_class_attrs(string $markup, string $token): array
{
    $doc = BlockMarkup::parse($markup);
    foreach ($doc->indices() as $i) {
        $classes = preg_split('/\\s+/', (string) (($doc->attrs($i) ?? [])['className'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array($token, $classes ?: [], true)) {
            return $doc->attrs($i) ?? [];
        }
    }
    throw new RuntimeException("fixture has no block classed {$token}");
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

test('section layout constrains CSS-owned roots and exempts only their direct out-of-flow children', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'background' => 'image', 'vertical_density' => 'standard'],
            ['slug' => 'story', 'background' => 'base', 'vertical_density' => 'compact'],
        ],
    ]]);
    try {
        $marker = 'blocks-engine-css-owned-layout';
        $project->writeText(
            'design/site.css',
            '.hero-media{position:absolute;inset:0;max-width:44rem}'
                . '.scrim{background:black}.scrim{position:fixed;inset:0}'
                . '.in-flow{position:relative}'
                . '.nested-layer{position:absolute;inset:0}'
                . '.story-layer{position:absolute;inset:0}',
        );
        $heroInner = section_layout_group(['className' => 'hero-media'], 'Hero image')
            . section_layout_group(['className' => 'scrim'], 'Scrim')
            . section_layout_group(['className' => 'in-flow'], 'Hero copy')
            . section_layout_group(
                ['className' => 'wrapper'],
                section_layout_group(['className' => 'nested-layer'], 'Nested image'),
            );
        $storyInner = '<!-- wp:paragraph --><p>Story copy</p><!-- /wp:paragraph -->';
        $marked = section_layout_section(
            ['tagName' => 'section', 'anchor' => 'hero', 'className' => $marker],
            $heroInner,
            " {$marker}",
        );
        $markedAttrs = section_layout_root_attrs($marked);
        assert_eq($marker, $markedAttrs['className'] ?? null, 'fixture carries the exact CSS-owned marker');
        assert_true(!isset($markedAttrs['layout']), 'fixture starts without a layout attribute');

        $project->writeText('theme/parts/page-home--hero.html', $marked);
        $project->writeText(
            'theme/parts/page-home--story.html',
            section_layout_section(
                ['tagName' => 'section', 'anchor' => 'story', 'className' => 'story'],
                section_layout_group(['className' => 'story-layer'], $storyInner),
                ' story',
            ),
        );

        (new SectionLayoutStep())->run($project);

        $hero = $project->readText('theme/parts/page-home--hero.html');
        $heroAttrs = section_layout_root_attrs($hero);
        assert_eq(['type' => 'constrained'], $heroAttrs['layout'] ?? null, 'CSS-owned root keeps the foundation inset');
        assert_eq(
            "{$marker} is-layout-constrained",
            $heroAttrs['className'] ?? null,
            'CSS-owned root keeps its marker and constrained context',
        );
        assert_contains('Hero image', $hero, 'absolute child content survives');
        assert_contains('Scrim', $hero, 'fixed child content survives');
        assert_contains('Hero copy', $hero, 'in-flow child content survives');
        assert_contains('Nested image', $hero, 'nested child content survives');

        $heroDoc = BlockMarkup::parse($hero);
        $attrsByClass = [];
        foreach ($heroDoc->indices() as $index) {
            $attrs = $heroDoc->attrs($index) ?? [];
            $className = $attrs['className'] ?? '';
            if (is_string($className)) {
                $attrsByClass[$className] = $attrs;
            }
        }
        assert_eq('full', $attrsByClass['hero-media']['align'] ?? null, 'direct absolute child escapes the content cap');
        assert_eq('full', $attrsByClass['scrim']['align'] ?? null, 'direct fixed child escapes the content cap');
        assert_true(!isset($attrsByClass['in-flow']['align']), 'relative child stays constrained');
        assert_true(!isset($attrsByClass['nested-layer']['align']), 'nested out-of-flow child stays outside this boundary');

        $story = $project->readText('theme/parts/page-home--story.html');
        assert_eq(
            [
                'tagName' => 'section',
                'anchor' => 'story',
                'className' => 'story is-layout-constrained',
                'layout' => ['type' => 'constrained'],
            ],
            section_layout_root_attrs($story),
            'unmarked section keeps the exact foundation-inset attributes',
        );
        assert_contains($storyInner, $story, 'unmarked section child bytes stay byte-identical');
        $storyDoc = BlockMarkup::parse($story);
        $storyRoot = (int) $storyDoc->topLevel();
        $storyChildren = $storyDoc->children($storyRoot);
        assert_eq(1, count($storyChildren));
        assert_true(!isset(($storyDoc->attrs($storyChildren[0]) ?? [])['align']), 'unmarked child is not promoted');

        $step = new SectionLayoutStep();
        $step->run($project);
        assert_eq($hero, $project->readText('theme/parts/page-home--hero.html'), 'second pass is byte-stable');
        assert_eq($story, $project->readText('theme/parts/page-home--story.html'), 'unmarked sibling is byte-stable');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

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
        assert_eq(
            ['pages.json', 'theme/parts/*', 'theme/theme.json', 'design/site.css', 'design/home.html'],
            $step->declaration()->reads,
        );
        assert_eq(['theme/parts/*', 'theme/theme.json', 'warnings.json'], $step->declaration()->writes);
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
                . '.bounded{background:#fff;max-width:40rem;margin:0 auto}'
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
        assert_true(!isset($attrsByClass['bounded']['align']), 'an author-centred max-width stays constrained');
        assert_true(!isset($attrsByClass['nested-band']['align']), 'nested bands stay outside the section-root boundary');

        $step->run($project);
        assert_eq($once, $project->readText('theme/parts/page-home--band.html'), 'promotion reaches a fixed point');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout full-bleed parser ignores pseudo-element backgrounds', function () {
    $css = '.before::before{background:red}'
        . '.after::after{background-color:red}'
        . '.first-line::first-line{background:red}'
        . '.first-letter::first-letter{background:red}'
        . '.marker::marker{background:red}'
        . '.selection::selection{background:red}'
        . '.placeholder::placeholder{background:red}'
        . '.legacy-before:before{background:red}'
        . '.legacy-after:after{background:red}'
        . '.hover:hover{background:red}'
        . '.band{background:var(--primary)}';

    assert_eq(
        ['hover', 'band'],
        SectionLayoutStep::fullBleedClassTokens($css),
        'only backgrounds painted on the selected element qualify',
    );
});

test('section layout full-bleed parser excludes out-of-flow classes across split rules', function () {
    $css = '.absolute{position:absolute}'
        . '.absolute{background:red}'
        . '.fixed{position:fixed}'
        . '.fixed{background-color:red}'
        . '.relative{position:relative}'
        . '.relative{background:red}'
        . '.sticky{position:sticky}'
        . '.sticky{background:red}';

    assert_eq(
        ['relative', 'sticky'],
        SectionLayoutStep::fullBleedClassTokens($css),
        'out-of-flow positioning in any rule disqualifies the class',
    );
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

/** @return array{target:array<mixed>,root:array<mixed>} fixture alignment attrs */
function section_layout_author_width_attrs(string $css): array
{
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', $css);
        $project->writeText(
            'theme/parts/page-home--story.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"anchor":"measure","className":"measure"} -->'
                . '<div id="measure" class="wp-block-group measure">'
                . '<!-- wp:paragraph --><p>Authored measure</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $markup = $project->readText('theme/parts/page-home--story.html');
        $doc = BlockMarkup::parse($markup);
        $targets = array_values(array_filter(
            $doc->indices(),
            static fn (int $index): bool => (($doc->attrs($index) ?? [])['anchor'] ?? null) === 'measure',
        ));
        assert_eq(1, count($targets), 'fixture contains exactly one non-vacuous authored-width target');
        return [
            'target' => $doc->attrs($targets[0]) ?? [],
            'root' => section_layout_root_attrs($markup),
        ];
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

test('A1 section layout preserves a left-aligned author-owned max-width', function () {
    $attrs = section_layout_author_width_attrs('.measure{max-width:min(100%,44rem)}');
    assert_true(!isset($attrs['target']['align']), 'the authored measure keeps the constrained inset');
    assert_eq(
        ['type' => 'constrained'],
        $attrs['root']['layout'] ?? null,
        'start alignment rides the child, never the container',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS,
        $attrs['target']['className'] ?? '',
        'the authored measure carries its own start marker',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_START_CLASS,
        $attrs['root']['className'] ?? '',
        'page styles can preserve this root authored inset',
    );
});

test('A2 section layout preserves an author-centred max-width', function () {
    $attrs = section_layout_author_width_attrs('.measure{max-width:min(100%,44rem);margin:0 auto}');
    assert_true(!isset($attrs['target']['align']), 'both authored auto margins keep the measure in constrained centring');
    assert_eq(['type' => 'constrained'], $attrs['root']['layout'] ?? null);
});

test('A3 section layout preserves a right-aligned author-owned max-width', function () {
    $attrs = section_layout_author_width_attrs('.measure{max-width:min(100%,44rem);margin-left:auto}');
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
        $attrs['target']['className'] ?? '',
        'the per-child pin leaves the one-sided authored auto margin in control',
    );
    assert_true(!isset($attrs['target']['align']), 'end alignment never becomes a full-bleed promotion');
    assert_eq(['type' => 'constrained'], $attrs['root']['layout'] ?? null);
});

test('A4 section layout honours the desktop media query that owns the render viewport', function () {
    // tbilisi4's real shape: the base rule governs below 1000px only, and the
    // desktop rule that actually applies at the render viewport ends-aligns the
    // column. Reading the base rule as the render rule stamps align:wide.
    $attrs = section_layout_author_width_attrs(
        ':root{--wide-size:1280px}'
            . '.measure{max-width:var(--wide-size);margin:0 auto;width:100%}'
            . '@media (min-width:1000px){.measure{max-width:38rem;margin:0 0 0 auto;}}',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
        $attrs['target']['className'] ?? '',
        'the desktop max-width plus one-sided auto margin escapes the constrained centring',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'the media-blind wide promotion never re-caps a classified measure',
    );
});

test('A5 section layout ignores a mobile-only media query at the render viewport', function () {
    // calm-lantern's real .badge shape, minus its position:absolute so the
    // out-of-flow skip cannot make this vacuous. The base rule alone proves
    // start alignment; the max-width:899px rule never applies at 1366.
    $attrs = section_layout_author_width_attrs(
        '.measure{max-width:12.5rem;padding:0.8rem 1.05rem;border-radius:20px}'
            . '@media (max-width:899px){.measure{max-width:10.5rem;padding:0.65rem 0.85rem;}}',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'a mobile-only declaration is ignored, not treated as unprovable',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_START_CLASS,
        $attrs['root']['className'] ?? '',
        'the base rule still proves start alignment',
    );
});

test('A6 section layout never cascades a mobile-only margin into the render viewport', function () {
    $attrs = section_layout_author_width_attrs(
        '.measure{max-width:12.5rem}'
            . '@media (max-width:899px){.measure{max-width:10.5rem;margin-left:auto;}}',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'the mobile end alignment must not reach the desktop classification',
    );
});

test('A7 section layout resolves a rem media prelude against the render viewport', function () {
    // swift-grove and sunny-ember author their desktop breakpoint in rem.
    $attrs = section_layout_author_width_attrs(
        '.measure{max-width:100%}'
            . '@media (min-width:56rem){.measure{max-width:38rem;margin-left:auto;}}',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
        $attrs['target']['className'] ?? '',
        '56rem is 896px, so the rule applies at 1366',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'a classified measure is never handed a block alignment',
    );
});

test('A8 section layout leaves a non-width at-rule unprovable', function () {
    $attrs = section_layout_author_width_attrs(
        '.measure{max-width:44rem}'
            . '@media (prefers-reduced-motion: reduce){.measure{margin-left:auto;}}',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'a preference query cannot be decided by a width comparison',
    );
    assert_true(
        !str_contains($attrs['root']['className'] ?? '', SectionLayoutStep::AUTHOR_WIDTH_START_CLASS),
        'one unprovable match still poisons the whole classification',
    );
});

test('AW1 section layout marks an escaping authored width per child, never align:full', function () {
    // align:full hands the block to WordPress's root-padding-aware alignment
    // rules, which outrank the authored max-width and margin at (0,1,0). The
    // remedy has to stay on the child and leave those authored values in play.
    $attrs = section_layout_author_width_attrs(
        ':root{--wide-size:1280px}'
            . '.measure{max-width:var(--wide-size);margin:0 auto;width:100%}'
            . '@media (min-width:1000px){.measure{max-width:38rem;margin:0 0 0 auto;}}',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
        $attrs['target']['className'] ?? '',
        'the escaping child carries the per-child end-alignment marker',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'the escaping child is never promoted to a full-bleed alignment',
    );
    assert_eq(
        ['type' => 'constrained'],
        $attrs['root']['layout'] ?? null,
        'the root keeps a plain constrained layout',
    );
});

test('AW2 section layout marks a start-aligned authored width per child, never on the container', function () {
    // layout.justifyContent is a container property: WordPress fans it out to
    // every child of the section with a !important margin-left reset, moving
    // siblings that own no authored width at all.
    $attrs = section_layout_author_width_attrs('.measure{max-width:min(100%,44rem)}');
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS,
        $attrs['target']['className'] ?? '',
        'the start-aligned child carries the per-child start marker',
    );
    assert_eq(
        ['type' => 'constrained'],
        $attrs['root']['layout'] ?? null,
        'the section root never carries justifyContent',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'a start-aligned child keeps the constrained inset',
    );
});

test('AW3 section layout keeps per-child width markers out of the footer control', function () {
    // tbilisi4's footer reproduces its design to the pixel while the body
    // sections carrying the identical classes do not. A per-child marker that
    // reached a part would move the one thing that already renders correctly.
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            ':root{--wide-size:1280px}'
                . '.hero-inner{max-width:var(--wide-size);margin:0 auto;width:100%}'
                . '@media (min-width:1000px){.hero-inner{max-width:38rem;margin:0 0 0 auto;}}',
        );
        $footer = '<!-- wp:group {"tagName":"footer","className":"hero-panel"} -->'
            . '<footer class="wp-block-group hero-panel">'
            . '<!-- wp:group {"align":"wide","className":"hero-inner"} -->'
            . '<div class="wp-block-group alignwide hero-inner">'
            . '<!-- wp:paragraph --><p>Tbilisi Tavern</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group --></footer><!-- /wp:group -->';
        $project->writeText('theme/parts/footer.html', $footer);
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
                . '<!-- wp:group {"className":"hero-inner"} --><div class="wp-block-group hero-inner">'
                . '<!-- wp:paragraph --><p>Chrome</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></header><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"className":"hero-inner"} --><div class="wp-block-group hero-inner">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        assert_eq($footer, $project->readText('theme/parts/footer.html'), 'the footer part is never rewritten');
        $header = $project->readText('theme/parts/header.html');
        assert_true(
            !str_contains($header, SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS),
            'a wide-carrier part never gains a per-child width marker',
        );
        assert_eq(
            'wide',
            section_layout_class_attrs($header, 'hero-inner')['align'] ?? null,
            'the part still receives the wide-carrier alignment it always had',
        );
        assert_contains(
            SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
            section_layout_class_attrs(
                $project->readText('theme/parts/page-home--band.html'),
                'hero-inner',
            )['className'] ?? '',
            'the page section carrying the same class does get the marker',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('AW8 section layout keeps a start-classified wide carrier out of the wide promotion', function () {
    // The start half of the wide-promotion exclusion. Every other start case
    // uses CSS with no var(--wide-size) rule at all, so $wideSelectors is empty
    // and the exclusion never runs — the branch was reachable only by a design
    // that measures wide-size AND owns a bound width with a non-auto margin.
    // Measured in WordPress: core's `.wp-container-x > .alignwide` is (0,2,0)
    // and overrides an author max-width at (0,1,0), so promoting this child
    // would discard the very declaration that classified it.
    $attrs = section_layout_author_width_attrs(
        ':root{--wide-size:1280px}'
            . '.measure{max-width:var(--wide-size)}'
            . '.measure{width:min(100%,44rem);margin-left:0}',
    );
    assert_contains(
        SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS,
        $attrs['target']['className'] ?? '',
        'the wide-carrying child still classifies as start',
    );
    assert_true(
        !isset($attrs['target']['align']),
        'a start-classified wide carrier is never promoted to align:wide',
    );
});

test('AW9 section layout leaves an authored full-bleed child unpinned', function () {
    // A child the design marks full-bleed is about to receive align:full, whose
    // root-padding-aware rules pull it past the content column on purpose. A pin
    // at (0,3,0) with !important would outrank them and shrink it back to the
    // authored measure — the exact defect this treatment removes.
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            '.bleed{background:var(--primary)}'
                . '.bleed{max-width:44rem;margin-left:auto}',
        );
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"className":"bleed"} --><div class="wp-block-group bleed">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $markup = $project->readText('theme/parts/page-home--band.html');
        $child = section_layout_class_attrs($markup, 'bleed');
        assert_eq('full', $child['align'] ?? null, 'the authored full-bleed intent still wins');
        assert_true(
            !str_contains($markup, SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS)
                && !str_contains($markup, SectionLayoutStep::AUTHOR_WIDTH_CHILD_START_CLASS),
            'a full-bleed child is never also pinned back to the content column',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('AW7 section layout clears a per-child marker the design no longer earns', function () {
    // A resumed build re-runs this step over markup an earlier revision
    // stamped. An add-only marker would survive its own classification and
    // PageStylesStep would pin a child with an !important margin that nothing
    // in the current design authored.
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $part = 'theme/parts/page-home--band.html';
        $project->writeText(
            $part,
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"className":"measure"} --><div class="wp-block-group measure">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );

        $project->writeText('design/site.css', '.measure{max-width:44rem;margin-left:auto}');
        (new SectionLayoutStep())->run($project);
        assert_contains(
            SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
            section_layout_class_attrs($project->readText($part), 'measure')['className'] ?? '',
            'the classifying design stamps the marker',
        );

        // Same markup, a design that no longer classifies the child at all.
        $project->writeText('design/site.css', '.measure{color:red}');
        (new SectionLayoutStep())->run($project);
        assert_true(
            !str_contains(
                $project->readText($part),
                SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
            ),
            'the stale marker is cleared rather than carried forward',
        );
        assert_contains(
            'measure',
            section_layout_class_attrs($project->readText($part), 'measure')['className'] ?? '',
            'clearing the marker leaves the authored class list intact',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
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
        $project->writeText(
            'design/site.css',
            ':root{--wide-size:1280px}.shell{max-width:var(--wide-size);margin:0 auto}',
        );
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

test('W6 section layout detects ID and descendant wide carriers with ancestry', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'background' => 'base', 'vertical_density' => 'standard'],
            ['slug' => 'standalone-nav', 'background' => 'base', 'vertical_density' => 'standard'],
        ],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            ':root{--wide-size:1280px}#hero{max-width:var(--wide-size)}'
                . 'header nav{max-width:var(--wide-size)}',
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"tagName":"section","anchor":"hero"} -->'
                . '<section id="hero" class="wp-block-group">'
                . '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->'
                . '</section><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-home--standalone-nav.html',
            '<!-- wp:group {"tagName":"section","anchor":"standalone-nav"} -->'
                . '<section id="standalone-nav" class="wp-block-group">'
                . '<!-- wp:group {"tagName":"nav"} -->'
                . '<nav class="wp-block-group"><!-- wp:paragraph --><p>Not header navigation</p><!-- /wp:paragraph -->'
                . '</nav><!-- /wp:group --></section><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group">'
                . '<!-- wp:paragraph --><p>Header navigation</p><!-- /wp:paragraph -->'
                . '</nav><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $hero = section_layout_root_attrs($project->readText('theme/parts/page-home--hero.html'));
        assert_eq('wide', $hero['align'] ?? null, '#hero ID selector reaches its section block');

        $header = BlockMarkup::parse($project->readText('theme/parts/header.html'));
        $headerNavs = array_values(array_filter(
            $header->indices(),
            static fn (int $index): bool => (($header->attrs($index) ?? [])['tagName'] ?? null) === 'nav',
        ));
        assert_eq(1, count($headerNavs), 'fixture contains one header nav block');
        assert_eq(
            'wide',
            ($header->attrs($headerNavs[0]) ?? [])['align'] ?? null,
            'header nav descendant selector reaches the nav block',
        );

        $standalone = BlockMarkup::parse($project->readText('theme/parts/page-home--standalone-nav.html'));
        $standaloneNavs = array_values(array_filter(
            $standalone->indices(),
            static fn (int $index): bool => (($standalone->attrs($index) ?? [])['tagName'] ?? null) === 'nav',
        ));
        assert_eq(1, count($standaloneNavs), 'fixture contains one standalone nav block');
        assert_true(
            !isset(($standalone->attrs($standaloneNavs[0]) ?? [])['align']),
            'header ancestry is required; a standalone nav is not promoted',
        );

        $once = [
            'hero' => $project->readText('theme/parts/page-home--hero.html'),
            'standalone' => $project->readText('theme/parts/page-home--standalone-nav.html'),
            'header' => $project->readText('theme/parts/header.html'),
        ];
        (new SectionLayoutStep())->run($project);
        assert_eq($once['hero'], $project->readText('theme/parts/page-home--hero.html'));
        assert_eq($once['standalone'], $project->readText('theme/parts/page-home--standalone-nav.html'));
        assert_eq($once['header'], $project->readText('theme/parts/header.html'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout isolates malformed header width repair and still promotes healthy pages', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'hero', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            '#hero{max-width:var(--wide-size)}header nav{max-width:var(--wide-size)}',
        );
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"tagName":"section","anchor":"hero"} -->'
                . '<section id="hero" class="wp-block-group">Hero</section><!-- /wp:group -->',
        );
        $malformedHeader = '<!-- wp:group {"tagName":"header"} --><header>Authored header';
        $project->writeText('theme/parts/header.html', $malformedHeader);

        (new SectionLayoutStep())->run($project);

        assert_eq($malformedHeader, $project->readText('theme/parts/header.html'));
        assert_eq(
            'wide',
            section_layout_root_attrs($project->readText('theme/parts/page-home--hero.html'))['align'] ?? null,
            'healthy page still reaches align:wide',
        );
        $warnings = implode(' ', $project->readJson('warnings.json')['section-layout'] ?? []);
        assert_contains('file theme/parts/header.html, block /', $warnings);
        assert_contains('authored value transformed header blocks', $warnings);
        assert_contains('delivered value pre-transformation bytes', $warnings);
        assert_contains('disposition wide-carrier alignment skipped', $warnings);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout ignores dead content token without final markup and normalizes copied wide width', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', ':root{--content-size:800px;--wide-size:1280px}');
        $project->writeJson('theme/theme.json', [
            'version' => 3,
            'settings' => ['layout' => ['contentSize' => '860px', 'wideSize' => 1320]],
        ]);
        $project->writeText(
            'theme/parts/page-home--story.html',
            '<!-- wp:group {"tagName":"section","anchor":"story"} -->'
                . '<section id="story" class="wp-block-group">Story</section><!-- /wp:group -->',
        );

        $step = new SectionLayoutStep();
        $step->run($project);
        assert_eq(
            ['contentSize' => '860px', 'wideSize' => '1280px'],
            $project->readJson('theme/theme.json')['settings']['layout'] ?? null,
        );
        $once = $project->readText('theme/theme.json');
        $step->run($project);
        assert_eq($once, $project->readText('theme/theme.json'), 'second pass leaves theme.json byte-stable');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('C5 section layout derives copied content width from the final design carrier', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            ':root{--content-size:800px;--wide-size:1280px}'
                . '*,*::before,*::after{box-sizing:border-box}'
                . '.shell{width:100%;max-width:var(--wide-size);margin:0 auto;padding:0 48px}',
        );
        $project->writeText(
            'design/home.html',
            '<main>'
                . '<section id="hero"><div class="shell"><h1>Hero</h1></div></section>'
                . '<section id="story"><div class="shell"><h2>Story</h2></div></section>'
                . '</main>',
        );
        $project->writeJson('theme/theme.json', [
            'version' => 3,
            'settings' => ['layout' => ['contentSize' => '800px', 'wideSize' => '1280px']],
        ]);
        $project->writeText(
            'theme/parts/page-home--story.html',
            '<!-- wp:group {"tagName":"section","anchor":"story"} -->'
                . '<section id="story" class="wp-block-group">Story</section><!-- /wp:group -->',
        );

        $step = new SectionLayoutStep();
        $step->run($project);
        assert_eq(
            ['contentSize' => '1184px', 'wideSize' => '1280px'],
            $project->readJson('theme/theme.json')['settings']['layout'] ?? null,
        );
        $once = $project->readText('theme/theme.json');
        $step->run($project);
        assert_eq($once, $project->readText('theme/theme.json'), 'second pass leaves derived width byte-stable');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('section layout recognizes dynamic core navigation as a header nav carrier', function () {
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', 'header nav{max-width:var(--wide-size)}');
        $project->writeText(
            'theme/parts/page-home--story.html',
            '<!-- wp:group {"tagName":"section","anchor":"story"} -->'
                . '<section id="story" class="wp-block-group">Story</section><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
                . '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /-->'
                . '<!-- /wp:navigation --></header><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $header = BlockMarkup::parse($project->readText('theme/parts/header.html'));
        $navigation = array_values(array_filter(
            $header->indices(),
            static fn (int $index): bool => $header->name($index) === 'navigation',
        ));
        assert_eq(1, count($navigation));
        assert_eq('wide', ($header->attrs($navigation[0]) ?? [])['align'] ?? null);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

/** @return list<string> aligned block anchors in document order */
function section_layout_selector_fixture(string $selector, string $declaration): array
{
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'hero', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText('design/site.css', $selector . '{' . $declaration . ';margin:0 auto}');
        $project->writeText(
            'theme/parts/page-home--hero.html',
            '<!-- wp:group {"tagName":"section","anchor":"hero","className":"hero outer"} -->'
                . '<section id="hero" class="wp-block-group hero outer">'
                . '<!-- wp:group {"anchor":"inner","className":"inner"} -->'
                . '<div id="inner" class="wp-block-group inner">Inner</div><!-- /wp:group -->'
                . '</section><!-- /wp:group -->',
        );
        (new SectionLayoutStep())->run($project);
        $doc = BlockMarkup::parse($project->readText('theme/parts/page-home--hero.html'));
        $anchors = [];
        foreach ($doc->indices() as $index) {
            $attrs = $doc->attrs($index) ?? [];
            if (($attrs['align'] ?? null) === 'wide') {
                $anchors[] = (string) ($attrs['anchor'] ?? '');
            }
        }
        return $anchors;
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

$sectionLayoutSelectorCases = [
    ['ID subject', '#hero', 'max-width:var(--wide-size)', ['hero']],
    ['type subject', 'section', 'max-width:var(--wide-size)', ['hero']],
    ['class subject', '.hero', 'max-width:var(--wide-size)', ['hero']],
    ['compound subject', 'section.hero#hero', 'max-width:var(--wide-size)', ['hero']],
    ['class descendant', '.outer .inner', 'max-width:var(--wide-size)', ['inner']],
    ['ID descendant', '#hero .inner', 'max-width:var(--wide-size)', ['inner']],
    ['missing ancestry', '.missing .inner', 'max-width:var(--wide-size)', []],
    ['selector list branch', '.missing, #hero', 'max-width:var(--wide-size)', ['hero']],
    ['width property', '#hero', 'width:min(100%,var(--wide-size))', ['hero']],
    ['inline-size property', '#hero', 'inline-size:var(--wide-size)', ['hero']],
    ['max-inline-size property', '#hero', 'max-inline-size:var(--wide-size)', ['hero']],
    ['unsupported child combinator', 'section > .inner', 'max-width:var(--wide-size)', []],
];

foreach ($sectionLayoutSelectorCases as [$name, $selector, $declaration, $expected]) {
    test("section layout wide selector: {$name}", function () use ($selector, $declaration, $expected) {
        assert_eq($expected, section_layout_selector_fixture($selector, $declaration));
    });
}

test('A10 section layout leaves the footer part exactly as it found it', function () {
    // The footer is the control case for the media-scoped width fix: it
    // reproduces tbilisi4's design to the pixel while the five body sections
    // carrying the identical classes do not. Only page sections and the header
    // part are rewritten, so a widened classification must not reach it.
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            ':root{--wide-size:1280px}'
                . '.hero-inner{max-width:var(--wide-size);margin:0 auto;width:100%}'
                . '@media (min-width:1000px){.hero-inner{max-width:38rem;margin:0 0 0 auto;}}',
        );
        $footer = '<!-- wp:group {"tagName":"footer","className":"hero-panel"} -->'
            . '<footer class="wp-block-group hero-panel">'
            . '<!-- wp:group {"align":"wide","className":"hero-inner"} -->'
            . '<div class="wp-block-group alignwide hero-inner">'
            . '<!-- wp:paragraph --><p>Tbilisi Tavern</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group --></footer><!-- /wp:group -->';
        $project->writeText('theme/parts/footer.html', $footer);
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:group {"className":"hero-inner"} --><div class="wp-block-group hero-inner">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        assert_eq($footer, $project->readText('theme/parts/footer.html'), 'the footer part is never rewritten');
        $band = section_layout_class_attrs(
            $project->readText('theme/parts/page-home--band.html'),
            'hero-inner',
        );
        assert_contains(
            SectionLayoutStep::AUTHOR_WIDTH_CHILD_ESCAPE_CLASS,
            $band['className'] ?? '',
            'the page section carrying the same class does escape',
        );
        assert_true(
            !isset($band['align']),
            'the escaping page-section child is never handed a block alignment',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('A11 section layout gives a wide-carrier part alignment only, never a constrained layout', function () {
    // rewriteWidePart() is the narrow path: align:wide and nothing else. It
    // must never apply the section-root treatment — layout:constrained plus
    // stripped inline padding — which is what moves a part off the design.
    [$project, $tmp] = section_layout_project([[
        'slug' => 'home',
        'sections' => [['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard']],
    ]]);
    try {
        $project->writeText(
            'design/site.css',
            ':root{--wide-size:1280px}'
                . '.hero-inner{max-width:var(--wide-size);margin:0 auto;width:100%}'
                . '@media (min-width:1000px){.hero-inner{max-width:38rem;margin:0 0 0 auto;}}',
        );
        $project->writeText(
            'theme/parts/header.html',
            '<!-- wp:group {"tagName":"header","style":{"spacing":{"padding":{"left":"2rem","right":"2rem"}}}} -->'
                . '<header class="wp-block-group">'
                . '<!-- wp:group {"className":"hero-inner"} --><div class="wp-block-group hero-inner">'
                . '<!-- wp:paragraph --><p>Chrome</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group --></header><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $markup = $project->readText('theme/parts/header.html');
        $root = section_layout_root_attrs($markup);
        assert_true(!isset($root['layout']), 'a wide-carrier part root never becomes constrained');
        assert_true(
            !str_contains($markup, 'is-layout-constrained'),
            'a wide-carrier part never carries the constrained layout class',
        );
        assert_eq(
            ['left' => '2rem', 'right' => '2rem'],
            $root['style']['spacing']['padding'] ?? null,
            'a wide-carrier part keeps its authored inline padding',
        );
        assert_eq(
            'wide',
            section_layout_class_attrs($markup, 'hero-inner')['align'] ?? null,
            'the wide measure carrier still reaches align:wide',
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
