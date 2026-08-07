<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\SectionCopyDedupeStep;

function copy_dedupe_step_part(string $label, bool $malformed = false): string
{
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Framing copy.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase"}}} -->'
        . '<p style="text-transform:uppercase">' . $label . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    return $malformed ? $markup . '<!-- /wp:quote -->' : $markup;
}

test('copy dedupe step preserves a malformed page and warns with file context', function () {
    $tmp = sys_get_temp_dir() . '/builder_copy_dedupe_bad_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'sections' => [
            ['slug' => 'one'],
            ['slug' => 'two'],
        ]],
        ['slug' => 'broken', 'sections' => [
            ['slug' => 'one'],
            ['slug' => 'two'],
        ]],
    ]]);
    $project->writeText('theme/parts/page-home--one.html', copy_dedupe_step_part('Open daily until late'));
    $project->writeText('theme/parts/page-home--two.html', copy_dedupe_step_part('Open daily until late'));
    $project->writeText('theme/parts/page-broken--one.html', copy_dedupe_step_part('Open daily until late'));
    $broken = copy_dedupe_step_part('Open daily until late', true);
    $project->writeText('theme/parts/page-broken--two.html', $broken);

    (new SectionCopyDedupeStep())->run($project);

    assert_true(
        !str_contains($project->readText('theme/parts/page-home--two.html'), 'Open daily until late'),
        'healthy sibling page is still deduped'
    );
    assert_eq(
        $broken,
        $project->readText('theme/parts/page-broken--two.html'),
        'malformed page remains byte-for-byte authored'
    );
    assert_contains(
        "file 'theme/parts/page-broken--two.html' has malformed block structure",
        implode(' ', $project->readJson('warnings.json')['copy-dedupe'] ?? [])
    );
    assert_contains(
        'all authored page copy delivered byte-for-byte',
        implode(' ', $project->readJson('warnings.json')['copy-dedupe'] ?? [])
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('copy dedupe step persists capped residuals with authored and delivered values', function () {
    $tmp = sys_get_temp_dir() . '/builder_copy_dedupe_cap_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $sections = [];
    foreach (range(1, 6) as $i) {
        $sections[] = ['slug' => "section-{$i}"];
        $project->writeText(
            "theme/parts/page-home--section-{$i}.html",
            copy_dedupe_step_part('Open daily until late')
        );
    }
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'sections' => $sections],
    ]]);

    (new SectionCopyDedupeStep())->run($project);

    $warnings = implode(' ', $project->readJson('warnings.json')['copy-dedupe'] ?? []);
    assert_contains("file 'theme/parts/page-home--section-6.html'", $warnings);
    assert_contains('authored value "Open daily until late" delivered unchanged', $warnings);
    assert_contains('disposition=duplicate retained', $warnings);
    assert_contains('page was preserved transactionally', $warnings);
    assert_contains(
        'Open daily until late',
        $project->readText('theme/parts/page-home--section-6.html'),
        'capped duplicate remains in the delivered file'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});
