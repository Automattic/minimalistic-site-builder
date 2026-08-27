<?php
/**
 * Plugin Name:       MSB Sandbox Companion
 * Description:       Makes a local WordPress Playground sandbox a ground-truth target for the tree graph: exposes the live block registry and theme tokens as machine-readable contracts, validates Tree IR against them, hosts the browser serialization harness, and publishes generated pages.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 *
 * This plugin is sandbox infrastructure for the site-build tree graph. Every
 * route refuses to serve unless the MSB_COMPANION_SANDBOX constant is defined
 * true — the sandbox blueprint defines it from an mu-plugin, so the plugin is
 * inert if it ever lands on a real site.
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

define( 'MSB_COMPANION_VERSION', '1.0.0' );
define( 'MSB_COMPANION_FILE', __FILE__ );
define( 'MSB_COMPANION_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSB_COMPANION_URL', plugin_dir_url( __FILE__ ) );

/** REST namespace the pipeline's SandboxClient targets. */
define( 'MSB_COMPANION_REST_NAMESPACE', 'msb-companion/v1' );

/**
 * Map an include filename to its class name.
 *
 * class-theme-tokens.php -> MSB_Companion_Theme_Tokens
 *
 * @param string $file Absolute path to the include.
 * @return string Class name.
 */
function msb_companion_class_for_file( string $file ): string {
    $base = basename( $file, '.php' );
    $base = preg_replace( '/^class-/', '', $base );

    $parts = array_map( 'ucfirst', explode( '-', (string) $base ) );

    return 'MSB_Companion_' . implode( '_', $parts );
}

/**
 * Require every present include and boot the classes they declare.
 *
 * @return void
 */
function msb_companion_load(): void {
    $includes = glob( MSB_COMPANION_DIR . 'includes/class-*.php' );
    $includes = is_array( $includes ) ? $includes : array();
    sort( $includes );

    $classes = array();

    foreach ( $includes as $file ) {
        if ( ! file_exists( $file ) ) {
            continue;
        }
        require_once $file;

        $class = msb_companion_class_for_file( $file );
        if ( class_exists( $class ) ) {
            $classes[] = $class;
        }
    }

    foreach ( $classes as $class ) {
        if ( method_exists( $class, 'init' ) ) {
            call_user_func( array( $class, 'init' ) );
        }
    }
}
add_action( 'plugins_loaded', 'msb_companion_load', 5 );
