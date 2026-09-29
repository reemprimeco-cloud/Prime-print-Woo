<?php
/**
 * The product page (§3.4): a "Your design" panel with Upload / Design it now
 * per cover, the editor in a modal, and Add to Cart held back until every
 * required design is approved.
 *
 * The button is only a courtesy. The real gate is server-side: an add-to-cart
 * request is refused unless each required design exists, belongs to this
 * visitor's session token, is for this product and template, has a finished
 * print file, and has not already been used by an order.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Storefront {

	const FIELD_DESIGNS = 'binder_design';
	const FIELD_SESSION = 'binder_session';
	const ITEM_KEY      = 'binder_designs';

	public static function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_panel' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'show_item_data' ), 10, 2 );

		// A designed product can't be bought from a shop card: send the customer to its page.
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'no_ajax_add' ), 10, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'card_url' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'card_text' ), 10, 2 );
	}

	/* ---------------------------------------------------------------- copy */

	/**
	 * Customer-facing strings. Held here (not in a .mo file) so the storefront
	 * is bilingual on a site with no plugin translations installed.
	 *
	 * @param string $key Copy key.
	 * @return string
	 */
	public static function copy( $key ) {
		$ar = self::is_arabic();

		$strings = array(
			'heading'     => array( 'Your design', 'تصميمك' ),
			'lead'        => array( 'Add your design before ordering. We prepare the print file for you automatically.', 'أضف تصميمك قبل الطلب. نجهّز ملف الطباعة تلقائياً.' ),
			'binder_outer' => array( 'Outer cover', 'الغلاف الخارجي' ),
			'binder_inner' => array( 'Inner cover', 'الغلاف الداخلي' ),
			'sticker'     => array( 'Sticker', 'الملصق' ),
			'size_first'  => array( 'Enter the sticker size and shape first, then add your design.', 'أدخل مقاس الملصق وشكله أولاً، ثم أضف تصميمك.' ),
			'size_changed' => array( 'The size or shape changed. Please add your design again for the new size.', 'تغيّر المقاس أو الشكل. الرجاء إضافة تصميمك من جديد للمقاس الجديد.' ),
			'size_mismatch' => array( 'Your design was made for a different sticker size. Please add it again.', 'تصميمك مُعدّ لمقاس ملصق مختلف. الرجاء إضافته من جديد.' ),
			'none'        => array( 'No design yet', 'لم يُضف تصميم بعد' ),
			'ready'       => array( 'Design ready', 'التصميم جاهز' ),
			'upload'      => array( 'Upload your design', 'ارفع تصميمك' ),
			'live'        => array( 'Design it now', 'صمّم الآن' ),
			'change'      => array( 'Change design', 'تغيير التصميم' ),
			'proof'       => array( 'View proof', 'عرض المعاينة' ),
			'hint'        => array( 'Add your design to enable Add to cart.', 'أضف تصميمك لتفعيل زر الإضافة للسلة.' ),
			'hint_set'    => array( 'Add both covers to enable Add to cart.', 'أضف تصميم الغلافين لتفعيل زر الإضافة للسلة.' ),
			'close'       => array( 'Close', 'إغلاق' ),
			'missing'     => array( 'Please add your %s design before adding to cart.', 'الرجاء إضافة تصميم %s قبل الإضافة للسلة.' ),
			'invalid'     => array( 'Your %s design could not be verified. Please add it again.', 'تعذّر التحقق من تصميم %s. الرجاء إضافته من جديد.' ),
			'attached'    => array( 'Attached', 'مرفق' ),
			'item_label'  => array( '%s design', 'تصميم %s' ),
			'card_cta'    => array( 'Design & order', 'صمّم واطلب' ),
		);

		if ( ! isset( $strings[ $key ] ) ) {
			return $key;
		}

		return $strings[ $key ][ $ar ? 1 : 0 ];
	}

	public static function is_arabic() {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			if ( $lang ) {
				return 'ar' === $lang;
			}
		}

		return 0 === strpos( determine_locale(), 'ar' );
	}

	/**
	 * A lower-case, cover-name form for use inside a sentence ("outer cover").
	 *
	 * @param string $template Template key.
	 * @return string
	 */
	private static function cover_name( $template ) {
		$name = self::copy( $template );

		return self::is_arabic() ? $name : strtolower( $name );
	}

	/* ------------------------------------------------------------ frontend */

	/**
	 * @return int Current product id when it needs a design, else 0.
	 */
	private static function current_designed_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return 0;
		}

		$id = get_queried_object_id();

		return $id && Binder_Product_Meta::required_templates( $id ) ? (int) $id : 0;
	}

	public static function enqueue() {
		if ( ! self::current_designed_product() ) {
			return;
		}

		wp_enqueue_style( 'binder-storefront', PRIME_BINDER_URL . 'assets/storefront.css', array(), PRIME_BINDER_VERSION );
		wp_enqueue_script( 'binder-storefront', PRIME_BINDER_URL . 'assets/storefront.js', array(), PRIME_BINDER_VERSION, true );
		wp_script_add_data( 'binder-storefront', 'strategy', 'defer' );
	}

	public static function render_panel() {
		$product_id = self::current_designed_product();

		if ( ! $product_id ) {
			return;
		}

		$templates = Binder_Product_Meta::required_templates( $product_id );
		$live      = (bool) apply_filters( 'binder_live_mode_enabled', Binder_Settings::live_editor_enabled(), $product_id );

		$config = array(
			'editor'  => PRIME_BINDER_URL . 'assets/dist/index.html',
			'rest'    => untrailingslashit( (string) wp_parse_url( rest_url( 'binder/v1' ), PHP_URL_PATH ) ),
			'product' => $product_id,
			'lang'    => self::is_arabic() ? 'ar' : 'en',
			'live'    => $live,
			'close'   => self::copy( 'close' ),
			'rows'    => array(),
		);

		if ( in_array( 'sticker', $templates, true ) ) {
			// The editor needs the size and shape; the page's calculator fields carry them (in cm).
			$config['sticker'] = array(
				'fields'       => Binder_Sticker::size_fields( $product_id ),
				'min_mm'       => Binder_Sticker::MIN_MM,
				'max_mm'       => Binder_Sticker::MAX_MM,
				'size_first'   => self::copy( 'size_first' ),
				'size_changed' => self::copy( 'size_changed' ),
			);
		}

		foreach ( $templates as $template ) {
			$config['rows'][] = array(
				'template' => $template,
				'label'    => self::copy( $template ),
			);
		}

		$hint = count( $templates ) > 1 ? self::copy( 'hint_set' ) : self::copy( 'hint' );
		?>
		<div class="binder-panel" data-binder-panel data-binder="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<h3 class="binder-panel__title"><?php echo esc_html( self::copy( 'heading' ) ); ?></h3>
			<p class="binder-panel__lead"><?php echo esc_html( self::copy( 'lead' ) ); ?></p>

			<?php foreach ( $templates as $template ) : ?>
				<div class="binder-row" data-binder-row="<?php echo esc_attr( $template ); ?>">
					<div class="binder-row__head">
						<span class="binder-row__label"><?php echo esc_html( self::copy( $template ) ); ?></span>
						<span class="binder-row__state" data-binder-state
							data-none="<?php echo esc_attr( self::copy( 'none' ) ); ?>"
							data-ready="<?php echo esc_attr( self::copy( 'ready' ) ); ?>"><?php echo esc_html( self::copy( 'none' ) ); ?></span>
					</div>
					<div class="binder-row__actions">
						<button type="button" class="binder-btn binder-btn--primary" data-binder-open="upload"
							data-label-new="<?php echo esc_attr( self::copy( 'upload' ) ); ?>"
							data-label-change="<?php echo esc_attr( self::copy( 'change' ) ); ?>"><?php echo esc_html( self::copy( 'upload' ) ); ?></button>
						<?php if ( $live ) : ?>
							<button type="button" class="binder-btn" data-binder-open="live"><?php echo esc_html( self::copy( 'live' ) ); ?></button>
						<?php endif; ?>
						<a class="binder-link" data-binder-proof target="_blank" rel="noopener" hidden><?php echo esc_html( self::copy( 'proof' ) ); ?></a>
					</div>
					<input type="hidden" name="<?php echo esc_attr( self::FIELD_DESIGNS . '[' . $template . ']' ); ?>" value="" data-binder-input>
				</div>
			<?php endforeach; ?>

			<input type="hidden" name="<?php echo esc_attr( self::FIELD_SESSION ); ?>" value="" data-binder-session>
			<p class="binder-panel__hint" data-binder-hint><?php echo esc_html( $hint ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- shop cards */

	public static function no_ajax_add( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $product && Binder_Product_Meta::required_templates( $product->get_id() ) ) {
			return false;
		}

		return $supports;
	}

	public static function card_url( $url, $product ) {
		return $product && Binder_Product_Meta::required_templates( $product->get_id() ) ? get_permalink( $product->get_id() ) : $url;
	}

	public static function card_text( $text, $product ) {
		return $product && Binder_Product_Meta::required_templates( $product->get_id() ) ? self::copy( 'card_cta' ) : $text;
	}

	/* ---------------------------------------------------------------- cart */

	/**
	 * The design ids posted with an add-to-cart request, by template.
	 *
	 * @return array<string,int>
	 */
	private static function posted_designs() {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce's add-to-cart request; the ownership check below is the authorisation.
		$posted = isset( $_POST[ self::FIELD_DESIGNS ] ) && is_array( $_POST[ self::FIELD_DESIGNS ] ) ? wp_unslash( $_POST[ self::FIELD_DESIGNS ] ) : array();
		// phpcs:enable

		$out = array();
		foreach ( $posted as $template => $id ) {
			if ( is_string( $template ) && is_scalar( $id ) ) {
				$out[ sanitize_key( $template ) ] = absint( $id );
			}
		}

		return $out;
	}

	private static function posted_token() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_POST[ self::FIELD_SESSION ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_SESSION ] ) ) : '';
	}

	/**
	 * Is this design fit to be ordered by the visitor holding $token?
	 *
	 * @param int    $design_id  Design row id.
	 * @param int    $product_id Product being ordered.
	 * @param string $template   Template it must be for.
	 * @param string $token      The visitor's session token.
	 * @return bool
	 */
	public static function design_is_orderable( $design_id, $product_id, $template, $token ) {
		$row = $design_id ? Binder_DB::get( $design_id ) : null;

		return $row
			&& '' !== $token
			&& hash_equals( (string) $row['session_token'], $token )
			&& (int) $row['product_id'] === (int) $product_id
			&& $row['template'] === $template
			&& 'ready' === $row['status']
			&& empty( $row['order_id'] );
	}

	public static function validate( $passed, $product_id ) {
		$required = Binder_Product_Meta::required_templates( $product_id );

		if ( ! $passed || empty( $required ) ) {
			return $passed;
		}

		$designs = self::posted_designs();
		$token   = self::posted_token();

		foreach ( $required as $template ) {
			$id = isset( $designs[ $template ] ) ? $designs[ $template ] : 0;

			if ( ! $id ) {
				wc_add_notice( sprintf( self::copy( 'missing' ), self::cover_name( $template ) ), 'error' );
				return false;
			}

			if ( ! self::design_is_orderable( $id, $product_id, $template, $token ) ) {
				wc_add_notice( sprintf( self::copy( 'invalid' ), self::cover_name( $template ) ), 'error' );
				return false;
			}

			// A sticker design is only good for the size and shape it was made for.
			if ( 'sticker' === $template && ! Binder_Sticker::design_matches_request( Binder_DB::get( $id ), $product_id ) ) {
				wc_add_notice( self::copy( 'size_mismatch' ), 'error' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Carry the verified design ids on the cart line. WooCommerce derives the
	 * line's identity from this data, so two different designs never merge.
	 */
	public static function add_item_data( $data, $product_id ) {
		$required = Binder_Product_Meta::required_templates( $product_id );

		if ( empty( $required ) ) {
			return $data;
		}

		$designs = self::posted_designs();
		$token   = self::posted_token();
		$keep    = array();

		foreach ( $required as $template ) {
			$id = isset( $designs[ $template ] ) ? $designs[ $template ] : 0;

			if ( self::design_is_orderable( $id, $product_id, $template, $token ) && ( 'sticker' !== $template || Binder_Sticker::design_matches_request( Binder_DB::get( $id ), $product_id ) ) ) {
				$keep[ $template ] = $id;
			}
		}

		if ( $keep ) {
			$data[ self::ITEM_KEY ] = $keep;
		}

		return $data;
	}

	public static function show_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! is_array( $cart_item[ self::ITEM_KEY ] ) ) {
			return $item_data;
		}

		foreach ( $cart_item[ self::ITEM_KEY ] as $template => $id ) {
			$item_data[] = array(
				'key'   => sprintf( self::copy( 'item_label' ), self::copy( $template ) ),
				'value' => self::copy( 'attached' ) . ' ✓',
			);
		}

		return $item_data;
	}
}
