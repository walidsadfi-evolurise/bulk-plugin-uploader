<?php
/*
Plugin Name: Bulk Plugin Uploader & Activator
Description: Drag and drop all your plugin files, then install and activate them with a single click.
Version:     1.0.0
Author:      Walid Sadfi - Evolurise
Author URI:  https://www.walidsadfi.com/
Text Domain: bulk-plugin-uploader
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**-------------------------------------------------
 * Bootstrap
 *-------------------------------------------------*/

// Start the PHP session if it isn’t already running.
if ( ! session_id() ) {
	session_start();
}

// Initialise the list of slugs marked "install-only".
if ( ! isset( $_SESSION['bpu_installed'] ) ) {
	$_SESSION['bpu_installed'] = array();
}

/**-------------------------------------------------
 * Main class
 *-------------------------------------------------*/
class Bulk_Plugin_Uploader_Activator {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'add_plugin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bulk_plugin_upload',   array( $this, 'handle_ajax_plugin_upload' ) );
		add_action( 'wp_ajax_bulk_plugin_activate', array( $this, 'handle_ajax_plugin_activate' ) );
	}

	/* ---------- Admin screen ---------- */

	public function add_plugin_page() {
		add_menu_page(
			__( 'Bulk Plugin Upload', 'bulk-plugin-uploader' ),
			__( 'Bulk Plugin Upload', 'bulk-plugin-uploader' ),
			'install_plugins',
			'bulk-plugin-uploader',
			array( $this, 'render_upload_page' ),
			'dashicons-upload',
			100
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== 'toplevel_page_bulk-plugin-uploader' ) {
			return;
		}

		wp_enqueue_script(
			'bulk-plugin-upload',
			plugin_dir_url( __FILE__ ) . 'assets/js/bulk-plugin-upload.js',
			array( 'jquery' ),
			'2.4',
			true
		);

		wp_localize_script(
			'bulk-plugin-upload',
			'BulkPluginUpload',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'bulk_plugin_upload' ),
				'session_id' => session_id(),
			)
		);

		wp_enqueue_style(
			'bulk-plugin-upload-style',
			plugin_dir_url( __FILE__ ) . 'assets/css/bulk-plugin-upload-styles.css',
			array(),
			'2.4'
		);
	}

	public function render_upload_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Plugin Uploader & Activator', 'bulk-plugin-uploader' ); ?></h1>

			<div id="dropzone" class="dropzone">
				<p>
					<?php esc_html_e( 'Drag & drop your ZIP files here', 'bulk-plugin-uploader' ); ?><br>
					<?php esc_html_e( 'or click to browse.', 'bulk-plugin-uploader' ); ?>
				</p>
				<input
					type="file"
					id="plugin_zips"
					name="plugin_zips[]"
					multiple
					accept=".zip"
					style="display: none;"
				/>
			</div>

			<div class="buttons">
				<button id="btn-install-activate" class="button button-primary">
					<?php esc_html_e( 'Install & Activate', 'bulk-plugin-uploader' ); ?>
				</button>

				<button id="btn-install-only" class="button">
					<?php esc_html_e( 'Install without activation', 'bulk-plugin-uploader' ); ?>
				</button>

				<?php if ( ! empty( $_SESSION['bpu_installed'] ) ) : ?>
					<button id="btn-activate-only" class="button button-secondary">
						<?php esc_html_e( 'Activate installed plugins', 'bulk-plugin-uploader' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div id="upload-results"></div>
		</div>
		<?php
	}

	/* ---------- AJAX: upload ---------- */

	public function handle_ajax_plugin_upload() {
		check_ajax_referer( 'bulk_plugin_upload', 'security' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( __( 'Not authorised.', 'bulk-plugin-uploader' ) );
		}

		// Basic file check
		if ( empty( $_FILES['plugin_zips'] ) || empty( $_FILES['plugin_zips']['tmp_name'] ) || ! isset( $_FILES['plugin_zips']['tmp_name'][0] ) ) {
			wp_send_json_error( __( 'Invalid file upload.', 'bulk-plugin-uploader' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Prepare file
		$file = array(
			'name'     => $_FILES['plugin_zips']['name'][0],
			'type'     => $_FILES['plugin_zips']['type'][0],
			'tmp_name' => $_FILES['plugin_zips']['tmp_name'][0],
			'error'    => $_FILES['plugin_zips']['error'][0],
			'size'     => $_FILES['plugin_zips']['size'][0]
		);

		// Upload to WordPress temporary folder
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => false
			)
		);

		if ( ! empty( $upload['error'] ) ) {
			wp_send_json_error( $upload['error'] );
		}

		if ( empty( $upload['file'] ) ) {
			wp_send_json_error( __( 'Upload failed.', 'bulk-plugin-uploader' ) );
		}

		// Installation
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result = $upgrader->install( $upload['file'] );

		// Clean up temporary file
		@unlink( $upload['file'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( ! $result ) {
			wp_send_json_error( __( 'Installation failed.', 'bulk-plugin-uploader' ) );
		}

		$plugin_file = $upgrader->plugin_info();
		
		if ( ! $plugin_file ) {
			wp_send_json_error( __( 'Plugin installed but unable to activate.', 'bulk-plugin-uploader' ) );
		}

		// Activate or not
		$install_only = isset( $_POST['install_only'] ) && $_POST['install_only'] === '1';
		
		if ( ! $install_only ) {
			$activated = activate_plugin( $plugin_file );
			if ( is_wp_error( $activated ) ) {
				wp_send_json_error( $activated->get_error_message() );
			}
			$message = __( 'Installed and activated.', 'bulk-plugin-uploader' );
		} else {
			$dir = dirname( $plugin_file );
			if ( ! in_array( $dir, $_SESSION['bpu_installed'], true ) ) {
				$_SESSION['bpu_installed'][] = $dir;
			}
			$message = __( 'Installed (not activated).', 'bulk-plugin-uploader' );
		}

		wp_send_json_success( array(
			array(
				'plugin'  => $file['name'],
				'status'  => __( 'Success', 'bulk-plugin-uploader' ),
				'message' => $message,
			)
		) );
	}

	/* ---------- AJAX: activate “install-only” plugins ---------- */

	public function handle_ajax_plugin_activate() {
		check_ajax_referer( 'bulk_plugin_upload', 'security' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'Not authorised.', 'bulk-plugin-uploader' ) );
		}

		if ( empty( $_SESSION['bpu_installed'] ) ) {
			wp_send_json_error( __( 'No plugins to activate.', 'bulk-plugin-uploader' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$results = array();

		foreach ( $_SESSION['bpu_installed'] as $slug ) {
			$all = get_plugins( $slug . '/' );

			if ( empty( $all ) ) {
				$results[ $slug ] = array(
					'status' => 'Error',
					'msg'    => __( 'Directory not found.', 'bulk-plugin-uploader' ),
				);
				continue;
			}

			$file = $slug . '/' . array_keys( $all )[0];

			if ( is_plugin_active( $file ) ) {
				$results[ $slug ] = array(
					'status' => 'OK',
					'msg'    => __( 'Already active.', 'bulk-plugin-uploader' ),
				);
				continue;
			}

			$r = activate_plugin( $file );

			$results[ $slug ] = is_wp_error( $r )
				? array( 'status' => 'Error', 'msg' => $r->get_error_message() )
				: array( 'status' => 'OK',    'msg' => __( 'Activated.', 'bulk-plugin-uploader' ) );
		}

		// Reset the session list.
		$_SESSION['bpu_installed'] = array();

		wp_send_json_success( $results );
	}
}



new Bulk_Plugin_Uploader_Activator();
