<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_DB {

    private $table;
    private const TRID_LOCK_NAME = 'wp_loc_trid_generation';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'icl_translations';
        $this->maybe_normalize_compat_language_codes();
    }

    /**
     * Create icl_translations table if not exists
     */
    public static function activate() {
        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            translation_id bigint(20) NOT NULL AUTO_INCREMENT,
            element_type varchar(60) NOT NULL DEFAULT '',
            element_id bigint(20) DEFAULT NULL,
            trid bigint(20) NOT NULL,
            language_code varchar(7) NOT NULL,
            source_language_code varchar(7) DEFAULT NULL,
            PRIMARY KEY  (translation_id),
            UNIQUE KEY element_id (element_type, element_id),
            KEY trid (trid, language_code)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        self::normalize_compat_language_codes();
        update_option( 'wp_loc_db_version', WP_LOC_VERSION );
        add_option( 'wp_loc_db_optimization_wizard_status', 'pending' );
        flush_rewrite_rules();
    }

    public static function to_db_language_code( ?string $language_code ): ?string {
        $language_code = sanitize_key( (string) $language_code );

        if ( $language_code === '' ) {
            return null;
        }

        if ( class_exists( 'WP_LOC_Languages' ) ) {
            return WP_LOC_Languages::get_wpml_code( $language_code );
        }

        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            return WP_LOC_Language_Registry::wpml_code_from_slug( $language_code );
        }

        return $language_code === 'ua' ? 'uk' : $language_code;
    }

    public static function from_db_language_code( ?string $language_code ): ?string {
        $language_code = sanitize_key( (string) $language_code );

        if ( $language_code === '' ) {
            return null;
        }

        if ( class_exists( 'WP_LOC_Languages' ) ) {
            foreach ( WP_LOC_Languages::get_languages() as $slug => $data ) {
                $wpml_code = sanitize_key( (string) ( $data['wpml_code'] ?? '' ) );

                if ( $wpml_code && $wpml_code === $language_code ) {
                    return sanitize_key( (string) $slug );
                }
            }
        }

        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            return WP_LOC_Language_Registry::slug_from_wpml_code( $language_code );
        }

        return $language_code === 'uk' ? 'ua' : $language_code;
    }

    public static function normalize_compat_language_codes(): void {
        global $wpdb;

        if ( ! $wpdb ) {
            return;
        }

        $table = $wpdb->prefix . 'icl_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $languages = class_exists( 'WP_LOC_Languages' ) ? WP_LOC_Languages::get_languages() : [];
        if ( empty( $languages ) ) {
            $languages = [
                'ua' => [ 'wpml_code' => 'uk' ],
            ];
        }

        foreach ( $languages as $slug => $data ) {
            $slug = sanitize_key( (string) $slug );
            $wpml_code = sanitize_key( (string) ( $data['wpml_code'] ?? self::to_db_language_code( $slug ) ) );

            if ( ! $slug || ! $wpml_code || $slug === $wpml_code ) {
                continue;
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET language_code = %s WHERE language_code = %s",
                $wpml_code,
                $slug
            ) );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET source_language_code = %s WHERE source_language_code = %s",
                $wpml_code,
                $slug
            ) );
        }
    }

    private function maybe_normalize_compat_language_codes(): void {
        if ( get_option( 'wp_loc_db_language_code_compat_version' ) === WP_LOC_VERSION ) {
            return;
        }

        self::normalize_compat_language_codes();
        update_option( 'wp_loc_db_language_code_compat_version', WP_LOC_VERSION );
    }

    /**
     * Taxonomy element types whose rows are fully loaded into the object cache
     * for this request, and how many times each type was primed (writes bust
     * the primed state, and re-priming is capped to protect write-heavy flows).
     */
    private array $primed_taxonomy_types = [];
    private array $taxonomy_prime_counts = [];

    private const MAX_TAXONOMY_PRIMES_PER_REQUEST = 3;

    /**
     * Bulk-load every translation row of a taxonomy element type into the
     * object cache. Term adjustment runs on the get_term filter, so taxonomy
     * lookups happen hundreds of times per request while taxonomy translation
     * sets stay small — one query per element type replaces per-term queries.
     *
     * Returns true when the element type is fully primed for this request,
     * which guarantees that a missing trid_/lang_ cache key means "no row".
     */
    private function maybe_prime_taxonomy_cache( string $element_type ): bool {
        if ( ! str_starts_with( $element_type, 'tax_' ) ) {
            return false;
        }

        if ( isset( $this->primed_taxonomy_types[ $element_type ] ) ) {
            return true;
        }

        $prime_count = $this->taxonomy_prime_counts[ $element_type ] ?? 0;

        if ( $prime_count >= self::MAX_TAXONOMY_PRIMES_PER_REQUEST ) {
            return false;
        }

        $this->taxonomy_prime_counts[ $element_type ] = $prime_count + 1;
        $this->primed_taxonomy_types[ $element_type ] = true;

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT element_id, trid, language_code, source_language_code FROM {$this->table} WHERE element_type = %s",
            $element_type
        ) );

        $translations_by_trid = [];

        foreach ( $rows as $row ) {
            $element_id = (int) $row->element_id;
            $trid = (int) $row->trid;
            $language = self::from_db_language_code( $row->language_code ) ?: (string) $row->language_code;

            wp_cache_set( "trid_{$element_type}_{$element_id}", $trid, 'wp_loc' );
            wp_cache_set( "lang_{$element_type}_{$element_id}", $language, 'wp_loc' );

            $row->element_id = $element_id;
            $row->language_code = $language;
            $row->source_language_code = self::from_db_language_code( $row->source_language_code );
            unset( $row->trid );

            $translations_by_trid[ $trid ][ $language ] = $row;
        }

        foreach ( $translations_by_trid as $trid => $translations ) {
            wp_cache_set( "translations_{$trid}", $translations, 'wp_loc' );
        }

        return true;
    }

    /**
     * Get trid for an element
     */
    public function get_trid( int $element_id, string $element_type ): ?int {
        $cache_key = "trid_{$element_type}_{$element_id}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached ?: null;
        }

        if ( $this->maybe_prime_taxonomy_cache( $element_type ) ) {
            $cached = wp_cache_get( $cache_key, 'wp_loc' );

            if ( $cached !== false ) {
                return $cached ?: null;
            }

            // The whole element type is primed, so a missing key means "no row".
            wp_cache_set( $cache_key, 0, 'wp_loc' );

            return null;
        }

        global $wpdb;

        $trid = $wpdb->get_var( $wpdb->prepare(
            "SELECT trid FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        $result = $trid ? (int) $trid : null;
        wp_cache_set( $cache_key, $result ?: 0, 'wp_loc' );

        return $result;
    }

    /**
     * Get all translations for a trid
     *
     * @return array [ 'ua' => object{element_id, language_code, source_language_code}, ... ]
     */
    public function get_element_translations( int $trid, string $element_type = '' ): array {
        if ( $element_type ) {
            $this->maybe_prime_taxonomy_cache( $element_type );
        }

        $cache_key = "translations_{$trid}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        $where = $wpdb->prepare( "WHERE trid = %d", $trid );
        if ( $element_type ) {
            $where .= $wpdb->prepare( " AND element_type = %s", $element_type );
        }

        $rows = $wpdb->get_results( "SELECT element_id, language_code, source_language_code FROM {$this->table} {$where}" );

        $result = [];
        foreach ( $rows as $row ) {
            $row->element_id = (int) $row->element_id;
            $row->language_code = self::from_db_language_code( $row->language_code ) ?: (string) $row->language_code;
            $row->source_language_code = self::from_db_language_code( $row->source_language_code );
            $result[ $row->language_code ] = $row;
        }

        wp_cache_set( $cache_key, $result, 'wp_loc' );

        return $result;
    }

    /**
     * Get translated element ID for a target language
     */
    public function get_element_translation( int $element_id, string $element_type, string $target_lang ): ?int {
        $target_lang = self::from_db_language_code( $target_lang ) ?: sanitize_key( $target_lang );
        $trid = $this->get_trid( $element_id, $element_type );
        if ( ! $trid ) return null;

        $translations = $this->get_element_translations( $trid, $element_type );

        if ( isset( $translations[ $target_lang ] ) ) {
            return (int) $translations[ $target_lang ]->element_id;
        }

        return null;
    }

    /**
     * Get language code for an element
     */
    public function get_element_language( int $element_id, string $element_type ): ?string {
        $cache_key = "lang_{$element_type}_{$element_id}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached ?: null;
        }

        if ( $this->maybe_prime_taxonomy_cache( $element_type ) ) {
            $cached = wp_cache_get( $cache_key, 'wp_loc' );

            if ( $cached !== false ) {
                return $cached ?: null;
            }

            // The whole element type is primed, so a missing key means "no row".
            wp_cache_set( $cache_key, '', 'wp_loc' );

            return null;
        }

        global $wpdb;

        $lang = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_code FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        $lang = self::from_db_language_code( $lang );
        wp_cache_set( $cache_key, $lang ?: '', 'wp_loc' );

        return $lang ?: null;
    }

    /**
     * Register or update element in translation table
     *
     * @return int trid
     */
    public function set_element_language( int $element_id, string $element_type, string $language_code, ?int $trid = null, ?string $source_language_code = null ): int {
        global $wpdb;

        $language_code = self::to_db_language_code( $language_code ) ?: $language_code;
        $source_language_code = $source_language_code !== null
            ? self::to_db_language_code( $source_language_code )
            : null;

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT translation_id, trid FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        $existing_trid = $existing ? (int) $existing->trid : null;
        $lock_acquired = false;

        // Reuse an existing trid for updates; only allocate a new one for brand-new elements.
        if ( $trid === null ) {
            if ( $existing_trid ) {
                $trid = $existing_trid;
            } else {
                $lock_acquired = $this->acquire_trid_lock();
                $max_trid = (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$this->table}" );
                $trid = $max_trid + 1;
            }
        }

        if ( $existing ) {
            $wpdb->update(
                $this->table,
                [
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_language_code,
                ],
                [
                    'element_id'   => $element_id,
                    'element_type' => $element_type,
                ],
                [ '%d', '%s', '%s' ],
                [ '%d', '%s' ]
            );
        } else {
            $wpdb->insert(
                $this->table,
                [
                    'element_type'         => $element_type,
                    'element_id'           => $element_id,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_language_code,
                ],
                [ '%s', '%d', '%d', '%s', '%s' ]
            );
        }

        if ( $lock_acquired ) {
            $this->release_trid_lock();
        }

        $this->bust_cache( $element_id, $element_type, $existing_trid );
        if ( $existing_trid !== $trid ) {
            $this->bust_cache( $element_id, $element_type, $trid );
        }

        return $trid;
    }

    /**
     * Remove element from translation table
     */
    public function delete_element( int $element_id, string $element_type ): void {
        $trid = $this->get_trid( $element_id, $element_type );

        global $wpdb;

        $wpdb->delete(
            $this->table,
            [
                'element_id'   => $element_id,
                'element_type' => $element_type,
            ],
            [ '%d', '%s' ]
        );

        $this->bust_cache( $element_id, $element_type, $trid );
    }

    /**
     * Get element_type string for a post type
     */
    public static function post_element_type( string $post_type ): string {
        return 'post_' . $post_type;
    }

    /**
     * Get element_type string for a taxonomy
     */
    public static function tax_element_type( string $taxonomy ): string {
        return 'tax_' . $taxonomy;
    }

    /**
     * Get the table name
     */
    public function get_table(): string {
        return $this->table;
    }

    /**
     * Clear caches for an element
     */
    private function bust_cache( int $element_id, string $element_type, ?int $trid = null ): void {
        unset( $this->primed_taxonomy_types[ $element_type ] );
        wp_cache_delete( "trid_{$element_type}_{$element_id}", 'wp_loc' );
        wp_cache_delete( "lang_{$element_type}_{$element_id}", 'wp_loc' );

        if ( $trid ) {
            wp_cache_delete( "translations_{$trid}", 'wp_loc' );
        }
    }

    private function acquire_trid_lock(): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::TRID_LOCK_NAME ) ) === 1;
    }

    private function release_trid_lock(): void {
        global $wpdb;

        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::TRID_LOCK_NAME ) );
    }
}
