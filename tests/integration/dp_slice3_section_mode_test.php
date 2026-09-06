<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return list<string> */
function dp3_integration_stitched_section_ids(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    assert_true((bool) $loaded, 'stitched design parses');
    $mains = $dom->getElementsByTagName('main');
    assert_eq(1, $mains->length, 'stitched design has one main');
    $main = $mains->item(0);
    assert_true($main instanceof DOMElement);
    $ids = [];
    foreach ($main->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        assert_eq('section', strtolower($child->tagName), 'main direct children are sections only');
        $ids[] = $child->getAttribute('id');
    }
    return $ids;
}

/** @return array<string,mixed> */
function dp3_integration_site_spec(): array
{
    return [
        'name' => 'Northstar Studio',
        'slug' => 'northstar-studio',
        'title' => 'Northstar Studio',
        'description' => 'Editorial design studio',
        'site_type' => 'studio',
        'topic' => 'design practice',
        'area' => 'design',
        'audience' => 'prospective clients',
        'visual_vibe' => 'crisp editorial',
        'language' => 'en',
        'persona_name' => '',
        'email_domain' => 'northstar.example',
        'invented' => ['name'],
        'sections' => ['Hero', 'Selected work'],
        'pages' => [
            ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome clients', 'children' => []],
            ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain the studio', 'children' => []],
        ],
    ];
}

/** @return array<string,mixed> */
function dp3_integration_direction(): array
{
    return ['direction' => [
        'title' => 'Measured Folio',
        'description' => 'Crisp editorial system with documentary studio imagery.',
        'palette' => [
            'base' => '#f7f4ed',
            'contrast' => '#192126',
            'primary' => '#315f68',
            'secondary' => '#b8a68d',
            'accent' => '#c85c3b',
        ],
        'type' => ['heading' => 'Georgia 700', 'body' => 'Arial 400/700'],
        'image_grade' => 'neutral documentary light',
        'motion' => 'calm',
        'motion_note' => 'Keep transitions restrained.',
        'signature_device' => 'numbered folios',
        'hero_composition' => 'editorial split',
    ]];
}

/** @return array<string,mixed> */
function dp3_integration_theme(): array
{
    return [
        'version' => 3,
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#f7f4ed', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#192126', 'name' => 'Contrast'],
                ['slug' => 'primary', 'color' => '#315f68', 'name' => 'Primary'],
                ['slug' => 'secondary', 'color' => '#b8a68d', 'name' => 'Secondary'],
                ['slug' => 'accent', 'color' => '#c85c3b', 'name' => 'Accent'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => 'Georgia, serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => 'Arial, sans-serif', 'name' => 'Body'],
            ]],
        ],
    ];
}

