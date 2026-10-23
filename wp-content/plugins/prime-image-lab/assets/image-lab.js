/**
 * PRIME Image Lab: the page.
 *
 * Everything runs in the visitor's browser. Nothing is uploaded: the chosen
 * file is read with createImageBitmap(), drawn to canvases that live only in
 * memory, processed in a Web Worker (or on the page if workers are blocked)
 * and downloaded through a short-lived blob URL. No network request is made
 * with image data, and no analytics, storage or third-party code is involved.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'pil-root' );
	var Core = window.PrimeLabCore;

	if ( ! root || ! Core ) {
		return;
	}

	var cfg = window.primeImageLab || {};

	// The theme sets a narrow reading width on page content; the tool needs the full width.
	var host = root.closest( '.prime-prose' );

	if ( host ) {
		host.classList.add( 'pil-host' );
	}
	var MB = 1024 * 1024;
	var isTouch = /iP(hone|ad|od)|Android/i.test( navigator.userAgent ) || ( navigator.maxTouchPoints > 1 && /Mac/i.test( navigator.platform ) );
	var LIMITS = {
		fileBytes: 30 * MB,
		// iOS Safari cannot create a canvas above about 16.7 megapixels; other
		// phones are tighter on memory than computers, so they share the limit.
		pixels: isTouch ? 16000000 : 40000000,
		minSide: 16
	};

	/* ---- Text ------------------------------------------------------------ */

	var T = {
		en: {
			title: 'PRIME Image Lab',
			steps: [ 'Upload', 'Tool', 'Adjust', 'Preview', 'Download', 'Print' ],
			dropTitle: 'Drop your image here',
			dropHint: 'JPEG, PNG or WebP, up to 30 MB. Your image stays on this device.',
			choose: 'Choose photo',
			change: 'Choose another photo',
			before: 'Before',
			after: 'After',
			compare: 'Before and after position',
			tabs: [ 'Improve', 'Background', 'Print check' ],
			improve: 'Improve image',
			brightness: 'Brightness',
			contrast: 'Contrast',
			sharpen: 'Sharpen',
			denoise: 'Noise reduction',
			scale: 'Size',
			custom: 'Custom size',
			width: 'Width (px)',
			height: 'Height (px)',
			lock: 'Keep aspect ratio',
			noteResize: 'Resizing makes the image bigger but cannot recreate missing detail. It is not AI super-resolution.',
			warnEnlarge: 'Enlarging this much can look soft or blocky when printed.',
			apply: 'Apply',
			reset: 'Reset',
			startOver: 'Start over',
			bgTitle: 'Remove plain background',
			bgNote: 'This removes a flat, single-colour background that touches the edges of the photo (a white wall, a studio sheet). It is not AI, so busy or similar-coloured backgrounds will not work. The result is a transparent PNG.',
			tolerance: 'Tolerance',
			feather: 'Edge softness',
			removeBg: 'Remove background',
			bgNone: 'No plain background was found. Try a higher tolerance, or use a photo with a flat background.',
			bgAll: 'Almost the whole image was removed. Lower the tolerance.',
			bgDone: 'Background removed: %s% of the image is now transparent.',
			printTitle: 'Print readiness check',
			printW: 'Print width',
			printH: 'Print height',
			unit: 'Unit',
			ppiTitle: 'Estimated resolution',
			ppiWord: 'PPI',
			levels: {
				excellent: 'Excellent for print',
				good: 'Good for most print',
				fair: 'Fair: fine for large formats seen from a distance, soft up close',
				low: 'Low: likely to look blurry or pixelated',
				none: 'Enter a print size'
			},
			ratioWarn: 'The print shape differs from the image shape, so it will be cropped or have margins.',
			lowWarn: 'The image has few pixels for this print size.',
			resizedWarn: 'Pixels added by resizing do not add real detail. The original image had about %s PPI at this size.',
			printNote: 'This is an estimate from pixel size, not a guarantee of print quality. Screen colours (RGB) can look different once printed. PRIME does not convert to CMYK here.',
			facts: { px: 'Pixels', type: 'Format', size: 'File size', ratio: 'Aspect ratio' },
			downloadTitle: 'Download',
			format: 'Format',
			quality: 'Quality',
			download: 'Download image',
			noResult: 'Apply a tool first to get a result to download.',
			pngForced: 'Transparent results are saved as PNG.',
			whatsapp: 'Continue on WhatsApp',
			shop: 'Browse printing products',
			waNote: 'Attach the downloaded image yourself in WhatsApp. It is not sent automatically.',
			waMessage: 'Hello PRIME, I prepared an image using PRIME Image Lab and would like to print it.',
			privacy: 'Your image never leaves your device. Everything runs in your browser: nothing is uploaded, stored or tracked.',
			statusReady: 'Ready',
			statusLoading: 'Reading image…',
			statusWorking: 'Processing…',
			statusDone: 'Done: %s',
			errType: 'This file type is not supported. Please choose a JPEG, PNG or WebP image.',
			errSize: 'This file is larger than 30 MB. Please choose a smaller one.',
			errPixels: 'This image has too many pixels for this device (limit %s megapixels). Please choose a smaller version.',
			errTiny: 'This image is too small to work with.',
			errRead: 'This image could not be read. It may be damaged. Please try another file.',
			errMemory: 'Your device ran out of memory. Try a smaller image or a smaller size.',
			errOut: 'The result would be too large for this device (limit %s megapixels). Choose a smaller size.',
			errGeneric: 'Something went wrong while processing. Please try again.',
			langName: 'English',
			original: 'Original'
		},
		ar: {
			title: 'مختبر الصور من برايم',
			steps: [ 'رفع', 'أداة', 'ضبط', 'معاينة', 'تنزيل', 'طباعة' ],
			dropTitle: 'أفلت صورتك هنا',
			dropHint: 'JPEG أو PNG أو WebP حتى 30 ميجابايت. صورتك تبقى على جهازك.',
			choose: 'اختر صورة',
			change: 'اختر صورة أخرى',
			before: 'قبل',
			after: 'بعد',
			compare: 'موضع المقارنة بين قبل وبعد',
			tabs: [ 'تحسين', 'الخلفية', 'فحص الطباعة' ],
			improve: 'تحسين الصورة',
			brightness: 'السطوع',
			contrast: 'التباين',
			sharpen: 'الحدة',
			denoise: 'تقليل التشويش',
			scale: 'الحجم',
			custom: 'حجم مخصص',
			width: 'العرض (بكسل)',
			height: 'الارتفاع (بكسل)',
			lock: 'الحفاظ على النسبة',
			noteResize: 'التكبير يزيد حجم الصورة لكنه لا يعيد التفاصيل المفقودة، وهو ليس تحسيناً بالذكاء الاصطناعي.',
			warnEnlarge: 'التكبير بهذا المقدار قد يظهر ناعماً أو مربعات عند الطباعة.',
			apply: 'تطبيق',
			reset: 'إعادة ضبط',
			startOver: 'البدء من جديد',
			bgTitle: 'إزالة الخلفية البسيطة',
			bgNote: 'تزيل هذه الأداة خلفية مسطحة بلون واحد تلامس حواف الصورة (جدار أبيض أو ورقة استوديو). وهي ليست ذكاءً اصطناعياً، فلن تعمل مع الخلفيات المزدحمة أو المشابهة للون الشيء. النتيجة PNG شفاف.',
			tolerance: 'درجة التسامح',
			feather: 'نعومة الحواف',
			removeBg: 'إزالة الخلفية',
			bgNone: 'لم نجد خلفية بسيطة. جرّب رفع درجة التسامح أو استخدم صورة بخلفية مسطحة.',
			bgAll: 'تمت إزالة الصورة تقريباً كلها. خفّض درجة التسامح.',
			bgDone: 'تمت إزالة الخلفية: %s% من الصورة أصبحت شفافة.',
			printTitle: 'فحص جاهزية الطباعة',
			printW: 'عرض الطباعة',
			printH: 'ارتفاع الطباعة',
			unit: 'الوحدة',
			ppiTitle: 'الدقة التقديرية',
			ppiWord: 'PPI',
			levels: {
				excellent: 'ممتازة للطباعة',
				good: 'جيدة لمعظم الطباعة',
				fair: 'مقبولة: مناسبة للأحجام الكبيرة التي تُشاهد من بعيد، وتظهر ناعمة عن قرب',
				low: 'منخفضة: غالباً ستظهر مموّهة أو مربعات',
				none: 'أدخل مقاس الطباعة'
			},
			ratioWarn: 'شكل الطباعة يختلف عن شكل الصورة، لذلك سيتم قصها أو ستظهر هوامش.',
			lowWarn: 'عدد بكسلات الصورة قليل لهذا المقاس.',
			resizedWarn: 'البكسلات المضافة بالتكبير لا تضيف تفاصيل حقيقية. الصورة الأصلية كانت بدقة %s PPI تقريباً بهذا المقاس.',
			printNote: 'هذا تقدير من عدد البكسلات وليس ضماناً لجودة الطباعة. ألوان الشاشة (RGB) قد تختلف بعد الطباعة، ولا يحوّل برايم الصورة إلى CMYK هنا.',
			facts: { px: 'البكسلات', type: 'الصيغة', size: 'حجم الملف', ratio: 'نسبة الأبعاد' },
			downloadTitle: 'التنزيل',
			format: 'الصيغة',
			quality: 'الجودة',
			download: 'تنزيل الصورة',
			noResult: 'طبّق أداة أولاً للحصول على نتيجة للتنزيل.',
			pngForced: 'النتائج الشفافة تُحفظ بصيغة PNG.',
			whatsapp: 'المتابعة على واتساب',
			shop: 'تصفح منتجات الطباعة',
			waNote: 'أرفق الصورة المنزّلة بنفسك في واتساب، فهي لا تُرسل تلقائياً.',
			waMessage: 'مرحباً برايم، جهّزت صورة عبر مختبر الصور من برايم وأرغب في طباعتها.',
			privacy: 'صورتك لا تغادر جهازك. كل المعالجة تتم داخل المتصفح، ولا يتم رفع أي شيء أو تخزينه أو تتبعه.',
			statusReady: 'جاهزة',
			statusLoading: 'جارٍ قراءة الصورة…',
			statusWorking: 'جارٍ المعالجة…',
			statusDone: 'تم: %s',
			errType: 'نوع الملف غير مدعوم. اختر صورة بصيغة JPEG أو PNG أو WebP.',
			errSize: 'حجم الملف أكبر من 30 ميجابايت. اختر ملفاً أصغر.',
			errPixels: 'هذه الصورة تحتوي بكسلات أكثر مما يتحمله هذا الجهاز (الحد %s ميجابكسل). اختر نسخة أصغر.',
			errTiny: 'الصورة صغيرة جداً.',
			errRead: 'تعذّرت قراءة الصورة. قد تكون تالفة. جرّب ملفاً آخر.',
			errMemory: 'نفدت ذاكرة جهازك. جرّب صورة أصغر أو مقاساً أصغر.',
			errOut: 'ستكون النتيجة أكبر مما يتحمله هذا الجهاز (الحد %s ميجابكسل). اختر مقاساً أصغر.',
			errGeneric: 'حدث خطأ أثناء المعالجة. حاول مرة أخرى.',
			langName: 'عربي',
			original: 'الأصلية'
		}
	};

	/* ---- State ----------------------------------------------------------- */

	var storedLang = null;

	try {
		storedLang = window.localStorage.getItem( 'pilLang' );
	} catch ( e ) {
		storedLang = null;
	}

	var S = {
		lang: storedLang === 'ar' || storedLang === 'en' ? storedLang : ( cfg.lang === 'ar' ? 'ar' : 'en' ),
		tool: 0,
		file: null, // the File the visitor chose; never modified
		fileInfo: null,
		orig: null, // full-resolution canvas of the original
		result: null, // full-resolution canvas of the latest result
		resultHasAlpha: false,
		resultKind: '',
		origUrl: '',
		stage: 1,
		downloaded: false,
		status: { text: '', cls: '' },
		set: { brightness: 0, contrast: 0, sharpen: 0, denoise: 0, scale: '1', tolerance: 30, feather: 1, w: 0, h: 0, lock: true, quality: 90, format: 'jpeg' },
		print: { w: 0, h: 0, unit: 'cm', lock: true }
	};

	var worker = null;
	var workerFailed = false;
	var jobId = 0;
	var pending = {};
	var els = {};

	var t = function () {
		return T[ S.lang ];
	};

	var fmt = function ( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return str.replace( /%s/g, function () {
			return args.length ? args.shift() : '';
		} );
	};

	var esc = function ( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	};

	// Keep sizes like "800 × 600 px" in reading order inside right-to-left text.
	var ltr = function ( s ) {
		return '\u2066' + s + '\u2069';
	};

	var human = function ( bytes ) {
		return bytes >= MB ? ( bytes / MB ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
	};

	var gcd = function ( a, b ) {
		return b ? gcd( b, a % b ) : a;
	};

	var ratioText = function ( w, h ) {
		var g = gcd( w, h );
		var a = w / g;
		var b = h / g;

		if ( a > 40 || b > 40 ) {
			return ( w / h ).toFixed( 2 ) + ':1';
		}

		return a + ':' + b;
	};

	/* ---- Canvas helpers -------------------------------------------------- */

	var freeCanvas = function ( c ) {
		if ( c ) {
			c.width = 0;
			c.height = 0;
		}
	};

	var makeCanvas = function ( w, h ) {
		var c = document.createElement( 'canvas' );

		c.width = w;
		c.height = h;

		if ( c.width !== w || c.height !== h ) {
			throw new Error( 'memory' );
		}

		return c;
	};

	/** Resample with the browser's high-quality filter, in steps so large changes stay smooth. */
	var resample = function ( src, w, h ) {
		var cur = src;
		var own = false;

		while ( Math.abs( Math.log( w / cur.width ) ) > Math.log( 2 ) + 0.001 || Math.abs( Math.log( h / cur.height ) ) > Math.log( 2 ) + 0.001 ) {
			var nw = w > cur.width ? Math.min( w, cur.width * 2 ) : Math.max( w, Math.ceil( cur.width / 2 ) );
			var nh = h > cur.height ? Math.min( h, cur.height * 2 ) : Math.max( h, Math.ceil( cur.height / 2 ) );
			var step = makeCanvas( Math.round( nw ), Math.round( nh ) );
			var sctx = step.getContext( '2d' );

			sctx.imageSmoothingEnabled = true;
			sctx.imageSmoothingQuality = 'high';
			sctx.drawImage( cur, 0, 0, step.width, step.height );

			if ( own ) {
				freeCanvas( cur );
			}

			cur = step;
			own = true;
		}

		var out = makeCanvas( w, h );
		var ctx = out.getContext( '2d' );

		ctx.imageSmoothingEnabled = true;
		ctx.imageSmoothingQuality = 'high';
		ctx.drawImage( cur, 0, 0, w, h );

		if ( own ) {
			freeCanvas( cur );
		}

		return out;
	};

	/* ---- Worker ---------------------------------------------------------- */

	var getWorker = function () {
		if ( worker || workerFailed || ! window.Worker || ! cfg.workerUrl ) {
			return worker;
		}

		try {
			worker = new window.Worker( cfg.workerUrl );
			worker.onmessage = function ( event ) {
				var m = event.data;
				var job = pending[ m.id ];

				if ( ! job ) {
					return;
				}

				delete pending[ m.id ];

				if ( m.ok ) {
					job.resolve( { buffer: m.buffer, info: m.info } );
				} else {
					job.reject( new Error( m.message ) );
				}
			};
			worker.onerror = function () {
				workerFailed = true;
				worker = null;

				Object.keys( pending ).forEach( function ( id ) {
					pending[ id ].fallback();
					delete pending[ id ];
				} );
			};
		} catch ( e ) {
			workerFailed = true;
			worker = null;
		}

		return worker;
	};

	/** Run an operation on a canvas's pixels; resolves with { canvas, info }. */
	var runOp = function ( op, canvas, settings ) {
		return new Promise( function ( resolve, reject ) {
			var w = canvas.width;
			var h = canvas.height;
			var ctx = canvas.getContext( '2d' );
			var data;

			try {
				data = ctx.getImageData( 0, 0, w, h );
			} catch ( e ) {
				reject( new Error( 'memory' ) );
				return;
			}

			var finish = function ( buffer, info ) {
				var out = makeCanvas( w, h );
				var img = new ImageData( new Uint8ClampedArray( buffer ), w, h );

				out.getContext( '2d' ).putImageData( img, 0, 0 );
				resolve( { canvas: out, info: info || {} } );
			};

			var inline = function () {
				var info = {};
				var img = { data: data.data, width: w, height: h };

				if ( 'improve' === op ) {
					Core.improve( img, settings );
				} else {
					var r = Core.removePlainBackground( img, settings.tolerance, settings.feather );

					info = { removed: r.removed };
				}

				finish( data.data.buffer, info );
			};

			var wk = getWorker();

			if ( ! wk ) {
				window.setTimeout( inline, 20 );
				return;
			}

			var id = ++jobId;
			var buf = data.data.buffer.slice( 0 );

			pending[ id ] = {
				resolve: function ( r ) {
					try {
						finish( r.buffer, r.info );
					} catch ( e ) {
						reject( e );
					}
				},
				reject: reject,
				fallback: inline
			};

			wk.postMessage( { id: id, op: 'improve' === op ? 'improve' : 'bg', width: w, height: h, buffer: buf, settings: settings }, [ buf ] );
		} );
	};

	/* ---- UI helpers ------------------------------------------------------ */

	var setStatus = function ( text, cls ) {
		S.status = { text: text, cls: cls || '' };

		if ( els.status ) {
			els.status.textContent = text;
			els.status.className = 'pil__status' + ( cls ? ' ' + cls : '' );
		}
	};

	var fail = function ( err ) {
		var msg = err && err.message === 'memory' ? t().errMemory : ( err && err.pilMessage ) || t().errGeneric;

		setStatus( msg, 'is-error' );
	};

	var hasAlpha = function ( canvas ) {
		try {
			var d = canvas.getContext( '2d' ).getImageData( 0, 0, canvas.width, canvas.height ).data;

			for ( var i = 3; i < d.length; i += 4 * 17 ) {
				if ( d[ i ] < 250 ) {
					return true;
				}
			}
		} catch ( e ) {
			return false;
		}

		return false;
	};

	var currentCanvas = function () {
		return S.result || S.orig;
	};

	/* ---- Rendering ------------------------------------------------------- */

	var slider = function ( id, label, min, max, value, step ) {
		return '<div class="pil__field"><label for="' + id + '">' + esc( label ) + '<output id="' + id + '-o">' + value + '</output></label>' +
			'<input id="' + id + '" type="range" min="' + min + '" max="' + max + '" step="' + ( step || 1 ) + '" value="' + value + '"></div>';
	};

	var stepsHtml = function () {
		var cur = S.stage;

		return t().steps.map( function ( label, i ) {
			var n = i + 1;
			var cls = 'pil__step' + ( n < cur ? ' is-done' : '' ) + ( n === cur ? ' is-current' : '' );

			return '<li class="' + cls + '"' + ( n === cur ? ' aria-current="step"' : '' ) + '><span>' + esc( label ) + '</span></li>';
		} ).join( '' );
	};

	var updateSteps = function () {
		var stage = 1;

		if ( S.orig ) {
			stage = 2;
		}
		if ( S.orig && S.touched ) {
			stage = 3;
		}
		if ( S.result ) {
			stage = 4;
		}
		if ( S.downloaded ) {
			stage = 6;
		}

		S.stage = stage;

		if ( els.steps ) {
			els.steps.innerHTML = stepsHtml();
		}
	};

	var scaleOptions = function () {
		var opts = [ [ '1', '1×' ], [ '1.5', '1.5×' ], [ '2', '2×' ], [ '3', '3×' ], [ '4', '4×' ], [ 'custom', t().custom ] ];

		return opts.map( function ( o ) {
			return '<option value="' + o[ 0 ] + '"' + ( S.set.scale === o[ 0 ] ? ' selected' : '' ) + '>' + esc( o[ 1 ] ) + '</option>';
		} ).join( '' );
	};

	var build = function () {
		var L = t();
		var dir = 'ar' === S.lang ? 'rtl' : 'ltr';

		root.setAttribute( 'dir', dir );
		root.setAttribute( 'lang', S.lang );

		var stage = S.orig ?
			'<div class="pil__facts" id="pil-facts"></div>' +
			'<div class="pil__compare" id="pil-compare"><div class="pil__compare-after" id="pil-after"></div>' +
			'<div class="pil__compare-before" id="pil-before"></div><div class="pil__handle" id="pil-handle"></div>' +
			'<span class="pil__tag pil__tag--before">' + esc( L.before ) + '</span><span class="pil__tag pil__tag--after">' + esc( L.after ) + '</span>' +
			'<input class="pil__range" id="pil-pos" type="range" min="0" max="100" value="50" aria-label="' + esc( L.compare ) + '"></div>' +
			'<p class="pil__status" id="pil-status" role="status" aria-live="polite"></p>' +
			'<button type="button" class="pil__btn pil__btn--ghost" id="pil-change">' + esc( L.change ) + '</button>' :
			'<div class="pil__drop" id="pil-drop" tabindex="0" role="button" aria-label="' + esc( L.choose ) + '">' +
			'<span class="pil__drop-title">' + esc( L.dropTitle ) + '</span><span class="pil__drop-hint">' + esc( L.dropHint ) + '</span>' +
			'<button type="button" class="pil__btn" id="pil-choose">' + esc( L.choose ) + '</button></div>' +
			'<p class="pil__status" id="pil-status" role="status" aria-live="polite"></p>';

		var panels = '';

		if ( S.orig ) {
			var set = S.set;

			panels =
				'<div class="pil__card"><div class="pil__tabs" role="tablist">' +
				L.tabs.map( function ( label, i ) {
					return '<button type="button" class="pil__tab" role="tab" id="pil-tab-' + i + '" aria-controls="pil-panel-' + i + '" aria-selected="' + ( S.tool === i ) + '" data-tab="' + i + '">' + esc( label ) + '</button>';
				} ).join( '' ) + '</div>' +

				'<div class="pil__panel" role="tabpanel" id="pil-panel-0" aria-labelledby="pil-tab-0"' + ( S.tool === 0 ? '' : ' hidden' ) + '><h3>' + esc( L.improve ) + '</h3>' +
				slider( 'pil-brightness', L.brightness, -100, 100, set.brightness ) +
				slider( 'pil-contrast', L.contrast, -100, 100, set.contrast ) +
				slider( 'pil-sharpen', L.sharpen, 0, 200, set.sharpen ) +
				slider( 'pil-denoise', L.denoise, 0, 100, set.denoise ) +
				'<div class="pil__field"><label for="pil-scale">' + esc( L.scale ) + '<output id="pil-scale-o"></output></label><select id="pil-scale">' + scaleOptions() + '</select></div>' +
				'<div id="pil-custom" class="pil__duo"' + ( 'custom' === set.scale ? '' : ' hidden' ) + '>' +
				'<div class="pil__field"><label for="pil-w">' + esc( L.width ) + '</label><input id="pil-w" type="number" min="' + LIMITS.minSide + '" step="1" inputmode="numeric" value="' + ( set.w || S.orig.width ) + '"></div>' +
				'<div class="pil__field"><label for="pil-h">' + esc( L.height ) + '</label><input id="pil-h" type="number" min="' + LIMITS.minSide + '" step="1" inputmode="numeric" value="' + ( set.h || S.orig.height ) + '"></div>' +
				'<label class="pil__check"><input id="pil-lock" type="checkbox"' + ( set.lock ? ' checked' : '' ) + '> ' + esc( L.lock ) + '</label></div>' +
				'<p class="pil__note pil__note--warn" id="pil-warn" hidden>' + esc( L.warnEnlarge ) + '</p>' +
				'<p class="pil__note">' + esc( L.noteResize ) + '</p>' +
				'<div class="pil__actions"><button type="button" class="pil__btn" id="pil-apply">' + esc( L.apply ) + '</button><button type="button" class="pil__btn pil__btn--ghost" id="pil-reset">' + esc( L.reset ) + '</button></div></div>' +

				'<div class="pil__panel" role="tabpanel" id="pil-panel-1" aria-labelledby="pil-tab-1"' + ( S.tool === 1 ? '' : ' hidden' ) + '><h3>' + esc( L.bgTitle ) + '</h3>' +
				'<p class="pil__note">' + esc( L.bgNote ) + '</p>' +
				slider( 'pil-tol', L.tolerance, 1, 100, set.tolerance ) +
				slider( 'pil-feather', L.feather, 0, 3, set.feather ) +
				'<div class="pil__actions"><button type="button" class="pil__btn" id="pil-bg">' + esc( L.removeBg ) + '</button><button type="button" class="pil__btn pil__btn--ghost" id="pil-reset2">' + esc( L.reset ) + '</button></div></div>' +

				'<div class="pil__panel" role="tabpanel" id="pil-panel-2" aria-labelledby="pil-tab-2"' + ( S.tool === 2 ? '' : ' hidden' ) + '><h3>' + esc( L.printTitle ) + '</h3>' +
				'<div class="pil__duo"><div class="pil__field"><label for="pil-pw">' + esc( L.printW ) + '</label><input id="pil-pw" type="number" min="0" step="any" inputmode="decimal" value="' + ( S.print.w || '' ) + '"></div>' +
				'<div class="pil__field"><label for="pil-ph">' + esc( L.printH ) + '</label><input id="pil-ph" type="number" min="0" step="any" inputmode="decimal" value="' + ( S.print.h || '' ) + '"></div></div>' +
				'<div class="pil__field"><label for="pil-unit">' + esc( L.unit ) + '</label><select id="pil-unit"><option value="cm"' + ( 'cm' === S.print.unit ? ' selected' : '' ) + '>cm</option><option value="mm"' + ( 'mm' === S.print.unit ? ' selected' : '' ) + '>mm</option></select></div>' +
				'<label class="pil__check"><input id="pil-plock" type="checkbox"' + ( S.print.lock ? ' checked' : '' ) + '> ' + esc( L.lock ) + '</label>' +
				'<div id="pil-print-out" aria-live="polite"></div></div></div>';
		}

		var exportCard = S.orig ?
			'<div class="pil__card pil__export"><h3>' + esc( L.downloadTitle ) + '</h3>' +
			'<div class="pil__export-row"><div class="pil__field"><label for="pil-format">' + esc( L.format ) + '</label><select id="pil-format"><option value="jpeg">JPEG</option><option value="png">PNG</option></select></div>' +
			'<div class="pil__field" id="pil-qwrap"><label for="pil-quality">' + esc( L.quality ) + '<output id="pil-quality-o">' + S.set.quality + '%</output></label><input id="pil-quality" type="range" min="50" max="100" value="' + S.set.quality + '"></div></div>' +
			'<p class="pil__note" id="pil-export-note"></p>' +
			'<div class="pil__handoff"><button type="button" class="pil__btn" id="pil-download">' + esc( L.download ) + '</button>' +
			( cfg.whatsapp ? '<a class="pil__btn pil__btn--wa" id="pil-wa" target="_blank" rel="noopener" href="#">' + esc( L.whatsapp ) + '</a>' : '' ) +
			'<a class="pil__btn pil__btn--ghost" id="pil-shop" href="' + esc( cfg.shop || '/' ) + '">' + esc( L.shop ) + '</a>' +
			'<button type="button" class="pil__btn pil__btn--ghost" id="pil-start">' + esc( L.startOver ) + '</button></div>' +
			( cfg.whatsapp ? '<p class="pil__note">' + esc( L.waNote ) + '</p>' : '' ) + '</div>' : '';

		root.innerHTML =
			'<div class="pil__top"><ol class="pil__steps" id="pil-steps">' + stepsHtml() + '</ol>' +
			'<div class="pil__lang" role="group" aria-label="Language"><button type="button" data-lang="en" aria-pressed="' + ( 'en' === S.lang ) + '">EN</button><button type="button" data-lang="ar" aria-pressed="' + ( 'ar' === S.lang ) + '">عربي</button></div></div>' +
			'<div class="pil__grid"><div class="pil__card pil__stage">' + stage + '</div>' + panels + '</div>' + exportCard +
			'<p class="pil__privacy">' + esc( L.privacy ) + '</p>' +
			'<input type="file" id="pil-file" class="pil__sr" accept="image/jpeg,image/png,image/webp" tabindex="-1" aria-hidden="true">';

		[ 'status', 'steps' ].forEach( function ( k ) {
			els[ k ] = root.querySelector( '#pil-' + k );
		} );

		bind();
		mountCanvases();
		refreshAll();
		setStatus( S.status.text || ( S.orig ? L.statusReady : '' ), S.status.cls );
	};

	var mountCanvases = function () {
		var before = root.querySelector( '#pil-before' );
		var after = root.querySelector( '#pil-after' );

		if ( ! before || ! after || ! S.orig ) {
			return;
		}

		els.beforeCanvas = S.orig;
		before.appendChild( S.orig );

		// The "after" layer shows the result, or the original until one exists.
		var shown = S.result || S.orig;
		var clone = S.result ? S.result : makeView( S.orig );

		after.appendChild( clone );
		els.afterCanvas = shown;
		els.afterView = clone;
		sizeCompare();
	};

	/** A separate lightweight view of the original for the "after" side before any result. */
	var makeView = function ( canvas ) {
		var v = document.createElement( 'canvas' );
		var max = 1400;
		var k = Math.min( 1, max / Math.max( canvas.width, canvas.height ) );

		v.width = Math.max( 1, Math.round( canvas.width * k ) );
		v.height = Math.max( 1, Math.round( canvas.height * k ) );
		v.getContext( '2d' ).drawImage( canvas, 0, 0, v.width, v.height );
		S.view = v;

		return v;
	};

	var sizeCompare = function () {
		var box = root.querySelector( '#pil-compare' );

		if ( ! box || ! S.orig ) {
			return;
		}

		var ratio = S.orig.width / S.orig.height;

		box.style.aspectRatio = S.orig.width + ' / ' + S.orig.height;
		box.style.setProperty( '--pil-w', box.clientWidth + 'px' );
		box.style.maxBlockSize = '70vh';
		box.style.marginInline = 'auto';

		if ( box.clientHeight > 0 && ratio < 1 ) {
			box.style.inlineSize = Math.min( box.parentElement.clientWidth, box.clientHeight * ratio ) + 'px';
		}

		setPos( parseInt( ( root.querySelector( '#pil-pos' ) || { value: 50 } ).value, 10 ) );
	};

	var setPos = function ( v ) {
		var before = root.querySelector( '#pil-before' );
		var handle = root.querySelector( '#pil-handle' );
		var box = root.querySelector( '#pil-compare' );

		if ( ! before || ! handle || ! box ) {
			return;
		}

		box.style.setProperty( '--pil-w', box.clientWidth + 'px' );
		before.style.inlineSize = v + '%';
		handle.style.insetInlineStart = v + '%';
	};

	/* ---- Facts, print check, export refresh ------------------------------ */

	var refreshFacts = function () {
		var box = root.querySelector( '#pil-facts' );

		if ( ! box || ! S.orig ) {
			return;
		}

		var L = t();
		var f = S.fileInfo;
		var c = currentCanvas();
		var chips = [
			f.name ? '' : '',
			f.type,
			human( f.size ),
			ltr( S.orig.width + ' × ' + S.orig.height + ' px' ),
			ltr( ratioText( S.orig.width, S.orig.height ) )
		].filter( Boolean );

		if ( S.result ) {
			chips.push( L.after + ': ' + ltr( c.width + ' × ' + c.height + ' px' ) );
		}

		box.innerHTML = chips.map( function ( x ) {
			return '<span class="pil__chip">' + esc( x ) + '</span>';
		} ).join( '' );
	};

	var targetSize = function () {
		var o = S.orig;
		var set = S.set;
		var w;
		var h;

		if ( 'custom' === set.scale ) {
			w = Math.max( 1, Math.round( set.w || o.width ) );
			h = Math.max( 1, Math.round( set.h || o.height ) );
		} else {
			var k = parseFloat( set.scale ) || 1;

			w = Math.round( o.width * k );
			h = Math.round( o.height * k );
		}

		return { w: w, h: h };
	};

	var refreshScale = function () {
		var out = root.querySelector( '#pil-scale-o' );
		var warn = root.querySelector( '#pil-warn' );

		if ( ! out || ! S.orig ) {
			return;
		}

		var s = targetSize();

		out.textContent = ltr( s.w + ' × ' + s.h + ' px' );

		if ( warn ) {
			warn.hidden = ! ( s.w / S.orig.width > 2.01 || s.h / S.orig.height > 2.01 );
		}
	};

	var printMm = function () {
		var k = 'cm' === S.print.unit ? 10 : 1;

		return { w: S.print.w * k, h: S.print.h * k };
	};

	var refreshPrint = function () {
		var out = root.querySelector( '#pil-print-out' );

		if ( ! out || ! S.orig ) {
			return;
		}

		var L = t();
		var c = currentCanvas();
		var mm = printMm();
		var p = Core.preflight( c.width, c.height, mm.w, mm.h );
		var html = '<table class="pil__table"><tbody>' +
			'<tr><th scope="row">' + esc( L.facts.px ) + '</th><td>' + esc( ltr( c.width + ' × ' + c.height ) ) + '</td></tr>' +
			'<tr><th scope="row">' + esc( L.facts.type ) + '</th><td>' + esc( S.fileInfo.type ) + '</td></tr>' +
			'<tr><th scope="row">' + esc( L.facts.size ) + '</th><td>' + esc( human( S.fileInfo.size ) ) + '</td></tr>' +
			'<tr><th scope="row">' + esc( L.facts.ratio ) + '</th><td>' + esc( ratioText( c.width, c.height ) ) + '</td></tr></tbody></table>';

		html += '<div class="pil__verdict pil__verdict--' + p.level + '"><span>' + esc( L.ppiTitle ) + '</span><strong>' +
			( p.ppi ? esc( ltr( Math.round( p.ppi ) + ' ' + L.ppiWord ) ) : '–' ) + '</strong><span>' + esc( L.levels[ p.level ] ) + '</span></div>';

		if ( p.ppi && p.ratioMismatch ) {
			html += '<p class="pil__note pil__note--warn">' + esc( L.ratioWarn ) + '</p>';
		}
		if ( p.ppi && p.level === 'low' ) {
			html += '<p class="pil__note pil__note--warn">' + esc( L.lowWarn ) + '</p>';
		}
		if ( p.ppi && S.result && c.width > S.orig.width * 1.05 ) {
			var o = Core.preflight( S.orig.width, S.orig.height, mm.w, mm.h );

			html += '<p class="pil__note pil__note--warn">' + esc( fmt( L.resizedWarn, Math.round( o.ppi ) ) ) + '</p>';
		}

		html += '<p class="pil__note">' + esc( L.printNote ) + '</p>';
		out.innerHTML = html;
	};

	var refreshExport = function () {
		var note = root.querySelector( '#pil-export-note' );
		var dl = root.querySelector( '#pil-download' );
		var fmtSel = root.querySelector( '#pil-format' );
		var qwrap = root.querySelector( '#pil-qwrap' );
		var wa = root.querySelector( '#pil-wa' );

		if ( ! dl ) {
			return;
		}

		var L = t();

		dl.disabled = ! S.result;
		note.textContent = ! S.result ? L.noResult : ( S.resultHasAlpha ? L.pngForced : '' );

		if ( S.resultHasAlpha ) {
			S.set.format = 'png';
		}

		fmtSel.value = S.set.format;
		fmtSel.disabled = S.resultHasAlpha;
		qwrap.hidden = 'png' === S.set.format;

		if ( wa ) {
			var base = cfg.whatsapp;

			wa.href = base + ( base.indexOf( '?' ) > -1 ? '&' : '?' ) + 'text=' + encodeURIComponent( L.waMessage );
		}
	};

	var refreshAll = function () {
		refreshFacts();
		refreshScale();
		refreshPrint();
		refreshExport();
		updateSteps();
	};

	/* ---- Loading a file -------------------------------------------------- */

	var releaseImages = function () {
		freeCanvas( S.orig );
		freeCanvas( S.result );
		freeCanvas( S.view );
		S.orig = null;
		S.result = null;
		S.view = null;
		S.resultHasAlpha = false;
		S.file = null;
		S.fileInfo = null;
		S.downloaded = false;
		S.touched = false;
	};

	var resetSettings = function () {
		S.set.brightness = 0;
		S.set.contrast = 0;
		S.set.sharpen = 0;
		S.set.denoise = 0;
		S.set.scale = '1';
		S.set.tolerance = 30;
		S.set.feather = 1;
		S.set.w = 0;
		S.set.h = 0;
		S.set.lock = true;
	};

	var loadFile = function ( file ) {
		var L = t();

		if ( ! file ) {
			return;
		}

		var okType = /^image\/(jpeg|png|webp)$/.test( file.type );

		if ( ! okType ) {
			setStatus( L.errType, 'is-error' );
			return;
		}
		if ( file.size > LIMITS.fileBytes ) {
			setStatus( L.errSize, 'is-error' );
			return;
		}

		setStatus( L.statusLoading, 'is-busy' );

		var done = function ( bmp ) {
			var w = bmp.width;
			var h = bmp.height;

			if ( w < LIMITS.minSide || h < LIMITS.minSide ) {
				bmp.close && bmp.close();
				setStatus( L.errTiny, 'is-error' );
				return;
			}
			if ( w * h > LIMITS.pixels ) {
				bmp.close && bmp.close();
				setStatus( fmt( L.errPixels, Math.round( LIMITS.pixels / 1e6 ) ), 'is-error' );
				return;
			}

			var canvas;

			try {
				canvas = makeCanvas( w, h );
				canvas.getContext( '2d' ).drawImage( bmp, 0, 0 );
			} catch ( e ) {
				bmp.close && bmp.close();
				fail( e );
				return;
			}

			bmp.close && bmp.close();
			releaseImages();
			resetSettings();
			S.orig = canvas;
			S.file = file;
			S.fileInfo = { name: '', type: file.type.replace( 'image/', '' ).toUpperCase().replace( 'JPEG', 'JPEG' ), size: file.size };
			S.tool = 0;
			setStatus( L.statusReady, '' );
			build();
		};

		var bad = function () {
			setStatus( L.errRead, 'is-error' );
		};

		if ( window.createImageBitmap ) {
			window.createImageBitmap( file, { imageOrientation: 'from-image' } ).then( done, function () {
				// Older browsers reject the options object; retry plainly.
				window.createImageBitmap( file ).then( done, bad );
			} );
		} else {
			var url = URL.createObjectURL( file );
			var img = new Image();

			img.onload = function () {
				URL.revokeObjectURL( url );
				done( img );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				bad();
			};
			img.src = url;
		}
	};

	/* ---- Actions --------------------------------------------------------- */

	var showResult = function ( canvas, kind ) {
		freeCanvas( S.result );
		freeCanvas( S.view );
		S.view = null;
		S.result = canvas;
		S.resultKind = kind;
		S.resultHasAlpha = 'bg' === kind && hasAlpha( canvas );
		S.downloaded = false;

		var after = root.querySelector( '#pil-after' );

		if ( after ) {
			after.innerHTML = '';
			after.appendChild( canvas );
		}

		sizeCompare();
		refreshAll();
	};

	var busy = function ( on ) {
		[ 'pil-apply', 'pil-bg', 'pil-reset', 'pil-reset2' ].forEach( function ( id ) {
			var b = root.querySelector( '#' + id );

			if ( b ) {
				b.disabled = on;
			}
		} );
	};

	var applyImprove = function () {
		var L = t();
		var s = targetSize();

		if ( s.w * s.h > LIMITS.pixels ) {
			setStatus( fmt( L.errOut, Math.round( LIMITS.pixels / 1e6 ) ), 'is-error' );
			return;
		}

		busy( true );
		setStatus( L.statusWorking, 'is-busy' );

		window.setTimeout( function () {
			var scaled = null;

			try {
				scaled = ( s.w === S.orig.width && s.h === S.orig.height ) ? copyCanvas( S.orig ) : resample( S.orig, s.w, s.h );
			} catch ( e ) {
				busy( false );
				fail( e );
				return;
			}

			runOp( 'improve', scaled, {
				brightness: S.set.brightness,
				contrast: S.set.contrast,
				sharpen: S.set.sharpen,
				denoise: S.set.denoise,
				sharpenRadius: scaled.width > 3000 ? 2 : 1
			} ).then( function ( r ) {
				freeCanvas( scaled );
				showResult( r.canvas, 'improve' );
				busy( false );
				setStatus( fmt( L.statusDone, ltr( r.canvas.width + ' × ' + r.canvas.height + ' px' ) ), '' );
			}, function ( e ) {
				freeCanvas( scaled );
				busy( false );
				fail( e );
			} );
		}, 30 );
	};

	var copyCanvas = function ( src ) {
		var c = makeCanvas( src.width, src.height );

		c.getContext( '2d' ).drawImage( src, 0, 0 );

		return c;
	};

	var applyBackground = function () {
		var L = t();

		busy( true );
		setStatus( L.statusWorking, 'is-busy' );

		window.setTimeout( function () {
			var copy;

			try {
				copy = copyCanvas( S.orig );
			} catch ( e ) {
				busy( false );
				fail( e );
				return;
			}

			runOp( 'bg', copy, { tolerance: S.set.tolerance, feather: S.set.feather } ).then( function ( r ) {
				freeCanvas( copy );
				busy( false );

				var pct = r.info.removed * 100;

				showResult( r.canvas, 'bg' );

				if ( pct < 2 ) {
					setStatus( L.bgNone, 'is-error' );
				} else if ( pct > 95 ) {
					setStatus( L.bgAll, 'is-error' );
				} else {
					setStatus( fmt( L.bgDone, Math.round( pct ) ), '' );
				}
			}, function ( e ) {
				freeCanvas( copy );
				busy( false );
				fail( e );
			} );
		}, 30 );
	};

	var download = function () {
		if ( ! S.result ) {
			return;
		}

		var L = t();
		var png = 'png' === S.set.format || S.resultHasAlpha;
		var src = S.result;
		var flat = null;

		if ( ! png ) {
			// JPEG has no transparency: put the image on white.
			flat = makeCanvas( src.width, src.height );

			var fctx = flat.getContext( '2d' );

			fctx.fillStyle = '#fff';
			fctx.fillRect( 0, 0, flat.width, flat.height );
			fctx.drawImage( src, 0, 0 );
			src = flat;
		}

		src.toBlob( function ( blob ) {
			freeCanvas( flat );

			if ( ! blob ) {
				setStatus( L.errMemory, 'is-error' );
				return;
			}

			var d = new Date();
			var pad = function ( n ) {
				return ( n < 10 ? '0' : '' ) + n;
			};
			var name = 'prime-image-lab-' + d.getFullYear() + pad( d.getMonth() + 1 ) + pad( d.getDate() ) + '-' + pad( d.getHours() ) + pad( d.getMinutes() ) + pad( d.getSeconds() ) + ( png ? '.png' : '.jpg' );
			var url = URL.createObjectURL( blob );
			var a = document.createElement( 'a' );

			a.href = url;
			a.download = name;
			a.rel = 'noopener';
			document.body.appendChild( a );
			a.click();
			a.remove();
			window.setTimeout( function () {
				URL.revokeObjectURL( url );
			}, 15000 );

			S.downloaded = true;
			updateSteps();
			setStatus( fmt( L.statusDone, name ), '' );
		}, png ? 'image/png' : 'image/jpeg', S.set.quality / 100 );
	};

	var startOver = function () {
		releaseImages();
		resetSettings();
		S.tool = 0;
		S.print = { w: 0, h: 0, unit: 'cm', lock: true };
		setStatus( '', '' );
		build();
	};

	var resetTool = function () {
		freeCanvas( S.result );
		S.result = null;
		S.resultHasAlpha = false;
		resetSettings();
		S.downloaded = false;
		setStatus( t().statusReady, '' );
		build();
	};

	/* ---- Events ---------------------------------------------------------- */

	var onInput = function ( id, fn ) {
		var el = root.querySelector( '#' + id );

		if ( el ) {
			el.addEventListener( 'input', function () {
				S.touched = true;
				fn( el );
				updateSteps();
			} );
		}
	};

	var bind = function () {
		var file = root.querySelector( '#pil-file' );
		var drop = root.querySelector( '#pil-drop' );
		var chooseBtn = root.querySelector( '#pil-choose' ) || root.querySelector( '#pil-change' );

		if ( chooseBtn ) {
			chooseBtn.addEventListener( 'click', function () {
				file.value = '';
				file.click();
			} );
		}

		file.addEventListener( 'change', function () {
			loadFile( file.files && file.files[ 0 ] );
		} );

		if ( drop ) {
			[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
				drop.addEventListener( ev, function ( e ) {
					e.preventDefault();
					drop.classList.add( 'is-over' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( ev ) {
				drop.addEventListener( ev, function ( e ) {
					e.preventDefault();
					drop.classList.remove( 'is-over' );
				} );
			} );
			drop.addEventListener( 'drop', function ( e ) {
				loadFile( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
			} );
			drop.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					file.click();
				}
			} );
			drop.addEventListener( 'click', function ( e ) {
				if ( e.target === drop || e.target.className === 'pil__drop-title' || e.target.className === 'pil__drop-hint' ) {
					file.click();
				}
			} );
		}

		Array.prototype.forEach.call( root.querySelectorAll( '[data-lang]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				S.lang = b.getAttribute( 'data-lang' );

				try {
					window.localStorage.setItem( 'pilLang', S.lang );
				} catch ( e ) {
					// Storage blocked: the choice lasts for this visit only.
				}

				S.status = { text: '', cls: '' };
				build();
			} );
		} );

		if ( ! S.orig ) {
			return;
		}

		var pos = root.querySelector( '#pil-pos' );

		pos.addEventListener( 'input', function () {
			setPos( parseInt( pos.value, 10 ) );
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-tab]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				S.tool = parseInt( b.getAttribute( 'data-tab' ), 10 );
				S.touched = true;

				[ 0, 1, 2 ].forEach( function ( i ) {
					root.querySelector( '#pil-panel-' + i ).hidden = i !== S.tool;
					root.querySelector( '#pil-tab-' + i ).setAttribute( 'aria-selected', String( i === S.tool ) );
				} );

				updateSteps();
			} );
		} );

		var bindSlider = function ( id, key ) {
			onInput( id, function ( el ) {
				S.set[ key ] = parseFloat( el.value );
				root.querySelector( '#' + id + '-o' ).textContent = el.value;
			} );
		};

		bindSlider( 'pil-brightness', 'brightness' );
		bindSlider( 'pil-contrast', 'contrast' );
		bindSlider( 'pil-sharpen', 'sharpen' );
		bindSlider( 'pil-denoise', 'denoise' );
		bindSlider( 'pil-tol', 'tolerance' );
		bindSlider( 'pil-feather', 'feather' );

		onInput( 'pil-scale', function ( el ) {
			S.set.scale = el.value;

			var custom = root.querySelector( '#pil-custom' );

			custom.hidden = 'custom' !== el.value;

			if ( 'custom' === el.value && ! S.set.w ) {
				S.set.w = S.orig.width;
				S.set.h = S.orig.height;
				root.querySelector( '#pil-w' ).value = S.set.w;
				root.querySelector( '#pil-h' ).value = S.set.h;
			}

			refreshScale();
		} );

		var ratio = function () {
			return S.orig.height / S.orig.width;
		};

		onInput( 'pil-w', function ( el ) {
			S.set.w = parseInt( el.value, 10 ) || 0;

			if ( S.set.lock ) {
				S.set.h = Math.max( 1, Math.round( S.set.w * ratio() ) );
				root.querySelector( '#pil-h' ).value = S.set.h;
			}

			refreshScale();
		} );

		onInput( 'pil-h', function ( el ) {
			S.set.h = parseInt( el.value, 10 ) || 0;

			if ( S.set.lock ) {
				S.set.w = Math.max( 1, Math.round( S.set.h / ratio() ) );
				root.querySelector( '#pil-w' ).value = S.set.w;
			}

			refreshScale();
		} );

		onInput( 'pil-lock', function ( el ) {
			S.set.lock = el.checked;
		} );

		root.querySelector( '#pil-apply' ).addEventListener( 'click', applyImprove );
		root.querySelector( '#pil-bg' ).addEventListener( 'click', applyBackground );
		root.querySelector( '#pil-reset' ).addEventListener( 'click', resetTool );
		root.querySelector( '#pil-reset2' ).addEventListener( 'click', resetTool );
		root.querySelector( '#pil-start' ).addEventListener( 'click', startOver );
		root.querySelector( '#pil-download' ).addEventListener( 'click', download );

		var shop = root.querySelector( '#pil-shop' );
		var wa = root.querySelector( '#pil-wa' );

		[ shop, wa ].forEach( function ( a ) {
			if ( a ) {
				a.addEventListener( 'click', function () {
					S.downloaded = true;
					updateSteps();
				} );
			}
		} );

		// Print check: width and height, optionally locked to the image's shape.
		var pr = function () {
			var c = currentCanvas();
			return c.height / c.width;
		};

		onInput( 'pil-pw', function ( el ) {
			S.print.w = parseFloat( el.value ) || 0;

			if ( S.print.lock && S.print.w ) {
				S.print.h = Math.round( S.print.w * pr() * 100 ) / 100;
				root.querySelector( '#pil-ph' ).value = S.print.h;
			}

			refreshPrint();
		} );

		onInput( 'pil-ph', function ( el ) {
			S.print.h = parseFloat( el.value ) || 0;

			if ( S.print.lock && S.print.h ) {
				S.print.w = Math.round( ( S.print.h / pr() ) * 100 ) / 100;
				root.querySelector( '#pil-pw' ).value = S.print.w;
			}

			refreshPrint();
		} );

		onInput( 'pil-unit', function ( el ) {
			S.print.unit = el.value;
			refreshPrint();
		} );

		onInput( 'pil-plock', function ( el ) {
			S.print.lock = el.checked;
		} );

		onInput( 'pil-format', function ( el ) {
			S.set.format = el.value;
			root.querySelector( '#pil-qwrap' ).hidden = 'png' === el.value;
		} );

		onInput( 'pil-quality', function ( el ) {
			S.set.quality = parseInt( el.value, 10 );
			root.querySelector( '#pil-quality-o' ).textContent = el.value + '%';
		} );
	};

	window.addEventListener( 'resize', function () {
		sizeCompare();
	} );

	window.addEventListener( 'pagehide', function () {
		releaseImages();

		if ( worker ) {
			worker.terminate();
		}
	} );

	// A hook for automated checks; it exposes state, never image data.
	window.PrimeImageLabDebug = { state: S, limits: LIMITS };

	build();
}() );
