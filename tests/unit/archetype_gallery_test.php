<?php
declare(strict_types=1);

use Automattic\SiteBuild\ArchetypeCatalog;
use Automattic\SiteBuild\ArchetypeGallery;
use Automattic\SiteBuild\ArchetypeProposals;

test('the archetype catalog flattens all four code-owned catalogs into one row shape', function () {
    $entries = ArchetypeCatalog::entries();
    assert_true($entries !== []);

    $seen = [];
    foreach ($entries as $entry) {
        foreach (['family', 'id', 'key', 'summary', 'source', 'facts', 'note'] as $field) {
            assert_true(array_key_exists($field, $entry), "row has {$field}");
        }
        assert_true(in_array($entry['family'], ArchetypeCatalog::FAMILIES, true), $entry['family']);
        assert_eq($entry['family'] . '/' . $entry['id'], $entry['key']);
        assert_true(!isset($seen[$entry['key']]), "one row per archetype: {$entry['key']}");
        $seen[$entry['key']] = true;
        // Every archetype describes itself: an empty summary means the prompt
        // fragment moved and the gallery would show a blank card.
        assert_true($entry['summary'] !== '', "{$entry['key']} has a summary");
    }

    // The counts are the catalogs' own, so a recipe added or retired anywhere
    // reaches the gallery without touching this class.
    $counts = ArchetypeCatalog::counts();
    assert_eq(count(\Automattic\SiteBuild\HeroComposition::RECIPES), $counts['hero']);
    assert_eq(count(\Automattic\SiteBuild\SectionComposition::ARCHETYPES), $counts['section']);
    assert_eq(count(\Automattic\SiteBuild\FooterComposition::ARCHETYPES), $counts['footer']);
    assert_eq(count(\Automattic\SiteBuild\AboveFoldContract::HEADER_ARCHETYPES), $counts['header']);
});

test('a proposal is normalized and its unsafe forms refused', function () {
    $scope = ArchetypeProposals::scopeClass('section', 'quote-band');
    $record = [
        'id' => 'Quote-Band',
        'family' => 'SECTION',
        'title' => 'quote-band — one oversized line',
        'idea' => 'One attributed line as its own band.',
        'why_new' => 'Nothing in the catalog is a short band.',
        'built_from' => 'One group, one quote block.',
        'risk' => 'Needs a real quotation.',
        'mockup' => [
            'html' => '<div class="preview ' . $scope . '"><p class="h">A line</p></div>',
            'css' => ".{$scope} { display: grid; } .{$scope} .h { font-size: 6cqw; }",
        ],
        'origin' => 'invented-origin',
    ];
    $clean = ArchetypeProposals::validate($record);
    assert_eq('quote-band', $clean['id'], 'id is lowercased');
    assert_eq('section', $clean['family']);
    assert_eq('hand', $clean['origin'], 'an unknown origin falls back rather than throwing');
    assert_eq($scope, $clean['mockup']['scope']);

    $withScript = $record;
    $withScript['mockup']['html'] = '<div class="preview ' . $scope . '"><script>fetch("/")</script></div>';
    assert_throws(fn () => ArchetypeProposals::validate($withScript));

    $withHandler = $record;
    $withHandler['mockup']['html'] = '<div class="preview ' . $scope . '" onclick="steal()"></div>';
    assert_throws(fn () => ArchetypeProposals::validate($withHandler));

    $remote = $record;
    $remote['mockup']['css'] = ".{$scope} { background: url(https://example.com/x.png); }";
    assert_throws(fn () => ArchetypeProposals::validate($remote));

    // The mockup renders inside the gallery, so a rule that escapes its own
    // card would restyle the tool around it.
    $unscoped = $record;
    $unscoped['mockup']['css'] = 'body { display: none; }';
    assert_throws(fn () => ArchetypeProposals::validate($unscoped));

    $missing = $record;
    unset($missing['risk']);
    assert_throws(fn () => ArchetypeProposals::validate($missing));

    $badId = $record;
    $badId['id'] = 'not a slug';
    assert_throws(fn () => ArchetypeProposals::validate($badId));
});

