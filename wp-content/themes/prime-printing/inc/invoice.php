<?php
/**
 * Phase 7 — bilingual PDF invoices.
 *
 * Replaces Challan Pro (WebAppick's "PDF Invoice for WooCommerce Pro") with a
 * custom generator built on the vendored Dompdf stack in vendor/ (see that
 * folder's autoload.php — there's no Composer on this project's dev machine,
 * so the library and its four dependencies are pulled in as plain source
 * trees rather than through a normal `composer install`).
 *
 * Two render modes share one template (prime_invoice_render_html()):
 *   color  the branded version, emailed to the customer on order completion
 *   print  a no-fill, outline-only version for in-house ink-saving printing
 *
 * The invoice number is the WooCommerce order number (Reem's call — Challan
 * Pro used its own separate sequence, but this build doesn't).
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * An order's invoice number — just its WooCommerce order number.
 *
 * get_order_number(), not get_id(): if a sequential-order-number plugin (or
 * a future QuickBooks-numbering scheme) ever changes what "the order number"
 * means, this follows it automatically rather than silently going back to
 * showing the raw post/order ID.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function prime_invoice_number( WC_Order $order ) {
	return $order->get_order_number();
}

/**
 * The theme's company info, for the invoice's "from" block.
 *
 * @return array{name: string, address: string, phone: string, email: string}
 */
function prime_invoice_company_info() {
	return array(
		'name'    => get_bloginfo( 'name' ),
		'address' => get_theme_mod( 'prime_contact_address', prime_default_company_address() ),
		'phone'   => get_theme_mod( 'prime_contact_phone', '' ),
		'email'   => get_theme_mod( 'prime_contact_email', '' ),
	);
}

/**
 * The billing block: whatever the order actually has, adapted to which of
 * the three address types it used.
 *
 * A gift order's street address is optional (Reem, 2026-09-05: "use the same
 * field address but optional not forced only to show them the price"), so it
 * is built exactly the same way as house/apartment — the same meta keys,
 * since prime_save_checkout_fields() now saves them for every type — and
 * simply comes out with fewer lines when the buyer skipped it. The
 * recipient's own name and phone are used regardless, since that (not the
 * buyer's) is who and where the parcel actually goes to.
 *
 * @param WC_Order $order Order.
 * @return array{name: string, lines: string[], phone: string}
 */
function prime_invoice_billing_block( WC_Order $order ) {
	$address_type = $order->get_meta( '_prime_address_type' );
	$is_gift      = 'gift' === $address_type;

	$lines = array();

	// House and apartment share block/street/building; an apartment adds
	// floor and flat. `_prime_building` is only read for orders placed before
	// the two panels were merged (2026-09-05) — newer ones put the building
	// number in `_prime_house_no` whichever type was chosen.
	$building_label = 'apartment' === $address_type
		/* translators: %s: building number or name. */
		? __( 'Building %s', 'prime-printing' )
		/* translators: %s: house number. */
		: __( 'House %s', 'prime-printing' );

	/* translators: %s: block number. */
	$keys = array( '_prime_block' => __( 'Block %s', 'prime-printing' ) );
	/* translators: %s: street name or number. */
	$keys['_prime_street']    = __( 'Street %s', 'prime-printing' );
	$keys['_prime_house_no']  = $building_label;
	/* translators: %s: building number or name. */
	$keys['_prime_building']  = __( 'Building %s', 'prime-printing' );

	if ( 'apartment' === $address_type ) {
		/* translators: %s: floor. */
		$keys['_prime_floor'] = __( 'Floor %s', 'prime-printing' );
		/* translators: %s: apartment number. */
		$keys['_prime_apartment_no'] = __( 'Apartment %s', 'prime-printing' );
	} else {
		/* translators: %s: avenue. */
		$keys['_prime_avenue'] = __( 'Avenue %s', 'prime-printing' );
	}

	/*
	 * Labelled, and on one line: the fields hold bare numbers, so an
	 * unlabelled join printed "1, 79, 5" on the first real order's invoice
	 * (2026-09-05) — three numbers nobody could deliver against. "Block 1,
	 * Street 79, House 5" is how a Kuwaiti address is actually written, and
	 * matches what prime_write_native_address() puts on the order itself.
	 */
	$street = array();

	foreach ( $keys as $key => $label ) {
		$value = $order->get_meta( $key );
		if ( $value ) {
			$street[] = sprintf( $label, $value );
		}
	}

	if ( $street ) {
		$lines[] = implode( ', ', $street );
	}

	// `_billing_area` is the real key — WooCommerce saves whatever the
	// billing_area field submitted (or the typed "Other" name, see
	// prime_save_checkout_fields()) under that name automatically, since it's
	// a 'billing_'-prefixed checkout field.
	$governorate = prime_governorate( $order->get_billing_state() );
	$area        = $order->get_meta( '_billing_area' );

	$locality = array_filter( array( $area, $governorate ? $governorate['name'] : null ) );
	if ( $locality ) {
		$lines[] = implode( ', ', $locality );
	}

	if ( $lines ) {
		$lines[] = __( 'Kuwait', 'prime-printing' );
	} elseif ( $is_gift ) {
		// The buyer left the optional address blank — Prime Printing phones
		// the recipient for it afterwards.
		$lines[] = __( 'Gift order — no street address collected.', 'prime-printing' );
	}

	return $is_gift
		? array(
			'name'  => $order->get_meta( '_prime_gift_name' ),
			'lines' => $lines,
			'phone' => $order->get_meta( '_prime_gift_phone' ),
		)
		: array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'lines' => $lines,
			'phone' => $order->get_billing_phone(),
		);
}

