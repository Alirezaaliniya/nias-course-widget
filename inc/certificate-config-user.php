<?php
// Make sure we are in WordPress context
if (!defined('ABSPATH')) {
    exit;
}

// Add QR Code dependencies
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

/**
 * Generate a random certificate code
 * 
 * @param int $user_id The user ID
 * @param int $product_id The product ID
 * @return string Random alphanumeric code
 */
function generate_certificate_code($user_id, $product_id) {
    // CSPRNG over an alphabet without look-alikes (0/O, 1/I): 14 random
    // characters ≈ 70 bits, so codes cannot be guessed or enumerated, and are
    // easy to read off a printed certificate. Same CERT-XXXX-XXXXXXXXXX shape
    // as before.
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code     = '';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = 'CERT-' . nias_cert_random_chars($alphabet, 4) . '-' . nias_cert_random_chars($alphabet, 10);
        if (!function_exists('nias_cert_code_exists') || !nias_cert_code_exists($code)) {
            break;
        }
    }
    return apply_filters('nias_certificate_generated_code', $code, $user_id, $product_id);
}

function add_certificate_meta_to_existing_product_purchasers() {
    global $wpdb;
    
    // Updated query to work with newer WooCommerce versions
    $order_query = $wpdb->prepare(
        "SELECT DISTINCT p.ID, pm.meta_value AS user_id, oim.meta_value AS product_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        WHERE p.post_type = 'shop_order'
        AND p.post_status = 'wc-completed'
        AND oim.meta_key = '_product_id'
        AND pm.meta_value > 0",
        1819
    );
    
    $results = $wpdb->get_results($order_query);
    
    if (empty($results)) {
        error_log('No completed orders found with product ID 1819');
        return;
    }
    
    $processed = 0;
    $updated = 0;
    
    foreach ($results as $result) {
        $user_id = $result->user_id;
        $product_id = $result->product_id;
        $order_id = $result->ID;
        
        // If it's a registered user (not a guest checkout)
        if ($user_id > 0) {
            $existing_code = get_user_meta($user_id, 'usercertificate_code', true);
            
            // Check if user has a certificate code that is empty or doesn't exist
            if (empty($existing_code)) {
                // Generate and add the random certificate code
                $new_code = generate_certificate_code($user_id, $product_id);
                update_user_meta($user_id, 'usercertificate_code', $new_code);
                $processed++;
                error_log("Added new certificate code $new_code to user ID: $user_id (Order ID: $order_id)");
            } else {
                // User already has a code
                error_log("User ID: $user_id already has certificate code: $existing_code");
            }
        }
    }
    
    error_log("Certificate processing complete. Added $processed new certificate codes.");
}

// Uncomment the line below to process existing orders, run it ONCE, then comment it out again
// add_certificate_meta_to_existing_product_purchasers();



/**
 * Add certificate meta field to WordPress user edit page
 */

