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
            'api_key'              => '',
            'stores'               => '',
            'markup'               => '0',
            'page_size'            => 100,
            'enable_variants'      => 1,
            'delete_missing'       => 1,
            'image_replace'        => 1,
            'product_display_mode' => 'embed_only',
        ) );
        // Back-fill default if key missing in saved option
        if ( empty( $settings['product_display_mode'] ) ) {
            $settings['product_display_mode'] = 'embed_only';
        }
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
                        <th scope="row"><?php esc_html_e( 'Product Display Mode', 'inksoft-woo-sync' ); ?></th>
                        <td>
                            <label><input type="radio" name="inksoft_woo_settings[product_display_mode]" value="embed_only" <?php checked( $settings['product_display_mode'], 'embed_only' ); ?> />
                                <strong><?php esc_html_e( 'InkSoft Designer only', 'inksoft-woo-sync' ); ?></strong> &mdash; <?php esc_html_e( 'Replaces the WooCommerce product page with the embedded design studio.', 'inksoft-woo-sync' ); ?>
                            </label><br/><br/>
                            <label><input type="radio" name="inksoft_woo_settings[product_display_mode]" value="both" <?php checked( $settings['product_display_mode'], 'both' ); ?> />
                                <strong><?php esc_html_e( 'Both (embed + WooCommerce)', 'inksoft-woo-sync' ); ?></strong> &mdash; <?php esc_html_e( 'Shows the InkSoft designer above the standard WooCommerce product details, tabs, and related products.', 'inksoft-woo-sync' ); ?>
                            </label><br/><br/>
                            <label><input type="radio" name="inksoft_woo_settings[product_display_mode]" value="woo_only" <?php checked( $settings['product_display_mode'], 'woo_only' ); ?> />
                                <strong><?php esc_html_e( 'WooCommerce only', 'inksoft-woo-sync' ); ?></strong> &mdash; <?php esc_html_e( 'Shows the standard WooCommerce product page. The InkSoft embed is hidden for all products.', 'inksoft-woo-sync' ); ?>
                            </label>
                            <p class="description" style="margin-top:8px;"><?php esc_html_e( 'Per-product override: open any product in WooCommerce admin and check &ldquo;Disable InkSoft Designer&rdquo; to force WooCommerce-only for that product regardless of this setting.', 'inksoft-woo-sync' ); ?></p>
                        </td>
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

            <hr />
            <h2><?php esc_html_e( 'Attribute Mapping', 'inksoft-woo-sync' ); ?></h2>
            <p><?php esc_html_e( 'Configure how InkSoft product structures map to WooCommerce attributes. This allows flexible handling of colors, sizes, materials, or any custom attributes from your InkSoft products.', 'inksoft-woo-sync' ); ?></p>
            
            <?php $this->render_attribute_mapping(); ?>

            <hr />
            <h2><?php esc_html_e( 'Manual Sync', 'inksoft-woo-sync' ); ?></h2>
            <p><button id="inksoft-start-sync" class="button button-primary"><?php esc_html_e( 'Start Sync (AJAX)', 'inksoft-woo-sync' ); ?></button></p>

            <div id="inksoft-sync-log" style="background:#fff;padding:12px;border:1px solid #ddd;max-height:400px;overflow:auto;font-family:monospace;white-space:pre-wrap;"></div>

            <hr />
            <h2 style="color:#b32d2e;"><?php esc_html_e( 'Danger Zone', 'inksoft-woo-sync' ); ?></h2>
            <p><?php esc_html_e( 'Permanently delete all products that were imported from InkSoft. Choose what to remove — only items exclusively created by InkSoft sync will be deleted.', 'inksoft-woo-sync' ); ?></p>

            <div id="inksoft-purge-section">
                <button id="inksoft-purge-check" class="button"><?php esc_html_e( 'Preview what will be deleted', 'inksoft-woo-sync' ); ?></button>

                <div id="inksoft-purge-counts" style="display:none;margin-top:15px;padding:12px;background:#fff8e1;border:1px solid #ffc107;border-radius:3px;"></div>

                <div id="inksoft-purge-form" style="display:none;margin-top:15px;">
                    <strong><?php esc_html_e( 'Select what to delete:', 'inksoft-woo-sync' ); ?></strong>
                    <ul style="margin-top:8px;">
                        <li><label><input type="checkbox" id="purge_products" checked /> <?php esc_html_e( 'Products', 'inksoft-woo-sync' ); ?> (<span id="count-products">0</span> products + their variations)</label></li>
                        <li><label><input type="checkbox" id="purge_images" checked /> <?php esc_html_e( 'Product images', 'inksoft-woo-sync' ); ?> (<span id="count-images">0</span> attachments)</label></li>
                        <li><label><input type="checkbox" id="purge_categories" /> <?php esc_html_e( 'Product categories', 'inksoft-woo-sync' ); ?> (<span id="count-categories">0</span> — only if no other products use them)</label></li>
                        <li><label><input type="checkbox" id="purge_tags" /> <?php esc_html_e( 'Product tags', 'inksoft-woo-sync' ); ?> (<span id="count-tags">0</span> — only if no other products use them)</label></li>
                        <li><label><input type="checkbox" id="purge_attributes" /> <?php esc_html_e( 'Attribute terms', 'inksoft-woo-sync' ); ?> (pa_color, pa_size etc. — <span id="count-attributes">0</span> — only if no other products use them)</label></li>
                    </ul>
                    <p style="margin-top:12px;">
                        <button id="inksoft-purge-execute" class="button" style="background:#b32d2e;color:#fff;border-color:#a02020;"><?php esc_html_e( 'Delete Selected Items', 'inksoft-woo-sync' ); ?></button>
                    </p>
                </div>

                <div id="inksoft-purge-log" style="display:none;background:#fff;padding:12px;border:1px solid #ddd;max-height:300px;overflow:auto;font-family:monospace;white-space:pre-wrap;margin-top:15px;"></div>
            </div>

            <!-- Confirmation modal -->
            <div id="inksoft-purge-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:30px;max-width:520px;width:90%;border-radius:4px;box-shadow:0 5px 20px rgba(0,0,0,0.35);">
                    <h2 style="margin-top:0;color:#b32d2e;"><?php esc_html_e( 'Confirm Permanent Deletion', 'inksoft-woo-sync' ); ?></h2>
                    <p id="inksoft-purge-modal-message"></p>
                    <p style="color:#666;font-size:12px;"><?php esc_html_e( 'This action cannot be undone. Make sure you have a database backup.', 'inksoft-woo-sync' ); ?></p>
                    <div style="margin-top:20px;text-align:right;">
                        <button id="inksoft-purge-cancel" class="button button-secondary" style="margin-right:10px;"><?php esc_html_e( 'Cancel', 'inksoft-woo-sync' ); ?></button>
                        <button id="inksoft-purge-confirm" class="button button-primary" style="background:#b32d2e;border-color:#a02020;"><?php esc_html_e( 'Yes, Delete Now', 'inksoft-woo-sync' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render attribute mapping configuration UI
     */
    public function render_attribute_mapping() {
        if ( ! class_exists( 'InkSoft_Attribute_Mapper' ) ) {
            require_once dirname( __FILE__ ) . '/../includes/class-attribute-mapper.php';
        }

        $config = InkSoft_Attribute_Mapper::get_attribute_config();
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Attribute', 'inksoft-woo-sync' ); ?></th>
                    <th><?php esc_html_e( 'InkSoft Path', 'inksoft-woo-sync' ); ?></th>
                    <th><?php esc_html_e( 'WooCommerce Attribute', 'inksoft-woo-sync' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'inksoft-woo-sync' ); ?></th>
                    <th><?php esc_html_e( 'Enabled', 'inksoft-woo-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $config as $key => $attr ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $key ); ?></strong></td>
                    <td><?php echo esc_html( $attr['inksoft_path'] ); ?></td>
                    <td><?php echo esc_html( $attr['attribute_slug'] ); ?></td>
                    <td><?php echo esc_html( $attr['attribute_label'] ); ?></td>
                    <td><?php echo $attr['enabled'] ? '✓' : '✗'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top: 15px;">
            <em><?php esc_html_e( 'To modify these settings, edit your WordPress settings or use the filter: apply_filters( "inksoft_attribute_config", ... )', 'inksoft-woo-sync' ); ?></em>
        </p>

        <details style="margin-top: 15px;">
            <summary><?php esc_html_e( 'Advanced: How to customize attribute mapping (developers)', 'inksoft-woo-sync' ); ?></summary>
            <p><?php esc_html_e( 'Add this to your wp-config.php or functions.php to customize:', 'inksoft-woo-sync' ); ?></p>
            <pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;">add_filter( 'inksoft_attribute_config', function( $config ) {
    // Add a new attribute for materials
    $config['material'] = array(
        'inksoft_path'    => 'Materials',
        'attribute_slug'  => 'pa_material',
        'attribute_label' => 'Material',
        'enabled'         => true,
    );
    return $config;
} );</pre>
        </details>
        <?php
    }
}
