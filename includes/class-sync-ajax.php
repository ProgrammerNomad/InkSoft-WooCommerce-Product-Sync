<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Sync_AJAX {
    public function __construct() {
        add_action( 'wp_ajax_inksoft_woo_sync_start', array( $this, 'ajax_start' ) );
        add_action( 'wp_ajax_inksoft_woo_sync_process_chunk', array( $this, 'ajax_process_chunk' ) );
        add_action( 'wp_ajax_inksoft_woo_sync_status', array( $this, 'ajax_status' ) );
    }

    public function ajax_start() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $settings = get_option( 'inksoft_woo_settings', array() );
        $stores_raw = $settings['stores'] ?? '';
        $stores = array();
        if ( ! empty( $stores_raw ) ) {
            $parts = explode( ',', $stores_raw );
            foreach ( $parts as $p ) {
                $s = trim( $p );
                if ( ! empty( $s ) ) $stores[] = $s;
            }
        }

        if ( empty( $stores ) && ! empty( $settings['stores_single'] ) ) {
            $stores[] = $settings['stores_single'];
        }

        wp_send_json_success( array( 'stores' => $stores ) );
    }

    public function ajax_process_chunk() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_POST['store'] ?? '' );
        $page = intval( $_POST['page'] ?? 0 );
        $page_size = intval( $_POST['page_size'] ?? ( get_option( 'inksoft_woo_settings' )['page_size'] ?? 100 ) );

        if ( empty( $store ) ) {
            wp_send_json_error( 'Store is required' );
        }

        $settings = get_option( 'inksoft_woo_settings', array() );
        $manager = new InkSoft_Sync_Manager();
        $res = $manager->process_chunk( $store, $page, $page_size, $settings );

        if ( ! empty( $res['logs'] ) ) {
            // append logs to transient
            $key = 'inksoft_sync_log_' . sanitize_key( $store );
            $existing = get_transient( $key ) ?: array();
            $existing = array_merge( $existing, $res['logs'] );
            set_transient( $key, $existing, HOUR_IN_SECONDS );
        }

        wp_send_json( $res );
    }

    public function ajax_status() {
        check_ajax_referer( 'inksoft-woo-sync', 'nonce' );
        $store = sanitize_text_field( $_GET['store'] ?? '' );
        $key = 'inksoft_sync_log_' . sanitize_key( $store );
        $logs = get_transient( $key ) ?: array();
        wp_send_json_success( array( 'logs' => $logs ) );
    }
}

new InkSoft_Sync_AJAX();
