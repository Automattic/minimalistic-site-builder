<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Start raw image requests after final page assembly. Apply results in the normal image phase. */
final class EarlyImageBuild
{
    private ?ImageTransportScheduler $scheduler = null;
    private ?PreparedImageBatch $prepared = null;
    private ?string $directory = null;
    private bool $joined = false;
    private array $stageDirectories = [];
    private array $retainedStageDirectories = [];
    private readonly string $storage;
    private readonly string $boundary;

    public function __construct(
        private readonly Project $project,
        private readonly ImageClient $client,
        private readonly array $stepIds,
        bool $htmlFirst,
        ?string $storage = null,
    ) {
        $this->boundary = $htmlFirst ? 'fix-pages' : 'assemble-pages';
        $this->storage = $storage ?? sys_get_temp_dir() . '/site-build-image-stage/' . hash('sha256', realpath($project->root) ?: $project->root);
    }

    /** A resumed graph may start after the final page boundary. */
    public function beforeStep(string $id): void
    {
        $boundary = array_search($this->boundary, $this->stepIds, true);
        $current = array_search($id, $this->stepIds, true);
        if ($boundary !== false && $current !== false && $current > $boundary) {
            $this->start();
        }
        $this->scheduler?->poll();
    }

    public function afterStep(string $id): void
    {
        if ($id === $this->boundary) {
            $this->start();
        }
        $this->scheduler?->poll();
    }

    private function prepare(): PreparedImageBatch
    {
        return PreparedImageBatch::fromProject($this->project, providerEligible: static fn (array $spec): bool =>
            UiMockupImage::layout($spec) === null);
    }

