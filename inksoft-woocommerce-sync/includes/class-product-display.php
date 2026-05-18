<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InkSoft_Product_Display {
    
    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_custom_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_custom_field' ) );
        add_action( 'template_redirect', array( $this, 'maybe_show_inksoft_embed' ) );
        add_action( 'wp_ajax_inksoft_contact_inquiry', array( $this, 'handle_contact_inquiry' ) );
        add_action( 'wp_ajax_nopriv_inksoft_contact_inquiry', array( $this, 'handle_contact_inquiry' ) );
    }

    public function add_custom_field() {
        global $post;

        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );

        if ( empty( $inksoft_product_id ) ) {
            return;
        }

        $display_type     = get_post_meta( $post->ID, '_inksoft_display_type', true ) ?: 'designer';
        $disable_designer = get_post_meta( $post->ID, '_disable_inksoft_designer', true );

        echo '<div class="options_group show_if_simple show_if_variable">';

        echo '<p class="form-field" style="padding: 12px; background: #f0f0f1; margin: 9px 0;"><strong>InkSoft Product ID:</strong> ' . esc_html( $inksoft_product_id ) . '</p>';

        woocommerce_wp_select( array(
            'id'          => '_inksoft_display_type',
            'label'       => 'InkSoft Display Type',
            'description' => 'Choose what to show on the product page for InkSoft products.',
            'value'       => $display_type,
            'options'     => array(
                'designer'     => 'InkSoft Designer (embed)',
                'contact_form' => 'Contact / Inquiry Form',
                'woo_only'     => 'WooCommerce Only (no InkSoft)',
            ),
            'wrapper_class' => 'show_if_simple show_if_variable',
        ) );

        echo '</div>';
    }

    public function save_custom_field( $post_id ) {
        $allowed = array( 'designer', 'contact_form', 'woo_only' );
        $type    = isset( $_POST['_inksoft_display_type'] ) ? sanitize_key( $_POST['_inksoft_display_type'] ) : 'designer';
        if ( ! in_array( $type, $allowed, true ) ) {
            $type = 'designer';
        }
        update_post_meta( $post_id, '_inksoft_display_type', $type );

        // Keep backward-compat field in sync.
        $disable = ( $type === 'woo_only' ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_disable_inksoft_designer', $disable );
    }

    public function handle_contact_inquiry() {
        check_ajax_referer( 'inksoft_contact_nonce', 'nonce' );

        $name       = sanitize_text_field( $_POST['contact_name']    ?? '' );
        $email      = sanitize_email( $_POST['contact_email']        ?? '' );
        $phone      = sanitize_text_field( $_POST['contact_phone']   ?? '' );
        $quantity   = absint( $_POST['contact_quantity']             ?? 0 );
        $message    = sanitize_textarea_field( $_POST['contact_message'] ?? '' );
        $product_id = absint( $_POST['product_id']                   ?? 0 );

        // Attribute selections (size, color, etc.) sent as JSON string.
        $attrs_raw  = sanitize_text_field( $_POST['contact_attrs'] ?? '' );
        $attrs      = array();
        if ( ! empty( $attrs_raw ) ) {
            $decoded = json_decode( wp_unslash( $attrs_raw ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $label => $value ) {
                    $attrs[ sanitize_text_field( $label ) ] = sanitize_text_field( $value );
                }
            }
        }

        if ( empty( $name ) || ! is_email( $email ) || empty( $message ) ) {
            wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
        }

        $product_name = $product_id ? get_the_title( $product_id ) : 'Unknown Product';
        $admin_email  = get_option( 'admin_email' );

        $attr_lines = '';
        foreach ( $attrs as $label => $value ) {
            if ( ! empty( $value ) ) {
                $attr_lines .= $label . ': ' . $value . "\n";
            }
        }

        $subject = 'New Inquiry: ' . $product_name;
        $body    = "Product: {$product_name}\n\n"
                 . ( $attr_lines ? "--- Product Details ---\n{$attr_lines}\n" : '' )
                 . "Quantity: {$quantity}\n\n"
                 . "--- Contact Info ---\n"
                 . "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\n\n"
                 . "Message:\n{$message}";
        $headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

        $sent = wp_mail( $admin_email, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success( array( 'message' => 'Thank you! We will contact you shortly.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Sorry, there was an error sending your message. Please try again.' ) );
        }
    }

    public function maybe_show_inksoft_embed() {
        if ( ! is_product() ) {
            return;
        }

        global $post;

        $inksoft_product_id = get_post_meta( $post->ID, '_inksoft_product_id', true );
        $disable_designer   = get_post_meta( $post->ID, '_disable_inksoft_designer', true );
        $inksoft_store_uri  = get_post_meta( $post->ID, '_inksoft_store_uri', true );

        // Not an InkSoft synced product — leave WooCommerce alone.
        if ( empty( $inksoft_product_id ) ) {
            return;
        }

        // Per-product display type (designer | contact_form | woo_only).
        $display_type = get_post_meta( $post->ID, '_inksoft_display_type', true );

        // Back-compat: old checkbox mapped to woo_only.
        if ( empty( $display_type ) ) {
            $disable_designer = get_post_meta( $post->ID, '_disable_inksoft_designer', true );
            $display_type     = ( $disable_designer === 'yes' ) ? 'woo_only' : 'designer';
        }

        // woo_only per product: show standard WooCommerce page.
        if ( $display_type === 'woo_only' ) {
            return;
        }

        // contact_form: normal WC product page + "Request a Quote" button → modal popup.
        if ( $display_type === 'contact_form' ) {
            add_filter( 'body_class', function( $classes ) {
                $classes[] = 'inksoft-contact-mode';
                return $classes;
            } );
            add_action( 'wp_head', array( $this, 'render_contact_mode_css' ) );
            add_action( 'woocommerce_single_product_summary', array( $this, 'render_quote_button' ), 30 );
            add_action( 'wp_footer', array( $this, 'render_contact_modal' ) );
            return;
        }

        // --- designer mode (default) ---

        $settings = get_option( 'inksoft_woo_settings', array() );
        $mode     = ! empty( $settings['product_display_mode'] ) ? $settings['product_display_mode'] : 'embed_only';

        // Global woo_only setting overrides per-product designer.
        if ( $mode === 'woo_only' ) {
            return;
        }

        $store_uri_raw = ! empty( $inksoft_store_uri ) ? $inksoft_store_uri : ( $settings['stores_single'] ?? '' );
        if ( empty( $store_uri_raw ) ) {
            return;
        }

        if ( $mode === 'embed_only' ) {
            // Layer 1: PHP hook removal — works for classic WooCommerce templates.
            add_action( 'woocommerce_single_product_summary', function() {
                remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
                remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
            }, 1 );
            remove_all_actions( 'woocommerce_after_single_product_summary' );

            // Render the embed before the product summary.
            add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_inksoft_embed' ), 5 );

            // Layer 2: body classes + CSS.
            // In block themes (FSE / Twenty Twenty-Five), WooCommerce's hook output is placed BEFORE <main>,
            // so hiding the entire <main> hides all WC product content while keeping the embed visible.
            // In classic themes, the embed is inside <main> so we use element-specific selectors instead.
            $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
            add_filter( 'body_class', function( $classes ) use ( $is_block_theme ) {
                $classes[] = 'inksoft-embed-only';
                if ( $is_block_theme ) {
                    $classes[] = 'inksoft-embed-only-block';
                }
                return $classes;
            } );

            add_action( 'wp_head', function() {
                echo '<style id="inksoft-embed-only-css">
/* InkSoft: embed-only mode — show only the embed, hide all WooCommerce product content */

/* Block themes (FSE / Twenty Twenty-Five): embed is injected before <main> by the hook,
   so hiding <main> removes all WC product blocks while keeping the embed visible. */
body.inksoft-embed-only-block main {
    display: none !important;
}

/* Classic WooCommerce: hide gallery, summary, cart, breadcrumb, tabs, related */
body.inksoft-embed-only .woocommerce-product-gallery,
body.inksoft-embed-only .summary.entry-summary,
body.inksoft-embed-only .woocommerce-breadcrumb,
body.inksoft-embed-only form.cart,
body.inksoft-embed-only .cart,
body.inksoft-embed-only .woocommerce-tabs,
body.inksoft-embed-only .related.products,
body.inksoft-embed-only .up-sells.products,

/* WooCommerce block elements (non-FSE block theme) */
body.inksoft-embed-only .wp-block-woocommerce-product-image-gallery,
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

    public function render_contact_mode_css() {
        echo '<style id="inksoft-contact-mode-css">
/* InkSoft: contact-mode — hide Add to Cart only, keep all product content visible */
body.inksoft-contact-mode .cart,
body.inksoft-contact-mode form.cart,
body.inksoft-contact-mode .woocommerce-variation-add-to-cart,
body.inksoft-contact-mode .wp-block-add-to-cart-form,
body.inksoft-contact-mode .wp-block-woocommerce-add-to-cart-form {
    display: none !important;
}

/* Modal overlay */
#inksoft-quote-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
#inksoft-quote-modal.is-open {
    display: flex;
}
#inksoft-quote-modal .iq-box {
    background: #fff;
    border-radius: 8px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px 32px 24px;
    box-sizing: border-box;
    position: relative;
    box-shadow: 0 8px 40px rgba(0,0,0,.25);
}
#inksoft-quote-modal .iq-close {
    position: absolute;
    top: 12px;
    right: 16px;
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    color: #555;
    padding: 4px 8px;
}
#inksoft-quote-modal .iq-close:hover { color: #000; }
#inksoft-quote-modal h3 {
    margin: 0 0 6px;
    font-size: 18px;
}
#inksoft-quote-modal .iq-product-name {
    color: #555;
    margin: 0 0 18px;
    font-size: 14px;
}
#inksoft-quote-modal .iq-field {
    margin-bottom: 14px;
}
#inksoft-quote-modal .iq-field label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 5px;
}
#inksoft-quote-modal .iq-field input,
#inksoft-quote-modal .iq-field select,
#inksoft-quote-modal .iq-field textarea {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
    font-size: 14px;
    font-family: inherit;
}
#inksoft-quote-modal .iq-field textarea { resize: vertical; }
#inksoft-quote-modal .iq-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
#inksoft-quote-modal .iq-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
}
#inksoft-quote-modal .iq-submit {
    background: #333;
    color: #fff;
    border: none;
    padding: 11px 28px;
    border-radius: 4px;
    font-size: 15px;
    cursor: pointer;
    font-family: inherit;
}
#inksoft-quote-modal .iq-submit:hover { background: #000; }
#inksoft-quote-modal .iq-spinner { color: #555; font-size: 13px; display: none; }
#inksoft-quote-modal .iq-response {
    display: none;
    padding: 11px 14px;
    border-radius: 4px;
    font-size: 14px;
    margin-top: 14px;
}
.inksoft-quote-btn {
    margin-top: 8px;
    width: 100%;
}
</style>' . "\n";
    }

    public function render_quote_button() {
        echo '<button type="button" class="inksoft-quote-btn button alt" id="inksoft-open-quote">'
           . esc_html__( 'Request a Quote / Contact Us', 'inksoft-woocommerce-sync' )
           . '</button>';
    }

    public function render_contact_modal() {
        if ( ! is_product() ) {
            return;
        }

        global $post;
        $product_id   = $post->ID;
        $product_name = get_the_title( $product_id );
        $nonce        = wp_create_nonce( 'inksoft_contact_nonce' );
        $ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );

        // Collect WooCommerce product attributes for dropdowns.
        $product    = wc_get_product( $product_id );
        $attr_fields = array();
        if ( $product ) {
            foreach ( $product->get_attributes() as $attr ) {
                $label   = wc_attribute_label( $attr->get_name() );
                $options = $attr->get_terms() ? wp_list_pluck( $attr->get_terms(), 'name' )
                                              : $attr->get_options();
                if ( ! empty( $options ) ) {
                    $attr_fields[] = array(
                        'label'   => $label,
                        'options' => $options,
                    );
                }
            }
        }

        ?>
