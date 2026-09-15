<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step: account for every image and link the composed pages reference.
 *
 * The library cannot import an image. An attachment id only exists on the
 * destination site, so what this produces is a list of what the host must
 * import and what it can leave alone — which is what VIPPROD-1145 asks for:
 * return the images, let VIP import them through the standard media path, and
 * make the blocks point at the attachments afterwards. Nothing here writes a
 * file, because a build that writes files is the thing that ticket removes.
 *
 * A pattern from a deployed theme mostly points at the theme's own images, and
 * those need nothing: they ship with the theme the destination already has.
 * The ones that matter are the rest, and the reason to look at all of them is
 * the failure in between — a path that is neither the theme's nor importable
 * renders as a broken image on a site where every other check passed. That one
 * stops the build.
 *
 * Links are reported rather than resolved. A theme ships its calls to action
 * pointing at `#`, and this stage knows the pages the plan made but not which
 * one a button meant; guessing sends "Register" to the venue page, which is
 * worse than a placeholder that is still visibly a placeholder. What it does
 * is refuse to let the count go unsaid.
 */
final class ResolveMediaStep implements Step
{
    public const MEDIA_VERSION = 1;

    /** Blocks whose `url` attribute names a source, not only their markup. */
    private const MEDIA_BLOCKS = ['core/image', 'core/cover', 'core/media-text'];

    public function id(): string
    {
        return 'resolve-media';
    }

    /**
     * Refuse a media manifest from an older contract.
     *
     * A run started at the export takes this file from a fixture, and a stale
     * one with no `images` key exports zero images and reports success.
     *
     * @param array<string, mixed> $media
     */
    public static function assertVersion(array $media): void
    {
        $version = $media['version'] ?? null;

        if ($version !== self::MEDIA_VERSION) {
            throw new \RuntimeException(sprintf(
                'The media manifest is version %s; this build reads version %d. Re-run from resolve-media.',
                var_export($version, true),
                self::MEDIA_VERSION,
            ));
        }
    }

