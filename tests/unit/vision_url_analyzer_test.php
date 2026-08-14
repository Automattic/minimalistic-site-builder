<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\ChromeScreenshotCapture;
use Automattic\SiteBuild\ImageInput;
use Automattic\SiteBuild\InspirationBrief;
use Automattic\SiteBuild\InspirationUrls;
use Automattic\SiteBuild\VisionUrlAnalyzer;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\ScreenshotCapture;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * A ScreenshotCapture whose child process is a stub script instead of Chrome,
 * so the real proc_open/pipe/collect path runs without launching a browser.
 */
function stub_capture(string $dir, string $behavior, int $slices = 2): ScreenshotCapture
{
    $script = $dir . '/stub-screenshot.js';
    file_put_contents($script, <<<JS
    const fs = require('fs');
    const path = require('path');
    const behavior = {$behavior};
    const out = process.argv[3];
    const slices = Number((process.argv.find((a) => a.startsWith('--slices=')) || '--slices=1').slice(9));
    if (behavior === 'fail') { process.stderr.write('stub: could not reach the page\\n'); process.exit(1); }
    if (behavior === 'silent') { process.exit(0); }
    const ext = path.extname(out);
    const stem = out.slice(0, out.length - ext.length);
    for (let i = 1; i <= slices; i++) {
      const p = `\${stem}-\${i}\${ext}`;
      fs.writeFileSync(p, `PNG-BYTES-\${i}`);
      process.stdout.write(`\${p}\\n`);
    }
    JS);
    return new ChromeScreenshotCapture(outputDir: $dir, script: $script, timeout: 30, slices: $slices);
}

/**
 * A ScreenshotCapture with no browser and no child process, standing in for a
 * host that has some other way to screenshot a page.
 */
final class PrewrittenCapture implements ScreenshotCapture
{
    public function __construct(private string $dir, private int $slices = 2)
    {
    }

    public function capture(array $urls): array
    {
        $out = [];
        foreach (array_values(array_unique($urls)) as $index => $url) {
            $slices = [];
            for ($n = 1; $n <= $this->slices; $n++) {
                $path = $this->dir . '/prewritten-' . $index . '-' . $n . '.png';
                file_put_contents($path, 'PNG-BYTES-' . $n);
                $slices[] = $path;
            }
            $out[$url] = ['slices' => $slices, 'error' => null];
        }
        return $out;
    }
}

/** @return array<string,mixed> a describe-shaped response the brief gate accepts */
function describe_response(string $style = 'Bold brutalist type on off-white'): array
{
    return [
        'page_type' => 'store',
        'owner_type' => 'business',
        'style' => $style,
        'colors' => [['hex' => '#ff90e8', 'name' => 'hot pink', 'role' => 'accent']],
        'sections' => [['category' => 'hero', 'description' => 'Oversized headline over a pale ground']],
    ];
}

test('ImageInput accepts a well-formed image and reports what is wrong otherwise', function () {
    assert_eq([], ImageInput::normalize(['prompt' => 'no images here']));

    $ok = ImageInput::normalize(['images' => [['bytes' => 'RAW', 'mime' => 'IMAGE/PNG']]]);
    assert_eq([['bytes' => 'RAW', 'mime' => 'image/png']], $ok, 'mime is normalized to lowercase');

    assert_contains('must be a list', assert_throws(
        static fn () => ImageInput::normalize(['images' => ['png' => ['bytes' => 'x', 'mime' => 'image/png']]]),
    )->getMessage());
    assert_contains('non-empty string', assert_throws(
        static fn () => ImageInput::normalize(['images' => [['bytes' => '', 'mime' => 'image/png']]]),
    )->getMessage());
    assert_contains('mime must be one of', assert_throws(
        static fn () => ImageInput::normalize(['images' => [['bytes' => 'x', 'mime' => 'image/tiff']]]),
    )->getMessage());
    assert_contains('over the', assert_throws(
        static fn () => ImageInput::normalize(['images' => [['bytes' => str_repeat('x', 3_750_001), 'mime' => 'image/png']]]),
    )->getMessage(), 'an oversized image is named here, not by an opaque HTTP 400');
    assert_contains('at most 8 images', assert_throws(
        static fn () => ImageInput::normalize([
            'images' => array_fill(0, 9, ['bytes' => 'x', 'mime' => 'image/png']),
        ]),
    )->getMessage());
});

