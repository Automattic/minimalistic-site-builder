<?php
declare(strict_types=1);

test('playground_ready_url reads the port off the readiness line', function () {
    $log = "Booting WordPress…\nReady! WordPress is running on http://127.0.0.1:9403\n";

    assert_eq('http://127.0.0.1:9403/', playground_ready_url($log));
});

test('playground_ready_url sees through the CLI colour escapes', function () {
    // What the CLI emits with colour on: "Ready!" bold, the URL underlined —
    // escapes sitting exactly where the readiness match needs whitespace.
    $log = "\x1b[1mReady!\x1b[0m WordPress is running on \x1b[4mhttp://127.0.0.1:9400\x1b[0m\n";

    assert_eq('http://127.0.0.1:9400/', playground_ready_url($log));
});

test('playground_ready_url stays null until the server reports ready', function () {
    assert_eq(null, playground_ready_url(''));
    assert_eq(null, playground_ready_url("Downloading WordPress…\nSetting up the site\n"));
    // A URL alone is not readiness: playground.php prints the requested one
    // before booting, and it may not be the port the server lands on.
    assert_eq(null, playground_ready_url("  url:    http://127.0.0.1:9400/\n"));
});