// Display the certificate field in the user profile admin section
function display_certificate_code_field($user) {
    // Check if current user has permission to edit users or is viewing their own profile
    if (!current_user_can('edit_users') && get_current_user_id() != $user->ID) {
        return;
    }
    
    // Get the certificate code if it exists
    $certificate_code = get_user_meta($user->ID, 'usercertificate_code', true);
    // Only staff may change a code; a student editing their own profile must
    // not be able to type in a code that would then verify as genuine.
    $can_edit = current_user_can('edit_users');
    ?>
    <h3><?php _e('اطلاعات مدرک', 'nias-course-widget'); ?></h3>

    <table class="form-table">
        <tr>
            <th><label for="usercertificate_code"><?php _e('کد گواهی', 'nias-course-widget'); ?></label></th>
            <td>
                <?php if (!$can_edit) : ?>
                    <code dir="ltr"><?php echo $certificate_code ? esc_html($certificate_code) : '—'; ?></code>
                </td></tr></table>
                <?php return; endif; ?>
                <input type="text" name="usercertificate_code" id="usercertificate_code"
                    value="<?php echo esc_attr($certificate_code); ?>" class="regular-text" />
                <?php if (empty($certificate_code)) : ?>
                    <p class="description"><?php _e('هنوز کد گواهی اختصاص داده نشده است.', 'nias-course-widget'); ?></p>
                    <button type="button" class="button" id="generate_certificate" 
                        onclick="document.getElementById('usercertificate_code').value='<?php echo esc_attr(generate_certificate_code($user->ID, 0)); ?>';">
                        <?php _e('تولید کد', 'nias-course-widget'); ?>
                    </button>
                <?php else : ?>
                    <p class="description"><?php _e('این کد به طور خودکار هنگام خرید محصول گواهی توسط کاربر ایجاد شد.', 'nias-course-widget'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'display_certificate_code_field');
add_action('edit_user_profile', 'display_certificate_code_field');
// Save the certificate field when the user profile is updated
function save_certificate_code_field($user_id) {
    // Codes are issued by the system; only staff may change them.
    if (!current_user_can('edit_users')) {
        return false;
    }

    // Update the certificate code if it's set in the form
    if (isset($_POST['usercertificate_code'])) {
        $code = nias_cert_normalize_code(wp_unslash($_POST['usercertificate_code']));
        if ($code !== '' && $code !== nias_cert_normalize_code(get_user_meta($user_id, 'usercertificate_code', true)) && nias_cert_code_exists($code)) {
            return false; // never let two people share one code
        }
        
        // If submitted code is empty but user should have a certificate (purchased product)
        if (empty($code) && has_user_purchased_certificate($user_id)) {
            $code = generate_certificate_code($user_id, 0);
            error_log("Auto-generated certificate code for user ID: $user_id during profile save");
        }
        
        update_user_meta($user_id, 'usercertificate_code', $code);
    }
}
add_action('personal_options_update', 'save_certificate_code_field');
add_action('edit_user_profile_update', 'save_certificate_code_field');

/**
 * Helper function to check if user has purchased the certificate product
 */
function has_user_purchased_certificate($user_id) {
    if (!function_exists('carbon_get_theme_option')) {
        return false;
    }

    $display_type = carbon_get_theme_option('certificate_display_type');
    
    // Get allowed product IDs based on display type setting
    $allowed_product_ids = array();
    
    if ($display_type === 'all') {
        // Every product counts, but the user must still have bought one
        // (checked below) — returning true here handed a certificate code
        // to every account.
    } elseif ($display_type === 'selected') {
        // Get specifically selected products
        $allowed_product_ids = carbon_get_theme_option('certificate_selected_products') ?: array();
    } elseif ($display_type === 'category') {
        // Get products from selected categories
        $selected_categories = carbon_get_theme_option('certificate_selected_categories') ?: array();
        if (!empty($selected_categories)) {
            $products = get_posts(array(
                'post_type' => 'product', // (category mode: WooCommerce categories)
                'posts_per_page' => -1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $selected_categories
                    )
                )
            ));
            $allowed_product_ids = wp_list_pluck($products, 'ID');
        }
    }

    // If no products are configured, return false
    if ($display_type !== 'all' && empty($allowed_product_ids)) {
        return false;
    }

    // Free «دوره» courses the user started count like a purchase.
    if (function_exists('nias_free_course_enrolments')) {
        foreach (array_keys(nias_free_course_enrolments($user_id)) as $pid) {
            if ($display_type === 'all' || in_array($pid, array_map('intval', (array) $allowed_product_ids), true)) {
                return true;
            }
        }
    }

    // Get customer orders
    $orders = function_exists('wc_get_orders') ? wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => 'completed',
        'limit' => -1,
    )) : array();
    
    if (!empty($orders)) {
        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                foreach ($order->get_items() as $item) {
                    if ($item instanceof WC_Order_Item_Product) {
                        $product_id = $item->get_product_id();
                        // For "all" display type or if the product is in allowed products list
                        if ($display_type === 'all' || in_array($product_id, $allowed_product_ids)) {
                            return true;
                        }
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * Add a column to show certificate code in the users list table
 */
function add_certificate_column($columns) {
    $columns['certificate_code'] = __('Certificate', 'nias-course-widget');
    return $columns;
}
add_filter('manage_users_columns', 'add_certificate_column');

/**
 * Display certificate code in the users list table
 */
function show_certificate_column_content($value, $column_name, $user_id) {
    if ('certificate_code' === $column_name) {
        // Read-only: rendering a list must not issue certificates (it used to
        // run an orders query and write a code for every row on the screen).
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . nias_cert_table() . " WHERE user_id = %d AND status = 'valid'",
            (int) $user_id
        ));
        if ($count) {
            return '<a href="' . esc_url(admin_url('admin.php?page=nias-course-cert-issued&user_id=' . (int) $user_id)) . '">' . esc_html(sprintf(_n('%s مدرک', '%s مدرک', $count, 'nias-course-widget'), number_format_i18n($count))) . '</a>';
        }
        $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
        return $certificate_code ? esc_html($certificate_code) : '—';
    }
    return $value;
}
add_filter('manage_users_custom_column', 'show_certificate_column_content', 10, 3);






/**
 * Helper function to get the certificate display page URL
 * 
 * @return string The URL of the selected certificate display page or home URL as fallback
 */


/**
 * Generate a button with certificate verification link for users with a certificate code
 * 
 * @param int $user_id The ID of the user
 * @return string HTML button or empty string if no certificate code exists
 */
function generate_certificate_verification_button($user_id) {
    // Get the certificate code for the user
    $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
    
    // If no certificate code exists, return empty string
    if (empty($certificate_code)) {
        return '';
    }
    
    // Generate the verification link with both user ID and certificate code
    $verification_link = nias_cert_verify_url($certificate_code);
    
    // Create a button HTML
    $button_html = sprintf(
        '<a href="%s" class="certificate-verification-button button" target="_blank">%s</a>',
        esc_url($verification_link),
        __('دریافت مدرک', 'nias-course-widget')
    );
    
    return $button_html;
}

/**
 * Generate a button with certificate verification link for users with a certificate code
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML button or empty string if no certificate code exists
 */
function userbutton_certificate_shortcode($atts) {
    // Parse attributes, allowing user_id to be passed
    $atts = shortcode_atts(array(
        'user_id' => get_current_user_id(), // Default to current user if no ID provided
    ), $atts, 'user_certificate');
    
    // Get the user ID
    $user_id = intval($atts['user_id']);
    
    // Get the certificate code for the user
    $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
    
    // If no certificate code exists, return empty string
    if (empty($certificate_code)) {
        return '';
    }
    
    // Generate the verification link with both user ID and certificate code
    $verification_link = nias_cert_verify_url($certificate_code);
    
    // Create a button HTML
    $button_html = sprintf(
        '<a href="%s" class="certificate-verification-button button" target="_blank" style="padding: 20px;">%s</a>',
        esc_url($verification_link),
        __('دریافت مدرک  دارای qrcode', 'nias-course-widget')
    );
    
    return $button_html;
}

/**
 * Generate an iframe with certificate verification link for users with a certificate code
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML iframe or empty string if no certificate code exists
 */
function user_certificate_iframe_shortcode($atts) {
    // Parse attributes, allowing user_id and custom iframe attributes
    $atts = shortcode_atts(array(
        'user_id' => get_current_user_id(), // Default to current user if no ID provided
        'width' => '100%', // Default width
        'height' => '600px', // Default height
        'class' => 'certificate-verification-iframe', // Default class
    ), $atts, 'user_certificate_iframe');
    
    // Get the user ID
    $user_id = intval($atts['user_id']);
    
    // Get the certificate code for the user
    $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
    
    // If no certificate code exists, return empty string
    if (empty($certificate_code)) {
        return '';
    }
    
    // Generate the verification link
    $verification_link = nias_cert_verify_url($certificate_code);
    
    // Create an iframe HTML
    $iframe_html = sprintf(
        '<iframe src="%s" width="%s" height="%s" class="%s" frameborder="0"></iframe>',
        esc_url($verification_link),
        esc_attr($atts['width']),
        esc_attr($atts['height']),
        esc_attr($atts['class'])
    );
    
    return $iframe_html;
}

/**
 * Shortcode to display certificate verification information
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML content with certificate information
 */
function user_certificate_shortcode($atts) {
    // Legacy [certificate_info]: now answered by the central verification
    // (registry status, revocation, name masking, rate limiting) instead of
    // the old code that always printed "تأیید شده".
    $code    = '';
    $user_id = 0;
    if (isset($_GET['cert_code'])) {
        $code = sanitize_text_field(wp_unslash($_GET['cert_code']));
    } elseif (isset($_GET['code'])) {
        $code    = sanitize_text_field(wp_unslash($_GET['code']));
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    }
    if ($code === '') {
        return do_shortcode('[nias_certificate_verify]');
    }
    ob_start();
    nias_cert_verify_styles();
    echo '<div class="ncv" dir="rtl"><div class="ncv-card">';
    echo nias_cert_verify_result_html(nias_cert_verify_lookup($code, $user_id)); // phpcs:ignore -- escaped inside
    echo '</div></div>';
    return ob_get_clean();
}

add_shortcode('certificate_info', 'user_certificate_shortcode');

/**
 * Add a column to show certificate verification button in user profile
 */
function add_certificate_verification_button_to_profile($user) {
    $verification_button = generate_certificate_verification_button($user->ID);
    
    if (!empty($verification_button)) {
        ?>
        <h3>تاییدیه مدرک</h3>
        <table class="form-table">
            <tr>
                <th>لینک تاییدیه</th>
                <td>
                    <?php echo $verification_button; ?>
                    <p class="description">این لینک را میتوانید به اشتراک بگذارید.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}
add_action('show_user_profile', 'add_certificate_verification_button_to_profile');
add_action('edit_user_profile', 'add_certificate_verification_button_to_profile');

function get_certificate_display_page_url() {
    if (!function_exists('carbon_get_theme_option')) {
        return home_url('/verify1'); // Fallback if the data layer is not available
    }

    $page_id = carbon_get_theme_option('certificate_display_page');
    
    if (!empty($page_id) && get_post_status($page_id) === 'publish') {
        return get_permalink($page_id);
    }
    
    // Fallback to home URL with /verify-certificate if no page is selected
    return home_url('/verify2');
}