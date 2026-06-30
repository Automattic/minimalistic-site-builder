<?php
declare(strict_types=1);

test('render expands a {{> partial}} include before variable substitution', function () {
    $dir = sys_get_temp_dir() . '/builder_inc_' . uniqid();
    @mkdir($dir . '/partials', 0775, true);
    file_put_contents($dir . '/partials/shared.md', 'SHARED for {{topic}}');
    file_put_contents($dir . '/main.md', "Top\n{{> partials/shared.md}}\nEnd");

    $out = (new PromptRenderer($dir))->render('main.md', ['topic' => 'coffee']);
    assert_contains('SHARED for coffee', $out);
    assert_contains('Top', $out);
    assert_contains('End', $out);

    exec('rm -rf ' . escapeshellarg($dir));
});

test('render fails loud on a missing include', function () {
    $dir = sys_get_temp_dir() . '/builder_inc_' . uniqid();
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/main.md', '{{> partials/nope.md}}');
    assert_throws(function () use ($dir) {
        (new PromptRenderer($dir))->render('main.md', []);
    });
    exec('rm -rf ' . escapeshellarg($dir));
});

test('the real design prompts resolve their includes and all placeholders', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    // design-direction uses the aesthetics partial; if includes or vars break,
    // render() throws. A successful render proves the partials are wired.
    $out = $renderer->render('design-direction.md', [
        'user_prompt' => 'A test brief',
        'site_spec'   => '{}',
    ]);
    assert_contains('AI slop', $out);          // came from partials/aesthetics.md
    assert_contains('Banned font families', $out);
    assert_contains('A test brief', $out);     // the variable substituted
});
