<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

/**
 * Freeze image requests after page assembly and the final block repair.
 * This class never changes project artifacts. A host must apply results serially.
 */
final class PreparedImageBatch
{
    /** @param array<int,array<string,mixed>> $requests @param array<int,array<string,mixed>> $specs */
    private function __construct(
        private readonly string $projectRoot,
        private readonly array $requests,
        private readonly array $specs,
        private readonly array $omitted,
        private readonly array $inputHashes,
    ) {}

    /** @param callable(array<string,mixed>):bool|null $providerEligible */
    public static function fromProject(Project $project, bool $generateAllImages = false, ?callable $providerEligible = null): self
    {
        // This artifact establishes the minimum phase. HTML-first hosts must also finish fix-pages.
        $project->readJson('plugin/pages.json');
        $specs = $project->readJson('images.json');
        $plan = $project->readJson('pages.json');
        $siteSpec = $project->readJson('siteSpec.json');
        $kind = DesignDirectionStep::imageKindFor($project);
        $grade = ImageKind::skipsGrade($kind) ? '' : DesignDirectionStep::imageGradeFor($project);
        $crop = DesignDirectionStep::imageCropFor($project) ?? '';
        $screenTheme = $kind === 'ui-mockup' ? DesignDirectionStep::screenThemeFor($project) : '';
        $context = GenerateImagesStep::siteContext($siteSpec);
        $policy = new InitialImagePolicy($plan, $generateAllImages);
        $markup = [];
        foreach ($project->markupFiles() as $file) {
            $relative = substr($file, strlen($project->root) + 1);
            $markup[$relative] = $project->readText($relative);
        }
        $requests = [];
        $selected = [];
        $omitted = [];
        foreach ($specs as $index => $spec) {
            if (!is_array($spec)) {
                throw new \RuntimeException('images.json contains an invalid image specification');
            }
            $spec['image_kind'] = $kind;
            if ($screenTheme !== '') {
                $spec['screen_theme'] = $screenTheme;
            }
            $reason = null;
            if (($spec['status'] ?? '') === 'completed') {
                $reason = 'completed';
            } elseif (!self::referenced($spec, $markup)) {
                $reason = 'unreferenced';
            } elseif (!$policy->shouldGenerate($spec)) {
                $reason = 'deferred';
            } elseif ($providerEligible !== null && !$providerEligible($spec)) {
                $reason = 'provider-ineligible';
            }
            if ($reason !== null) {
                $omitted[$index] = ['filename' => (string) ($spec['filename'] ?? ''), 'reason' => $reason];
                continue;
            }
            $selected[$index] = $spec;
            $requests[$index] = GenerateImagesStep::generationSpec($spec, $context, $grade, $crop);
        }
        return new self($project->root, $requests, $selected, $omitted, self::hashInputs($project));
    }

    /** @param array<string,mixed> $spec @param array<string,string> $markup */
    private static function referenced(array $spec, array $markup): bool
    {
        // The collector adds this asset for the site identity. It has no placeholder source.
        if (($spec['role'] ?? '') === 'site-logo') {
            return true;
        }
        $sources = array_filter([(string) ($spec['src'] ?? ''), (string) ($spec['url'] ?? '')]);
        foreach ($markup as $content) {
            foreach ($sources as $source) {
                if (MediaReferenceRemoval::position($content, $source) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public function requests(): array { return $this->requests; }

    /** @return array<int,array<string,mixed>> */
    public function specs(): array { return $this->specs; }

    /** @return array<int,array{filename:string,reason:string}> */
    public function omitted(): array { return $this->omitted; }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->inputHashes, JSON_THROW_ON_ERROR));
    }

    /** Recheck this before a host applies results to the project. */
    public function isCurrent(Project $project): bool
    {
        return $project->root === $this->projectRoot && self::hashInputs($project) === $this->inputHashes;
    }

    /** @return array<string,string> */
    private static function hashInputs(Project $project): array
    {
        $files = ['images.json', 'pages.json', 'siteSpec.json', 'designDirection.json', 'plugin/pages.json'];
        foreach ($project->markupFiles() as $file) {
            $files[] = substr($file, strlen($project->root) + 1);
        }
        sort($files);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = hash('sha256', $project->readText($file));
        }
        return $hashes;
    }

    /**
     * Store raw transport results in an empty directory outside the project.
     * The host still owns image QA, repairs, transforms, manifests, and final validation.
     *
     * @return array{fingerprint:string,results:array<int,array<string,mixed>>}
     */
    public function stage(ImageClient $client, string $directory): array
    {
        $target = realpath($directory);
        $project = realpath($this->projectRoot) ?: $this->projectRoot;
        if ($target === false || !is_dir($target)
            || $target === $project || str_starts_with($target, $project . '/')
            || array_values(array_diff(scandir($target) ?: [], ['.', '..'])) !== []
        ) {
            throw new \InvalidArgumentException('Image results need an empty directory outside the project');
        }
        $writer = new NativeStagedFileWriter();
        $results = [];
        if ($this->requests !== []) {
            $client->generateBatch($this->requests, function (int $index, array $result) use (&$results, $target, $writer): void {
                if (!isset($this->requests[$index]) || isset($results[$index])) {
                    throw new \LogicException('Image result does not match one prepared request');
                }
                if (!empty($result['ok'])) {
                    $bytes = (string) ($result['bytes'] ?? '');
                    $mime = $this->requests[$index]['mime'];
                    if (GeminiImage::mimeFromBytes($bytes) !== $mime) {
                        $result = ['ok' => false, 'error' => 'Image result does not match the requested MIME type'];
                    } else {
                        $filename = $index . ($mime === 'image/png' ? '.png' : '.jpg');
                        $destination = $target . '/' . $filename;
                        $temporary = $writer->stage($destination, $bytes);
                        $writer->replace($temporary, $destination);
                        $result['file'] = $filename;
                        $result['sha256'] = hash('sha256', $bytes);
                    }
                }
                unset($result['bytes']);
                $results[$index] = $result;
            });
        }
        foreach ($this->requests as $index => $_request) {
            $results[$index] ??= ['ok' => false, 'error' => 'Image client omitted a prepared request result'];
        }
        ksort($results);
        $manifest = ['fingerprint' => $this->fingerprint(), 'results' => $results];
        $destination = $target . '/results.json';
        $temporary = $writer->stage($destination, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
        $writer->replace($temporary, $destination);
        return $manifest;
    }
}
