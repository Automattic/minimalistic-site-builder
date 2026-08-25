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
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A cozy neighborhood bakery at hearthandcrumb.com, open Tue–Sun 7am–3pm';
    $project->writeJson('meta.json', $meta);
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
        'invented' => ['name', 'colors'],                // unknown key must be dropped
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
    assert_eq('hearthandcrumb.com', $spec['email_domain']);       // lowercased stated domain
    assert_eq(['name'], $spec['invented']);                       // non-identity key dropped
    assert_true(is_array($spec['sections']));
    assert_eq('Hero', $spec['sections'][0]);
    assert_eq('Tue–Sun 7am–3pm', $spec['hours']);        // arbitrary fact preserved

    // No design fields should be invented/filled.
    assert_true(!isset($spec['colors']), 'no colors in factual spec');
    assert_true(!isset($spec['typography']), 'no typography in factual spec');
    assert_true(!isset($spec['layout']), 'no layout in factual spec');

    // The rendered prompt must carry the user's words.
    assert_contains('hearthandcrumb.com', $llm->calls[0]['prompt']);

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
    // A missing email_domain stays empty — never derived from the slug.
    assert_eq('', $spec['email_domain']);
    assert_eq([], $spec['invented']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec drops an implausible email_domain instead of inventing one', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en', 'email_domain' => 'not a domain!']);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['email_domain']);
    assert_eq([], $spec['invented']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('not a usable domain', $joined);
    assert_contains('dropped rather than inventing one', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec drops an unflagged email_domain the prompt never stated', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name'],
        'email' => 'hello@hearthandcrumb.com',
        'phone' => '+1 207 555 0100',
        'website' => 'https://hearthandcrumb.com',
        'location' => ['street' => '24 Market Street', 'city' => 'Portland'],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['email_domain'], 'a plausible domain still needs to appear in the prompt');
    assert_true(!isset($spec['email']));
    assert_true(!isset($spec['phone']));
    assert_true(!isset($spec['website']));
    assert_true(!isset($spec['location']['street']));
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('email_domain', $joined);
    assert_contains('not stated in the prompt', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps generated contact facts that the prompt stated', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery. Email hello@hearthandcrumb.com or call +1 207 555 0100. '
        . 'Site https://hearthandcrumb.com. 24 Market Street, Portland.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name'],
        'email' => 'hello@hearthandcrumb.com',
        'phone' => '+1 207 555 0100',
        'website' => 'https://hearthandcrumb.com',
        'location' => ['street' => '24 Market Street', 'city' => 'Portland'],
    ]);
    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hearthandcrumb.com', $spec['email_domain']);
    assert_eq('hello@hearthandcrumb.com', $spec['email']);
    assert_eq('+1 207 555 0100', $spec['phone']);
    assert_eq('https://hearthandcrumb.com', $spec['website']);
    assert_eq('24 Market Street', $spec['location']['street']);
    assert_eq('Portland', $spec['location']['city']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec drops an invented email_domain even when it looks valid', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name', 'email_domain'],
    ]);
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new SiteSpecStep($llm, $renderer))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['email_domain'], 'an invented domain is not a contact fact');
    assert_eq(['name'], $spec['invented']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('email_domain', $joined);
    assert_contains('not stated in the prompt', $joined);

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
        "the SITE SPEC's own language (never a language implied by the site's location or audience)",
        SiteSpecStep::languageOf($project),
        'the empty field renders an instruction the copy prompts can actually resolve',
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

test('site-spec leaves a caller-requested Cart page named and routed as asked', function () {
    // REQUESTED_SCOPE promises a caller-fixed list survives unchanged — same
    // order, same slugs, same titles. That promise outranks the cart rename:
    // the page keeps its name and its route, and its CONTENTS degrade later,
    // where StorefrontDegrade::markup strips the purchase controls.
    [$project, $llm, $tmp] = make_sitespec_fixture(multiPage: true, pages: ['Home', 'Cart', 'Contact']);
    $llm->queueJson([
        'name' => 'Hearth & Crumb', 'language' => 'en',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome visitors', 'children' => []],
            ['title' => 'Cart', 'slug' => 'cart', 'purpose' => 'Basket and checkout', 'children' => []],
            ['title' => 'Contact', 'slug' => 'contact', 'purpose' => 'Find us', 'children' => []],
        ],
    ]);
    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $pages = $project->readJson('siteSpec.json')['pages'];
    assert_eq(['home', 'cart', 'contact'], array_column($pages, 'slug'), 'the requested route survives');
    assert_eq(['Home', 'Cart', 'Contact'], array_column($pages, 'title'), 'the requested title survives');

    $warnings = $project->exists('warnings.json')
        ? implode(' ', $project->readJson('warnings.json')['site-spec'] ?? [])
        : '';
    assert_true(
        !str_contains($warnings, 'catalog storefront'),
        'no rewrite is claimed for a page the caller pinned',
    );
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

test('copy prompts never mint or invent contact details', function () {
    $files = [
        'prompts/site-spec.md',
        'prompts/refine-prompt.md',
        'prompts/section.md',
        'prompts/footer.md',
        'prompts/page-plan.md',
        'prompts/no-forms.md',
        'prompts/hero.md',
        'prompts/inner-page-design.md',
        'prompts/homepage-design.md',
        'prompts/home-body-design.md',
        'prompts/inner-section-design.md',
        'prompts/design-preview.md',
    ];
    foreach ($files as $file) {
        $text = (string) file_get_contents(repo_path($file));
        assert_contains(
            'Never invent an email, street address, phone number, or URL',
            $text,
            $file,
        );
        assert_true(
            !preg_match('/mint(?:ed| a short local part)/i', $text),
            "{$file} must not tell the model to mint a contact address",
        );
    }
});

test('site-spec grounds contact facts in the original prompt, not the refined rewrite', function () {
    // refine-prompt replaces meta's `prompt` with its own rewrite immediately
    // before this step, so a contact fact IT invented must not vouch for itself.
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['original_prompt'] = 'A cozy neighborhood bakery';
    $meta['prompt'] = 'A cozy neighborhood bakery. Reach the team at hello@hearthandcrumb.com.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name'],
        'email' => 'hello@hearthandcrumb.com',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['email_domain'], 'the refined brief cannot ground a contact fact it invented');
    assert_true(!isset($spec['email']), 'an email only the refined brief carries is dropped');
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('not stated in the prompt', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps a contact fact the user stated even when refinement reworded around it', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['original_prompt'] = 'A bakery. Email hello@hearthandcrumb.com.';
    $meta['prompt'] = 'A warm neighborhood bakery serving naturally leavened bread and seasonal pastries.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name'],
        'email' => 'hello@hearthandcrumb.com',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hearthandcrumb.com', $spec['email_domain']);
    assert_eq('hello@hearthandcrumb.com', $spec['email']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec scrubs contact facts hiding in a list and reindexes what survives', function () {
    // A list item carries no key of its own, so it inherits its parent's.
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery at 24 Market Street, Portland.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'address' => ['10 Elm Avenue', '24 Market Street'],
        'phones' => ['+1 207 555 0100'],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq(['24 Market Street'], $spec['address'], 'the unstated street is dropped and the list stays a list');
    assert_true(!isset($spec['phones']), 'a phone stated nowhere is dropped even inside a list');
    assert_contains(
        '"address[]"',
        implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []),
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec scrubs a phone the model emitted as a JSON number', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'phone' => 2075550100,   // a number, not a string
        'members' => 1234567,    // a plain count under a non-contact key
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_true(!isset($spec['phone']), 'a numeric phone is still a phone');
    assert_eq(1234567, $spec['members'], 'a bare number under a non-contact key is not a contact fact');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps a numeric phone the prompt stated', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery. Call 2075550100.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en', 'phone' => 2075550100]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(2075550100, $project->readJson('siteSpec.json')['phone']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec scrubs contact facts under camelCase keys', function () {
    // Generated JSON spells one key three ways. The word boundaries that keep
    // `tel` out of `hotel` must cut at a camelCase hump too.
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Harbor House',
        'language' => 'en',
        'emailAddress' => 'hello@harborhouse.com',
        'phoneNumber' => 2075550100,
        'streetAddress' => '24 Market Street',
        'whatsApp' => '+1 207 555 0111',
        'hotelName' => 'The Harbor',   // `tel` inside `hotel` is not a phone
        'memberCount' => 1234567,      // a plain count is not a contact fact
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    foreach (['emailAddress', 'phoneNumber', 'streetAddress', 'whatsApp'] as $key) {
        assert_true(!isset($spec[$key]), "{$key} was stated nowhere and must be dropped");
    }
    assert_eq('The Harbor', $spec['hotelName'], 'a word merely containing `tel` is not a contact key');
    assert_eq(1234567, $spec['memberCount']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps camelCase contact facts the prompt stated', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A shop. Email hello@harborhouse.com or call +1 207 555 0100.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Harbor House',
        'language' => 'en',
        'emailAddress' => 'hello@harborhouse.com',
        'phoneNumber' => '+1 207 555 0100',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hello@harborhouse.com', $spec['emailAddress']);
    assert_eq('+1 207 555 0100', $spec['phoneNumber']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec falls back to the prompt when original_prompt is unusable', function () {
    // A blank or non-string original_prompt must not ground everything to
    // nothing — that would scrub every contact fact the real prompt states.
    foreach (['' => 'blank', '   ' => 'whitespace', 0 => 'non-string'] as $bad => $label) {
        [$project, $llm, $tmp] = make_sitespec_fixture();
        $meta = $project->readJson('meta.json');
        $meta['prompt'] = 'A bakery. Email hello@hearthandcrumb.com.';
        $meta['original_prompt'] = $bad === 0 ? ['not', 'a', 'string'] : $bad;
        $project->writeJson('meta.json', $meta);
        $llm->queueJson(['name' => 'Hearth & Crumb', 'language' => 'en', 'email' => 'hello@hearthandcrumb.com']);

        (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq(
            'hello@hearthandcrumb.com',
            $project->readJson('siteSpec.json')['email'] ?? null,
            "a {$label} original_prompt must fall back to the prompt, not scrub everything",
        );
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
