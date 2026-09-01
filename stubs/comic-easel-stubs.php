<?php
/**
 * Stub declarations for the comic-easel parent plugin.
 *
 * Loaded only by static analysis (Phan via `autoload_files`, Psalm via
 * `<stubs>`) so the analyzers can type-check this plugin without the parent
 * plugin installed. Deliberately free of an ABSPATH guard — analyzers load
 * this outside a WordPress request.
 *
 * @package ComicEaselREST
 */

/**
 * Return Comic Easel configuration.
 *
 * @param string $key Optional configuration key.
 * @return mixed
 */
function ceo_pluginfo( $key = '' ) { // NOSONAR: stub mirrors the parent comic-easel plugin's public signature; name and parameter must match for Phan/Psalm.
    return '';
}

/**
 * Read the autoload flag for a single option. Added in WordPress 6.6; the
 * php-stubs/wordpress-stubs package does not yet declare it, so we add it
 * here so Phan/Psalm can type-check our option-migration code.
 *
 * @param string $option Option name.
 * @return string|bool 'on' | 'off' | 'auto' on WP 6.6+; bool on older stubs.
 */
function wp_get_option_autoload( $option ) {
    return 'on';
}
