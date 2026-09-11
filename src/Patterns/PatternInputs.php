<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StepDeclaration;

/** Versioned supplied-layout contract for the first pattern extraction proof. */
final class PatternInputs
{
    public const VERSION = 1;

    public static function read(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        if (($meta['graph'] ?? null) !== 'patterns') {
            throw new \InvalidArgumentException('Pattern composition requires meta.json graph=patterns');
        }
        return self::validate($project->readJson('pattern-inputs.json'));
    }

    public static function validate(array $input): array
    {
        self::assertKeys($input, ['version', 'facts', 'brand', 'delivery', 'layouts', 'media'], 'Pattern inputs');
        if (($input['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported pattern input version');
        }
        foreach (['facts', 'brand', 'delivery', 'layouts', 'media'] as $key) {
            if (!isset($input[$key]) || !is_array($input[$key])) {
                throw new \InvalidArgumentException("Pattern inputs require {$key}");
            }
        }
        $delivery = $input['delivery'];
        self::assertKeys($delivery, ['theme', 'site_title'], 'Pattern delivery');
        foreach (['theme', 'site_title'] as $key) {
            if (!is_string($delivery[$key] ?? null) || trim($delivery[$key]) === '') {
                throw new \InvalidArgumentException("Pattern delivery requires {$key}");
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $delivery['theme']) !== 1) {
            throw new \InvalidArgumentException('Pattern delivery theme must be an installed theme slug');
        }
        if ($input['layouts'] === [] || !array_is_list($input['layouts'])) {
            throw new \InvalidArgumentException('Pattern inputs require a non-empty list of layouts');
        }
        $ids = [];
        $placements = [];
        $pageCount = 0;
        foreach ($input['layouts'] as $layout) {
            if (!is_array($layout)) {
                throw new \InvalidArgumentException('Each supplied layout must be an object');
            }
            $id = $layout['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1 || isset($ids[$id])) {
                throw new \InvalidArgumentException('Layouts require unique lowercase IDs');
            }
            $ids[$id] = true;
            if (!in_array($layout['role'] ?? null, ['page', 'shared-part'], true)
                || !is_string($layout['markup'] ?? null) || trim($layout['markup']) === ''
                || !is_array($layout['slots'] ?? null)) {
                throw new \InvalidArgumentException("Layout {$id} requires an explicit role, markup and slots");
            }
            $placement = $layout['role'] === 'page' ? ($layout['page'] ?? null) : ($layout['shared_part'] ?? null);
            $required = $layout['role'] === 'page' ? ['title', 'slug'] : ['title', 'slug', 'area'];
            self::assertKeys(
                $layout,
                $layout['role'] === 'page'
                    ? ['id', 'role', 'markup', 'slots', 'page']
                    : ['id', 'role', 'markup', 'slots', 'shared_part'],
                "Layout {$id}",
            );
            if (!is_array($placement)) {
                throw new \InvalidArgumentException("Layout {$id} requires destination placement");
            }
            self::assertKeys($placement, $required, "Layout {$id} placement");
            foreach ($required as $key) {
                if (!is_string($placement[$key] ?? null) || trim($placement[$key]) === '') {
                    throw new \InvalidArgumentException("Layout {$id} placement requires {$key}");
                }
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $placement['slug']) !== 1) {
                throw new \InvalidArgumentException("Layout {$id} placement requires a lowercase URL-safe slug");
            }
            $placementKey = $layout['role'] . ':' . $placement['slug'];
            if (isset($placements[$placementKey])) {
                throw new \InvalidArgumentException("Layout {$id} has a duplicate destination slug");
            }
            $placements[$placementKey] = true;
            $pageCount += $layout['role'] === 'page' ? 1 : 0;
            if (!array_is_list($layout['slots'])) {
                throw new \InvalidArgumentException("Layout {$id} slots must be a list");
            }
            foreach ($layout['slots'] as $slot) {
                if (!is_array($slot)) {
                    throw new \InvalidArgumentException("Layout {$id} slots must be objects");
                }
                self::assertKeys(
                    $slot,
                    ['id', 'block_path', 'field', 'fallback', 'binding', 'instruction', 'max_words'],
                    "Layout {$id} slot",
                );
            }
            new ApprovedPattern($layout['markup'], $layout['slots']);
        }
        if ($pageCount === 0) {
            throw new \InvalidArgumentException('Pattern inputs require at least one page layout');
        }
        $mediaIds = [];
        if (!array_is_list($input['media'])) {
            throw new \InvalidArgumentException('Pattern media must be a list');
        }
        foreach ($input['media'] as $media) {
            if (!is_array($media)) {
                throw new \InvalidArgumentException('Each media item must be an object');
            }
            self::assertKeys($media, ['source', 'upload_path', 'url', 'title', 'slug', 'mime_type'], 'Pattern media');
            foreach (['source', 'upload_path', 'url', 'title', 'slug', 'mime_type'] as $key) {
                if (!is_string($media[$key] ?? null) || trim($media[$key]) === '') {
                    throw new \InvalidArgumentException("Pattern media requires {$key}");
                }
            }
            StepDeclaration::assertValidProjectPath($media['source'], 'Pattern media source');
            StepDeclaration::assertValidProjectPath($media['upload_path'], 'Pattern media upload_path');
            if (str_contains($media['source'], '*') || str_contains($media['upload_path'], '*')) {
                throw new \InvalidArgumentException('Pattern media paths must name concrete files');
            }
            if (!str_starts_with($media['source'], 'media/')) {
                throw new \InvalidArgumentException('Pattern media sources must live under media/');
            }
            if (!str_starts_with($media['mime_type'], 'image/')) {
                throw new \InvalidArgumentException('Pattern media currently accepts image MIME types only');
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $media['slug']) !== 1) {
                throw new \InvalidArgumentException('Pattern media requires a lowercase URL-safe slug');
            }
            if (filter_var($media['url'], FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($media['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Pattern media URL must be an absolute HTTP URL');
            }
            foreach (['source', 'upload_path', 'slug'] as $identity) {
                $key = $identity . ':' . $media[$identity];
                if (isset($mediaIds[$key])) {
                    throw new \InvalidArgumentException("Pattern media requires unique {$identity} values");
                }
                $mediaIds[$key] = true;
            }
        }
        return $input;
    }

    public static function fingerprint(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    public static function retained(Project $project, string $file, array $input): array
    {
        $artifact = $project->readJson($file);
        if (($artifact['version'] ?? null) !== self::VERSION
            || ($artifact['input_hash'] ?? null) !== self::fingerprint($input)) {
            throw new \InvalidArgumentException("Stale {$file}; rerun from prepare-pattern-content");
        }
        if (!isset($artifact['layouts']) || !is_array($artifact['layouts'])) {
            throw new \InvalidArgumentException("Corrupt {$file}: missing layouts");
        }
        return $artifact;
    }

    /** Reject contract drift while leaving facts and Brand values extensible. */
    private static function assertKeys(array $value, array $allowed, string $subject): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$subject} contains undeclared field " . reset($unknown));
        }
    }
}
