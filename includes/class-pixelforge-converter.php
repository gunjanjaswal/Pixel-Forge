<?php
/**
 * Converts attachment images to next-gen formats (WebP, AVIF).
 *
 * Generated files sit next to the original with the format appended, for
 * example photo.jpg becomes photo.jpg.webp. Originals are never modified, and
 * every generated file is recorded in post meta so it can be removed cleanly.
 *
 * @package PixelForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PixelForge_Converter {

	const DONE_META  = '_pixelforge_done';
	const FILES_META = '_pixelforge_files';
	const BYTES_META = '_pixelforge_bytes';

	/**
	 * Which next-gen formats this server can actually write.
	 *
	 * @return array<string,bool> Keyed by 'webp' and 'avif'.
	 */
	public static function supported_formats() {
		return array(
			'webp' => (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'avif' => (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
		);
	}

	/**
	 * Mime type for a format extension.
	 *
	 * @param string $ext 'webp' or 'avif'.
	 * @return string
	 */
	public static function mime_for( $ext ) {
		return 'avif' === $ext ? 'image/avif' : 'image/webp';
	}

	/**
	 * Convert a single attachment's full image and every registered sub-size.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param string[] $formats       Formats to write, e.g. array( 'webp', 'avif' ).
	 * @param int      $quality       Encoder quality, 1-100.
	 * @return array{created:string[],bytes_source:int,bytes_webp:int,bytes_avif:int,errors:string[]}
	 */
	public static function convert_attachment( $attachment_id, $formats, $quality ) {
		$result = array(
			'created'      => array(),
			'bytes_source' => 0,
			'bytes_webp'   => 0,
			'bytes_avif'   => 0,
			'errors'       => array(),
		);

		$full = get_attached_file( $attachment_id );
		if ( ! $full || ! file_exists( $full ) ) {
			$result['errors'][] = __( 'Original file is missing.', 'pixel-forge' );
			update_post_meta( $attachment_id, self::DONE_META, PIXELFORGE_VERSION );
			return $result;
		}

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$dir     = trailingslashit( dirname( $full ) );

		// Full image plus every sub-size file.
		$sources = array( $full );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$sources[] = $dir . $size['file'];
				}
			}
		}
		$sources = array_unique( $sources );

		$recorded = (array) get_post_meta( $attachment_id, self::FILES_META, true );

		foreach ( $sources as $source ) {
			if ( ! file_exists( $source ) ) {
				continue;
			}

			$source_bytes = (int) filesize( $source );
			$source_used  = false;

			foreach ( $formats as $ext ) {
				$dest = $source . '.' . $ext;
				$rel  = ltrim( str_replace( $base, '', $dest ), '/' );

				if ( file_exists( $dest ) ) {
					if ( ! in_array( $rel, $recorded, true ) ) {
						$recorded[] = $rel;
					}
					continue;
				}

				$editor = wp_get_image_editor( $source );
				if ( is_wp_error( $editor ) ) {
					$result['errors'][] = $editor->get_error_message();
					continue;
				}

				$editor->set_quality( (int) $quality );
				$saved = $editor->save( $dest, self::mime_for( $ext ) );
				if ( is_wp_error( $saved ) ) {
					$result['errors'][] = $saved->get_error_message();
					continue;
				}

				if ( empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
					continue;
				}

				$rel_saved = ltrim( str_replace( $base, '', $saved['path'] ), '/' );
				if ( ! in_array( $rel_saved, $recorded, true ) ) {
					$recorded[] = $rel_saved;
				}
				$result['created'][] = $rel_saved;
				$source_used         = true;

				$out_bytes = (int) filesize( $saved['path'] );
				if ( 'avif' === $ext ) {
					$result['bytes_avif'] += $out_bytes;
				} else {
					$result['bytes_webp'] += $out_bytes;
				}
			}

			if ( $source_used ) {
				$result['bytes_source'] += $source_bytes;
			}
		}

		update_post_meta( $attachment_id, self::FILES_META, $recorded );
		update_post_meta( $attachment_id, self::DONE_META, PIXELFORGE_VERSION );

		// Store totals from disk so the Media Library column always has accurate
		// savings, even on a re-run where nothing new was written this pass.
		$totals = array( 'source' => 0, 'webp' => 0, 'avif' => 0 );
		foreach ( $sources as $source ) {
			if ( file_exists( $source ) ) {
				$totals['source'] += (int) filesize( $source );
			}
		}
		foreach ( $recorded as $rel ) {
			$path = $base . ltrim( (string) $rel, '/' );
			if ( ! file_exists( $path ) ) {
				continue;
			}
			if ( '.avif' === substr( $path, -5 ) ) {
				$totals['avif'] += (int) filesize( $path );
			} elseif ( '.webp' === substr( $path, -5 ) ) {
				$totals['webp'] += (int) filesize( $path );
			}
		}
		update_post_meta( $attachment_id, self::BYTES_META, $totals );

		return $result;
	}

	/**
	 * Delete every generated file recorded for an attachment and clear its state.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return int Number of files removed.
	 */
	public static function remove_attachment( $attachment_id ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		$files   = (array) get_post_meta( $attachment_id, self::FILES_META, true );
		$removed = 0;

		foreach ( $files as $rel ) {
			$path = $base . ltrim( (string) $rel, '/' );
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
				$removed++;
			}
		}

		delete_post_meta( $attachment_id, self::FILES_META );
		delete_post_meta( $attachment_id, self::DONE_META );
		delete_post_meta( $attachment_id, self::BYTES_META );

		return $removed;
	}
}