function dp3_integration_preview(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>:root{--content-size:800px;--wide-size:1280px;--ink:#192126;--paper:#f7f4ed}'
        . 'body{margin:0;color:var(--ink);background:var(--paper);font-family:Arial,sans-serif}'
        . 'header{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;justify-content:space-between}'
        . 'h1,h2{font-family:Georgia,serif}</style></head><body>'
        . '<header><a class="brand" href="/">Northstar Studio</a>'
        . '<nav aria-label="Primary"><a href="/about/">About</a></nav></header>'
        . '<main><section id="hero"><h1 class="has-display-font-size">NORTHSTAR-HERO</h1>'
        . '<img alt="AI_IMAGE: Designers reviewing print layouts on a long studio table | homepage hero beside editorial headline | photorealistic | landscape">'
        . '</section></main></body></html>';
}

/** @return array<string,mixed> */
function dp3_integration_section(string $slug, string $title, string $archetype): array
{
    return [
        'slug' => $slug,
        'title' => $title,
        'type' => 'content',
        'purpose' => "Deliver {$title}",
        'content_notes' => "Specific copy for {$title}.",
        'layout_archetype' => $archetype,
        'background' => 'base',
        'vertical_density' => 'standard',
        'text_placement' => 'left-column',
        'handoff' => "Connect {$title} to adjacent sections.",
    ];
}

test('TG5 section mode stitches whole-page HTML then real transform and assemble preserve outline order', function () {
    $tmp = sys_get_temp_dir() . '/builder_dp_slice3_integration_' . uniqid();
    $previousMode = getenv('SITE_BUILD_GEN_UNIT');
    $previousHtmlFirst = getenv('SITE_BUILD_HTML_FIRST');
    putenv('SITE_BUILD_GEN_UNIT=section');
    putenv('SITE_BUILD_HTML_FIRST=1');
    try {
        assert_eq(
            'a9b88b42c740046ef24e75c90f813f9a8da0e4a450a9ad0b91dc9601a9879a9c',
            hash_file('sha256', repo_path('src/Steps/TransformSiteStep.php')),
            'transform-site source stays frozen after one accessor for the footer archetype',
        );
        assert_eq(
            'da340cb5566303c52c3f26667a361e221ca38f1aef7f0aa80deaaaed68de35ef',
            hash_file('sha256', repo_path('src/Steps/AssemblePagesStep.php')),
            'assemble-pages source stays frozen after site-logo union and content-row preserve',
        );
        $llm = new FakeLlm();
        $llm->queueText('Northstar Studio presents a measured editorial portfolio.');
        $llm->queueText(dp3_integration_preview());
        $llm->queueText(
            '<main><section id="studio-story"><h2>HOME-STORY</h2></section></main>'
            . '<footer><p>NORTHSTAR-FOOTER</p></footer>',
        );
        $llm->queueText('<section id="about-intro"><h1>ABOUT-FIRST-MARKER</h1></section>');
        $llm->queueText('<section id="about-process"><h2>ABOUT-SECOND-MARKER</h2></section>');

        $llm->queueJson(dp3_integration_site_spec());
        $llm->queueJson(['seeds' => ['Measured Folio', 'Studio Ledger', 'Northstar Index', 'Working Proof']]);
        $llm->queueJson(['winner' => 0, 'why' => 'fixture judge']);
        $llm->queueJson(dp3_integration_direction());
        $llm->queueJson(dp3_integration_theme());
        $llm->queueJson(['sections' => [
            dp3_integration_section('about-intro', 'About intro', 'centered-stack'),
            dp3_integration_section('about-process', 'About process', 'asymmetric-split'),
        ]]);

        $builder = new SiteBuilder(
            llm: $llm,
            promptsDir: Package::promptsDir(),
            outputRoot: $tmp,
            blockFixer: BlockFixers::default(),
        );
        $project = $builder->createProject(
            'Build Northstar Studio',
            'demo',
            true,
            [
                ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome clients'],
                ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain the studio'],
            ],
        );
        $builder->pipeline()->runThrough($project, 'transform-site');

        assert_eq(
            ['about-intro', 'about-process'],
            dp3_integration_stitched_section_ids($project->readText('design/about.html')),
            'section results stitch into one whole-page envelope before transform',
        );
        assert_eq(
            ['about-intro', 'about-process'],
            array_map(
                static fn (array $section): string => (string) $section['slug'],
                array_values(array_filter(
                    $project->readJson('pages.json')['pages'][1]['sections'] ?? [],
                    'is_array',
                )),
            ),
        );
        foreach ([
            'theme/parts/page-about--about-intro.html' => 'ABOUT-FIRST-MARKER',
            'theme/parts/page-about--about-process.html' => 'ABOUT-SECOND-MARKER',
        ] as $path => $marker) {
            assert_true($project->exists($path), "transform emitted {$path}");
            assert_contains($marker, $project->readText($path));
        }

        (new AssemblePagesStep())->run($project);
        $about = $project->readText('plugin/pages/about.html');
        assert_contains('ABOUT-FIRST-MARKER', $about);
        assert_contains('ABOUT-SECOND-MARKER', $about);
        assert_true(
            strpos($about, 'ABOUT-FIRST-MARKER') < strpos($about, 'ABOUT-SECOND-MARKER'),
            'assembled plugin page preserves outline order',
        );
    } finally {
        $previousMode === false
            ? putenv('SITE_BUILD_GEN_UNIT')
            : putenv('SITE_BUILD_GEN_UNIT=' . $previousMode);
        $previousHtmlFirst === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previousHtmlFirst);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});
