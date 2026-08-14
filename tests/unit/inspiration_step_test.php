<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\InspirationLogger;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\InspirationStep;
use Automattic\SiteBuild\UrlAnalyzer;

/** @return array<string,mixed> */
function inspiration_step_reference(string $url = 'https://a.com'): array
{
    return [
        'url' => $url,
        'page_type' => 'store',
        'owner_type' => 'business',
        'style' => 'Bold and playful',
        'colors' => [['hex' => '#ff90e8', 'name' => 'pink', 'role' => 'accent']],
        'sections' => [['category' => 'hero', 'description' => 'Full-bleed color field']],
    ];
}

/** Scripted analyzer at the frozen UrlAnalyzer boundary. */
final class InspirationStepAnalyzerDouble implements UrlAnalyzer
{
    /** @var list<list<string>> */
    public array $calls = [];

    /**
     * @param array{references:array<string,array<string,mixed>>,failures:array<string,array{url:string,kind:string,message:string}>} $result
     */
    public function __construct(
        private array $result = ['references' => [], 'failures' => []],
        private ?\Throwable $error = null,
    ) {}

    public function analyze(array $urls): array
    {
        $this->calls[] = $urls;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }
}

/** @param array<string,mixed> $meta */
function with_inspiration_step_project(array $meta, callable $test): mixed
{
    return with_project('inspiration-step-', function (Project $project) use ($meta, $test): mixed {
        $project->writeJson('meta.json', $meta);
        return $test($project);
    });
}

test('inspiration step declares its artifacts and stable identity', function () {
    $step = new InspirationStep();
    $declaration = $step->declaration();

    assert_eq('inspiration', $step->id());
    assert_eq('Analyze reference sites', $step->label());
    assert_eq(['meta.json'], $declaration->reads);
    assert_eq(['inspiration.json', 'warnings.json'], $declaration->writes);
    assert_eq(false, $declaration->concurrent);
});

test('inspiration step writes an empty artifact for a prompt without URLs', function () {
    with_inspiration_step_project(['prompt' => 'a bakery in Lisbon'], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble();
        (new InspirationStep($analyzer))->run($project);

        assert_eq(['urls' => [], 'references' => []], $project->readJson('inspiration.json'));
        assert_eq(false, $project->exists('warnings.json'));
        assert_eq(false, is_dir($project->path('logs/inspiration')));
        assert_eq([], $analyzer->calls);
    });
});

test('inspiration step scans prompt URLs and writes analyzed references', function () {
    with_inspiration_step_project(['prompt' => 'a candy shop like a.com'], function (Project $project): void {
        $url = 'https://a.com';
        $analyzer = new InspirationStepAnalyzerDouble([
            'references' => [$url => inspiration_step_reference($url)],
            'failures' => [],
        ]);

        (new InspirationStep($analyzer))->run($project);

        assert_eq([[$url]], $analyzer->calls);
        $artifact = $project->readJson('inspiration.json');
        assert_eq([$url], $artifact['urls']);
        assert_eq([$url], array_column($artifact['references'], 'url'));
        assert_eq(false, $project->exists('warnings.json'));
    });
});

test('inspiration step binds production analyzer logs to the project before analysis', function () {
    with_inspiration_step_project(['prompt' => 'a candy shop like a.com'], function (Project $project): void {
        $analyzer = new class implements UrlAnalyzer {
            public function analyze(array $urls): array
            {
                $url = $urls[0];
                InspirationLogger::log($url, [
                    'endpoint' => 'https://example.invalid/analyze',
                    'url' => $url,
                    'status' => 200,
                ], inspiration_step_reference($url));
                return [
                    'references' => [$url => inspiration_step_reference($url)],
                    'failures' => [],
                ];
            }
        };

        (new InspirationStep($analyzer))->run($project);

        $files = glob($project->path('logs/inspiration/*.txt')) ?: [];
        assert_eq(1, count($files), 'production step must activate project-scoped analyzer logging');
        assert_contains('https://a.com', (string) file_get_contents($files[0]));
    });
});

test('inspiration step scans original_prompt instead of a refined prompt', function () {
    with_inspiration_step_project([
        'prompt' => 'A charming confectionery like ignored.com.',
        'original_prompt' => 'a candy shop like a.com',
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble();
        (new InspirationStep($analyzer))->run($project);

        assert_eq([['https://a.com']], $analyzer->calls);
    });
});

test('inspiration step uses supplied URL mode without scanning prompt', function () {
    with_inspiration_step_project([
        'prompt' => 'a candy shop like ignored.com',
        'inspiration_urls' => ['https://supplied.com'],
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble();
        (new InspirationStep($analyzer))->run($project);

        assert_eq([['https://supplied.com']], $analyzer->calls);
    });
});

test('an explicit empty supplied URL mode never falls through to prompt scanning', function () {
    with_inspiration_step_project([
        'prompt' => 'a candy shop like ignored.com',
        'inspiration_urls' => [],
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble();
        (new InspirationStep($analyzer))->run($project);

        assert_eq([], $analyzer->calls);
        assert_eq(['urls' => [], 'references' => []], $project->readJson('inspiration.json'));
        assert_eq(false, $project->exists('warnings.json'));
    });
});

