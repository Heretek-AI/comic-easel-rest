<?php
/**
 * Helper functions for Comic Easel REST.
 *
 * Procedural helpers used by both the CPT shim and the REST controller.
 *
 * @package ComicEaselREST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the live comic CPT slug from the parent plugin's config. Falls
 * back to 'comic' if ceo_pluginfo() is missing or returns an empty value.
 *
 * @return string
 */
function cer_resolve_comic_slug() {
	if ( ! function_exists( 'ceo_pluginfo' ) ) {
		return 'comic';
	}
	$slug = ceo_pluginfo( 'custom_post_type_slug_name' );
	if ( ! is_string( $slug ) || '' === $slug ) {
		return 'comic';
	}
	return $slug;
}

/**
 * Whether Application Passwords are usable on this site.
 *
 * @return bool
 */
function cer_app_passwords_available() {
	if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
		return false;
	}
	return (bool) wp_is_application_passwords_available();
}

/**
 * Decode an image payload that may be:
 *   - a data URL (`data:image/png;base64,...`)
 *   - a remote URL (downloaded via wp_remote_get)
 *   - raw base64
 *
 * Returns an array `{ file_path, file_url, mime_type }` on success or a
 * WP_Error on failure.
 *
 * @param string $payload The raw payload from the request.
 * @return array|WP_Error
 */
function cer_decode_image_payload( $payload ) {
	if ( ! is_string( $payload ) || '' === $payload ) {
		return new WP_Error( 'cer_no_image', __( 'No image provided.', 'comic-easel-rest' ), array( 'status' => 400 ) );
	}

	if ( preg_match( '#^data:([^;]+);base64,(.+)$#', $payload, $m ) ) {
		$mime = $m[1];
		$bin  = base64_decode( $m[2], true );
		if ( false === $bin ) {
			return new WP_Error( 'cer_bad_base64', __( 'Image data is not valid base64.', 'comic-easel-rest' ), array( 'status' => 400 ) );
		}
	} elseif ( filter_var( $payload, FILTER_VALIDATE_URL ) ) {
		$resp = wp_remote_get( $payload, array( 'timeout' => 30 ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return new WP_Error( 'cer_remote_image_failed', __( 'Could not download image from URL.', 'comic-easel-rest' ), array( 'status' => 400 ) );
		}
		$bin  = wp_remote_retrieve_body( $resp );
		$mime = wp_remote_retrieve_header( $resp, 'content-type' );
		if ( ! is_string( $mime ) || '' === $mime ) {
			$mime = 'application/octet-stream';
		}
	} else {
		$bin  = base64_decode( $payload, true );
		$mime = 'application/octet-stream';
		if ( false === $bin ) {
			return new WP_Error( 'cer_bad_image', __( 'Image payload is neither a URL nor base64 data.', 'comic-easel-rest' ), array( 'status' => 400 ) );
		}
	}

	return cer_save_image_to_uploads( $bin, $mime );
}

/**
 * Write a binary image to the uploads directory and register it as a media
 * attachment. Returns `{ attachment_id, file_path }` or WP_Error.
 *
 * @param string $bin  Raw bytes.
 * @param string $mime MIME type.
 * @return array|WP_Error
 */
function cer_save_image_to_uploads( $bin, $mime ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'cer_upload_dir_failed', $upload['error'], array( 'status' => 500 ) );
	}

	$ext       = cer_mime_to_extension( $mime );
	$filename  = wp_unique_filename( $upload['path'], 'comic-' . $ext );
	$file_path = trailingslashit( $upload['path'] ) . $filename;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- binary payload.
	$written = file_put_contents( $file_path, $bin );
	if ( false === $written ) {
		return new WP_Error( 'cer_write_failed', __( 'Could not write image to uploads directory.', 'comic-easel-rest' ), array( 'status' => 500 ) );
	}

	$wp_filetype = wp_check_filetype( $filename, null );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $wp_filetype['type'] ? $wp_filetype['type'] : $mime,
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$file_path
	);
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
	wp_update_attachment_metadata( $attachment_id, $attach_data );

	return array(
		'attachment_id' => (int) $attachment_id,
		'file_path'     => $file_path,
		'mime_type'     => $mime,
	);
}

/**
 * Map a MIME type to a file extension.
 *
 * @param string $mime MIME type.
 * @return string Extension with leading dot.
 */
function cer_mime_to_extension( $mime ) {
	$mime = strtolower( trim( $mime ) );
	$map  = array(
		'image/jpeg' => '.jpg',
		'image/jpg'  => '.jpg',
		'image/png'  => '.png',
		'image/gif'  => '.gif',
		'image/webp' => '.webp',
		'image/avif' => '.avif',
	);
	if ( isset( $map[ $mime ] ) ) {
		return $map[ $mime ];
	}
	// Fallback: derive from MIME subtype.
	$parts = explode( '/', $mime );
	return '.' . end( $parts );
}

/**
 * Convenience wrapper for set_post_thumbnail + alt-text, used by the
 * endpoint callbacks.
 *
 * @param int    $post_id        The post to attach to.
 * @param int    $attachment_id  The attachment ID.
 * @param string $image_alt      Alt text, may be empty.
 */
function cer_attach_featured_image( $post_id, $attachment_id, $image_alt = '' ) {
	set_post_thumbnail( $post_id, $attachment_id );
	if ( is_string( $image_alt ) && '' !== $image_alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image_alt ) );
	}
}

/**
 * Assign chapter term IDs to a post. No-op if $chapter_ids is empty.
 *
 * @param int   $post_id     The post ID.
 * @param array $chapter_ids Array of term IDs.
 */
function cer_assign_chapters( $post_id, $chapter_ids ) {
	if ( ! is_array( $chapter_ids ) || empty( $chapter_ids ) ) {
		return;
	}
	$clean = array_filter( array_map( 'intval', $chapter_ids ) );
	if ( ! empty( $clean ) ) {
		wp_set_object_terms( $post_id, $clean, 'chapters' );
	}
}