<div id="inksoft-quote-modal" role="dialog" aria-modal="true" aria-label="Request a Quote">
    <div class="iq-box">
        <button class="iq-close" id="inksoft-close-quote" aria-label="Close">&times;</button>
        <h3>Request a Quote / Place an Order</h3>
        <p class="iq-product-name">Inquiring about: <strong><?php echo esc_html( $product_name ); ?></strong></p>

        <div id="inksoft-quote-response" class="iq-response"></div>

        <form id="inksoft-quote-form" novalidate>

            <?php if ( ! empty( $attr_fields ) ) : ?>
            <div class="iq-row">
                <?php foreach ( $attr_fields as $af ) : ?>
                <div class="iq-field">
                    <label><?php echo esc_html( $af['label'] ); ?></label>
                    <select name="attr_<?php echo esc_attr( sanitize_title( $af['label'] ) ); ?>" data-attr-label="<?php echo esc_attr( $af['label'] ); ?>">
                        <option value="">-- Select --</option>
                        <?php foreach ( $af['options'] as $opt ) : ?>
                        <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="iq-field">
                <label>Quantity</label>
                <input type="number" name="contact_quantity" min="1" placeholder="e.g. 50" />
            </div>

            <div class="iq-row">
                <div class="iq-field">
                    <label>Name <span style="color:red;">*</span></label>
                    <input type="text" name="contact_name" required placeholder="Your name" />
                </div>
                <div class="iq-field">
                    <label>Email <span style="color:red;">*</span></label>
                    <input type="email" name="contact_email" required placeholder="your@email.com" />
                </div>
            </div>

            <div class="iq-field">
                <label>Phone</label>
                <input type="tel" name="contact_phone" placeholder="(optional)" />
            </div>

            <div class="iq-field">
                <label>Message / Requirements <span style="color:red;">*</span></label>
                <textarea name="contact_message" required rows="4" placeholder="Describe your requirements, artwork details, deadline, etc."></textarea>
            </div>

            <div class="iq-actions">
                <button type="submit" class="iq-submit">Send Inquiry</button>
                <span class="iq-spinner" id="inksoft-quote-spinner">Sending&hellip;</span>
            </div>

        </form>
    </div>
