<?php
/**
 * Shared (non-translatable) taxonomies opted in via wp_loc_synced_shared_taxonomies
 * are copied to every translation with the same term ids.
 * Run: wp eval-file web/app/plugins/wp-loc/tests/shared-taxonomy-sync.php
 *
 * Needs a translatable post type with a non-translatable taxonomy and at least one
 * post with a translation; the terms it changes are restored at the end.
 */

$failed = 0;
$check = static function ( string $label, $actual, $expected ) use ( &$failed ) {
    if ( $actual === $expected ) {
        WP_CLI::log( "PASS {$label}" );
        return;
    }
    $failed++;
    WP_CLI::warning( "FAIL {$label}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
};

// --- merge_syncable_taxonomies (pure) ----------------------------------------

$check( 'no shared: translatable only',
    WP_LOC_Content::merge_syncable_taxonomies( [ 'product_cat', 'product_brand', 'product_type' ], [ 'product_cat' ], [] ),
    [ 'product_cat' ] );
$check( 'shared taxonomy is added',
    WP_LOC_Content::merge_syncable_taxonomies( [ 'product_cat', 'product_brand', 'product_type' ], [ 'product_cat' ], [ 'product_brand' ] ),
    [ 'product_cat', 'product_brand' ] );
$check( 'shared taxonomy of another post type is ignored',
    WP_LOC_Content::merge_syncable_taxonomies( [ 'category' ], [ 'category' ], [ 'product_brand' ] ),
    [ 'category' ] );
$check( 'a translatable taxonomy is never treated as shared',
    WP_LOC_Content::merge_syncable_taxonomies( [ 'product_cat' ], [ 'product_cat' ], [ 'product_cat' ] ),
    [ 'product_cat' ] );

// --- live sync ---------------------------------------------------------------

$post_type = 'product';
$taxonomy  = 'product_brand';

if ( ! taxonomy_exists( $taxonomy ) || in_array( $taxonomy, WP_LOC_Terms::get_translatable_taxonomies(), true ) ) {
    WP_CLI::error( "{$taxonomy} must exist and be non-translatable for the live check" );
}

$share = static fn( array $t ) => array_merge( $t, [ $taxonomy ] );
add_filter( 'wp_loc_synced_shared_taxonomies', $share );

$db = WP_LOC::instance()->db;
$element_type = WP_LOC_DB::post_element_type( $post_type );

$source = 0;
$twin = 0;
foreach ( get_posts( [ 'post_type' => $post_type, 'posts_per_page' => 50, 'fields' => 'ids', 'post_status' => 'publish' ] ) as $id ) {
    $trid = $db->get_trid( $id, $element_type );
    foreach ( $trid ? $db->get_element_translations( $trid, $element_type ) : [] as $row ) {
        if ( (int) $row->element_id !== (int) $id ) {
            $source = (int) $id;
            $twin = (int) $row->element_id;
            break 2;
        }
    }
}

if ( ! $source ) {
    WP_CLI::error( 'No translated post found' );
}

$brands = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 2, 'fields' => 'ids' ] );
if ( count( $brands ) < 2 ) {
    WP_CLI::error( "Need at least two {$taxonomy} terms" );
}

$read = static fn( int $id ) => array_map( 'intval', wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids', 'orderby' => 'term_id' ] ) );
$source_before = $read( $source );
$twin_before = $read( $twin );

try {
    foreach ( $brands as $brand ) {
        wp_set_object_terms( $source, [ (int) $brand ], $taxonomy, false );
        $check( "brand {$brand} copied from #{$source} to #{$twin}", $read( $twin ), [ (int) $brand ] );
    }

    // An empty source set must never wipe the translations.
    wp_set_object_terms( $source, [], $taxonomy, false );
    $check( 'emptying the source keeps the twin', $read( $twin ), [ (int) end( $brands ) ] );
} finally {
    // Without the opt-in the restore of one copy is not mirrored onto the other.
    remove_filter( 'wp_loc_synced_shared_taxonomies', $share );
    wp_set_object_terms( $source, $source_before, $taxonomy, false );
    wp_set_object_terms( $twin, $twin_before, $taxonomy, false );
}

$check( 'source restored', $read( $source ), $source_before );
$check( 'twin restored', $read( $twin ), $twin_before );

$failed ? WP_CLI::error( "{$failed} checks failed" ) : WP_CLI::success( 'shared taxonomies sync as-is' );
