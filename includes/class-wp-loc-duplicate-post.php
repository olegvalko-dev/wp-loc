<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Integration with Yoast Duplicate Post.
 *
 * When a translatable post is cloned, its whole translation group is cloned too:
 * every sibling translation is duplicated (through Duplicate Post's own engine, so
 * meta/taxonomies/attachments follow the user's Duplicate Post settings) and the
 * copies are linked together as a fresh translation group.
 *
 * The Rewrite & Republish flow is intentionally left untouched: it merges the copy
 * back into the original, which keeps its existing translation links, and it never
 * fires `duplicate_post_after_duplicated`.
 */
class WP_LOC_Duplicate_Post {

    /**
     * Guards against recursion while we duplicate sibling translations, and against
     * the deprecated child-copy pass registering the same copy twice.
     */
    private static $replicating = false;

    /**
     * Nesting depth of the current Duplicate Post copy operation. While it is above
     * zero, WP-LOC's default new-post registration is suspended so this class can
     * register the copies explicitly and correctly.
     */
    private static $copy_depth = 0;

    public function __construct() {
        add_action( 'duplicate_post_pre_copy', [ $this, 'begin_copy' ] );
        add_action( 'duplicate_post_post_copy', [ $this, 'end_copy' ] );

        // Priority 100 so Duplicate Post has already copied meta (10), children (20),
        // attachments (30), comments (40) and taxonomies (50) onto the new copy.
        add_action( 'duplicate_post_after_duplicated', [ $this, 'replicate_translation_group' ], 100, 4 );
    }

    /**
     * Suspend WP-LOC's default new-post handling for the duration of the copy.
     */
    public function begin_copy(): void {
        self::$copy_depth++;
        WP_LOC_Content::$suspend_new_post_registration = true;
    }

    /**
     * Restore default handling once the outermost copy operation finishes.
     */
    public function end_copy(): void {
        if ( --self::$copy_depth <= 0 ) {
            self::$copy_depth = 0;
            WP_LOC_Content::$suspend_new_post_registration = false;
        }
    }

    /**
     * Register the freshly created copy and duplicate the source's sibling translations.
     *
     * @param int     $new_post_id The ID of the new copy.
     * @param WP_Post $post        The original post that was cloned.
     * @param string  $status      The intended status of the copy.
     * @param string  $post_type   The post type of the copy.
     */
    public function replicate_translation_group( $new_post_id, $post, $status, $post_type ): void {
        $new_post_id = (int) $new_post_id;

        if ( self::$replicating || ! $new_post_id || ! $post instanceof \WP_Post ) {
            return;
        }

        if ( ! apply_filters( 'wp_loc_duplicate_translation_group', true, $new_post_id, $post ) ) {
            return;
        }

        if ( ! class_exists( 'WP_LOC_Admin_Settings' ) || ! WP_LOC_Admin_Settings::is_translatable( (string) $post_type ) ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( (string) $post_type );

        // Language of the original post; fall back to the admin language so the copy is
        // always registered (mirroring WP-LOC's default handling for unlinked posts).
        $source_lang = $db->get_element_language( (int) $post->ID, $element_type ) ?: wp_loc_get_admin_lang();

        // The copy becomes the source of a brand-new translation group.
        $new_trid = $db->set_element_language( $new_post_id, $element_type, $source_lang );

        $source_trid = $db->get_trid( (int) $post->ID, $element_type );
        if ( ! $source_trid ) {
            return;
        }

        $translations = $db->get_element_translations( $source_trid, $element_type );
        if ( count( $translations ) < 2 ) {
            return;
        }

        if ( ! function_exists( 'duplicate_post_create_duplicate' ) ) {
            return;
        }

        self::$replicating = true;

        try {
            foreach ( $translations as $lang_slug => $row ) {
                // The source language slot is already filled by $new_post_id (the copy of $post).
                if ( $lang_slug === $source_lang ) {
                    continue;
                }

                $sibling = get_post( (int) $row->element_id );
                if ( ! $sibling instanceof \WP_Post ) {
                    continue;
                }

                $sibling_copy_id = duplicate_post_create_duplicate( $sibling, $status );
                if ( is_wp_error( $sibling_copy_id ) || ! $sibling_copy_id ) {
                    continue;
                }

                // Link the sibling copy into the new group, translated from the copied source.
                $db->set_element_language( (int) $sibling_copy_id, $element_type, (string) $lang_slug, $new_trid, $source_lang );
            }
        } finally {
            self::$replicating = false;
        }
    }
}
