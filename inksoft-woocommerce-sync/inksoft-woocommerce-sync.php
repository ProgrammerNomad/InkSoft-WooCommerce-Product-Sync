<?php
/**
 * Plugin Name: InkSoft WooCommerce Product Sync
 * Plugin URI: https://github.com/ProgrammerNomad/InkSoft-WooCommerce-Product-Sync
 * Description: Sync products from multiple InkSoft stores to WooCommerce
 * Version: 1.4.2
 * Author: Developer
 * Author URI: https://github.com/ProgrammerNomad
 * License: GPL v2 or later
 * Text Domain: inksoft-woo-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INKSOFT_WOO_SYNC_VERSION', '1.4.2' );
define( 'INKSOFT_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'INKSOFT_WOO_SYNC_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with WooCommerce features
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Include required files
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-inksoft-api.php';
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-sync-manager.php';
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-sync-ajax.php';
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-product-display.php';
require_once INKSOFT_WOO_SYNC_PATH . 'admin/class-admin.php';

/**
 * Create the form submissions table using dbDelta.
 * Safe to call multiple times - only creates if missing.
 */
function inksoft_create_submissions_table() {
	global $wpdb;
	$table           = $wpdb->prefix . 'inksoft_form_submissions';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		product_id bigint(20) unsigned NOT NULL DEFAULT 0,
		product_name varchar(255) NOT NULL DEFAULT '',
		contact_name varchar(255) NOT NULL DEFAULT '',
		contact_email varchar(255) NOT NULL DEFAULT '',
		contact_phone varchar(100) NOT NULL DEFAULT '',
		contact_quantity smallint(5) unsigned NOT NULL DEFAULT 0,
		contact_attrs longtext NOT NULL,
		contact_message longtext NOT NULL,
		submitted_at datetime NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'new',
		email_sent tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Initialize the plugin
add_action( 'plugins_loaded', function() {
	// Ensure submissions table exists (covers existing installs after plugin update).
	inksoft_create_submissions_table();

	// Check if WooCommerce is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'InkSoft WooCommerce Sync requires WooCommerce to be installed and activated.', 'inksoft-woo-sync' );
			echo '</p></div>';
		});
		return;
	}

	// Initialize admin
	new InkSoft_Woo_Sync_Admin();

	// Schedule cron jobs
	if ( ! wp_next_scheduled( 'inksoft_woo_sync_daily' ) ) {
		wp_schedule_event( time(), 'daily', 'inksoft_woo_sync_daily' );
	}

	add_action( 'inksoft_woo_sync_daily', function() {
		$sync_manager = new InkSoft_Sync_Manager();
		$sync_manager->sync_all_stores();
	});
});

// Activation hook
register_activation_hook( __FILE__, function() {
	wp_schedule_event( time(), 'daily', 'inksoft_woo_sync_daily' );
	inksoft_create_submissions_table();
});

// Deactivation hook
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'inksoft_woo_sync_daily' );
});