</div>
<script type="text/javascript">
(function() {
    var modal   = document.getElementById('inksoft-quote-modal');
    var openBtn = document.getElementById('inksoft-open-quote');
    var closeBtn= document.getElementById('inksoft-close-quote');
    var form    = document.getElementById('inksoft-quote-form');
    var resp    = document.getElementById('inksoft-quote-response');
    var spinner = document.getElementById('inksoft-quote-spinner');

    if (!modal) return;

    function openModal() {
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }
    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        resp.style.display = 'none';
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);

    // Close on overlay click (outside the box).
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });

    // Close on ESC key.
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // Collect attribute selects into a JSON object.
        var attrs = {};
        form.querySelectorAll('select[data-attr-label]').forEach(function(sel) {
            var label = sel.getAttribute('data-attr-label');
            if (sel.value) attrs[label] = sel.value;
        });

        var data = new FormData(form);
        data.append('action',        'inksoft_contact_inquiry');
        data.append('nonce',         '<?php echo esc_js( $nonce ); ?>');
        data.append('product_id',    '<?php echo esc_js( $product_id ); ?>');
        data.append('contact_attrs', JSON.stringify(attrs));

        spinner.style.display = 'inline';
        resp.style.display    = 'none';

        fetch('<?php echo $ajax_url; ?>', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                spinner.style.display = 'none';
                resp.style.display    = 'block';
                if (json.success) {
                    resp.style.background = '#d4edda';
                    resp.style.color      = '#155724';
                    resp.style.border     = '1px solid #c3e6cb';
                    resp.textContent      = json.data.message;
                    form.reset();
                    setTimeout(closeModal, 2500);
                } else {
                    resp.style.background = '#f8d7da';
                    resp.style.color      = '#721c24';
                    resp.style.border     = '1px solid #f5c6cb';
                    resp.textContent      = json.data.message;
                }
            })
            .catch(function() {
                spinner.style.display = 'none';
                resp.style.display    = 'block';
                resp.style.background = '#f8d7da';
                resp.style.color      = '#721c24';
                resp.style.border     = '1px solid #f5c6cb';
                resp.textContent      = 'Error submitting form. Please try again.';
            });
    });
})();
</script>
        <?php
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
