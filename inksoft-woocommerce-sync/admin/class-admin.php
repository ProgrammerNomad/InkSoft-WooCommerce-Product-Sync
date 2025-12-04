<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Woo_Sync_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_menu() {
        add_menu_page(
            __( 'InkSoft Sync', 'inksoft-woo-sync' ),
            __( 'InkSoft Sync', 'inksoft-woo-sync' ),
            'manage_woocommerce',
            'inksoft-woo-sync',
            array( $this, 'settings_page' ),
            'dashicons-update',
            56
        );
    }

    public function register_settings() {
        register_setting( 'inksoft_woo_sync', 'inksoft_woo_settings' );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_inksoft-woo-sync' ) {
            return;
        }

        wp_enqueue_script( 'inksoft-woo-admin', INKSOFT_WOO_SYNC_URL . 'assets/admin.js', array( 'jquery' ), INKSOFT_WOO_SYNC_VERSION, true );
        $settings = get_option( 'inksoft_woo_settings', array() );
        wp_localize_script( 'inksoft-woo-admin', 'InkSoftWoo', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'inksoft-woo-sync' ),
            'settings' => $settings,
        ) );
    }

    public function settings_page() {
        $settings = get_option( 'inksoft_woo_settings', array(
            'api_key' => '',
            'stores' => '',
            'markup' => '0',
            'page_size' => 100,
            'enable_variants' => 1,
            'delete_missing' => 1,
            'image_replace' => 1,
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'InkSoft → WooCommerce Sync', 'inksoft-woo-sync' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'inksoft_woo_sync' ); ?>
                <?php do_settings_sections( 'inksoft_woo_sync' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_key"><?php esc_html_e( 'API Key', 'inksoft-woo-sync' ); ?></label></th>
                        <td><input name="inksoft_woo_settings[api_key]" type="text" id="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stores"><?php esc_html_e( 'Store URIs (comma separated)', 'inksoft-woo-sync' ); ?></label></th>
                        <td><input name="inksoft_woo_settings[stores]" type="text" id="stores" value="<?php echo esc_attr( $settings['stores'] ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Example: Devo_Designs,devodesigns', 'inksoft-woo-sync' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="markup"><?php esc_html_e( 'Markup (%)', 'inksoft-woo-sync' ); ?></label></th>
                        <td><input name="inksoft_woo_settings[markup]" type="number" step="0.01" id="markup" value="<?php echo esc_attr( $settings['markup'] ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Apply percentage markup to base price when importing.', 'inksoft-woo-sync' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Page Size', 'inksoft-woo-sync' ); ?></th>
                        <td><input name="inksoft_woo_settings[page_size]" type="number" id="page_size" value="<?php echo esc_attr( $settings['page_size'] ); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Options', 'inksoft-woo-sync' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="inksoft_woo_settings[enable_variants]" value="1" <?php checked( $settings['enable_variants'], 1 ); ?> /> <?php esc_html_e( 'Enable variants (styles) mapping', 'inksoft-woo-sync' ); ?></label><br/>
                            <label><input type="checkbox" name="inksoft_woo_settings[delete_missing]" value="1" <?php checked( $settings['delete_missing'], 1 ); ?> /> <?php esc_html_e( 'Delete missing products after sync', 'inksoft-woo-sync' ); ?></label><br/>
                            <label><input type="checkbox" name="inksoft_woo_settings[image_replace]" value="1" <?php checked( $settings['image_replace'], 1 ); ?> /> <?php esc_html_e( 'Replace images if they exist', 'inksoft-woo-sync' ); ?></label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Manual Sync', 'inksoft-woo-sync' ); ?></h2>
            <p><button id="inksoft-start-sync" class="button button-primary"><?php esc_html_e( 'Start Sync (AJAX)', 'inksoft-woo-sync' ); ?></button></p>

            <div id="inksoft-sync-log" style="background:#fff;padding:12px;border:1px solid #ddd;max-height:400px;overflow:auto;font-family:monospace;white-space:pre-wrap;"></div>
        </div>
        <?php
    }
}