test('scoped media queries and comments survive proposal validation', function () {
    $scope = ArchetypeProposals::scopeClass('hero', 'quiet-fold');
    $record = [
        'id' => 'quiet-fold', 'family' => 'hero', 'title' => 't', 'idea' => 'i',
        'why_new' => 'w', 'built_from' => 'b', 'risk' => 'r',
        'mockup' => [
            'html' => '<div class="preview ' . $scope . '"></div>',
            'css' => "/* a note about the shape */\n.{$scope} { display: flex; }\n"
                . "@media (max-width: 600px) { .{$scope} { display: block; } }",
        ],
    ];
    $clean = ArchetypeProposals::validate($record);
    assert_contains('@media', $clean['mockup']['css']);
});

test('the proposal store round-trips a record and skips a broken file', function () {
    $dir = sys_get_temp_dir() . '/archetype_proposals_' . uniqid();
    mkdir($dir, 0o777, true);
    $store = new ArchetypeProposals($dir);
    $scope = ArchetypeProposals::scopeClass('section', 'stat-band');
    $file = $store->save([
        'id' => 'stat-band', 'family' => 'section', 'title' => 'stat-band — figures',
        'idea' => 'Three numerals.', 'why_new' => 'No band is numeric.',
        'built_from' => 'columns + headings', 'risk' => 'invented numbers',
        'mockup' => ['html' => '<div class="preview ' . $scope . '"></div>', 'css' => ".{$scope} { gap: 2cqw; }"],
        'origin' => 'auto',
    ]);
    assert_true(is_file($file));
    assert_eq(['stat-band'], $store->idsFor('section'));

    file_put_contents($dir . '/broken.json', '{ not json');
    file_put_contents($dir . '/unsafe.json', json_encode([
        'id' => 'unsafe', 'family' => 'section', 'title' => 't', 'idea' => 'i', 'why_new' => 'w',
        'built_from' => 'b', 'risk' => 'r',
        'mockup' => ['html' => '<script>x</script>', 'css' => 'body{}'],
    ]));
    // One bad record on disk must not stop the gallery opening.
    assert_eq(1, count($store->all()));

    exec('rm -rf ' . escapeshellarg($dir));
});

test('the gallery renders every archetype, every proposal, and only composes when served', function () {
    $entries = ArchetypeCatalog::entries();
    $scope = ArchetypeProposals::scopeClass('section', 'quote-band');
    $proposals = [ArchetypeProposals::validate([
        'id' => 'quote-band', 'family' => 'section', 'title' => 'quote-band — one line',
        'idea' => 'One attributed line.', 'why_new' => 'w', 'built_from' => 'b', 'risk' => 'r',
        'mockup' => [
            'html' => '<div class="preview ' . $scope . '"><p>QUOTE-MOCKUP-SENTINEL</p></div>',
            'css' => ".{$scope} { display: grid; }",
        ],
    ])];
    $shots = ['width' => 1366, 'shots' => [
        $entries[0]['key'] => ['file' => 'shot.webp', 'site' => 'SHOT-SITE-SENTINEL', 'slug' => 'demo'],
    ]];

    $html = ArchetypeGallery::render($entries, $shots, $proposals, live: true);
    foreach ($entries as $entry) {
        assert_contains('>' . $entry['id'] . '<', $html, "{$entry['key']} has a card");
    }
    assert_contains('QUOTE-MOCKUP-SENTINEL', $html, 'the mockup markup is inlined');
    assert_contains('.' . $scope . ' { display: grid; }', $html, 'the mockup css is inlined');
    assert_contains('SHOT-SITE-SENTINEL', $html, 'a committed shot is credited to its site');
    assert_contains('id="compose-run"', $html, 'the served page offers the composer');
    assert_contains('/api/propose', $html);

    // Opened from disk there is no server to answer, so the composer is not
    // offered at all rather than offered and broken.
    $offline = ArchetypeGallery::render($entries, $shots, $proposals, live: false);
    assert_true(!str_contains($offline, 'id="compose-run"'), 'a file:// page offers no composer');
    assert_contains('Composing needs the dev server', $offline);
});
