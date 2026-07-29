<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;

/** Read everything written to an in-memory stream. */
function narrator_test_read(mixed $stream): string
{
    rewind($stream);
    return (string) stream_get_contents($stream);
}

test('narration goes to the injected stream verbatim', function () {
    $sink = fopen('php://memory', 'w+');
    Narrator::setStream($sink);
    try {
        Narrator::write("    (part 'hero': salvaged)\n");
        Narrator::write('no trailing newline');
        assert_eq("    (part 'hero': salvaged)\nno trailing newline", narrator_test_read($sink));
    } finally {
        Narrator::reset();
        fclose($sink);
    }
});

test('a closed injected stream falls back instead of fataling', function () {
    // The production failure this class exists for: a host hands over a stream
    // (or PHP defines STDERR) and the process closes it mid-run. The handle is
    // still defined and non-null, but no longer a valid resource, so writing to
    // it raises "supplied resource is not a valid stream resource".
    $dead = fopen('php://memory', 'w+');
    Narrator::setStream($dead);
    fclose($dead);
    try {
        Narrator::write("still narrating\n");
        assert_true(Narrator::stream() !== null, 'expected a live fallback target');
        assert_true(is_resource(Narrator::stream()), 'fallback target must be a valid resource');
    } finally {
        Narrator::reset();
    }
});

test('a stream that dies mid-run is re-resolved on the next write', function () {
    $sink = fopen('php://memory', 'w+');
    Narrator::setStream($sink);
    try {
        Narrator::write("before\n");
        assert_eq("before\n", narrator_test_read($sink));

        fclose($sink);

        // Definedness has not changed — only liveness. A guard that checked
        // defined()/null would hand back the dead handle here.
        Narrator::write("after\n");
        assert_true(is_resource(Narrator::stream()), 'expected re-resolution after the target died');
    } finally {
        Narrator::reset();
    }
});

test('setStream rejects a non-resource and keeps resolving a default', function () {
    Narrator::setStream(null);
    try {
        assert_true(is_resource(Narrator::stream()), 'null override must fall back to a default target');
        Narrator::write("no override\n");
    } finally {
        Narrator::reset();
    }
});

test('disabled narration writes nothing and stays disabled until reset', function () {
    $sink = fopen('php://memory', 'w+');
    Narrator::setStream($sink);
    Narrator::setEnabled(false);
    try {
        Narrator::write("suppressed\n");
        assert_eq('', narrator_test_read($sink));
        assert_true(!Narrator::enabled(), 'narration should report itself disabled');

        Narrator::setEnabled(true);
        Narrator::write("audible\n");
        assert_eq("audible\n", narrator_test_read($sink));
    } finally {
        Narrator::reset();
        fclose($sink);
    }
});

test('reset clears the override and re-enables narration', function () {
    $sink = fopen('php://memory', 'w+');
    Narrator::setStream($sink);
    Narrator::setEnabled(false);
    Narrator::reset();
    try {
        assert_true(Narrator::enabled(), 'reset must re-enable narration');
        Narrator::write("after reset\n");
        assert_eq('', narrator_test_read($sink), 'reset must drop the injected stream');
    } finally {
        Narrator::reset();
        fclose($sink);
    }
});

test('an empty message is a no-op', function () {
    $sink = fopen('php://memory', 'w+');
    Narrator::setStream($sink);
    try {
        Narrator::write('');
        assert_eq('', narrator_test_read($sink));
    } finally {
        Narrator::reset();
        fclose($sink);
    }
});

test('no library code writes to the STDERR constant directly', function () {
    // The first migration to Narrator converted the single-line
    // `fwrite(STDERR, "…")` calls and missed every multi-line one, where
    // STDERR sits on its own line. Those survivors are unreachable by eye and
    // only fail on a host whose standard streams are closed — a long-lived
    // job worker — so the miss shipped looking clean. Assert on the tree
    // instead of trusting the next migration to be thorough.
    $offenders = [];
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(repo_path('src')));
    foreach ($dir as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'Narrator.php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        // Strip comments so prose about STDERR does not trip the check.
        $stripped = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }
        if (preg_match('/\bSTDERR\b/', $stripped)) {
            $offenders[] = $file->getFilename();
        }
    }
    assert_eq([], $offenders);
});
