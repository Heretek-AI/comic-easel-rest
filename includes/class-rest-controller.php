<?php
/**
 * REST controller for Comic Easel REST.
 *
 * Registers six endpoints under the `comic-easel/v1` namespace. Callbacks
 * delegate to procedural `cer_*` functions in functions/settings.php — the
 * WooCommerce-style split where OOP handles route registration and
 * procedural handles business logic.
 *
 * @package ComicEaselREST
 */

namespace ComicEaselRest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Controller extends WP_REST_Controller {

	/**
	 * Constructor: set the namespace and the rest_base used for the main
	 * `comics` collection endpoint.
	 */
	public function __construct() {
		$this->namespace = 'comic-easel/v1';
		$this->rest_base = 'comics';
	}

	/**
	 * Register all six routes. Overrides the parent's instance method, so call
	 * it on an instance (see the rest_api_init hook in comic-easel-rest.php).
	 */
	public function register_routes() {
		$this->register_endpoint_with_thumbnail();
		$this->register_endpoint_chapter();
		$this->register_endpoint_schedule();
		$this->register_endpoint_settings();
		$this->register_endpoint_bulk_import();
	}

	/**
	 * POST /comic-easel/v1/comics/with-thumbnail — create a comic with a
	 * featured image in one request. The image is supplied as base64 (with
	 * or without a `data:image/...;base64,` prefix) or as a URL.
	 */
	private function register_endpoint_with_thumbnail() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/with-thumbnail',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'route_with_thumbnail' ),
				'permission_callback' => array( $this, 'permissions_check_create' ),
				'args'                => array(
					'title'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'image'       => array(
						'required' => true,
						'type'     => 'string',
					),
					'chapters'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'post_status' => array(
						'type'              => 'string',
						'default'           => 'draft',
						'sanitize_callback' => 'sanitize_key',
					),
					'image_alt'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * POST /comic-easel/v1/chapters — create a chapter term.
	 */
	private function register_endpoint_chapter() {
		register_rest_route(
			$this->namespace,
			'/chapters',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'route_chapter' ),
				'permission_callback' => array( $this, 'permissions_check_create' ),
				'args'                => array(
					'name'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'parent'      => array(
						'type' => 'integer',
					),
					'slug'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * POST /comic-easel/v1/comics/schedule — schedule a comic for future
	 * publish. `post_date_gmt` must be ISO 8601 in UTC and strictly in the
	 * future.
	 */
	private function register_endpoint_schedule() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/schedule',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'route_schedule' ),
				'permission_callback' => array( $this, 'permissions_check_publish' ),
				'args'                => array(
					'title'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_date_gmt' => array(
						'required' => true,
						'type'     => 'string',
					),
					'image'         => array(
						'type' => 'string',
					),
					'chapters'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'image_alt'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /comic-easel/v1/settings — read whitelisted plugin options.
	 * POST /comic-easel/v1/settings — update whitelisted plugin options
	 * (requires manage_options).
	 */
	private function register_endpoint_settings() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'route_settings_read' ),
					'permission_callback' => array( $this, 'permissions_check_read' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'route_settings_update' ),
					'permission_callback' => array( $this, 'permissions_check_manage_options' ),
					'args'                => array(
						'enable_cpt_args_shim'     => array( 'type' => 'boolean' ),
						'enable_rest_namespace'    => array( 'type' => 'boolean' ),
						'enable_settings_endpoint' => array( 'type' => 'boolean' ),
						'enable_bulk_import'       => array( 'type' => 'boolean' ),
						'enable_throttle'          => array( 'type' => 'boolean' ),
						'throttle_per_minute'      => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * POST /comic-easel/v1/comics/bulk-import — create many comics under a
	 * chapter in one request.
	 */
	private function register_endpoint_bulk_import() {
		if ( ! cer_get_option( 'enable_bulk_import' ) ) {
			return;
		}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'route_bulk_import' ),
				'permission_callback' => array( $this, 'permissions_check_publish' ),
				'args'                => array(
					'chapter_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'items'      => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'title'         => array( 'type' => 'string' ),
								'image_url'     => array( 'type' => 'string' ),
								'image_data'    => array( 'type' => 'string' ),
								'post_date_gmt' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);
	}

	// ── Permission callbacks ─────────────────────────────────────────────

	public function permissions_check_read( $request ) {
		return current_user_can( 'edit_posts' );
	}

	public function permissions_check_create( $request ) {
		return current_user_can( 'edit_posts' ) && current_user_can( 'upload_files' );
	}

	public function permissions_check_publish( $request ) {
		return current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
	}

	public function permissions_check_manage_options( $request ) {
		return current_user_can( 'manage_options' );
	}

	// ── Route callbacks (delegated to procedural functions) ──────────────

	public function route_with_thumbnail( $request ) {
		return cer_create_with_thumbnail( $request );
	}

	public function route_chapter( $request ) {
		return cer_create_chapter( $request );
	}

	public function route_schedule( $request ) {
		return cer_schedule_comic( $request );
	}

	public function route_settings_read( $request ) {
		return cer_get_settings( $request );
	}

	public function route_settings_update( $request ) {
		return cer_update_settings( $request );
	}

	public function route_bulk_import( $request ) {
		return cer_bulk_import( $request );
	}
}