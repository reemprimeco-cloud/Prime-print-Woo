<?php
/** Does a customer ordering in Arabic see Arabic, and does the order come through intact? */
require_once __DIR__ . '/lib.php';

$product = get_page_by_path( 'gergean-box-live', OBJECT, 'product' );
t_ok( (bool) $product, 'the merged test product exists' );
$id = $product->ID;

PLL()->curlang = PLL()->model->get_language( 'ar' );
t_ok( prime_is_arabic(), 'browsing in Arabic' );

// ---- the cart -------------------------------------------------------------------
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $id, 2 );
$items = WC()->cart->get_cart();
$item  = reset( $items );

t_eq( count( $items ), 1, 'the product goes into the cart' );
t_eq( $item['data']->get_name(), 'صندوق القرقيعان', 'the cart line shows the Arabic name' );
t_eq( (float) $item['data']->get_price(), 7.5, 'and the one price — same as English' );
WC()->cart->calculate_totals();
t_eq( (float) WC()->cart->get_subtotal(), 15.0, 'the total is right' );

// ---- the order ------------------------------------------------------------------
$order = wc_create_order();
$order->add_product( wc_get_product( $id ), 2 );
$order->set_status( 'pending' );
$order->calculate_totals();
$order->save();

$line = array_values( $order->get_items() )[0];
t_eq( $line->get_name(), 'صندوق القرقيعان', 'the order line records the Arabic name the customer saw' );
t_eq( (float) $order->get_total(), 15.0, 'with the right total' );
t_eq( (int) $line->get_product_id(), $id, 'and points at the one product' );

// The shop reads the same order in English.
PLL()->curlang = PLL()->model->get_language( 'en' );
$reloaded = wc_get_order( $order->get_id() );
$line_en  = array_values( $reloaded->get_items() )[0];
t_eq( $line_en->get_name(), 'صندوق القرقيعان', 'the order keeps what the customer ordered, whichever language the shop reads it in' );
t_eq( $line_en->get_product()->get_name(), 'Gergean Box', 'while the product itself reads English for the shop' );

// ---- stock is shared, because there is one product ----------------------------------
$p = wc_get_product( $id );
$p->set_manage_stock( true );
$p->set_stock_quantity( 5 );
$p->save();
wc_maybe_reduce_stock_levels( $order->get_id() );
t_eq( (int) wc_get_product( $id )->get_stock_quantity(), 3, 'an Arabic order reduces the one stock figure' );

WC()->cart->empty_cart();
$order->delete( true );
t_done();
