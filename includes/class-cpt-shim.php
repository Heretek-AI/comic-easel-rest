<?php
/**
 * CPT-args shim for Comic Easel.
 *
 * Flips `show_in_rest` on the existing `comic` CPT and the three taxonomies
 * (chapters, characters, locations) so they become reachable through
 * `/wp-json/wp/v2/...` automatically. Idempotent — re-applying the filter on
 * a CPT that already has show_in_rest set is a no-op.
 *
 * @package ComicEaselREST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace ComicEaselRest;

class CPT_Shim {

	/**
	 * Filter priority: we register at 99 so we run after comic-easel (which
	 * hooks `init` at the default priority 10) and after most third-party
	 * filters that may also touch show_in_rest.
	 */
	const FILTER_PRIORITY = 99;

	/**
	 * Register the four filters that flip show_in_rest on comic-easel's CPT
	 * and the three taxonomies.
	 */
	public static function register() {
		add_filter( 'register_comic_post_type_args',     array( __CLASS__, 'filter_post_type_args' ), self::FILTER_PRIORITY, 2 );
		add_filter( 'register_chapters_taxonomy_args',   array( __CLASS__, 'filter_taxonomy_args'  ), self::FILTER_PRIORITY, 2 );
		add_filter( 'register_characters_taxonomy_args', array( __CLASS__, 'filter_taxonomy_args'  ), self::FILTER_PRIORITY, 2 );
		add_filter( 'register_locations_taxonomy_args',  array( __CLASS__, 'filter_taxonomy_args'  ), self::FILTER_PRIORITY, 2 );
	}

	/**
	 * Flip show_in_rest on the comic CPT. We must preserve every other key
	 * comic-easel sets (labels, rewrite, supports, etc.).
	 *
	 * @param array  $args      The CPT args array.
	 * @param string $post_type The post type slug.
	 * @return array
	 */
	public static function filter_post_type_args( $args, $post_type ) {
		if ( 'comic' !== $post_type ) {
			return $args;
		}
		// Idempotency: if another plugin already enabled REST on this CPT,
		// don't clobber its rest_base or controller.
		if ( ! empty( $args['show_in_rest'] ) ) {
			return $args;
		}

		$rest_base = cer_resolve_comic_slug();

		$args['show_in_rest']          = true;
		$args['rest_base']             = $rest_base;
		$args['rest_controller_class'] = 'WP_REST_Posts_Controller';
		return $args;
	}

	/**
	 * Flip show_in_rest on a taxonomy registered by comic-easel.
	 *
	 * @param array  $args     The taxonomy args array.
	 * @param string $taxonomy  The taxonomy slug.
	 * @return array
	 */
	public static function filter_taxonomy_args( $args, $taxonomy ) {
		// Idempotency: skip if already exposed to REST.
		if ( ! empty( $args['show_in_rest'] ) ) {
			return $args;
		}

		$args['show_in_rest']          = true;
		$args['rest_base']             = $taxonomy;
		$args['rest_controller_class'] = 'WP_REST_Terms_Controller';
		return $args;
	}
}