/**
 * PRIME Image Lab: the page.
 *
 * Three one-tap jobs, no settings: make a photo bigger and clearer, remove a
 * plain background, or prepare a print-ready file for a paper size. All of it
 * runs in the visitor's browser. Nothing is uploaded: the chosen file is read
 * with createImageBitmap(), drawn on in-memory canvases, processed in a Web
 * Worker (or on the page if workers are blocked) and downloaded through a
 * short-lived blob URL. No analytics, storage of images or third-party code.
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

	// Sizes in mm. "large" formats are viewed from a distance, so they are judged and
	// exported at a lower resolution and fill the whole artboard.
	var SIZES = [
		{ key: 'A5', w: 148, h: 210 },
		{ key: 'A4', w: 210, h: 297 },
		{ key: 'A3', w: 297, h: 420 },
		{ key: 'A2', w: 420, h: 594 },
		{ key: 'RU', w: 850, h: 2000, large: true }
	];

	/* ---- Text ------------------------------------------------------------ */

	var T = {
		en: {
			steps: [ 'Add photo', 'Choose', 'Download' ],
			dropTitle: 'Drop your photo here',
			dropHint: 'JPEG, PNG or WebP. It stays on your device.',
			choose: 'Choose photo',
			another: 'Choose another photo',
			before: 'Before',
			after: 'After',
			compare: 'Before and after',
			ask: 'What would you like to do?',
			actions: {
				up: [ 'Make it bigger and clearer', 'A larger, sharper photo for printing.' ],
				bg: [ 'Remove the background', 'Cuts out the main subject. You get a see-through image.' ],
				print: [ 'Make a print-ready file', 'A5 to A2, or an 85 × 200 cm roll-up. Exact size, with a PDF for the printer.' ]
			},
			sizeAsk: 'Choose the paper size',
			sizeBack: 'Back',
			working: 'Working on it…',
			ready: 'Your photo is ready. Choose what to do.',
			nowSize: 'Now: %s',
			photo: 'Your photo: %s',
			upDone: 'Done. Your photo is now %s. Making a photo bigger cannot add real detail, but it makes it larger and a little clearer.',
			upAlready: 'Your photo is already large, so we only made it a little clearer.',
			bgDone: 'Done. The background is now transparent.',
			bgFail: 'We could not find a clear subject to cut out. Try a photo where one object or person stands out from the background.',
			bgPlain: 'Done. The AI model could not run on this device, so we removed a plain background instead.',
			aiTitle: 'Remove the background with AI',
			aiText: 'To cut out the subject, your browser downloads a small AI model (about 19 MB, one time only). It runs on your device and your photo is never uploaded. It works best when one object or person clearly stands out.',
			aiGo: 'Continue',
			aiCancel: 'Cancel',
			aiDownloading: 'Downloading the AI model… %s%',
			aiPreparing: 'Starting the AI model…',
			aiRunning: 'Removing the background…',
			printDone: 'Ready to print on %s at %s DPI.',
			ruName: 'Roll-up banner',
			ruDims: '85 × 200 cm',
			ruDone: 'Your %s file is ready, exactly 85 × 200 cm, at %s DPI.',
			ruGood: 'It should look sharp on a banner, seen from a short distance.',
			ruFair: 'Fine from a distance, a little soft up close.',
			ruLow: 'This photo is too small for a banner this size. Choose a bigger photo.',
			ruCrop: 'The banner is tall and narrow, so the photo was filled in and trimmed at the sides.',
			ruFit: 'The whole photo is kept, with blank space above and below.',
			ruShowWhole: 'Show the whole photo instead',
			ruFill: 'Fill the banner instead',
			ruDesignTitle: 'Design your roll-up banner',
			ruDesignText: 'We build the banner from your photo: your main subject, colours taken from the photo, and your words. The words are optional.',
			ruAiNote: 'The first time, your browser downloads a small AI model (about 19 MB) to cut out your subject. It runs on your device and your photo is never uploaded.',
			fHeadline: 'Headline',
			fSub: 'A short line',
			fContact: 'Website and phone',
			phHeadline: 'Your product or offer here',
			phSub: 'A short line about it',
			phContact: 'Your website | your phone',
			ruDesignGo: 'Design my banner',
			ruAsIs: 'Use my photo as it is',
			ruDesigned: 'Your banner is ready: exactly 85 × 200 cm at %s DPI.',
			ruExtendNote: 'We could not cut out your subject on this device, so your whole photo is placed on a matching background instead.',
			ruEditText: 'Change the text',
			ruBig: 'This is a very large file. It can take a minute.',
			ruCap: 'Your device cannot make a 100 DPI file this big, so this one is %s DPI.',
			downloadPdf: 'Download PDF for the printer',
			pdfNote: 'The PDF is exactly the paper size. Photos stay as pixels; send your original logo or text files if they must stay vector.',
			printCap: 'Your device cannot make a full 300 DPI file this big, so this one is %s DPI.',
			shape: 'Your photo is a different shape from %s, so there will be blank space on the paper. We kept the whole photo.',
			good: 'It should look sharp when printed.',
			fair: 'It will print fine, but a little soft when you look closely.',
			low: 'This photo is too small for that paper size. Choose a smaller size or a bigger photo.',
			download: 'Download',
			startOver: 'Start over',
			whatsapp: 'Send to PRIME on WhatsApp',
			waShare: 'Send on WhatsApp (full quality)',
			waPreparing: 'Preparing the full-quality file…',
			waShareNote: 'Choose WhatsApp from the list. The file goes as a document, so WhatsApp does not compress it.',
			waDeskNote: 'WhatsApp cannot take a file from a web page on this device. Open the chat, tap the paperclip, choose Document and pick the downloaded file. That keeps full quality.',
			waShareFail: 'Sharing did not work on this device. Use Download, then attach the file as a Document in WhatsApp.',
			shop: 'Browse printing products',
			waNote: 'Attach the downloaded image yourself in WhatsApp. It is not sent automatically.',
			waMessage: 'Hello PRIME, I prepared an image using PRIME Image Lab and would like to print it.',
			privacy: 'Your photo never leaves your device. Nothing is uploaded or saved.',
			errType: 'This file type is not supported. Please choose a JPEG, PNG or WebP photo.',
			errSize: 'This file is larger than 30 MB. Please choose a smaller one.',
			errPixels: 'This photo is too big for this device (limit %s megapixels). Please choose a smaller version.',
			errTiny: 'This photo is too small to work with.',
			errRead: 'This photo could not be read. It may be damaged. Please try another one.',
			errMemory: 'Your device ran out of memory. Try a smaller photo.',
			errGeneric: 'Something went wrong. Please try again.'
		},
		ar: {
			steps: [ 'أضف صورة', 'اختر', 'نزّل' ],
			dropTitle: 'أفلت صورتك هنا',
			dropHint: 'JPEG أو PNG أو WebP. تبقى على جهازك.',
			choose: 'اختر صورة',
			another: 'اختر صورة أخرى',
			before: 'قبل',
			after: 'بعد',
			compare: 'قبل وبعد',
			ask: 'ماذا تريد أن تفعل؟',
			actions: {
				up: [ 'تكبير وتوضيح الصورة', 'صورة أكبر وأوضح للطباعة.' ],
				bg: [ 'إزالة الخلفية', 'تقص العنصر الرئيسي. تحصل على صورة شفافة.' ],
				print: [ 'ملف جاهز للطباعة', 'من A5 إلى A2، أو رول أب 85 × 200 سم. بمقاس دقيق مع PDF للمطبعة.' ]
			},
			sizeAsk: 'اختر مقاس الورق',
			sizeBack: 'رجوع',
			working: 'جارٍ العمل…',
			ready: 'صورتك جاهزة. اختر ما تريد.',
			nowSize: 'الآن: %s',
			photo: 'صورتك: %s',
			upDone: 'تم. صورتك الآن %s. تكبير الصورة لا يضيف تفاصيل حقيقية، لكنه يجعلها أكبر وأوضح قليلاً.',
			upAlready: 'صورتك كبيرة أصلاً، فاكتفينا بتوضيحها قليلاً.',
			bgDone: 'تم. أصبحت الخلفية شفافة.',
			bgFail: 'لم نجد عنصراً واضحاً لقصه. جرّب صورة يظهر فيها شيء أو شخص واحد بوضوح عن الخلفية.',
			bgPlain: 'تم. لم يعمل نموذج الذكاء الاصطناعي على هذا الجهاز، فأزلنا الخلفية البسيطة بدلاً منه.',
			aiTitle: 'إزالة الخلفية بالذكاء الاصطناعي',
			aiText: 'لقص العنصر يحمّل المتصفح نموذج ذكاء اصطناعي صغيراً (حوالي 19 ميجابايت، مرة واحدة فقط). يعمل على جهازك ولا تُرفع صورتك أبداً. يعمل بأفضل شكل عندما يظهر شيء أو شخص واحد بوضوح.',
			aiGo: 'متابعة',
			aiCancel: 'إلغاء',
			aiDownloading: 'جارٍ تنزيل نموذج الذكاء الاصطناعي… %s%',
			aiPreparing: 'جارٍ تشغيل النموذج…',
			aiRunning: 'جارٍ إزالة الخلفية…',
			printDone: 'جاهزة للطباعة على %s بدقة %s DPI.',
			ruName: 'رول أب',
			ruDims: '85 × 200 سم',
			ruDone: 'ملف %s جاهز، بمقاس 85 × 200 سم بالضبط، بدقة %s DPI.',
			ruGood: 'يُتوقع أن يظهر حاداً على البانر عند المشاهدة من مسافة قريبة.',
			ruFair: 'مناسب من مسافة، ويظهر ناعماً قليلاً عن قرب.',
			ruLow: 'الصورة صغيرة لبانر بهذا المقاس. اختر صورة أكبر.',
			ruCrop: 'البانر طويل وضيق، لذلك ملأنا مساحته وقصصنا جوانب الصورة.',
			ruFit: 'حافظنا على الصورة كاملة مع مساحة فارغة أعلاها وأسفلها.',
			ruShowWhole: 'أظهر الصورة كاملة بدلاً من ذلك',
			ruFill: 'املأ البانر بدلاً من ذلك',
			ruDesignTitle: 'صمّم الرول أب',
			ruDesignText: 'نبني البانر من صورتك: العنصر الرئيسي، وألوان مأخوذة من الصورة، وكلماتك. الكلمات اختيارية.',
			ruAiNote: 'في المرة الأولى يحمّل المتصفح نموذج ذكاء اصطناعي صغيراً (حوالي 19 ميجابايت) لقص العنصر. يعمل على جهازك ولا تُرفع صورتك أبداً.',
			fHeadline: 'العنوان',
			fSub: 'سطر قصير',
			fContact: 'الموقع والهاتف',
			phHeadline: 'اسم منتجك أو عرضك هنا',
			phSub: 'وصف قصير',
			phContact: 'موقعك | رقم هاتفك',
			ruDesignGo: 'صمّم البانر',
			ruAsIs: 'استخدم صورتي كما هي',
			ruDesigned: 'بانرك جاهز: بمقاس 85 × 200 سم بالضبط بدقة %s DPI.',
			ruExtendNote: 'لم نستطع قص العنصر على هذا الجهاز، فوضعنا صورتك كاملة على خلفية متناسقة بدلاً من ذلك.',
			ruEditText: 'غيّر النص',
			ruBig: 'هذا ملف كبير جداً. قد يستغرق دقيقة.',
			ruCap: 'جهازك لا يستطيع إنشاء ملف بدقة 100 DPI بهذا الحجم، لذلك هذا الملف بدقة %s DPI.',
			downloadPdf: 'تنزيل PDF للمطبعة',
			pdfNote: 'ملف PDF بمقاس الورق بالضبط. تبقى الصور بكسلات، وأرسل ملفات الشعار أو النص الأصلية إذا كان يجب أن تبقى متجهية.',
			printCap: 'جهازك لا يستطيع إنشاء ملف بدقة 300 DPI بهذا الحجم، لذلك هذا الملف بدقة %s DPI.',
			shape: 'شكل صورتك يختلف عن %s، لذلك ستظهر مساحة فارغة على الورقة. حافظنا على الصورة كاملة.',
			good: 'يُتوقع أن تظهر حادة عند الطباعة.',
			fair: 'ستُطبع جيداً، لكنها تظهر ناعمة قليلاً عند النظر عن قرب.',
			low: 'الصورة صغيرة لهذا المقاس. اختر مقاساً أصغر أو صورة أكبر.',
			download: 'تنزيل',
			startOver: 'البدء من جديد',
			whatsapp: 'أرسل إلى برايم على واتساب',
			waShare: 'أرسل على واتساب (بجودة كاملة)',
			waPreparing: 'جارٍ تجهيز الملف بجودة كاملة…',
			waShareNote: 'اختر واتساب من القائمة. يُرسل الملف كمستند، لذلك لا يضغطه واتساب.',
			waDeskNote: 'لا يستطيع واتساب استلام ملف من صفحة ويب على هذا الجهاز. افتح المحادثة، اضغط على المشبك، اختر مستند، ثم اختر الملف الذي نزّلته. هكذا تبقى الجودة كاملة.',
			waShareFail: 'لم تنجح المشاركة على هذا الجهاز. استخدم التنزيل، ثم أرفق الملف كمستند في واتساب.',
			shop: 'تصفح منتجات الطباعة',
			waNote: 'أرفق الصورة المنزّلة بنفسك في واتساب، فهي لا تُرسل تلقائياً.',
			waMessage: 'مرحباً برايم، جهّزت صورة عبر مختبر الصور من برايم وأرغب في طباعتها.',
			privacy: 'صورتك لا تغادر جهازك. لا يتم رفع أي شيء ولا حفظه.',
			errType: 'نوع الملف غير مدعوم. اختر صورة بصيغة JPEG أو PNG أو WebP.',
			errSize: 'حجم الملف أكبر من 30 ميجابايت. اختر ملفاً أصغر.',
			errPixels: 'هذه الصورة أكبر مما يتحمله هذا الجهاز (الحد %s ميجابكسل). اختر نسخة أصغر.',
			errTiny: 'الصورة صغيرة جداً.',
			errRead: 'تعذّرت قراءة الصورة. قد تكون تالفة. جرّب صورة أخرى.',
			errMemory: 'نفدت ذاكرة جهازك. جرّب صورة أصغر.',
			errGeneric: 'حدث خطأ. حاول مرة أخرى.'
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
		orig: null, // full-resolution canvas of the photo as chosen
		work: null, // the current result; null until a job has run
		info: null, // { type, size } of the chosen file
		view: null,
		mode: 'choose', // choose | sizes
		kind: '', // up | bg | print
		dpi: 0,
		sizeKey: '',
		hasAlpha: false,
		busy: false,
		paper: null, // { w, h } in mm of the artboard the last print job was made for
		bannerText: { headline: '', sub: '', contact: '' },
		designed: false, // the result is a designed banner, not the photo as is
		fit: false, // roll-up: keep the whole photo instead of filling the banner
		printBase: null, // the image the print job started from, so it can be redone
		printBaseOwned: false,
		aiOk: false, // the visitor agreed to download the model
		shareFile: null, // the full-quality PDF handed to WhatsApp through the share sheet
		sharePrep: false,
		downloaded: false,
		msg: { text: '', cls: '' }
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
		return '⁦' + s + '⁩';
	};

	var dims = function ( c ) {
		return ltr( c.width + ' × ' + c.height + ' px' );
	};

	var human = function ( bytes ) {
		return bytes >= MB ? ( bytes / MB ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
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

	var copyCanvas = function ( src ) {
		var c = makeCanvas( src.width, src.height );

		c.getContext( '2d' ).drawImage( src, 0, 0 );

		return c;
	};

	/** Resample with the browser's high-quality filter, in steps so big changes stay smooth. */
	var resample = function ( src, w, h ) {
		var cur = src;
		var own = false;
		var limit = Math.log( 2 ) + 0.001;

		while ( Math.abs( Math.log( w / cur.width ) ) > limit || Math.abs( Math.log( h / cur.height ) ) > limit ) {
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
			var data;

			try {
				data = canvas.getContext( '2d' ).getImageData( 0, 0, w, h );
			} catch ( e ) {
				reject( new Error( 'memory' ) );
				return;
			}

			var finish = function ( buffer, info ) {
				var out = makeCanvas( w, h );

				out.getContext( '2d' ).putImageData( new ImageData( new Uint8ClampedArray( buffer ), w, h ), 0, 0 );
				resolve( { canvas: out, info: info || {} } );
			};

			var inline = function () {
				var info = {};
				var img = { data: data.data, width: w, height: h };

				if ( 'improve' === op ) {
					Core.improve( img, settings );
				} else {
					info = { removed: Core.removePlainBackground( img, settings.tolerance, settings.feather ).removed };
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

	/* ---- Rendering ------------------------------------------------------- */

	var setMsg = function ( text, cls ) {
		S.msg = { text: text, cls: cls || '' };

		if ( els.msg ) {
			els.msg.textContent = text;
			els.msg.className = 'pil__status' + ( cls ? ' ' + cls : '' );
		}
	};

	var stepNow = function () {
		if ( ! S.orig ) {
			return 1;
		}

		return S.work ? 3 : 2;
	};

	var stepsHtml = function () {
		var cur = stepNow();

		return t().steps.map( function ( label, i ) {
			var n = i + 1;

			return '<li class="pil__step' + ( n < cur ? ' is-done' : '' ) + ( n === cur ? ' is-current' : '' ) + '"' + ( n === cur ? ' aria-current="step"' : '' ) + '><span>' + esc( label ) + '</span></li>';
		} ).join( '' );
	};

	var actionButton = function ( key ) {
		var a = t().actions[ key ];

		return '<button type="button" class="pil__action" data-action="' + key + '"' + ( S.busy ? ' disabled' : '' ) + '>' +
			'<span class="pil__action-title">' + esc( a[ 0 ] ) + '</span><span class="pil__action-text">' + esc( a[ 1 ] ) + '</span></button>';
	};

	var build = function () {
		var L = t();

		root.setAttribute( 'dir', 'ar' === S.lang ? 'rtl' : 'ltr' );
		root.setAttribute( 'lang', S.lang );

		var top = '<div class="pil__top"><ol class="pil__steps" id="pil-steps">' + stepsHtml() + '</ol>' +
			'<div class="pil__lang" role="group" aria-label="Language"><button type="button" data-lang="en" aria-pressed="' + ( 'en' === S.lang ) + '">EN</button>' +
			'<button type="button" data-lang="ar" aria-pressed="' + ( 'ar' === S.lang ) + '">عربي</button></div></div>';

		var body;

		if ( ! S.orig ) {
			body = '<div class="pil__card pil__stage"><div class="pil__drop" id="pil-drop" tabindex="0" role="button" aria-label="' + esc( L.choose ) + '">' +
				'<span class="pil__drop-title">' + esc( L.dropTitle ) + '</span><span class="pil__drop-hint">' + esc( L.dropHint ) + '</span>' +
				'<button type="button" class="pil__btn" id="pil-choose">' + esc( L.choose ) + '</button></div>' +
				'<p class="pil__status" id="pil-msg" role="status" aria-live="polite"></p></div>';
		} else {
			var choices;

			if ( 'ai' === S.mode ) {
				choices = '<h3>' + esc( L.aiTitle ) + '</h3><p class="pil__note">' + esc( L.aiText ) + '</p><div class="pil__actions"><button type="button" class="pil__btn" id="pil-ai-go">' + esc( L.aiGo ) + '</button>' +
					'<button type="button" class="pil__btn pil__btn--ghost" id="pil-ai-cancel">' + esc( L.aiCancel ) + '</button></div>';
			} else if ( 'rudesign' === S.mode ) {
				var bt = S.bannerText;
				var field = function ( id, label, ph, value, max ) {
					return '<div class="pil__field"><label for="' + id + '">' + esc( label ) + '</label><input id="' + id + '" type="text" maxlength="' + max + '" placeholder="' + esc( ph ) + '" value="' + esc( value ) + '"></div>';
				};

				choices = '<h3>' + esc( L.ruDesignTitle ) + '</h3><p class="pil__note">' + esc( L.ruDesignText ) + '</p>' +
					field( 'pil-t-head', L.fHeadline, L.phHeadline, bt.headline, 60 ) + field( 'pil-t-sub', L.fSub, L.phSub, bt.sub, 90 ) + field( 'pil-t-contact', L.fContact, L.phContact, bt.contact, 60 ) +
					( aiAvailable() && ! S.aiOk && ! AI.session ? '<p class="pil__note">' + esc( L.ruAiNote ) + '</p>' : '' ) +
					'<div class="pil__actions"><button type="button" class="pil__btn" id="pil-ru-go">' + esc( L.ruDesignGo ) + '</button>' +
					'<button type="button" class="pil__btn pil__btn--ghost" id="pil-ru-asis">' + esc( L.ruAsIs ) + '</button></div>' +
					'<button type="button" class="pil__btn pil__btn--ghost" id="pil-back">' + esc( L.sizeBack ) + '</button>';
			} else if ( 'sizes' === S.mode ) {
				choices = '<h3>' + esc( L.sizeAsk ) + '</h3><div class="pil__sizes">' + SIZES.map( function ( z ) {
					var name = z.large ? L.ruName : z.key;
					var dim = z.large ? ltr( L.ruDims ) : ltr( z.w + ' × ' + z.h + ' mm' );

					return '<button type="button" class="pil__size' + ( z.large ? ' pil__size--wide' : '' ) + '" data-size="' + z.key + '"' + ( S.busy ? ' disabled' : '' ) + '><strong>' + esc( name ) + '</strong><span>' + dim + '</span></button>';
				} ).join( '' ) + '</div><button type="button" class="pil__btn pil__btn--ghost" id="pil-back">' + esc( L.sizeBack ) + '</button>';
			} else {
				choices = '<h3>' + esc( L.ask ) + '</h3><div class="pil__actions-grid">' + actionButton( 'up' ) + actionButton( 'bg' ) + actionButton( 'print' ) + '</div>';
			}

			body = '<div class="pil__grid pil__grid--simple"><div class="pil__card pil__stage">' +
				'<div class="pil__compare" id="pil-compare"><div class="pil__compare-after" id="pil-after"></div><div class="pil__compare-before" id="pil-before"></div><div class="pil__handle" id="pil-handle"></div>' +
				'<span class="pil__tag pil__tag--before">' + esc( L.before ) + '</span><span class="pil__tag pil__tag--after">' + esc( L.after ) + '</span>' +
				'<input class="pil__range" id="pil-pos" type="range" min="0" max="100" value="50" aria-label="' + esc( L.compare ) + '"></div>' +
				'<p class="pil__facts-line" id="pil-facts"></p><p class="pil__status" id="pil-msg" role="status" aria-live="polite"></p></div>' +
				'<div class="pil__card pil__panel">' + choices + '<div id="pil-verdict"></div></div></div>' +
				( S.work ?
					'<div class="pil__card pil__export"><div class="pil__handoff"><button type="button" class="pil__btn" id="pil-download">' + esc( L.download ) + '</button>' +
					( 'print' === S.kind ? '<button type="button" class="pil__btn" id="pil-download-pdf">' + esc( L.downloadPdf ) + '</button>' : '' ) +
					waElement() +
					'<a class="pil__btn pil__btn--ghost" id="pil-shop" href="' + esc( cfg.shop || '/' ) + '">' + esc( L.shop ) + '</a></div>' +
					( 'print' === S.kind ? '<p class="pil__note">' + esc( L.pdfNote ) + '</p>' : '' ) +
					( cfg.whatsapp ? '<p class="pil__note">' + esc( canShareFiles() ? L.waShareNote : L.waDeskNote ) + '</p>' : '' ) + '</div>' : '' ) +
				'<div class="pil__after-actions"><button type="button" class="pil__btn pil__btn--ghost" id="pil-start">' + esc( L.startOver ) + '</button>' +
				'<button type="button" class="pil__btn pil__btn--ghost" id="pil-change">' + esc( L.another ) + '</button></div>';
		}

		root.innerHTML = top + body + '<p class="pil__privacy">' + esc( L.privacy ) + '</p>' +
			'<input type="file" id="pil-file" class="pil__sr" accept="image/jpeg,image/png,image/webp" tabindex="-1" aria-hidden="true">';

		els.msg = root.querySelector( '#pil-msg' );
		els.steps = root.querySelector( '#pil-steps' );

		bind();
		mount();
		setMsg( S.msg.text, S.msg.cls );
		refresh();
	};

	var mount = function () {
		var before = root.querySelector( '#pil-before' );
		var after = root.querySelector( '#pil-after' );

		if ( ! before || ! after || ! S.orig ) {
			return;
		}

		before.appendChild( S.orig );

		if ( S.work ) {
			after.appendChild( S.work );
		} else {
			// Until a job has run the "after" side shows the photo itself.
			freeCanvas( S.view );

			var v = document.createElement( 'canvas' );
			var k = Math.min( 1, 1400 / Math.max( S.orig.width, S.orig.height ) );

			v.width = Math.max( 1, Math.round( S.orig.width * k ) );
			v.height = Math.max( 1, Math.round( S.orig.height * k ) );
			v.getContext( '2d' ).drawImage( S.orig, 0, 0, v.width, v.height );
			S.view = v;
			after.appendChild( v );
		}

		sizeCompare();
	};

	var sizeCompare = function () {
		var box = root.querySelector( '#pil-compare' );

		if ( ! box || ! S.orig ) {
			return;
		}

		box.style.aspectRatio = S.orig.width + ' / ' + S.orig.height;
		box.style.setProperty( '--pil-w', box.clientWidth + 'px' );
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

	var refresh = function () {
		var L = t();
		var facts = root.querySelector( '#pil-facts' );
		var wa = root.querySelector( '#pil-wa' );

		if ( els.steps ) {
			els.steps.innerHTML = stepsHtml();
		}

		if ( facts && S.orig ) {
			facts.textContent = fmt( L.photo, dims( S.orig ) + ' · ' + S.info.type + ' · ' + human( S.info.size ) ) + ( S.work ? '  |  ' + fmt( L.nowSize, dims( S.work ) ) : '' );
		}

		if ( wa ) {
			wa.href = cfg.whatsapp + ( cfg.whatsapp.indexOf( '?' ) > -1 ? '&' : '?' ) + 'text=' + encodeURIComponent( L.waMessage );
		}
	};

	/* ---- Loading a file -------------------------------------------------- */

	var release = function () {
		freeCanvas( S.orig );
		freeCanvas( S.work );
		freeCanvas( S.view );
		S.orig = null;
		S.work = null;
		S.view = null;
		S.info = null;
		S.kind = '';
		S.dpi = 0;
		S.hasAlpha = false;
		if ( S.printBaseOwned ) {
			freeCanvas( S.printBase );
		}

		S.printBase = null;
		S.printBaseOwned = false;
		S.paper = null;
		S.fit = false;
		S.designed = false;
		S.shareFile = null;
		S.sharePrep = false;
		S.downloaded = false;
		S.mode = 'choose';
	};

	var loadFile = function ( file ) {
		var L = t();

		if ( ! file ) {
			return;
		}

		if ( ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) {
			setMsg( L.errType, 'is-error' );
			return;
		}
		if ( file.size > LIMITS.fileBytes ) {
			setMsg( L.errSize, 'is-error' );
			return;
		}

		setMsg( L.working, 'is-busy' );

		var done = function ( bmp ) {
			var w = bmp.width;
			var h = bmp.height;

			if ( w < LIMITS.minSide || h < LIMITS.minSide ) {
				bmp.close && bmp.close();
				setMsg( L.errTiny, 'is-error' );
				return;
			}
			if ( w * h > LIMITS.pixels ) {
				bmp.close && bmp.close();
				setMsg( fmt( L.errPixels, Math.round( LIMITS.pixels / 1e6 ) ), 'is-error' );
				return;
			}

			var canvas;

			try {
				canvas = makeCanvas( w, h );
				canvas.getContext( '2d' ).drawImage( bmp, 0, 0 );
			} catch ( e ) {
				bmp.close && bmp.close();
				setMsg( e.message === 'memory' ? L.errMemory : L.errGeneric, 'is-error' );
				return;
			}

			bmp.close && bmp.close();
			release();
			S.orig = canvas;
			S.info = { type: file.type.replace( 'image/', '' ).toUpperCase(), size: file.size };
			setMsg( L.ready, '' );
			build();
		};

		var bad = function () {
			setMsg( L.errRead, 'is-error' );
		};

		if ( window.createImageBitmap ) {
			window.createImageBitmap( file, { imageOrientation: 'from-image' } ).then( done, function () {
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

	/* ---- The three jobs -------------------------------------------------- */

	var setBusy = function ( on ) {
		S.busy = on;

		Array.prototype.forEach.call( root.querySelectorAll( '[data-action],[data-size]' ), function ( b ) {
			b.disabled = on;
		} );
	};

	/** Replace the working image with a finished result. */
	var finish = function ( canvas, kind, dpi ) {
		freeCanvas( S.work );
		S.work = canvas;
		S.kind = kind;
		S.dpi = dpi || 0;
		S.hasAlpha = hasAlpha( canvas );
		S.shareFile = null;
		S.sharePrep = false;
		S.downloaded = false;
		S.mode = 'choose';
	};

	var current = function () {
		return S.work || S.orig;
	};

	var showVerdict = function ( level, text ) {
		var box = root.querySelector( '#pil-verdict' );

		if ( box ) {
			box.innerHTML = text ? '<p class="pil__verdict pil__verdict--' + level + '">' + esc( text ) + '</p>' : '';
		}

		if ( box && text && S.verdict && S.verdict.edit ) {
			box.innerHTML += '<button type="button" class="pil__btn pil__btn--ghost" id="pil-ru-edit">' + esc( t().ruEditText ) + '</button>';
			box.querySelector( '#pil-ru-edit' ).addEventListener( 'click', function () {
				S.mode = 'rudesign';
				build();
			} );
		}

		if ( box && text && S.verdict && S.verdict.toggle ) {
			box.innerHTML += '<button type="button" class="pil__btn pil__btn--ghost" id="pil-fit">' + esc( S.fit ? t().ruFill : t().ruShowWhole ) + '</button>';

			var fitBtn = box.querySelector( '#pil-fit' );

			fitBtn.addEventListener( 'click', function () {
				S.fit = ! S.fit;
				run( function () {
					return jobPrint( 'RU' );
				} );
			} );
		}
	};

	/** Pick how much to enlarge: more for small photos, none for already large ones. */
	var autoScale = function ( w, h ) {
		var side = Math.max( w, h );
		var s = side < 1000 ? 4 : side < 2000 ? 3 : side < 3500 ? 2 : side < 4500 ? 1.25 : 1;

		s = Math.min( s, Math.sqrt( LIMITS.pixels / ( w * h ) ) );

		return s < 1.05 ? 1 : s;
	};

	var jobUp = function () {
		var L = t();
		var src = current();
		var s = autoScale( src.width, src.height );
		var scaled;

		try {
			scaled = s > 1 ? resample( src, Math.round( src.width * s ), Math.round( src.height * s ) ) : copyCanvas( src );
		} catch ( e ) {
			return Promise.reject( e );
		}

		return runOp( 'improve', scaled, { sharpen: s > 1 ? 70 : 50, denoise: s > 1 ? 25 : 10, sharpenRadius: scaled.width > 3000 ? 2 : 1 } ).then( function ( r ) {
			freeCanvas( scaled );
			finish( r.canvas, 'up', 0 );
			setMsg( s > 1 ? fmt( L.upDone, dims( r.canvas ) ) : L.upAlready, '' );
			showVerdict( '', '' );
		}, function ( e ) {
			freeCanvas( scaled );
			throw e;
		} );
	};

	var plainBg = function () {
		var L = t();
		var tolerances = [ 30, 15, 45, 60, 75 ];
		var src = current();
		var i = 0;

		var attempt = function () {
			var copy = copyCanvas( src );

			return runOp( 'bg', copy, { tolerance: tolerances[ i ], feather: 1 } ).then( function ( r ) {
				freeCanvas( copy );

				var pct = r.info.removed * 100;

				if ( pct >= 3 && pct <= 92 ) {
					finish( r.canvas, 'bg', 0 );
					setMsg( L.bgDone, '' );
					showVerdict( '', '' );
					return;
				}

				freeCanvas( r.canvas );
				i++;

				if ( i < tolerances.length ) {
					return attempt();
				}

				setMsg( L.bgFail, 'is-error' );
			}, function ( e ) {
				freeCanvas( copy );
				throw e;
			} );
		};

		return attempt();
	};


	/* ---- AI background removal (optional, loaded only on request) ---------- */

	var AI = { session: null, loading: null };

	var aiAvailable = function () {
		return !! ( cfg.aiModel && cfg.aiOrt && cfg.aiWasm && window.WebAssembly );
	};

	var concatBytes = function ( parts, total ) {
		var out = new Uint8Array( total );
		var at = 0;

		parts.forEach( function ( p ) {
			out.set( p, at );
			at += p.length;
		} );

		return out;
	};

	/** Fetch a file with byte progress. */
	var fetchBytes = function ( url, onBytes ) {
		return window.fetch( url ).then( function ( r ) {
			if ( ! r.ok || ! r.body ) {
				throw new Error( 'fetch' );
			}

			var reader = r.body.getReader();
			var parts = [];
			var total = 0;

			var pump = function () {
				return reader.read().then( function ( res ) {
					if ( res.done ) {
						return concatBytes( parts, total );
					}

					parts.push( res.value );
					total += res.value.length;
					onBytes( res.value.length );

					return pump();
				} );
			};

			return pump();
		} );
	};

	var loadScript = function ( src ) {
		return new Promise( function ( resolve, reject ) {
			if ( window.ort ) {
				resolve();
				return;
			}

			var el = document.createElement( 'script' );

			el.src = src;
			el.onload = resolve;
			el.onerror = function () {
				reject( new Error( 'script' ) );
			};
			document.head.appendChild( el );
		} );
	};

	var ensureAi = function () {
		var L = t();

		if ( AI.session ) {
			return Promise.resolve( AI.session );
		}

		if ( AI.loading ) {
			return AI.loading;
		}

		var total = ( cfg.aiWasmBytes || 14239897 ) + ( cfg.aiModelBytes || 4574861 );
		var got = 0;
		var report = function ( n ) {
			got += n;
			setMsg( fmt( t().aiDownloading, String( Math.min( 99, Math.round( got / total * 100 ) ) ) ), 'is-busy' );
		};

		setMsg( fmt( L.aiDownloading, '0' ), 'is-busy' );

		AI.loading = loadScript( cfg.aiOrt ).then( function () {
			return Promise.all( [ fetchBytes( cfg.aiWasm, report ), fetchBytes( cfg.aiModel, report ) ] );
		} ).then( function ( files ) {
			setMsg( t().aiPreparing, 'is-busy' );

			var ort = window.ort;

			ort.env.wasm.wasmBinary = files[ 0 ];
			ort.env.wasm.wasmPaths = cfg.aiOrt.replace( /[^/]*$/, '' );
			ort.env.wasm.numThreads = 1;

			return ort.InferenceSession.create( files[ 1 ], { executionProviders: [ 'wasm' ] } );
		} ).then( function ( session ) {
			AI.session = session;
			AI.loading = null;

			return session;
		}, function ( e ) {
			AI.loading = null;
			throw e;
		} );

		return AI.loading;
	};

	/** Cut out the main subject with the U2-Net-P model; returns a Promise of the finished canvas. */
	var aiRemove = function ( session, src ) {
		var SIZE = 320;
		var small = makeCanvas( SIZE, SIZE );
		var sctx = small.getContext( '2d' );

		// Draw on white so any transparency already present reads as background.
		sctx.fillStyle = '#fff';
		sctx.fillRect( 0, 0, SIZE, SIZE );
		sctx.imageSmoothingQuality = 'high';
		sctx.drawImage( src, 0, 0, SIZE, SIZE );

		var px = sctx.getImageData( 0, 0, SIZE, SIZE ).data;

		freeCanvas( small );

		var max = 1;
		var i;

		for ( i = 0; i < px.length; i += 4 ) {
			max = Math.max( max, px[ i ], px[ i + 1 ], px[ i + 2 ] );
		}

		var mean = [ 0.485, 0.456, 0.406 ];
		var std = [ 0.229, 0.224, 0.225 ];
		var plane = SIZE * SIZE;
		var input = new Float32Array( 3 * plane );

		for ( var p = 0; p < plane; p++ ) {
			for ( var ch = 0; ch < 3; ch++ ) {
				input[ ch * plane + p ] = ( px[ p * 4 + ch ] / max - mean[ ch ] ) / std[ ch ];
			}
		}

		var feeds = {};

		feeds[ session.inputNames[ 0 ] ] = new window.ort.Tensor( 'float32', input, [ 1, 3, SIZE, SIZE ] );

		return session.run( feeds ).then( function ( out ) {
			var o = out[ session.outputNames[ 0 ] ].data;
			var mn = Infinity;
			var mx = -Infinity;

			for ( var k = 0; k < o.length; k++ ) {
				if ( o[ k ] < mn ) {
					mn = o[ k ];
				}
				if ( o[ k ] > mx ) {
					mx = o[ k ];
				}
			}

			var maskCanvas = makeCanvas( SIZE, SIZE );
			var mctx = maskCanvas.getContext( '2d' );
			var mi = mctx.createImageData( SIZE, SIZE );

			for ( var q = 0; q < plane; q++ ) {
				mi.data[ q * 4 ] = 255;
				mi.data[ q * 4 + 1 ] = 255;
				mi.data[ q * 4 + 2 ] = 255;
				mi.data[ q * 4 + 3 ] = Math.round( 255 * ( o[ q ] - mn ) / ( mx - mn || 1 ) );
			}

			mctx.putImageData( mi, 0, 0 );

			// Scale the mask to the photo's size with the browser's smooth filter.
			var big = makeCanvas( src.width, src.height );
			var bctx = big.getContext( '2d' );

			bctx.imageSmoothingQuality = 'high';
			bctx.drawImage( maskCanvas, 0, 0, src.width, src.height );
			freeCanvas( maskCanvas );

			var mask = bctx.getImageData( 0, 0, src.width, src.height ).data;

			freeCanvas( big );

			var res = copyCanvas( src );
			var rctx = res.getContext( '2d' );
			var rd = rctx.getImageData( 0, 0, res.width, res.height );
			var removed = 0;
			var n = res.width * res.height;

			for ( var m = 0; m < n; m++ ) {
				var v = mask[ m * 4 + 3 ] / 255;
				var tt = Math.min( 1, Math.max( 0, ( v - 0.3 ) / 0.4 ) );
				var a = tt * tt * ( 3 - 2 * tt );

				rd.data[ m * 4 + 3 ] = Math.round( rd.data[ m * 4 + 3 ] * a );

				if ( a < 0.5 ) {
					removed++;
				}
			}

			rctx.putImageData( rd, 0, 0 );

			return { canvas: res, removed: removed / n };
		} );
	};

	var jobBg = function () {
		var L = t();

		if ( ! aiAvailable() ) {
			return plainBg();
		}

		return ensureAi().then( function ( session ) {
			setMsg( L.aiRunning, 'is-busy' );

			// Let the message paint before the model starts working.
			return new Promise( function ( resolve ) {
				window.setTimeout( resolve, 30 );
			} ).then( function () {
				return aiRemove( session, current() );
			} );
		} ).then( function ( r ) {
			if ( r.removed < 0.02 || r.removed > 0.98 ) {
				freeCanvas( r.canvas );
				setMsg( L.bgFail, 'is-error' );
				return;
			}

			finish( r.canvas, 'bg', 0 );
			setMsg( L.bgDone, '' );
			showVerdict( '', '' );
		}, function () {
			// The model or the engine would not load on this device: use the plain-background method.
			return plainBg().then( function () {
				if ( S.work && 'bg' === S.kind ) {
					setMsg( L.bgPlain, '' );
				}
			} );
		} );
	};

	/** Trim a cut-out to the box around its visible pixels (with a small margin). */
	var trimToAlpha = function ( canvas ) {
		var k = Math.min( 1, 400 / Math.max( canvas.width, canvas.height ) );
		var sw = Math.max( 1, Math.round( canvas.width * k ) );
		var sh = Math.max( 1, Math.round( canvas.height * k ) );
		var small = makeCanvas( sw, sh );
		var sctx = small.getContext( '2d' );

		sctx.drawImage( canvas, 0, 0, sw, sh );

		var d = sctx.getImageData( 0, 0, sw, sh ).data;
		var x0 = sw;
		var y0 = sh;
		var x1 = -1;
		var y1 = -1;

		for ( var y = 0; y < sh; y++ ) {
			for ( var x = 0; x < sw; x++ ) {
				if ( d[ ( y * sw + x ) * 4 + 3 ] > 80 ) {
					x0 = Math.min( x0, x );
					x1 = Math.max( x1, x );
					y0 = Math.min( y0, y );
					y1 = Math.max( y1, y );
				}
			}
		}

		freeCanvas( small );

		if ( x1 < x0 || y1 < y0 ) {
			return canvas;
		}

		var m = Math.round( Math.max( sw, sh ) * 0.01 );
		var rx = Math.max( 0, Math.floor( ( x0 - m ) / k ) );
		var ry = Math.max( 0, Math.floor( ( y0 - m ) / k ) );
		var rw = Math.min( canvas.width - rx, Math.ceil( ( x1 - x0 + 2 * m + 1 ) / k ) );
		var rh = Math.min( canvas.height - ry, Math.ceil( ( y1 - y0 + 2 * m + 1 ) / k ) );
		var out = makeCanvas( rw, rh );

		out.getContext( '2d' ).drawImage( canvas, rx, ry, rw, rh, 0, 0, rw, rh );

		return out;
	};

	/** Design the roll-up: cut-out subject on a gradient from the photo's colours, plus the visitor's words. */
	var jobRuDesign = function () {
		var L = t();
		var size = SIZES.filter( function ( z ) {
			return z.large;
		} )[ 0 ];
		var src = 'print' === S.kind && S.printBase ? S.printBase : current();
		var area = ( size.w / 25.4 ) * ( size.h / 25.4 );
		var dpi = Math.max( 50, Math.min( 100, Math.floor( Math.sqrt( LIMITS.pixels / area ) ) ) );
		var W = Math.round( size.w / 25.4 * dpi );
		var H = Math.round( size.h / 25.4 * dpi );
		var family = 'Montserrat, "IBM Plex Sans Arabic", "GE Dinar Two", Arial, sans-serif';
		var subject = null;

		setMsg( L.ruBig, 'is-busy' );

		var fonts = Promise.all( [ '700 40px Montserrat', '400 40px Montserrat', '500 40px Montserrat', '700 40px "IBM Plex Sans Arabic"', '400 40px "IBM Plex Sans Arabic"' ].map( function ( f ) {
			return document.fonts && document.fonts.load ? document.fonts.load( f ).catch( function () {} ) : Promise.resolve();
		} ) );

		var cut = aiAvailable() ? ensureAi().then( function ( session ) {
			setMsg( L.aiRunning, 'is-busy' );

			return aiRemove( session, src );
		} ).then( function ( r ) {
			if ( r.removed < 0.02 || r.removed > 0.98 ) {
				freeCanvas( r.canvas );
				return null;
			}

			var trimmed = trimToAlpha( r.canvas );

			if ( trimmed !== r.canvas ) {
				freeCanvas( r.canvas );
			}

			return trimmed;
		} ).catch( function () {
			return null;
		} ) : Promise.resolve( null );

		return Promise.all( [ cut, fonts ] ).then( function ( res ) {
			subject = res[ 0 ];

			var style = subject ? 'hero' : 'extend';
			var canvas;

			setMsg( L.ruBig, 'is-busy' );

			try {
				canvas = window.PrimeLabBanner.compose( { src: src, mask: subject, style: style, width: W, height: H, text: S.bannerText, family: family } );
			} catch ( e ) {
				freeCanvas( subject );
				throw e;
			}

			var img = subject || src;
			var effPpi = Core.ppi( img.width, window.PrimeLabBanner.drawnWidth( style, img, W, H ) / dpi * 25.4 );

			freeCanvas( subject );

			if ( 'print' !== S.kind ) {
				if ( S.printBaseOwned ) {
					freeCanvas( S.printBase );
				}

				S.printBase = src;
				S.printBaseOwned = src === S.work;

				if ( S.printBaseOwned ) {
					S.work = null;
				}
			}

			finish( canvas, 'print', dpi );
			S.sizeKey = 'RU';
			S.paper = { w: size.w, h: size.h };
			S.designed = true;
			S.fit = false;
			S.mode = 'choose';

			var level = effPpi >= 100 ? 'ruGood' : effPpi >= 60 ? 'ruFair' : 'ruLow';

			setMsg( fmt( L.ruDesigned, String( dpi ) ), '' );
			S.verdict = {
				level: level === 'ruGood' ? 'good' : level === 'ruFair' ? 'fair' : 'low',
				text: L[ level ] + ( 'extend' === style ? ' ' + L.ruExtendNote : '' ) + ( dpi < 100 ? ' ' + fmt( L.ruCap, String( dpi ) ) : '' ),
				edit: true
			};
		} );
	};

	/** Crop a canvas to a target aspect ratio, centred. Returns a new canvas. */
	var cropToAspect = function ( src, aspect ) {
		var cw = src.width;
		var ch = src.height;

		if ( cw / ch > aspect ) {
			cw = Math.round( ch * aspect );
		} else {
			ch = Math.round( cw / aspect );
		}

		var out = makeCanvas( cw, ch );

		out.getContext( '2d' ).drawImage( src, Math.round( ( src.width - cw ) / 2 ), Math.round( ( src.height - ch ) / 2 ), cw, ch, 0, 0, cw, ch );

		return out;
	};

	var jobPrint = function ( key ) {
		var L = t();
		var size = SIZES.filter( function ( z ) {
			return z.key === key;
		} )[ 0 ];

		// Redo from the image the first print job used, never from a print result.
		var src = 'print' === S.kind && S.printBase ? S.printBase : current();

		S.designed = false;

		var landscape = src.width >= src.height;
		var pw = size.large ? size.w : ( landscape ? size.h : size.w );
		var ph = size.large ? size.h : ( landscape ? size.w : size.h );
		var work;
		var dpi;
		var origPpi;

		if ( size.large ) {
			setMsg( L.ruBig, 'is-busy' );
		}

		var level;
		var shape;
		var capped = false;
		var prepared;

		if ( size.large ) {
			// A banner is made to its exact size. Seen from a distance, 100 DPI is the usual target.
			var area = ( pw / 25.4 ) * ( ph / 25.4 );

			dpi = Math.max( 50, Math.min( 100, Math.floor( Math.sqrt( LIMITS.pixels / area ) ) ) );
			capped = dpi < 100;

			var tw = Math.round( pw / 25.4 * dpi );
			var th = Math.round( ph / 25.4 * dpi );
			var aspect = pw / ph;
			var source;

			try {
				if ( S.fit ) {
					source = null;
				} else {
					source = cropToAspect( src, aspect );
				}

				if ( S.fit ) {
					var k = Math.min( tw / src.width, th / src.height );
					var fw = Math.round( src.width * k );
					var fh = Math.round( src.height * k );
					var placed = ( fw === src.width && fh === src.height ) ? copyCanvas( src ) : resample( src, fw, fh );

					work = makeCanvas( tw, th );

					var wctx = work.getContext( '2d' );

					wctx.fillStyle = '#fff';
					wctx.fillRect( 0, 0, tw, th );
					wctx.drawImage( placed, Math.round( ( tw - fw ) / 2 ), Math.round( ( th - fh ) / 2 ) );
					freeCanvas( placed );
					origPpi = Core.ppi( src.width, fw / dpi * 25.4 );
				} else {
					work = ( source.width === tw && source.height === th ) ? copyCanvas( source ) : resample( source, tw, th );
					origPpi = Core.ppi( source.width, pw );
					freeCanvas( source );
				}
			} catch ( e ) {
				freeCanvas( source );
				return Promise.reject( e );
			}

			level = origPpi >= 100 ? 'ruGood' : origPpi >= 60 ? 'ruFair' : 'ruLow';
			shape = Math.abs( ( src.width / src.height ) - aspect ) / aspect > 0.03;
			prepared = work;
		} else {
			var k2 = Math.min( ( pw / 25.4 * 300 ) / src.width, ( ph / 25.4 * 300 ) / src.height );
			var nw = Math.round( src.width * k2 );
			var nh = Math.round( src.height * k2 );

			if ( nw * nh > LIMITS.pixels ) {
				var c = Math.sqrt( LIMITS.pixels / ( nw * nh ) );

				nw = Math.floor( nw * c );
				nh = Math.floor( nh * c );
				capped = true;
			}

			var fitWmm = ( src.width * k2 ) / 300 * 25.4;

			origPpi = Core.ppi( src.width, fitWmm );
			level = origPpi >= 250 ? 'good' : origPpi >= 150 ? 'fair' : 'low';
			dpi = Math.round( nw / ( fitWmm / 25.4 ) );
			shape = Math.abs( ( src.width / src.height ) - ( pw / ph ) ) / ( pw / ph ) > 0.03;

			try {
				prepared = ( nw === src.width && nh === src.height ) ? copyCanvas( src ) : resample( src, nw, nh );
			} catch ( e2 ) {
				return Promise.reject( e2 );
			}
		}

		return runOp( 'improve', prepared, { sharpen: size.large ? 40 : ( dpi > 0 && prepared.width > src.width ? 50 : 30 ), denoise: 0, sharpenRadius: 1 } ).then( function ( r ) {
			freeCanvas( prepared );

			// Keep the starting image, so the same job can be redone (roll-up fill or fit).
			if ( 'print' !== S.kind ) {
				if ( S.printBaseOwned ) {
					freeCanvas( S.printBase );
				}

				S.printBase = src;
				S.printBaseOwned = src === S.work;

				if ( S.printBaseOwned ) {
					S.work = null;
				}
			}

			finish( r.canvas, 'print', dpi );
			S.sizeKey = key;
			S.paper = { w: pw, h: ph };

			if ( size.large ) {
				setMsg( fmt( L.ruDone, L.ruName, String( dpi ) ), '' );
				S.verdict = {
					level: level === 'ruGood' ? 'good' : level === 'ruFair' ? 'fair' : 'low',
					text: L[ level ] + ' ' + ( S.fit ? L.ruFit : ( shape ? L.ruCrop : '' ) ) + ( capped ? ' ' + fmt( L.ruCap, String( dpi ) ) : '' ),
					toggle: true
				};
			} else {
				setMsg( fmt( L.printDone, key, String( dpi ) ), '' );
				S.verdict = { level: level, text: L[ level ] + ( shape ? ' ' + fmt( L.shape, key ) : '' ) + ( capped ? ' ' + fmt( L.printCap, String( dpi ) ) : '' ) };
			}
		}, function ( e ) {
			freeCanvas( prepared );
			throw e;
		} );
	};

	var run = function ( job ) {
		var L = t();

		setBusy( true );
		setMsg( L.working, 'is-busy' );
		S.verdict = null;

		window.setTimeout( function () {
			Promise.resolve().then( job ).then( function () {
				S.busy = false;

				var v = S.verdict;

				build();

				if ( v ) {
					showVerdict( v.level, v.text );
				}
			}, function ( e ) {
				S.busy = false;
				setBusy( false );
				setMsg( e && e.message === 'memory' ? L.errMemory : L.errGeneric, 'is-error' );
			} );
		}, 30 );
	};

	/* ---- WhatsApp: hand over the file itself ------------------------------ */

	var canShareFiles = function () {
		try {
			return !! ( navigator.share && navigator.canShare && navigator.canShare( { files: [ new File( [ 'x' ], 'a.pdf', { type: 'application/pdf' } ) ] } ) );
		} catch ( e ) {
			return false;
		}
	};

	var waElement = function () {
		var L = t();

		if ( ! cfg.whatsapp ) {
			return '';
		}

		if ( canShareFiles() ) {
			return '<button type="button" class="pil__btn pil__btn--wa" id="pil-wa-share"' + ( S.shareFile ? '' : ' disabled' ) + '>' + esc( S.shareFile ? L.waShare : L.waPreparing ) + '</button>';
		}

		return '<a class="pil__btn pil__btn--wa" id="pil-wa" target="_blank" rel="noopener" href="#">' + esc( L.whatsapp ) + '</a>';
	};

	var concat = function ( parts ) {
		var total = 0;

		parts.forEach( function ( p ) {
			total += p.length;
		} );

		var out = new Uint8Array( total );
		var at = 0;

		parts.forEach( function ( p ) {
			out.set( p, at );
			at += p.length;
		} );

		return out;
	};

	var deflate = function ( bytes ) {
		var stream = new Blob( [ bytes ] ).stream().pipeThrough( new window.CompressionStream( 'deflate' ) );

		return new Response( stream ).arrayBuffer().then( function ( b ) {
			return new Uint8Array( b );
		} );
	};

	/**
	 * Wrap the result in a one-page PDF at its print size. A PDF goes through
	 * WhatsApp as a document, which WhatsApp never recompresses, and the image
	 * inside is stored as is: a JPEG at maximum quality is embedded untouched,
	 * and transparent images are stored losslessly.
	 */
	var buildPdf = function () {
		var c = S.work;
		var enc = new TextEncoder();
		var dpi = S.dpi || 300;
		var dw = c.width / dpi * 72;
		var dh = c.height / dpi * 72;
		var pageW = dw;
		var pageH = dh;
		var offX = 0;
		var offY = 0;

		// A print job gets a page of exactly the paper size. A roll-up fills it;
		// a photo on a smaller sheet sits centred at its true print size.
		if ( S.paper && 'print' === S.kind ) {
			pageW = S.paper.w / 25.4 * 72;
			pageH = S.paper.h / 25.4 * 72;

			if ( 'RU' === S.sizeKey ) {
				dw = pageW;
				dh = pageH;
			} else {
				offX = ( pageW - dw ) / 2;
				offY = ( pageH - dh ) / 2;
			}
		}

		var wPt = pageW.toFixed( 2 );
		var hPt = pageH.toFixed( 2 );

		var imageParts;

		if ( S.hasAlpha ) {
			if ( ! window.CompressionStream ) {
				return Promise.resolve( null );
			}

			var px = c.getContext( '2d' ).getImageData( 0, 0, c.width, c.height ).data;
			var n = c.width * c.height;
			var rgb = new Uint8Array( n * 3 );
			var alpha = new Uint8Array( n );

			for ( var i = 0, j = 0, k = 0; i < n; i++, j += 3, k += 4 ) {
				rgb[ j ] = px[ k ];
				rgb[ j + 1 ] = px[ k + 1 ];
				rgb[ j + 2 ] = px[ k + 2 ];
				alpha[ i ] = px[ k + 3 ];
			}

			imageParts = Promise.all( [ deflate( rgb ), deflate( alpha ) ] ).then( function ( r ) {
				return { rgb: r[ 0 ], alpha: r[ 1 ], jpeg: null };
			} );
		} else {
			imageParts = new Promise( function ( resolve ) {
				var flat = makeCanvas( c.width, c.height );
				var fctx = flat.getContext( '2d' );

				fctx.fillStyle = '#fff';
				fctx.fillRect( 0, 0, flat.width, flat.height );
				fctx.drawImage( c, 0, 0 );
				flat.toBlob( function ( blob ) {
					freeCanvas( flat );
					resolve( blob );
				}, 'image/jpeg', 1 );
			} ).then( function ( blob ) {
				return blob ? blob.arrayBuffer().then( function ( b ) {
					return { jpeg: new Uint8Array( b ) };
				} ) : null;
			} );
		}

		return imageParts.then( function ( img ) {
			if ( ! img ) {
				return null;
			}

			var chunks = [];
			var offsets = [];
			var len = 0;
			var push = function ( bytes ) {
				chunks.push( bytes );
				len += bytes.length;
			};
			var text = function ( str ) {
				push( enc.encode( str ) );
			};
			var obj = function ( num, dict, stream ) {
				offsets[ num ] = len;
				text( num + ' 0 obj\n' + dict.replace( 'LEN', stream ? String( stream.length ) : '0' ) + '\n' );

				if ( stream ) {
					text( 'stream\n' );
					push( stream );
					text( '\nendstream\n' );
				}

				text( 'endobj\n' );
			};

			text( '%PDF-1.4\n' );
			obj( 1, '<< /Type /Catalog /Pages 2 0 R >>' );
			obj( 2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>' );
			obj( 3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + wPt + ' ' + hPt + '] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>' );

			var content = enc.encode( 'q ' + dw.toFixed( 2 ) + ' 0 0 ' + dh.toFixed( 2 ) + ' ' + offX.toFixed( 2 ) + ' ' + offY.toFixed( 2 ) + ' cm /Im0 Do Q' );

			obj( 4, '<< /Length LEN >>', content );

			var base = '/Type /XObject /Subtype /Image /Width ' + c.width + ' /Height ' + c.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8';

			if ( img.jpeg ) {
				obj( 5, '<< ' + base + ' /Filter /DCTDecode /Length LEN >>', img.jpeg );
			} else {
				obj( 5, '<< ' + base + ' /Filter /FlateDecode /SMask 6 0 R /Length LEN >>', img.rgb );
				obj( 6, '<< /Type /XObject /Subtype /Image /Width ' + c.width + ' /Height ' + c.height + ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length LEN >>', img.alpha );
			}

			var count = offsets.length;
			var xref = len;
			var table = 'xref\n0 ' + count + '\n0000000000 65535 f \n';

			for ( var o = 1; o < count; o++ ) {
				table += ( '0000000000' + offsets[ o ] ).slice( -10 ) + ' 00000 n \n';
			}

			text( table + 'trailer\n<< /Size ' + count + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n' );

			return new Blob( [ concat( chunks ) ], { type: 'application/pdf' } );
		} );
	};

	var prepareShare = function () {
		if ( ! S.work || S.shareFile || S.sharePrep || ! cfg.whatsapp || ! canShareFiles() ) {
			return;
		}

		S.sharePrep = true;

		var work = S.work;

		buildPdf().then( function ( blob ) {
			if ( S.work !== work || ! blob ) {
				S.sharePrep = false;
				return;
			}

			var d = new Date();
			var pad = function ( n ) {
				return ( n < 10 ? '0' : '' ) + n;
			};
			var label = 'print' === S.kind ? 'print-' + S.sizeKey : 'bg' === S.kind ? 'no-background' : 'bigger';

			S.shareFile = new File( [ blob ], 'prime-' + label + '-' + d.getFullYear() + pad( d.getMonth() + 1 ) + pad( d.getDate() ) + '.pdf', { type: 'application/pdf' } );
			S.sharePrep = false;

			var btn = root.querySelector( '#pil-wa-share' );

			if ( btn ) {
				btn.disabled = false;
				btn.textContent = t().waShare;
			}
		}, function () {
			S.sharePrep = false;
		} );
	};

	var shareToWhatsApp = function () {
		var L = t();

		if ( ! S.shareFile ) {
			return;
		}

		navigator.share( { files: [ S.shareFile ], title: 'PRIME', text: L.waMessage } ).then( function () {
			S.downloaded = true;
		}, function ( e ) {
			if ( e && 'AbortError' === e.name ) {
				return;
			}

			setMsg( L.waShareFail, 'is-error' );
		} );
	};

	/* ---- Download -------------------------------------------------------- */

	var download = function () {
		if ( ! S.work ) {
			return;
		}

		var L = t();
		var png = S.hasAlpha;
		var src = S.work;
		var flat = null;

		if ( ! png ) {
			// JPEG has no transparency; nothing here is transparent, but keep white behind just in case.
			flat = makeCanvas( src.width, src.height );

			var fctx = flat.getContext( '2d' );

			fctx.fillStyle = '#fff';
			fctx.fillRect( 0, 0, flat.width, flat.height );
			fctx.drawImage( src, 0, 0 );
			src = flat;
		}

		var type = png ? 'image/png' : 'image/jpeg';

		src.toBlob( function ( blob ) {
			freeCanvas( flat );

			if ( ! blob ) {
				setMsg( L.errMemory, 'is-error' );
				return;
			}

			blob.arrayBuffer().then( function ( buf ) {
				var bytes = new Uint8Array( buf );

				if ( S.dpi ) {
					bytes = Core.stampDpi( bytes, type, S.dpi );
				}

				var d = new Date();
				var pad = function ( n ) {
					return ( n < 10 ? '0' : '' ) + n;
				};
				var label = 'print' === S.kind ? 'print-' + S.sizeKey : 'bg' === S.kind ? 'no-background' : 'bigger';
				var name = 'prime-' + label + '-' + d.getFullYear() + pad( d.getMonth() + 1 ) + pad( d.getDate() ) + '-' + pad( d.getHours() ) + pad( d.getMinutes() ) + ( png ? '.png' : '.jpg' );
				var url = URL.createObjectURL( new Blob( [ bytes ], { type: type } ) );
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
			} );
		}, type, 0.93 );
	};

	/* ---- Events ---------------------------------------------------------- */

	var bind = function () {
		var file = root.querySelector( '#pil-file' );
		var drop = root.querySelector( '#pil-drop' );
		var choose = root.querySelector( '#pil-choose' ) || root.querySelector( '#pil-change' );

		if ( choose ) {
			choose.addEventListener( 'click', function () {
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
				if ( e.target === drop || /pil__drop-(title|hint)/.test( e.target.className ) ) {
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

				S.msg = { text: S.orig ? t().ready : '', cls: '' };
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

		Array.prototype.forEach.call( root.querySelectorAll( '[data-action]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var key = b.getAttribute( 'data-action' );

				if ( 'up' === key ) {
					run( jobUp );
				} else if ( 'bg' === key ) {
					if ( aiAvailable() && ! S.aiOk && ! AI.session ) {
						S.mode = 'ai';
						build();
					} else {
						run( jobBg );
					}
				} else {
					S.mode = 'sizes';
					build();
				}
			} );
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-size]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var key = b.getAttribute( 'data-size' );

				if ( 'RU' === key ) {
					S.mode = 'rudesign';
					build();
					return;
				}

				run( function () {
					return jobPrint( key );
				} );
			} );
		} );

		var ruGo = root.querySelector( '#pil-ru-go' );

		if ( ruGo ) {
			var readText = function () {
				S.bannerText = {
					headline: root.querySelector( '#pil-t-head' ).value.trim(),
					sub: root.querySelector( '#pil-t-sub' ).value.trim(),
					contact: root.querySelector( '#pil-t-contact' ).value.trim()
				};
			};

			[ 'pil-t-head', 'pil-t-sub', 'pil-t-contact' ].forEach( function ( id ) {
				root.querySelector( '#' + id ).addEventListener( 'input', readText );
			} );

			ruGo.addEventListener( 'click', function () {
				readText();
				S.aiOk = true;
				run( jobRuDesign );
			} );

			root.querySelector( '#pil-ru-asis' ).addEventListener( 'click', function () {
				S.fit = false;
				S.mode = 'choose';
				run( function () {
					return jobPrint( 'RU' );
				} );
			} );
		}

		var aiGo = root.querySelector( '#pil-ai-go' );
		var aiCancel = root.querySelector( '#pil-ai-cancel' );

		if ( aiGo ) {
			aiGo.addEventListener( 'click', function () {
				S.aiOk = true;
				S.mode = 'choose';
				run( jobBg );
			} );
			aiCancel.addEventListener( 'click', function () {
				S.mode = 'choose';
				build();
			} );
		}

		var back = root.querySelector( '#pil-back' );

		if ( back ) {
			back.addEventListener( 'click', function () {
				S.mode = 'rudesign' === S.mode ? 'sizes' : 'choose';
				build();
			} );
		}

		var dl = root.querySelector( '#pil-download' );

		if ( dl ) {
			dl.addEventListener( 'click', download );
		}

		var pdfBtn = root.querySelector( '#pil-download-pdf' );

		if ( pdfBtn ) {
			pdfBtn.addEventListener( 'click', function () {
				pdfBtn.disabled = true;
				setMsg( t().working, 'is-busy' );

				buildPdf().then( function ( blob ) {
					pdfBtn.disabled = false;

					if ( ! blob ) {
						setMsg( t().errGeneric, 'is-error' );
						return;
					}

					var d = new Date();
					var pad = function ( n ) {
						return ( n < 10 ? '0' : '' ) + n;
					};
					var tag = 'RU' === S.sizeKey ? 'rollup-85x200cm' : S.sizeKey;
					var name = 'prime-print-' + tag + '-' + d.getFullYear() + pad( d.getMonth() + 1 ) + pad( d.getDate() ) + '-' + pad( d.getHours() ) + pad( d.getMinutes() ) + '.pdf';
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
					setMsg( t().statusDonePdf || name, '' );
				}, function () {
					pdfBtn.disabled = false;
					setMsg( t().errMemory, 'is-error' );
				} );
			} );
		}

		var share = root.querySelector( '#pil-wa-share' );

		if ( share ) {
			share.addEventListener( 'click', shareToWhatsApp );
			prepareShare();
		}

		root.querySelector( '#pil-start' ).addEventListener( 'click', function () {
			// Back to the photo as chosen, with nothing applied.
			freeCanvas( S.work );
			S.work = null;

			if ( S.printBaseOwned ) {
				freeCanvas( S.printBase );
			}

			S.printBase = null;
			S.printBaseOwned = false;
			S.paper = null;
			S.fit = false;
			S.kind = '';
			S.dpi = 0;
			S.mode = 'choose';
			S.msg = { text: t().ready, cls: '' };
			build();
		} );
	};

	window.addEventListener( 'resize', sizeCompare );

	window.addEventListener( 'pagehide', function () {
		release();

		if ( worker ) {
			worker.terminate();
		}
	} );

	// A hook for automated checks; it exposes state, never image data.
	window.PrimeImageLabDebug = { state: S, limits: LIMITS };

	build();
}() );
