<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\WritingDirection;

test('site-spec normalizes a host-supplied spec without an LLM call', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true, siteSpec: [
        'name' => 'Supplied Bakery',
        'slug' => 'Supplied Bakery',
        'language' => 'en',
        'email_domain' => 'SUPPLIED.EXAMPLE',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors'],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'List the baked goods'],
        ],
        'hours' => 'Tue-Sun 7am-3pm',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq(0, $llm->completeJsonCalls, 'a supplied spec must bypass candidate generation');
    assert_eq('supplied-bakery', $spec['slug']);
    assert_eq('supplied.example', $spec['email_domain']);
    assert_eq(['home', 'menu'], array_column($spec['pages'], 'slug'));
    assert_eq('Tue-Sun 7am-3pm', $spec['hours'], 'arbitrary factual fields survive normalization');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec treats an explicitly supplied empty array as input and degrades without an LLM call', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(siteSpec: []);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq(0, $llm->completeJsonCalls, 'an empty supplied spec must not fall through to generation');
    assert_eq('A Cozy Neighborhood Bakery', $spec['name']);
    assert_eq('home', $spec['pages'][0]['slug']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('site spec has no "name"', $joined);
    assert_contains('site spec has no "language"', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec rejects a malformed explicit supplied input instead of invoking the LLM', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['site_spec'] = 'not an object';
    $project->writeJson('meta.json', $meta);

    assert_throws(fn () => (new SiteSpecStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
    ))->run($project));
    assert_eq(0, $llm->completeJsonCalls);
    assert_true(!$project->exists('siteSpec.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

/** @return list<string> */
function site_spec_tree_slugs(array $pages): array
{
    $slugs = [];
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $slugs[] = (string) ($page['slug'] ?? '');
        $slugs = array_merge(
            $slugs,
            site_spec_tree_slugs(is_array($page['children'] ?? null) ? $page['children'] : []),
        );
    }
    return $slugs;
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
    assert_eq('ltr', $spec['writing_direction']);
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

test('site-spec delivers a prompt-derived fallback when repaired model JSON is still malformed', function () {
    [$project, , $tmp] = make_sitespec_fixture();
    $llm = new class implements Llm {
        public int $rounds = 0;

        public function complete(string $prompt, array $opts = []): string
        {
            throw new RuntimeException('unused');
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            return JsonBatchRecovery::run(
                ['request' => ['prompt' => $prompt] + $opts],
                function (array $subset): array {
                    $this->rounds++;
                    return ['request' => ['text' => '{"sections":[}']];
                },
            )['request'];
        }

        public function completeJsonBatch(array $requests): array
        {
            throw new RuntimeException('unused');
        }

        public function completeBatch(array $requests): \Automattic\SiteBuild\TextBatchResult
        {
            throw new RuntimeException('unused');
        }
    };

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('A Cozy Neighborhood Bakery', $spec['name']);
    assert_eq(2, $llm->rounds, 'one malformed response and one malformed repair response');
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('generated JSON remained unusable', $joined);
    assert_contains('deterministic prompt-derived site spec delivered', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps an operational JSON failure fatal', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(); // no queued response => plain RuntimeException

    assert_throws(fn () => (new SiteSpecStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
    ))->run($project));

    assert_true(!$project->exists('siteSpec.json'), 'no fallback for an unclassified operational failure');
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

test('site-spec reserves preview artifact slug in model and caller-fixed page trees', function () {
    $modelPages = SiteSpecStep::normalizePages([
        ['title' => 'Home', 'slug' => 'home'],
        ['title' => 'Work', 'slug' => 'work', 'children' => [
            ['title' => 'Preview', 'slug' => 'preview'],
            ['title' => 'Archive', 'slug' => 'archive', 'children' => [
                ['title' => 'Another Preview', 'slug' => 'preview'],
            ]],
        ]],
    ], [], true);
    assert_eq(
        ['home', 'work', 'preview-2', 'archive', 'preview-3'],
        site_spec_tree_slugs($modelPages),
        'model tree cannot claim design/preview.html',
    );

    $requestedPages = SiteSpecStep::requestedPages([
        ['title' => 'Home', 'slug' => 'home'],
        ['title' => 'Work', 'slug' => 'work', 'children' => [
            ['title' => 'Preview', 'slug' => 'preview'],
            ['title' => 'Archive', 'slug' => 'archive', 'children' => [
                ['title' => 'Another Preview', 'slug' => 'preview'],
            ]],
        ]],
    ]);
    assert_eq(
        ['home', 'work', 'preview-2', 'archive', 'preview-3'],
        site_spec_tree_slugs($requestedPages),
        'caller-fixed tree cannot claim design/preview.html',
    );
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

test('writing direction uses caller override, then reviewed language mapping, then ltr', function () {
    assert_eq('rtl', WritingDirection::fromLanguage('ar'));
    assert_eq('rtl', WritingDirection::fromLanguage('Hebrew'));
    assert_eq('rtl', WritingDirection::fromLanguage('fa-IR'));
    assert_eq('ltr', WritingDirection::fromLanguage('es-AR'));
    assert_eq('ltr', WritingDirection::fromLanguage('unknown language'));

    [$project, $llm, $tmp] = make_sitespec_fixture();
    $project->writeJson('meta.json', [
        'prompt' => 'A publication in Arabic',
        'writing_direction' => 'ltr',
    ]);
    $llm->queueJson(['name' => 'مجلة', 'language' => 'ar']);
    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    assert_eq('ltr', $project->readJson('siteSpec.json')['writing_direction'], 'caller wins over language');
    assert_eq('ltr', SiteSpecStep::writingDirectionOf($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec derives rtl from generated language and ignores a model-authored direction', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'مجلة',
        'language' => 'ar',
        'writing_direction' => 'ltr',
    ]);
    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    assert_eq('rtl', $project->readJson('siteSpec.json')['writing_direction']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('invalid caller writing direction fails before the site-spec LLM call', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $project->writeJson('meta.json', [
        'prompt' => 'A cozy neighborhood bakery',
        'writing_direction' => 'auto',
    ]);
    assert_throws(fn () => (new SiteSpecStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
    ))->run($project));
    assert_eq(0, count($llm->calls));
    assert_true(!$project->exists('siteSpec.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec normalizes subject_is_visual_work to a strict boolean', function () {
    foreach ([
        [true, true],
        [false, false],
        ['true', false],
        [1, false],
        [null, false],
    ] as [$authored, $expected]) {
        [$project, $llm, $tmp] = make_sitespec_fixture();
        $payload = ['name' => 'Solo', 'language' => 'en'];
        if ($authored !== null) {
            $payload['subject_is_visual_work'] = $authored;
        }
        $llm->queueJson($payload);
        (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
        assert_eq($expected, $project->readJson('siteSpec.json')['subject_is_visual_work']);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
