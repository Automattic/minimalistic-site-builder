<?php
/**
 * POST /publish/* — how generated content lands on the sandbox.
 *
 * These routes replace the authenticated core-REST lane the x-pipeline used
 * (application passwords on a Playground): the companion runs inside the
 * sandbox already, so publishing is a set of thin wrappers over
 * wp_insert_post / update_option / the template-part customization post.
 * Every route sits behind the shared sandbox-only permission gate.
 *
 * @package msb-companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publish handlers.
 */
final class MSB_Companion_Publish {

    /**
     * No hooks. Present so the bootstrap loader can call ::init() uniformly.
     *
     * @return void
     */
    public static function init(): void {}

    /**
     * POST /publish/page — upsert a page by slug.
     *
     * Input:  { title, slug, content, template? }
     * Output: { id, link, updated }
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function page( WP_REST_Request $request ) {
        $title    = (string) $request->get_param( 'title' );
        $slug     = sanitize_title( (string) $request->get_param( 'slug' ) );
        $content  = (string) $request->get_param( 'content' );
        $template = (string) ( $request->get_param( 'template' ) ?? '' );

        if ( '' === $slug ) {
            return new WP_Error( 'invalid_slug', 'A page needs a non-empty slug.', array( 'status' => 400 ) );
        }

        $existing = get_posts(
            array(
                'post_type'      => 'page',
                'name'           => $slug,
                'post_status'    => array( 'publish', 'draft', 'pending' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
            )
        );

        $postarr = array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => wp_slash( $content ),
        );

        $updated = ! empty( $existing );
        if ( $updated ) {
            $postarr['ID'] = (int) $existing[0];
            $id            = wp_update_post( $postarr, true );
        } else {
            $id = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $id ) || 0 === $id ) {
            return new WP_Error(
                'publish_failed',
                'Could not write the page' . ( is_wp_error( $id ) ? ': ' . $id->get_error_message() : '.' ),
                array( 'status' => 500 )
            );
        }

        if ( '' !== $template ) {
            update_post_meta( (int) $id, '_wp_page_template', $template );
        }

        return array(
            'id'      => (int) $id,
            'link'    => (string) get_permalink( (int) $id ),
            'updated' => $updated,
        );
    }

    /**
     * POST /publish/update-page — replace an existing page's content by id.
     *
     * Input:  { id, content }
     * Output: { id, link }
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function update_page( WP_REST_Request $request ) {
        $id      = (int) $request->get_param( 'id' );
        $content = (string) $request->get_param( 'content' );

        if ( $id <= 0 || ! get_post( $id ) ) {
            return new WP_Error( 'not_found', sprintf( 'No post with id %d.', $id ), array( 'status' => 404 ) );
        }

        $updated = wp_update_post(
            array(
                'ID'           => $id,
                'post_content' => wp_slash( $content ),
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            return new WP_Error( 'publish_failed', $updated->get_error_message(), array( 'status' => 500 ) );
        }

        return array(
            'id'   => $id,
            'link' => (string) get_permalink( $id ),
        );
    }

    /**
     * POST /publish/settings — site identity and front page.
     *
     * Input:  { title?, description?, show_on_front?, page_on_front? }
     * Output: { ok }
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    public static function settings( WP_REST_Request $request ): array {
        $map = array(
            'title'         => 'blogname',
            'description'   => 'blogdescription',
            'show_on_front' => 'show_on_front',
            'page_on_front' => 'page_on_front',
        );

        foreach ( $map as $param => $option ) {
            $value = $request->get_param( $param );
            if ( null === $value ) {
                continue;
            }
            update_option( $option, is_scalar( $value ) ? $value : '' );
        }

        return array( 'ok' => true );
    }

    /**
     * POST /publish/template-part — customize the active theme's part.
     *
     * Mirrors what core's POST /wp/v2/template-parts/<id> does: the theme's
     * file-provided part gets (or updates) a wp_template_part customization
     * post carrying the new content, tagged with the theme and the part's
     * area so templates keep resolving it.
     *
     * Input:  { slug, content }
     * Output: { written, id?, reason? }
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function template_part( WP_REST_Request $request ) {
        $slug    = (string) $request->get_param( 'slug' );
        $content = (string) $request->get_param( 'content' );

        if ( '' === $slug ) {
            return new WP_Error( 'invalid_slug', 'A template part needs a slug.', array( 'status' => 400 ) );
        }

        $template = function_exists( 'get_block_template' )
            ? get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' )
            : null;

        // Canonical slug first; the part's declared area is the fallback —
        // themes ship several footer-area parts and only one is rendered.
        if ( ! $template && function_exists( 'get_block_templates' ) ) {
            foreach ( get_block_templates( array(), 'wp_template_part' ) as $candidate ) {
                if ( ( $candidate->area ?? '' ) === $slug ) {
                    $template = $candidate;
                    break;
                }
            }
        }

        if ( ! $template ) {
            return array(
                'written' => false,
                'reason'  => 'no_part',
            );
        }

        if ( ! empty( $template->wp_id ) ) {
            $id = wp_update_post(
                array(
                    'ID'           => (int) $template->wp_id,
                    'post_content' => wp_slash( $content ),
                ),
                true
            );

            if ( is_wp_error( $id ) ) {
                return new WP_Error( 'publish_failed', $id->get_error_message(), array( 'status' => 500 ) );
            }

            return array(
                'written' => true,
                'id'      => (int) $id,
            );
        }

        $id = wp_insert_post(
            array(
                'post_type'    => 'wp_template_part',
                'post_status'  => 'publish',
                'post_name'    => (string) $template->slug,
                'post_title'   => (string) ( $template->title ?? $template->slug ),
                'post_content' => wp_slash( $content ),
            ),
            true
        );

        if ( is_wp_error( $id ) || 0 === $id ) {
            return new WP_Error(
                'publish_failed',
                'Could not create the template-part customization' . ( is_wp_error( $id ) ? ': ' . $id->get_error_message() : '.' ),
                array( 'status' => 500 )
            );
        }

        // tax_input needs caps context inside REST; set the terms directly so
        // the customization stays attached to the theme and keeps its area.
        wp_set_post_terms( (int) $id, array( get_stylesheet() ), 'wp_theme' );
        if ( '' !== (string) ( $template->area ?? '' ) ) {
            wp_set_post_terms( (int) $id, array( (string) $template->area ), 'wp_template_part_area' );
        }

        return array(
            'written' => true,
            'id'      => (int) $id,
        );
    }

    /**
     * POST /publish/navigation — upsert the site's navigation post.
     *
     * Input:  { content } (inner navigation-link markup, no outer wrapper)
     * Output: { id }
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function navigation( WP_REST_Request $request ) {
        $content = (string) $request->get_param( 'content' );

        $existing = get_posts(
            array(
                'post_type'      => 'wp_navigation',
                'post_status'    => array( 'publish', 'draft' ),
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            )
        );

        if ( ! empty( $existing ) ) {
            $id = wp_update_post(
                array(
                    'ID'           => (int) $existing[0],
                    'post_status'  => 'publish',
                    'post_content' => wp_slash( $content ),
                ),
                true
            );
        } else {
            $id = wp_insert_post(
                array(
                    'post_type'    => 'wp_navigation',
                    'post_status'  => 'publish',
                    'post_title'   => 'Navigation',
                    'post_content' => wp_slash( $content ),
                ),
                true
            );
        }

        if ( is_wp_error( $id ) || 0 === $id ) {
            return new WP_Error(
                'publish_failed',
                'Could not write the navigation post' . ( is_wp_error( $id ) ? ': ' . $id->get_error_message() : '.' ),
                array( 'status' => 500 )
            );
        }

        return array( 'id' => (int) $id );
    }

    /**
     * POST /publish/delete-sample-page — remove the default Sample Page.
     *
     * Output: { deleted }
     *
     * @param WP_REST_Request $request Request.
     * @return array
     */
    public static function delete_sample_page( WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $samples = get_posts(
            array(
                'post_type'      => 'page',
                'name'           => 'sample-page',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        $deleted = 0;
        foreach ( $samples as $id ) {
            if ( wp_delete_post( (int) $id, true ) ) {
                ++$deleted;
            }
        }

        return array( 'deleted' => $deleted );
    }

    /**
     * POST /publish/media — sideload one generated image into the library.
     *
     * Input:  { filename, mime, data (base64), alt? }
     * Output: { id, url }
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function media( WP_REST_Request $request ) {
        $filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
        $mime     = (string) $request->get_param( 'mime' );
        $alt      = (string) ( $request->get_param( 'alt' ) ?? '' );
        $bytes    = base64_decode( (string) $request->get_param( 'data' ), true );

        if ( '' === $filename ) {
            return new WP_Error( 'invalid_media', 'A media upload needs a filename.', array( 'status' => 400 ) );
        }
        if ( false === $bytes || '' === $bytes ) {
            return new WP_Error( 'invalid_media', 'The "data" parameter must be non-empty base64.', array( 'status' => 400 ) );
        }

        $upload = wp_upload_bits( $filename, null, $bytes );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'media_write_failed', (string) $upload['error'], array( 'status' => 500 ) );
        }

        $id = wp_insert_attachment(
            array(
                'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
                'post_mime_type' => $mime,
                'post_status'    => 'inherit',
            ),
            $upload['file']
        );

        if ( is_wp_error( $id ) || 0 === $id ) {
            return new WP_Error( 'media_write_failed', 'Could not create the attachment.', array( 'status' => 500 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( (int) $id, (array) wp_generate_attachment_metadata( (int) $id, $upload['file'] ) );

        if ( '' !== $alt ) {
            update_post_meta( (int) $id, '_wp_attachment_image_alt', $alt );
        }

        return array(
            'id'  => (int) $id,
            'url' => (string) $upload['url'],
        );
    }
}