/**
 * Contextual Arabic letter forms, keyed by codepoint.
 *
 * [isolated, final, initial, medial] — a 0 means the letter has no such form
 * (the right-joining letters ا د ذ ر ز و ة and friends only ever take the
 * isolated or final shape). See prime_pdf_text() for why this exists at all.
 *
 * @return array<int, int[]>
 */
function prime_arabic_forms() {
	static $forms = null;

	if ( null !== $forms ) {
		return $forms;
	}

	$forms = array(
		0x0621 => array( 0xFE80, 0, 0, 0 ),
		0x0622 => array( 0xFE81, 0xFE82, 0, 0 ),
		0x0623 => array( 0xFE83, 0xFE84, 0, 0 ),
		0x0624 => array( 0xFE85, 0xFE86, 0, 0 ),
		0x0625 => array( 0xFE87, 0xFE88, 0, 0 ),
		0x0626 => array( 0xFE89, 0xFE8A, 0xFE8B, 0xFE8C ),
		0x0627 => array( 0xFE8D, 0xFE8E, 0, 0 ),
		0x0628 => array( 0xFE8F, 0xFE90, 0xFE91, 0xFE92 ),
		0x0629 => array( 0xFE93, 0xFE94, 0, 0 ),
		0x062A => array( 0xFE95, 0xFE96, 0xFE97, 0xFE98 ),
		0x062B => array( 0xFE99, 0xFE9A, 0xFE9B, 0xFE9C ),
		0x062C => array( 0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0 ),
		0x062D => array( 0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4 ),
		0x062E => array( 0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8 ),
		0x062F => array( 0xFEA9, 0xFEAA, 0, 0 ),
		0x0630 => array( 0xFEAB, 0xFEAC, 0, 0 ),
		0x0631 => array( 0xFEAD, 0xFEAE, 0, 0 ),
		0x0632 => array( 0xFEAF, 0xFEB0, 0, 0 ),
		0x0633 => array( 0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4 ),
		0x0634 => array( 0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8 ),
		0x0635 => array( 0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC ),
		0x0636 => array( 0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0 ),
		0x0637 => array( 0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4 ),
		0x0638 => array( 0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8 ),
		0x0639 => array( 0xFEC9, 0xFECA, 0xFECB, 0xFECC ),
		0x063A => array( 0xFECD, 0xFECE, 0xFECF, 0xFED0 ),
		0x0640 => array( 0x0640, 0x0640, 0x0640, 0x0640 ),
		0x0641 => array( 0xFED1, 0xFED2, 0xFED3, 0xFED4 ),
		0x0642 => array( 0xFED5, 0xFED6, 0xFED7, 0xFED8 ),
		0x0643 => array( 0xFED9, 0xFEDA, 0xFEDB, 0xFEDC ),
		0x0644 => array( 0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0 ),
		0x0645 => array( 0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4 ),
		0x0646 => array( 0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8 ),
		0x0647 => array( 0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC ),
		0x0648 => array( 0xFEED, 0xFEEE, 0, 0 ),
		0x0649 => array( 0xFEEF, 0xFEF0, 0, 0 ),
		0x064A => array( 0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4 ),
	);

	return $forms;
}

/**
 * Render Arabic text so Dompdf can draw it.
 *
 * Dompdf has no OpenType shaping engine and no bidi pass: it draws the
 * codepoints it is handed, left to right, in their isolated forms. Arabic
 * fed to it straight therefore comes out as disconnected letters running
 * backwards, which is what Reem reported on 2026-09-05 ("arabic text is
 * brok"). Nothing in the PDF stack fixes that for us, so the text is turned
 * into its final visual form here, before Dompdf ever sees it:
 *
 *   1. combining marks are dropped — they would land on the wrong base
 *      letter once the run is reversed, and Kuwaiti names and addresses do
 *      not vowel-mark;
 *   2. each letter is swapped for the Presentation Forms-B glyph (U+FE70
 *      block) that matches its joining context, plus the four lam-alef
 *      ligatures, which is what makes the letters actually connect — DejaVu
 *      Sans, the bundled PDF font, covers that whole block;
 *   3. the result is reordered into visual order by prime_bidi_visual().
 *
 * This is deliberately a subset of the Unicode bidi algorithm: enough for
 * names, addresses, product titles and the د.ك currency mark, which is all
 * an invoice carries. Latin-only strings are returned untouched, so it is
 * safe to wrap every field with it.
 *
 * @param string $text Logical-order text.
 * @return string Visual-order text ready for Dompdf.
 */
function prime_pdf_text( $text ) {
	$text = (string) $text;

	if ( '' === $text || ! preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
		return $text;
	}

	// Harakat, and the superscript alef — see point 1 in the docblock.
	$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text );

	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

	if ( ! $chars ) {
		return $text;
	}

	$points = array_map( 'prime_uni_ord', $chars );
	$forms  = prime_arabic_forms();

	// Lam followed by one of the four alefs is a single glyph in Arabic, not
	// two letters side by side; a font has no way to draw it as two.
	$ligatures = array(
		0x0622 => array( 0xFEF5, 0xFEF6 ),
		0x0623 => array( 0xFEF7, 0xFEF8 ),
		0x0625 => array( 0xFEF9, 0xFEFA ),
		0x0627 => array( 0xFEFB, 0xFEFC ),
	);

	$shaped     = array();
	$prev_joins = false; // Whether the letter before this one connects forward.
	$total      = count( $points );

	for ( $i = 0; $i < $total; $i++ ) {
		$point = $points[ $i ];

		if ( ! isset( $forms[ $point ] ) ) {
			$shaped[]   = $point;
			$prev_joins = false;
			continue;
		}

		$next = isset( $points[ $i + 1 ] ) ? $points[ $i + 1 ] : 0;

		if ( 0x0644 === $point && isset( $ligatures[ $next ] ) ) {
			$shaped[] = $prev_joins ? $ligatures[ $next ][1] : $ligatures[ $next ][0];
			// The ligature ends in an alef, which never connects forward.
			$prev_joins = false;
			$i++;
			continue;
		}

		$form          = $forms[ $point ];
		$joins_forward = 0 !== $form[2];
		$next_joinable = isset( $forms[ $next ] );

		if ( $prev_joins && $next_joinable && $joins_forward ) {
			$glyph = $form[3];
		} elseif ( $prev_joins ) {
			$glyph = $form[1];
		} elseif ( $next_joinable && $joins_forward ) {
			$glyph = $form[2];
		} else {
			$glyph = $form[0];
		}

		$shaped[]   = $glyph ? $glyph : $form[0];
		$prev_joins = $joins_forward;
	}

	$visual = prime_bidi_visual( $shaped );

	return implode( '', array_map( 'prime_uni_chr', $visual ) );
}

