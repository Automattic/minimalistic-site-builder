<?php
declare(strict_types=1);

use Automattic\SiteBuild\Tests\FakeLlm;

function blocks_fallback_block(string $heading, string $marker): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading --><h2>' . $heading . '</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>' . $marker . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

test('one failed inner design uses blocks-path sections while sibling pages stay transformed', function () {
    $tmp = sys_get_temp_dir() . '/builder_mixed_route_' . uniqid();
    $previous = getenv('SITE_BUILD_HTML_FIRST');
    putenv('SITE_BUILD_HTML_FIRST=1');
    try {
        $llm = new FakeLlm();
        $pages = [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'About', 'slug' => 'about', 'purpose' => 'Explain the bakery', 'children' => []],
            ['title' => 'Contact', 'slug' => 'contact', 'purpose' => 'Help visitors plan a trip', 'children' => []],
        ];
        html_first_queue_success($llm, html_first_site_spec($pages), html_first_home_body());
        $llm->queueText(
            '<main><section id="about-intro"><h1>About</h1><p>HTML-FIRST-ABOUT</p></section></main>',
        );
        $llm->queueText('CONTACT-GARBAGE');
        $llm->queueText('CONTACT-REPAIR-GARBAGE');
        $llm->queueText('OK');
        $llm->queueText(blocks_fallback_block('Visit', 'BLOCKS-CONTACT'));
        $llm->queueJson(['sections' => [[
            'slug' => 'visit', 'title' => 'Visit', 'role' => 'hero', 'type' => 'contact-details',
            'layout_archetype' => 'centered-stack', 'background' => 'base',
            'vertical_density' => 'standard', 'handoff' => 'Before the footer.',
        ]]]);

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject(
            'A neighborhood bakery',
            'demo',
            multiPage: true,
            pages: $pages,
        );
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 1;
        $project->writeJson('meta.json', $meta);

        $builder->pipeline()->runThrough($project);

        assert_true($project->exists('design/contact.failed'));
        assert_contains('HTML-FIRST-HOME', $project->readText('plugin/pages/home.html'));
        assert_contains('HTML-FIRST-ABOUT', $project->readText('plugin/pages/about.html'));
        assert_contains('BLOCKS-CONTACT', $project->readText('plugin/pages/contact.html'));
        assert_true(
            trim($project->readText('theme/parts/footer.html')) !== '',
            'multi-page full build lifts non-empty home-body footer part',
        );
        assert_true(!str_contains($project->readText('plugin/pages/about.html'), 'BLOCKS-CONTACT'));
        assert_eq(['home', 'about', 'contact'], array_column(
            $project->readJson('plugin/pages.json')['pages'],
            'slug',
        ));
        $sectionCount = 0;
        foreach (['home', 'about', 'contact'] as $slug) {
            $sectionCount += assert_html_first_page_sections_constrained($project, $slug);
        }
        assert_true($sectionCount >= 3, 'multi-page HTML-first build constrains every delivered section');
        assert_eq(
            3,
            $llm->completeBatchCalls,
            'page generation, scoped section cache warm, and scoped blocks-path section batches only',
        );
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});
