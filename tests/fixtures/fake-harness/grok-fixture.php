<?php
declare(strict_types=1);

/**
 * Shared fake Grok CLI implementation.
 *
 * argv: <mode> <copied-binary-path> <actual Grok argv...>
 */

$mode = (string) ($argv[1] ?? 'success');
$binary = (string) ($argv[2] ?? '');
$args = array_slice($argv, 3);
$stdin = stream_get_contents(STDIN);
if (!is_string($stdin)) {
    $stdin = '';
}

$promptIndex = array_search('--prompt-file', $args, true);
$promptPath = $promptIndex === false ? '' : (string) ($args[$promptIndex + 1] ?? '');
$prompt = $promptPath === '' ? false : file_get_contents($promptPath);
if (!is_string($prompt)) {
    fwrite(STDERR, "fake Grok could not read --prompt-file\n");
    exit(18);
}

$record = [
    'mode' => $mode,
    'argv' => $args,
    'stdin' => $stdin,
    'prompt_path' => $promptPath,
    'prompt' => $prompt,
];
$recordPath = $binary . '.records.jsonl';
$recordHandle = fopen($recordPath, 'ab');
if ($recordHandle === false || !flock($recordHandle, LOCK_EX)) {
    fwrite(STDERR, "fake Grok record lock failed\n");
    exit(19);
}
try {
    fwrite(
        $recordHandle,
        json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
    );
    fflush($recordHandle);
} finally {
    flock($recordHandle, LOCK_UN);
    fclose($recordHandle);
}

if ($mode === 'nonzero') {
    fwrite(STDERR, "fake Grok non-zero diagnostic\n");
    exit(17);
}
if ($mode === 'malformed') {
    echo "not a Grok JSON object\n";
    exit(0);
}

$envelope = [
    'usage' => [
        'input_tokens' => 24066,
        'cache_read_input_tokens' => 5760,
        'cache_creation_input_tokens' => 0,
        'output_tokens' => 31,
        'reasoning_tokens' => 24,
        'total_tokens' => 29857,
    ],
    // Deliberately conflicts with top-level usage. Transport must ignore it.
    'modelUsage' => [
        'wrong-model' => [
            'input_tokens' => 900001,
            'cache_read_input_tokens' => 900002,
            'output_tokens' => 900003,
        ],
    ],
    'stopReason' => $mode === 'truncated' ? 'max_tokens' : 'end_turn',
    'total_cost_usd' => 0.123,
    'sessionId' => 'fake-session',
    'requestId' => 'fake-request',
];
if ($mode !== 'missing-text') {
    $envelope['text'] = $prompt;
}

echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