/**
 * Reorder a shaped codepoint run from logical into visual order.
 *
 * The paragraph direction is taken from the first strong character, then
 * right-to-left runs are reversed in place and, in a right-to-left
 * paragraph, the runs themselves are reversed too. Digits count as
 * left-to-right so "10.000 د.ك" keeps its number the right way round.
 *
 * @param int[] $points Shaped codepoints in logical order.
 * @return int[] Codepoints in visual order.
 */
function prime_bidi_visual( array $points ) {
	$strength = static function ( $point ) {
		if ( ( $point >= 0x0590 && $point <= 0x08FF ) || ( $point >= 0xFB1D && $point <= 0xFEFF ) ) {
			return 'rtl';
		}

		if (
			( $point >= 0x0030 && $point <= 0x0039 )
			|| ( $point >= 0x0041 && $point <= 0x005A )
			|| ( $point >= 0x0061 && $point <= 0x007A )
			|| ( $point >= 0x00C0 && $point <= 0x024F )
		) {
			return 'ltr';
		}

		return '';
	};

	$base = 'ltr';

	foreach ( $points as $point ) {
		$dir = $strength( $point );
		if ( $dir ) {
			$base = $dir;
			break;
		}
	}

	$runs    = array();
	$current = null;

	foreach ( $points as $point ) {
		// Spaces and punctuation have no direction of their own; they stay
		// with the run they were typed in.
		$dir = $strength( $point );

		if ( ! $dir ) {
			$dir = $current ? $current['dir'] : $base;
		}

		if ( ! $current || $current['dir'] !== $dir ) {
			if ( $current ) {
				$runs[] = $current;
			}
			$current = array(
				'dir'    => $dir,
				'points' => array(),
			);
		}

		$current['points'][] = $point;
	}

	if ( $current ) {
		$runs[] = $current;
	}

	foreach ( $runs as $index => $run ) {
		if ( 'rtl' === $run['dir'] ) {
			$runs[ $index ]['points'] = array_reverse( $run['points'] );
		}
	}

	if ( 'rtl' === $base ) {
		$runs = array_reverse( $runs );
	}

	$out = array();

	foreach ( $runs as $run ) {
		foreach ( $run['points'] as $point ) {
			$out[] = $point;
		}
	}

	return $out;
}

/**
 * mb_ord()/mb_chr() with a fallback, since neither is guaranteed on every
 * host this theme has to run on (the WASM dev server ships a trimmed mbstring).
 *
 * @param string $char One UTF-8 character.
 * @return int
 */
