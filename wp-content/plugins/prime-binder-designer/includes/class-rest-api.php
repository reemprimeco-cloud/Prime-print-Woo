<?php
/**
 * REST endpoints, namespace binder/v1 (§3.3).
 *
 * Guest-safe: a customer is identified by the random session_token their
 * browser generated (X-Binder-Session header or session_token parameter), and
 * may only touch designs carrying that token. Shop staff (manage_woocommerce)
 * may read any design.
 *
 * Added to the §3.3 table: POST /upload (artwork files) and POST
 * /render-callback (the render service's signed result).
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Rest_API {

	const NAMESPACE = 'binder/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Shared argument schema for the design routes.
	 *
	 * @return array
	 */
	private static function design_args() {
		return array(
			// Header X-Binder-Session or this parameter; the permission callbacks enforce that one is present.
			'session_token' => array(
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9-]{16,64}$',
			),
			'product_id'    => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			'template'      => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => Binder_Templates::keys(),
			),
			'mode'          => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( 'upload', 'live' ),
			),
			'design_json'   => array(
				'type'     => 'object',
				'required' => true,
			),
		);
	}

	public static function register_routes() {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/template/(?P<template>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_template' ),
				'permission_callback' => '__return_true', // Public: geometry only.
				'args'                => array(
					'template' => array(
						'type' => 'string',
						'enum' => Binder_Templates::keys(),
					),
					// Sticker only: size in mm and shape, as chosen on the product page.
					'w'        => array( 'type' => 'number' ),
					'h'        => array( 'type' => 'number' ),
					'shape'    => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/template/(?P<template>[a-z_]+)/overlay',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_overlay' ),
				'permission_callback' => '__return_true', // Authorised by the signature in the URL.
				'args'                => array(
					'template' => array(
						'type' => 'string',
						'enum' => Binder_Templates::keys(),
					),
					'exp'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'sig'      => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => array( __CLASS__, 'permit_token' ),
				'args'                => array(
					'session_token' => self::design_args()['session_token'],
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/design',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_design' ),
				'permission_callback' => array( __CLASS__, 'permit_save' ),
				'args'                => array_merge(
					self::design_args(),
					array(
						'id'                  => array( 'type' => 'integer', 'minimum' => 1 ),
						'validation_warnings' => array( 'type' => 'array' ),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/design/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_design' ),
				'permission_callback' => array( __CLASS__, 'permit_owner' ),
				'args'                => $id_arg,
			)
		);

		foreach ( array(
			'preview'  => 'preview',
			'finalize' => 'finalize',
		) as $action => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/design/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'permit_owner' ),
					'args'                => $id_arg,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/design/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'permit_owner' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/render-callback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'render_callback' ),
				'permission_callback' => array( __CLASS__, 'permit_signed_callback' ),
			)
		);
	}

	// ---- Real: template geometry ---------------------------------------------

	/**
	 * GET /template/{template}: the spec.json plus a signed overlay URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_template( WP_REST_Request $request ) {
		$template = $request['template'];
		$params   = null;

		if ( Binder_Templates::is_parametric( $template ) ) {
			$params = Binder_Sticker::normalize( $request['w'], $request['h'], $request['shape'] );
			if ( ! $params ) {
				return new WP_Error( 'binder_bad_size', __( 'Enter a sticker size between 1 and 100 cm and choose a shape.', 'prime-binder-designer' ), array( 'status' => 400 ) );
			}
		}

		$spec = Binder_Templates::spec( $template, $params );

		if ( null === $spec ) {
			return new WP_Error( 'binder_unknown_template', __( 'Unknown template.', 'prime-binder-designer' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'template'    => $template,
				'spec'        => $spec,
				'overlay_url' => Binder_Templates::overlay_url( $template ),
			)
		);
	}

	/**
	 * GET /template/{template}/overlay: the PNG, if the signature is valid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_overlay( WP_REST_Request $request ) {
		$template = $request['template'];
		$path     = Binder_Templates::path( $template, 'overlay' );

		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'binder_unknown_template', __( 'Unknown template.', 'prime-binder-designer' ), array( 'status' => 404 ) );
		}

		if ( ! Binder_Templates::verify( $template, $request['exp'], $request['sig'] ) ) {
			return new WP_Error( 'binder_bad_signature', __( 'This link has expired or is invalid.', 'prime-binder-designer' ), array( 'status' => 403 ) );
		}

		// The REST server JSON-encodes whatever a callback returns. Take over the
		// output for this one route and send the file bytes as they are.
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $req ) use ( $path, $request ) {
				if ( $req->get_route() !== $request->get_route() ) {
					return $served;
				}

				header( 'Content-Type: image/png' );
				header( 'Content-Length: ' . filesize( $path ) );
				header( 'Cache-Control: private, max-age=3600' );

				readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

				return true;
			},
			10,
			3
		);

		return new WP_REST_Response( null, 200 );
	}

	// ---- Permissions -------------------------------------------------------------------------

	/**
	 * The customer's session token: header first, then a parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function token( WP_REST_Request $request ) {
		$token = (string) $request->get_header( 'X-Binder-Session' );

		if ( '' === $token ) {
			$token = (string) $request->get_param( 'session_token' );
		}

		return preg_match( '/^[A-Za-z0-9-]{16,64}$/', $token ) ? $token : '';
	}

	/**
	 * Any well-formed session token (uploads and new drafts have no row yet).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permit_token( WP_REST_Request $request ) {
		if ( '' === self::token( $request ) ) {
			return new WP_Error( 'binder_no_session', __( 'A session token is required.', 'prime-binder-designer' ), array( 'status' => 401 ) );
		}

		return self::rate_limit( 'tok', self::token( $request ), 240 );
	}

	/**
	 * Creating or updating a draft: a token, and if it names a row, that row's owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permit_save( WP_REST_Request $request ) {
		$ok = self::permit_token( $request );
		if ( true !== $ok ) {
			return $ok;
		}

		$id = (int) $request->get_param( 'id' );
		if ( $id > 0 ) {
			return self::owns( $id, $request );
		}

		return true;
	}

	/**
	 * Routes about an existing design: its owner, or shop staff.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permit_owner( WP_REST_Request $request ) {
		return self::owns( (int) $request['id'], $request );
	}

	private static function owns( $id, WP_REST_Request $request ) {
		$row = Binder_DB::get( $id );

		// One answer for "missing" and "not yours", so ids cannot be probed.
		$deny = new WP_Error( 'binder_not_found', __( 'Design not found.', 'prime-binder-designer' ), array( 'status' => 404 ) );

		if ( ! $row ) {
			return $deny;
		}
		if ( Binder_Files::is_shop_staff() && is_user_logged_in() ) {
			return true;
		}

		$token = self::token( $request );

		return ( '' !== $token && hash_equals( (string) $row['session_token'], $token ) ) ? true : $deny;
	}

	/**
	 * The render service's callback: authorised by its HMAC over the raw body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permit_signed_callback( WP_REST_Request $request ) {
		if ( Binder_Render_Client::verify_signature( $request->get_body(), (string) $request->get_header( 'X-Binder-Signature' ) ) ) {
			return true;
		}

		return new WP_Error( 'binder_bad_signature', __( 'Invalid signature.', 'prime-binder-designer' ), array( 'status' => 401 ) );
	}

	/**
	 * Fixed-window limiter (transients) so a token or address cannot hammer the endpoints.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Who.
	 * @param int    $max    Requests per hour.
	 * @return bool|WP_Error
	 */
	private static function rate_limit( $bucket, $key, $max ) {
		$name  = 'binder_rl_' . $bucket . '_' . substr( md5( $key ), 0, 20 );
		$count = (int) get_transient( $name );

		if ( $count >= $max ) {
			return new WP_Error( 'binder_rate_limited', __( 'Too many requests. Please wait a while and try again.', 'prime-binder-designer' ), array( 'status' => 429 ) );
		}

		set_transient( $name, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	// ---- Handlers -----------------------------------------------------------------------------

	/**
	 * POST /upload: one artwork file, multipart field "file".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$limited = self::rate_limit( 'up', self::token( $request ), 60 );
		if ( true !== $limited ) {
			return $limited;
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'binder_upload_failed', __( 'No file was sent.', 'prime-binder-designer' ), array( 'status' => 400 ) );
		}

		$stored = Binder_Uploads::store( $files['file'] );

		return is_wp_error( $stored ) ? $stored : rest_ensure_response( $stored );
	}

	/**
	 * Public shape of a design row. Staff also get the CMYK link.
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	private static function present( array $row ) {
		$errors = null;
		if ( ! empty( $row['render_error'] ) ) {
			$errors = json_decode( $row['render_error'], true );
		}

		$out = array(
			'id'                  => (int) $row['id'],
			'design_id'           => (int) $row['id'],
			'product_id'          => (int) $row['product_id'],
			'template'            => $row['template'],
			'mode'                => $row['mode'],
			'status'              => $row['status'],
			'preview_url'         => $row['preview_url'],
			'proof_url'           => Binder_Files::link_for( $row, 'rgb' ),
			'validation_warnings' => $row['validation_warnings'] ? json_decode( $row['validation_warnings'], true ) : array(),
			'errors'              => $errors,
			'updated_at'          => $row['updated_at'],
		);

		if ( Binder_Files::is_shop_staff() ) {
			$out['print_url'] = Binder_Files::link_for( $row, 'cmyk' );
		}

		return $out;
	}

	/**
	 * POST /design: create a draft, or update the caller's own draft when `id` is given.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_design( WP_REST_Request $request ) {
		$template = $request['template'];
		$mode     = $request['mode'];
		$product  = (int) $request['product_id'];
		$design   = $request['design_json'];

		if ( 'product' !== get_post_type( $product ) || ! Binder_Product_Meta::allows( $product, $template ) ) {
			return new WP_Error( 'binder_bad_product', __( 'This product does not use this template.', 'prime-binder-designer' ), array( 'status' => 400 ) );
		}
		if ( ( $design['template'] ?? '' ) !== $template || ( $design['mode'] ?? '' ) !== $mode || ! isset( $design['elements'] ) || ! is_array( $design['elements'] ) || count( $design['elements'] ) > 60 ) {
			return new WP_Error( 'binder_bad_design', __( 'The design does not match its template.', 'prime-binder-designer' ), array( 'status' => 400 ) );
		}
		// A sticker design must say which size and shape it is for, and its canvas must be that size.
		if ( Binder_Templates::is_parametric( $template ) && ! Binder_Sticker::design_params( $design ) ) {
			return new WP_Error( 'binder_bad_design', __( 'The design does not carry a valid sticker size and shape.', 'prime-binder-designer' ), array( 'status' => 400 ) );
		}

		$json = wp_json_encode( $design );
		if ( false === $json || strlen( $json ) > 900000 ) {
			return new WP_Error( 'binder_bad_design', __( 'The design is too large.', 'prime-binder-designer' ), array( 'status' => 413 ) );
		}

		$warnings = isset( $request['validation_warnings'] ) ? wp_json_encode( $request['validation_warnings'] ) : null;
		$id       = (int) $request['id'];

		if ( $id > 0 ) {
			$row = Binder_DB::get( $id );
			// A finished design is locked (§3.3 finalize); editing it means starting a new draft.
			if ( ! $row || ! in_array( $row['status'], array( 'draft', 'failed' ), true ) ) {
				return new WP_Error( 'binder_locked', __( 'This design has been submitted and can no longer be edited.', 'prime-binder-designer' ), array( 'status' => 409 ) );
			}
			Binder_DB::update(
				$id,
				array(
					'template'            => $template,
					'mode'                => $mode,
					'design_json'         => $json,
					'validation_warnings' => $warnings,
					'status'              => 'draft',
					'render_error'        => null,
				)
			);
		} else {
			$id = Binder_DB::insert(
				array(
					'product_id'          => $product,
					'session_token'       => self::token( $request ),
					'template'            => $template,
					'mode'                => $mode,
					'design_json'         => $json,
					'status'              => 'draft',
					'validation_warnings' => $warnings,
				)
			);
			if ( ! $id ) {
				return new WP_Error( 'binder_save_failed', __( 'The design could not be saved.', 'prime-binder-designer' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( self::present( Binder_DB::get( $id ) ) );
	}

	/**
	 * GET /design/{id}: reopen a saved design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_design( WP_REST_Request $request ) {
		$row  = Binder_DB::get( (int) $request['id'] );
		$data = self::present( $row );

		$data['design_json'] = json_decode( (string) $row['design_json'], true );

		return rest_ensure_response( $data );
	}

	/**
	 * POST /design/{id}/preview: a fast RGB PNG proof of the saved draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		if ( ! Binder_Render_Client::configured() ) {
			return new WP_Error( 'binder_not_configured', __( 'The design service is not available yet.', 'prime-binder-designer' ), array( 'status' => 503 ) );
		}

		$limited = self::rate_limit( 'pv', self::token( $request ) . '|' . (int) $request['id'], 30 );
		if ( true !== $limited ) {
			return $limited;
		}

		$row = Binder_DB::get( (int) $request['id'] );
		$res = Binder_Render_Client::preview( $row );

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'binder_service_unreachable', __( 'The design service could not be reached.', 'prime-binder-designer' ), array( 'status' => 502 ) );
		}
		if ( 200 !== $res['status'] || 0 !== strpos( $res['type'], 'image/png' ) ) {
			$body = json_decode( $res['body'], true );

			return new WP_Error( 'binder_preview_failed', __( 'A preview could not be made.', 'prime-binder-designer' ), array( 'status' => 422 === $res['status'] ? 422 : 502, 'errors' => $body['errors'] ?? array() ) );
		}

		$upload = wp_upload_dir();
		$sub    = 'binder-designs/previews';
		wp_mkdir_p( trailingslashit( $upload['basedir'] ) . $sub );
		$name = substr( hash_hmac( 'sha256', 'preview|' . $row['id'] . '|' . microtime( true ), wp_salt( 'auth' ) ), 0, 32 ) . '.png';
		file_put_contents( trailingslashit( $upload['basedir'] ) . $sub . '/' . $name, $res['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$url = trailingslashit( $upload['baseurl'] ) . $sub . '/' . $name;
		Binder_DB::update( $row['id'], array( 'preview_url' => $url ) );

		return rest_ensure_response( array( 'id' => (int) $row['id'], 'preview_url' => $url ) );
	}

	/**
	 * POST /design/{id}/finalize: lock the design and start the production render.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finalize( WP_REST_Request $request ) {
		$row = Binder_DB::get( (int) $request['id'] );

		// Already submitted: report where it stands instead of starting a second render.
		if ( in_array( $row['status'], array( 'rendering', 'ready' ), true ) ) {
			return rest_ensure_response( self::present( $row ) );
		}

		if ( ! Binder_Render_Client::configured() ) {
			return new WP_Error( 'binder_not_configured', __( 'The design service is not available yet. Please try again later.', 'prime-binder-designer' ), array( 'status' => 503 ) );
		}

		$limited = self::rate_limit( 'fin', self::token( $request ) . '|' . $row['id'], 12 );
		if ( true !== $limited ) {
			return $limited;
		}

		Binder_DB::update( $row['id'], array( 'status' => 'rendering', 'render_error' => null, 'render_job_id' => null ) );

		$res = Binder_Render_Client::start_render( $row );

		if ( is_wp_error( $res ) ) {
			Binder_DB::update( $row['id'], array( 'status' => 'draft' ) );

			return new WP_Error( 'binder_service_unreachable', __( 'The design service could not be reached. Please try again.', 'prime-binder-designer' ), array( 'status' => 502 ) );
		}

		if ( 202 === $res['status'] && ! empty( $res['body']['job_id'] ) ) {
			Binder_DB::update( $row['id'], array( 'render_job_id' => sanitize_text_field( $res['body']['job_id'] ) ) );

			return rest_ensure_response( self::present( Binder_DB::get( $row['id'] ) ) );
		}

		// Refused before rendering: a hard block (422), a busy/limited service, or a bad request.
		$errors = $res['body']['errors'] ?? array();

		if ( 422 === $res['status'] ) {
			Binder_DB::update( $row['id'], array( 'status' => 'failed', 'render_error' => wp_json_encode( $errors ) ) );

			return new WP_Error( 'binder_validation_failed', __( 'This design cannot be printed as it is.', 'prime-binder-designer' ), array( 'status' => 422, 'errors' => $errors ) );
		}

		Binder_DB::update( $row['id'], array( 'status' => 'draft' ) );
		$http = in_array( $res['status'], array( 429, 503 ), true ) ? $res['status'] : 502;

		return new WP_Error( 'binder_service_busy', __( 'The design service is busy. Please try again in a moment.', 'prime-binder-designer' ), array( 'status' => $http, 'retry_after' => $res['retry'] ) );
	}

	/**
	 * GET /design/{id}/status: poll a render. If the callback has not arrived
	 * for a while, ask the service directly, so a lost callback cannot strand
	 * a customer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function status( WP_REST_Request $request ) {
		$row = Binder_DB::get( (int) $request['id'] );

		if ( 'rendering' === $row['status'] && ! empty( $row['render_job_id'] ) && time() - strtotime( $row['updated_at'] . ' UTC' ) > 45 ) {
			$job = Binder_Render_Client::job( $row['render_job_id'] );
			if ( ! is_wp_error( $job ) && 'ready' === ( $job['status'] ?? '' ) && ! empty( $job['outcome'] ) ) {
				self::finish_ready( $row, $job['outcome'] );
			} elseif ( ! is_wp_error( $job ) && 'failed' === ( $job['status'] ?? '' ) ) {
				self::finish_failed( $row, $job['errors'] ?? array(), $job['error'] ?? 'render_failed' );
			}
			$row = Binder_DB::get( $row['id'] );
		}

		return rest_ensure_response( self::present( $row ) );
	}

	/**
	 * POST /render-callback: the service reports a finished (or failed) render.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function render_callback( WP_REST_Request $request ) {
		$p   = (array) json_decode( $request->get_body(), true );
		$row = Binder_DB::get( (int) ( $p['design_id'] ?? 0 ) );

		if ( ! $row || empty( $p['job_id'] ) || ( $row['render_job_id'] && $row['render_job_id'] !== $p['job_id'] ) ) {
			return new WP_Error( 'binder_unknown_job', __( 'Unknown design or job.', 'prime-binder-designer' ), array( 'status' => 404 ) );
		}
		// A retried callback for a finished design is acknowledged, not applied twice.
		if ( 'rendering' !== $row['status'] ) {
			return rest_ensure_response( array( 'ok' => true, 'already' => true ) );
		}

		if ( 'ready' === ( $p['status'] ?? '' ) ) {
			$done = self::finish_ready( $row, $p );

			return is_wp_error( $done ) ? $done : rest_ensure_response( array( 'ok' => true ) );
		}

		self::finish_failed( $row, $p['errors'] ?? array(), (string) ( $p['error'] ?? 'render_failed' ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Fetch both PDFs into private storage and mark the design ready.
	 *
	 * @param array $row     Design row.
	 * @param array $outcome Service result: pdf_rgb_url, pdf_cmyk_url, warnings.
	 * @return true|WP_Error
	 */
	private static function finish_ready( array $row, array $outcome ) {
		foreach ( array( 'rgb' => 'pdf_rgb_url', 'cmyk' => 'pdf_cmyk_url' ) as $kind => $key ) {
			if ( empty( $outcome[ $key ] ) ) {
				self::finish_failed( $row, array(), 'missing_pdf' );
				return new WP_Error( 'binder_missing_pdf', __( 'The render result had no PDF.', 'prime-binder-designer' ), array( 'status' => 400 ) );
			}

			$tmp = Binder_Render_Client::download( $outcome[ $key ] );
			if ( is_wp_error( $tmp ) ) {
				// Leave the design 'rendering': the service retries the callback, and status() can recover it.
				return new WP_Error( 'binder_download_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
			}
			Binder_Files::adopt( $row['id'], $kind, $tmp );
		}

		$warnings = array_merge( (array) json_decode( (string) $row['validation_warnings'], true ), (array) ( $outcome['warnings'] ?? array() ) );

		Binder_DB::update(
			$row['id'],
			array(
				'status'              => 'ready',
				'pdf_url'             => Binder_Files::base_url( $row['id'], 'rgb' ),
				'pdf_cmyk_url'        => Binder_Files::base_url( $row['id'], 'cmyk' ),
				'validation_warnings' => wp_json_encode( $warnings ),
				'render_error'        => null,
			)
		);

		do_action( 'binder_design_ready', (int) $row['id'] );

		return true;
	}

	/**
	 * @param array  $row    Design row.
	 * @param array  $errors Issues from the service.
	 * @param string $code   Failure code.
	 */
	private static function finish_failed( array $row, array $errors, $code ) {
		Binder_DB::update(
			$row['id'],
			array(
				'status'       => 'failed',
				'render_error' => wp_json_encode( $errors ? $errors : array( array( 'code' => $code, 'severity' => 'error' ) ) ),
			)
		);

		do_action( 'binder_design_failed', (int) $row['id'], $code );
	}
}
