<?php
declare(strict_types=1);

// DirectionHistory: the cross-build memory of chosen design directions.

/** @return array<string,mixed> a normalized-shape direction with a distinguishing title */
function dh_direction(string $title): array
{
    return [
        'title'            => $title,
        'description'      => 'A vision.',
        'palette'          => ['base' => '#FDF6EC', 'accent' => '#E08A3C'],
        'type'             => ['heading' => 'Fraunces 900', 'body' => 'Source Sans 3 400'],
        'image_grade'      => 'warm kodachrome',
        'signature_device' => 'hairline rules',
        'hero_composition' => 'headline lower-left',
    ];
}

test('history appends entries and returns the most recent first', function () {
    $tmp = sys_get_temp_dir() . '/builder_dh_' . uniqid();
    mkdir($tmp);
    $history = new DirectionHistory($tmp . '/.direction-history.json');

    assert_eq([], $history->recent(8), 'empty before any append');

    $history->append(dh_direction('First'));
    $history->append(dh_direction('Second'));
    $history->append(dh_direction('Third'));

    $recent = $history->recent(2);
    assert_eq(2, count($recent));
    assert_eq('Third', $recent[0]['title']);
    assert_eq('Second', $recent[1]['title']);
    assert_true(isset($recent[0]['chosen_at']), 'entries are timestamped');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('history keeps only the fingerprint fields and caps stored entries', function () {
    $tmp = sys_get_temp_dir() . '/builder_dh_' . uniqid();
    mkdir($tmp);
    $path = $tmp . '/.direction-history.json';
    $history = new DirectionHistory($path);

    for ($i = 1; $i <= 25; $i++) {
        $history->append(dh_direction("Dir {$i}"));
    }

    $stored = json_decode((string) file_get_contents($path), true);
    assert_eq(20, count($stored), 'capped at 20 entries');
    assert_eq('Dir 25', $stored[19]['title'], 'newest kept');
    assert_eq('Dir 6', $stored[0]['title'], 'oldest dropped');
    // The full description is NOT stored — only the fingerprint.
    assert_true(!isset($stored[0]['description']), 'no description in history');
    assert_eq('Fraunces 900', $stored[0]['type']['heading']);
    assert_eq('headline lower-left', $stored[0]['hero_composition']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('history tolerates a missing or corrupt file', function () {
    $tmp = sys_get_temp_dir() . '/builder_dh_' . uniqid();
    mkdir($tmp);
    $path = $tmp . '/.direction-history.json';
    file_put_contents($path, '{not json');

    $history = new DirectionHistory($path);
    assert_eq([], $history->recent(8), 'corrupt file reads as empty');
    $history->append(dh_direction('Fresh'));
    assert_eq('Fresh', $history->recent(1)[0]['title'], 'append recovers the file');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('renderForPrompt lists each entry with type, palette and hero, and handles empty history', function () {
    assert_contains('none recorded', DirectionHistory::renderForPrompt([]));

    $text = DirectionHistory::renderForPrompt([
        DirectionHistory::entryFor(dh_direction('Archivo Silencioso')),
        ['title' => 'Bare Entry'],
    ]);
    assert_contains('"Archivo Silencioso"', $text);
    assert_contains('Fraunces 900', $text);
    assert_contains('#FDF6EC', $text);
    assert_contains('hero: headline lower-left', $text);
    assert_contains('"Bare Entry"', $text);
});

test('forProject stores the history beside the project directories', function () {
    $tmp = sys_get_temp_dir() . '/builder_dh_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    DirectionHistory::forProject($project)->append(dh_direction('Beside'));

    assert_true(is_file($tmp . '/.direction-history.json'), 'history sits in the projects root, not the project');

    exec('rm -rf ' . escapeshellarg($tmp));
});
