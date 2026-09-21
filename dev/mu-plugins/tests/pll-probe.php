<?php
// Probe: how is Polylang configured, and what does it consider translatable?
$o = get_option( 'polylang', array() );
echo "polylang option keys: " . implode( ', ', array_keys( $o ) ) . "\n";
echo "post_types: " . wp_json_encode( $o['post_types'] ?? null ) . "\n";
echo "taxonomies: " . wp_json_encode( $o['taxonomies'] ?? null ) . "\n";
echo "default_lang: " . ( $o['default_lang'] ?? '?' ) . "  hide_default: " . ( $o['hide_default'] ?? '?' ) . "  force_lang: " . ( $o['force_lang'] ?? '?' ) . "\n";
echo "languages: " . implode( ',', (array) pll_languages_list() ) . "\n";
echo "is 'product' translated? " . ( pll_is_translated_post_type( 'product' ) ? 'YES' : 'no' ) . "\n";
echo "is 'product_cat' translated? " . ( pll_is_translated_taxonomy( 'product_cat' ) ? 'YES' : 'no' ) . "\n";
echo "\nfilters on pll_get_post_types: " . ( has_filter( 'pll_get_post_types' ) ? 'yes' : 'none' ) . "\n";
