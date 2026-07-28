<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function make_sitespec_fixture(bool $multiPage = false, ?array $pages = null): array
{
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $meta = ['prompt' => 'A cozy neighborhood bakery', 'multi_page' => $multiPage];
    if ($pages !== null) {
        $meta['pages'] = $pages;
    }
    $project->writeJson('meta.json', $meta);
    return [$project, new FakeLlm(), $tmp];
}

test('site-spec writes a factual, normalized siteSpec.json', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        // slug intentionally omitted -> derived from name
        'site_type' => 'bakery storefront',
        'topic' => 'artisan sourdough and pastries',
        'area' => 'bakery',
        'audience' => 'neighborhood locals',
        'language' => 'en',
        'persona_name' => '',
        'email_domain' => 'HearthAndCrumb.com',          // must be lowercased
        'invented' => ['name', 'email_domain', 'colors'], // unknown key must be dropped
        'visual_vibe' => 'warm and rustic',
        'sections' => ['Hero', 'Menu', 'About', 'Visit'],
        // An extra factual field the user stated — must pass through.
        'hours' => 'Tue–Sun 7am–3pm',
    ]);

    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Hearth & Crumb', $spec['name']);
    assert_eq('hearth-crumb', $spec['slug']);            // derived + slugified
    assert_eq('Hearth & Crumb', $spec['title']);         // title falls back to name
    assert_eq('warm and rustic', $spec['visual_vibe']);
    assert_eq('en', $spec['language']);
    assert_eq('hearthandcrumb.com', $spec['email_domain']);       // lowercased
    assert_eq(['name', 'email_domain'], $spec['invented']);       // non-identity key dropped
    assert_true(is_array($spec['sections']));
    assert_eq('Hero', $spec['sections'][0]);
    assert_eq('Tue–Sun 7am–3pm', $spec['hours']);        // arbitrary fact preserved

    // No design fields should be invented/filled.
    assert_true(!isset($spec['colors']), 'no colors in factual spec');
    assert_true(!isset($spec['typography']), 'no typography in factual spec');
    assert_true(!isset($spec['layout']), 'no layout in factual spec');

    // The rendered prompt must carry the user's words.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec fills missing fixed properties with empty strings', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Solo', 'language' => 'en']); // only name + language
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('Solo', $spec['name']);
    foreach (['title', 'site_type', 'topic', 'area', 'audience', 'visual_vibe', 'persona_name'] as $key) {
        assert_true(array_key_exists($key, $spec), "{$key} key present");
    }
    assert_eq([], $spec['sections']);
    // A missing email_domain is derived from the slug and flagged as invented.
    assert_eq('solo.com', $spec['email_domain']);
    assert_eq(['email_domain'], $spec['invented']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec derives email_domain from multi-word slug when implausible', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en', 'email_domain' => 'not a domain!']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hearthcrumb.com', $spec['email_domain']); // slug minus hyphens
    assert_eq(['email_domain'], $spec['invented']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec accepts a language name as well as a BCP-47 code', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));

    $llm->queueJson(['name' => 'Solo', 'language' => 'es-AR']);
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('es-AR', $project->readJson('siteSpec.json')['language']);

    $llm->queueJson(['name' => 'Solo', 'language' => 'Spanish']);
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('Spanish', $project->readJson('siteSpec.json')['language']);

    // Parenthesised region is not a plausible code or plain name: the field
    // is dropped with a durable warning — downstream prompts then follow the
    // user prompt's language via languageOf() — instead of failing the build.
    $llm->queueJson(['name' => 'Solo', 'language' => 'Spanish (Argentina)']);
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('', $project->readJson('siteSpec.json')['language']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('not a plausible language', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec falls back to a prompt-derived name when the model returns none', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['topic' => 'no name here', 'language' => 'en']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('A Cozy Neighborhood Bakery', $spec['name']);
    assert_eq($spec['name'], $spec['title'], 'title falls back to the name');
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('site spec has no "name"', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec degrades a missing or implausible language with a durable warning', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $renderer = new PromptRenderer(repo_path('prompts'));

    $llm->queueJson(['name' => 'Solo']); // no language
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('', $project->readJson('siteSpec.json')['language']);
    assert_eq(
        'the language the user prompt is written in',
        SiteSpecStep::languageOf($project),
        'the empty field renders the follow-the-prompt instruction downstream',
    );

    $llm->queueJson(['name' => 'Solo', 'language' => '12345']); // not a code or name
    (new SiteSpecStep($llm, $renderer))->run($project);
    assert_eq('', $project->readJson('siteSpec.json')['language']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('site spec has no "language"', $joined);
    assert_contains('12345', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('nameFromPrompt derives a clean short name and floors at "New Site"', function () {
    assert_eq('A Cozy Neighborhood Bakery', SiteSpecStep::nameFromPrompt('A cozy neighborhood bakery'));
    assert_eq(
        'Modern Portfolio For A Buenos Aires',
        SiteSpecStep::nameFromPrompt('Modern portfolio for a Buenos Aires photographer, dark & moody'),
        'first six words, punctuation stripped',
    );
    assert_eq('New Site', SiteSpecStep::nameFromPrompt('!!! ???'));
    assert_eq(
        'Ñoquis De La Abuela',
        SiteSpecStep::nameFromPrompt('ñoquis de la abuela'),
        'a leading multibyte letter is capitalized',
    );
    assert_eq(
        'Buenos Aires Photo Diary',
        SiteSpecStep::nameFromPrompt('Buenos-Aires photo diary'),
        'hyphenated words stay separate instead of being joined',
    );
});

test('site-spec normalizes the pages tree: slugs slugified and globally unique', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true);
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'Our Menu', 'purpose' => 'The menu', 'children' => [
                ['title' => 'Breads', 'slug' => 'Our Menu', 'purpose' => 'Bread list'], // slugifies to our-menu -> collides
            ]],
            ['title' => 'Visit', 'slug' => 'visit', 'purpose' => 'Hours and address', 'children' => 'nope'],
        ],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq('home', $pages[0]['slug']);
    assert_eq('our-menu', $pages[1]['slug']);                 // derived from title
    assert_eq('our-menu-2', $pages[1]['children'][0]['slug']); // deduped across the whole tree
    assert_eq('Breads', $pages[1]['children'][0]['title']);
    assert_eq([], $pages[2]['children']);                      // non-array children dropped
    assert_eq('Hours and address', $pages[2]['purpose']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec without multi_page cuts the tree to the homepage and asks for one page', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(); // multi_page defaults to false
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        // The model disobeyed the one-page instruction — the flag must win.
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => [
                ['title' => 'News', 'slug' => 'news', 'purpose' => 'Updates'],
            ]],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'The menu', 'children' => []],
        ],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(1, count($pages));
    assert_eq('home', $pages[0]['slug']);
    assert_eq([], $pages[0]['children']);

    // The rendered prompt must carry the one-page instruction, not the tree menu.
    assert_contains('one-page site', $llm->calls[0]['prompt']);
    assert_true(!str_contains($llm->calls[0]['prompt'], '1-6 top-level pages'), 'no multi-page scope in prompt');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec with multi_page keeps the tree and asks for it', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true);
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'The menu', 'children' => []],
        ],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(2, count($pages));
    assert_eq('menu', $pages[1]['slug']);
    assert_contains('1-6 top-level pages', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec with requested pages fixes the tree — the model contributes only purposes', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true, pages: ['Home', 'Menu', 'Contact']);
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        // The model added a page, dropped one, and renamed another — none of it sticks.
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'Full Menu', 'slug' => 'menu', 'purpose' => 'The menu', 'children' => []],
            ['title' => 'Gallery', 'slug' => 'gallery', 'purpose' => 'Photos', 'children' => []],
        ],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(['home', 'menu', 'contact'], array_column($pages, 'slug'));   // added page gone, dropped page back
    assert_eq(['Home', 'Menu', 'Contact'], array_column($pages, 'title'));  // rename didn't stick
    assert_eq('Welcome visitors', $pages[0]['purpose']);  // model purposes kept (matched by slug)
    assert_eq('The menu', $pages[1]['purpose']);
    assert_eq('', $pages[2]['purpose']);                  // model dropped it -> synthesized, no purpose

    // The rendered prompt carries the fixed list, not the invent-a-tree scope.
    assert_contains('"Contact" (slug: contact)', $llm->calls[0]['prompt']);
    assert_contains('already decided', $llm->calls[0]['prompt']);
    assert_true(!str_contains($llm->calls[0]['prompt'], '1-6 top-level pages'), 'no invent-scope in prompt');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec requested pages: caller-stated purposes win over the model\'s', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true, pages: [
        ['title' => 'Home', 'slug' => 'home'],
        ['title' => 'Our Menu', 'purpose' => 'Breads and pastries, with prices'],
    ]);
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'Our Menu', 'slug' => 'our-menu', 'purpose' => 'Something else', 'children' => []],
        ],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq('Breads and pastries, with prices', $pages[1]['purpose']); // caller's wins
    assert_eq('Welcome visitors', $pages[0]['purpose']);                 // caller left blank -> model's

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec ignores requested pages without multi_page — the flag owns the decision', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(pages: ['Home', 'Menu', 'Contact']); // multi_page false
    $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(1, count($pages));
    assert_eq('home', $pages[0]['slug']);
    assert_contains('one-page site', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec requestedPages accepts titles and page maps, drops junk', function () {
    $requested = SiteSpecStep::requestedPages([
        'Home',
        '  ',                                                       // blank -> dropped
        ['title' => 'Our Menu', 'purpose' => 'The menu'],
        42,                                                         // junk -> dropped
        ['title' => 'Visit', 'children' => [['title' => 'Directions']]],
    ]);

    assert_eq(['home', 'our-menu', 'visit'], array_column($requested, 'slug'));
    assert_eq('The menu', $requested[1]['purpose']);
    assert_eq('directions', $requested[2]['children'][0]['slug']);
    assert_eq([], SiteSpecStep::requestedPages(null));
    assert_eq([], SiteSpecStep::requestedPages('Home, Menu'));      // a bare string is not a list

    exec('true');
});

test('site-spec defaults pages to a single homepage when the model omits them', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Solo', 'language' => 'en',
        'description' => 'A one-page site about one thing.',
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));
    (new SiteSpecStep($llm, $renderer))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(1, count($pages));
    assert_eq('home', $pages[0]['slug']);
    assert_eq('Home', $pages[0]['title']);
    assert_eq('A one-page site about one thing.', $pages[0]['purpose']);
    assert_eq([], $pages[0]['children']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec pages entries get title fallback from slug and drop junk entries', function () {
    $pages = SiteSpecStep::normalizePages([
        ['slug' => 'about-us'],           // no title -> Ucwords from slug
        'not-a-page',                     // junk entry dropped
        ['purpose' => 'no slug, no title'], // unsluggable -> page-N fallback
    ], ['description' => '']);

    assert_eq('About Us', $pages[0]['title']);
    assert_eq('about-us', $pages[0]['slug']);
    assert_eq(2, count($pages));
    assert_true($pages[1]['slug'] !== '', 'fallback slug non-empty');

    exec('true');
});

test('site-spec throws when meta prompt missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_sitespec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => '']);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($renderer, $project) {
        (new SiteSpecStep(new FakeLlm(), $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
