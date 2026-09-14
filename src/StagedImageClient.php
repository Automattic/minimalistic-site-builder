<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Consume verified raw results once, then use the provider for later repairs. */
final class StagedImageClient implements ImageClient, CooperativeTransport
{
    private array $results = [];
    private int $reused = 0;
    private array $consumed = [];
    private array $awaiting = [];
    private ?array $watch = null;
    private array $loadedVersions = [];

    public function __construct(private ImageClient $client) {}

    public static function identity(ImageClient $client): string
    {
        return $client instanceof self ? self::identity($client->client) : get_class($client) . ':' . $client->model();
    }

    private static function assetKey(array $request, string $model): string
    {
        return self::requestKey($request, $model) . ':' . (string) ($request['asset'] ?? '');
    }

    public static function requestKey(array $request, string $model): string
    {
        return hash('sha256', json_encode([
            $model, $request['prompt'], $request['aspect_ratio'] ?? '16:9',
            $request['sample_image_size'] ?? null, $request['mime'] ?? 'image/jpeg',
        ], JSON_THROW_ON_ERROR));
    }

    /** Load only requests that still occur in the final prepared batch. */
    public function load(PreparedImageBatch $current, string $directory, bool $successesOnly = false, bool $pending = false): void
    {
        $path = $directory . '/results.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($raw)) {
            if ($pending) { throw new \RuntimeException('The raw image stage manifest is absent'); }
            return;
        }
        $version = hash('sha256', $raw);
        $versionKey = $directory . ':' . (int) $pending . ':' . (int) $successesOnly;
        if (($this->loadedVersions[$versionKey] ?? null) === $version) {
            return;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || ($manifest['model'] ?? '') !== $this->model()
            || ($manifest['project_root'] ?? '') !== $current->projectRoot()
            || ($manifest['provider'] ?? '') !== self::identity($this->client)) {
            if ($pending) { throw new \RuntimeException('The raw image stage manifest has an invalid identity'); }
            return;
        }
        $allowed = [];
        foreach ($current->requests() as $request) {
            $allowed[self::requestKey($request, $this->model())][self::assetKey($request, $this->model())] = true;
        }
        foreach ($manifest['requests'] ?? [] as $index => $request) {
            if (!is_array($request) || !is_string($request['prompt'] ?? null)) {
                continue;
            }
            $key = self::requestKey($request, $this->model());
            if ($pending && isset($allowed[$key])) {
                foreach ($allowed[$key] as $assetKey => $_) {
                    if (!isset($this->consumed[$assetKey])) {
                        $this->awaiting[$assetKey] = true;
                    }
                }
            }
            $needed = false;
            foreach ($allowed[$key] ?? [] as $assetKey => $_) {
                $needed = $needed || (!isset($this->consumed[$assetKey]) && !isset($this->results[$assetKey]));
            }
            if (!$needed) {
                continue;
            }
            $result = $manifest['results'][$index] ?? null;
            if ($pending && !is_array($result) && !empty($manifest['complete'])) {
                throw new \RuntimeException('A completed raw image stage omitted a required result');
            }
            if (!isset($allowed[$key]) || !is_array($result) || ($successesOnly && empty($result['ok']))) {
                continue;
            }
            if (!empty($result['ok'])) {
                $filename = $result['file'] ?? '';
                if (!is_string($filename) || basename($filename) !== $filename || $filename === '') {
                    if ($pending) { throw new \RuntimeException('A staged image result has an invalid file path'); }
                    continue;
                }
                $file = $directory . '/' . $filename;
                if (!is_file($file) || !hash_equals((string) ($result['sha256'] ?? ''), hash_file('sha256', $file))) {
                    if ($pending) { throw new \RuntimeException('A staged image result failed its file checksum'); }
                    continue;
                }
                $result['file'] = $file;
            }
            foreach ($allowed[$key] as $assetKey => $_) {
                if (!isset($this->consumed[$assetKey])) {
                    $this->results[$assetKey] = $result;
                }
            }
        }
        $this->loadedVersions[$versionKey] = $version;
    }

    /** Follow raw results that can still complete after the normal image phase starts. */
    public function follow(PreparedImageBatch $current, string $directory): void
    {
        $this->watch = [$current, $directory];
        $this->load($current, $directory, pending: true);
    }

    public function model(): string { return $this->client->model(); }

    public function supportsCooperativeRequests(array $opts = []): bool
    {
        return $this->client instanceof CooperativeTransport && $this->client->supportsCooperativeRequests($opts);
    }

    public function reusedResults(): int { return $this->reused; }

    public function generate(string $prompt, array $opts = []): string
    {
        $result = $this->generateBatch([['prompt' => $prompt] + $opts])[0];
        if (empty($result['ok'])) {
            throw new \RuntimeException((string) ($result['error'] ?? 'Image generation failed'));
        }
        return $result['bytes'];
    }

    public function generateBatch(array $specs, ?callable $onResult = null): array
    {
        $out = [];
        $pending = [];
        $waiting = [];
        $deliver = static function (int $index, array $result) use (&$out, $onResult): void {
            if ($onResult !== null) {
                $onResult($index, $result);
                unset($result['bytes']);
            }
            $out[$index] = $result;
        };
        foreach ($specs as $index => $request) {
            $key = self::assetKey($request, $this->model());
            if (isset($this->results[$key]) || isset($this->awaiting[$key])) {
                $waiting[$index] = $request;
            } else {
                $pending[$index] = $request;
            }
        }
        $providerDone = $pending === [];
        if ($pending !== []) {
            $task = function () use ($pending, $deliver, &$providerDone): void {
                $this->client->generateBatch($pending, $deliver);
                $providerDone = true;
            };
            if (ImageTransportScheduler::current() !== null) {
                ImageTransportScheduler::current()->spawn($task);
            } else {
                $task();
            }
        }
        while ($waiting !== [] || !$providerDone) {
            if ($this->watch !== null) {
                $this->load($this->watch[0], $this->watch[1], pending: true);
            }
            foreach ($waiting as $index => $request) {
                $key = self::assetKey($request, $this->model());
                $result = $this->results[$key] ?? null;
                if ($result === null) {
                    continue;
                }
                if (!empty($result['ok'])) {
                    $bytes = file_get_contents($result['file']);
                    if (!is_string($bytes) || !hash_equals($result['sha256'], hash('sha256', $bytes))
                        || GeminiImage::mimeFromBytes($bytes) !== ($request['mime'] ?? 'image/jpeg')) {
                        throw new \RuntimeException('A staged image changed before serial apply');
                    }
                    $result['bytes'] = $bytes;
                }
                unset($result['file'], $result['sha256']);
                unset($this->results[$key], $this->awaiting[$key], $waiting[$index]);
                $this->consumed[$key] = true;
                $this->reused++;
                $deliver($index, $result);
            }
            if ($waiting !== [] || !$providerDone) {
                if (ImageTransportScheduler::current() === null) {
                    throw new \RuntimeException('A staged image result is absent after its transport stopped');
                }
                ImageTransportScheduler::pause();
            }
        }
        $ordered = [];
        foreach ($specs as $index => $_) {
            $ordered[$index] = $out[$index] ?? ['ok' => false, 'error' => 'Image client omitted a result'];
        }
        return $ordered;
    }
}
