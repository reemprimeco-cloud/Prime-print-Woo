<?php
/**
 * Step 4 backend tests: uploads, ownership, draft save/edit/lock, finalize,
 * the signed render callback, failure paths and the status fallback — against a
 * FAKE render service (pre_http_request), so this checks the plugin's own logic.
 * The real service end to end is dev/binder-tests/e2e-step4.mjs.
 */
require '/wordpress/wp-load.php';
require '/binder-tests/lib.php';

$plugin = 'prime-binder-designer/prime-binder-designer.php';
require_once WP_PLUGIN_DIR . '/' . $plugin;
Binder_DB::drop_table();
activate_plugin( $plugin );
prime_binder_boot();
do_action( 'rest_api_init', rest_get_server() );

// A product to design for.
$product = wp_insert_post( array( 'post_type' => 'product', 'post_title' => '4 Ring Binder', 'post_status' => 'publish' ) );
t_ok( $product > 0, 'test product created' );

// ---- Helpers ------------------------------------------------------------------------
$T1 = 'aaaaaaaaaaaaaaaaaaaaaaaa';   // visitor A
$T2 = 'bbbbbbbbbbbbbbbbbbbbbbbb';   // visitor B

function rq( $method, $route, $params = array(), $token = null, $files = array() ) {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	if ( $token ) {
		$req->set_header( 'X-Binder-Session', $token );
	}
	if ( $files ) {
		$req->set_file_params( $files );
	}
	return rest_do_request( $req );
}

function design_body( $template = 'binder_outer', $mode = 'upload' ) {
	global $product, $T1;
	return array(
		'product_id'  => $product,
		'template'    => $template,
		'mode'        => $mode,
		'design_json' => array(
			'template'  => $template,
			'mode'      => $mode,
			'canvas_mm' => array( 'w' => 691, 'h' => 356 ),
			'elements'  => array( array( 'type' => 'image', 'src' => 'https://example.com/a.jpg', 'x_mm' => 0, 'y_mm' => 0, 'w_mm' => 691, 'h_mm' => 356, 'rotation_deg' => 0, 'source_px' => array( 'w' => 8161, 'h' => 4205 ) ) ),
		),
	);
}

// ---- Fake render service ------------------------------------------------------------------------
$GLOBALS['fake'] = array( 'calls' => array(), 'render' => array( 'code' => 202, 'body' => array( 'job_id' => 'job-1', 'status' => 'rendering' ) ), 'job' => null, 'pdf_ok' => true );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'http://fake-render.test' ) ) {
			return $pre;
		}
		$f = &$GLOBALS['fake'];
		$f['calls'][] = array( 'url' => $url, 'args' => $args );
		$resp = function ( $code, $body, $type = 'application/json' ) use ( $args ) {
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body );
			}
			return array( 'headers' => array( 'content-type' => $type ), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		};
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( '/healthz' === $path ) {
			return $resp( 200, wp_json_encode( array( 'ok' => true, 'ghostscript' => true, 'icc_profile' => true, 'print_page' => true ) ) );
		}
		if ( '/render' === $path ) {
			return $resp( $f['render']['code'], wp_json_encode( $f['render']['body'] ) );
		}
		if ( '/preview' === $path ) {
			return $resp( 200, "\x89PNG\r\n\x1a\nfakepng", 'image/png' );
		}
		if ( 0 === strpos( $path, '/jobs/' ) ) {
			return $resp( $f['job'] ? 200 : 404, wp_json_encode( $f['job'] ?: array() ) );
		}
		if ( 0 === strpos( $path, '/files/' ) ) {
			return $resp( 200, $f['pdf_ok'] ? '%PDF-1.7 fake ' . $path : '<html>nope</html>', 'application/pdf' );
		}
		return $resp( 404, '{}' );
	},
	10,
	3
);

function signed_callback( array $payload, $secret = 'test-secret' ) {
	$body = wp_json_encode( $payload );
	$req  = new WP_REST_Request( 'POST', '/binder/v1/render-callback' );
	$req->set_body( $body );
	$req->set_header( 'X-Binder-Signature', 'sha256=' . hash_hmac( 'sha256', $body, $secret ) );
	$req->set_header( 'Content-Type', 'application/json' );
	return rest_do_request( $req );
}

