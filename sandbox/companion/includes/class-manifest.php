<?php
/**
 * Registry -> Manifest compiler + fingerprint.
 *
 * Trimmed port of x-companion's X_Companion_Manifest. Two layers:
 *
 *  - LIVE layer  (snapshot_registry(), active_theme(), active_plugins(),
 *                 theme_tokens(), styles_map(), global_styles_stamp())
 *                 touches WordPress.
 *  - PURE layer  (canonicalize(), canonical_json(), fingerprint_inputs(),
 *                 compute_fingerprint(), build_blocks(), build()) is a
 *                 function of an injected registry snapshot and no globals.
 *
 * The fingerprint is the epoch every Tree IR carries: it moves when the block
 * registry, active theme/plugins, or the user-origin global styles change —
 * which is exactly what a design-token write touches.
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manifest compiler.
 */
final class MSB_Companion_Manifest {

    /**
     * Transient name prefix. The remainder of the name is the fingerprint,
     * so a registry change invalidates the cache by construction.
     */
    const TRANSIENT_PREFIX = 'msb_companion_manifest_';

    /** Option tracking the transient currently in play, so bust_cache() can find it. */
    const CACHE_KEY_OPTION = 'msb_companion_manifest_cache_key';

    /** Manifest transient TTL. The fingerprint is the real invalidator; this is a backstop. */
    const TRANSIENT_TTL = DAY_IN_SECONDS;

    /**
     * Per-request memo of the registry snapshot.
     *
     * @var array<string,array>|null
     */
    private static $snapshot = null;

    /**
     * Per-request memo of the fingerprint.
     *
     * @var string|null
     */
    private static $fingerprint = null;

    /**
     * Per-request memo of the built manifest.
     *
     * @var array|null
     */
    private static $manifest = null;

    /**
     * Register cache invalidation hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'activated_plugin', array( __CLASS__, 'bust_cache' ) );
        add_action( 'deactivated_plugin', array( __CLASS__, 'bust_cache' ) );
        add_action( 'switch_theme', array( __CLASS__, 'bust_cache' ) );
    }

    /*
     * -------------------------------------------------------------------
     * Public API
     * -------------------------------------------------------------------
     */

    /**
     * Drop every cached manifest artefact.
     *
     * Call after anything that changes the registry or global styles
     * mid-request, then read fingerprint( true ) for the new epoch.
     *
     * @return void
     */
    public static function bust_cache(): void {
        self::$snapshot    = null;
        self::$fingerprint = null;
        self::$manifest    = null;

        if ( function_exists( 'get_option' ) ) {
            $key = get_option( self::CACHE_KEY_OPTION );
            if ( is_string( $key ) && '' !== $key ) {
                delete_transient( $key );
            }
            delete_option( self::CACHE_KEY_OPTION );
        }
    }

    /**
     * The epoch.
     *
     * @param bool $force_refresh Recompute even if memoised this request.
     * @return string 64 hex characters.
     */
    public static function fingerprint( bool $force_refresh = false ): string {
        if ( $force_refresh ) {
            self::$snapshot    = null;
            self::$fingerprint = null;
        }

        if ( null !== self::$fingerprint ) {
            return self::$fingerprint;
        }

        $inputs = self::fingerprint_inputs(
            self::snapshot_registry(),
            self::active_theme(),
            self::active_plugins(),
            self::global_styles_stamp()
        );

        self::$fingerprint = self::compute_fingerprint( $inputs );

        return self::$fingerprint;
    }

