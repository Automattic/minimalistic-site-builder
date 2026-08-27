<?php
/**
 * REST surface: msb-companion/v1.
 *
 * Every route sits behind one permission gate: the MSB_COMPANION_SANDBOX
 * constant, which only the sandbox blueprint's mu-plugin defines. On any
 * other installation the whole namespace answers 403, so the plugin is inert
 * outside the disposable Playground it was built for.
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration + the handlers small enough to live here.
 */
final class MSB_Companion_Rest {

    /**
     * Hook route registration.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * The one permission gate.
     *
     * @return true|WP_Error
     */
    public static function permission() {
        if ( defined( 'MSB_COMPANION_SANDBOX' ) && MSB_COMPANION_SANDBOX ) {
            self::become_admin();

            return true;
        }

        return new WP_Error(
            'sandbox_only',
            'This companion only serves a local sandbox (MSB_COMPANION_SANDBOX is not defined).',
            array( 'status' => 403 )
        );
    }

    /**
     * Run every companion handler as an administrator.
     *
     * The pipeline's HTTP client is anonymous, and WordPress silently
     * degrades several of the writes this plugin performs for user zero:
     * kses filtering rewrites the user global-styles JSON so a token apply
     * reports success while nothing lands, and template/attachment writes
     * hit similar capability-shaped traps. This companion only ever runs
     * inside a disposable local sandbox (the constant gate above), where the
     * admin user is the only human — so admin context is the honest one,
     * exactly like the auto-logged-in browser Playground itself opens.
     *
     * @return void
     */
    private static function become_admin(): void {
        if ( ! function_exists( 'wp_set_current_user' ) || ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) ) {
            return;
        }

        $admins = get_users(
            array(
                'role'   => 'administrator',
                'fields' => 'ID',
                'number' => 1,
            )
        );

        wp_set_current_user( ! empty( $admins ) ? (int) $admins[0] : 1 );
    }

    /**
     * Register every route.
     *
     * @return void
     */
    public static function register_routes(): void {
        $ns   = MSB_COMPANION_REST_NAMESPACE;
        $gate = array( __CLASS__, 'permission' );

        register_rest_route(
            $ns,
            '/fingerprint',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'route_fingerprint' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/manifest',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'route_manifest' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/validate',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'route_validate' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/patterns',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'route_patterns' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/harness',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'route_harness' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/theme/tokens',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( 'MSB_Companion_Theme_Tokens', 'route_tokens' ),
                'permission_callback' => $gate,
            )
        );

        register_rest_route(
            $ns,
            '/placeholder',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( 'MSB_Companion_Placeholders', 'handle' ),
                'permission_callback' => $gate,
            )
        );

        $publish = array(
            'page'               => 'page',
            'update-page'        => 'update_page',
            'settings'           => 'settings',
            'template-part'      => 'template_part',
            'navigation'         => 'navigation',
            'delete-sample-page' => 'delete_sample_page',
            'media'              => 'media',
        );

        foreach ( $publish as $route => $method ) {
            register_rest_route(
                $ns,
                '/publish/' . $route,
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( 'MSB_Companion_Publish', $method ),
                    'permission_callback' => $gate,
                )
            );
        }
    }

    /**
     * GET /fingerprint.
     *
     * @return WP_REST_Response
     */
    public static function route_fingerprint() {
        return rest_ensure_response(
            array(
                'fingerprint' => MSB_Companion_Manifest::fingerprint(),
            )
        );
    }

    /**
     * GET /manifest.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function route_manifest( WP_REST_Request $request ) {
        return rest_ensure_response(
            MSB_Companion_Manifest::get_manifest( (bool) $request->get_param( 'refresh' ) )
        );
    }

    /**
     * POST /validate.
     *
     * The TreeIR schema is deliberately NOT enforced by the route args: a
     * malformed tree is the pipeline's primary feedback channel and must come
     * back as Diagnostics with E_TREE_SCHEMA, not as a bare 400.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function route_validate( WP_REST_Request $request ) {
        // Decoded with objects preserved so that `{}` and `[]` stay
        // distinguishable: BlockNode.attributes must be an object and
        // TreeIR.blocks must be an array, and json_decode( $body, true )
        // collapses both empties to the same PHP value.
        $tree = json_decode( (string) $request->get_body() );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $tree = $request->get_json_params();
        }

        return rest_ensure_response( MSB_Companion_Validator::validate_request( $tree ) );
    }

    /**
     * GET /patterns — the instance's registered pattern corpus, parsed.
     *
     * @return WP_REST_Response
     */
    public static function route_patterns() {
        $patterns = array();

        if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
            foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
                $content    = (string) ( $pattern['content'] ?? '' );
                $patterns[] = array(
                    'name'       => (string) ( $pattern['name'] ?? '' ),
                    'title'      => (string) ( $pattern['title'] ?? '' ),
                    'categories' => array_values( array_map( 'strval', (array) ( $pattern['categories'] ?? array() ) ) ),
                    'content'    => $content,
                    'parsed'     => parse_blocks( $content ),
                );
            }
        }

        usort(
            $patterns,
            static function ( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            }
        );

        return rest_ensure_response( $patterns );
    }

    /**
     * GET /harness. Streams the page and exits; it never returns.
     *
     * @param WP_REST_Request $request Request.
     * @return void
     */
    public static function route_harness( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        MSB_Companion_Harness::serve();
    }
}