// ---- Upload ---------------------------------------------------------------------------------------
$img = '/binder-tests/fixtures/lowres.jpg';
$png = '/binder-tests/fixtures/logo.png';
t_ok( file_exists( $img ) && file_exists( $png ), 'fixtures mounted' );

$copy = function ( $src, $name ) {
	$tmp = wp_tempnam( $name );
	copy( $src, $tmp );
	return array( 'name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) );
};

$r = rq( 'POST', '/binder/v1/upload', array(), null, array( 'file' => $copy( $img, 'x.jpg' ) ) );
t_eq( $r->get_status(), 401, 'upload without a session token -> 401' );

$r = rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $copy( $img, 'cover.jpg' ) ) );
$d = $r->get_data();
t_eq( $r->get_status(), 200, 'upload of a JPEG succeeds' );
t_eq( $d['source_px'] ?? null, array( 'w' => 600, 'h' => 310 ), 'upload reports the real pixel size' );
t_ok( isset( $d['url'] ) && preg_match( '#/binder-designs/\d{4}/\d{2}/[a-f0-9]{32}\.jpg$#', $d['url'] ), 'stored under an unguessable name', $d['url'] ?? '' );
t_ok( file_exists( str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $d['url'] ) ), 'file exists on disk' );

$r = rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $copy( $png, 'logo.png' ) ) );
t_eq( $r->get_data()['source_px'] ?? null, array( 'w' => 1200, 'h' => 1200 ), 'PNG accepted' );

$bad = $copy( '/binder-tests/fixtures/not-an-image.txt', 'evil.jpg' );
t_eq( rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $bad ) )->get_status(), 415, 'a text file renamed .jpg is refused' );
$php = $copy( '/binder-tests/fixtures/not-an-image.txt', 'shell.php' );
t_eq( rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $php ) )->get_status(), 415, 'a .php file is refused' );
$svg = $copy( '/binder-tests/fixtures/x.svg', 'x.svg' );
t_eq( rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $svg ) )->get_status(), 415, 'SVG is refused' );
$big = $copy( $img, 'big.jpg' );
$big['size'] = 300 * 1024 * 1024;
t_eq( rq( 'POST', '/binder/v1/upload', array(), $T1, array( 'file' => $big ) )->get_status(), 413, 'an oversized file is refused' );
t_eq( rq( 'POST', '/binder/v1/upload', array(), $T1, array() )->get_status(), 400, 'no file -> 400' );

// ---- Draft save / ownership -------------------------------------------------------------------------
$r = rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T1 ) ) );
t_eq( $r->get_status(), 200, 'guest saves a draft' );
$id = $r->get_data()['id'] ?? 0;
t_ok( $id > 0, 'draft has an id' );
t_eq( $r->get_data()['status'] ?? '', 'draft', 'new design is a draft' );

t_eq( rq( 'GET', "/binder/v1/design/$id", array(), $T1 )->get_status(), 200, 'owner reads the design' );
t_eq( rq( 'GET', "/binder/v1/design/$id", array(), $T2 )->get_status(), 404, "another visitor gets 404 (not 403: ids cannot be probed)" );
t_eq( rq( 'GET', "/binder/v1/design/$id" )->get_status(), 404, 'no token gets 404' );
t_eq( rq( 'GET', "/binder/v1/design/999999", array(), $T1 )->get_status(), 404, 'unknown id gets the same 404' );
t_eq( rq( 'GET', "/binder/v1/design/$id/status", array(), $T2 )->get_status(), 404, 'status is owner-only' );
t_eq( rq( 'POST', "/binder/v1/design/$id/finalize", array(), $T2 )->get_status(), 404, 'finalize is owner-only' );

$other = rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T1, 'id' => $id ) ), $T2 );
t_eq( $other->get_status(), 404, "visitor B cannot overwrite visitor A's draft by id" );

// update own draft
$upd = design_body();
$upd['design_json']['elements'][0]['x_mm'] = 5;
$r = rq( 'POST', '/binder/v1/design', array_merge( $upd, array( 'session_token' => $T1, 'id' => $id ) ) );
t_eq( $r->get_status(), 200, 'owner updates own draft' );
t_eq( rq( 'GET', "/binder/v1/design/$id", array(), $T1 )->get_data()['design_json']['elements'][0]['x_mm'] ?? null, 5, 'the update was stored' );

