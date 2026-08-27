<?php
/**
 * POST /theme/tokens — the design-tokens compiler.
 *
 * Trimmed port of x-companion's X_Companion_Theme_Tokens (no snapshot export,
 * no suite adapters, no theme.json file writing). The write path is the
 * user-origin global styles CPT (`wp_global_styles`): it works on a read-only
 * theme directory, survives theme updates, and is the same origin the site
 * editor writes to, so what the pipeline applies is what a human would see in
 * Styles.
 *
 * The route always returns the compiled theme.json settings preview and a
 * diff against the instance's current theme tokens, so dry_run:true is a free
 * rehearsal.
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Design tokens compiler.
 */
final class MSB_Companion_Theme_Tokens {

    /**
     * No hooks. Present so the bootstrap loader can call ::init() uniformly.
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * POST /theme/tokens.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function route_tokens( WP_REST_Request $request ) {
        $tokens = $request->get_json_params();

        if ( ! is_array( $tokens ) ) {
            return new WP_Error(
                'rest_invalid_param',
                'The request body must be a DesignTokens object.',
                array( 'status' => 400 )
            );
        }

        $settings = self::compile( $tokens );
        $preview  = $settings;
        $diff     = self::diff_against_instance( $settings, MSB_Companion_Manifest::theme_tokens() );

        if ( $request->get_param( 'dry_run' ) ) {
            return array(
                'applied'               => false,
                'dry_run'               => true,
                'fingerprint'           => MSB_Companion_Manifest::fingerprint(),
                'theme_json_preview'    => $preview,
                'diff_against_instance' => $diff,
            );
        }

        $css     = self::compile_css( $tokens );
        $written = self::write_global_styles( $settings, $css['styles'] );

        // The manifest body carries theme_tokens, so it is now stale.
        MSB_Companion_Manifest::bust_cache();

        return array(
            'applied'               => true,
            'dry_run'               => false,
            'theme_json_written'    => $written,
            'fingerprint'           => MSB_Companion_Manifest::fingerprint( true ),
            'theme_json_preview'    => $preview,
            'diff_against_instance' => $diff,
            'css_written'           => array() !== $css['styles'],
            'css_rejected'          => $css['rejected'],
        );
    }

    /**
     * Diff compiled settings against the instance's theme tokens.
     *
     * Faithful port of the x-agent theme-json emitter's
     * diffAgainstThemeTokens(): best effort — the instance shape is loose
     * (origin-keyed arrays on real instances), so anything unrecognised is
     * skipped rather than guessed at, which surfaces as missing_on_instance.
     *
     * @param array $settings     Compiled theme.json settings fragment.
     * @param array $theme_tokens Instance theme tokens (four groups).
     * @return array List of { group, slug, kind, expected, actual }.
     */
    public static function diff_against_instance( array $settings, array $theme_tokens ): array {
        $diffs = array();

        $index_by_slug = static function ( $value, string $value_key ): array {
            $map = array();
            if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
                return $map;
            }
            foreach ( $value as $entry ) {
                if ( is_array( $entry ) && isset( $entry['slug'] ) && is_string( $entry['slug'] ) ) {
                    $val                          = $entry[ $value_key ] ?? '';
                    $map[ (string) $entry['slug'] ] = is_scalar( $val ) ? (string) $val : '';
                }
            }
            return $map;
        };

        $compare = static function ( string $group, array $expected, array $actual ) use ( &$diffs ): void {
            foreach ( $expected as $e ) {
                if ( ! array_key_exists( $e['slug'], $actual ) ) {
                    $diffs[] = array(
                        'group'    => $group,
                        'slug'     => $e['slug'],
                        'kind'     => 'missing_on_instance',
                        'expected' => $e['value'],
                        'actual'   => null,
                    );
                } elseif ( strtolower( $actual[ $e['slug'] ] ) !== strtolower( $e['value'] ) ) {
                    $diffs[] = array(
                        'group'    => $group,
                        'slug'     => $e['slug'],
                        'kind'     => 'value_differs',
                        'expected' => $e['value'],
                        'actual'   => $actual[ $e['slug'] ],
                    );
                }
            }
        };

