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

        // Autoload should be enabled so the option is loaded each request.
        $this->assertTrue( cer_is_option_autoload_yes( CER_OPTION_KEY ) );
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
        $this->assertTrue( cer_is_option_autoload_yes( CER_OPTION_KEY ) );
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

        $this->assertFalse( cer_is_option_autoload_yes( CER_OPTION_KEY ) );

        cer_init_option_defaults();

        // Values should be untouched.
        $this->assertSame( $matching, get_option( CER_OPTION_KEY ) );
        // But autoload should now be enabled.
        $this->assertTrue( cer_is_option_autoload_yes( CER_OPTION_KEY ) );
    }
}

/**
 * Return whether an option has autoload enabled.
 *
 * The wp_options.autoload column stores the value in two flavours depending
 * on WP version: pre-6.7 it is 'yes' / 'no'; from 6.7 onward it is 'on' /
 * 'off' (with 'auto' as a third value). Normalise both to a boolean.
 */
function cer_is_option_autoload_yes( $option ) { // NOSONAR: cer_* snake_case is the project naming convention.
    global $wpdb;
    // WP 6.6+ exposes wp_get_option_autoload(), which returns the value in
    // the new normalised form ('on' / 'off' / 'auto') regardless of what is
    // stored in the column. 'auto' means "autoload but skip if not used",
    // which is still good enough for our migration's purpose.
    if ( function_exists( 'wp_get_option_autoload' ) ) {
        $value = wp_get_option_autoload( $option );
        return 'on' === $value || 'auto' === $value;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            $option
        )
    );
    if ( ! $row ) {
        return false;
    }
    // Pre-6.7 stored 'yes' / 'no'. The bug we're fixing predates the
    // normalisation, so we have to handle both spellings.
    return in_array( $row->autoload, array( 'yes', 'on' ), true );
}