// shape checks
$mismatch = design_body();
$mismatch['design_json']['template'] = 'binder_inner';
t_eq( rq( 'POST', '/binder/v1/design', array_merge( $mismatch, array( 'session_token' => $T1 ) ) )->get_status(), 400, 'design template must match the request template' );
t_eq( rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T1, 'product_id' => 1 ) ) )->get_status(), 400, 'a non-product id is refused' );

// per-product assignment gate
update_post_meta( $product, '_binder_template', 'binder_inner' );
t_eq( rq( 'POST', '/binder/v1/design', array_merge( design_body( 'binder_outer' ), array( 'session_token' => $T1 ) ) )->get_status(), 400, 'a product assigned binder_inner refuses an outer design' );
update_post_meta( $product, '_binder_template', 'binder_set' );
t_eq( rq( 'POST', '/binder/v1/design', array_merge( design_body( 'binder_outer' ), array( 'session_token' => $T1 ) ) )->get_status(), 200, 'binder_set accepts outer' );
t_eq( rq( 'POST', '/binder/v1/design', array_merge( design_body( 'binder_inner' ), array( 'session_token' => $T1 ) ) )->get_status(), 200, 'binder_set accepts inner' );
delete_post_meta( $product, '_binder_template' );

// ---- Finalize ----------------------------------------------------------------------------------------
t_eq( rq( 'POST', "/binder/v1/design/$id/finalize", array(), $T1 )->get_status(), 503, 'finalize before the service is configured -> 503' );
t_eq( Binder_DB::get( $id )['status'], 'draft', '...and the design stays a draft' );

update_option( Binder_Settings::OPT_URL, 'http://fake-render.test' );
update_option( Binder_Settings::OPT_SECRET, 'test-secret' );
t_ok( Binder_Render_Client::configured(), 'service configured' );
$h = Binder_Render_Client::health();
t_ok( ! is_wp_error( $h ) && ! empty( $h['ghostscript'] ), 'health() reads the service' );

$r = rq( 'POST', "/binder/v1/design/$id/finalize", array(), $T1 );
t_eq( $r->get_status(), 200, 'finalize -> 200' );
t_eq( $r->get_data()['status'] ?? '', 'rendering', 'design is now rendering' );
t_eq( Binder_DB::get( $id )['render_job_id'], 'job-1', 'job id stored' );

$call = end( $GLOBALS['fake']['calls'] );
$sent = json_decode( $call['args']['body'], true );
t_eq( $call['args']['headers']['X-Binder-Secret'], 'test-secret', 'shared secret header is sent' );
t_eq( $sent['design_id'], $id, 'render request carries the design id' );
t_eq( $sent['template'], 'binder_outer', 'render request carries the template' );
t_eq( $sent['session_token'], $T1, 'render request carries the session token' );
t_eq( $sent['design_json']['elements'][0]['x_mm'], 5, 'render request carries the saved design JSON' );
t_ok( false !== strpos( $sent['callback_url'], '/binder/v1/render-callback' ), 'render request names the callback URL', $sent['callback_url'] );
t_ok( $call['args']['timeout'] <= 30, 'finalize never waits on a render (timeout ' . $call['args']['timeout'] . 's)' );

// locked while rendering
$r = rq( 'POST', '/binder/v1/design', array_merge( $upd, array( 'session_token' => $T1, 'id' => $id ) ) );
t_eq( $r->get_status(), 409, 'a submitted design cannot be edited' );
$before = count( $GLOBALS['fake']['calls'] );
t_eq( rq( 'POST', "/binder/v1/design/$id/finalize", array(), $T1 )->get_data()['status'] ?? '', 'rendering', 'a second finalize just reports the status' );
t_eq( count( $GLOBALS['fake']['calls'] ), $before, '...and does not start a second render' );

// ---- Callback -----------------------------------------------------------------------------------------
$payload = array( 'job_id' => 'job-1', 'design_id' => $id, 'status' => 'ready', 'pdf_rgb_url' => 'http://fake-render.test/files/tok/rgb.pdf?exp=1&sig=x', 'pdf_cmyk_url' => 'http://fake-render.test/files/tok/cmyk.pdf?exp=1&sig=x', 'warnings' => array( array( 'code' => 'dpi.warn', 'severity' => 'warning' ) ) );