    /**
     * The full manifest.
     *
     * Cached in a transient keyed by the fingerprint. Every call recomputes
     * the cheap fingerprint; the heavy body is rebuilt only when it moved.
     *
     * @param bool $force_refresh Bypass both memo and transient.
     * @return array Manifest.
     */
    public static function get_manifest( bool $force_refresh = false ): array {
        if ( $force_refresh ) {
            self::bust_cache();
        }

        if ( null !== self::$manifest ) {
            return self::$manifest;
        }

        $fingerprint = self::fingerprint();
        $key         = self::TRANSIENT_PREFIX . substr( $fingerprint, 0, 32 );

        if ( ! $force_refresh ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) && isset( $cached['fingerprint'] ) && $cached['fingerprint'] === $fingerprint ) {
                self::$manifest = $cached;

                return $cached;
            }
        }

        $manifest = self::build(
            self::snapshot_registry(),
            array(
                'fingerprint'  => $fingerprint,
                'generated_at' => gmdate( 'c' ),
                'wp_version'   => (string) get_bloginfo( 'version' ),
                'site_url'     => (string) get_site_url(),
                'theme_tokens' => self::theme_tokens(),
                'block_styles' => self::styles_map(),
            )
        );

        set_transient( $key, $manifest, self::TRANSIENT_TTL );
        update_option( self::CACHE_KEY_OPTION, $key, false );

        self::$manifest = $manifest;

        return $manifest;
    }

    /*
     * -------------------------------------------------------------------
     * Pure layer: canonical JSON
     * -------------------------------------------------------------------
     */

    /**
     * Recursively sort object keys ascending byte order.
     *
     * PHP list arrays keep their order (they are JSON arrays). PHP assoc
     * arrays and stdClass are JSON objects and are ksort()ed at every depth.
     * stdClass is preserved as stdClass so an empty object stays `{}` rather
     * than collapsing to `[]`.
     *
     * @param mixed $value Value.
     * @return mixed Canonicalised value.
     */
    public static function canonicalize( $value ) {
        if ( $value instanceof stdClass ) {
            $assoc = (array) $value;
            ksort( $assoc, SORT_STRING );
            foreach ( $assoc as $k => $v ) {
                $assoc[ $k ] = self::canonicalize( $v );
            }

            return (object) $assoc;
        }

        if ( is_array( $value ) ) {
            if ( array_is_list( $value ) ) {
                return array_map( array( __CLASS__, 'canonicalize' ), $value );
            }

            ksort( $value, SORT_STRING );
            foreach ( $value as $k => $v ) {
                $value[ $k ] = self::canonicalize( $v );
            }

            return $value;
        }

        return $value;
    }

    /**
     * Canonical JSON: UTF-8, object keys sorted ascending byte order at every
     * depth, no insignificant whitespace, `/` and unicode not escaped.
     *
     * @param mixed $value Value.
     * @return string JSON.
     */
    public static function canonical_json( $value ): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $canonical = self::canonicalize( $value );

        if ( function_exists( 'wp_json_encode' ) ) {
            $json = wp_json_encode( $canonical, $flags );
        } else {
            $json = json_encode( $canonical, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        }

        return is_string( $json ) ? $json : '';
    }

    /*
     * -------------------------------------------------------------------
     * Pure layer: fingerprint
     * -------------------------------------------------------------------
     */

    /**
     * Build the fingerprint inputs object.
     *
     * @param array  $snapshot      Registry snapshot, name => normalised block.
     * @param array  $theme         { slug, version }.
     * @param array  $plugins       List of { slug, version }.
     * @param string $global_styles Stamp of the user global-styles post.
     * @return array
     */
    public static function fingerprint_inputs( array $snapshot, array $theme, array $plugins, string $global_styles = '' ): array {
        $names = array_keys( $snapshot );
        usort( $names, 'strcmp' );

        $blocks = array();
        foreach ( $names as $name ) {
            $block    = $snapshot[ $name ];
            $blocks[] = array(
                'name'        => (string) $name,
                'api_version' => (int) ( $block['api_version'] ?? 1 ),
                'attributes'  => self::as_object( $block['attributes'] ?? array() ),
                'parent'      => self::as_sorted_list_or_null( $block['parent'] ?? null ),
                'ancestor'    => self::as_sorted_list_or_null( $block['ancestor'] ?? null ),
            );
        }

        usort(
            $plugins,
            static function ( $a, $b ) {
                return strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) );
            }
        );

        $plugin_list = array();
        foreach ( $plugins as $plugin ) {
            $plugin_list[] = array(
                'slug'    => (string) ( $plugin['slug'] ?? '' ),
                'version' => (string) ( $plugin['version'] ?? '' ),
            );
        }

        return array(
            'interfaces_version' => '1',
            'blocks'             => $blocks,
            'theme'              => array(
                'slug'    => (string) ( $theme['slug'] ?? '' ),
                'version' => (string) ( $theme['version'] ?? '' ),
            ),
            'plugins'            => $plugin_list,
            'global_styles'      => $global_styles,
        );
    }

    /**
     * sha256 of the canonical JSON of the inputs.
     *
     * @param array $inputs Result of fingerprint_inputs().
     * @return string 64 hex characters.
     */
    public static function compute_fingerprint( array $inputs ): string {
        return hash( 'sha256', self::canonical_json( $inputs ) );
    }

    /*
     * -------------------------------------------------------------------
     * Pure layer: manifest body
     * -------------------------------------------------------------------
     */

    /**
     * Compile the manifest `blocks` map from a registry snapshot.
     *
     * @param array $snapshot   Registry snapshot.
     * @param array $styles_map block name => registered styles.
     * @return array name => block entry.
     */
    public static function build_blocks( array $snapshot, array $styles_map = array() ): array {
        $names = array_keys( $snapshot );
        usort( $names, 'strcmp' );

        $blocks = array();

        foreach ( $names as $name ) {
            $block = $snapshot[ $name ];

            $blocks[ (string) $name ] = array(
                'title'            => (string) ( $block['title'] ?? $name ),
                'category'         => isset( $block['category'] ) ? ( null === $block['category'] ? null : (string) $block['category'] ) : null,
                'api_version'      => (int) ( $block['api_version'] ?? 1 ),
                'attributes'       => self::as_object( $block['attributes'] ?? array() ),
                'supports'         => self::as_object( $block['supports'] ?? array() ),
                'parent'           => self::as_sorted_list_or_null( $block['parent'] ?? null ),
                'ancestor'         => self::as_sorted_list_or_null( $block['ancestor'] ?? null ),
                'is_dynamic'       => (bool) ( $block['is_dynamic'] ?? false ),
                'variations_count' => (int) ( $block['variations_count'] ?? 0 ),
                'variations'       => array_values( (array) ( $block['variations'] ?? array() ) ),
                'styles'           => array_values( (array) ( $styles_map[ (string) $name ] ?? array() ) ),
            );
        }

        return $blocks;
    }

    /**
     * Trim registry variations to the manifest shape.
     *
     * Icon (often a large inline SVG), example and keywords are deliberately
     * dropped — they inform pickers, not generation.
     *
     * @param array $variations Raw registry variations.
     * @return array
     */
    public static function normalize_variations( array $variations ): array {
        $out = array();

        foreach ( $variations as $variation ) {
            if ( ! is_array( $variation ) || '' === (string) ( $variation['name'] ?? '' ) ) {
                continue;
            }

            $entry = array(
                'name'  => (string) $variation['name'],
                'title' => (string) ( $variation['title'] ?? $variation['name'] ),
            );

            if ( isset( $variation['description'] ) && '' !== (string) $variation['description'] ) {
                $entry['description'] = (string) $variation['description'];
            }
            if ( isset( $variation['scope'] ) && is_array( $variation['scope'] ) ) {
                $entry['scope'] = array_values( array_map( 'strval', $variation['scope'] ) );
            }
            if ( ! empty( $variation['isDefault'] ) ) {
                $entry['isDefault'] = true;
            }
            if ( isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ) {
                $entry['attributes'] = self::as_object( $variation['attributes'] );
            }
            if ( isset( $variation['innerBlocks'] ) && is_array( $variation['innerBlocks'] ) ) {
                $entry['innerBlocks'] = $variation['innerBlocks'];
            }

            $out[] = $entry;
        }

        usort(
            $out,
            static function ( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            }
        );

        return $out;
    }

    /**
     * Assemble the whole manifest from a snapshot plus a live context bundle.
     *
     * @param array $snapshot Registry snapshot.
     * @param array $context  fingerprint, generated_at, wp_version, site_url,
     *                        theme_tokens, block_styles.
     * @return array Manifest.
     */
    public static function build( array $snapshot, array $context ): array {
        $blocks = self::build_blocks( $snapshot, (array) ( $context['block_styles'] ?? array() ) );

        $dynamic = 0;
        foreach ( $blocks as $block ) {
            if ( ! empty( $block['is_dynamic'] ) ) {
                ++$dynamic;
            }
        }

        return array(
            'fingerprint'  => (string) ( $context['fingerprint'] ?? '' ),
            'generated_at' => (string) ( $context['generated_at'] ?? '' ),
            'wp_version'   => (string) ( $context['wp_version'] ?? '' ),
            'site_url'     => (string) ( $context['site_url'] ?? '' ),
            'blocks'       => $blocks,
            'theme_tokens' => self::normalize_theme_tokens( (array) ( $context['theme_tokens'] ?? array() ) ),
            'counts'       => array(
                'blocks'         => count( $blocks ),
                'dynamic_blocks' => $dynamic,
                'static_blocks'  => count( $blocks ) - $dynamic,
            ),
        );
    }

    /**
     * Guarantee the four required theme_tokens groups exist.
     *
     * @param array $tokens Raw tokens.
     * @return array
     */
    public static function normalize_theme_tokens( array $tokens ): array {
        return array(
            'color'      => array(
                'palette' => $tokens['color']['palette'] ?? self::as_object( array() ),
            ),
            'spacing'    => array(
                'spacingSizes' => $tokens['spacing']['spacingSizes'] ?? self::as_object( array() ),
                'spacingScale' => $tokens['spacing']['spacingScale'] ?? self::as_object( array() ),
            ),
            'typography' => array(
                'fontSizes'    => $tokens['typography']['fontSizes'] ?? self::as_object( array() ),
                'fontFamilies' => $tokens['typography']['fontFamilies'] ?? self::as_object( array() ),
            ),
            'layout'     => array(
                'contentSize' => $tokens['layout']['contentSize'] ?? '',
                'wideSize'    => $tokens['layout']['wideSize'] ?? '',
            ),
        );
    }

    /**
     * A stamp for the user-origin global styles, so design-token writes move
     * the fingerprint.
     *
     * Without this, POST /theme/tokens changed what the manifest reports
     * (theme_tokens) while the fingerprint stayed put, and clients kept
     * reading stale tokens. The stamp is the sha256 of the user
     * global-styles post content ('' when none exists) — exactly the surface
     * the tokens route writes.
     *
     * @return string 64 hex characters, or ''.
     */
    public static function global_styles_stamp(): string {
        if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
            return '';
        }

        $post_id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

        if ( $post_id <= 0 ) {
            return '';
        }

        $post = get_post( $post_id );

        if ( ! $post || '' === (string) $post->post_content ) {
            return '';
        }

        return hash( 'sha256', (string) $post->post_content );
    }

    /*
     * -------------------------------------------------------------------
     * Live layer
     * -------------------------------------------------------------------
     */

    /**
     * Normalise the live block registry into the snapshot shape.
     *
     * This is the ONLY place the manifest reads WP_Block_Type_Registry.
     *
     * @return array name => normalised block.
     */
    public static function snapshot_registry(): array {
        if ( null !== self::$snapshot ) {
            return self::$snapshot;
        }

        $snapshot = array();

        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
            self::$snapshot = $snapshot;

            return $snapshot;
        }

        $registry = WP_Block_Type_Registry::get_instance();

        foreach ( $registry->get_all_registered() as $name => $type ) {
            $attributes = method_exists( $type, 'get_attributes' )
                ? $type->get_attributes()
                : (array) ( $type->attributes ?? array() );

            $variations = $type->variations ?? null;

            $snapshot[ (string) $name ] = array(
                'title'            => (string) ( $type->title ?? $name ),
                'category'         => isset( $type->category ) ? $type->category : null,
                'api_version'      => (int) ( $type->api_version ?? 1 ),
                'attributes'       => is_array( $attributes ) ? $attributes : array(),
                'supports'         => (array) ( $type->supports ?? array() ),
                'parent'           => is_array( $type->parent ?? null ) ? $type->parent : null,
                'ancestor'         => is_array( $type->ancestor ?? null ) ? $type->ancestor : null,
                'is_dynamic'       => method_exists( $type, 'is_dynamic' ) ? (bool) $type->is_dynamic() : is_callable( $type->render_callback ?? null ),
                'variations_count' => is_array( $variations ) ? count( $variations ) : 0,
                'variations'       => self::normalize_variations( is_array( $variations ) ? $variations : array() ),
            );
        }

        self::$snapshot = $snapshot;

        return $snapshot;
    }

    /**
     * Server-registered block styles, block name => list of { name, label }.
     *
     * @return array<string,array>
     */
    public static function styles_map(): array {
        if ( ! class_exists( 'WP_Block_Styles_Registry' ) ) {
            return array();
        }

        $map = array();

        foreach ( WP_Block_Styles_Registry::get_instance()->get_all_registered() as $block_name => $styles ) {
            $list = array();
            foreach ( (array) $styles as $style_name => $style ) {
                $style  = is_array( $style ) ? $style : array();
                $list[] = array(
                    'name'  => (string) ( $style['name'] ?? $style_name ),
                    'label' => (string) ( $style['label'] ?? $style_name ),
                );
            }
            if ( array() !== $list ) {
                usort(
                    $list,
                    static function ( $a, $b ) {
                        return strcmp( $a['name'], $b['name'] );
                    }
                );
                $map[ (string) $block_name ] = $list;
            }
        }

        return $map;
    }

    /**
     * Active theme slug + version.
     *
     * @return array { slug, version }
     */
    public static function active_theme(): array {
        if ( ! function_exists( 'wp_get_theme' ) ) {
            return array(
                'slug'    => '',
                'version' => '',
            );
        }

        $theme = wp_get_theme();

        return array(
            'slug'    => (string) $theme->get_stylesheet(),
            'version' => (string) $theme->get( 'Version' ),
        );
    }

    /**
     * Active plugins as { slug, version }.
     *
     * @return array List of { slug, version }.
     */
    public static function active_plugins(): array {
        $files = (array) get_option( 'active_plugins', array() );

        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $network = get_site_option( 'active_sitewide_plugins', array() );
            if ( is_array( $network ) ) {
                $files = array_merge( $files, array_keys( $network ) );
            }
        }

        $files   = array_values( array_unique( array_map( 'strval', $files ) ) );
        $plugins = array();

        foreach ( $files as $file ) {
            $dir  = dirname( $file );
            $slug = ( '.' === $dir || '' === $dir ) ? basename( $file, '.php' ) : $dir;

            $version = '';
            $path    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $file : '';
            if ( $path && file_exists( $path ) && function_exists( 'get_file_data' ) ) {
                $data    = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
                $version = (string) ( $data['Version'] ?? '' );
            }

            $plugins[] = array(
                'slug'    => $slug,
                'version' => $version,
            );
        }

        return $plugins;
    }

    /**
     * The resolved theme token subset.
     *
     * @return array
     */
    public static function theme_tokens(): array {
        if ( ! function_exists( 'wp_get_global_settings' ) ) {
            return self::normalize_theme_tokens( array() );
        }

        $settings = wp_get_global_settings();
        $settings = is_array( $settings ) ? $settings : array();

        return self::normalize_theme_tokens(
            array(
                'color'      => array(
                    'palette' => $settings['color']['palette'] ?? self::as_object( array() ),
                ),
                'spacing'    => array(
                    'spacingSizes' => $settings['spacing']['spacingSizes'] ?? self::as_object( array() ),
                    'spacingScale' => $settings['spacing']['spacingScale'] ?? self::as_object( array() ),
                ),
                'typography' => array(
                    'fontSizes'    => $settings['typography']['fontSizes'] ?? self::as_object( array() ),
                    'fontFamilies' => $settings['typography']['fontFamilies'] ?? self::as_object( array() ),
                ),
                'layout'     => array(
                    'contentSize' => $settings['layout']['contentSize'] ?? '',
                    'wideSize'    => $settings['layout']['wideSize'] ?? '',
                ),
            )
        );
    }

    /*
     * -------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------
     */

    /**
     * Force a value to encode as a JSON object.
     *
     * An empty PHP array encodes as `[]`; the manifest shape demands `{}` for
     * attributes/supports, so empties become stdClass.
     *
     * @param mixed $value Value.
     * @return array|stdClass
     */
    public static function as_object( $value ) {
        if ( $value instanceof stdClass ) {
            return $value;
        }

        if ( ! is_array( $value ) || array() === $value ) {
            return new stdClass();
        }

        return $value;
    }

    /**
     * Null, or the list sorted ascending.
     *
     * @param mixed $value Value.
     * @return array|null
     */
    public static function as_sorted_list_or_null( $value ) {
        if ( ! is_array( $value ) || array() === $value ) {
            return null;
        }

        $list = array_values( array_map( 'strval', $value ) );
        usort( $list, 'strcmp' );

        return $list;
    }
}
