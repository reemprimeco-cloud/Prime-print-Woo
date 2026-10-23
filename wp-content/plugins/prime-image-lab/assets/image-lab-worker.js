/* PRIME Image Lab worker: runs the pixel maths off the main thread. */
importScripts( 'image-lab-core.js' );

self.onmessage = function ( event ) {
	var m = event.data;

	try {
		var img = { data: new Uint8ClampedArray( m.buffer ), width: m.width, height: m.height };
		var info = {};

		if ( 'improve' === m.op ) {
			self.PrimeLabCore.improve( img, m.settings );
		} else if ( 'bg' === m.op ) {
			var r = self.PrimeLabCore.removePlainBackground( img, m.settings.tolerance, m.settings.feather );
			info = { removed: r.removed, color: r.color };
		}

		self.postMessage( { id: m.id, ok: true, buffer: img.data.buffer, info: info }, [ img.data.buffer ] );
	} catch ( e ) {
		self.postMessage( { id: m.id, ok: false, message: String( e && e.message ? e.message : e ) } );
	}
};