test('Anthropic bodyFor puts images ahead of the prompt and after any cached prefix', function () {
    $body = AnthropicClient::bodyFor(
        ['prompt' => 'describe it', 'images' => [['bytes' => 'RAW', 'mime' => 'image/png']]],
        'claude-test',
        1024,
    );
    $content = $body['messages'][0]['content'];
    assert_eq(2, count($content));
    assert_eq('image', $content[0]['type']);
    assert_eq('base64', $content[0]['source']['type']);
    assert_eq('image/png', $content[0]['source']['media_type']);
    assert_eq(base64_encode('RAW'), $content[0]['source']['data'], 'the client encodes; callers pass raw bytes');
    assert_eq(['type' => 'text', 'text' => 'describe it'], $content[1]);

    $cached = AnthropicClient::bodyFor(
        [
            'prompt' => 'describe it',
            'images' => [['bytes' => 'RAW', 'mime' => 'image/png']],
            'cached_prefixes' => ["reusable layer\n"],
        ],
        'claude-test',
        1024,
    );
    $types = array_map(static fn (array $b): string => $b['type'], $cached['messages'][0]['content']);
    assert_eq(['text', 'image', 'text'], $types, 'a varying image must not sit inside the cached prefix');
    assert_eq(
        ['type' => 'ephemeral'],
        $cached['messages'][0]['content'][0]['cache_control'],
        'the prefix keeps its cache breakpoint',
    );
});

test('a prompt without images keeps the plain string content shape', function () {
    $body = AnthropicClient::bodyFor(['prompt' => 'plain'], 'claude-test', 1024);
    assert_eq('plain', $body['messages'][0]['content']);

    $openAi = OpenAiCompatibleClient::bodyFor(['prompt' => 'plain'], 'gpt-test', 1024);
    assert_eq('plain', $openAi['messages'][1]['content']);
});

test('OpenAI-compatible bodyFor sends images as data-URI blocks before the text', function () {
    $body = OpenAiCompatibleClient::bodyFor(
        ['prompt' => 'describe it', 'images' => [['bytes' => 'RAW', 'mime' => 'image/png']]],
        'gpt-test',
        1024,
    );
    $content = $body['messages'][1]['content'];
    assert_eq('image_url', $content[0]['type']);
    assert_eq('data:image/png;base64,' . base64_encode('RAW'), $content[0]['image_url']['url']);
    assert_eq(['type' => 'text', 'text' => 'describe it'], $content[1]);
    assert_eq('system', $body['messages'][0]['role'], 'the system message is untouched');
});

test('VisionUrlAnalyzer turns a captured page into a reference keyed by URL', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->queueJson(describe_response());
        $analyzer = new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'"));

        $result = $analyzer->analyze(['https://gumroad.com']);

        assert_eq([], $result['failures']);
        assert_eq(['https://gumroad.com'], array_keys($result['references']));
        $reference = $result['references']['https://gumroad.com'];
        assert_eq('https://gumroad.com', $reference['url']);
        assert_eq('store', $reference['page_type']);
        assert_eq('#ff90e8', $reference['colors'][0]['hex']);

        // One vision call carrying both captured slices as raw PNG bytes.
        assert_eq(1, $llm->completeJsonBatchCalls);
        assert_eq(1, count($llm->calls));
        $opts = $llm->calls[0]['opts'];
        assert_eq(2, count($opts['images']));
        assert_eq('PNG-BYTES-1', $opts['images'][0]['bytes']);
        assert_eq('image/png', $opts['images'][0]['mime']);
        assert_eq('describePage', $opts['json_schema']['name']);
    });
});

test('VisionUrlAnalyzer asks for a design brief, not a description', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->queueJson(describe_response());
        (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))->analyze(['https://example.com']);
        $prompt = $llm->calls[0]['prompt'];

        // Asking for a precise description returns transcription a design step
        // cannot act on; asking for a brief returns something it can.
        assert_contains('design brief another designer could build from', $prompt);
        assert_contains('capture the FEEL', $prompt);
        // Without this the brief reproduces the reference's own headlines.
        assert_contains('Never reproduce', $prompt);
        assert_contains('ORIGINAL design', $prompt);
        // A screenshot can carry a page's own injected instructions.
        assert_contains('untrusted third-party web page', $prompt);
    });
});

