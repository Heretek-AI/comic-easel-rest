<?php
/**
 * Tests for the REST controller and endpoints.
 *
 * @package ComicEaselREST
 */

/**
 * @group rest
 */
class Tests_REST_Controller extends WP_Test_REST_TestCase {

	/**
	 * @var int Author user ID.
	 */
	protected static $author_id;

	/**
	 * @var int Editor user ID.
	 */
	protected static $editor_id;

	/**
	 * @var int Subscriber user ID.
	 */
	protected static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$author_id     = $factory->user->create( array( 'role' => 'author' ) );
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();
		// The companion needs to be loaded once per process; the WP test
		// suite handles plugin loading, so we only need to make sure the
		// rest_api_init hook fires.
		do_action( 'rest_api_init' );
	}

	// ── Settings endpoint ────────────────────────────────────────────────

	public function test_settings_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/comic-easel/v1/settings', $routes );
	}

	public function test_settings_get_returns_whitelisted_keys_for_author() {
		wp_set_current_user( self::$author_id );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/comic-easel/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'enable_cpt_args_shim', $data );
		$this->assertArrayHasKey( 'throttle_per_minute', $data );
		// No spurious keys.
		$this->assertArrayNotHasKey( 'arbitrary', $data );
	}

	public function test_settings_post_requires_manage_options() {
		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/settings' );
		$request->set_body_params( array( 'enable_cpt_args_shim' => false ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_settings_post_rejects_unknown_keys() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/settings' );
		$request->set_body_params( array( 'not_a_real_setting' => 'value' ) );
		$response = rest_do_request( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'cer_no_settings', $response->get_error_code() );
	}

	public function test_settings_post_updates_whitelisted_keys() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/settings' );
		$request->set_body_params( array( 'throttle_per_minute' => 120 ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 120, $data['throttle_per_minute'] );
	}

	// ── Chapter endpoint ─────────────────────────────────────────────────

	public function test_chapter_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/comic-easel/v1/chapters', $routes );
	}

	public function test_chapter_create_succeeds_for_author() {
		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/chapters' );
		$request->set_body_params( array(
			'name'        => 'Chapter One',
			'description' => 'First chapter',
		) );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertGreaterThan( 0, $data['id'] );
	}

	public function test_chapter_create_rejects_subscriber() {
		wp_set_current_user( self::$subscriber_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/chapters' );
		$request->set_body_params( array( 'name' => 'Forbidden' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// ── Schedule endpoint ────────────────────────────────────────────────

	public function test_schedule_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/comic-easel/v1/comics/schedule', $routes );
	}

	public function test_schedule_rejects_past_dates() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/comics/schedule' );
		$request->set_body_params( array(
			'title'         => 'Late Page',
			'post_date_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
		) );
		$response = rest_do_request( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'cer_invalid_date', $response->get_error_code() );
	}

	// ── Bulk-import endpoint ─────────────────────────────────────────────

	public function test_bulk_import_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/comic-easel/v1/comics/bulk-import', $routes );
	}

	public function test_bulk_import_rejects_invalid_chapter() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/comics/bulk-import' );
		$request->set_body_params( array(
			'chapter_id' => 99999,
			'items'      => array( array( 'title' => 'X' ) ),
		) );
		$response = rest_do_request( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'cer_invalid_chapter', $response->get_error_code() );
	}

	// ── With-thumbnail endpoint ──────────────────────────────────────────

	public function test_with_thumbnail_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/comic-easel/v1/comics/with-thumbnail', $routes );
	}

	public function test_with_thumbnail_rejects_missing_image() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/comic-easel/v1/comics/with-thumbnail' );
		$request->set_body_params( array( 'title' => 'No Image Here' ) );
		$response = rest_do_request( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'cer_no_image', $response->get_error_code() );
	}
}