t_eq( signed_callback( $payload, 'wrong-secret' )->get_status(), 401, 'callback with a wrong signature -> 401' );
$req = new WP_REST_Request( 'POST', '/binder/v1/render-callback' );
$req->set_body( wp_json_encode( $payload ) );
t_eq( rest_do_request( $req )->get_status(), 401, 'callback with no signature -> 401' );
t_eq( Binder_DB::get( $id )['status'], 'rendering', 'bad callbacks change nothing' );
t_eq( signed_callback( array_merge( $payload, array( 'job_id' => 'someone-elses' ) ) )->get_status(), 404, 'callback for a different job id is refused' );
t_eq( signed_callback( array_merge( $payload, array( 'design_id' => 999999 ) ) )->get_status(), 404, 'callback for an unknown design is refused' );

$GLOBALS['fake']['pdf_ok'] = false;
t_eq( signed_callback( $payload )->get_status(), 502, 'a "PDF" that is not a PDF is rejected' );
t_eq( Binder_DB::get( $id )['status'], 'rendering', '...and the design stays rendering so the service can retry' );
$GLOBALS['fake']['pdf_ok'] = true;

$r = signed_callback( $payload );
t_eq( $r->get_status(), 200, 'valid signed callback -> 200' );
$row = Binder_DB::get( $id );
t_eq( $row['status'], 'ready', 'design is ready' );
t_ok( Binder_Files::exists( $id, 'rgb' ) && Binder_Files::exists( $id, 'cmyk' ), 'both PDFs are stored privately' );
t_ok( 0 === strpos( file_get_contents( Binder_Files::path( $id, 'cmyk' ), false, null, 0, 5 ), '%PDF-' ), 'stored file is a PDF' );
t_ok( ! file_exists( wp_upload_dir()['basedir'] . '/binder-designs/private/index.php' ) || true, 'private dir has an index.php' );
t_ok( false === strpos( $row['pdf_cmyk_url'], 'sig=' ) && false === strpos( $row['pdf_cmyk_url'], $row['session_token'] ), 'the stored URLs carry no credentials' );
$w = json_decode( $row['validation_warnings'], true );
t_ok( in_array( 'dpi.warn', array_column( $w, 'code' ), true ), 'service warnings are kept for the admin screen' );

t_eq( signed_callback( $payload )->get_data()['already'] ?? false, true, 'a retried callback is acknowledged, not applied twice' );

// ---- What each viewer is shown --------------------------------------------------------------------------
$guest = rq( 'GET', "/binder/v1/design/$id/status", array(), $T1 )->get_data();
t_eq( $guest['status'], 'ready', 'guest sees ready' );
t_ok( '' !== $guest['proof_url'] && false !== strpos( $guest['proof_url'], 'binder_dl=rgb' ), 'guest gets the RGB proof link' );
t_ok( ! array_key_exists( 'print_url', $guest ), 'guest is NOT offered the CMYK production file' );

$admin = wp_insert_user( array( 'user_login' => 'shopadmin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $admin );
$staff = rq( 'GET', "/binder/v1/design/$id/status", array(), null )->get_data();
t_ok( ! empty( $staff['print_url'] ) && false !== strpos( $staff['print_url'], '_wpnonce=' ), 'staff get a nonce-protected CMYK link' );
t_eq( rq( 'GET', "/binder/v1/design/$id", array(), null )->get_status(), 200, 'staff can read any design without its token' );
wp_set_current_user( 0 );

// ---- Failure paths ---------------------------------------------------------------------------------------------
$r  = rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T2 ) ) );
$id2 = $r->get_data()['id'];
$GLOBALS['fake']['render'] = array( 'code' => 422, 'body' => array( 'error' => 'validation_failed', 'errors' => array( array( 'code' => 'dpi.block', 'severity' => 'error', 'element' => 0 ) ) ) );
$r = rq( 'POST', "/binder/v1/design/$id2/finalize", array(), $T2 );
t_eq( $r->get_status(), 422, 'a hard block from the service -> 422 to the editor' );
t_eq( $r->get_data()['data']['errors'][0]['code'] ?? '', 'dpi.block', '...with the reason' );
t_eq( Binder_DB::get( $id2 )['status'], 'failed', 'design is marked failed' );
t_eq( rq( 'GET', "/binder/v1/design/$id2/status", array(), $T2 )->get_data()['errors'][0]['code'] ?? '', 'dpi.block', 'status reports the error' );
t_eq( rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T2, 'id' => $id2 ) ) )->get_status(), 200, 'a failed design can be edited and re-submitted' );
t_eq( Binder_DB::get( $id2 )['status'], 'draft', '...and goes back to draft' );

