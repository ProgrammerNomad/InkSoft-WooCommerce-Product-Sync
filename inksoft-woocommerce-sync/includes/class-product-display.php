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
        $disable_designer   = get_post_meta( $post->ID, '_disable_inksoft_designer', true );
        $inksoft_store_uri  = get_post_meta( $post->ID, '_inksoft_store_uri', true );

        // Per-product override: checkbox forces WooCommerce-only for this product.
        if ( $disable_designer === 'yes' ) {
            return;
        }

        // Not an InkSoft synced product — leave WooCommerce alone.
        if ( empty( $inksoft_product_id ) ) {
            return;
        }

        $settings = get_option( 'inksoft_woo_settings', array() );
        $mode     = ! empty( $settings['product_display_mode'] ) ? $settings['product_display_mode'] : 'embed_only';

        // woo_only: show standard WooCommerce page, no embed at all.
        if ( $mode === 'woo_only' ) {
            return;
        }

        $store_uri_raw = ! empty( $inksoft_store_uri ) ? $inksoft_store_uri : ( $settings['stores_single'] ?? '' );
        if ( empty( $store_uri_raw ) ) {
            return;
        }

        if ( $mode === 'embed_only' ) {
            // Layer 1: PHP hook removal — works for classic WooCommerce templates.
            // Remove only the Add to Cart form; keep images, title, price, description.
            add_action( 'woocommerce_single_product_summary', function() {
                remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
                remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
            }, 1 );
            remove_all_actions( 'woocommerce_after_single_product_summary' );

            // Render the embed before the product summary.
            add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_inksoft_embed' ), 5 );

            // Layer 2: CSS hiding — guaranteed fallback for block-based WooCommerce (WooCommerce 8+ / block themes).
            // Block-rendered content does not go through classic action hooks, so CSS is the only reliable way
            // to suppress it without overriding the entire block template.
            add_filter( 'body_class', function( $classes ) {
                $classes[] = 'inksoft-embed-only';
                return $classes;
            } );

            add_action( 'wp_head', function() {
                echo '<style id="inksoft-embed-only-css">
/* InkSoft: embed-only mode — hide Add to Cart, keep images + product info visible */

/* Classic WooCommerce: hide add-to-cart form, tabs, related/upsell sections */
body.inksoft-embed-only .cart,
body.inksoft-embed-only form.cart,
body.inksoft-embed-only .woocommerce-tabs,
body.inksoft-embed-only .related.products,
body.inksoft-embed-only .up-sells.products,

/* WooCommerce block theme (WC 8+): hide cart/checkout blocks only */
body.inksoft-embed-only .wp-block-add-to-cart-form,
body.inksoft-embed-only .wp-block-woocommerce-add-to-cart-form,
body.inksoft-embed-only .wp-block-woocommerce-product-meta,
body.inksoft-embed-only .wp-block-woocommerce-product-sku,
body.inksoft-embed-only .wp-block-woocommerce-product-stock-indicator,
body.inksoft-embed-only .wp-block-woocommerce-related-products {
    display: none !important;
}

/* Ensure the embed container stretches full width */
body.inksoft-embed-only .embed-container {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 0 2em 0 !important;
    padding: 0 !important;
}
</style>' . "\n";
            } );

        } elseif ( $mode === 'both' ) {
            // Prepend the embed above the standard WooCommerce product page; keep all WooCommerce content intact.
            add_action( 'woocommerce_before_single_product', array( $this, 'render_inksoft_embed' ), 5 );
        }
    }

    public function render_inksoft_embed() {
        global $post;

        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );
        $inksoft_store_uri  = get_post_meta( $post->ID, '_inksoft_store_uri', true );
        $settings           = get_option( 'inksoft_woo_settings', array() );
        $store_uri_raw      = ! empty( $inksoft_store_uri ) ? $inksoft_store_uri : ( $settings['stores_single'] ?? '' );
        $store_uri          = str_replace( '_', '', $store_uri_raw );

        echo '<div class="embed-container" style="width: 100%;">';
        echo '<div id="inksoftEmbed" style="width: 100%; height: 720px; padding: 0; margin: 0; border: 0; max-height: 100%;"></div>';
        echo '</div>';
        echo '<script type="text/javascript">
        (function() {
          function init() {
            var scriptElement = document.createElement("script");
            scriptElement.type = "text/javascript";
            scriptElement.async = true;
            scriptElement.src = "https://stores.inksoft.com/FrontendApps/storefront/assets/scripts/designer-embed.js";
            scriptElement.onload = function() { launchDesignStudio(); };
            document.getElementsByTagName("body")[0].appendChild(scriptElement);
          }

          function launchDesignStudio() {
            window.inksoftApi.launchEmbeddedDesignStudio({
              targetElementId: "inksoftEmbed",
              domain: "https://stores.inksoft.com",
              cdnDomain: "https://stores.inksoft.com",
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
