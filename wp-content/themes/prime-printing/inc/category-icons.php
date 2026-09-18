<?php
/**
 * The 3D glossy category icon set (Reem's `prime-printing-icons-3d.html`
 * reference), as inline SVG rather than raster images — crisp at any size,
 * and immune to the old hexagon-baked-into-the-PNG problem the original
 * category thumbnails had (see template-parts/shop/category-card.php).
 *
 * Each icon shares the same three gradients + drop-shadow filter, scoped to
 * a unique id per instance (a page can render the same icon more than once —
 * the shop grid and the homepage ticker, for example — and SVG gradient/
 * filter ids are global to the document).
 *
 * @package PrimePrinting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shared gradient/filter defs every icon draws from.
 *
 * `%1$s` is the instance id; literal `%` signs in the filter's percentage
 * units are escaped as `%%` for sprintf().
 *
 * @return string
 */
function prime_category_icon_defs_tpl() {
	return '<defs>'
		. '<linearGradient id="%1$sa" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3D5A85"/><stop offset="1" stop-color="#10254A"/></linearGradient>'
		. '<linearGradient id="%1$sb" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7CA5C4"/><stop offset="1" stop-color="#5083A8"/></linearGradient>'
		. '<linearGradient id="%1$shl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="rgba(255,255,255,.55)"/><stop offset="1" stop-color="rgba(255,255,255,0)"/></linearGradient>'
		. '<filter id="%1$ssh" x="-40%%" y="-20%%" width="180%%" height="180%%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#10254A" flood-opacity=".28"/></filter>'
		. '</defs>';
}

/**
 * Every icon body, keyed by lowercased category name. `%1$s` throughout is
 * the instance id, substituted once by prime_category_icon_svg().
 *
 * @return array<string, string>
 */
