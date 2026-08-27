<?php
/**
 * GET /harness — the Tree IR compiler page.
 *
 * Port of x-companion's X_Companion_Harness. The page is deliberately the
 * thinnest thing that can host a faithful `wp.blocks` registry:
 *
 *   1. `get_block_editor_server_block_settings()` is printed into an inline
 *      bootstrap for `wp.blocks.unstable__bootstrapServerSideBlockDefinitions`,
 *      exactly as core's editor does it.
 *   2. `wp-blocks`, `wp-block-library`, `wp-element`, `wp-data`, `wp-dom-ready`
 *      and `wp-i18n` are enqueued.
 *   3. Every `WP_Block_Type`'s `editor_script_handles` are enqueued — this is
 *      how third-party blocks self-register client-side.
 *   4. `enqueue_block_editor_assets` is fired behind a shutdown guard, because
 *      plugins hooking it routinely assume a full admin page. A fatal there
 *      degrades the page instead of killing it: the response is served without
 *      that action and carries `X-Harness-Degraded: enqueue_block_editor_assets`.
 *   5. `harness/harness.js` is enqueued last.
 *
 * No theme, no admin chrome. The page is a compiler, not an editor. The
 * request always enters the editor-side admin context (WP_ADMIN) — this
 * companion only serves a disposable sandbox, whose whole purpose is registry
 * fidelity, and several block suites gate their editor-handle registration
 * behind is_admin().
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Harness page.
 */
final class MSB_Companion_Harness {

    /**
     * Script handle for harness.js.
     */
    const SCRIPT_HANDLE = 'msb-companion-harness';

    /**
     * The action that is allowed to fail.
     */
    const GUARDED_ACTION = 'enqueue_block_editor_assets';

    /**
     * Script handles the harness always needs.
     *
     * @var string[]
     */
    const CORE_HANDLES = array(
        'wp-blocks',
        'wp-block-library',
        'wp-element',
        'wp-data',
        'wp-dom-ready',
        'wp-i18n',
    );

    /**
     * True once the page body has been written; the shutdown guard is a no-op
     * from then on.
     *
     * @var bool
     */
    private static $rendered = false;

    /**
     * True when the guarded action was skipped or blew up.
     *
     * @var bool
     */
    private static $degraded = false;

    /**
     * Output buffering level when the guard was armed, so the shutdown handler
     * knows how many buffers belong to it.
     *
     * @var int
     */
    private static $base_ob_level = 0;

    /**
     * True while the guarded action is on the stack.
     *
     * @var bool
     */
    private static $in_guard = false;

    /**
     * True when this request entered the editor-side admin context.
     *
     * @var bool
     */
    private static $admin_context = false;

    /**
     * Enter the admin context when this request is the harness route.
     *
     * Runs on `plugins_loaded` (the bootstrap calls it at priority 5), which
     * is the last moment at which the admin context can still be entered
     * before `init` fires.
     *
     * @return void
     */
    public static function init(): void {
        self::maybe_enter_admin_context();
    }

    /**
     * Present this request to other plugins as an editor request.
     *
     * Block suites (measured against Kadence Blocks 3.7.9.1 upstream) declare
     * their per-block editor handles but only register them from an `init`
     * callback that starts with `if ( ! is_admin() ) return;`. Defining
     * WP_ADMIN for this one request is enough: the handles register, step 3
     * finds them. Nothing else of the admin bootstrap happens — `admin_init`
     * is fired by wp-admin/admin.php, which is not in play here.
     *
     * @return void
     */
    private static function maybe_enter_admin_context(): void {
        if ( defined( 'WP_ADMIN' ) ) {
            // A real admin request, or someone already decided.
            return;
        }

        if ( ! self::is_harness_request() ) {
            return;
        }

        define( 'WP_ADMIN', true );
        self::$admin_context = true;
    }

    /**
     * Is this request GET /harness, on either permalink mode?
     *
     * @return bool
     */
    private static function is_harness_request(): bool {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return false;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        if ( '' === $uri ) {
            return false;
        }

        // Pretty: /wp-json/msb-companion/v1/harness. Plain: /?rest_route=/msb-companion/v1/harness.
        return false !== strpos( $uri, '/' . MSB_COMPANION_REST_NAMESPACE . '/harness' );
    }

    /*
     * -------------------------------------------------------------------
     * The page
     * -------------------------------------------------------------------
     */

    /**
     * Build the asset queue, fire the guarded action, render, exit.
     *
     * @return void
     */
    public static function serve(): void {
        self::$base_ob_level = ob_get_level();
        register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

        self::enqueue_base_assets();

        /*
         * Two layers of protection:
         *
         *  - try/catch handles a thrown Throwable, which is the common case
         *    for a plugin that calls a method on null;
         *  - the shutdown handler handles a true fatal (E_ERROR), which no
         *    catch block can see.
         *
         * Output is buffered so that a fatal's own error text never lands in
         * front of the HTML the shutdown handler is about to write.
         */
        self::$in_guard = true;
        ob_start();

        try {
            do_action( self::GUARDED_ACTION );
            ob_end_clean();
            self::$in_guard = false;

            // Second pass: a suite may register its per-block editor handles
            // from inside that action rather than before it. Re-running step 3
            // is idempotent for handles already enqueued.
            self::enqueue_block_handles();
        } catch ( Throwable $e ) {
            ob_end_clean();
            self::$in_guard = false;
            self::degrade( get_class( $e ) . ': ' . $e->getMessage() );
        }

        self::render();
    }

