<?php
/**
 * Settings, batch scanning, and the AJAX endpoints that drive the progress UI.
 *
 * @package PixelForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PixelForge_Process {

	const OPTION = 'pixelforge_settings';
	const STATS  = 'pixelforge_stats';

	/**
	 * Register the AJAX handlers.
	 */
	public static function init() {
		add_action( 'wp_ajax_pixelforge_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_pixelforge_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_pixelforge_convert_batch', array( __CLASS__, 'ajax_convert_batch' ) );
		add_action( 'wp_ajax_pixelforge_rollback_batch', array( __CLASS__, 'ajax_rollback_batch' ) );
		add_action( 'wp_ajax_pixelforge_convert_one', array( __CLASS__, 'ajax_convert_one' ) );
		add_action( 'wp_ajax_pixelforge_remove_one', array( __CLASS__, 'ajax_remove_one' ) );
	}

	/**
	 * Source mime types we convert.
	 *
	 * @return string[]
	 */
	public static function source_mimes() {
		return array( 'image/jpeg', 'image/png' );
	}

	/**
	 * Default settings, with formats defaulting to whatever the server supports.
	 *
	 * @return array{formats:string[],quality:int,serve:int}
	 */
	public static function defaults() {
		$supported = PixelForge_Converter::supported_formats();
		$formats   = array();
		foreach ( $supported as $ext => $ok ) {
			if ( $ok ) {
				$formats[] = $ext;
			}
		}
		return array(
			'formats' => $formats,
			'quality' => 82,
			'serve'   => 0,
		);
	}

	/**
	 * Current settings, merged with defaults and sanitized.
	 *
	 * @return array{formats:string[],quality:int,serve:int}
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return self::sanitize_settings( array_merge( self::defaults(), $saved ) );
	}

	/**
	 * Sanitize a settings array.
	 *
	 * @param array $raw Raw settings.
	 * @return array{formats:string[],quality:int,serve:int}
	 */
	public static function sanitize_settings( $raw ) {
		$supported = PixelForge_Converter::supported_formats();
		$formats   = array();
		if ( ! empty( $raw['formats'] ) && is_array( $raw['formats'] ) ) {
			foreach ( $raw['formats'] as $ext ) {
				$ext = sanitize_key( $ext );
				if ( isset( $supported[ $ext ] ) && $supported[ $ext ] && ! in_array( $ext, $formats, true ) ) {
					$formats[] = $ext;
				}
			}
		}

		$quality = isset( $raw['quality'] ) ? (int) $raw['quality'] : 82;
		$quality = max( 1, min( 100, $quality ) );

		return array(
			'formats' => $formats,
			'quality' => $quality,
			'serve'   => empty( $raw['serve'] ) ? 0 : 1,
		);
	}

	/**
	 * Count attachments still waiting to be converted.
	 *
	 * @return int
	 */
	public static function count_pending() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::source_mimes(),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin scan.
					array(
						'key'     => PixelForge_Converter::DONE_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Count attachments already converted.
	 *
	 * @return int
	 */
	public static function count_done() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::source_mimes(),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin scan.
					array(
						'key'     => PixelForge_Converter::DONE_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Running totals shown on the status card.
	 *
	 * @return array{images:int,files:int,bytes_source:int,bytes_webp:int,bytes_avif:int}
	 */
	public static function get_stats() {
		$stats = get_option( self::STATS, array() );
		return wp_parse_args(
			is_array( $stats ) ? $stats : array(),
			array(
				'images'       => 0,
				'files'        => 0,
				'bytes_source' => 0,
				'bytes_webp'   => 0,
				'bytes_avif'   => 0,
			)
		);
	}

	/**
	 * Reset the running totals.
	 */
	public static function reset_stats() {
		delete_option( self::STATS );
	}

	/**
	 * Verify the AJAX request and the user's capability.
	 */
	private static function guard() {
		check_ajax_referer( 'pixelforge', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pixel-forge' ) ), 403 );
		}
	}

	/**
	 * A snapshot of counts, stats, and settings for the UI.
	 *
	 * @return array
	 */
	private static function snapshot() {
		return array(
			'pending'   => self::count_pending(),
			'done'      => self::count_done(),
			'stats'     => self::get_stats(),
			'settings'  => self::get_settings(),
			'supported' => PixelForge_Converter::supported_formats(),
		);
	}

	/**
	 * AJAX: return the current snapshot.
	 */
	public static function ajax_status() {
		self::guard();
		wp_send_json_success( self::snapshot() );
	}

	/**
	 * AJAX: save settings.
	 */
	public static function ajax_save_settings() {
		self::guard();

		// Nonce and capability are verified in guard() above.
		$raw = array(
			'formats' => isset( $_POST['formats'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['formats'] ) ) : array(), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'quality' => isset( $_POST['quality'] ) ? (int) $_POST['quality'] : 82, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'serve'   => isset( $_POST['serve'] ) ? (int) $_POST['serve'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$settings = self::sanitize_settings( $raw );
		update_option( self::OPTION, $settings );

		wp_send_json_success( array( 'settings' => $settings ) );
	}

	/**
	 * AJAX: convert the next batch of attachments.
	 */
	public static function ajax_convert_batch() {
		self::guard();

		$settings = self::get_settings();
		if ( empty( $settings['formats'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one format to convert to.', 'pixel-forge' ) ) );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::source_mimes(),
				'fields'         => 'ids',
				'posts_per_page' => PIXELFORGE_BATCH,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin scan.
					array(
						'key'     => PixelForge_Converter::DONE_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$stats = self::get_stats();
		$log   = array();

		foreach ( $ids as $id ) {
			$res = PixelForge_Converter::convert_attachment( $id, $settings['formats'], $settings['quality'] );

			$stats['images']       += 1;
			$stats['files']        += count( $res['created'] );
			$stats['bytes_source'] += $res['bytes_source'];
			$stats['bytes_webp']   += $res['bytes_webp'];
			$stats['bytes_avif']   += $res['bytes_avif'];

			$log[] = array(
				'id'      => (int) $id,
				'title'   => get_the_title( $id ),
				'created' => count( $res['created'] ),
				'errors'  => $res['errors'],
			);
		}

		update_option( self::STATS, $stats );

		wp_send_json_success(
			array(
				'processed' => count( $ids ),
				'log'       => $log,
				'pending'   => self::count_pending(),
				'done'      => self::count_done(),
				'stats'     => $stats,
			)
		);
	}

	/**
	 * AJAX: remove generated files for the next batch of attachments.
	 */
	public static function ajax_rollback_batch() {
		self::guard();

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin cleanup.
					array(
						'key'     => PixelForge_Converter::FILES_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$removed = 0;
		foreach ( $ids as $id ) {
			$removed += PixelForge_Converter::remove_attachment( $id );
		}

		$remaining = count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin cleanup.
						array(
							'key'     => PixelForge_Converter::FILES_META,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);

		if ( 0 === $remaining ) {
			self::reset_stats();
		}

		wp_send_json_success(
			array(
				'removed'   => $removed,
				'remaining' => $remaining,
				'pending'   => self::count_pending(),
				'done'      => self::count_done(),
			)
		);
	}

	/**
	 * AJAX: convert a single attachment from the Media Library.
	 */
	public static function ajax_convert_one() {
		self::guard();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( ! $id || ! in_array( get_post_mime_type( $id ), self::source_mimes(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a convertible image.', 'pixel-forge' ) ) );
		}

		$settings = self::get_settings();
		if ( empty( $settings['formats'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one format in the Pixel Forge settings first.', 'pixel-forge' ) ) );
		}

		$was_done = (bool) get_post_meta( $id, PixelForge_Converter::DONE_META, true );
		$res      = PixelForge_Converter::convert_attachment( $id, $settings['formats'], $settings['quality'] );

		$stats = self::get_stats();
		if ( ! $was_done ) {
			$stats['images'] += 1;
		}
		$stats['files']        += count( $res['created'] );
		$stats['bytes_source'] += $res['bytes_source'];
		$stats['bytes_webp']   += $res['bytes_webp'];
		$stats['bytes_avif']   += $res['bytes_avif'];
		update_option( self::STATS, $stats );

		wp_send_json_success(
			array(
				'html'    => PixelForge_Admin::render_media_cell( $id ),
				'stats'   => $stats,
				'pending' => self::count_pending(),
				'done'    => self::count_done(),
				'errors'  => $res['errors'],
			)
		);
	}

	/**
	 * AJAX: remove the generated files for a single attachment.
	 */
	public static function ajax_remove_one() {
		self::guard();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image.', 'pixel-forge' ) ) );
		}

		$bytes    = get_post_meta( $id, PixelForge_Converter::BYTES_META, true );
		$files    = (array) get_post_meta( $id, PixelForge_Converter::FILES_META, true );
		$was_done = (bool) get_post_meta( $id, PixelForge_Converter::DONE_META, true );

		PixelForge_Converter::remove_attachment( $id );

		$stats = self::get_stats();
		if ( $was_done ) {
			$stats['images'] = max( 0, $stats['images'] - 1 );
		}
		$stats['files'] = max( 0, $stats['files'] - count( $files ) );
		if ( is_array( $bytes ) ) {
			$stats['bytes_source'] = max( 0, $stats['bytes_source'] - ( isset( $bytes['source'] ) ? (int) $bytes['source'] : 0 ) );
			$stats['bytes_webp']   = max( 0, $stats['bytes_webp'] - ( isset( $bytes['webp'] ) ? (int) $bytes['webp'] : 0 ) );
			$stats['bytes_avif']   = max( 0, $stats['bytes_avif'] - ( isset( $bytes['avif'] ) ? (int) $bytes['avif'] : 0 ) );
		}
		update_option( self::STATS, $stats );

		wp_send_json_success(
			array(
				'html'    => PixelForge_Admin::render_media_cell( $id ),
				'stats'   => $stats,
				'pending' => self::count_pending(),
				'done'    => self::count_done(),
			)
		);
	}
}
