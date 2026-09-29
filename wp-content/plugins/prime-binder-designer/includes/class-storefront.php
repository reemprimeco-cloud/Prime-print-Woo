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
	const FIELD_BINDING = 'binder_binding';
	const ITEM_KEY      = 'binder_designs';
	const ITEM_BINDING  = 'binder_binding';

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
			'binding_title' => array( 'Binder language', 'لغة الكلاسير' ),
			'binding_ltr'  => array( 'English · opens from the left', 'إنجليزي · يُفتح من اليسار' ),
			'binding_rtl'  => array( 'Arabic · opens from the right', 'عربي · يُفتح من اليمين' ),
			'binding_first' => array( 'Choose English or Arabic first: it decides which panel is the front cover.', 'اختر إنجليزي أو عربي أولاً، فهذا يحدد أي جهة هي الغلاف الأمامي.' ),
			'binding_changed' => array( 'The binder language changed. Please add your design again.', 'تغيّرت لغة الكلاسير. الرجاء إضافة تصميمك من جديد.' ),
			'binding_missing' => array( 'Please choose English or Arabic for your binder.', 'الرجاء اختيار إنجليزي أو عربي للكلاسير.' ),
			'binding_mismatch' => array( 'Your design was made for the other binder language. Please add it again.', 'تصميمك مُعدّ للغة الكلاسير الأخرى. الرجاء إضافته من جديد.' ),
			'opens_from'  => array( 'Binder', 'الكلاسير' ),
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

		$config = array(
			'editor'  => PRIME_BINDER_URL . 'assets/dist/index.html',
			'rest'    => untrailingslashit( (string) wp_parse_url( rest_url( 'binder/v1' ), PHP_URL_PATH ) ),
			'product' => $product_id,
			'lang'    => self::is_arabic() ? 'ar' : 'en',
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

		$hint      = count( $templates ) > 1 ? self::copy( 'hint_set' ) : self::copy( 'hint' );
		$is_binder = ! in_array( 'sticker', $templates, true );

		if ( $is_binder ) {
			$config['binding'] = array(
				'field'   => self::FIELD_BINDING,
				'first'   => self::copy( 'binding_first' ),
				'changed' => self::copy( 'binding_changed' ),
			);
		}
		?>
		<div class="binder-panel" data-binder-panel data-binder="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<h3 class="binder-panel__title"><?php echo esc_html( self::copy( 'heading' ) ); ?></h3>
			<p class="binder-panel__lead"><?php echo esc_html( self::copy( 'lead' ) ); ?></p>

			<?php if ( $is_binder ) : ?>
				<fieldset class="binder-binding" data-binder-binding>
					<legend class="binder-binding__title"><?php echo esc_html( self::copy( 'binding_title' ) ); ?></legend>
					<label class="binder-binding__option"><input type="radio" name="<?php echo esc_attr( self::FIELD_BINDING ); ?>" value="ltr" required> <span><?php echo esc_html( self::copy( 'binding_ltr' ) ); ?></span></label>
					<label class="binder-binding__option"><input type="radio" name="<?php echo esc_attr( self::FIELD_BINDING ); ?>" value="rtl" required> <span><?php echo esc_html( self::copy( 'binding_rtl' ) ); ?></span></label>
				</fieldset>
			<?php endif; ?>

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
						<button type="button" class="binder-btn" data-binder-open="live"><?php echo esc_html( self::copy( 'live' ) ); ?></button>
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
	 * The binder language posted with the add-to-cart request: 'ltr' | 'rtl' | ''.
	 *
	 * @return string
	 */
	public static function posted_binding() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$v = isset( $_POST[ self::FIELD_BINDING ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD_BINDING ] ) ) : '';

		return Binder_Templates::is_binding( $v ) ? $v : '';
	}

	/**
	 * The binding a saved design was made for: 'ltr' | 'rtl' | ''.
	 *
	 * @param array $row wp_binder_designs row.
	 * @return string
	 */
	public static function design_binding( array $row ) {
		$design = json_decode( (string) $row['design_json'], true );
		$v      = is_array( $design ) ? ( $design['binding'] ?? '' ) : '';

		return Binder_Templates::is_binding( $v ) ? $v : '';
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
		$binding = self::posted_binding();

		if ( ! in_array( 'sticker', $required, true ) && '' === $binding ) {
			wc_add_notice( self::copy( 'binding_missing' ), 'error' );
			return false;
		}

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

			// A binder design is only good for the language (opening side) it was made for.
			if ( 'sticker' !== $template && self::design_binding( Binder_DB::get( $id ) ) !== $binding ) {
				wc_add_notice( self::copy( 'binding_mismatch' ), 'error' );
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
		$binding = self::posted_binding();
		$keep    = array();

		foreach ( $required as $template ) {
			$id = isset( $designs[ $template ] ) ? $designs[ $template ] : 0;

			if ( ! self::design_is_orderable( $id, $product_id, $template, $token ) ) {
				continue;
			}
			if ( 'sticker' === $template ? Binder_Sticker::design_matches_request( Binder_DB::get( $id ), $product_id ) : self::design_binding( Binder_DB::get( $id ) ) === $binding ) {
				$keep[ $template ] = $id;
			}
		}

		if ( $keep ) {
			$data[ self::ITEM_KEY ] = $keep;
			if ( ! in_array( 'sticker', $required, true ) ) {
				$data[ self::ITEM_BINDING ] = $binding;
			}
		}

		return $data;
	}

	public static function show_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! is_array( $cart_item[ self::ITEM_KEY ] ) ) {
			return $item_data;
		}

		if ( ! empty( $cart_item[ self::ITEM_BINDING ] ) && Binder_Templates::is_binding( $cart_item[ self::ITEM_BINDING ] ) ) {
			$item_data[] = array(
				'key'   => self::copy( 'opens_from' ),
				'value' => self::copy( 'binding_' . $cart_item[ self::ITEM_BINDING ] ),
			);
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
