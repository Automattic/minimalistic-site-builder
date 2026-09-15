<?php
declare(strict_types=1);

use Automattic\SiteBuild\ArchetypeCatalog;
use Automattic\SiteBuild\ArchetypeGallery;
use Automattic\SiteBuild\ArchetypeMockups;
use Automattic\SiteBuild\ArchetypeProposals;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;

test('the archetype catalog flattens all four code-owned catalogs into one row shape', function () {
    $entries = ArchetypeCatalog::entries();
    assert_true($entries !== []);

    $seen = [];
    foreach ($entries as $entry) {
        foreach (['family', 'id', 'key', 'brief', 'summary', 'source', 'facts', 'note'] as $field) {
            assert_true(array_key_exists($field, $entry), "row has {$field}");
        }
        assert_true(in_array($entry['family'], ArchetypeCatalog::FAMILIES, true), $entry['family']);
        assert_eq($entry['family'] . '/' . $entry['id'], $entry['key']);
        assert_true(!isset($seen[$entry['key']]), "one row per archetype: {$entry['key']}");
        $seen[$entry['key']] = true;
        // Every archetype describes itself: an empty summary means the prompt
        // fragment moved and the gallery would show a blank card.
        assert_true($entry['summary'] !== '', "{$entry['key']} has a summary");
        // The card leads with the brief, so it must be a readable opening line
        // rather than the whole fragment.
        assert_true($entry['brief'] !== '', "{$entry['key']} has a brief");
        assert_true(mb_strlen($entry['brief']) <= 211, "{$entry['key']} brief is short");
        assert_true(
            str_starts_with($entry['summary'], rtrim($entry['brief'], '…')),
            "{$entry['key']} brief opens the summary",
        );
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

test('a mockup may not paint outside its own card', function () {
    $scope = ArchetypeProposals::scopeClass('hero', 'probe');
    $record = [
        'id' => 'probe', 'family' => 'hero', 'title' => 't', 'idea' => 'i',
        'why_new' => 'w', 'built_from' => 'b', 'risk' => 'r',
        'mockup' => ['html' => '<div class="preview ' . $scope . '"></div>', 'css' => ".{$scope} { display: grid; }"],
    ];
    $with = static function (string $part, string $value) use ($record): array {
        $record['mockup'][$part] = $value;
        return $record;
    };

    // A stylesheet inside the markup would never reach the scope check, which
    // reads the css field only, and it applies to the whole document.
    assert_throws(fn () => ArchetypeProposals::validate(
        $with('html', '<div class="preview ' . $scope . '"><style>body{display:none}</style></div>')
    ), 'a style element in the markup');
    // The scope class appears, but only inside an argument list: the rule
    // matches the document, not the card.
    assert_throws(fn () => ArchetypeProposals::validate(
        $with('css', "body:has(.{$scope}) { display: none; }")
    ), 'an ancestor selected through :has()');
    // Shares a prefix with the scope class and is a different class.
    assert_throws(fn () => ArchetypeProposals::validate(
        $with('css', ".{$scope}-wide { display: none; }")
    ), 'a class that merely starts the same');
    assert_throws(fn () => ArchetypeProposals::validate(
        $with('css', "body .{$scope} { display: none; }")
    ), 'a rule anchored on the document');
    assert_throws(fn () => ArchetypeProposals::validate(
        $with('css', ".{$scope} { color: red; } .other { display: none; }")
    ), 'one unscoped rule among scoped ones');

    // Everything a real mockup needs still passes.
    foreach ([
        ".{$scope} { display: grid; }",
        ".{$scope} .row { gap: 2cqw; }",
        ".{$scope}:hover .row { opacity: 1; }",
        ".{$scope}:not(.x) .h { color: red; }",
        ".{$scope} > .row + .row { margin: 0; }",
        ".{$scope} :is(.a, .b) { gap: 1cqw; }",
        ".{$scope} .a, .{$scope} .b { gap: 1cqw; }",
        "@media (max-width: 600px) { .{$scope} { display: block; } }",
    ] as $css) {
        $clean = ArchetypeProposals::validate($with('css', $css));
        assert_eq($css, $clean['mockup']['css'], "accepted: {$css}");
    }
});

test('a proposal carries a status so a settled one stops being offered', function () {
    $dir = sys_get_temp_dir() . '/archetype_status_' . uniqid();
    mkdir($dir, 0o777, true);
    $store = new ArchetypeProposals($dir);
    $scope = ArchetypeProposals::scopeClass('hero', 'knockout-type');
    $record = [
        'id' => 'knockout-type', 'family' => 'hero', 'title' => 't', 'idea' => 'i',
        'why_new' => 'w', 'built_from' => 'b', 'risk' => 'r',
        'mockup' => ['html' => '<div class="preview ' . $scope . '"></div>', 'css' => ".{$scope} { display: grid; }"],
    ];
    assert_eq('waiting', ArchetypeProposals::validate($record)['status'], 'a record with no status is waiting');
    assert_eq(
        'waiting',
        ArchetypeProposals::validate($record + ['status' => 'invented'])['status'],
        'an unknown status falls back rather than throwing',
    );

    $store->save($record);
    $store->setStatus('hero', 'knockout-type', 'dropped', 'merged in #393, reverted in #399');
    $stored = $store->all()[0];
    assert_eq('dropped', $stored['status']);
    assert_eq('merged in #393, reverted in #399', $stored['status_note']);
    // The record stays on disk on purpose: deleting it is what makes the
    // variety pass draw the same idea again.
    assert_eq(['knockout-type'], $store->idsFor('hero'));
    assert_throws(fn () => $store->setStatus('hero', 'knockout-type', 'nonsense'));
    assert_throws(fn () => $store->setStatus('hero', 'never-proposed', 'built'));

    exec('rm -rf ' . escapeshellarg($dir));
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
    assert_contains('One example only', $html, 'a single-sample archetype says so');
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

test('the gallery reads both shot-index shapes and reports what the catalog cannot show', function () {
    $entries = ArchetypeCatalog::entries();
    $first = $entries[0]['key'];
    $second = $entries[1]['key'];

    // The first version of the tool wrote one object per archetype; it still
    // reads, because the index is committed data and a reader that breaks on
    // yesterday's file is a reader nobody can upgrade past.
    $normalized = ArchetypeGallery::normalizeShots([
        $first => ['file' => 'a.webp', 'site' => 'One'],
        $second => [['file' => 'b.webp', 'site' => 'Two'], ['file' => 'b2.webp', 'site' => 'Three']],
        'section/junk' => 'not an entry',
    ]);
    assert_eq(1, count($normalized[$first]), 'a lone object becomes a one-item list');
    assert_eq(2, count($normalized[$second]));
    assert_true(!isset($normalized['section/junk']), 'an unreadable entry is dropped');

    $shots = ['width' => 1366, 'shots' => [
        $second => [
            ['file' => 'b.webp', 'site' => 'SITE-TWO-SENTINEL', 'slug' => 'two'],
            ['file' => 'b2.webp', 'site' => 'SITE-THREE-SENTINEL', 'slug' => 'three'],
        ],
        // BIGR-912 merged a hero recipe away; its shot outlives the catalog
        // row, and the gallery draws catalog rows, so nothing would show it.
        'hero/editorial-split' => [['file' => 'stale.webp', 'site' => 'Gone', 'slug' => 'old']],
    ]];
    $html = ArchetypeGallery::render($entries, $shots, [], live: false);
    assert_contains('SITE-TWO-SENTINEL', $html, 'every example of an archetype is drawn');
    assert_contains('SITE-THREE-SENTINEL', $html);
    assert_contains('2 examples', $html, 'the card counts its examples');
    assert_contains('Stale shots', $html, 'a shot of a retired archetype is reported');
    assert_contains('hero/editorial-split', $html, 'and named');
});

test('the model draw is re-scoped to the id the record ships with', function () {
    $llm = new FakeLlm();
    $draft = ArchetypeProposals::scopeClass('section', 'draft');
    // The catalog already owns this id, so the record must not take it.
    $taken = ArchetypeCatalog::entries();
    $collision = null;
    foreach ($taken as $entry) {
        if ($entry['family'] === 'section') {
            $collision = $entry['id'];
            break;
        }
    }
    $llm->queueJson([
        'id' => strtoupper($collision),
        'title' => 't', 'idea' => 'i', 'why_new' => 'w', 'built_from' => 'b', 'risk' => 'r',
        'mockup' => [
            'html' => '<div class="preview ' . $draft . '"><p class="h">A</p></div>',
            'css' => ".{$draft} { display: grid; } .{$draft} .h { font-size: 6cqw; }",
        ],
    ]);

    $mockups = new ArchetypeMockups($llm, new PromptRenderer(repo_path('prompts')));
    $record = $mockups->draw('section', $taken, [], 'a band that does something new');

    assert_eq($collision . '-2', $record['id'], 'a colliding id is renamed, not refused');
    assert_eq('section', $record['family']);
    assert_eq('prompt', $record['origin'], 'a request makes it a prompted draw');
    assert_eq('waiting', $record['status'], 'a fresh draw joins the queue');
    assert_eq('a band that does something new', $record['prompt']);

    // The model wrote against the draft scope because it could not know the
    // final id; a card whose css still named the draft would style nothing.
    $scope = ArchetypeProposals::scopeClass('section', $record['id']);
    assert_eq($scope, $record['mockup']['scope']);
    assert_contains('.' . $scope . ' .h', $record['mockup']['css']);
    assert_contains('class="preview ' . $scope . '"', $record['mockup']['html']);
    assert_true(!str_contains($record['mockup']['css'], $draft), 'no draft scope survives');

    // The variety pass gets no request and is told so.
    $llm->queueJson([
        'id' => 'wholly-new', 'title' => 't', 'idea' => 'i', 'why_new' => 'w',
        'built_from' => 'b', 'risk' => 'r',
        'mockup' => ['html' => '<div class="preview ' . $draft . '"></div>', 'css' => ".{$draft} { gap: 1cqw; }"],
    ]);
    $auto = $mockups->draw('section', $taken, [$record]);
    assert_eq('auto', $auto['origin']);
    assert_eq('wholly-new', $auto['id']);
    assert_contains('Find the widest gap', $llm->calls[1]['prompt'], 'the variety pass states its own brief');
    assert_contains($record['id'], $llm->calls[1]['prompt'], 'and lists what is already proposed');
});