test('inspiration step normalizes supplied briefs without calling analyzer', function () {
    $raw = inspiration_step_reference('https://host.com');
    $raw['page_type'] = ' STORE ';
    $raw['colors'][0]['hex'] = '#FF90E8';

    with_inspiration_step_project([
        'prompt' => 'a candy shop like ignored.com',
        'inspiration' => ['references' => [$raw]],
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('must not run'));
        (new InspirationStep($analyzer))->run($project);

        assert_eq([], $analyzer->calls);
        $artifact = $project->readJson('inspiration.json');
        assert_eq(['https://host.com'], $artifact['urls']);
        assert_eq('store', $artifact['references'][0]['page_type']);
        assert_eq('#ff90e8', $artifact['references'][0]['colors'][0]['hex']);
    });
});

test('inspiration step normalizes exactly one host URL and reports unusable values', function () {
    $normalized = inspiration_step_reference('HTTPS://HOST.COM/path/');
    with_inspiration_step_project([
        'inspiration' => ['references' => [$normalized]],
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('must not run'));
        (new InspirationStep($analyzer))->run($project);

        assert_eq([], $analyzer->calls);
        assert_eq(
            ['https://host.com/path/'],
            array_column($project->readJson('inspiration.json')['references'], 'url'),
        );
    });

    foreach (['not a URL', 'https://a.com https://b.com'] as $rawUrl) {
        $reference = inspiration_step_reference($rawUrl);
        with_inspiration_step_project([
            'inspiration' => ['references' => [$reference]],
        ], function (Project $project) use ($rawUrl): void {
            $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('must not run'));
            (new InspirationStep($analyzer))->run($project);

            assert_eq([], $analyzer->calls);
            assert_eq(['urls' => [$rawUrl], 'references' => []], $project->readJson('inspiration.json'));
            $warning = $project->readJson('warnings.json')['inspiration'][0];
            assert_contains($rawUrl, $warning);
            assert_contains('unusable_url', $warning);
        });
    }
});

test('host-supplied mode records and reports every considered URL', function () {
    $valid = inspiration_step_reference('https://valid.com');
    $rejected = inspiration_step_reference('https://rejected.com');
    $rejected['colors'] = [];
    $rejected['sections'] = [];
    $unusable = inspiration_step_reference('not a URL');

    with_inspiration_step_project([
        'inspiration' => ['references' => [$valid, $rejected, $unusable]],
    ], function (Project $project): void {
        $sink = fopen('php://memory', 'w+');
        Narrator::setStream($sink);
        try {
            $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('must not run'));
            (new InspirationStep($analyzer))->run($project);
            rewind($sink);
            $narration = (string) stream_get_contents($sink);
        } finally {
            Narrator::reset();
            fclose($sink);
        }

        assert_eq([], $analyzer->calls);
        $artifact = $project->readJson('inspiration.json');
        assert_eq(['https://valid.com', 'https://rejected.com', 'not a URL'], $artifact['urls']);
        assert_eq(['https://valid.com'], array_column($artifact['references'], 'url'));

        $warnings = $project->readJson('warnings.json')['inspiration'];
        assert_eq(2, count($warnings));
        assert_contains('https://rejected.com', $warnings[0]);
        assert_contains('gate_rejected', $warnings[0]);
        assert_contains('response contained neither usable colors nor sections', $warnings[0]);
        assert_contains('not a URL', $warnings[1]);
        assert_contains('unusable_url', $warnings[1]);
        assert_contains('expected exactly one usable URL', $warnings[1]);

        assert_contains('https://rejected.com — dropped (gate_rejected)', $narration);
        assert_contains('not a URL — dropped (unusable_url)', $narration);
    });
});

test('host-supplied mode caps entries examined even when every brief is rejected', function () {
    $references = [];
    for ($i = 1; $i <= 500; $i++) {
        $reference = inspiration_step_reference("https://rejected{$i}.com");
        $reference['colors'] = [];
        $reference['sections'] = [];
        $references[] = $reference;
    }

    with_inspiration_step_project([
        'inspiration' => ['references' => $references],
    ], function (Project $project): void {
        $sink = fopen('php://memory', 'w+');
        Narrator::setStream($sink);
        try {
            (new InspirationStep())->run($project);
            rewind($sink);
            $narration = (string) stream_get_contents($sink);
        } finally {
            Narrator::reset();
            fclose($sink);
        }

        $artifact = $project->readJson('inspiration.json');
        assert_eq(3, count($artifact['urls']), 'the cap counts rejected entries examined');
        assert_eq([], $artifact['references']);
        assert_eq(3, count($project->readJson('warnings.json')['inspiration']));
        assert_eq(3, substr_count($narration, '— dropped (gate_rejected)'));
    });
});

