<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Product_Display {
    
    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_custom_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_custom_field' ) );
        add_action( 'template_redirect', array( $this, 'maybe_show_inksoft_embed' ) );
    }

    public function add_custom_field() {
        global $post;
        
        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );
        $disable_designer = get_post_meta( $post->ID, '_disable_inksoft_designer', true );
        
        if ( empty( $inksoft_product_id ) ) {
            return;
        }
        
        echo '<div class="options_group show_if_simple show_if_variable">';
        
        echo '<p class="form-field" style="padding: 12px; background: #f0f0f1; margin: 9px 0;"><strong>InkSoft Product ID:</strong> ' . esc_html( $inksoft_product_id ) . '</p>';
        
        woocommerce_wp_checkbox( array(
            'id' => '_disable_inksoft_designer',
            'label' => 'Disable InkSoft Designer',
            'description' => 'Check this to show default WooCommerce product page instead of InkSoft embed',
            'value' => $disable_designer === 'yes' ? 'yes' : 'no',
            'wrapper_class' => 'show_if_simple show_if_variable'
        ) );
        
        echo '</div>';
    }

    public function save_custom_field( $post_id ) {
        $disable = isset( $_POST['_disable_inksoft_designer'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_disable_inksoft_designer', $disable );
    }

    public function maybe_show_inksoft_embed() {
        if ( ! is_product() ) {
            return;
        }
        
        global $post;
        
        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );
        $disable_designer = get_post_meta( $post->ID, '_disable_inksoft_designer', true );
        $inksoft_store_uri = get_post_meta( $post->ID, '_inksoft_store_uri', true );
        
        if ( empty( $inksoft_product_id ) || $disable_designer === 'yes' ) {
            return;
        }
        
        $settings = get_option( 'inksoft_woo_settings', array() );
        $store_uri_raw = ! empty( $inksoft_store_uri ) ? $inksoft_store_uri : ( $settings['stores_single'] ?? '' );
        
        if ( empty( $store_uri_raw ) ) {
            return;
        }
        
        $store_uri = str_replace( '_', '', $store_uri_raw );
        
        // Remove EVERYTHING from WooCommerce single product page
        // Before content (breadcrumbs etc)
        remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
        
        // Before single product
        remove_all_actions( 'woocommerce_before_single_product' );
        
        // Product images & sale flash
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
        remove_all_actions( 'woocommerce_before_single_product_summary' );
        
        // Main summary (title, price, add to cart, meta, etc.)
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
        remove_all_actions( 'woocommerce_single_product_summary' );
        
        // Tabs, upsells, related products
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
        remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
        remove_all_actions( 'woocommerce_after_single_product_summary' );
        
        // After single product
        remove_all_actions( 'woocommerce_after_single_product' );
        
        // Add InkSoft embed to replace all content
        add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_inksoft_embed' ), 5 );
    }
    
    public function render_inksoft_embed() {
        global $post;
        
        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );
        $inksoft_store_uri = get_post_meta( $post->ID, '_inksoft_store_uri', true );
        $settings = get_option( 'inksoft_woo_settings', array() );
        $store_uri_raw = ! empty( $inksoft_store_uri ) ? $inksoft_store_uri : ( $settings['stores_single'] ?? '' );
        $store_uri = str_replace( '_', '', $store_uri_raw );
        
        echo '<div class="inksoft-embed-container" style="width: 100%;">';
        echo '<div id="inksoftEmbed" style="width: 100%; height: 720px; padding: 0; margin: 0; border: 0;"></div>';
        echo '</div>';
        echo '<script type="text/javascript">
        (function() {
          function init() {
            let scriptElement = document.createElement("script");
            scriptElement.type = "text/javascript";
            scriptElement.async = true;
            scriptElement.src = "https://cdn.inksoft.com/FrontendApps/storefront/assets/scripts/designer-embed.js";
            scriptElement.onload = function() { launchDesignStudio() };
            document.getElementsByTagName("body")[0].appendChild(scriptElement);
          }

          function launchDesignStudio() {
            window.inksoftApi.launchEmbeddedDesignStudio({
              targetElementId: "inksoftEmbed",
              domain: "https://stores.inksoft.com",
              cdnDomain: "https://cdn.inksoft.com",
              storeUri: "' . esc_js( $store_uri ) . '",
              productId: ' . intval( $inksoft_product_id ) . '
            });
          }

          init();
        })();
        </script>';
    }
}

new InkSoft_Product_Display();
