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

        // Sub-page: Settings (mirrors parent so sidebar shows "Settings" as first child).
        add_submenu_page(
            'inksoft-woo-sync',
            __( 'Settings', 'inksoft-woo-sync' ),
            __( 'Settings', 'inksoft-woo-sync' ),
            'manage_woocommerce',
            'inksoft-woo-sync',
            array( $this, 'settings_page' )
        );

        // Sub-page: Form Submissions - badge shows unread count.
        $unread       = $this->get_unread_count();
        $sub_label    = __( 'Form Submissions', 'inksoft-woo-sync' );
        if ( $unread > 0 ) {
            $sub_label .= ' <span class="update-plugins count-' . $unread . '"><span class="plugin-count">' . $unread . '</span></span>';
        }

        add_submenu_page(
            'inksoft-woo-sync',
            __( 'Form Submissions', 'inksoft-woo-sync' ),
            $sub_label,
            'manage_woocommerce',
            'inksoft-form-submissions',
            array( $this, 'submissions_page' )
        );
    }

    /**
     * Return count of unread (status = 'new') form submissions.
     */
    private function get_unread_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'inksoft_form_submissions';
        // Table may not exist yet on first load before activation hook runs.
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $exists ) {
            return 0;
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
    }

    public function register_settings() {
        register_setting( 'inksoft_woo_sync', 'inksoft_woo_settings' );
    }

    public function enqueue_assets( $hook ) {
        $allowed_hooks = array(
            'toplevel_page_inksoft-woo-sync',
            'inksoft-sync_page_inksoft-form-submissions',
        );
        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
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
            <p id="inksoft-sync-progress" style="display:none;font-family:monospace;font-size:13px;color:#555;margin-top:6px;"></p>

            <div id="inksoft-sync-log" style="background:#fff;padding:12px;border:1px solid #ddd;max-height:400px;overflow:auto;font-family:monospace;white-space:pre-wrap;"></div>

            <hr />
            <h2 style="color:#b32d2e;"><?php esc_html_e( 'Danger Zone', 'inksoft-woo-sync' ); ?></h2>
            <p><?php esc_html_e( 'Permanently delete all products that were imported from InkSoft. Choose what to remove - only items exclusively created by InkSoft sync will be deleted.', 'inksoft-woo-sync' ); ?></p>

            <div id="inksoft-purge-section">
                <button id="inksoft-purge-check" class="button"><?php esc_html_e( 'Preview what will be deleted', 'inksoft-woo-sync' ); ?></button>

                <div id="inksoft-purge-counts" style="display:none;margin-top:15px;padding:12px;background:#fff8e1;border:1px solid #ffc107;border-radius:3px;"></div>

                <div id="inksoft-purge-form" style="display:none;margin-top:15px;">
                    <strong><?php esc_html_e( 'Select what to delete:', 'inksoft-woo-sync' ); ?></strong>
                    <ul style="margin-top:8px;">
                        <li><label><input type="checkbox" id="purge_products" checked /> <?php esc_html_e( 'Products', 'inksoft-woo-sync' ); ?> (<span id="count-products">0</span> products + their variations)</label></li>
                        <li><label><input type="checkbox" id="purge_images" checked /> <?php esc_html_e( 'Product images', 'inksoft-woo-sync' ); ?> (<span id="count-images">0</span> attachments)</label></li>
                        <li><label><input type="checkbox" id="purge_categories" /> <?php esc_html_e( 'Product categories', 'inksoft-woo-sync' ); ?> (<span id="count-categories">0</span> - only if no other products use them)</label></li>
                        <li><label><input type="checkbox" id="purge_tags" /> <?php esc_html_e( 'Product tags', 'inksoft-woo-sync' ); ?> (<span id="count-tags">0</span> - only if no other products use them)</label></li>
                        <li><label><input type="checkbox" id="purge_attributes" /> <?php esc_html_e( 'Attribute terms', 'inksoft-woo-sync' ); ?> (pa_color, pa_size etc. - <span id="count-attributes">0</span> - only if no other products use them)</label></li>
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

    // =========================================================================
    // Form Submissions Admin Pages
    // =========================================================================

    /**
     * Dispatcher: list or detail view.
     */
    public function submissions_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'inksoft-woo-sync' ) );
        }

        $view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list';
        $id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( 'detail' === $view && $id > 0 ) {
            $this->render_submission_detail( $id );
        } else {
            $this->render_submissions_list();
        }
    }

    /**
     * Render paginated list of all submissions.
     */
    private function render_submissions_list() {
        global $wpdb;
        $table    = $wpdb->prefix . 'inksoft_form_submissions';
        $per_page = 20;
        $paged    = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $rows     = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} ORDER BY submitted_at DESC LIMIT %d OFFSET %d", $per_page, $offset )
        );
        $base_url = admin_url( 'admin.php?page=inksoft-form-submissions' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Form Submissions', 'inksoft-woo-sync' ); ?>
                <span style="font-size:13px;font-weight:400;color:#888;margin-left:8px;"><?php echo (int) $total; ?> total</span>
            </h1>
            <style>
                .inks-list-table .is-new td { font-weight: 700; }
                .inks-badge-new  { background:#2271b1;color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;display:inline-block; }
                .inks-badge-read { background:#e0e0e0;color:#666;padding:2px 9px;border-radius:10px;font-size:11px;display:inline-block; }
            </style>
            <?php if ( empty( $rows ) ) : ?>
            <p><?php esc_html_e( 'No submissions yet.', 'inksoft-woo-sync' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped inks-list-table" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th style="width:46px;">#</th>
                        <th style="width:145px;"><?php esc_html_e( 'Date', 'inksoft-woo-sync' ); ?></th>
                        <th><?php esc_html_e( 'Product', 'inksoft-woo-sync' ); ?></th>
                        <th style="width:155px;"><?php esc_html_e( 'Customer', 'inksoft-woo-sync' ); ?></th>
                        <th style="width:195px;"><?php esc_html_e( 'Email', 'inksoft-woo-sync' ); ?></th>
                        <th style="width:75px;"><?php esc_html_e( 'Status', 'inksoft-woo-sync' ); ?></th>
                        <th style="width:65px;"><?php esc_html_e( 'Action', 'inksoft-woo-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                    <tr class="<?php echo 'new' === $row->status ? 'is-new' : ''; ?>">
                        <td><?php echo (int) $row->id; ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $row->submitted_at ) ) ); ?></td>
                        <td><?php echo esc_html( $row->product_name ); ?></td>
                        <td><?php echo esc_html( $row->contact_name ); ?></td>
                        <td><?php echo esc_html( $row->contact_email ); ?></td>
                        <td>
                            <?php if ( 'new' === $row->status ) : ?>
                            <span class="inks-badge-new"><?php esc_html_e( 'New', 'inksoft-woo-sync' ); ?></span>
                            <?php else : ?>
                            <span class="inks-badge-read"><?php esc_html_e( 'Read', 'inksoft-woo-sync' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $base_url . '&view=detail&id=' . $row->id ); ?>">
                                <?php esc_html_e( 'View', 'inksoft-woo-sync' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $page_links = paginate_links( array(
                'base'    => $base_url . '%_%',
                'format'  => '&paged=%#%',
                'current' => $paged,
                'total'   => ceil( $total / $per_page ),
            ) );
            if ( $page_links ) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages" style="margin-top:10px;">' . $page_links . '</div></div>';
            }
            ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render detail view for a single submission - auto-marks as read.
     */
    private function render_submission_detail( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'inksoft_form_submissions';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        if ( ! $row ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'inksoft-woo-sync' ) . '</p></div>';
            return;
        }

        // Auto-mark as read when viewed.
        if ( 'new' === $row->status ) {
            $wpdb->update( $table, array( 'status' => 'read' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
        }

        $attrs        = json_decode( $row->contact_attrs, true );
        $attrs        = is_array( $attrs ) ? $attrs : array();
        $back_url     = admin_url( 'admin.php?page=inksoft-form-submissions' );
        $product_edit = $row->product_id ? get_edit_post_link( $row->product_id ) : '';
        ?>
        <div class="wrap">
            <p style="margin-bottom:4px;">
                <a href="<?php echo esc_url( $back_url ); ?>" style="text-decoration:none;color:#2271b1;font-size:13px;">
                    &larr; <?php esc_html_e( 'Form Submissions', 'inksoft-woo-sync' ); ?>
                </a>
            </p>
            <h1 style="margin-top:6px;">
                <?php
                /* translators: %1$d = submission ID, %2$s = product name */
                echo esc_html( sprintf( __( 'Inquiry #%1$d - %2$s', 'inksoft-woo-sync' ), (int) $row->id, $row->product_name ) );
                ?>
            </h1>

            <style>
                .inks-meta-bar  { color:#888;font-size:13px;margin:0 0 16px; }
                .inks-actions   { display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:24px; }
                .inks-grid      { display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:960px; }
                .inks-card      { background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px; }
                .inks-card h3   { margin:0 0 14px;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#999;border-bottom:1px solid #eee;padding-bottom:8px; }
                .inks-card dl   { margin:0; }
                .inks-card dt   { font-weight:600;font-size:12px;color:#666;margin-top:12px; }
                .inks-card dt:first-child { margin-top:0; }
                .inks-card dd   { margin:3px 0 0;font-size:14px;color:#111;word-break:break-word; }
                .inks-message   { white-space:pre-wrap;background:#f9f9f9;padding:10px 12px;border-radius:4px;font-size:13px;line-height:1.6;margin-top:4px;border:1px solid #eee; }
                @media (max-width:782px) { .inks-grid { grid-template-columns:1fr; } }
            </style>

            <p class="inks-meta-bar">
                <?php
                echo esc_html( sprintf(
                    /* translators: %s = human-readable date/time */
                    __( 'Submitted: %s', 'inksoft-woo-sync' ),
                    date_i18n( 'F j, Y \a\t g:i a', strtotime( $row->submitted_at ) )
                ) );
                ?>
                &nbsp;&mdash;&nbsp;
                <?php if ( $row->email_sent ) : ?>
                <span style="color:#007017;">&#10003; <?php esc_html_e( 'Email sent', 'inksoft-woo-sync' ); ?></span>
                <?php else : ?>
                <span style="color:#aaa;"><?php esc_html_e( 'Email not sent (check mail config)', 'inksoft-woo-sync' ); ?></span>
                <?php endif; ?>
            </p>

            <div class="inks-actions">
                <a href="<?php echo esc_url( 'mailto:' . rawurlencode( $row->contact_name ) . ' <' . $row->contact_email . '>' ); ?>" class="button button-primary">
                    <?php echo esc_html( sprintf( __( 'Reply to %s', 'inksoft-woo-sync' ), $row->contact_name ) ); ?>
                </a>
                <?php if ( $product_edit ) : ?>
                <a href="<?php echo esc_url( $product_edit ); ?>" class="button" target="_blank">
                    <?php esc_html_e( 'Edit Product', 'inksoft-woo-sync' ); ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button">
                    <?php esc_html_e( 'Back to List', 'inksoft-woo-sync' ); ?>
                </a>
            </div>

            <div class="inks-grid">
                <div class="inks-card">
                    <h3><?php esc_html_e( 'Product Details', 'inksoft-woo-sync' ); ?></h3>
                    <dl>
                        <dt><?php esc_html_e( 'Product Name', 'inksoft-woo-sync' ); ?></dt>
                        <dd><?php echo esc_html( $row->product_name ); ?></dd>

                        <?php if ( $row->product_id ) : ?>
                        <dt><?php esc_html_e( 'Product ID', 'inksoft-woo-sync' ); ?></dt>
                        <dd>#<?php echo (int) $row->product_id; ?></dd>
                        <?php endif; ?>

                        <dt><?php esc_html_e( 'Quantity Requested', 'inksoft-woo-sync' ); ?></dt>
                        <dd><?php echo $row->contact_quantity > 0 ? (int) $row->contact_quantity : '&mdash;'; ?></dd>

                        <?php if ( ! empty( $attrs ) ) : ?>
                        <dt><?php esc_html_e( 'Selected Options', 'inksoft-woo-sync' ); ?></dt>
                        <?php foreach ( $attrs as $label => $value ) : ?>
                        <dd><?php echo esc_html( $label ); ?>: <strong><?php echo esc_html( $value ); ?></strong></dd>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </dl>
                </div>

                <div class="inks-card">
                    <h3><?php esc_html_e( 'Customer Contact Info', 'inksoft-woo-sync' ); ?></h3>
                    <dl>
                        <dt><?php esc_html_e( 'Name', 'inksoft-woo-sync' ); ?></dt>
                        <dd><?php echo esc_html( $row->contact_name ); ?></dd>

                        <dt><?php esc_html_e( 'Email', 'inksoft-woo-sync' ); ?></dt>
                        <dd><a href="mailto:<?php echo esc_attr( $row->contact_email ); ?>"><?php echo esc_html( $row->contact_email ); ?></a></dd>

                        <?php if ( $row->contact_phone ) : ?>
                        <dt><?php esc_html_e( 'Phone', 'inksoft-woo-sync' ); ?></dt>
                        <dd><?php echo esc_html( $row->contact_phone ); ?></dd>
                        <?php endif; ?>

                        <dt><?php esc_html_e( 'Message / Requirements', 'inksoft-woo-sync' ); ?></dt>
                        <dd class="inks-message"><?php echo esc_html( $row->contact_message ); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <?php
    }
}
