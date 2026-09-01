<?php
/**
 * Settings and endpoint callbacks for Comic Easel REST.
 *
 * Owns the option whitelist and the six REST endpoint implementations.
 * Functions here are called from includes/class-rest-controller.php.
 *
 * @package ComicEaselREST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelist of plugin options exposed via the settings endpoint and stored
 * in the `comic-easel-rest-settings` option.
 */
const CER_OPTION_WHITELIST = array(
	'enable_cpt_args_shim',
	'enable_rest_namespace',
	'enable_settings_endpoint',
	'enable_bulk_import',
	'enable_throttle',
	'throttle_per_minute',
);

/**
 * Read a single plugin option with default fallback.
 *
 * @param string $key     Option key (must be in CER_OPTION_WHITELIST).
 * @param mixed  $default Default to return if the key is unset.
 * @return mixed
 */
function cer_get_option( $key, $default = null ) {
	$opts = get_option( CER_OPTION_KEY, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
}

/**
 * Update a single plugin option. Rejects non-whitelisted keys.
 *
 * @param string $key   Option key (must be in CER_OPTION_WHITELIST).
 * @param mixed  $value New value.
 * @return bool|WP_Error True on success, WP_Error on invalid key.
 */
function cer_update_option( $key, $value ) {
	if ( ! in_array( $key, CER_OPTION_WHITELIST, true ) ) {
		return new WP_Error(
			'cer_invalid_option',
			sprintf(
				/* translators: %s: option key */
				__( 'Option %s is not whitelisted.', 'comic-easel-rest' ),
				$key
			),
			array( 'status' => 400 )
		);
	}
	$opts         = get_option( CER_OPTION_KEY, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	$opts[ $key ] = $value;
	return update_option( CER_OPTION_KEY, $opts );
}

/**
 * GET /comic-easel/v1/settings — return whitelisted settings.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function cer_get_settings( WP_REST_Request $request ) {
	$opts = get_option( CER_OPTION_KEY, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	$public = array_intersect_key( $opts, array_flip( CER_OPTION_WHITELIST ) );
	return rest_ensure_response( $public );
}

/**
 * POST /comic-easel/v1/settings — update whitelisted settings.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function cer_update_settings( WP_REST_Request $request ) {
	$updates = array();
	foreach ( CER_OPTION_WHITELIST as $key ) {
		if ( $request->has_param( $key ) ) {
			$updates[ $key ] = $request->get_param( $key );
		}
	}
	if ( empty( $updates ) ) {
		return new WP_Error(
			'cer_no_settings',
			__( 'No recognised settings provided.', 'comic-easel-rest' ),
			array( 'status' => 400 )
		);
	}

	$opts         = get_option( CER_OPTION_KEY, array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	$opts         = array_merge( $opts, $updates );
	update_option( CER_OPTION_KEY, $opts );

	$public = array_intersect_key( $opts, array_flip( CER_OPTION_WHITELIST ) );
	return rest_ensure_response( $public );
}

/**
 * POST /comic-easel/v1/comics/with-thumbnail — create a comic with an
 * attached featured image.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function cer_create_with_thumbnail( WP_REST_Request $request ) {
	$decoded = cer_decode_image_payload( (string) $request->get_param( 'image' ) );
	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => cer_resolve_comic_slug(),
			// post_status is already defaulted ('draft') and sanitized by the
			// route schema, so a plain read is safe here.
			'post_status'  => (string) $request->get_param( 'post_status' ),
			'post_title'   => sanitize_post_field( 'post_title', $request->get_param( 'title' ), 0, 'db' ),
			'post_author'  => get_current_user_id(),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	cer_attach_featured_image( $post_id, $decoded['attachment_id'], (string) $request->get_param( 'image_alt' ) );
	cer_assign_chapters( $post_id, (array) $request->get_param( 'chapters' ) );

	return new WP_REST_Response(
		array(
			'id'            => (int) $post_id,
			'featured_media' => (int) $decoded['attachment_id'],
		),
		201
	);
}

/**
 * POST /comic-easel/v1/chapters — create a chapter term.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function cer_create_chapter( WP_REST_Request $request ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$args = array();
	if ( $request->has_param( 'description' ) ) {
		$args['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
	}
	if ( $request->has_param( 'parent' ) ) {
		$args['parent'] = (int) $request->get_param( 'parent' );
	}
	if ( $request->has_param( 'slug' ) ) {
		$args['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
	}

	$term = wp_insert_term(
		sanitize_text_field( (string) $request->get_param( 'name' ) ),
		'chapters',
		$args
	);
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	return new WP_REST_Response(
		array(
			'id'               => (int) $term['term_id'],
			'term_taxonomy_id' => (int) $term['term_taxonomy_id'],
		),
		201
	);
}

/**
 * POST /comic-easel/v1/comics/schedule — create a `future` comic.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function cer_schedule_comic( WP_REST_Request $request ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$valid = cer_schedule_validate( $request );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$timestamp     = $valid['timestamp'];
	$attachment_id = $valid['attachment_id'];

	$post_id = wp_insert_post(
		array(
			'post_type'     => cer_resolve_comic_slug(),
			'post_status'   => 'future',
			'post_title'    => sanitize_post_field( 'post_title', $request->get_param( 'title' ), 0, 'db' ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'post_author'   => get_current_user_id(),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( $attachment_id ) {
		cer_attach_featured_image( $post_id, $attachment_id, (string) $request->get_param( 'image_alt' ) );
	}
	cer_assign_chapters( $post_id, (array) $request->get_param( 'chapters' ) );

	return new WP_REST_Response(
		array(
			'id'           => (int) $post_id,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		),
		201
	);
}

/**
 * Validate a schedule request: parse the future UTC datetime, and if an
 * image was supplied, decode it. Returns either
 * `array( 'timestamp' => int, 'attachment_id' => int )` or a `WP_Error`
 * describing the failure (the date error or any image decode error).
 *
 * @param WP_REST_Request $request The request.
 * @return array{timestamp: int, attachment_id: int}|WP_Error
 */
function cer_schedule_validate( WP_REST_Request $request ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$date_gmt_raw = (string) $request->get_param( 'post_date_gmt' );
	$timestamp    = strtotime( $date_gmt_raw . ' UTC' );
	if ( ! $timestamp || $timestamp <= time() ) {
		return new WP_Error(
			'cer_invalid_date',
			__( 'post_date_gmt must be a future ISO 8601 datetime in UTC.', 'comic-easel-rest' ),
			array( 'status' => 400 )
		);
	}

	$attachment_id = 0;
	if ( $request->has_param( 'image' ) && '' !== (string) $request->get_param( 'image' ) ) {
		$decoded = cer_decode_image_payload( (string) $request->get_param( 'image' ) );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$attachment_id = (int) $decoded['attachment_id'];
	}

	return array(
		'timestamp'     => $timestamp,
		'attachment_id' => $attachment_id,
	);
}

/**
 * POST /comic-easel/v1/comics/bulk-import — create many comics under a
 * chapter.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function cer_bulk_import( WP_REST_Request $request ) { // NOSONAR: cer_* snake_case is the project naming convention.
	if ( ! cer_get_option( 'enable_bulk_import' ) ) {
		return new WP_Error(
			'cer_bulk_import_disabled',
			__( 'Bulk import is disabled in plugin settings.', 'comic-easel-rest' ),
			array( 'status' => 403 )
		);
	}

	$chapter_id = (int) $request->get_param( 'chapter_id' );
	if ( ! term_exists( $chapter_id, 'chapters' ) ) {
		return new WP_Error(
			'cer_invalid_chapter',
			__( 'Chapter does not exist.', 'comic-easel-rest' ),
			array( 'status' => 400 )
		);
	}

	$items   = (array) $request->get_param( 'items' );
	$results = array();
	$errors  = array();

	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$outcome = cer_bulk_import_item( $item, $i, $chapter_id );
		if ( is_wp_error( $outcome ) ) {
			$errors[] = array( 'index' => $i, 'error' => $outcome->get_error_code() );
			continue;
		}
		$results[] = $outcome;
	}

	return new WP_REST_Response(
		array(
			'chapter_id' => $chapter_id,
			'created'    => count( $results ),
			'failed'     => count( $errors ),
			'results'    => $results,
			'errors'     => $errors,
		),
		201
	);
}

/**
 * Process a single bulk-import item: validate, resolve image/schedule, insert,
 * attach image and chapters. Returns the created item's
 * `array( 'index', 'id', 'attachment_id' )` on success or a `WP_Error` on
 * failure. The caller converts the error code into the response `errors`
 * entry `{ index, error }`.
 *
 * @param array $item       The bulk item (already known to be an array).
 * @param int   $index      Item index in the request payload.
 * @param int   $chapter_id Chapter to assign.
 * @return array{index: int, id: int, attachment_id: int}|WP_Error
 */
function cer_bulk_import_item( array $item, $index, $chapter_id ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
	if ( '' === $title ) {
		return new WP_Error( 'missing_title' );
	}

	$attachment_id = cer_bulk_item_resolve_image( $item );
	if ( is_wp_error( $attachment_id ) ) {
		// Route the decode error through the same single error check as a
		// failed insert, so the function keeps exactly three returns. The `0`
		// reset also narrows the type for static analysis on the success path.
		$post_id       = $attachment_id;
		$attachment_id = 0;
	} else {
		list( $post_status, $date_gmt ) = cer_bulk_item_resolve_schedule( $item );
		$post_id = wp_insert_post(
			array(
				'post_type'     => cer_resolve_comic_slug(),
				'post_status'   => $post_status,
				'post_title'    => $title,
				'post_date_gmt' => $date_gmt,
				'post_author'   => get_current_user_id(),
			),
			true
		);
	}

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( $attachment_id ) {
		cer_attach_featured_image( $post_id, $attachment_id );
	}
	cer_assign_chapters( $post_id, array( $chapter_id ) );

	return array(
		'index'         => $index,
		'id'            => $post_id,
		'attachment_id' => $attachment_id,
	);
}

/**
 * Resolve the image payload for a bulk-import item. Returns the attachment
 * ID (int), `0` when no image was supplied, or a `WP_Error` when decoding
 * failed.
 *
 * @param array $item The bulk item.
 * @return int|WP_Error
 */
function cer_bulk_item_resolve_image( array $item ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$payload = '';
	if ( ! empty( $item['image_url'] ) ) {
		$payload = (string) $item['image_url'];
	} elseif ( ! empty( $item['image_data'] ) ) {
		$payload = (string) $item['image_data'];
	}
	if ( '' === $payload ) {
		return 0;
	}
	$decoded = cer_decode_image_payload( $payload );
	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}
	return (int) $decoded['attachment_id'];
}

/**
 * Resolve the post status and GMT date for a bulk-import item. Future dates
 * become `future` with the formatted `Y-m-d H:i:s` value; everything else
 * (empty, unparseable, or past) falls back to immediate `publish`.
 *
 * @param array $item The bulk item.
 * @return array{0: string, 1: string}
 */
function cer_bulk_item_resolve_schedule( array $item ) { // NOSONAR: cer_* snake_case is the project naming convention.
	$post_date_gmt = isset( $item['post_date_gmt'] ) ? (string) $item['post_date_gmt'] : '';
	if ( '' === $post_date_gmt ) {
		return array( 'publish', '' );
	}
	$ts = strtotime( $post_date_gmt . ' UTC' );
	if ( $ts && $ts > time() ) {
		return array( 'future', gmdate( 'Y-m-d H:i:s', $ts ) );
	}
	return array( 'publish', '' );
}
