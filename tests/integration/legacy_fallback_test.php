<?php
declare(strict_types=1);

use Automattic\SiteBuild\Tests\FakeLlm;

function legacy_fallback_block(string $heading, string $marker): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading --><h2>' . $heading . '</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>' . $marker . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

function legacy_fallback_header(): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->';
}

function legacy_fallback_footer(): string
{
    return '<!-- wp:group {"tagName":"footer","layout":{"type":"constrained"}} -->'
        . '<footer class="wp-block-group"><!-- wp:paragraph --><p>Visit us.</p><!-- /wp:paragraph -->'
        . '</footer><!-- /wp:group -->';
}

test('malformed homepage reroutes the build through the legacy tail and records it', function () {
    $tmp = sys_get_temp_dir() . '/builder_legacy_reroute_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $llm = new FakeLlm();
        $llm->queueText('A warm neighborhood bakery site.');
        $llm->queueText('<!doctype html><html><body><main>GARBAGE-CANDIDATE</main></body></html>');
        $llm->queueText('<!doctype html><html><body><main>GARBAGE-REPAIR</main></body></html>');
        $llm->queueText('OK');
        $llm->queueText(legacy_fallback_header());
        $llm->queueText(legacy_fallback_footer());
        $llm->queueText(legacy_fallback_block('Welcome', 'LEGACY-REROUTE-HOME'));
        $llm->queueText(legacy_fallback_block('Our bakehouse', 'LEGACY-REROUTE-STORY'));

        $llm->queueJson(html_first_site_spec([
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
        ]));
        $llm->queueJson(['seeds' => ['Flour Archive', 'Bread Ledger', 'Oven Journal', 'Grain Index']]);
        $llm->queueJson(html_first_direction());
        $llm->queueJson(['seeds' => ['Broken candidate']]);
        $llm->queueJson(['winner' => 0, 'why' => 'Only candidate']);
        $llm->queueJson(html_first_theme_payload());
        $llm->queueJson(['sections' => [
            [
                'slug' => 'welcome', 'title' => 'Welcome', 'role' => 'hero', 'type' => 'welcome',
                'layout_archetype' => 'centered-stack', 'background' => 'base',
                'vertical_density' => 'standard', 'handoff' => 'Before the story.',
            ],
            [
                'slug' => 'story', 'title' => 'Our bakehouse', 'role' => 'closing', 'type' => 'story',
                'layout_archetype' => 'mixed-width-editorial', 'background' => 'base',
                'vertical_density' => 'compact', 'handoff' => 'Before the footer.',
            ],
        ]]);

        $builder = html_first_integration_builder($llm, $tmp);
        $project = $builder->createProject('A neighborhood bakery', 'demo');
        $meta = $project->readJson('meta.json');
        $meta['design_candidates'] = 1;
        $meta['critique_rounds'] = 0;
        $project->writeJson('meta.json', $meta);

        $builder->pipeline()->runThrough($project);

        assert_contains('LEGACY-REROUTE-HOME', $project->readText('plugin/pages/home.html'));
        assert_true($project->exists('logs/validate-theme.log'), 'legacy reroute reaches final validation');
        $warningText = json_encode(
            $project->readJson('warnings.json'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        assert_contains('legacy_reroute', $warningText, 'warnings.json records runtime reroute');
        assert_contains('homepage-design', $warningText, 'warning locates failed step');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});

test('one failed inner design uses legacy sections while sibling pages stay transformed', function () {
    $tmp = sys_get_temp_dir() . '/builder_mixed_route_' . uniqid();
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $llm = new FakeLlm();
        $pages = [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'About', 'slug' => 'about', 'purpose' => 'Explain the bakery', 'children' => []],
            ['title' => 'Contact', 'slug' => 'contact', 'purpose' => 'Help visitors plan a trip', 'children' => []],
        ];
        html_first_queue_success($llm, html_first_site_spec($pages), html_first_home_document());
        $llm->queueText(
            '<main><section id="about-intro"><h1>About</h1><p>HTML-FIRST-ABOUT</p></section></main>',
        );
        $llm->queueText('CONTACT-GARBAGE');
        $llm->queueText('CONTACT-REPAIR-GARBAGE');
        $llm->queueText('OK');
        $llm->queueText(legacy_fallback_block('Visit', 'LEGACY-CONTACT'));
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
        assert_contains('LEGACY-CONTACT', $project->readText('plugin/pages/contact.html'));
        assert_true(!str_contains($project->readText('plugin/pages/about.html'), 'LEGACY-CONTACT'));
        assert_eq(['home', 'about', 'contact'], array_column(
            $project->readJson('plugin/pages.json')['pages'],
            'slug',
        ));
        assert_eq(3, $llm->completeBatchCalls, 'homepage, inner-page, and scoped legacy section batches only');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
        if (is_dir($tmp)) {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }
});
