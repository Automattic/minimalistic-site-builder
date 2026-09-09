<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * What a preview site is, independent of which backend runs it.
 *
 * Studio accepts Playground Blueprint steps, so one description drives both
 * backends. Only theme activation diverges: `studio create` needs an empty
 * directory, so the theme lands after the blueprint has already run.
 */
final class SitePreset
{
    public const OFFLINE_GUARD_PATH = '/wordpress/wp-content/mu-plugins/0-preview-offline.php';

    /** @return list<array<string,mixed>> */
    public static function sharedSteps(Project $project): array
    {
        return [
            ['step' => 'writeFile', 'path' => self::OFFLINE_GUARD_PATH, 'data' => self::offlineGuardPhp()],
            ['step' => 'setSiteOptions', 'options' => self::siteOptions($project)],
        ];
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    public static function wrapBlueprint(array $steps): array
    {
        return [
            '$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
            'login'       => true,
            'steps'       => $steps,
        ];
    }

    /**
     * WP site identity for a Blueprint's setSiteOptions step: the header's
     * site-title/site-tagline blocks read these options, not the theme.
     * A malformed siteSpec.json falls back to the theme header/slug.
     *
     * @return array<string,string>
     */
    public static function siteOptions(Project $project): array
    {
        $name = self::themeDisplayName($project);
        $blogname = $name !== '' ? $name : $project->slug();
        $blogdescription = '';

        if ($project->exists('siteSpec.json')) {
            try {
                $spec = $project->readJson('siteSpec.json');
            } catch (\RuntimeException) {
                $spec = [];
            }
            $blogname = (string) ($spec['name'] ?? $blogname);
            $blogdescription = self::siteDescription($spec);
        }

        return [
            'blogname'        => $blogname,
            'blogdescription' => $blogdescription,
            // Pretty permalinks so the seeded page tree's paths (/menu/,
            // /menu/breads/) resolve; WP rebuilds rewrite rules lazily and
            // the content plugin flushes them on activation.
            'permalink_structure' => '/%postname%/',
        ];
    }

    /** Theme display name from the style.css header, or '' if absent. */
    public static function themeDisplayName(Project $project): string
    {
        $style = $project->themePath('style.css');
        if (preg_match('/Theme Name:\s*(.+)/', (string) file_get_contents($style), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Tagline for the WP `blogdescription` option: the user's stated tagline,
     * or nothing. The spec's `topic` is deliberately NOT a fallback (BIGR-773):
     * it is a factual description of the whole site — the same semantic content
     * every hero eyebrow/standfirst carries — so rendering it in the header
     * guarantees a near-duplicate small line ~100px above the hero's. A blank
     * tagline is strictly better; header generation drops the block when this
     * is empty.
     *
     * @param array<string,mixed> $spec
     */
    public static function blogDescription(array $spec): string
    {
        return trim((string) ($spec['tagline'] ?? ''));
    }

    /**
     * The string WordPress stores in `blogdescription`.
     *
     * The stated tagline comes first, and it has to: when the header keeps a
     * wp:site-tagline block, that block renders this exact option, so the two
     * can never be allowed to disagree. A header only keeps the block when a
     * tagline was stated (AboveFoldContract::headerTextFacts), so with no
     * tagline nothing on the page renders this and the option is free to
     * carry the spec's `description` — one factual sentence about the site,
     * and a required field. BIGR-773 still holds: blogDescription() above —
     * tagline or nothing — is what the header reads to decide, and it is
     * untouched.
     *
     * `topic` is not a third rung. WordPress appends this option to the front
     * page's <title> (wp_get_document_title), so a bare topic phrase would
     * read as "Corner Bakery – sourdough" in the tab and in search results.
     *
     * Filling the option matters because a shared link has no other source.
     * Jetpack builds the front page's og:description from
     * get_bloginfo('description') and never from the page's own content, so
     * an empty option ships a Slack or Facebook card with no description at
     * all: the card falls back to the Twitter default, "Visit the post for
     * more."
     *
     * @param array<string,mixed> $spec
     */
    public static function siteDescription(array $spec): string
    {
        foreach (['tagline', 'description'] as $key) {
            $value = trim((string) ($spec[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** Mu-plugin body that fails outbound HTTP fast in the local preview. */
    public static function offlineGuardPhp(): string
    {
        return <<<'PHP'
                <?php
                /**
                 * A local preview site cannot complete outbound requests.
                 * Resolve oEmbeds to a plain link and fail any other WordPress
                 * HTTP request fast so a render never pins a worker.
                 */
                add_filter( 'pre_oembed_result', function ( $result, $url ) {
                    return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
                }, 10, 2 );
                add_filter( 'pre_http_request', function () {
                    return new WP_Error( 'http_request_failed', 'Outbound HTTP is disabled in this local preview.' );
                } );

                /**
                 * The three update checks read a blocked request as an SSL failure and
                 * raise E_USER_WARNING, which lands mid-page in wp-admin and then breaks
                 * every later header() call. Answer their transients as already-fresh so
                 * _maybe_update_core/plugins/themes return before asking at all.
                 */
                $sb_up_to_date = function () {
                    return (object) array(
                        'last_checked'    => time(),
                        'version_checked' => get_bloginfo( 'version' ),
                        'updates'         => array(),
                        'response'        => array(),
                        'translations'    => array(),
                    );
                };
                add_filter( 'pre_site_transient_update_core', $sb_up_to_date );
                add_filter( 'pre_site_transient_update_plugins', $sb_up_to_date );
                add_filter( 'pre_site_transient_update_themes', $sb_up_to_date );
                PHP;
    }
}
