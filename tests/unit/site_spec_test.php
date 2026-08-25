<?php
declare(strict_types=1);

use Automattic\SiteBuild\JsonBatchRecovery;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\ContactFacts;
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

test('site-spec drops a host-supplied email domain marked as invented', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture(siteSpec: [
        'name' => 'Supplied Bakery',
        'language' => 'en',
        'email_domain' => 'invented.example',
        'invented' => ['email_domain'],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq('', $project->readJson('siteSpec.json')['email_domain']);
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('path="email_domain"', $joined);
    assert_contains('authored="invented.example"', $joined);
    assert_contains('delivered=""', $joined);
    assert_contains('disposition=dropped because it was marked invented', $joined);

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
    assert_contains("file='siteSpec.json'", $joined);
    assert_contains('path="email_domain"', $joined);
    assert_contains('authored="not a domain!"', $joined);
    assert_contains('delivered=""', $joined);
    assert_contains('disposition=dropped unusable domain rather than inventing one', $joined);

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
    assert_contains('authored="hearthandcrumb.com"', $joined);
    assert_contains('delivered=""', $joined);
    assert_contains('disposition=dropped because the prompt did not state it', $joined);
    assert_contains('path="location.street"', $joined);
    assert_contains('authored="24 Market Street"', $joined);
    assert_contains('delivered=removed', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec warning paths cannot spoof authored, delivered, or disposition fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $key = 'phone"; delivered="kept"; disposition=ignored';
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        $key => '+1 207 555 0100',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')[$key]));
    $joined = implode(' ', $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('path="phone\\"; delivered=\\"kept\\"; disposition=ignored"', $joined);
    assert_contains('authored="+1 207 555 0100"; delivered=removed;', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec scrubs invented contact facts from reserved copy and nested location fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'description' => 'Call +1 207 555 0199',
        'sections' => ['Welcome', 'Visit https://invented.example'],
        'pages' => [[
            'title' => 'Home',
            'slug' => 'home',
            'purpose' => 'Email hello@invented.example',
            'children' => [],
        ]],
        'location' => [
            'city' => 'Boston',
            'postal' => '02108',
            'line1' => '24 Market Street',
        ],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['description'], 'required copy field stays present but loses invented contact copy');
    assert_eq(['Welcome'], $spec['sections'], 'only the contaminated section hint is removed');
    assert_eq('', $spec['pages'][0]['purpose'], 'page shape survives without invented email copy');
    assert_true(!isset($spec['location']), 'nested location fields inherit contact semantics from their parent');
    $encoded = json_encode($spec, JSON_UNESCAPED_SLASHES);
    foreach (['+1 207 555 0199', 'invented.example', 'Boston', '02108', '24 Market Street'] as $invented) {
        assert_true(!str_contains((string) $encoded, $invented), "invented fact {$invented} is absent");
    }

    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    foreach (['description', 'sections[]', 'pages[].purpose', 'location.city', 'location.postal', 'location.line1'] as $path) {
        assert_contains('path="' . $path . '"', $warnings, "warning names {$path}");
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec scrubs numeric and composite invented address fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery at 24 Market Street.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'phone' => 12075550199,
        'support_number' => 12075550198,
        'hotline' => 12075550197,
        'office' => 'Cambridge',
        'headquarters' => 'Somerville',
        'branch' => 'Portland',
        'location' => ['postal' => 2108, 'city' => 'Boston'],
        'address' => '24 Market Street, Boston 02108',
        'mailing' => 'PO Box 123, Boston, MA 02108',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    foreach (['phone', 'support_number', 'hotline', 'office', 'headquarters', 'branch', 'location', 'address', 'mailing'] as $key) {
        assert_true(!isset($spec[$key]), "invented {$key} is absent");
    }
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    foreach (['phone', 'support_number', 'hotline', 'office', 'headquarters', 'branch', 'location.postal', 'location.city', 'address', 'mailing'] as $path) {
        assert_contains('path="' . $path . '"', $warnings, "warning names {$path}");
    }
    assert_contains('authored=12075550199', $warnings, 'numeric authored value remains actionable');
    assert_contains('authored=2108', $warnings, 'numeric postal authored value remains actionable');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec cannot promote compact prompt identifiers into phone fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Order ID 2075550199. ISBN 9781234567890. Version 12075550198.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'phone' => 2075550199,
        'phone_numbers' => [9781234567890, 12075550198],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_true(!isset($spec['phone']));
    assert_true(!isset($spec['phone_numbers']));
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    foreach (['2075550199', '9781234567890', '12075550198'] as $identifier) {
        assert_contains($identifier, $warnings);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec cannot promote formatted identifiers into phone fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'SSN 123-45-6789. Ticket 207.555.0199. Pedido: 207-555-0199.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'phone_numbers' => ['123-45-6789', '207.555.0199', '207-555-0199'],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')['phone_numbers']));
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    foreach (['123-45-6789', '207.555.0199', '207-555-0199'] as $identifier) {
        assert_contains($identifier, $warnings);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec rejects a browser-normalized authority URL grounded only by its domain', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Our email domain is example.com.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'email_domain' => 'example.com',
        'website' => '///example.com/evil',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('example.com', $spec['email_domain']);
    assert_true(!isset($spec['website']), 'a domain does not authorize a longer browser-normalized URL');
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('///example.com/evil', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec contact grounding rejects longer-token substring spoofing', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery described by notexample.com and sales@example.com.evil.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'example.com',
        'email' => 'sales@example.com',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('', $spec['email_domain'], 'a suffix inside a longer domain is not the stated domain');
    assert_true(!isset($spec['email']), 'an address prefix inside a longer address-like token is not stated');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec does not shelter invented contact facts in identity fields', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'fake@example.com',
        'title' => 'Call +1 207 555 0199',
        'persona_name' => 'https://invented.example',
        'language' => 'en',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('A Cozy Neighborhood Bakery', $spec['name']);
    assert_eq('A Cozy Neighborhood Bakery', $spec['title']);
    assert_eq('a-cozy-neighborhood-bakery', $spec['slug']);
    assert_eq('', $spec['persona_name']);
    $encoded = json_encode($spec, JSON_UNESCAPED_SLASHES);
    foreach (['fake@example.com', '+1 207 555 0199', 'invented.example'] as $invented) {
        assert_true(!str_contains((string) $encoded, $invented));
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps generated contact facts that the prompt stated', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'A bakery. Email hello@hearthandcrumb.com or call +1 207 555 0100. '
        . 'Support 12075550199. Secondary phone 12075550197. '
        . 'Arabic hotline +١٢٠٧٥٥٥٠١٩٦. '
        . 'Site https://hearthandcrumb.com. 24 Market Street, Portland.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'email_domain' => 'hearthandcrumb.com',
        'invented' => ['name'],
        'email' => 'hello@hearthandcrumb.com',
        'phone' => '+12075550100',
        'phone_uri' => 'tel:+12075550100',
        'support_number' => 12075550199,
        'phone_numbers' => ['primary' => 12075550197],
        'hotline' => '+١٢٠٧٥٥٥٠١٩٦',
        'website' => 'https://hearthandcrumb.com',
        'location' => ['street' => '24 Market Street', 'city' => 'Portland'],
    ]);
    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('hearthandcrumb.com', $spec['email_domain']);
    assert_eq('hello@hearthandcrumb.com', $spec['email']);
    assert_eq('+12075550100', $spec['phone'], 'phone formatting does not change the stated fact');
    assert_eq('tel:+12075550100', $spec['phone_uri'], 'an explicit tel URI keeps phone-field provenance');
    assert_eq(12075550199, $spec['support_number'], 'a grounded numeric phone preserves its JSON scalar');
    assert_eq(12075550197, $spec['phone_numbers']['primary'], 'nested phone context reaches compact leaves');
    assert_eq('+١٢٠٧٥٥٥٠١٩٦', $spec['hotline'], 'Unicode phone presentation stays intact after canonical comparison');
    assert_eq('https://hearthandcrumb.com', $spec['website']);
    assert_eq('24 Market Street', $spec['location']['street']);
    assert_eq('Portland', $spec['location']['city']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec keeps phones stated with compound localized labels', function () {
    foreach ([
        ['Telefon-Nr.: 0301234567', '0301234567'],
        ['Telefone de contato: 2112345678', '2112345678'],
        ['☎ 2075550199', '2075550199'],
        ['📞 2075550199', '2075550199'],
        ['WhatsApp de ventas: 2112345678', '2112345678'],
        ['Número de contacto: 2112345678', '2112345678'],
        ['Telefonische Auskunft: 0301234567', '0301234567'],
    ] as [$prompt, $phone]) {
        [$project, $llm, $tmp] = make_sitespec_fixture();
        $meta = $project->readJson('meta.json');
        $meta['prompt'] = $prompt;
        $project->writeJson('meta.json', $meta);
        $llm->queueJson(['name' => 'Demo', 'language' => 'en', 'phone' => $phone]);

        (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq($phone, $project->readJson('siteSpec.json')['phone'] ?? null);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('site-spec compares opaque communication URIs as exact destinations', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Skype us at skype:echo123?call.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'skype' => 'skype:echo123?call',
        'signal' => 'signal:invented-user',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('skype:echo123?call', $spec['skype']);
    assert_true(!isset($spec['signal']));
    assert_contains('signal:invented-user', implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec does not fold endpoint digits across numeral systems', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Email user123@example.com, visit https://example.com/order/123, or use skype:user123.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'email' => 'user١٢٣@example.com',
        'website' => 'https://example.com/order/١٢٣',
        'chat' => 'skype:user١٢٣',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_true(!isset($spec['email']));
    assert_true(!isset($spec['website']));
    assert_true(!isset($spec['chat']));
    assert_eq(3, count($project->readJson('warnings.json')['site-spec'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec does not strip punctuation from structured endpoint identities', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Email user@example.com, visit https://trusted.example/path, or use skype:trusted and '
        . 'sip:+12075550199.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'website' => 'https://trusted.example/path!',
        'chat' => 'skype:trusted!',
        'sip' => 'sip:+12075550199!',
        'email' => 'user@example.com!',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_true(!isset($spec['website']));
    assert_true(!isset($spec['chat']));
    assert_true(!isset($spec['sip']));
    assert_true(!isset($spec['email']));
    assert_eq(4, count($project->readJson('warnings.json')['site-spec'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec carries email context through nested generated objects', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Contact user@example.com.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'emails' => ['primary' => 'user@example.com!'],
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')['emails']));
    assert_eq(1, count($project->readJson('warnings.json')['site-spec'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec preserves exact endpoint punctuation explicitly stated in source prose', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Use the exact URL https://trusted.example/path! and exact chat skype:trusted!';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'website' => 'https://trusted.example/path!',
        'chat' => 'skype:trusted!',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $spec = $project->readJson('siteSpec.json');
    assert_eq('https://trusted.example/path!', $spec['website'] ?? null);
    assert_eq('skype:trusted!', $spec['chat'] ?? null);
    assert_true(!$project->exists('warnings.json'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec does not extract an embedded URL from an alphanumeric token', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Product abchttps://invented.example/path';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Demo',
        'language' => 'en',
        'website' => 'https://invented.example/path',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')['website']));
    assert_eq(1, count($project->readJson('warnings.json')['site-spec'] ?? []));
    assert_true(!ContactFacts::sourceStatesExactDestination(
        'Product abchttps://invented.example/path',
        'https://invented.example/path',
    ));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('exact source URL boundaries reject combining-mark prefixes and accept website labels', function () {
    assert_true(!ContactFacts::sourceStatesExactDestination(
        "brand e\u{0301}https://invented.example/path",
        'https://invented.example/path',
    ));
    assert_true(ContactFacts::sourceStatesExactDestination(
        'Website:https://example.com/path',
        'https://example.com/path',
    ));
});

test('exact source destinations preserve browser-distinct default-ignorable code points', function () {
    foreach ([
        ["https://trusted.example/pa\u{200B}th", 'https://trusted.example/path'],
        ["https://trusted.example/pa\u{200B}th", "https://trusted.example/pa\u{200C}th"],
        ["https://us\u{200B}er@trusted.example/p", "https://us\u{200C}er@trusted.example/p"],
        ["skype:first\u{200B}last", 'skype:firstlast'],
        ["sip:user\u{200B}@example.com", 'sip:user@example.com'],
    ] as [$source, $destination]) {
        assert_true(!ContactFacts::sourceStatesExactDestination($source, $destination));
        assert_true(ContactFacts::sourceStatesExactDestination($source, $source));
    }
});

test('exact source destinations normalize browser-identical hosts and default ports', function () {
    foreach ([
        ['https://example.com/path', 'https://example.com:443/path'],
        ['https://example.com/path', 'https://example.com:0443/path'],
        ['https://example.com/path', 'https://example.com:000443/path'],
        ['https://example.com/path', 'https://example.com:/path'],
        ['https://example.com/path', 'https://@example.com/path'],
        ['https://example.com/path', 'https://:@example.com/path'],
        ['https://user@example.com/p', 'https://user:@example.com/p'],
        ['http://example.com/path', 'http://example.com:80/path'],
        ['https://example.com/path', 'https://%65xample.com/path'],
        ['https://bücher.example/path', 'https://xn--bcher-kva.example/path'],
        ['https://[::1]/path', 'https://[0:0:0:0:0:0:0:1]/path'],
        ['https://127.0.0.1/path', 'https://127.1/path'],
        ['https://127.0.0.1/path', 'https://0x7f.1/path'],
        ['https://127.0.0.1/path', 'https://2130706433/path'],
        ['https://us%40er@trusted.example/path', 'https://us@er@trusted.example/path'],
    ] as [$source, $destination]) {
        assert_true(ContactFacts::sourceStatesExactDestination($source, $destination));
        assert_true(ContactFacts::sourceStatesExactDestination($destination, $source));
    }
});

test('exact source destinations preserve browser-distinct credential boundaries and invalid dotted hosts', function () {
    foreach ([
        ['https://user:name@example.com/x', 'https://user%3Aname@example.com/x'],
        ['https://127.0.0.1/path', 'https://127.0.0.1../path'],
        ['https://example.com/?q=a/b', 'https://example.com/?q=a\b'],
        ['https://example.com/#a/b', 'https://example.com/#a\b'],
    ] as [$source, $destination]) {
        assert_true(!ContactFacts::sourceStatesExactDestination($source, $destination));
        assert_true(ContactFacts::sourceStatesExactDestination($source, $source));
    }
});

test('site-spec compares phone extensions as a separate part of the stated fact', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $meta = $project->readJson('meta.json');
    $meta['prompt'] = 'Call +1 207 555 0199.';
    $project->writeJson('meta.json', $meta);
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'phone' => '+1 207 555 019 ext 9',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')['phone']));
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('+1 207 555 019 ext 9', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site-spec drops a tel destination outside a phone-shaped field', function () {
    [$project, $llm, $tmp] = make_sitespec_fixture();
    $llm->queueJson([
        'name' => 'Hearth & Crumb',
        'language' => 'en',
        'order_id' => 'tel:1234567890',
    ]);

    (new SiteSpecStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!isset($project->readJson('siteSpec.json')['order_id']));
    $warnings = implode("\n", $project->readJson('warnings.json')['site-spec'] ?? []);
    assert_contains('tel:1234567890', $warnings);
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
    assert_contains('authored="hearthandcrumb.com"', $joined);
    assert_contains('delivered=""', $joined);
    assert_contains('disposition=dropped because the prompt did not state it', $joined);

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