    public function label(): string
    {
        return 'Account for every image and link the pages reference';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [PatternArtifacts::NORMALIZED, PatternArtifacts::PAGES],
            writes: [PatternArtifacts::MEDIA, 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        NormalizeInputsStep::assertVersion($inputs);

        $theme = (string) ($inputs['theme'] ?? '');
        $facts = is_array($inputs['facts'] ?? null) ? $inputs['facts'] : [];
        $pages = self::pages($project);

        if ($pages === []) {
            throw new \RuntimeException('No page was written, so there is no media to account for.');
        }

        $records = $project->exists(GenerateMediaStep::MANIFEST) ? $project->readJson(GenerateMediaStep::MANIFEST) : [];
        $imports = [];
        $generated = [];
        $unknown = [];
        $links = [];
        $avatars = [];

        foreach ($pages as $slug => $markup) {
            foreach (self::sources($markup) as $source) {
                $where = $slug . ': ' . $source;

                match (self::classify($source, $theme)) {
                    'theme' => null,
                    'import' => $imports[$source]['pages'][] = $slug,
                    'generated' => $generated[$source]['pages'][] = $slug,
                    default => $unknown[] = $where,
                };
            }

            foreach (self::links($markup) as $link) {
                $links[] = ['page' => $slug, 'target' => $link['target'], 'kind' => $link['kind']];
            }

            foreach (self::avatarBlocks($markup) as $ref) {
                $avatars[] = $slug . ':' . $ref;
            }
        }

        // A source that is neither the theme's nor something the host can
        // fetch is a broken image on the published site, and every other check
        // passes. Observed: an inventory built outside WordPress emitted
        // `/assets/images/...` because nothing told it where the theme lives.
        if ($unknown !== []) {
            throw new \RuntimeException(
                "These media sources are neither the destination theme's own nor importable, "
                . "so they would render broken:\n  - " . implode("\n  - ", $unknown)
            );
        }

        // Only what the host consumes is written. What was seen and left
        // alone — the theme's own images, the links — is said in the
        // warnings, where a person reads it, not persisted for a reader that
        // does not exist yet.
        $project->writeJson(PatternArtifacts::MEDIA, [
            'version' => self::MEDIA_VERSION,
            'images' => array_map(static fn (array $entry): array => isset($records[$entry['source']]) ? array_merge($entry, $records[$entry['source']]) : $entry, self::images($imports, $generated, $avatars, $facts)),
        ]);

        $project->replaceWarnings($this->id(), self::warnings($links, $avatars, $facts));
    }

    /**
     * What the host has to import, each named once however many pages use it.
     *
     * @param array<string, array{pages: list<string>}> $imports
     * @param array<string, array{pages: list<string>}> $generated
     * @param list<string>                              $avatars
     * @param array<string, mixed>                      $facts
     * @return list<array<string, mixed>>
     */
    private static function images(array $imports, array $generated, array $avatars, array $facts): array
    {
        $images = [];

        foreach ($imports as $source => $found) {
            $images[] = [
                'source' => $source,
                'pages' => array_values(array_unique($found['pages'])),
                'role' => 'content',
            ];
        }

        // Made by this build and already sitting in the bundle. `file` says so:
        // the host reads it from beside the manifest instead of fetching it.
        foreach ($generated as $source => $found) {
            $images[] = [
                'source' => $source,
                'file' => $source,
                'pages' => array_values(array_unique($found['pages'])),
                'role' => 'generated',
            ];
        }

        // The avatar binding `personalize-content` left alone, resolved here
        // because this is where a source becomes something the host imports.
        $avatar = is_array($facts['avatar'] ?? null) ? $facts['avatar'] : [];
        $url = trim((string) ($avatar['url'] ?? ''));

        if ($avatars !== [] && $url !== '') {
            $images[] = [
                'source' => $url,
                'pages' => array_values(array_unique(array_map(
                    static fn (string $ref): string => explode(':', $ref)[0],
                    $avatars,
                ))),
                'role' => 'avatar',
                'alt' => (string) ($avatar['alt'] ?? ''),
            ];
        }

        usort($images, static fn (array $a, array $b): int => strcmp($a['source'], $b['source']));

        return $images;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @param list<string>               $avatars
     * @param array<string, mixed>       $facts
     * @return list<string>
     */
    private static function warnings(array $links, array $avatars, array $facts): array
    {
        $warnings = [];

        $placeholders = array_filter($links, static fn (array $l): bool => $l['kind'] === 'placeholder');
        if ($placeholders !== []) {
            $pages = array_count_values(array_column($placeholders, 'page'));
            $named = [];
            foreach ($pages as $page => $count) {
                $named[] = $page . ' (' . $count . ')';
            }
            $warnings[] = sprintf(
                '%d links still point at the placeholder the pattern shipped with: %s',
                count($placeholders),
                implode(', ', $named),
            );
        }

        $hasAvatar = trim((string) (($facts['avatar']['url'] ?? ''))) !== '';
        if ($avatars !== [] && !$hasAvatar) {
            $warnings[] = sprintf(
                '%d blocks are bound to an avatar the request supplies no image for, so they keep the '
                . "pattern's own: %s",
                count($avatars),
                implode(', ', $avatars),
            );
        }

        return $warnings;
    }

    /**
     * Whose image a source is.
     *
     * A theme's own is recognised by the theme sitting in the path rather than
     * by a fixed prefix, because a host serves its themes from wherever it
     * serves them and the inventory was built against that.
     */
    private static function classify(string $source, string $theme): string
    {
        // A picture `generate-media` made and wrote into the bundle. It is an
        // import like any other, except the host reads it from the bundle
        // rather than fetching it.
        if (preg_match('~^media/[a-f0-9]{24}\.jpg$~D', $source) === 1) {
            return 'generated';
        }

        if ($theme !== '' && str_contains($source, '/themes/' . $theme . '/')) {
            return 'theme';
        }

        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            return 'import';
        }

        return 'unknown';
    }

    /**
     * Every media source a page names: what the browser fetches, and what the
     * block attributes say, since both have to end up pointing at the import.
     *
     * @return list<string>
     */
    private static function sources(string $markup): array
    {
        $sources = [];

        preg_match_all('/\ssrc="([^"]+)"/i', $markup, $matches);
        foreach ($matches[1] as $source) {
            $sources[] = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if (!in_array(BlockText::coreName($document->name($index)), self::MEDIA_BLOCKS, true)) {
                continue;
            }

            $field = BlockText::coreName($document->name($index)) === 'core/media-text' ? 'mediaUrl' : 'url';
            $url = trim((string) ($document->attrs($index)[$field] ?? ''));
            if ($url !== '') {
                $sources[] = $url;
            }
        }

        return array_values(array_filter(array_unique($sources), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Every link a page follows, and whether it goes anywhere.
     *
     * @return list<array{target: string, kind: string}>
     */
    private static function links(string $markup): array
    {
        preg_match_all('/\shref="([^"]*)"/i', $markup, $matches);

        $links = [];
        foreach ($matches[1] as $target) {
            $trimmed = trim($target);
            $kind = match (true) {
                $trimmed === '' || $trimmed === '#' => 'placeholder',
                str_starts_with($trimmed, 'http://'), str_starts_with($trimmed, 'https://') => 'external',
                str_starts_with($trimmed, '#') => 'anchor',
                str_starts_with($trimmed, 'mailto:'), str_starts_with($trimmed, 'tel:') => 'contact',
                default => 'relative',
            };
            $links[] = ['target' => $trimmed, 'kind' => $kind];
        }

        return $links;
    }

    /**
     * Blocks the avatar binding left for this stage, named by where they sit.
     *
     * @return list<string>
     */
    private static function avatarBlocks(string $markup): array
    {
        $document = BlockMarkup::parse($markup);
        $found = [];

        foreach ($document->indices() as $index) {
            $class = (string) ($document->attrs($index)['className'] ?? '');
            if (str_contains($class, ContentBindings::BIND_PREFIX . 'avatar')) {
                $found[] = BlockText::coreName($document->name($index)) . '#' . $index;
            }
        }

        return $found;
    }

    /**
     * Every composed page's markup, by slug.
     *
     * @return array<string, string>
     */
    private static function pages(Project $project): array
    {
        $pages = [];
        foreach (PatternArtifacts::pages($project) as $page) {
            $pages[(string) $page['slug']] = (string) ($page['content'] ?? '');
        }

        return $pages;
    }
}
