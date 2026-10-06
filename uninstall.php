<?php
/**
 * Uninstall cleanup for Pixel Forge.
 *
 * Removes the plugin's options and tracking meta. Generated WebP/AVIF files are
 * left in place; use the Rollback button before deleting the plugin if you want
 * them removed too.
 *
 * @package PixelForge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pixelforge_settings' );
delete_option( 'pixelforge_stats' );

delete_post_meta_by_key( '_pixelforge_done' );
delete_post_meta_by_key( '_pixelforge_files' );
