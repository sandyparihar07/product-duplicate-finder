<?php
/**
 * Plugin Name: Duplicate Product Finder
 * Plugin URI: https://shopifygems.com
 * Description: Find and safely resolve duplicate product and variation SKUs and product names, review conflicts, and resolve them without damaging catalog, order, inventory, or SEO data.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Sandeep Parihar
 * Author URI: https://shopifygems.com
 * License: GPL-2.0-or-later
 * Text Domain: product-duplicate-finder
 */

defined( 'ABSPATH' ) || exit;

define( 'SKUSN_VERSION', '1.0.0' );
define( 'SKUSN_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'SKUSN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKUSN_TEXT_DOMAIN', 'product-duplicate-finder' );
define( 'SKUSN_CAPABILITY', 'skusn_manage_duplicates' );

require_once SKUSN_PLUGIN_DIR . 'includes/class-capabilities.php';

register_activation_hook( __FILE__, 'sku_sentinel_on_activation' );

function sku_sentinel_on_activation() {
	if ( ! function_exists( 'wc_get_template' ) || ! defined( 'WC_VERSION' ) ) {
		return;
	}
	if ( version_compare( WC_VERSION, '6.0', '<' ) ) {
		return;
	}

	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->add_cap( SKUSN_CAPABILITY );
	}

	require_once SKUSN_PLUGIN_DIR . 'includes/class-database.php';
	$db = new SKUSN_Database();
	$db->create_tables();
}

register_deactivation_hook( __FILE__, 'sku_sentinel_on_deactivation' );
function sku_sentinel_on_deactivation() {}

register_uninstall_hook( __FILE__, 'sku_sentinel_uninstall' );
function sku_sentinel_uninstall() {
	delete_option( 'skusn_settings' );
	delete_option( 'skusn_db_version' );
}

function sku_sentinel_init() {
	if ( ! is_admin() ) {
		return;
	}

	require_once SKUSN_PLUGIN_DIR . 'includes/class-dependencies.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-database.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-sku-normalizer.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-resolution-manager.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-exporter.php';
	require_once SKUSN_PLUGIN_DIR . 'admin/class-admin.php';

	// Ensure tables exist (handles case where activation hook was skipped)
	$db = new SKUSN_Database();
	$db->create_tables();

	// Ensure admin capability exists
	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( SKUSN_CAPABILITY ) ) {
		$role->add_cap( SKUSN_CAPABILITY );
	}

	new SKUSN_Admin();
}
add_action( 'plugins_loaded', 'sku_sentinel_init' );

function sku_sentinel_admin_menu() {
	add_menu_page(
		'Duplicate Product Finder',
		'Duplicate Product Finder',
		SKUSN_CAPABILITY,
		'product-duplicate-finder',
		'sku_sentinel_dashboard_page',
		'dashicons-tag',
		65
	);

	add_submenu_page(
		'product-duplicate-finder',
		'Settings',
		'Settings',
		SKUSN_CAPABILITY,
		'sku-sentinel-settings',
		'sku_sentinel_settings_page'
	);

	add_submenu_page(
		'product-duplicate-finder',
		'Scan Results',
		'Results',
		SKUSN_CAPABILITY,
		'sku-sentinel-results',
		'sku_sentinel_results_page'
	);

	// Hidden page for export downloads
	add_submenu_page(
		null,
		'Export Scan',
		'',
		SKUSN_CAPABILITY,
		'sku-sentinel-export',
		'sku_sentinel_export_page'
	);
}
add_action( 'admin_menu', 'sku_sentinel_admin_menu' );

function sku_sentinel_dashboard_page() {
	if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to access this page.', 'product-duplicate-finder' ) . '</p></div>';
		return;
	}
	include SKUSN_PLUGIN_DIR . 'admin/views/dashboard.php';
}

function sku_sentinel_results_page() {
	if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to access this page.', 'product-duplicate-finder' ) . '</p></div>';
		return;
	}
	include SKUSN_PLUGIN_DIR . 'admin/views/results.php';
}

function sku_sentinel_settings_page() {
	if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to access this page.', 'product-duplicate-finder' ) . '</p></div>';
		return;
	}
	include SKUSN_PLUGIN_DIR . 'admin/views/settings.php';
}

function sku_sentinel_export_page() {
	if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
		wp_die( esc_html__( 'No permission.', 'product-duplicate-finder' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
	$scan_id = isset( $_GET['scan_id'] ) ? absint( wp_unslash( $_GET['scan_id'] ) ) : 0;

	if ( ! $scan_id ) {
		wp_die( esc_html__( 'Invalid export request.', 'product-duplicate-finder' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This IS the nonce verification; raw value required for wp_verify_nonce().
	$nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
	if ( ! wp_verify_nonce( $nonce, 'skusn_export_' . $scan_id ) ) {
		wp_die( esc_html__( 'Security check failed.', 'product-duplicate-finder' ) );
	}

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	require_once SKUSN_PLUGIN_DIR . 'includes/class-database.php';
	require_once SKUSN_PLUGIN_DIR . 'includes/class-exporter.php';

	$db       = new SKUSN_Database();
	$exporter = new SKUSN_Exporter( $db );
	$exporter->export_csv( $scan_id );
	exit;
}

function sku_sentinel_get_default_settings() {
	return array(
		'include_variations'  => true,
		'include_trashed'     => false,
		'trim_whitespace'     => true,
		'case_insensitive'    => true,
		'default_action'      => 'trash',
		'excluded_categories' => array(),
	);
}

function sku_sentinel_get_settings() {
	$defaults = sku_sentinel_get_default_settings();
	$saved    = get_option( 'skusn_settings', array() );
	return wp_parse_args( $saved, $defaults );
}
