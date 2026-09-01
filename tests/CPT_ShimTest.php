<?php
/**
 * Tests for the CPT-args shim.
 *
 * @package ComicEaselREST
 */

use ComicEaselRest\CPT_Shim;

/**
 * @group shim
 */
class CPT_ShimTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Force the shim to register fresh for each test.
		remove_all_filters( 'register_comic_post_type_args' );
		remove_all_filters( 'register_chapters_taxonomy_args' );
		remove_all_filters( 'register_characters_taxonomy_args' );
		remove_all_filters( 'register_locations_taxonomy_args' );
	}

	public function test_filter_post_type_args_flips_show_in_rest_on_comic() {
		CPT_Shim::register();

		$args = apply_filters(
			'register_comic_post_type_args',
			array(
				'public'              => true,
				'show_in_rest'        => false,
				'rest_base'           => '',
				'rest_controller_class'=> '',
			),
			'comic'
		);

		$this->assertTrue( $args['show_in_rest'] );
		$this->assertSame( 'WP_REST_Posts_Controller', $args['rest_controller_class'] );
		$this->assertNotEmpty( $args['rest_base'] );
	}

	public function test_filter_post_type_args_is_noop_for_other_post_types() {
		CPT_Shim::register();

		$original = array(
			'public'       => true,
			'show_in_rest' => false,
		);
		$args     = apply_filters( 'register_comic_post_type_args', $original, 'post' );

		$this->assertSame( $original, $args );
	}

	public function test_filter_post_type_args_is_idempotent() {
		CPT_Shim::register();

		$already = array(
			'public'               => true,
			'show_in_rest'         => true,
			'rest_base'            => 'custom-rest-base',
			'rest_controller_class'=> 'My_Custom_Controller',
		);
		$args    = apply_filters( 'register_comic_post_type_args', $already, 'comic' );

		$this->assertSame( 'custom-rest-base', $args['rest_base'] );
		$this->assertSame( 'My_Custom_Controller', $args['rest_controller_class'] );
	}

	public function test_filter_taxonomy_args_flips_show_in_rest() {
		CPT_Shim::register();

		$args = apply_filters(
			'register_chapters_taxonomy_args',
			array(
				'public'       => true,
				'show_in_rest' => false,
			),
			'chapters'
		);

		$this->assertTrue( $args['show_in_rest'] );
		$this->assertSame( 'chapters', $args['rest_base'] );
		$this->assertSame( 'WP_REST_Terms_Controller', $args['rest_controller_class'] );
	}
}