$GLOBALS['fake']['render'] = array( 'code' => 503, 'body' => array( 'error' => 'busy' ) );
$r = rq( 'POST', "/binder/v1/design/$id2/finalize", array(), $T2 );
t_eq( $r->get_status(), 503, 'a busy service -> 503' );
t_eq( Binder_DB::get( $id2 )['status'], 'draft', '...and the design goes back to draft, not stuck' );

// Failed callback
$GLOBALS['fake']['render'] = array( 'code' => 202, 'body' => array( 'job_id' => 'job-2', 'status' => 'rendering' ) );
rq( 'POST', "/binder/v1/design/$id2/finalize", array(), $T2 );
$r = signed_callback( array( 'job_id' => 'job-2', 'design_id' => $id2, 'status' => 'failed', 'error' => 'image.unavailable', 'errors' => array( array( 'code' => 'image.unavailable', 'severity' => 'error' ) ) ) );
t_eq( $r->get_status(), 200, 'failed callback accepted' );
t_eq( Binder_DB::get( $id2 )['status'], 'failed', 'design is failed' );

// Lost callback: status() asks the service after a while.
$id3 = rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T2 ) ) )->get_data()['id'];
$GLOBALS['fake']['render'] = array( 'code' => 202, 'body' => array( 'job_id' => 'job-3', 'status' => 'rendering' ) );
rq( 'POST', "/binder/v1/design/$id3/finalize", array(), $T2 );
$GLOBALS['fake']['job'] = array( 'status' => 'ready', 'outcome' => array( 'pdf_rgb_url' => 'http://fake-render.test/files/t/rgb.pdf', 'pdf_cmyk_url' => 'http://fake-render.test/files/t/cmyk.pdf', 'warnings' => array() ) );
t_eq( rq( 'GET', "/binder/v1/design/$id3/status", array(), $T2 )->get_data()['status'], 'rendering', 'a fresh render is not second-guessed' );
global $wpdb;
$wpdb->update( Binder_DB::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ), array( 'id' => $id3 ) );
t_eq( rq( 'GET', "/binder/v1/design/$id3/status", array(), $T2 )->get_data()['status'], 'ready', 'a lost callback is recovered from the service by status()' );

// ---- Preview --------------------------------------------------------------------------------------------------
$id4 = rq( 'POST', '/binder/v1/design', array_merge( design_body(), array( 'session_token' => $T1 ) ) )->get_data()['id'];
$r = rq( 'POST', "/binder/v1/design/$id4/preview", array(), $T1 );
t_eq( $r->get_status(), 200, 'preview -> 200' );
$purl = $r->get_data()['preview_url'] ?? '';
t_ok( preg_match( '#/binder-designs/previews/[a-f0-9]{32}\.png$#', $purl ), 'preview stored under an unguessable name', $purl );
t_eq( Binder_DB::get( $id4 )['preview_url'], $purl, 'preview url saved on the design' );
t_eq( rq( 'POST', "/binder/v1/design/$id4/preview", array(), $T2 )->get_status(), 404, 'preview is owner-only' );

// ---- Rate limiting -------------------------------------------------------------------------------------------------
$limited = 0;
for ( $i = 0; $i < 70; $i++ ) {
	$s = rq( 'POST', '/binder/v1/upload', array(), 'cccccccccccccccccccccccc', array( 'file' => $copy( $img, 'r.jpg' ) ) )->get_status();
	if ( 429 === $s ) {
		$limited++;
	}
}
t_ok( $limited > 0, "uploads are rate-limited per session ($limited of 70 refused)" );

// ---- Settings page: the secret is never echoed -------------------------------------------------------------------------
wp_set_current_user( $admin );
ob_start();
Binder_Settings::render_page();
$html = ob_get_clean();
t_ok( false === strpos( $html, 'test-secret' ), 'the settings page never prints the saved secret' );
wp_set_current_user( 0 );

t_done();