test('an explicit empty supplied brief mode never calls analyzer or scans prompt', function () {
    with_inspiration_step_project([
        'prompt' => 'a candy shop like ignored.com',
        'inspiration' => ['references' => []],
    ], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('must not run'));
        (new InspirationStep($analyzer))->run($project);

        assert_eq([], $analyzer->calls);
        assert_eq(['urls' => [], 'references' => []], $project->readJson('inspiration.json'));
        assert_eq(false, $project->exists('warnings.json'));
    });
});

test('inspiration step writes one actionable warning for each analyzer failure', function () {
    with_inspiration_step_project(['prompt' => 'like a.com and b.org'], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble([
            'references' => [],
            'failures' => [
                'https://a.com' => [
                    'url' => 'https://a.com',
                    'kind' => 'gate_rejected',
                    'message' => 'response described the mShots placeholder',
                ],
                'https://b.org' => [
                    'url' => 'https://b.org',
                    'kind' => 'http_error',
                    'message' => 'HTTP 401',
                ],
            ],
        ]);

        (new InspirationStep($analyzer))->run($project);

        $artifact = $project->readJson('inspiration.json');
        assert_eq(['https://a.com', 'https://b.org'], $artifact['urls']);
        assert_eq([], $artifact['references']);
        $warnings = $project->readJson('warnings.json')['inspiration'];
        assert_eq(2, count($warnings));
        assert_contains('https://a.com', $warnings[0]);
        assert_contains('gate_rejected', $warnings[0]);
        assert_contains('response described the mShots placeholder', $warnings[0]);
        assert_contains('https://b.org', $warnings[1]);
        assert_contains('http_error', $warnings[1]);
        assert_contains('HTTP 401', $warnings[1]);
        assert_eq(false, str_contains(implode("\n", $warnings), 'analysis produced nothing usable'));
        assert_eq(false, str_contains(strtolower(implode("\n", $warnings)), 'log file'));
    });
});

test('inspiration step flattens and caps analyzer failure messages at four hundred characters', function () {
    with_inspiration_step_project(['prompt' => 'like a.com'], function (Project $project): void {
        $url = 'https://a.com';
        $analyzer = new InspirationStepAnalyzerDouble([
            'references' => [],
            'failures' => [
                $url => [
                    'url' => $url,
                    'kind' => 'transport_error',
                    'message' => "first line\n" . str_repeat('x', 500),
                ],
            ],
        ]);

        (new InspirationStep($analyzer))->run($project);

        $warning = $project->readJson('warnings.json')['inspiration'][0];
        assert_eq(false, str_contains($warning, "\n"));
        assert_eq(1, preg_match('/; message=(.*); authored=/', $warning, $matches));
        assert_eq(400, mb_strlen($matches[1], 'UTF-8'));
        assert_contains('first line ', $matches[1]);
        assert_eq(false, str_contains($matches[1], str_repeat('x', 390)));
    });
});

test('inspiration step warns once per URL when analyzer is unavailable', function () {
    with_inspiration_step_project(['prompt' => 'like a.com and b.org'], function (Project $project): void {
        (new InspirationStep())->run($project);

        $warnings = $project->readJson('warnings.json')['inspiration'];
        assert_eq(2, count($warnings));
        assert_contains('https://a.com', $warnings[0]);
        assert_contains('transport_error', $warnings[0]);
        assert_contains('no reference-site analyzer is configured', $warnings[0]);
        assert_contains('https://b.org', $warnings[1]);
    });
});

test('inspiration step degrades a throwing analyzer per URL', function () {
    with_inspiration_step_project(['prompt' => 'like a.com and b.org'], function (Project $project): void {
        $analyzer = new InspirationStepAnalyzerDouble(error: new RuntimeException('transport exploded'));
        (new InspirationStep($analyzer))->run($project);

        $artifact = $project->readJson('inspiration.json');
        assert_eq([], $artifact['references']);
        $warnings = $project->readJson('warnings.json')['inspiration'];
        assert_eq(2, count($warnings));
        foreach ($warnings as $index => $warning) {
            assert_contains($index === 0 ? 'https://a.com' : 'https://b.org', $warning);
            assert_contains('transport_error', $warning);
            assert_contains('transport exploded', $warning);
        }
    });
});

test('inspiration step reports a missing analyzer outcome as malformed contract data', function () {
    with_inspiration_step_project(['prompt' => 'like a.com'], function (Project $project): void {
        (new InspirationStep(new InspirationStepAnalyzerDouble()))->run($project);

        $warning = $project->readJson('warnings.json')['inspiration'][0];
        assert_contains('https://a.com', $warning);
        assert_contains('malformed_response', $warning);
        assert_contains('neither a reference nor a failure record', $warning);
    });
});

test('referencesFor returns references or an empty list without an artifact', function () {
    with_inspiration_step_project(['prompt' => 'x'], function (Project $project): void {
        assert_eq([], InspirationStep::referencesFor($project));
        $project->writeJson('inspiration.json', [
            'urls' => ['https://a.com'],
            'references' => [inspiration_step_reference(), 'invalid'],
        ]);
        assert_eq(['https://a.com'], array_column(InspirationStep::referencesFor($project), 'url'));
    });
});
