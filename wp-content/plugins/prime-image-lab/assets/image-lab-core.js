/**
 * PRIME Image Lab: pixel maths.
 *
 * Pure functions over ImageData-shaped objects ({ data, width, height }).
 * Loaded on the page and, through importScripts(), in the Web Worker, so the
 * same code runs in either place. Nothing here touches the DOM, the network
 * or storage.
 */
( function ( root ) {
	'use strict';

	var clamp = function ( v ) {
		return v < 0 ? 0 : v > 255 ? 255 : v;
	};

	/** Brightness (-100..100) and contrast (-100..100), in place. */
	function brightnessContrast( img, brightness, contrast ) {
		if ( ! brightness && ! contrast ) {
			return img;
		}

		var d = img.data;
		var b = ( brightness / 100 ) * 255;
		var c = contrast / 100;
		var f = ( 259 * ( c * 255 + 255 ) ) / ( 255 * ( 259 - c * 255 ) );
		var lut = new Uint8ClampedArray( 256 );

		for ( var i = 0; i < 256; i++ ) {
			lut[ i ] = clamp( f * ( i + b - 128 ) + 128 );
		}

		for ( var p = 0; p < d.length; p += 4 ) {
			d[ p ] = lut[ d[ p ] ];
			d[ p + 1 ] = lut[ d[ p + 1 ] ];
			d[ p + 2 ] = lut[ d[ p + 2 ] ];
		}

		return img;
	}

	/** Separable box blur on the RGB channels, one radius, returns a new array. */
	function boxBlur( src, w, h, radius ) {
		var out = new Uint8ClampedArray( src.length );
		var tmp = new Uint8ClampedArray( src.length );
		var win = radius * 2 + 1;
		var x;
		var y;
		var c;
		var sum;
		var idx;

		for ( y = 0; y < h; y++ ) {
			for ( c = 0; c < 3; c++ ) {
				sum = 0;
				for ( x = -radius; x <= radius; x++ ) {
					idx = ( y * w + Math.min( w - 1, Math.max( 0, x ) ) ) * 4 + c;
					sum += src[ idx ];
				}
				for ( x = 0; x < w; x++ ) {
					tmp[ ( y * w + x ) * 4 + c ] = sum / win;
					var add = ( y * w + Math.min( w - 1, x + radius + 1 ) ) * 4 + c;
					var sub = ( y * w + Math.max( 0, x - radius ) ) * 4 + c;
					sum += src[ add ] - src[ sub ];
				}
			}
		}

		for ( x = 0; x < w; x++ ) {
			for ( c = 0; c < 3; c++ ) {
				sum = 0;
				for ( y = -radius; y <= radius; y++ ) {
					idx = ( Math.min( h - 1, Math.max( 0, y ) ) * w + x ) * 4 + c;
					sum += tmp[ idx ];
				}
				for ( y = 0; y < h; y++ ) {
					out[ ( y * w + x ) * 4 + c ] = sum / win;
					var add2 = ( Math.min( h - 1, y + radius + 1 ) * w + x ) * 4 + c;
					var sub2 = ( Math.max( 0, y - radius ) * w + x ) * 4 + c;
					sum += tmp[ add2 ] - tmp[ sub2 ];
				}
			}
		}

		for ( var a = 3; a < src.length; a += 4 ) {
			out[ a ] = src[ a ];
		}

		return out;
	}

	/** Unsharp mask. amount 0..200 (%), radius 1..4 px. In place. */
	function sharpen( img, amount, radius ) {
		if ( ! amount ) {
			return img;
		}

		var d = img.data;
		var blur = boxBlur( d, img.width, img.height, Math.max( 1, Math.round( radius || 1 ) ) );
		var k = amount / 100;

		for ( var p = 0; p < d.length; p += 4 ) {
			d[ p ] = clamp( d[ p ] + ( d[ p ] - blur[ p ] ) * k );
			d[ p + 1 ] = clamp( d[ p + 1 ] + ( d[ p + 1 ] - blur[ p + 1 ] ) * k );
			d[ p + 2 ] = clamp( d[ p + 2 ] + ( d[ p + 2 ] - blur[ p + 2 ] ) * k );
		}

		return img;
	}

	/**
	 * Light noise reduction that spares edges: each pixel moves toward its
	 * neighbourhood average only as far as the difference stays small.
	 * strength 0..100. In place.
	 */
	function denoise( img, strength ) {
		if ( ! strength ) {
			return img;
		}

		var d = img.data;
		var blur = boxBlur( d, img.width, img.height, 1 );
		var threshold = 8 + strength * 0.6;
		var weight = Math.min( 1, strength / 60 );

		for ( var p = 0; p < d.length; p += 4 ) {
			for ( var c = 0; c < 3; c++ ) {
				var diff = Math.abs( d[ p + c ] - blur[ p + c ] );
				var w = weight * Math.max( 0, 1 - diff / threshold );
				d[ p + c ] = d[ p + c ] + ( blur[ p + c ] - d[ p + c ] ) * w;
			}
		}

		return img;
	}

	/** Median of border pixel colour, used as the plain background colour. */
	function sampleBackground( img ) {
		var d = img.data;
		var w = img.width;
		var h = img.height;
		var rs = [];
		var gs = [];
		var bs = [];
		var step = Math.max( 1, Math.floor( ( w + h ) / 200 ) );
		var push = function ( x, y ) {
			var i = ( y * w + x ) * 4;
			rs.push( d[ i ] );
			gs.push( d[ i + 1 ] );
			bs.push( d[ i + 2 ] );
		};

		for ( var x = 0; x < w; x += step ) {
			push( x, 0 );
			push( x, h - 1 );
		}
		for ( var y = 0; y < h; y += step ) {
			push( 0, y );
			push( w - 1, y );
		}

		var med = function ( a ) {
			a.sort( function ( m, n ) {
				return m - n;
			} );
			return a[ a.length >> 1 ];
		};

		return [ med( rs ), med( gs ), med( bs ) ];
	}

	/**
	 * Make a plain, flat background transparent.
	 *
	 * Floods inward from the image border through pixels close to the sampled
	 * background colour, so a same-coloured area inside the subject is kept.
	 * tolerance 1..100, feather 0..3 px. Returns { img, removed } where removed
	 * is the fraction of pixels made transparent (a low or very high value
	 * tells the caller the background was not plain).
	 */
	function removePlainBackground( img, tolerance, feather ) {
		var d = img.data;
		var w = img.width;
		var h = img.height;
		var bg = sampleBackground( img );
		var maxDist = ( tolerance / 100 ) * 140 + 6;
		var maxSq = maxDist * maxDist;
		var seen = new Uint8Array( w * h );
		var queue = new Int32Array( w * h );
		var head = 0;
		var tail = 0;

		var close = function ( i ) {
			var dr = d[ i * 4 ] - bg[ 0 ];
			var dg = d[ i * 4 + 1 ] - bg[ 1 ];
			var db = d[ i * 4 + 2 ] - bg[ 2 ];
			return dr * dr + dg * dg + db * db <= maxSq;
		};

		var enqueue = function ( i ) {
			if ( ! seen[ i ] && close( i ) ) {
				seen[ i ] = 1;
				queue[ tail++ ] = i;
			}
		};

		var x;
		var y;

		for ( x = 0; x < w; x++ ) {
			enqueue( x );
			enqueue( ( h - 1 ) * w + x );
		}
		for ( y = 0; y < h; y++ ) {
			enqueue( y * w );
			enqueue( y * w + w - 1 );
		}

		while ( head < tail ) {
			var i = queue[ head++ ];
			var px = i % w;
			var py = ( i - px ) / w;

			if ( px > 0 ) {
				enqueue( i - 1 );
			}
			if ( px < w - 1 ) {
				enqueue( i + 1 );
			}
			if ( py > 0 ) {
				enqueue( i - w );
			}
			if ( py < h - 1 ) {
				enqueue( i + w );
			}
		}

		// Soften the cut edge: a pixel next to removed ones gets partial alpha.
		var alpha = new Float32Array( w * h ).fill( 1 );
		var removed = 0;

		for ( var n = 0; n < seen.length; n++ ) {
			if ( seen[ n ] ) {
				alpha[ n ] = 0;
				removed++;
			}
		}

		for ( var pass = 0; pass < feather; pass++ ) {
			var next = alpha.slice();

			for ( y = 1; y < h - 1; y++ ) {
				for ( x = 1; x < w - 1; x++ ) {
					var k = y * w + x;

					if ( alpha[ k ] > 0 ) {
						var m = Math.min( alpha[ k - 1 ], alpha[ k + 1 ], alpha[ k - w ], alpha[ k + w ] );
						next[ k ] = Math.min( alpha[ k ], 0.5 + m * 0.5 );
					}
				}
			}
			alpha = next;
		}

		for ( var q = 0; q < alpha.length; q++ ) {
			d[ q * 4 + 3 ] = Math.round( d[ q * 4 + 3 ] * alpha[ q ] );
		}

		return { img: img, removed: removed / ( w * h ), color: bg };
	}

	/** Run the improve pipeline on ImageData. */
	function improve( img, s ) {
		denoise( img, s.denoise || 0 );
		brightnessContrast( img, s.brightness || 0, s.contrast || 0 );
		sharpen( img, s.sharpen || 0, s.sharpenRadius || 1 );
		return img;
	}

	/** Estimated pixels per inch for a pixel length over a physical length in mm. */
	function ppi( pixels, mm ) {
		return mm > 0 ? pixels / ( mm / 25.4 ) : 0;
	}

	/**
	 * Print readiness from pixel size and intended print size (mm).
	 * Thresholds are rules of thumb, not a quality guarantee.
	 */
	function preflight( wPx, hPx, wMm, hMm ) {
		var px = ppi( wPx, wMm );
		var py = ppi( hPx, hMm );
		var eff = Math.min( px, py );
		var level = eff >= 300 ? 'excellent' : eff >= 200 ? 'good' : eff >= 150 ? 'fair' : eff > 0 ? 'low' : 'none';
		var imageRatio = wPx / hPx;
		var printRatio = wMm / hMm;
		var ratioDiff = Math.abs( imageRatio - printRatio ) / printRatio;

		return {
			ppiX: px,
			ppiY: py,
			ppi: eff,
			level: level,
			ratioMismatch: ratioDiff > 0.03,
			ratioDiff: ratioDiff
		};
	}

	/* ---- DPI metadata -------------------------------------------------- */

	var crcTable = null;

	function crc32( bytes, start, end ) {
		if ( ! crcTable ) {
			crcTable = new Uint32Array( 256 );

			for ( var n = 0; n < 256; n++ ) {
				var c = n;

				for ( var k = 0; k < 8; k++ ) {
					c = c & 1 ? 0xEDB88320 ^ ( c >>> 1 ) : c >>> 1;
				}

				crcTable[ n ] = c >>> 0;
			}
		}

		var crc = 0xFFFFFFFF;

		for ( var i = start; i < end; i++ ) {
			crc = crcTable[ ( crc ^ bytes[ i ] ) & 0xFF ] ^ ( crc >>> 8 );
		}

		return ( crc ^ 0xFFFFFFFF ) >>> 0;
	}

	/**
	 * Record the print resolution in the file so printers and layout programs
	 * read it as dpi. PNG gets a pHYs chunk; JPEG gets (or has patched) its
	 * JFIF density. Returns a new Uint8Array; anything unrecognised is returned unchanged.
	 */
	function stampDpi( bytes, type, dpi ) {
		dpi = Math.round( dpi );

		if ( ! dpi || dpi < 1 ) {
			return bytes;
		}

		if ( 'image/png' === type && bytes[ 0 ] === 0x89 && bytes[ 1 ] === 0x50 ) {
			var perMeter = Math.round( dpi / 0.0254 );
			var chunk = new Uint8Array( 21 );
			var dv = new DataView( chunk.buffer );

			dv.setUint32( 0, 9 );
			chunk.set( [ 0x70, 0x48, 0x59, 0x73 ], 4 );
			dv.setUint32( 8, perMeter );
			dv.setUint32( 12, perMeter );
			chunk[ 16 ] = 1;
			dv.setUint32( 17, crc32( chunk, 4, 17 ) );

			// Drop an existing pHYs, then insert ours right after IHDR (8 + 25 bytes).
			var out = [];
			var pos = 8;
			var view = new DataView( bytes.buffer, bytes.byteOffset, bytes.byteLength );

			while ( pos < bytes.length ) {
				var len = view.getUint32( pos );
				var name = String.fromCharCode( bytes[ pos + 4 ], bytes[ pos + 5 ], bytes[ pos + 6 ], bytes[ pos + 7 ] );
				var end = pos + 12 + len;

				if ( 'pHYs' !== name ) {
					out.push( bytes.subarray( pos, end ) );

					if ( 'IHDR' === name ) {
						out.push( chunk );
					}
				}

				pos = end;
			}

			var total = 8;

			out.forEach( function ( part ) {
				total += part.length;
			} );

			var res = new Uint8Array( total );

			res.set( bytes.subarray( 0, 8 ), 0 );

			var at = 8;

			out.forEach( function ( part ) {
				res.set( part, at );
				at += part.length;
			} );

			return res;
		}

		if ( 'image/jpeg' === type && bytes[ 0 ] === 0xFF && bytes[ 1 ] === 0xD8 ) {
			var hasJfif = bytes[ 2 ] === 0xFF && bytes[ 3 ] === 0xE0 && bytes[ 6 ] === 0x4A && bytes[ 7 ] === 0x46 && bytes[ 8 ] === 0x49 && bytes[ 9 ] === 0x46;
			var jfif = new Uint8Array( [ 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x01, dpi >> 8, dpi & 255, dpi >> 8, dpi & 255, 0, 0 ] );

			if ( hasJfif ) {
				var copy = new Uint8Array( bytes );

				copy[ 13 ] = 1;
				copy[ 14 ] = dpi >> 8;
				copy[ 15 ] = dpi & 255;
				copy[ 16 ] = dpi >> 8;
				copy[ 17 ] = dpi & 255;

				return copy;
			}

			var res2 = new Uint8Array( bytes.length + jfif.length );

			res2.set( bytes.subarray( 0, 2 ), 0 );
			res2.set( jfif, 2 );
			res2.set( bytes.subarray( 2 ), 2 + jfif.length );

			return res2;
		}

		return bytes;
	}

	var api = {
		stampDpi: stampDpi,
		brightnessContrast: brightnessContrast,
		sharpen: sharpen,
		denoise: denoise,
		removePlainBackground: removePlainBackground,
		improve: improve,
		ppi: ppi,
		preflight: preflight
	};

	root.PrimeLabCore = api;

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
}( typeof self !== 'undefined' ? self : this ) );
