/**
 * PRIME Image Lab: roll-up banner layouts.
 *
 * Builds a banner artboard around a photo with three rule-based layouts. This
 * is design automation, not generative AI: it extends the photo's own colours
 * and edges, uses the optional cut-out of the main subject, and sets the text
 * the visitor typed. Nothing is invented that is not in the photo. Everything
 * runs in the browser on canvases.
 */
( function ( root ) {
	'use strict';

	var NAVY = '#10254A';
	var SKY = '#7CA5C4';

	var makeCanvas = function ( w, h ) {
		var c = document.createElement( 'canvas' );

		c.width = Math.max( 1, Math.round( w ) );
		c.height = Math.max( 1, Math.round( h ) );

		return c;
	};

	/** Soft blur without ctx.filter (not available in every Safari): shrink hard, enlarge smoothly. */
	var softBlur = function ( src, w, h, strength ) {
		var tiny = makeCanvas( Math.max( 4, w / strength ), Math.max( 4, h / strength ) );
		var tctx = tiny.getContext( '2d' );

		tctx.imageSmoothingQuality = 'high';
		tctx.drawImage( src, 0, 0, tiny.width, tiny.height );

		var out = makeCanvas( w, h );
		var octx = out.getContext( '2d' );

		octx.imageSmoothingQuality = 'high';
		octx.drawImage( tiny, 0, 0, w, h );

		// A second pass smooths the blocky steps left by one large enlargement.
		var mid = makeCanvas( w / 2, h / 2 );
		var mctx = mid.getContext( '2d' );

		mctx.imageSmoothingQuality = 'high';
		mctx.drawImage( out, 0, 0, mid.width, mid.height );
		octx.drawImage( mid, 0, 0, w, h );
		tiny.width = 0;
		mid.width = 0;

		return out;
	};

	/** Draw src to fill w x h, cropping the overflow around a focal point. */
	var cover = function ( ctx, src, x, y, w, h, fx, fy ) {
		var k = Math.max( w / src.width, h / src.height );
		var sw = w / k;
		var sh = h / k;
		var sx = Math.min( src.width - sw, Math.max( 0, ( src.width - sw ) * ( fx === undefined ? 0.5 : fx ) ) );
		var sy = Math.min( src.height - sh, Math.max( 0, ( src.height - sh ) * ( fy === undefined ? 0.5 : fy ) ) );

		ctx.drawImage( src, sx, sy, sw, sh, x, y, w, h );
	};

	/** Average colour, a vibrant colour and a light/dark flag from a 24 px sample. */
	var palette = function ( src ) {
		var c = makeCanvas( 24, 24 );
		var ctx = c.getContext( '2d' );

		ctx.drawImage( src, 0, 0, 24, 24 );

		var d = ctx.getImageData( 0, 0, 24, 24 ).data;
		var r = 0;
		var g = 0;
		var b = 0;
		var best = null;
		var bestScore = -1;
		var n = 0;

		for ( var i = 0; i < d.length; i += 4 ) {
			if ( d[ i + 3 ] < 200 ) {
				continue;
			}

			r += d[ i ];
			g += d[ i + 1 ];
			b += d[ i + 2 ];
			n++;

			var mx = Math.max( d[ i ], d[ i + 1 ], d[ i + 2 ] );
			var mn = Math.min( d[ i ], d[ i + 1 ], d[ i + 2 ] );
			var sat = mx ? ( mx - mn ) / mx : 0;
			var score = sat * ( 1 - Math.abs( mx / 255 - 0.55 ) );

			if ( score > bestScore ) {
				bestScore = score;
				best = [ d[ i ], d[ i + 1 ], d[ i + 2 ] ];
			}
		}

		n = n || 1;

		var avg = [ r / n, g / n, b / n ];

		return {
			avg: avg,
			vivid: bestScore > 0.15 ? best : avg,
			dark: ( 0.299 * avg[ 0 ] + 0.587 * avg[ 1 ] + 0.114 * avg[ 2 ] ) < 140
		};
	};

	var rgb = function ( c, a ) {
		return 'rgba(' + Math.round( c[ 0 ] ) + ',' + Math.round( c[ 1 ] ) + ',' + Math.round( c[ 2 ] ) + ',' + ( a === undefined ? 1 : a ) + ')';
	};

	var mix = function ( a, b, t ) {
		return [ a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * t, a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * t, a[ 2 ] + ( b[ 2 ] - a[ 2 ] ) * t ];
	};

	var luminance = function ( c ) {
		return 0.299 * c[ 0 ] + 0.587 * c[ 1 ] + 0.114 * c[ 2 ];
	};

	/** Wrap text into lines that fit maxW, shrinking the font until it fits maxLines. */
	var fitText = function ( ctx, text, maxW, maxLines, startPx, weight, family ) {
		var px = startPx;
		var lines;

		while ( px > 8 ) {
			ctx.font = weight + ' ' + px + 'px ' + family;
			lines = [];

			var words = text.split( /\s+/ );
			var line = '';

			for ( var i = 0; i < words.length; i++ ) {
				var test = line ? line + ' ' + words[ i ] : words[ i ];

				if ( ctx.measureText( test ).width > maxW && line ) {
					lines.push( line );
					line = words[ i ];
				} else {
					line = test;
				}
			}

			lines.push( line );

			var widest = 0;

			lines.forEach( function ( l ) {
				widest = Math.max( widest, ctx.measureText( l ).width );
			} );

			if ( lines.length <= maxLines && widest <= maxW ) {
				break;
			}

			px -= Math.max( 1, Math.round( px * 0.06 ) );
		}

		return { lines: lines, px: px };
	};

	var drawText = function ( ctx, W, text, topY, maxW, o ) {
		if ( ! text ) {
			return topY;
		}

		var fit = fitText( ctx, text, maxW, o.maxLines, o.size, o.weight, o.family );

		ctx.font = o.weight + ' ' + fit.px + 'px ' + o.family;
		ctx.fillStyle = o.color;
		ctx.textAlign = 'center';
		ctx.textBaseline = 'top';
		ctx.direction = o.rtl ? 'rtl' : 'ltr';

		if ( o.shadow ) {
			ctx.shadowColor = 'rgba(0,0,0,.35)';
			ctx.shadowBlur = fit.px * 0.18;
			ctx.shadowOffsetY = fit.px * 0.05;
		}

		var y = topY;

		fit.lines.forEach( function ( l ) {
			ctx.fillText( l, W / 2, y );
			y += fit.px * 1.18;
		} );

		ctx.shadowColor = 'transparent';
		ctx.shadowBlur = 0;
		ctx.shadowOffsetY = 0;

		return y;
	};

	/**
	 * compose( opts ) -> canvas
	 *  src     canvas of the photo
	 *  mask    optional canvas of the photo with the background removed (transparent PNG-like)
	 *  style   'extend' | 'hero' | 'panel'
	 *  width, height   output size in px
	 *  text    { headline, sub, contact }
	 *  rtl     right-to-left text
	 *  family  CSS font-family for the text
	 */
	function compose( opts ) {
		var W = opts.width;
		var H = opts.height;
		var src = opts.src;
		var text = opts.text || {};
		var family = opts.family || 'Montserrat, Arial, sans-serif';
		var out = makeCanvas( W, H );
		var ctx = out.getContext( '2d' );
		var pal = palette( opts.mask || src );
		var pad = W * 0.07;
		var textW = W - pad * 2;
		var style = opts.style || 'extend';
		var headlineSize = W * 0.105;
		var white = '#ffffff';
		var topTextY;
		var bottomTextY;

		if ( 'extend' === style ) {
			// The photo's own edges are stretched and blurred into a backdrop, and the photo sits
			// in the middle with its top and bottom melting into it.
			var back = makeCanvas( W, H );
			var bctx = back.getContext( '2d' );

			cover( bctx, src, 0, 0, W, H, 0.5, 0.5 );

			var blurred = softBlur( back, W, H, 28 );

			ctx.drawImage( blurred, 0, 0 );
			blurred.width = 0;
			back.width = 0;
			ctx.fillStyle = rgb( mix( pal.avg, [ 8, 19, 42 ], 0.55 ), 0.62 );
			ctx.fillRect( 0, 0, W, H );

			var ph = src.height * ( W / src.width );
			var maxPh = H * 0.5;
			var pw = W;

			if ( ph > maxPh ) {
				ph = maxPh;
				pw = src.width * ( ph / src.height );
			}

			var py = H * 0.3;
			var band = makeCanvas( W, ph );
			var cx = band.getContext( '2d' );

			cx.drawImage( src, ( W - pw ) / 2, 0, pw, ph );

			// Feather the band's top and bottom (and sides if it does not span the width).
			cx.globalCompositeOperation = 'destination-in';

			var fade = cx.createLinearGradient( 0, 0, 0, ph );

			fade.addColorStop( 0, 'rgba(0,0,0,0)' );
			fade.addColorStop( 0.16, 'rgba(0,0,0,1)' );
			fade.addColorStop( 0.84, 'rgba(0,0,0,1)' );
			fade.addColorStop( 1, 'rgba(0,0,0,0)' );
			cx.fillStyle = fade;
			cx.fillRect( 0, 0, W, ph );
			ctx.drawImage( band, 0, py );
			band.width = 0;

			topTextY = H * 0.07;
			bottomTextY = py + ph + H * 0.04;
		} else if ( 'hero' === style ) {
			// A gradient built from the photo's colours, a glow, and the cut-out subject large in front.
			var top = mix( pal.vivid, [ 8, 19, 42 ], pal.dark ? 0.35 : 0.62 );
			var bottom = mix( pal.vivid, [ 8, 19, 42 ], pal.dark ? 0.7 : 0.85 );
			var grad = ctx.createLinearGradient( 0, 0, 0, H );

			grad.addColorStop( 0, rgb( top ) );
			grad.addColorStop( 1, rgb( bottom ) );
			ctx.fillStyle = grad;
			ctx.fillRect( 0, 0, W, H );

			var glow = ctx.createRadialGradient( W / 2, H * 0.55, W * 0.05, W / 2, H * 0.55, W * 0.9 );

			glow.addColorStop( 0, rgb( mix( pal.vivid, [ 255, 255, 255 ], 0.35 ), 0.55 ) );
			glow.addColorStop( 1, rgb( pal.vivid, 0 ) );
			ctx.fillStyle = glow;
			ctx.fillRect( 0, 0, W, H );

			var subject = opts.mask || src;
			var sw = W * 0.9;
			var sh = subject.height * ( sw / subject.width );
			var maxSh = H * 0.5;

			if ( sh > maxSh ) {
				sh = maxSh;
				sw = subject.width * ( sh / subject.height );
			}

			ctx.shadowColor = 'rgba(0,0,0,.45)';
			ctx.shadowBlur = W * 0.04;
			ctx.shadowOffsetY = W * 0.025;
			ctx.drawImage( subject, ( W - sw ) / 2, H * 0.62 - sh / 2, sw, sh );
			ctx.shadowColor = 'transparent';
			ctx.shadowBlur = 0;
			ctx.shadowOffsetY = 0;

			topTextY = H * 0.07;
			bottomTextY = H * 0.62 + sh / 2 + H * 0.025;
		} else {
			// A photo block on top, a solid brand panel below for the words.
			var blockH = H * 0.52;

			ctx.fillStyle = NAVY;
			ctx.fillRect( 0, 0, W, H );
			cover( ctx, src, 0, 0, W, blockH, 0.5, 0.45 );

			var shade = ctx.createLinearGradient( 0, blockH * 0.7, 0, blockH );

			shade.addColorStop( 0, 'rgba(16,37,74,0)' );
			shade.addColorStop( 1, 'rgba(16,37,74,1)' );
			ctx.fillStyle = shade;
			ctx.fillRect( 0, blockH * 0.7, W, blockH * 0.3 );
			ctx.fillStyle = SKY;
			ctx.fillRect( pad, blockH + H * 0.012, W * 0.12, H * 0.004 );

			topTextY = blockH + H * 0.04;
			bottomTextY = null;
		}

		var onDark = 'panel' === style || 'hero' === style || true;
		var textColor = onDark ? white : NAVY;
		var y;

		if ( 'panel' === style ) {
			y = drawText( ctx, W, text.headline, topTextY, textW, { size: headlineSize, weight: 700, family: family, color: textColor, maxLines: 3, rtl: opts.rtl } );
			y = drawText( ctx, W, text.sub, y + H * 0.015, textW, { size: W * 0.052, weight: 400, family: family, color: 'rgba(255,255,255,.82)', maxLines: 4, rtl: opts.rtl } );
		} else {
			y = drawText( ctx, W, text.headline, topTextY, textW, { size: headlineSize, weight: 700, family: family, color: textColor, maxLines: 3, rtl: opts.rtl, shadow: true } );
			drawText( ctx, W, text.sub, y + H * 0.012, textW, { size: W * 0.05, weight: 400, family: family, color: 'rgba(255,255,255,.9)', maxLines: 3, rtl: opts.rtl, shadow: true } );

			if ( bottomTextY ) {
				drawText( ctx, W, text.body || '', bottomTextY, textW, { size: W * 0.048, weight: 400, family: family, color: 'rgba(255,255,255,.9)', maxLines: 4, rtl: opts.rtl, shadow: true } );
			}
		}

		// Contact bar at the foot.
		if ( text.contact ) {
			var barH = H * 0.085;

			ctx.fillStyle = 'rgba(8,19,42,.86)';
			ctx.fillRect( 0, H - barH, W, barH );
			ctx.fillStyle = SKY;
			ctx.fillRect( 0, H - barH, W, H * 0.003 );
			drawText( ctx, W, text.contact, H - barH + barH * 0.3, textW, { size: W * 0.055, weight: 500, family: family, color: white, maxLines: 1, rtl: opts.rtl } );
		}

		return out;
	}

	root.PrimeLabBanner = { compose: compose, palette: palette };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = root.PrimeLabBanner;
	}
}( typeof self !== 'undefined' ? self : this ) );