    private function start(): void
    {
        if ($this->prepared !== null || !$this->project->exists('images.json')) {
            return;
        }
        $this->prepared = $this->prepare();
        $this->stageDirectories = glob($this->storage . '/*', GLOB_ONLYDIR) ?: [];
        if ($this->prepared->requests() === []) {
            return;
        }
        if (!is_dir($this->storage) && !mkdir($this->storage, 0700, true) && !is_dir($this->storage)) {
            throw new \RuntimeException('Could not create the raw image stage directory');
        }
        $replay = new StagedImageClient($this->client);
        foreach ($this->stageDirectories as $directory) {
            $replay->load($this->prepared, $directory, successesOnly: true);
        }
        $this->directory = $this->storage . '/' . bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Could not create the raw image batch directory');
        }
        $this->stageDirectories[] = $this->directory;
        $this->scheduler = new ImageTransportScheduler();
        Narrator::write("    start raw image requests after {$this->boundary}\n");
        $this->scheduler->start(function () use ($replay): void {
            $previous = ImageLogger::dir();
            ImageLogger::setDir($this->directory . '/logs');
            try {
                $this->prepared->stage($replay, $this->directory);
            } finally {
                if (ImageLogger::dir() === $this->directory . '/logs') {
                    ImageLogger::setDir($previous);
                }
            }
        });
    }

    /** The host requests cleanup only after every post-image step succeeds. */
    public function finishRaw(bool $publish = false, bool $cleanup = false): void
    {
        if ($cleanup && !$publish) {
            throw new \LogicException('Raw image cleanup requires durable attempt logs');
        }
        try {
            if (!$this->joined && $this->scheduler !== null) {
                $this->joined = true;
                $this->scheduler->join();
            }
        } finally {
            if ($publish) {
                $this->publishAttemptLog();
            }
        }
        if ($cleanup) {
            $this->removeCompletedStages();
        }
    }

    public function directory(): ?string { return $this->directory; }

    /** Recheck final references and request keys before any asset or manifest changes. */
    public function applicationClient(): StagedImageClient
    {
        $replay = new StagedImageClient($this->client);
        if ($this->directory !== null && $this->prepared !== null) {
            $current = $this->prepared->isCurrent($this->project) ? $this->prepared : $this->prepare();
            $replay->follow($current, $this->directory);
        }
        return $replay;
    }

    private function publishAttemptLog(): void
    {
        $sources = array_filter(array_map(static fn (string $directory): string =>
            $directory . '/logs/attempts.jsonl', $this->stageDirectories), is_file(...));
        if ($sources === []) {
            return;
        }
        $target = $this->project->logPath('images');
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException('Could not create the image log directory');
        }
        $handle = fopen($target . '/attempts.jsonl', 'a+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the image attempt log');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the image attempt log');
            }
            rewind($handle);
            $seen = [];
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (is_string($record['stage_attempt_id'] ?? null)) {
                    $seen[$record['stage_attempt_id']] = true;
                }
            }
            fseek($handle, 0, SEEK_END);
            foreach ($sources as $source) {
                $lines = @file($source, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    if ($this->retainOldStage($source, 'Could not read the attempt log')) {
                        continue;
                    }
                    throw new \RuntimeException('Could not read the raw image attempt log');
                }
                foreach ($lines as $index => $line) {
                    $id = hash('sha256', $source . ':' . $index);
                    $record = json_decode($line, true);
                    if (!is_array($record)) {
                        if ($this->retainOldStage($source, 'The attempt log contains an invalid record')) {
                            continue;
                        }
                        throw new \RuntimeException('The raw image attempt log contains an invalid record');
                    }
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $record['stage_attempt_id'] = $id;
                    $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    if (fwrite($handle, $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('Could not publish the raw image attempt log');
                    }
                    $seen[$id] = true;
                }
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Could not flush the image attempt log');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function retainOldStage(string $source, string $reason): bool
    {
        $directory = dirname($source, 2);
        if ($directory === $this->directory) {
            return false;
        }
        if (!isset($this->retainedStageDirectories[$directory])) {
            Narrator::write("    Raw image stage retained: {$reason} ({$directory})\n");
        }
        $this->retainedStageDirectories[$directory] = true;
        return true;
    }

    /** Remove only complete stages from this run's snapshot, without recursive traversal. */
    private function removeCompletedStages(): void
    {
        foreach ($this->stageDirectories as $directory) {
            if (isset($this->retainedStageDirectories[$directory])
                || is_link($directory) || !is_file($directory . '/results.json')) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($directory . '/results.json'), true);
            if (($manifest['complete'] ?? false) !== true
                || ($manifest['project_root'] ?? '') !== (realpath($this->project->root) ?: $this->project->root)) {
                continue;
            }
            $files = [];
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
                $path = $directory . '/' . $name;
                if ($name === 'logs' && is_dir($path) && !is_link($path)) {
                    if (array_diff(scandir($path) ?: [], ['.', '..', 'attempts.jsonl']) !== []) {
                        continue 2;
                    }
                    if (is_link($path . '/attempts.jsonl')
                        || (file_exists($path . '/attempts.jsonl') && !is_file($path . '/attempts.jsonl'))) {
                        continue 2;
                    }
                    if (is_file($path . '/attempts.jsonl')) {
                        $files[] = $path . '/attempts.jsonl';
                    }
                } elseif (preg_match('/^(?:results\.json|[0-9]+\.(?:jpg|png))$/D', $name)
                    && is_file($path) && !is_link($path)) {
                    $files[] = $path;
                } else {
                    continue 2;
                }
            }
            foreach ($files as $file) {
                if (!@unlink($file)) {
                    Narrator::write("    Raw image cleanup could not remove {$file}\n");
                    continue 2;
                }
            }
            if (is_dir($directory . '/logs')) {
                @rmdir($directory . '/logs');
            }
            if (!@rmdir($directory)) {
                Narrator::write("    Raw image cleanup could not remove {$directory}\n");
            } elseif ($directory === $this->directory) {
                $this->directory = null;
            }
        }
        if (is_dir($this->storage) && !is_link($this->storage)) {
            @rmdir($this->storage);
        }
    }
}