function prime_uni_ord( $char ) {
	if ( function_exists( 'mb_ord' ) ) {
		return (int) mb_ord( $char, 'UTF-8' );
	}

	$bytes = unpack( 'C*', $char );

	if ( ! $bytes ) {
		return 0;
	}

	$first = $bytes[1];

	if ( $first < 0x80 ) {
		return $first;
	}

	if ( $first < 0xE0 ) {
		return ( ( $first & 0x1F ) << 6 ) | ( $bytes[2] & 0x3F );
	}

	if ( $first < 0xF0 ) {
		return ( ( $first & 0x0F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
	}

	return ( ( $first & 0x07 ) << 18 ) | ( ( $bytes[2] & 0x3F ) << 12 ) | ( ( $bytes[3] & 0x3F ) << 6 ) | ( $bytes[4] & 0x3F );
}

/**
 * @param int $point Codepoint.
 * @return string One UTF-8 character.
 */
function prime_uni_chr( $point ) {
	if ( function_exists( 'mb_chr' ) ) {
		return (string) mb_chr( $point, 'UTF-8' );
	}

	if ( $point < 0x80 ) {
		return chr( $point );
	}

	if ( $point < 0x800 ) {
		return chr( 0xC0 | ( $point >> 6 ) ) . chr( 0x80 | ( $point & 0x3F ) );
	}

	if ( $point < 0x10000 ) {
		return chr( 0xE0 | ( $point >> 12 ) ) . chr( 0x80 | ( ( $point >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $point & 0x3F ) );
	}

	return chr( 0xF0 | ( $point >> 18 ) ) . chr( 0x80 | ( ( $point >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $point >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $point & 0x3F ) );
}

/**
 * An attachment as a base64 data URI, for embedding in the PDF.
 *
 * Dompdf runs with isRemoteEnabled off (it must — an invoice renderer that
 * fetches URLs is an SSRF waiting to happen), so images cannot be linked;
 * they have to travel inside the document. The registered thumbnail is used
 * rather than the original: a customer's 12 MP artwork upload would
 * otherwise base64 itself into a 15 MB invoice attachment that no mail
 * server would accept.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Registered image size.
 * @return string Data URI, or '' when the file is missing or is not an image.
 */
function prime_invoice_image_data_uri( $attachment_id, $size = 'thumbnail', $square = false ) {
	$attachment_id = (int) $attachment_id;

	if ( ! $attachment_id ) {
		return '';
	}

	$file = get_attached_file( $attachment_id );

	if ( ! $file ) {
		return '';
	}

	$meta = wp_get_attachment_metadata( $attachment_id );

	if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
		$sized = trailingslashit( dirname( $file ) ) . $meta['sizes'][ $size ]['file'];

		if ( is_readable( $sized ) ) {
			$file = $sized;
		}
	}

	if ( ! is_readable( $file ) ) {
		return '';
	}

	/*
	 * Customer artwork lives outside the uploads tree (see
	 * prime_handle_addon_upload()), so WordPress never generated thumbnails
	 * for it and $file above is still the original upload. Embedding that
	 * whole file turned the first real order's invoice into a 2.4 MB PDF with
	 * a phone screenshot running most of a page — so anything sizeable is
	 * resized here first, and an image that cannot be resized is dropped to
	 * its filename and download link rather than bloating an email
	 * attachment.
	 */
	if ( $square || filesize( $file ) > 150000 ) {
		$resized = prime_invoice_resized_copy( $file, $square );

		if ( ! $resized ) {
			return '';
		}

		$file = $resized;
	}

	$type = wp_check_filetype( $file );

	// A print-ready PDF/AI/EPS upload has no raster preview to show — those
	// line items simply get the filename and its download link instead.
	if ( empty( $type['type'] ) || ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
		return '';
	}

	$data = 'data:' . $type['type'] . ';base64,' . base64_encode( file_get_contents( $file ) );

	if ( ! empty( $resized ) ) {
		unlink( $resized );
	}

	return $data;
}

/**
 * A small temporary copy of an image, for embedding in the PDF.
 *
 * @param string $file   Absolute path to the original.
 * @param bool   $square Crop to a square thumbnail rather than scaling to fit.
 * @return string Absolute path to the copy, or '' if one could not be made.
 *                The caller owns the file and must delete it.
 */
function prime_invoice_resized_copy( $file, $square = false ) {
	$editor = wp_get_image_editor( $file );

	if ( is_wp_error( $editor ) ) {
		return '';
	}

	// Square-cropped for artwork: a portrait phone screenshot scaled to fit a
	// narrow column comes out several times taller than it is wide and pushes
	// the totals onto a second page (Reem, 2026-09-05: "make it thumbnail
	// small size in the invoice"). Cropping keeps every preview the same
	// small square whatever shape was uploaded — the full file is a click
	// away on the link beneath it.
	$editor->resize( $square ? 220 : 420, $square ? 220 : 420, $square );

	// wp_tempnam() creates the file it names, and the editor needs a .jpg
	// path to write to — so the placeholder is dropped and its name reused.
	$stub   = wp_tempnam( 'prime-invoice-preview' );
	$target = $stub . '.jpg';
	unlink( $stub );

	$saved = $editor->save( $target, 'image/jpeg' );

	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_readable( $saved['path'] ) ) {
		return '';
	}

	return $saved['path'];
}

/**
 * One <tr> per line item, in the four-column shape of Reem's reference
 * invoice (2026-09-05): product thumbnail, description, quantity, price.
 *
 * The description cell carries the same specs WooCommerce's own order emails
 * show — get_formatted_meta_data() is the identical call those templates
 * use, so nothing needs per-field special-casing here — except the uploaded
 * artwork, which is pulled out of that list and rendered the way the
 * reference does it: a preview image above the filename, linked to the
 * nonced download endpoint. Its display meta is skipped by matching the
 * download URL rather than a label, because each calculator labels it
 * differently ("Artwork", "File Upload", …) and a label match would quietly
 * start printing raw URLs the day one of them is renamed.
 *
 * @param WC_Order $order Order.
 * @param bool     $with_images Whether to embed thumbnails.
 * @return string HTML.
 */
function prime_invoice_line_items_html( WC_Order $order, $with_images = true ) {
	$rows = '';

	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = $item->get_product();
		$thumb   = '';

		if ( $with_images && $product ) {
			$data = prime_invoice_image_data_uri( $product->get_image_id() );

			if ( $data ) {
				$thumb = sprintf( '<img src="%s" class="prime-inv-thumb">', esc_attr( $data ) );
			}
		}

		$body = sprintf( '<div class="prime-inv-item-name">%s</div>', esc_html( prime_pdf_text( $item->get_name() ) ) );

		foreach ( $item->get_formatted_meta_data( '_' ) as $spec ) {
			$value = wp_strip_all_tags( $spec->display_value );

			if ( false !== strpos( $value, 'prime_download_file' ) ) {
				continue; // Rendered below, with its preview.
			}

			$body .= sprintf(
				'<div class="prime-inv-spec">%s: %s</div>',
				esc_html( prime_pdf_text( wp_strip_all_tags( $spec->display_key ) ) ),
				esc_html( prime_pdf_text( $value ) )
			);
		}

		foreach ( (array) $item->get_meta( '_prime_addon_files' ) as $attachment_id ) {
			$preview = $with_images ? prime_invoice_image_data_uri( $attachment_id, 'thumbnail', true ) : '';

			$body .= '<div class="prime-inv-artwork">';
			$body .= '<div class="prime-inv-artwork-label">' . esc_html__( 'Artwork', 'prime-printing' ) . '</div>';

			if ( $preview ) {
				$body .= sprintf( '<img src="%s" class="prime-inv-art">', esc_attr( $preview ) );
			}

			$body .= sprintf(
				'<div class="prime-inv-artwork-file"><a href="%s">%s</a></div>',
				esc_url( prime_file_download_url( $attachment_id ) ),
				esc_html( get_the_title( $attachment_id ) )
			);
			$body .= '</div>';
		}

		$rows .= sprintf(
			'<tr>
				<td class="prime-inv-cell prime-inv-thumbcell">%1$s</td>
				<td class="prime-inv-cell prime-inv-desc">%2$s</td>
				<td class="prime-inv-cell prime-inv-qty">%3$d</td>
				<td class="prime-inv-cell prime-inv-price">%4$s</td>
			</tr>',
			$thumb,
			$body,
			(int) $item->get_quantity(),
			esc_html( prime_invoice_money( $item->get_total() ) )
		);
	}

	return $rows;
}

/**
 * KWD is quoted to three decimals everywhere else in this theme
 * (see SETUP.md's store-settings table) — invoices match that, not
 * WooCommerce's own wc_price() output, so the two never visually disagree.
 *
 * @param float $amount Amount.
 * @return string
 */
function prime_invoice_format_money( $amount ) {
	return number_format( (float) $amount, 3 );
}

/**
 * The same amount with the Kuwaiti dinar mark, shaped for the PDF.
 *
 * @param float $amount Amount.
 * @return string
 */
function prime_invoice_money( $amount ) {
	return prime_pdf_text( prime_invoice_format_money( $amount ) . ' د.ك' );
}

/**
 * Build the invoice's HTML.
 *
 * The layout follows the invoice Reem asked for on 2026-09-05 ("use this
 * invoice template design for the woocommerce new orders", reference
 * 5928-invoice.pdf): logo left and company details right above a rule, a
 * centred "Invoice" heading, the buyer's details beside the order's, then a
 * fully ruled four-column items table that carries the totals as its last
 * rows, and a thank-you line.
 *
 * Both render modes share the markup; `prime-inv--print` drops the
 * thumbnails and the logo image for the ink-saving in-house copy.
 *
 * @param WC_Order $order Order.
 * @param string   $mode  'color' or 'print'.
 * @return string
 */
function prime_invoice_render_html( WC_Order $order, $mode = 'color' ) {
	$company  = prime_invoice_company_info();
	$billing  = prime_invoice_billing_block( $order );
	$number   = prime_invoice_number( $order );
	$is_print = 'print' === $mode;

	$logo_path = PRIME_DIR . '/assets/img/logo-navy.png';
	$logo_data = ( ! $is_print && is_readable( $logo_path ) )
		? 'data:image/png;base64,' . base64_encode( file_get_contents( $logo_path ) )
		: '';

	$items_html = prime_invoice_line_items_html( $order, ! $is_print );

	$shipping_label = $order->get_shipping_method()
		? $order->get_shipping_method()
		: __( 'Pickup', 'prime-printing' );

	$shipping_total = (float) $order->get_total_shipping();

	$company_lines = array_filter(
		array(
			$company['address'],
			/* translators: %s: company phone number. */
			$company['phone'] ? sprintf( __( 'Phone: %s', 'prime-printing' ), $company['phone'] ) : '',
			$company['email'],
		)
	);

	ob_start();
	?>
	<!DOCTYPE html>
	<html>
	<head>
	<meta charset="utf-8">
	<style>
		@page { margin: 30px 34px; }
		body { font-family: DejaVu Sans, sans-serif; color: #1F2937; font-size: 11px; }
		a { color: #2563EB; text-decoration: none; }

		table.prime-inv-head { width: 100%; }
		table.prime-inv-head td { vertical-align: top; }
		.prime-inv-logocell { width: 45%; }
		.prime-inv-logocell img { width: 175px; }
		.prime-inv-logocell strong { font-size: 17px; color: #10254A; }
		.prime-inv-company { text-align: right; font-size: 11px; line-height: 1.55; }
		.prime-inv-company strong { font-size: 12px; }

		.prime-inv-rule { border-bottom: 1px solid #7CA5C4; margin: 10px 0 22px; }
		.prime-inv--print .prime-inv-rule { border-bottom-color: #1F2937; }

		.prime-inv-title { text-align: center; font-size: 24px; font-weight: bold; color: #1F2937; margin-bottom: 18px; }

		table.prime-inv-meta { width: 100%; margin-bottom: 22px; }
		table.prime-inv-meta td { vertical-align: top; line-height: 1.5; font-size: 11px; }
		.prime-inv-metaleft { width: 42%; padding-right: 16px; }
		.prime-inv-label { font-weight: bold; }
		.prime-inv-heading { font-weight: bold; font-size: 12px; padding-bottom: 2px; }

		table.prime-inv-items { width: 100%; border-collapse: collapse; }
		.prime-inv-items td { border: 1px solid #10254A; }
		.prime-inv--print .prime-inv-items td { border-color: #1F2937; }
		.prime-inv-items thead td { text-align: center; font-weight: bold; font-size: 11px; padding: 7px 6px; }
		.prime-inv-cell { padding: 8px 7px; vertical-align: top; font-size: 10.5px; }
		.prime-inv-thumbcell { width: 105px; text-align: center; }
		.prime-inv-thumb { width: 92px; }
		.prime-inv-qty { width: 62px; text-align: center; }
		.prime-inv-price { width: 96px; text-align: right; white-space: nowrap; }
		.prime-inv-desc { text-align: center; }
		.prime-inv-item-name { font-weight: bold; margin-bottom: 3px; }
		.prime-inv-spec { color: #4B5563; font-size: 10px; }
		.prime-inv-artwork { margin-top: 6px; }
		.prime-inv-artwork-label { font-weight: bold; font-size: 10px; }
		.prime-inv-art { width: 62px; height: 62px; margin: 3px 0; }
		.prime-inv-artwork-file { font-size: 9.5px; }
		.prime-inv-totrow td { padding: 8px 7px; font-size: 11px; }
		.prime-inv-totlabel { font-weight: bold; }
		.prime-inv-totvalue { text-align: right; white-space: nowrap; }
		.prime-inv-grand td { font-weight: bold; }

		.prime-inv-thanks { text-align: center; font-size: 13px; margin-top: 26px; }
	</style>
	</head>
	<body class="<?php echo $is_print ? 'prime-inv--print' : ''; ?>">

		<table class="prime-inv-head">
			<tr>
				<td class="prime-inv-logocell">
					<?php if ( $logo_data ) : ?>
						<img src="<?php echo esc_attr( $logo_data ); ?>">
					<?php else : ?>
						<strong><?php echo esc_html( prime_pdf_text( $company['name'] ) ); ?></strong>
					<?php endif; ?>
				</td>
				<td class="prime-inv-company">
					<?php // The print copy already carries the name on the left, in place of the logo. ?>
					<?php if ( ! $is_print ) : ?>
						<strong><?php echo esc_html( prime_pdf_text( $company['name'] ) ); ?></strong><br>
					<?php endif; ?>
					<?php foreach ( $company_lines as $line ) : ?>
						<?php echo nl2br( esc_html( prime_pdf_text( $line ) ) ); ?><br>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>

		<div class="prime-inv-rule"></div>

		<div class="prime-inv-title"><?php esc_html_e( 'Invoice', 'prime-printing' ); ?></div>

		<table class="prime-inv-meta">
			<tr>
				<td class="prime-inv-metaleft">
					<div class="prime-inv-heading"><?php esc_html_e( 'Bill to', 'prime-printing' ); ?></div>
					<?php echo esc_html( prime_pdf_text( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) ); ?><br>
					<?php foreach ( array_filter( $billing['lines'] ) as $line ) : ?>
						<?php echo esc_html( prime_pdf_text( $line ) ); ?><br>
					<?php endforeach; ?>
					<?php if ( $order->get_billing_phone() ) : ?>
						<?php echo esc_html( $order->get_billing_phone() ); ?><br>
					<?php endif; ?>
					<?php echo esc_html( $order->get_billing_email() ); ?>
					<?php if ( $order->get_meta( '_prime_company_name' ) ) : ?>
						<br><?php echo esc_html( prime_pdf_text( $order->get_meta( '_prime_company_name' ) ) ); ?>
						<?php if ( $order->get_meta( '_prime_tax_number' ) ) : ?>
							<br><?php esc_html_e( 'Tax number:', 'prime-printing' ); ?> <?php echo esc_html( $order->get_meta( '_prime_tax_number' ) ); ?>
						<?php endif; ?>
					<?php endif; ?>
				</td>
				<td>
					<span class="prime-inv-label"><?php esc_html_e( 'Order:', 'prime-printing' ); ?></span> #<?php echo esc_html( $number ); ?><br>
					<span class="prime-inv-label"><?php esc_html_e( 'Shipping method:', 'prime-printing' ); ?></span> <?php echo esc_html( prime_pdf_text( $shipping_label ) ); ?><br>
					<span class="prime-inv-label"><?php esc_html_e( 'Payment method:', 'prime-printing' ); ?></span> <?php echo esc_html( prime_pdf_text( $order->get_payment_method_title() ) ); ?><br>
					<span class="prime-inv-label"><?php esc_html_e( 'Date:', 'prime-printing' ); ?></span> <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : '' ); ?>
					<?php
					/*
					 * A gift order goes to someone other than the buyer, so the
					 * recipient is spelled out here rather than being silently
					 * folded into "Bill to" — that address and phone are what
					 * fulfilment actually delivers against.
					 */
					?>
					<?php if ( 'gift' === $order->get_meta( '_prime_address_type' ) ) : ?>
						<br><br>
						<span class="prime-inv-label"><?php esc_html_e( 'Deliver to:', 'prime-printing' ); ?></span>
						<?php echo esc_html( prime_pdf_text( $billing['name'] ) ); ?><br>
						<?php echo esc_html( prime_pdf_text( implode( ', ', array_filter( $billing['lines'] ) ) ) ); ?><br>
						<?php echo esc_html( $billing['phone'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<table class="prime-inv-items">
			<thead>
				<tr>
					<td><?php esc_html_e( 'Thumbnail', 'prime-printing' ); ?></td>
					<td><?php esc_html_e( 'Product', 'prime-printing' ); ?></td>
					<td><?php esc_html_e( 'Quantity', 'prime-printing' ); ?></td>
					<td><?php esc_html_e( 'Price', 'prime-printing' ); ?></td>
				</tr>
			</thead>
			<tbody>
				<?php echo $items_html; // phpcs:ignore -- built from escaped pieces above. ?>

				<tr class="prime-inv-totrow">
					<td colspan="3" class="prime-inv-totlabel"><?php esc_html_e( 'Subtotal:', 'prime-printing' ); ?></td>
					<td class="prime-inv-totvalue"><?php echo esc_html( prime_invoice_money( $order->get_subtotal() ) ); ?></td>
				</tr>

				<tr class="prime-inv-totrow">
					<td colspan="3" class="prime-inv-totlabel"><?php esc_html_e( 'Shipping:', 'prime-printing' ); ?></td>
					<td class="prime-inv-totvalue">
						<?php
						// A free collection or pickup has no figure to show, so
						// the method's own name goes in its place — same as the
						// reference invoice's "Pick up From Al-Dajeej".
						echo esc_html( $shipping_total > 0 ? prime_invoice_money( $shipping_total ) : prime_pdf_text( $shipping_label ) );
						?>
					</td>
				</tr>

				<?php foreach ( $order->get_fees() as $fee ) : ?>
					<tr class="prime-inv-totrow">
						<td colspan="3" class="prime-inv-totlabel"><?php echo esc_html( prime_pdf_text( $fee->get_name() ) ); ?></td>
						<td class="prime-inv-totvalue"><?php echo esc_html( prime_invoice_money( $fee->get_total() ) ); ?></td>
					</tr>
				<?php endforeach; ?>

				<tr class="prime-inv-totrow prime-inv-grand">
					<td colspan="3" class="prime-inv-totlabel"><?php esc_html_e( 'Total:', 'prime-printing' ); ?></td>
					<td class="prime-inv-totvalue"><?php echo esc_html( prime_invoice_money( $order->get_total() ) ); ?></td>
				</tr>

				<tr class="prime-inv-totrow">
					<td colspan="3" class="prime-inv-totlabel"><?php esc_html_e( 'Payment method:', 'prime-printing' ); ?></td>
					<td class="prime-inv-totvalue"><?php echo esc_html( prime_pdf_text( $order->get_payment_method_title() ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="prime-inv-thanks"><?php esc_html_e( 'Thank you for your Business !', 'prime-printing' ); ?></div>

	</body>
	</html>
	<?php
	return ob_get_clean();
}

/**
 * Where a generated invoice PDF is cached on disk.
 *
 * Not inside a plugin/theme directory (both are code, not data — losing an
 * invoice PDF to a theme update would be a bad surprise) and not directly
 * web-guessable: `.htaccess` below denies all direct requests, so every real
 * download goes through prime_invoice_download() or the WooCommerce email
 * attachment, both of which check the requester actually has a right to that
 * specific order before ever touching the file.
 *
 * @param WC_Order $order Order.
 * @param string   $mode  'color' or 'print'.
 * @return string Absolute filesystem path.
 */
function prime_invoice_pdf_path( WC_Order $order, $mode ) {
	$upload_dir = wp_upload_dir();
	$dir        = trailingslashit( $upload_dir['basedir'] ) . 'prime-invoices';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$htaccess = $dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		// Apache only (production hosting, per SETUP.md) — harmless no-op on
		// the WASM dev server, which doesn't read .htaccess at all.
		file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
	}

	$index = $dir . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	return sprintf( '%s/%d-%s.pdf', $dir, $order->get_id(), $mode );
}

/**
 * Render (or return the cached copy of) an order's invoice PDF.
 *
 * Cached rather than regenerated on every request: a PDF render isn't free,
 * and the invoice for a completed order never changes, so there's nothing to
 * gain from re-rendering it — except after a manual edit to totals/items,
 * which is exactly what $force is for (the admin "Regenerate" case).
 *
 * @param WC_Order $order Order.
 * @param string   $mode  'color' or 'print'.
 * @param bool     $force Regenerate even if a cached copy exists.
 * @return string Absolute filesystem path to the PDF.
 */
function prime_invoice_generate_pdf( WC_Order $order, $mode = 'color', $force = false ) {
	$path = prime_invoice_pdf_path( $order, $mode );

	if ( ! $force && is_readable( $path ) ) {
		return $path;
	}

	require_once PRIME_DIR . '/vendor/autoload.php';

	$dompdf = new \Dompdf\Dompdf(
		array(
			'isRemoteEnabled'    => false,
			'defaultFont'        => 'DejaVu Sans',
			'isHtml5ParserEnabled' => true,
		)
	);
	$dompdf->loadHtml( prime_invoice_render_html( $order, $mode ), 'UTF-8' );
	$dompdf->setPaper( 'A4', 'portrait' );
	$dompdf->render();

	file_put_contents( $path, $dompdf->output() );

	return $path;
}

/**
 * Attach the color invoice to the order's own emails once it reaches a paid
 * status — not just the "completed" one, since a print business's order
 * isn't "completed" until it's actually fulfilled (days later), while the
 * customer should get their invoice as soon as the order is confirmed and
 * paid. Idempotent via order meta so a later completed/status-changed email
 * doesn't attach (or regenerate) it a second time.
 *
 * @param string       $status Slug without the wc- prefix, e.g. 'processing'.
 * @param int          $order_id Order ID.
 * @param WC_Order|null $order   Order object (WooCommerce also passes this).
 */
function prime_maybe_generate_invoice_on_status_change( $order_id, $order = null ) {
	$order = $order ?: wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( $order->get_meta( '_prime_invoice_generated' ) ) {
		return;
	}

	prime_invoice_generate_pdf( $order, 'color' );

	$order->update_meta_data( '_prime_invoice_generated', current_time( 'mysql' ) );
	$order->save_meta_data();
}
add_action( 'woocommerce_order_status_processing', 'prime_maybe_generate_invoice_on_status_change' );
add_action( 'woocommerce_order_status_completed', 'prime_maybe_generate_invoice_on_status_change' );

/**
 * Attach the (by now already-generated, or generated here on demand) color
 * invoice PDF to WooCommerce's own order emails.
 *
 * Scoped to the emails that are about one specific order: the customer's own
 * processing/completed/invoice mails, plus the shop's new-order notification.
 * Anything else (a password reset, a note to the customer) gets nothing.
 *
 * @param array   $attachments Existing attachment paths.
 * @param string  $email_id    e.g. 'customer_processing_order'.
 * @param WC_Order|null $order Order, when the email is order-related.
 * @return array
 */
function prime_attach_invoice_to_email( $attachments, $email_id, $order = null ) {
	$order_emails = array(
		'customer_processing_order',
		'customer_completed_order',
		'customer_invoice',
		// The shop's own new-order notification carries it too: Reem reads
		// that one (2026-09-05, "the order email has no attached PDF
		// invoice"), and having the invoice in hand the moment an order lands
		// is exactly what the notification is for.
		'new_order',
	);

	if ( ! in_array( $email_id, $order_emails, true ) || ! $order instanceof WC_Order ) {
		return $attachments;
	}

	$attachments[] = prime_invoice_generate_pdf( $order, 'color' );

	return $attachments;
}
add_filter( 'woocommerce_email_attachments', 'prime_attach_invoice_to_email', 10, 3 );

/**
 * Stream an invoice PDF, after checking the requester actually has a right
 * to it — shared by both entry points below, since the check and the stream
 * itself don't differ; only how each is reached does.
 *
 * @param int    $order_id Order ID.
 * @param string $mode     'color' or 'print'.
 */
function prime_stream_invoice( $order_id, $mode ) {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		wp_die( esc_html__( 'Order not found.', 'prime-printing' ), 404 );
	}

	// Shop staff can view any invoice; a logged-in customer only their own —
	// the nonce alone isn't ownership (a customer could still know another
	// customer's order ID), so this check has to run either way.
	$is_owner = is_user_logged_in() && $order->get_customer_id() && get_current_user_id() === $order->get_customer_id();

	if ( ! current_user_can( 'edit_shop_orders' ) && ! $is_owner ) {
		wp_die( esc_html__( 'You do not have permission to view this invoice.', 'prime-printing' ), 403 );
	}

	$path = prime_invoice_generate_pdf( $order, $mode );

	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="invoice-' . prime_invoice_number( $order ) . '-' . $mode . '.pdf"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a generated PDF, not user input.
	exit;
}

/**
 * The admin order screen's "Download invoice" / "Print version" buttons —
 * an admin_action_* on admin.php works here because shop staff clicking
 * this button are, by definition, already inside wp-admin.
 */
function prime_invoice_download() {
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
	$mode     = isset( $_GET['mode'] ) && 'print' === $_GET['mode'] ? 'print' : 'color';

	if ( ! $order_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'prime_invoice_download_' . $order_id ) ) {
		wp_die( esc_html__( 'Invalid or expired link.', 'prime-printing' ), 403 );
	}

	prime_stream_invoice( $order_id, $mode );
}
add_action( 'admin_action_prime_download_invoice', 'prime_invoice_download' );

/**
 * The same download, reachable from the front end — Phase 8's Orders tab
 * link. `admin_action_*` on `admin.php` won't do here: WordPress's own
 * wp-admin bootstrap redirects any user without an admin-area capability
 * away before an admin_action_* hook fires, and a WooCommerce "customer" has
 * none, so that endpoint would silently bounce every real customer back to
 * /my-account/ instead of serving the PDF (found by testing this as an
 * actual customer account — an admin account would never trip it, since
 * admins have wp-admin access).
 */
function prime_invoice_download_frontend() {
	if ( empty( $_GET['prime_download_invoice'] ) ) {
		return;
	}

	$order_id = absint( $_GET['prime_download_invoice'] );
	$mode     = isset( $_GET['mode'] ) && 'print' === $_GET['mode'] ? 'print' : 'color';

	if ( ! $order_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'prime_invoice_download_' . $order_id ) ) {
		wp_die( esc_html__( 'Invalid or expired link.', 'prime-printing' ), 403 );
	}

	prime_stream_invoice( $order_id, $mode );
}
add_action( 'template_redirect', 'prime_invoice_download_frontend' );

/**
 * Nonce'd, customer-reachable invoice download URL — for the account page's
 * Orders tab (not the admin order screen, which builds its own admin.php URL
 * in prime_admin_order_invoice_buttons() below, since that one runs inside
 * wp-admin and needs to stay there).
 *
 * @param WC_Order $order Order.
 * @param string   $mode  'color' or 'print'.
 * @return string
 */
function prime_invoice_frontend_url( WC_Order $order, $mode = 'color' ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'prime_download_invoice' => $order->get_id(),
				'mode'                   => $mode,
			),
			home_url( '/' )
		),
		'prime_invoice_download_' . $order->get_id()
	);
}

/**
 * The "Download invoice" / "Print version" buttons on the order edit screen.
 *
 * @param WC_Order $order Order.
 */
function prime_admin_order_invoice_buttons( $order ) {
	$color_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'   => 'prime_download_invoice',
				'order_id' => $order->get_id(),
				'mode'     => 'color',
			),
			admin_url( 'admin.php' )
		),
		'prime_invoice_download_' . $order->get_id()
	);

	$print_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'   => 'prime_download_invoice',
				'order_id' => $order->get_id(),
				'mode'     => 'print',
			),
			admin_url( 'admin.php' )
		),
		'prime_invoice_download_' . $order->get_id()
	);
	?>
	<div class="prime-admin-invoice order_data_column" style="clear:both; padding-top:12px;">
		<h4><?php esc_html_e( 'Invoice', 'prime-printing' ); ?></h4>
		<p>
			<a class="button" href="<?php echo esc_url( $color_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Download invoice PDF', 'prime-printing' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Print version (ink-saving)', 'prime-printing' ); ?>
			</a>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: invoice number */
				esc_html__( 'Invoice #%s', 'prime-printing' ),
				esc_html( prime_invoice_number( $order ) )
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'prime_admin_order_invoice_buttons' );
