<?php
/**
 * Tests for cer_force_classic_editor_for_comic() — the use_block_editor_for_post_type
 * filter that forces the classic editor for the comic CPT.
 *
 * @package ComicEaselREST
 */

/**
 * @group editor
 */
class ClassicEditorForComicTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// The companion plugin already registers the filter during
		// plugins_loaded; the WP test bootstrap loads it via active_plugins.
		// Each test starts from a clean filter state.
		remove_all_filters( 'use_block_editor_for_post_type' );
		add_filter( 'use_block_editor_for_post_type', 'cer_force_classic_editor_for_comic', 10, 2 );
	}

	public function test_filter_disables_block_editor_for_comic_cpt() {
		$result = apply_filters( 'use_block_editor_for_post_type', true, 'comic' );
		$this->assertFalse( $result );
	}

	public function test_filter_disables_block_editor_when_block_editor_was_already_disabled() {
		// Pass-through still wins for non-comic post types; for comic we
		// always return false regardless of the incoming value.
		$result = apply_filters( 'use_block_editor_for_post_type', false, 'comic' );
		$this->assertFalse( $result );
	}

	public function test_filter_passes_through_for_unrelated_post_types() {
		$result = apply_filters( 'use_block_editor_for_post_type', true, 'post' );
		$this->assertTrue( $result );

		$result = apply_filters( 'use_block_editor_for_post_type', false, 'page' );
		$this->assertFalse( $result );
	}

	public function test_filter_respects_comic_slug_option_override() {
		// The plugin reads the slug from ceo_pluginfo('custom_post_type_slug_name').
		// The parent plugin stores it in a global rather than exposing a
		// filter, so we set the global directly to exercise the non-default
		// slug path.
		global $ceo_pluginfo;
		$original = isset( $ceo_pluginfo ) ? $ceo_pluginfo : null;
		$ceo_pluginfo = array( 'custom_post_type_slug_name' => 'webcomic' );

		$result = apply_filters( 'use_block_editor_for_post_type', true, 'webcomic' );
		$this->assertFalse( $result );

		// The default 'comic' slug no longer matches once overridden — the
		// filter must not affect it.
		$result = apply_filters( 'use_block_editor_for_post_type', true, 'comic' );
		$this->assertTrue( $result );

		// Restore.
		$ceo_pluginfo = $original;
	}

	public function test_filter_falls_back_to_admin_screen_when_post_type_arg_is_empty() {
		// Simulate a WP version that passes only one argument to the filter.
		// We can't easily change the filter signature mid-test, so emulate
		// the legacy path by calling the function directly with no
		// $post_type and checking it consults get_current_screen().
		set_current_screen( 'edit-comic' );

		$result = cer_force_classic_editor_for_comic( true );
		$this->assertFalse( $result );

		// A non-comic screen should leave the value untouched.
		set_current_screen( 'edit-post' );
		$result = cer_force_classic_editor_for_comic( true );
		$this->assertTrue( $result );

		// Reset for subsequent tests.
		set_current_screen( 'front' );
	}
}