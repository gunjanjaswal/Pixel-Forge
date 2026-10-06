<?php
/**
 * Serves the generated next-gen files on the front end.
 *
 * When serving is on, each <img> that has a matching .avif/.webp sibling on
 * disk is wrapped in a <picture> element. The browser picks the first source
 * it can decode and falls back to the original <img> otherwise, so nothing
 * breaks if a sibling is missing.
 *
 * @package PixelForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PixelForge_Serve {

	/**
	 * Hook the output filters when serving is enabled.
	 */
	public static function init() {
		if ( is_admin() ) {
			return;
		}

		$settings = PixelForge_Process::get_settings();
		if ( empty( $settings['serve'] ) ) {
			return;
		}

		add_filter( 'the_content', array( __CLASS__, 'rewrite' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'rewrite' ), 20 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'rewrite' ), 20 );
	}

	/**
	 * Wrap convertible <img> tags in a <picture> with next-gen sources.
	 *
	 * @param string $html HTML possibly containing <img> tags.
	 * @return string
	 */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		if ( is_feed() ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			array( __CLASS__, 'rewrite_tag' ),
			$html
		);
	}

	/**
	 * Build a <picture> wrapper for one matched <img> tag.
	 *
	 * @param array $m Regex match; $m[0] is the full <img> tag.
	 * @return string
	 */
	private static function rewrite_tag( $m ) {
		$tag = $m[0];

		if ( ! preg_match( '/\bsrc=(["\'])(.*?)\1/i', $tag, $src_match ) ) {
			return $tag;
		}
		$src = $src_match[2];

		$srcset = '';
		if ( preg_match( '/\bsrcset=(["\'])(.*?)\1/i', $tag, $srcset_match ) ) {
			$srcset = $srcset_match[2];
		}

		$sources = '';
		foreach ( array( 'avif', 'webp' ) as $ext ) {
			$set = self::build_srcset( $src, $srcset, $ext );
			if ( '' !== $set ) {
				$sources .= '<source type="' . esc_attr( PixelForge_Converter::mime_for( $ext ) ) . '" srcset="' . esc_attr( $set ) . '">';
			}
		}

		if ( '' === $sources ) {
			return $tag;
		}

		return '<picture>' . $sources . $tag . '</picture>';
	}

	/**
	 * Build a srcset string of next-gen siblings that actually exist on disk.
	 *
	 * @param string $src    The img src URL.
	 * @param string $srcset The img srcset, if any.
	 * @param string $ext    'avif' or 'webp'.
	 * @return string Empty when no sibling exists for this format.
	 */
	private static function build_srcset( $src, $srcset, $ext ) {
		$candidates = array();

		if ( '' !== $srcset ) {
			foreach ( explode( ',', $srcset ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$bits       = preg_split( '/\s+/', $part, 2 );
				$descriptor = isset( $bits[1] ) ? ' ' . $bits[1] : '';
				$candidates[] = array( $bits[0], $descriptor );
			}
		} else {
			$candidates[] = array( $src, '' );
		}

		$out = array();
		foreach ( $candidates as $cand ) {
			$sibling = self::sibling_url( $cand[0], $ext );
			if ( $sibling ) {
				$out[] = $sibling . $cand[1];
			}
		}

		return implode( ', ', $out );
	}

	/**
	 * Return the sibling URL for a format if the file exists under uploads.
	 *
	 * @param string $url An image URL.
	 * @param string $ext 'avif' or 'webp'.
	 * @return string|false
	 */
	private static function sibling_url( $url, $ext ) {
		static $base_url = null;
		static $base_dir = null;
		if ( null === $base_url ) {
			$uploads  = wp_get_upload_dir();
			$base_url = trailingslashit( $uploads['baseurl'] );
			$base_dir = trailingslashit( $uploads['basedir'] );
		}

		// Strip any query string before matching the file on disk.
		$clean = preg_replace( '/[?#].*$/', '', $url );
		if ( 0 !== strpos( $clean, $base_url ) ) {
			return false;
		}

		$rel  = substr( $clean, strlen( $base_url ) );
		$path = $base_dir . $rel . '.' . $ext;
		if ( ! file_exists( $path ) ) {
			return false;
		}

		return $clean . '.' . $ext;
	}
}
