<?php
/**
 * Plugin Name: InkSoft WooCommerce Product Sync
 * Plugin URI: https://github.com/ProgrammerNomad/InkSoft-WooCommerce-Product-Sync
 * Description: Sync products from multiple InkSoft stores to WooCommerce
 * Version: 1.0.0
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

define( 'INKSOFT_WOO_SYNC_VERSION', '1.0.0' );
define( 'INKSOFT_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'INKSOFT_WOO_SYNC_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-inksoft-api.php';
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-sync-manager.php';
require_once INKSOFT_WOO_SYNC_PATH . 'includes/class-sync-ajax.php';
require_once INKSOFT_WOO_SYNC_PATH . 'admin/class-admin.php';

// Initialize the plugin
add_action( 'plugins_loaded', function() {
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
});

// Deactivation hook
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'inksoft_woo_sync_daily' );
});
