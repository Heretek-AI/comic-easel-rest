<?php
/**
 * Tests for cer_init_option_defaults() — the option-migration helper.
 *
 * @package ComicEaselREST
 */

/**
 * @group defaults
 */
class OptionDefaultsTest extends WP_UnitTestCase {

	/**
	 * Drop the option between tests so each one starts from a clean state.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( CER_OPTION_KEY );
	}

	public function tear_down() {
		delete_option( CER_OPTION_KEY );
		parent::tear_down();
	}

	public function test_fresh_install_creates_option_with_defaults_and_autoload_yes() {
		cer_init_option_defaults();

		$value = get_option( CER_OPTION_KEY );
		$this->assertIsArray( $value );
		$this->assertTrue( $value['enable_cpt_args_shim'] );
		$this->assertSame( 60, $value['throttle_per_minute'] );

		// Autoload should be 'yes' / 'on' so the option is loaded each request.
		$this->assertSame( 'on', cer_get_option_autoload( CER_OPTION_KEY ) );
	}

	public function test_migrates_existing_partial_values_and_sets_autoload_yes() {
		// Pre-existing option with only one key set, no autoload arg.
		update_option( CER_OPTION_KEY, array( 'throttle_per_minute' => 120 ) );

		cer_init_option_defaults();

		$value = get_option( CER_OPTION_KEY );
		// Existing value preserved.
		$this->assertSame( 120, $value['throttle_per_minute'] );
		// Missing defaults filled in.
		$this->assertTrue( $value['enable_cpt_args_shim'] );
		$this->assertSame( 'on', cer_get_option_autoload( CER_OPTION_KEY ) );
	}

	public function test_equal_values_with_autoload_off_still_flips_autoload() {
		// Reproduce the exact bug: existing values already match defaults,
		// but the option was created without autoload (older WP or older code).
		$matching = array(
			'enable_cpt_args_shim'     => true,
			'enable_rest_namespace'    => true,
			'enable_settings_endpoint' => true,
			'enable_bulk_import'       => true,
			'enable_throttle'          => true,
			'throttle_per_minute'      => 60,
		);
		update_option( CER_OPTION_KEY, $matching, false ); // autoload = no

		$this->assertSame( 'off', cer_get_option_autoload( CER_OPTION_KEY ) );

		cer_init_option_defaults();

		// Values should be untouched.
		$this->assertSame( $matching, get_option( CER_OPTION_KEY ) );
		// But autoload should now be 'yes'.
		$this->assertSame( 'on', cer_get_option_autoload( CER_OPTION_KEY ) );
	}
}

/**
 * Return the autoload value for an option ('on', 'off', or 'auto').
 * Uses the WP 6.4+ helper when available, falls back to a direct query.
 */
function cer_get_option_autoload( $option ) {
	global $wpdb;
	if ( function_exists( 'wp_get_option_autoload' ) ) {
		// The WP helper is loosely typed; normalise to a known set.
		$value = wp_get_option_autoload( $option );
		if ( true === $value ) {
			return 'on';
		}
		if ( false === $value ) {
			return 'off';
		}
		return (string) $value;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
			$option
		)
	);
	return $row ? (string) $row->autoload : '';
}
