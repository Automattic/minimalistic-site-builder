<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Decides whether a Studio site directory may be destroyed.
 *
 * The site root is a real user workspace holding hand-made sites, so a rebuild
 * may only delete a directory this project created and marked. Delete this
 * guard and a build with a colliding --slug destroys someone's real site.
 * A symlink is refused outright: a link into an unrelated directory with a
 * marker planted inside it is the one route to destroying the wrong thing.
 */
final class StudioSiteGuard
{
    public const MARKER = '.sb-site.json';

    /** @return 'create'|'recreate'|'refuse' */
    public static function decide(string $path, string $slug): string
    {
        if (is_link($path)) {
            return 'refuse';
        }
        if (!file_exists($path)) {
            return 'create';
        }
        $marker = $path . '/' . self::MARKER;
        if (!is_file($marker)) {
            return 'refuse';
        }
        $decoded = json_decode((string) file_get_contents($marker), true);
        if (!is_array($decoded) || ($decoded['slug'] ?? null) !== $slug) {
            return 'refuse';
        }
        return 'recreate';
    }

    public static function refusalMessage(string $path): string
    {
        return "{$path} already exists and was not created by site-builder, so it will not be removed.\n"
            . "Either build with a different --slug, or delete that directory yourself first.";
    }

    public static function writeMarker(string $path, string $slug, string $repo): void
    {
        file_put_contents($path . '/' . self::MARKER, json_encode([
            'generator' => 'site-builder',
            'slug'      => $slug,
            'repo'      => $repo,
            'created'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
