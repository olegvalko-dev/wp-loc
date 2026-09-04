<?php
/**
 * Regression: primed term maps must survive a full object-cache flush.
 * Запуск: wp eval-file web/app/plugins/wp-loc/tests/prime-survives-cache-flush.php
 *
 * До фіксу після wp_cache_flush() прапорці «прогріто» лишались у PHP-пам'яті,
 * а даних у кеші вже не було — і обидва lookup-и повертали null, ще й
 * записували отруйний 0 у кеш. Саме так 30.08.2026 батчевий CLI стер
 * терми ~777 пар товарів ekabeauty.
 */

global $wpdb;

$taxonomy = 'product_cat';
$element_type = WP_LOC_DB::tax_element_type( $taxonomy );
$db = WP_LOC::instance()->db;

$tt_id = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT element_id FROM {$db->get_table()} WHERE element_type = %s LIMIT 1",
    $element_type
) );

if ( ! $tt_id ) {
    WP_CLI::error( "Немає рядків icl_translations для {$element_type} — тест не має на чому працювати" );
}

$failed = 0;
$check = static function ( string $label, $actual, $expected ) use ( &$failed ) {
    if ( $actual === $expected ) {
        WP_CLI::log( "PASS {$label}" );
        return;
    }
    $failed++;
    WP_CLI::warning( "FAIL {$label}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
};

$term_id_before = WP_LOC_Terms::get_term_id_from_taxonomy_id( $tt_id, $taxonomy );
$trid_before    = $db->get_trid( $tt_id, $element_type );
$lang_before    = $db->get_element_language( $tt_id, $element_type );

$check( 'term_id resolves before flush', is_int( $term_id_before ), true );
$check( 'trid resolves before flush', is_int( $trid_before ), true );
$check( 'language resolves before flush', is_string( $lang_before ) && $lang_before !== '', true );

for ( $round = 1; $round <= 5; $round++ ) {
    wp_cache_flush();

    $check( "round {$round}: term_id after flush", WP_LOC_Terms::get_term_id_from_taxonomy_id( $tt_id, $taxonomy ), $term_id_before );
    $check( "round {$round}: trid after flush", $db->get_trid( $tt_id, $element_type ), $trid_before );
    $check( "round {$round}: language after flush", $db->get_element_language( $tt_id, $element_type ), $lang_before );
}

// Другий виклик у тому ж поколінні має йти з кешу, а не з отруєного нуля.
$check( 'term_id stays resolved on repeat', WP_LOC_Terms::get_term_id_from_taxonomy_id( $tt_id, $taxonomy ), $term_id_before );

$failed ? WP_CLI::error( "{$failed} перевірок провалено" ) : WP_CLI::success( 'primed maps survive cache flush' );