test('the brief schema adds the dimensions describe has no field for', function () {
    $schema = VisionUrlAnalyzer::schema()['schema'];

    assert_eq(
        ['page_type', 'owner_type', 'style', 'typography', 'layout', 'mood', 'colors', 'sections'],
        array_keys($schema['properties']),
    );
    // Typography is absent from the endpoint's schema entirely, so it only ever
    // surfaced when the model volunteered it inside free-form style.
    assert_eq(
        ['page_type', 'owner_type', 'style', 'typography', 'layout', 'sections'],
        $schema['required'],
    );
    assert_eq(false, in_array('colors', $schema['required'], true),
        'colors stays optional, matching the endpoint');
});

test('InspirationBrief carries the new dimensions and leaves them empty for a wpcom response', function () {
    $local = InspirationBrief::fromResponse('https://a.com', describe_response() + [
        'typography' => 'Condensed geometric sans; oversized tightly-tracked headlines',
        'layout' => 'Full-bleed alternating light and dark bands',
        'mood' => ['premium', 'aspirational', 'cinematic', 'bold', 'warm', 'sixth-is-dropped'],
    ]);
    assert_contains('Condensed geometric sans', $local['typography']);
    assert_contains('alternating light and dark', $local['layout']);
    assert_eq(5, count($local['mood']), 'mood is capped at five adjectives');
    assert_eq(false, in_array('sixth-is-dropped', $local['mood'], true));

    // The describe endpoint never sends these; its references stay valid.
    $wpcom = InspirationBrief::fromResponse('https://b.com', describe_response());
    assert_eq('', $wpcom['typography']);
    assert_eq('', $wpcom['layout']);
    assert_eq([], $wpcom['mood']);
});

test('VisionUrlAnalyzer carries its screenshots forward on the reference', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->queueJson(describe_response());
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))->analyze(['https://a.com']);

        $shots = $result['references']['https://a.com']['screenshots'];
        assert_eq(2, count($shots));
        foreach ($shots as $name) {
            assert_eq($name, basename($name), 'stored as basenames so a moved project still resolves them');
        }
    });
});

test('VisionUrlAnalyzer degrades a failed capture without calling the model', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $analyzer = new VisionUrlAnalyzer($llm, stub_capture($dir, "'fail'"));

        $result = $analyzer->analyze(['https://example.com']);

        assert_eq([], $result['references']);
        assert_eq('transport_error', $result['failures']['https://example.com']['kind']);
        assert_contains('could not reach the page', $result['failures']['https://example.com']['message']);
        assert_eq(0, $llm->completeJsonBatchCalls, 'no screenshot means nothing to look at');
    });
});

test('VisionUrlAnalyzer reports a capture that wrote nothing', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'silent'")))->analyze(['https://example.com']);

        assert_eq([], $result['references']);
        assert_eq('transport_error', $result['failures']['https://example.com']['kind']);
        assert_eq(0, $llm->completeJsonBatchCalls);
    });
});

test('VisionUrlAnalyzer applies the positive-evidence gate to what the model returns', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        // Neither colors nor sections: nothing usable to design from.
        $llm->queueJson(['page_type' => 'other', 'owner_type' => 'other', 'style' => 'plain', 'sections' => []]);
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))->analyze(['https://example.com']);

        assert_eq([], $result['references']);
        assert_eq('gate_rejected', $result['failures']['https://example.com']['kind']);
        assert_contains('neither usable colors nor sections', $result['failures']['https://example.com']['message']);
    });
});

test('VisionUrlAnalyzer keeps successful references when a sibling URL fails', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->queueJson(describe_response('First site'));
        $llm->queueJson(['error' => ['message' => 'vision could not read the capture']]);
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))
            ->analyze(['https://a.com', 'https://b.com']);

        assert_eq(['https://a.com'], array_keys($result['references']));
        assert_eq(['https://b.com'], array_keys($result['failures']));
        assert_eq('gate_rejected', $result['failures']['https://b.com']['kind']);
    });
});

test('VisionUrlAnalyzer never throws when the vision call fails outright', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->failPromptSubstrings = ['describe the page'];
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))->analyze(['https://example.com']);

        assert_eq([], $result['references']);
        assert_eq('transport_error', $result['failures']['https://example.com']['kind']);
        assert_contains('vision request failed', $result['failures']['https://example.com']['message']);
    });
});