function prime_category_icons() {
	$defs = prime_category_icon_defs_tpl();

	return array(
		'occasions' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M18 30h28v10H18z" fill="url(#%1$sa)"/>'
			. '<path d="M20 40h24v18H20z" fill="url(#%1$sa)"/>'
			. '<path d="M20 40h24v3H20z" fill="url(#%1$sb)"/>'
			. '<rect x="30" y="30" width="4" height="28" fill="url(#%1$sb)"/>'
			. '<path d="M32 30c-4-7-14-8-14-2 0 3.5 6 2 14 2Z" fill="url(#%1$sb)"/>'
			. '<path d="M32 30c4-7 14-8 14-2 0 3.5-6 2-14 2Z" fill="url(#%1$sa)"/>'
			. '<path d="M20 40h24v3H20z" fill="url(#%1$shl)" opacity=".6"/>'
			. '</g>',

		'stickers' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M20 14h20l14 14v20a2 2 0 0 1-2 2H20a2 2 0 0 1-2-2V16a2 2 0 0 1 2-2Z" fill="url(#%1$sa)"/>'
			. '<path d="M40 14v12a2 2 0 0 0 2 2h12z" fill="url(#%1$sb)"/>'
			. '<path d="M20 14h20l4 4H24a4 4 0 0 0-4 4z" fill="url(#%1$shl)" opacity=".55"/>'
			. '</g>',

		'calendars' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="14" y="18" width="36" height="32" rx="4" fill="url(#%1$sa)"/>'
			. '<rect x="14" y="18" width="36" height="10" rx="4" fill="url(#%1$sb)"/>'
			. '<rect x="20" y="12" width="4" height="10" rx="2" fill="url(#%1$sa)"/>'
			. '<rect x="40" y="12" width="4" height="10" rx="2" fill="url(#%1$sa)"/>'
			. '<circle cx="24" cy="38" r="2.6" fill="url(#%1$sb)"/>'
			. '<circle cx="32" cy="38" r="2.6" fill="url(#%1$sb)"/>'
			. '<circle cx="40" cy="38" r="2.6" fill="rgba(255,255,255,.4)"/>'
			. '<rect x="14" y="18" width="36" height="5" fill="url(#%1$shl)" opacity=".6"/>'
			. '</g>',

		'stamps' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M26 12h12l3 10H23z" fill="url(#%1$sa)"/>'
			. '<path d="M21 22h22l4 10H17z" fill="url(#%1$sb)"/>'
			. '<rect x="14" y="32" width="36" height="10" rx="2" fill="url(#%1$sa)"/>'
			. '<path d="M14 48c3-4 6-4 9 0s6 4 9 0 6-4 9 0 6 4 9 0" stroke="url(#%1$sb)" stroke-width="3" fill="none" stroke-linecap="round"/>'
			. '<rect x="21" y="22" width="22" height="4" fill="url(#%1$shl)" opacity=".5"/>'
			. '</g>',

		'gifts printing' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="14" y="26" width="36" height="12" fill="url(#%1$sa)"/>'
			. '<rect x="16" y="38" width="32" height="20" fill="url(#%1$sa)"/>'
			. '<rect x="29" y="26" width="6" height="32" fill="url(#%1$sb)"/>'
			. '<path d="M32 26c-3.5-6.5-13-7.5-13-2.2 0 3.3 5 2.2 13 2.2Z" fill="url(#%1$sb)"/>'
			. '<path d="M32 26c3.5-6.5 13-7.5 13-2.2 0 3.3-5 2.2-13 2.2Z" fill="url(#%1$sa)"/>'
			. '<rect x="14" y="26" width="36" height="5" fill="url(#%1$shl)" opacity=".55"/>'
			. '</g>',

		'notebooks' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="16" y="10" width="30" height="44" rx="2" fill="url(#%1$sa)"/>'
			. '<rect x="12" y="10" width="6" height="44" rx="2" fill="url(#%1$sb)"/>'
			. '<rect x="24" y="20" width="16" height="3" rx="1.5" fill="rgba(255,255,255,.55)"/>'
			. '<rect x="24" y="27" width="16" height="3" rx="1.5" fill="rgba(255,255,255,.35)"/>'
			. '<rect x="24" y="34" width="10" height="3" rx="1.5" fill="rgba(255,255,255,.25)"/>'
			. '<rect x="16" y="10" width="30" height="6" fill="url(#%1$shl)" opacity=".5"/>'
			. '</g>',

		'packaging' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M32 8l20 9v22l-20 9-20-9V17z" fill="url(#%1$sa)"/>'
			. '<path d="M32 8l20 9-20 9-20-9z" fill="url(#%1$sb)"/>'
			. '<path d="M32 26v22l-20-9V17z" fill="rgba(8,19,42,.18)"/>'
			. '<path d="M32 8l20 9-6 2.7-20-9z" fill="url(#%1$shl)" opacity=".55"/>'
			. '</g>',

		'sign printing' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="14" y="10" width="5" height="44" rx="2" fill="url(#%1$sa)"/>'
			. '<path d="M19 12h26l-8 9 8 9H19z" fill="url(#%1$sb)"/>'
			. '<path d="M19 12h26l-8 9-18 0z" fill="url(#%1$shl)" opacity=".45"/>'
			. '</g>',

		'stationery printing' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M18 8h20l10 10v36H18z" fill="url(#%1$sa)"/>'
			. '<path d="M38 8v10h10z" fill="url(#%1$sb)"/>'
			. '<rect x="24" y="28" width="18" height="3" rx="1.5" fill="rgba(255,255,255,.5)"/>'
			. '<rect x="24" y="35" width="18" height="3" rx="1.5" fill="rgba(255,255,255,.3)"/>'
			. '<rect x="24" y="42" width="10" height="3" rx="1.5" fill="rgba(255,255,255,.2)"/>'
			. '<path d="M18 8h20l4 4H22a4 4 0 0 0-4 4z" fill="url(#%1$shl)" opacity=".5"/>'
			. '</g>',

		'party theme' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M12 50V30a20 20 0 0 1 40 0v20" stroke="url(#%1$sa)" stroke-width="7" fill="none" stroke-linecap="round"/>'
			. '<path d="M12 50V30a20 20 0 0 1 40 0v20" stroke="url(#%1$shl)" stroke-width="7" fill="none" stroke-linecap="round" opacity=".35" stroke-dasharray="0 44 34"/>'
			. '<circle cx="14" cy="46" r="6" fill="url(#%1$sb)"/>'
			. '<path d="M14 52l0 6" stroke="url(#%1$sb)" stroke-width="1.6" stroke-linecap="round"/>'
			. '<circle cx="50" cy="46" r="6" fill="url(#%1$sb)"/>'
			. '<path d="M50 52l0 6" stroke="url(#%1$sb)" stroke-width="1.6" stroke-linecap="round"/>'
			. '<circle cx="24" cy="14" r="5" fill="url(#%1$sb)"/>'
			. '<circle cx="32" cy="9" r="5.5" fill="url(#%1$shl2)"/>'
			. '<circle cx="40" cy="14" r="5" fill="url(#%1$sb)"/>'
			. '</g><defs><radialGradient id="%1$shl2" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".9"/><stop offset="1" stop-color="#7CA5C4"/></radialGradient></defs>',

		'luxury hardboard box' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M14 22l18-9 18 9-18 9z" fill="url(#%1$sb)"/>'
			. '<path d="M14 22v20l18 9V31z" fill="url(#%1$sa)"/>'
			. '<path d="M50 22v20l-18 9V31z" fill="#0C1C3B"/>'
			. '<rect x="28" y="27" width="8" height="8" rx="1" fill="rgba(255,255,255,.35)"/>'
			. '<path d="M14 22l18-9 4 2-18 9z" fill="url(#%1$shl)" opacity=".6"/>'
			. '</g>',

		'teacher supplies' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="10" y="10" width="44" height="30" rx="2" fill="url(#%1$sa)"/>'
			. '<rect x="10" y="10" width="44" height="6" fill="url(#%1$shl)" opacity=".5"/>'
			. '<rect x="15" y="15" width="34" height="20" rx="1" fill="#0C1C3B"/>'
			. '<path d="M20 28l6-9 5 6 5-8 8 11" stroke="url(#%1$sb)" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<rect x="10" y="40" width="44" height="4" rx="1.5" fill="url(#%1$sb)"/>'
			. '<path d="M18 44l-4 10M46 44l4 10" stroke="url(#%1$sa)" stroke-width="4" stroke-linecap="round"/>'
			. '</g>',

		'gift wrapping' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="16" y="18" width="32" height="32" rx="3" fill="url(#%1$sa)"/>'
			. '<rect x="16" y="18" width="32" height="8" fill="url(#%1$shl)" opacity=".45"/>'
			. '<rect x="29" y="18" width="6" height="32" fill="url(#%1$sb)"/>'
			. '<path d="M32 18c-3-6-11-6.6-11-2 0 3 4.4 2 11 2Z" fill="url(#%1$sb)"/>'
			. '<path d="M32 18c3-6 11-6.6 11-2 0 3-4.4 2-11 2Z" fill="url(#%1$sa)"/>'
			. '</g>',

		'bags printing' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M16 22h32l-3 32H19z" fill="url(#%1$sa)"/>'
			. '<path d="M16 22h32l-1 6H17z" fill="url(#%1$sb)"/>'
			. '<path d="M23 22v-5a9 9 0 0 1 18 0v5" stroke="url(#%1$sb)" stroke-width="3.5" fill="none" stroke-linecap="round"/>'
			. '<path d="M16 22h32l-1 3H17z" fill="url(#%1$shl)" opacity=".55"/>'
			. '</g>',

		'desk set' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="6" y="18" width="17" height="28" rx="2" fill="url(#%1$sa)"/>'
			. '<rect x="9" y="12" width="8" height="7" rx="1" fill="url(#%1$sa)"/>'
			. '<rect x="6" y="18" width="17" height="5" fill="url(#%1$shl)" opacity=".5"/>'
			. '<rect x="23" y="8" width="18" height="42" rx="2" fill="url(#%1$sb)"/>'
			. '<rect x="23" y="8" width="5" height="42" rx="2" fill="url(#%1$sa)"/>'
			. '<circle cx="25.5" cy="17" r="1.7" fill="rgba(255,255,255,.6)"/>'
			. '<circle cx="25.5" cy="29" r="1.7" fill="rgba(255,255,255,.6)"/>'
			. '<circle cx="25.5" cy="41" r="1.7" fill="rgba(255,255,255,.6)"/>'
			. '<rect x="23" y="8" width="18" height="5" fill="url(#%1$shl)" opacity=".4"/>'
			. '<rect x="39" y="24" width="16" height="14" rx="1" fill="url(#%1$sb)"/>'
			. '<rect x="39" y="24" width="16" height="3" fill="rgba(255,255,255,.5)"/>'
			. '<path d="M36 38h22l2 6a2 2 0 0 1-2 2H36a2 2 0 0 1-2-2z" fill="url(#%1$sa)"/>'
			. '<rect x="36" y="38" width="22" height="3" fill="url(#%1$shl)" opacity=".4"/>'
			. '</g>',

		'digital download' => $defs . '<g filter="url(#%1$ssh)">'
			. '<path d="M32 8v26" stroke="url(#%1$sa)" stroke-width="6" stroke-linecap="round"/>'
			. '<path d="M20 26l12 12 12-12" stroke="url(#%1$sb)" stroke-width="6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<rect x="12" y="42" width="40" height="12" rx="4" fill="url(#%1$sa)"/>'
			. '<rect x="12" y="42" width="40" height="4" fill="url(#%1$shl)" opacity=".5"/>'
			. '</g>',

		'vinyls' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="8" y="8" width="48" height="48" rx="2" fill="url(#%1$sa)"/>'
			. '<rect x="8" y="8" width="48" height="8" fill="url(#%1$shl)" opacity=".4"/>'
			. '<path d="M40 40C34 30 24 32 22 22c-1-5 3-9 8-8 8 2 8 12 15 18 5 4 5 10-2 12-4 1-8-1-3-4Z" fill="url(#%1$sb)"/>'
			. '<path d="M40 40c3-1 6 1 8 4l4 6" stroke="url(#%1$sb)" stroke-width="2" fill="none" stroke-linecap="round"/>'
			. '<path d="M22 22c1.5-2 4-2.6 6-1" stroke="rgba(255,255,255,.5)" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
			. '</g>',

		'silkscreen' => $defs . '<g filter="url(#%1$ssh)">'
			. '<rect x="10" y="10" width="44" height="44" rx="4" fill="url(#%1$sa)"/>'
			. '<rect x="10" y="10" width="44" height="8" fill="url(#%1$shl)" opacity=".5"/>'
			. '<path d="M10 24h44M10 38h44M24 10v44M38 10v44" stroke="url(#%1$sb)" stroke-width="2.5" opacity=".85"/>'
			. '</g>',
	);
}

/**
 * The inline SVG markup for one category, or an empty string if the name
 * doesn't match anything in the set (a category added later with no matching
 * icon yet — the caller falls back to the term's own thumbnail image).
 *
 * @param string     $name       Category name, e.g. $term->name.
 * @param int|string $unique_id  Anything unique to this render (the term id
 *                                is the natural choice) — keeps this icon's
 *                                gradient/filter ids from colliding with
 *                                another instance of the same icon elsewhere
 *                                on the same page.
 * @return string
 */
function prime_category_icon_svg( $name, $unique_id ) {
	$icons = prime_category_icons();
	$key   = strtolower( trim( $name ) );

	if ( ! isset( $icons[ $key ] ) ) {
		return '';
	}

	$id   = 'picon' . preg_replace( '/[^a-z0-9]/', '', $key ) . absint( $unique_id );
	$body = sprintf( $icons[ $key ], $id );

	return '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">' . $body . '</svg>';
}