        $expected_of = static function ( array $entries, string $value_key ): array {
            $out = array();
            foreach ( $entries as $entry ) {
                if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
                    $out[] = array(
                        'slug'  => (string) $entry['slug'],
                        'value' => (string) ( $entry[ $value_key ] ?? '' ),
                    );
                }
            }
            return $out;
        };

        $compare(
            'color.palette',
            $expected_of( (array) ( $settings['color']['palette'] ?? array() ), 'color' ),
            $index_by_slug( $theme_tokens['color']['palette'] ?? null, 'color' )
        );
        $compare(
            'spacing.spacingSizes',
            $expected_of( (array) ( $settings['spacing']['spacingSizes'] ?? array() ), 'size' ),
            $index_by_slug( $theme_tokens['spacing']['spacingSizes'] ?? null, 'size' )
        );
        $compare(
            'typography.fontSizes',
            $expected_of( (array) ( $settings['typography']['fontSizes'] ?? array() ), 'size' ),
            $index_by_slug( $theme_tokens['typography']['fontSizes'] ?? null, 'size' )
        );
        $compare(
            'typography.fontFamilies',
            $expected_of( (array) ( $settings['typography']['fontFamilies'] ?? array() ), 'fontFamily' ),
            $index_by_slug( $theme_tokens['typography']['fontFamilies'] ?? null, 'fontFamily' )
        );

        $layout = (array) ( $theme_tokens['layout'] ?? array() );
        foreach ( array( 'contentSize', 'wideSize' ) as $key ) {
            if ( ! isset( $settings['layout'][ $key ] ) ) {
                continue;
            }
            $expected = (string) $settings['layout'][ $key ];
            if ( ! array_key_exists( $key, $layout ) ) {
                $diffs[] = array(
                    'group'    => 'layout',
                    'slug'     => $key,
                    'kind'     => 'missing_on_instance',
                    'expected' => $expected,
                    'actual'   => null,
                );
            } elseif ( (string) $layout[ $key ] !== $expected ) {
                $diffs[] = array(
                    'group'    => 'layout',
                    'slug'     => $key,
                    'kind'     => 'value_differs',
                    'expected' => $expected,
                    'actual'   => (string) $layout[ $key ],
                );
            }
        }

        return $diffs;
    }

    /**
     * DesignTokens `css` section -> a theme.json `styles` fragment.
     *
     * Validation mirrors core's
     * WP_REST_Global_Styles_Controller::validate_custom_css (markup in a css
     * string is rejected); an unknown block name is rejected too. Every
     * rejection is itemized — never silently dropped.
     *
     * @param array $tokens DesignTokens (may carry `css`).
     * @return array{styles:array,rejected:array<int,array{target:string,reason:string}>}
     */
    public static function compile_css( array $tokens ): array {
        $css = $tokens['css'] ?? null;
        if ( ! is_array( $css ) ) {
            return array(
                'styles'   => array(),
                'rejected' => array(),
            );
        }

        $styles   = array();
        $rejected = array();

        $validate = static function ( string $value ): ?string {
            // Core's validate_custom_css: markup inside a css payload.
            if ( preg_match( '#</?\w+#', $value ) ) {
                return 'markup is not allowed in css';
            }

            return null;
        };

        $global = $css['global'] ?? null;
        if ( is_string( $global ) && '' !== trim( $global ) ) {
            $reason = $validate( $global );
            if ( null === $reason ) {
                $styles['css'] = $global;
            } else {
                $rejected[] = array(
                    'target' => 'global',
                    'reason' => $reason,
                );
            }
        }

        $registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
        if ( $registry && ! method_exists( $registry, 'is_registered' ) ) {
            $registry = null;
        }

        foreach ( (array) ( $css['blocks'] ?? array() ) as $block_name => $value ) {
            $block_name = (string) $block_name;

            if ( ! is_string( $value ) || '' === trim( $value ) ) {
                continue;
            }

            if ( $registry && ! $registry->is_registered( $block_name ) ) {
                $rejected[] = array(
                    'target' => $block_name,
                    'reason' => 'block is not registered on this instance',
                );
                continue;
            }

            $reason = $validate( $value );
            if ( null !== $reason ) {
                $rejected[] = array(
                    'target' => $block_name,
                    'reason' => $reason,
                );
                continue;
            }

            $styles['blocks'][ $block_name ] = array( 'css' => $value );
        }

        return array(
            'styles'   => $styles,
            'rejected' => $rejected,
        );
    }

    /**
     * DesignTokens -> a theme.json `settings` object.
     *
     * Only the groups the contract names are emitted. Everything else in the
     * target document is left alone by the merge.
     *
     * @param array $tokens DesignTokens.
     * @return array theme.json settings fragment.
     */
    public static function compile( array $tokens ): array {
        $settings = array();

        $palette = array();
        foreach ( (array) ( $tokens['palette'] ?? array() ) as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['slug'] ) || empty( $entry['color'] ) ) {
                continue;
            }

            $palette[] = array(
                'slug'  => (string) $entry['slug'],
                'name'  => (string) ( $entry['name'] ?? $entry['slug'] ),
                'color' => (string) $entry['color'],
            );
        }

        if ( ! empty( $palette ) ) {
            $settings['color'] = array( 'palette' => $palette );
        }

        $sizes = array();
        foreach ( (array) ( $tokens['spacing']['steps'] ?? array() ) as $step ) {
            if ( ! is_array( $step ) || empty( $step['slug'] ) || ! isset( $step['size'] ) ) {
                continue;
            }

            $sizes[] = array(
                'size' => (string) $step['size'],
                'slug' => (string) $step['slug'],
                'name' => (string) ( $step['name'] ?? $step['slug'] ),
            );
        }

        if ( ! empty( $sizes ) ) {
            $settings['spacing'] = array(
                'spacingSizes' => $sizes,
                // A spacingSizes array and a generated spacingScale fight each
                // other; turning the generator off is what the site editor does
                // when a custom set is present.
                'spacingScale' => array( 'steps' => 0 ),
            );
        }

        $font_sizes = array();
        foreach ( (array) ( $tokens['typography']['sizes'] ?? array() ) as $size ) {
            if ( ! is_array( $size ) || empty( $size['slug'] ) || ! isset( $size['size'] ) ) {
                continue;
            }

            $entry = array(
                'size' => (string) $size['size'],
                'slug' => (string) $size['slug'],
                'name' => (string) ( $size['name'] ?? $size['slug'] ),
            );

            if ( array_key_exists( 'fluid', $size ) ) {
                $entry['fluid'] = is_array( $size['fluid'] ) ? $size['fluid'] : (bool) $size['fluid'];
            }

            $font_sizes[] = $entry;
        }

        $families = array();
        foreach ( (array) ( $tokens['typography']['families'] ?? array() ) as $family ) {
            if ( ! is_array( $family ) || empty( $family['slug'] ) || empty( $family['fontFamily'] ) ) {
                continue;
            }

            $families[] = array(
                'fontFamily' => (string) $family['fontFamily'],
                'slug'       => (string) $family['slug'],
                'name'       => (string) ( $family['name'] ?? $family['slug'] ),
            );
        }

        if ( ! empty( $font_sizes ) || ! empty( $families ) ) {
            $settings['typography'] = array();

            if ( ! empty( $font_sizes ) ) {
                $settings['typography']['fontSizes'] = $font_sizes;
            }

            if ( ! empty( $families ) ) {
                $settings['typography']['fontFamilies'] = $families;
            }
        }

        $layout = array();
        foreach ( array( 'contentSize', 'wideSize' ) as $key ) {
            if ( isset( $tokens['layout'][ $key ] ) && '' !== $tokens['layout'][ $key ] ) {
                $layout[ $key ] = (string) $tokens['layout'][ $key ];
            }
        }

        if ( ! empty( $layout ) ) {
            $settings['layout'] = $layout;
        }

        return $settings;
    }

    /**
     * Deep merge that treats lists as atomic values.
     *
     * `settings.color.palette` is replaced wholesale — it is the token set —
     * while `settings.color.custom`, `settings.spacing.units` and every other
     * unrelated key survive untouched.
     *
     * @param array $base     Existing settings.
     * @param array $incoming Compiled settings.
     * @return array
     */
    public static function merge_settings( array $base, array $incoming ): array {
        foreach ( $incoming as $key => $value ) {
            $mergeable = is_array( $value )
                && ! array_is_list( $value )
                && isset( $base[ $key ] )
                && is_array( $base[ $key ] )
                && ! array_is_list( $base[ $key ] );

            $base[ $key ] = $mergeable ? self::merge_settings( $base[ $key ], $value ) : $value;
        }

        return $base;
    }

    /**
     * Write the compiled settings into the user-origin global styles CPT.
     *
     * @param array $settings theme.json settings fragment.
     * @param array $styles   theme.json styles fragment (custom css).
     * @return bool
     */
    public static function write_global_styles( array $settings, array $styles = array() ): bool {
        if ( ( empty( $settings ) && empty( $styles ) ) || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
            return false;
        }

        $post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

        if ( ! $post_id ) {
            return false;
        }

        $post = get_post( $post_id );

        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        $config = json_decode( (string) $post->post_content, true );
        $config = is_array( $config ) ? $config : array();

        $config['version']                     = class_exists( 'WP_Theme_JSON' ) ? WP_Theme_JSON::LATEST_SCHEMA : 3;
        $config['isGlobalStylesUserThemeJSON'] = true;
        $config['settings']                    = self::merge_settings( (array) ( $config['settings'] ?? array() ), $settings );

        if ( array() !== $styles ) {
            $config['styles'] = self::merge_settings( (array) ( $config['styles'] ?? array() ), $styles );
        }

        $json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( ! is_string( $json ) ) {
            return false;
        }

        // wp_update_post() unslashes; font family stacks are full of escaped
        // quotes, so the JSON must go in slashed or it comes out broken.
        $updated = wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => wp_slash( $json ),
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return false;
        }

        self::flush_global_styles_cache();

        return true;
    }

    /**
     * Drop every layer of theme.json caching.
     *
     * @return void
     */
    private static function flush_global_styles_cache(): void {
        if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
            WP_Theme_JSON_Resolver::clean_cached_data();
        }

        if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
            wp_clean_theme_json_cache();
        }
    }
}