    /**
     * Fatal-error net for the guarded action.
     *
     * Runs only when the page never made it out. Everything the fatal echoed
     * is discarded, the asset queue is rebuilt from scratch (so half-finished
     * enqueues from the failing plugin are gone) and the page is served
     * without the action.
     *
     * @return void
     */
    public static function on_shutdown(): void {
        if ( self::$rendered ) {
            return;
        }

        $error = error_get_last();
        $fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

        if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), $fatal, true ) ) {
            return;
        }

        while ( ob_get_level() > self::$base_ob_level ) {
            ob_end_clean();
        }

        if ( ! self::$in_guard ) {
            // The fatal came from somewhere we do not own. Do not pretend to
            // have a page.
            return;
        }

        self::$in_guard = false;
        self::degrade( sprintf( '%s in %s:%s', (string) $error['message'], (string) $error['file'], (string) $error['line'] ) );
        self::render();
    }

    /**
     * Mark the response degraded and rebuild the asset queue without the action.
     *
     * @param string $reason Human text for the log.
     * @return void
     */
    private static function degrade( string $reason ): void {
        self::$degraded = true;

        if ( function_exists( 'error_log' ) ) {
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                sprintf(
                    'msb-companion harness: %s fataled, serving degraded page. %s',
                    self::GUARDED_ACTION,
                    $reason
                )
            );
        }

        // Drop whatever the failing plugin managed to enqueue.
        $GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        self::enqueue_base_assets();
    }

    /**
     * Steps 1-3: core handles, server block settings bootstrap, block handles.
     *
     * @return void
     */
    private static function enqueue_base_assets(): void {
        if ( ! function_exists( 'get_block_editor_server_block_settings' ) ) {
            // A pure function library: no top-level side effects, safe outside admin.
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        foreach ( self::CORE_HANDLES as $handle ) {
            wp_enqueue_script( $handle );
        }

        $definitions = function_exists( 'get_block_editor_server_block_settings' )
            ? get_block_editor_server_block_settings()
            : array();

        wp_add_inline_script(
            'wp-blocks',
            'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( $definitions, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');',
            'after'
        );

        self::enqueue_block_handles();
    }

    /**
     * Step 3 on its own, so it can be run again after step 4.
     *
     * @return void
     */
    private static function enqueue_block_handles(): void {
        foreach ( self::block_script_handles() as $handle ) {
            if ( wp_script_is( $handle, 'registered' ) ) {
                wp_enqueue_script( $handle );
            }
        }
    }

    /**
     * Every editor script handle declared by a registered block type.
     *
     * @return string[] Unique handles, registry order.
     */
    public static function block_script_handles(): array {
        $handles = array();

        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
            return $handles;
        }

        foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $type ) {
            $declared = (array) ( $type->editor_script_handles ?? array() );

            foreach ( $declared as $handle ) {
                $handle = (string) $handle;
                if ( '' !== $handle && ! in_array( $handle, $handles, true ) ) {
                    $handles[] = $handle;
                }
            }
        }

        return $handles;
    }

    /**
     * Step 5, then write the document and stop.
     *
     * @return void
     */
    private static function render(): void {
        self::$rendered = true;

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            MSB_COMPANION_URL . 'harness/harness.js',
            self::CORE_HANDLES,
            MSB_COMPANION_VERSION,
            true
        );

        if ( ! headers_sent() ) {
            // The REST server already sent application/json; header() replaces.
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'X-Robots-Tag: noindex, nofollow' );
            header( 'X-Harness-Version: ' . MSB_COMPANION_VERSION );
            header( 'X-Harness-Admin-Context: ' . ( self::$admin_context ? '1' : '0' ) );

            if ( self::$degraded ) {
                header( 'X-Harness-Degraded: ' . self::GUARDED_ACTION );
            }
        }

        echo "<!DOCTYPE html>\n";
        echo '<html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . "\">\n";
        echo "<head>\n";
        echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . "\">\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        echo "<title>msb sandbox harness</title>\n";
        echo "<style>body{margin:0;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;padding:1rem;color:#1e1e1e;background:#fff}</style>\n";
        wp_print_styles();
        echo "</head>\n";
        echo '<body class="msb-companion-harness">' . "\n";
        echo '<div id="msb-companion-harness-root" hidden></div>' . "\n";
        printf(
            '<p>msb sandbox harness v%1$s.%2$s</p>' . "\n",
            esc_html( MSB_COMPANION_VERSION ),
            self::$degraded ? ' <strong>degraded: ' . esc_html( self::GUARDED_ACTION ) . ' was skipped</strong>' : ''
        );
        wp_print_scripts();
        echo "</body>\n</html>\n";

        exit;
    }
}
