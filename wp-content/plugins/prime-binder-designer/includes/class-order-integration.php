<?php
/**
 * Carry the designs from the cart onto the order (§3.5): item meta, the
 * design rows pointed at their order, the shop's "Print Files" box on the
 * order screen, the customer's proofs, and the shop notification when an
 * order is paid.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Order_Integration {

	const ITEM_META    = '_binder_designs';
	const NOTIFIED     = '_binder_notified';
	const PAID_STATUSES = array( 'processing', 'completed' );

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_to_item' ), 10, 3 );

		// Classic checkout, and the Store API (block checkout / REST) — both run after the items are saved.
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'link_designs' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link_designs' ) );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'binder_design_ready', array( __CLASS__, 'on_design_ready' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_proofs' ) );
	}

	/* ----------------------------------------------------------- the order */

	/**
	 * Cart line -> order line: keep the design ids (hidden) and a readable note.
	 *
	 * @param WC_Order_Item_Product $item          Order line.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart line.
	 */
	public static function copy_to_item( $item, $cart_item_key, $values ) {
		unset( $cart_item_key );

		if ( empty( $values[ Binder_Storefront::ITEM_KEY ] ) || ! is_array( $values[ Binder_Storefront::ITEM_KEY ] ) ) {
			return;
		}

		$designs = array_map( 'absint', $values[ Binder_Storefront::ITEM_KEY ] );

		$item->add_meta_data( self::ITEM_META, $designs, true );

		foreach ( $designs as $template => $id ) {
			$item->add_meta_data( sprintf( Binder_Storefront::copy( 'item_label' ), Binder_Storefront::copy( $template ) ), sprintf( '%s (#%d)', Binder_Storefront::copy( 'attached' ), $id ), true );
		}
	}

	/**
	 * The designs on an order line, by template.
	 *
	 * @param WC_Order_Item $item Order line.
	 * @return array<string,int>
	 */
	public static function designs_of( $item ) {
		$designs = $item->get_meta( self::ITEM_META );

		return is_array( $designs ) ? array_map( 'absint', $designs ) : array();
	}

	/**
	 * Point each design at its order and line, so the row can never be reused
	 * and the shop can find it. Safe to run twice.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public static function link_designs( $order ) {
		$order = wc_get_order( $order );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			foreach ( self::designs_of( $item ) as $design_id ) {
				$row = Binder_DB::get( $design_id );

				if ( ! $row ) {
					continue;
				}

				if ( ! empty( $row['order_id'] ) && (int) $row['order_id'] !== (int) $order->get_id() ) {
					$order->add_order_note( sprintf( 'Binder design #%d was already attached to order #%d — check this order before printing.', $design_id, (int) $row['order_id'] ) );
					continue;
				}

				Binder_DB::update( $design_id, array( 'order_id' => $order->get_id(), 'order_item_id' => (int) $item_id ) );
			}
		}
	}

	/**
	 * Every design on an order, with its row.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each: item (WC_Order_Item), template, id, row (array|null).
	 */
	public static function order_designs( $order ) {
		$out = array();

		foreach ( $order->get_items() as $item ) {
			foreach ( self::designs_of( $item ) as $template => $id ) {
				$out[] = array( 'item' => $item, 'template' => $template, 'id' => $id, 'row' => Binder_DB::get( $id ) );
			}
		}

		return $out;
	}

	/* -------------------------------------------------------- paid -> notify */

	public static function on_status_changed( $order_id, $from, $to ) {
		unset( $from );

		if ( in_array( $to, self::PAID_STATUSES, true ) ) {
			self::maybe_notify( wc_get_order( $order_id ) );
		}
	}

	/** A render that finished after the order was paid still reaches the shop. */
	public static function on_design_ready( $design_id ) {
		$row = Binder_DB::get( $design_id );

		if ( $row && ! empty( $row['order_id'] ) ) {
			self::maybe_notify( wc_get_order( $row['order_id'] ) );
		}
	}

	/**
	 * Tell the shop once, when a paid order's print files are all ready.
	 *
	 * @param WC_Order|false $order Order.
	 */
	public static function maybe_notify( $order ) {
		if ( ! $order || ! in_array( $order->get_status(), self::PAID_STATUSES, true ) || $order->get_meta( self::NOTIFIED ) ) {
			return;
		}

		$designs = self::order_designs( $order );

		if ( ! $designs ) {
			return;
		}

		$waiting = array();
		foreach ( $designs as $d ) {
			if ( ! $d['row'] || 'ready' !== $d['row']['status'] ) {
				$waiting[] = '#' . $d['id'];
			}
		}

		if ( $waiting ) {
			// Once each, not on every status change.
			if ( ! $order->get_meta( '_binder_waiting_noted' ) ) {
				$order->add_order_note( 'Binder print file not ready yet for design ' . implode( ', ', $waiting ) . '. The shop is notified when it finishes; if it does not, open the order and check the design.' );
				$order->update_meta_data( '_binder_waiting_noted', 1 );
				$order->save();
			}
			return;
		}

		$files = array();
		foreach ( $designs as $d ) {
			$files[] = array(
				'design_id' => $d['id'],
				'template'  => $d['template'],
				'label'     => Binder_Storefront::copy( $d['template'] ),
				'product'   => $d['item']->get_name(),
				'cmyk_url'  => Binder_Files::signed_url( $d['id'], 'cmyk' ),
			);
		}

		$order->update_meta_data( self::NOTIFIED, gmdate( 'c' ) );
		$order->add_order_note( 'Binder print files are ready (' . count( $files ) . ' file' . ( 1 === count( $files ) ? '' : 's' ) . '). Download them from the Print Files box.' );
		$order->save();

		/**
		 * The order is paid and every print file is ready.
		 *
		 * @param WC_Order $order Order.
		 * @param array    $files One entry per design: design_id, template, label, product, cmyk_url (expires in 7 days).
		 */
		do_action( 'binder_order_print_ready', $order, $files );
	}

	/* ---------------------------------------------------- the shop: order box */

	public static function add_meta_box() {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'binder-print-files', __( 'Print Files', 'prime-binder-designer' ), array( __CLASS__, 'render_meta_box' ), $screen, 'normal', 'high' );
		}
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order The order being edited.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order );

		if ( ! $order ) {
			return;
		}

		$designs = self::order_designs( $order );

		if ( ! $designs ) {
			echo '<p>' . esc_html__( 'No designed items on this order.', 'prime-binder-designer' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="border:0"><thead><tr><th>' . esc_html__( 'Item', 'prime-binder-designer' ) . '</th><th>' . esc_html__( 'Cover', 'prime-binder-designer' ) . '</th><th>' . esc_html__( 'Status', 'prime-binder-designer' ) . '</th><th>' . esc_html__( 'Files', 'prime-binder-designer' ) . '</th></tr></thead><tbody>';

		foreach ( $designs as $d ) {
			$row    = $d['row'];
			$cmyk   = $row ? Binder_Files::link_for( $row, 'cmyk' ) : '';
			$rgb    = $row ? Binder_Files::link_for( $row, 'rgb' ) : '';
			$status = $row ? $row['status'] : 'missing';
			$warns  = $row && $row['validation_warnings'] ? count( (array) json_decode( $row['validation_warnings'], true ) ) : 0;

			echo '<tr><td>' . esc_html( $d['item']->get_name() ) . '</td>';
			echo '<td>' . esc_html( Binder_Storefront::copy( $d['template'] ) ) . '<br><small>' . esc_html( $row ? ( 'live' === $row['mode'] ? 'Designed online' : 'Uploaded' ) : '' ) . ' · #' . (int) $d['id'] . '</small></td>';
			echo '<td>' . esc_html( ucfirst( $status ) ) . ( $warns ? '<br><small>' . esc_html( sprintf( '%d warning(s)', $warns ) ) . '</small>' : '' ) . '</td><td>';

			if ( $cmyk ) {
				echo '<a class="button button-primary" href="' . esc_url( $cmyk ) . '">' . esc_html__( 'Print file (CMYK PDF)', 'prime-binder-designer' ) . '</a> ';
			}
			if ( $rgb ) {
				echo '<a class="button" href="' . esc_url( $rgb ) . '">' . esc_html__( 'Customer proof', 'prime-binder-designer' ) . '</a>';
			}
			if ( ! $cmyk && ! $rgb ) {
				echo '<em>' . esc_html__( 'No file yet', 'prime-binder-designer' ) . '</em>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/* ------------------------------------------------------ the customer */

	/**
	 * "Your design" proofs under the order table (My Account order view and
	 * the confirmation page). The customer gets the RGB proof, never the
	 * CMYK production file.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_customer_proofs( $order ) {
		$designs = self::order_designs( $order );

		if ( ! $designs ) {
			return;
		}

		echo '<section class="binder-proofs"><h2>' . esc_html( Binder_Storefront::copy( 'heading' ) ) . '</h2><ul>';

		foreach ( $designs as $d ) {
			$url = $d['row'] ? Binder_Files::link_for( $d['row'], 'rgb' ) : '';

			echo '<li>' . esc_html( $d['item']->get_name() . ' — ' . Binder_Storefront::copy( $d['template'] ) );
			if ( $url ) {
				echo ': <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( Binder_Storefront::copy( 'proof' ) ) . '</a>';
			}
			echo '</li>';
		}

		echo '</ul></section>';
	}
}
