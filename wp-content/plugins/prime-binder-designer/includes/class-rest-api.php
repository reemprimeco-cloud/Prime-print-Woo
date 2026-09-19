<?php
/**
 * REST endpoints, namespace binder/v1 (§3.3).
 *
 * STEP 1 STATE: the design routes are stubs that return mock data and touch no
 * state. GET /template/{template} and its overlay route are real, because the
 * editor needs them and there is nothing to mock. Steps 4 and 6 replace the
 * stubs with the real handlers, keeping the routes and argument schemas below.
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
			'session_token' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z0-9-]{16,64}$',
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

		// ---- Stubs (Step 1) — mock data, no state ----------------------------

		register_rest_route(
			self::NAMESPACE,
			'/design',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stub_save_design' ),
				'permission_callback' => '__return_true', // STUB. Real ownership check (session_token) arrives in Step 4.
				'args'                => self::design_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/design/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'stub_get_design' ),
				'permission_callback' => '__return_true', // STUB.
				'args'                => $id_arg,
			)
		);

		foreach ( array(
			'preview'  => 'stub_preview',
			'finalize' => 'stub_finalize',
		) as $action => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/design/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => '__return_true', // STUB.
					'args'                => $id_arg,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/design/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'stub_status' ),
				'permission_callback' => '__return_true', // STUB.
				'args'                => $id_arg,
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
		$spec     = Binder_Templates::spec( $template );

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

	// ---- Stubs ---------------------------------------------------------------

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stub_save_design( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mock'     => true,
				'id'       => 1,
				'status'   => 'draft',
				'template' => $request['template'],
				'mode'     => $request['mode'],
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stub_get_design( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mock'        => true,
				'id'          => (int) $request['id'],
				'status'      => 'draft',
				'template'    => 'binder_outer',
				'mode'        => 'upload',
				'design_json' => new stdClass(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stub_preview( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mock'        => true,
				'id'          => (int) $request['id'],
				'preview_url' => null,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stub_finalize( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mock'      => true,
				'id'        => (int) $request['id'],
				'design_id' => (int) $request['id'],
				'status'    => 'rendering',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stub_status( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'mock'         => true,
				'id'           => (int) $request['id'],
				'status'       => 'ready',
				'pdf_cmyk_url' => null,
			)
		);
	}
}