test('VisionUrlAnalyzer analyzes each URL once and abandons the ones over the cap', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        for ($i = 0; $i < InspirationUrls::MAX; $i++) {
            $llm->queueJson(describe_response('Site ' . $i));
        }
        $urls = [];
        for ($i = 0; $i < InspirationUrls::MAX + 1; $i++) {
            $urls[] = 'https://site-' . $i . '.com';
        }
        // The duplicate must not consume one of the capped slots.
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'ok'")))
            ->analyze([...$urls, 'https://site-0.com']);

        assert_eq(InspirationUrls::MAX, count($result['references']));
        assert_eq(1, $llm->completeJsonBatchCalls, 'every URL goes in one concurrent batch');
        assert_eq(InspirationUrls::MAX, count($llm->calls), 'one request per analyzed URL');
        $overflow = 'https://site-' . InspirationUrls::MAX . '.com';
        assert_eq('abandoned', $result['failures'][$overflow]['kind']);
        assert_contains('maximum is ' . InspirationUrls::MAX, $result['failures'][$overflow]['message']);
    });
});

test('VisionUrlAnalyzer returns nothing for no URLs and never launches a browser', function () {
    with_temp_dir('local_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $result = (new VisionUrlAnalyzer($llm, stub_capture($dir, "'fail'")))->analyze([]);
        assert_eq(['references' => [], 'failures' => []], $result);
        assert_eq(0, $llm->completeJsonBatchCalls);
    });
});

test('a host without a browser gets the same analyzer by implementing the capture', function () {
    with_temp_dir('vision_url_analyzer_', function (string $dir) {
        $llm = new FakeLlm();
        $llm->queueJson(describe_response() + [
            'typography' => 'Grotesque sans with oversized display headings',
            'layout' => 'Centered hero over a three-column feature grid',
            'mood' => ['playful', 'bold'],
        ]);
        // No Chrome, no Node, no child process — the whole point of the seam.
        $analyzer = new VisionUrlAnalyzer($llm, new PrewrittenCapture($dir));

        $result = $analyzer->analyze(['https://example.com']);
        $reference = $result['references']['https://example.com'];

        // Identical brief quality: the dimensions, the images, the screenshots
        // downstream. Only the capture differed.
        assert_eq([], $result['failures']);
        assert_contains('Grotesque sans', $reference['typography']);
        assert_contains('three-column feature grid', $reference['layout']);
        assert_eq(['playful', 'bold'], $reference['mood']);
        assert_eq(2, count($reference['screenshots']));
        assert_eq(2, count($llm->calls[0]['opts']['images']));
        assert_eq('PNG-BYTES-1', $llm->calls[0]['opts']['images'][0]['bytes']);
    });
});

test('ChromeScreenshotCapture runs every URL in one concurrent wave and keys results by URL', function () {
    with_temp_dir('screenshot_capture_', function (string $dir) {
        $result = stub_capture($dir, "'ok'")->capture(['https://a.com', 'https://b.com', 'https://a.com']);

        assert_eq(['https://a.com', 'https://b.com'], array_keys($result), 'duplicates captured once');
        foreach ($result as $url => $shot) {
            assert_eq(null, $shot['error'], $url);
            assert_eq(2, count($shot['slices']));
            foreach ($shot['slices'] as $path) {
                assert_true(is_file($path), "slice must exist on disk: {$path}");
            }
        }
        assert_true(
            $result['https://a.com']['slices'][0] !== $result['https://b.com']['slices'][0],
            'concurrent captures must not overwrite each other',
        );
    });
});

test('ChromeScreenshotCapture reports a missing Node binary instead of throwing', function () {
    with_temp_dir('screenshot_capture_', function (string $dir) {
        $previous = getenv('NODE_BIN');
        putenv('NODE_BIN=/nonexistent/node-binary');
        try {
            $result = stub_capture($dir, "'ok'")->capture(['https://example.com']);
            assert_eq([], $result['https://example.com']['slices']);
            assert_true(
                is_string($result['https://example.com']['error']),
                'a missing browser driver degrades to an error string, not an exception',
            );
        } finally {
            $previous === false ? putenv('NODE_BIN') : putenv('NODE_BIN=' . $previous);
        }
    });
});
