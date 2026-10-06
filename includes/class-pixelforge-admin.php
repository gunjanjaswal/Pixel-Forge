<?php
/**
 * Admin screen under Media: settings, the conversion progress UI, and rollback.
 *
 * @package PixelForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PixelForge_Admin {

	const MENU_SLUG = 'pixel-forge';

	/**
	 * @var string Page hook suffix, used to scope asset loading.
	 */
	private static $hook = '';

	/**
	 * Hook the admin screen, assets, and plugin-row links.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . PIXELFORGE_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
	}

	/**
	 * Add the screen under the Media menu.
	 */
	public static function add_menu() {
		self::$hook = add_submenu_page(
			'upload.php',
			__( 'Pixel Forge', 'pixel-forge' ),
			__( 'Pixel Forge', 'pixel-forge' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the stylesheet and script only on our screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( 'upload.php' === $hook ) {
			if ( current_user_can( 'manage_options' ) ) {
				self::enqueue_media();
			}
			return;
		}
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style(
			'pixelforge',
			PIXELFORGE_URL . 'admin/css/admin.css',
			array(),
			PIXELFORGE_VERSION
		);

		wp_enqueue_script(
			'pixelforge',
			PIXELFORGE_URL . 'admin/js/pixelforge.js',
			array(),
			PIXELFORGE_VERSION,
			true
		);

		wp_localize_script(
			'pixelforge',
			'PixelForge',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pixelforge' ),
				'batch'   => PIXELFORGE_BATCH,
				'i18n'    => array(
					'converting'  => __( 'Converting…', 'pixel-forge' ),
					'done'        => __( 'All done.', 'pixel-forge' ),
					'stopped'     => __( 'Stopped.', 'pixel-forge' ),
					'saved'       => __( 'Settings saved.', 'pixel-forge' ),
					'rollbackRun' => __( 'Removing generated files…', 'pixel-forge' ),
					'rollbackEnd' => __( 'All generated files removed.', 'pixel-forge' ),
					'confirmRoll' => __( 'Delete every WebP and AVIF file this plugin generated? Your original images are not touched.', 'pixel-forge' ),
					'errorNo'     => __( 'Something went wrong. Please try again.', 'pixel-forge' ),
				),
			)
		);
	}

	/**
	 * Load the small script that powers the Media Library column actions.
	 */
	private static function enqueue_media() {
		wp_enqueue_script(
			'pixelforge-media',
			PIXELFORGE_URL . 'admin/js/media.js',
			array(),
			PIXELFORGE_VERSION,
			true
		);
		wp_localize_script(
			'pixelforge-media',
			'PixelForgeMedia',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pixelforge' ),
				'i18n'    => array(
					'working' => __( 'Working…', 'pixel-forge' ),
					'error'   => __( 'Failed. Please try again.', 'pixel-forge' ),
					'confirm' => __( 'Remove the generated files for this image? The original is kept.', 'pixel-forge' ),
				),
			)
		);
	}

	/**
	 * Add a "Next-gen" column to the Media Library list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function media_column( $columns ) {
		$columns['pixelforge'] = __( 'Next-gen', 'pixel-forge' );
		return $columns;
	}

	/**
	 * Render the "Next-gen" column cell.
	 *
	 * @param string $column        Column name.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function media_column_content( $column, $attachment_id ) {
		if ( 'pixelforge' !== $column ) {
			return;
		}
		$allowed = array(
			'span' => array(
				'class'   => array(),
				'data-id' => array(),
			),
			'a'    => array(
				'href'    => array(),
				'class'   => array(),
				'data-id' => array(),
			),
		);
		echo wp_kses( self::render_media_cell( (int) $attachment_id ), $allowed );
	}

	/**
	 * Build the HTML for one attachment's next-gen status.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function render_media_cell( $attachment_id ) {
		$id  = absint( $attachment_id );
		$can = current_user_can( 'manage_options' );

		if ( ! in_array( get_post_mime_type( $id ), PixelForge_Process::source_mimes(), true ) ) {
			return '<span class="pf-cell" data-id="' . $id . '">&#8212;</span>';
		}

		$supported = PixelForge_Converter::supported_formats();
		if ( ! $supported['webp'] && ! $supported['avif'] ) {
			return '<span class="pf-cell" data-id="' . $id . '">&#8212;</span>';
		}

		$bytes = get_post_meta( $id, PixelForge_Converter::BYTES_META, true );
		if ( is_array( $bytes ) && ! empty( $bytes['source'] ) ) {
			$parts = array();
			if ( ! empty( $bytes['webp'] ) ) {
				$parts[] = 'WebP &#8722;' . self::pct( $bytes['source'], $bytes['webp'] ) . '%';
			}
			if ( ! empty( $bytes['avif'] ) ) {
				$parts[] = 'AVIF &#8722;' . self::pct( $bytes['source'], $bytes['avif'] ) . '%';
			}
			$label  = empty( $parts ) ? esc_html__( 'Converted', 'pixel-forge' ) : implode( ', ', $parts );
			$remove = $can ? ' <a href="#" class="pf-remove-one" data-id="' . $id . '">' . esc_html__( 'Remove', 'pixel-forge' ) . '</a>' : '';
			return '<span class="pf-cell" data-id="' . $id . '"><span class="pf-done">' . $label . '</span>' . $remove . '</span>';
		}

		if ( ! $can ) {
			return '<span class="pf-cell" data-id="' . $id . '">&#8212;</span>';
		}

		return '<span class="pf-cell" data-id="' . $id . '"><a href="#" class="pf-convert-one" data-id="' . $id . '">' . esc_html__( 'Convert', 'pixel-forge' ) . '</a></span>';
	}

	/**
	 * Percentage smaller, floored at zero.
	 *
	 * @param int $source Original bytes.
	 * @param int $out    Converted bytes.
	 * @return int
	 */
	private static function pct( $source, $out ) {
		$source = (int) $source;
		$out    = (int) $out;
		return $source > 0 ? (int) round( ( 1 - $out / $source ) * 100 ) : 0;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$supported = PixelForge_Converter::supported_formats();
		$settings  = PixelForge_Process::get_settings();
		$pending   = PixelForge_Process::count_pending();
		$done      = PixelForge_Process::count_done();
		$none      = ( ! $supported['webp'] && ! $supported['avif'] );
		?>
		<div class="wrap pixelforge">
			<h1><?php esc_html_e( 'Pixel Forge', 'pixel-forge' ); ?></h1>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Convert your media library to WebP and AVIF. Originals are kept, every generated file is tracked, and you can remove them all again with one click.', 'pixel-forge' ); ?>
			</p>

			<?php if ( $none ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'This server cannot write WebP or AVIF. Ask your host to enable the GD or Imagick support for these formats, then reload this page.', 'pixel-forge' ); ?>
				</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="pixelforge-cards">
				<div class="pixelforge-card">
					<span class="pixelforge-num" id="pf-stat-images">0</span>
					<span class="pixelforge-label"><?php esc_html_e( 'Images converted', 'pixel-forge' ); ?></span>
				</div>
				<div class="pixelforge-card">
					<span class="pixelforge-num" id="pf-stat-files">0</span>
					<span class="pixelforge-label"><?php esc_html_e( 'Next-gen files', 'pixel-forge' ); ?></span>
				</div>
				<div class="pixelforge-card">
					<span class="pixelforge-num" id="pf-stat-saved">—</span>
					<span class="pixelforge-label"><?php esc_html_e( 'Smaller than originals', 'pixel-forge' ); ?></span>
				</div>
				<div class="pixelforge-card">
					<span class="pixelforge-num" id="pf-stat-pending"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span>
					<span class="pixelforge-label"><?php esc_html_e( 'Still to convert', 'pixel-forge' ); ?></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Settings', 'pixel-forge' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Formats', 'pixel-forge' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="pf-fmt-webp" value="webp"
								<?php checked( in_array( 'webp', $settings['formats'], true ) ); ?>
								<?php disabled( ! $supported['webp'] ); ?> />
							<?php esc_html_e( 'WebP', 'pixel-forge' ); ?>
							<?php if ( ! $supported['webp'] ) : ?>
								<span class="description">(<?php esc_html_e( 'not available on this server', 'pixel-forge' ); ?>)</span>
							<?php endif; ?>
						</label><br>
						<label>
							<input type="checkbox" id="pf-fmt-avif" value="avif"
								<?php checked( in_array( 'avif', $settings['formats'], true ) ); ?>
								<?php disabled( ! $supported['avif'] ); ?> />
							<?php esc_html_e( 'AVIF', 'pixel-forge' ); ?>
							<span class="description">(<?php echo ! $supported['avif'] ? esc_html__( 'not available on this server', 'pixel-forge' ) : esc_html__( 'smaller, but slower to create', 'pixel-forge' ); ?>)</span>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pf-quality"><?php esc_html_e( 'Quality', 'pixel-forge' ); ?></label></th>
					<td>
						<input type="range" id="pf-quality" min="40" max="100" value="<?php echo esc_attr( $settings['quality'] ); ?>" />
						<output id="pf-quality-out"><?php echo esc_html( $settings['quality'] ); ?></output>
						<p class="description"><?php esc_html_e( 'Higher keeps more detail and makes bigger files. 80 to 85 suits most photos.', 'pixel-forge' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Serve next-gen images', 'pixel-forge' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="pf-serve" value="1" <?php checked( ! empty( $settings['serve'] ) ); ?> />
							<?php esc_html_e( 'Deliver WebP/AVIF on the front end using a <picture> tag, with the original as fallback.', 'pixel-forge' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" id="pf-save"><?php esc_html_e( 'Save settings', 'pixel-forge' ); ?></button>
				<span class="pixelforge-inline-msg" id="pf-save-msg" aria-live="polite"></span>
			</p>

			<h2><?php esc_html_e( 'Convert', 'pixel-forge' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="pf-start"><?php esc_html_e( 'Start conversion', 'pixel-forge' ); ?></button>
				<button type="button" class="button" id="pf-stop" disabled><?php esc_html_e( 'Stop', 'pixel-forge' ); ?></button>
			</p>
			<div class="pixelforge-progress" aria-hidden="true"><div class="pixelforge-bar" id="pf-bar"></div></div>
			<p class="pixelforge-status" id="pf-status" aria-live="polite"></p>
			<div class="pixelforge-log" id="pf-log"></div>

			<h2><?php esc_html_e( 'Rollback', 'pixel-forge' ); ?></h2>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Remove every WebP and AVIF file Pixel Forge created. Your original images stay exactly as they are.', 'pixel-forge' ); ?>
			</p>
			<p>
				<button type="button" class="button button-link-delete" id="pf-rollback"><?php esc_html_e( 'Delete generated files', 'pixel-forge' ); ?></button>
				<span class="pixelforge-inline-msg" id="pf-rollback-msg" aria-live="polite"></span>
			</p>

			<hr style="max-width:720px;margin:28px 0 12px;" />
			<p class="description" style="max-width:720px;">
				<?php
				printf(
					/* translators: 1: website link, 2: Ko-fi support link, 3: developer contact email link. */
					esc_html__( 'Built by Gunjan Jaswal at %1$s. Enjoying Pixel Forge? %2$s, or %3$s.', 'pixel-forge' ),
					'<a href="' . esc_url( 'https://www.gunjanjaswal.me' ) . '" target="_blank" rel="noopener noreferrer">gunjanjaswal.me</a>',
					'<a href="' . esc_url( 'https://ko-fi.com/gunjanjaswal' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'buy me a coffee on Ko-fi', 'pixel-forge' ) . '</a>',
					'<a href="' . esc_url( 'mailto:hello@gunjanjaswal.me' ) . '">' . esc_html__( 'contact the developer', 'pixel-forge' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Settings, support and contact links on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'upload.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'pixel-forge' )
		);
		array_unshift( $links, $settings );

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://www.gunjanjaswal.me' ),
			esc_html__( 'Website', 'pixel-forge' )
		);
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://ko-fi.com/gunjanjaswal' ),
			esc_html__( 'Support on Ko-fi', 'pixel-forge' )
		);
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'mailto:hello@gunjanjaswal.me' ),
			esc_html__( 'Contact developer', 'pixel-forge' )
		);

		return $links;
	}
}
