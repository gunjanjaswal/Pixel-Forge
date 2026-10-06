<?php
/**
 * Plugin Name:       Pixel Forge
 * Plugin URI:        https://github.com/gunjanjaswal/Pixel-Forge
 * Description:       Bulk-convert your media library to WebP and AVIF with a live progress screen, then serve the next-gen files with a one-click rollback whenever you want the originals back.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Gunjan Jaswal
 * Author URI:        https://www.gunjanjaswal.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixel-forge
 *
 * @package PixelForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIXELFORGE_VERSION', '1.0.0' );
define( 'PIXELFORGE_FILE', __FILE__ );
define( 'PIXELFORGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELFORGE_URL', plugin_dir_url( __FILE__ ) );
define( 'PIXELFORGE_BASENAME', plugin_basename( __FILE__ ) );

// How many attachments to process per AJAX batch. AVIF encoding is slow, so keep it small.
if ( ! defined( 'PIXELFORGE_BATCH' ) ) {
	define( 'PIXELFORGE_BATCH', 3 );
}

require_once PIXELFORGE_DIR . 'includes/class-pixelforge-converter.php';
require_once PIXELFORGE_DIR . 'includes/class-pixelforge-process.php';
require_once PIXELFORGE_DIR . 'includes/class-pixelforge-serve.php';

if ( is_admin() ) {
	require_once PIXELFORGE_DIR . 'includes/class-pixelforge-admin.php';
}

add_action(
	'plugins_loaded',
	static function () {
		PixelForge_Process::init();
		PixelForge_Serve::init();
		if ( is_admin() ) {
			PixelForge_Admin::init();
		}
	}
);
