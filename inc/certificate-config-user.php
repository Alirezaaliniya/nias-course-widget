<?php
// Make sure we are in WordPress context
if (!defined('ABSPATH')) {
    exit;
}
// Include Carbon Fields
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Carbon_Fields;

/**
 * Generate a random certificate code
 * 
 * @return string Random alphanumeric code
 */
function generate_certificate_code() {
    // Define characters to use in the random code
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code_length = 10; // Length of the code
    $code = '';
    
    // Generate random code
    for ($i = 0; $i < $code_length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    // Add a prefix to make it look more like a certificate
    return 'CERT-' . $code;
}


function add_certificate_meta_to_existing_product_purchasers() {
    global $wpdb;
    
    // Updated query to work with newer WooCommerce versions
    $order_query = $wpdb->prepare(
        "SELECT DISTINCT p.ID, pm.meta_value AS user_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        WHERE p.post_type = 'shop_order'
        AND p.post_status = 'wc-completed'
        AND oim.meta_key = '_product_id'
        AND oim.meta_value = %d
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
        $order_id = $result->ID;
        
        // If it's a registered user (not a guest checkout)
        if ($user_id > 0) {
            $existing_code = get_user_meta($user_id, 'usercertificate_code', true);
            
            // Check if user has a certificate code that is empty or doesn't exist
            if (empty($existing_code)) {
                // Generate and add the random certificate code
                $new_code = generate_certificate_code();
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
    ?>
    <h3><?php _e('Certificate Information', 'nias-course-widget'); ?></h3>
    
    <table class="form-table">
        <tr>
            <th><label for="usercertificate_code"><?php _e('Certificate Code', 'nias-course-widget'); ?></label></th>
            <td>
                <input type="text" name="usercertificate_code" id="usercertificate_code" 
                    value="<?php echo esc_attr($certificate_code); ?>" class="regular-text" />
                <?php if (empty($certificate_code)) : ?>
                    <p class="description"><?php _e('هنوز کد گواهی اختصاص داده نشده است.', 'nias-course-widget'); ?></p>
                    <button type="button" class="button" id="generate_certificate" 
                        onclick="document.getElementById('usercertificate_code').value='<?php echo esc_attr(generate_certificate_code()); ?>';">
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
    // Check if current user has permission to edit users or is editing their own profile
    if (!current_user_can('edit_users') && get_current_user_id() != $user_id) {
        return false;
    }
    
    // Update the certificate code if it's set in the form
    if (isset($_POST['usercertificate_code'])) {
        $code = sanitize_text_field($_POST['usercertificate_code']);
        
        // If submitted code is empty but user should have a certificate (purchased product)
        if (empty($code) && has_user_purchased_certificate($user_id)) {
            $code = generate_certificate_code();
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
    $display_type = carbon_get_theme_option('certificate_display_type');
    
    // Get allowed product IDs based on display type setting
    $allowed_product_ids = array();
    
    if ($display_type === 'all') {
        // All products are allowed
        return true;
    } elseif ($display_type === 'selected') {
        // Get specifically selected products
        $allowed_product_ids = carbon_get_theme_option('certificate_selected_products');
    } elseif ($display_type === 'category') {
        // Get products from selected categories
        $selected_categories = carbon_get_theme_option('certificate_selected_categories');
        if (!empty($selected_categories)) {
            $products = get_posts(array(
                'post_type' => 'product',
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

    $customer = new WC_Customer($user_id);
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => 'completed',
        'limit' => -1,
    ));
    
    foreach ($orders as $order) {
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            // For "all" display type or if the product is in allowed products list
            if ($display_type === 'all' || in_array($product_id, $allowed_product_ids)) {
                return true;
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
        $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
        
        // If empty but user has purchased certificate product, generate code now
        if (empty($certificate_code) && has_user_purchased_certificate($user_id)) {
            $certificate_code = generate_certificate_code();
            update_user_meta($user_id, 'usercertificate_code', $certificate_code);
            error_log("Auto-generated certificate code for user ID: $user_id during user list display");
        }
        
        return $certificate_code ? $certificate_code : '—';
    }
    return $value;
}
add_filter('manage_users_custom_column', 'show_certificate_column_content', 10, 3);







/**
 * Generate a button with certificate verification link for users with a certificate code
 * 
 * @param int $user_id The ID of the user
 * @return string HTML button or empty string if no certificate code exists
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
    
    // Get the configured certificate page ID
    $certificate_page_id = carbon_get_theme_option('certificate_page');
    $verification_url = $certificate_page_id ? get_permalink($certificate_page_id) : home_url('/verify-certificate');
    
    // Generate the verification link
    $verification_link = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user_id
        ), 
        $verification_url
    );
    
    // Create a button HTML
    $button_html = sprintf(
        '<a href="%s" class="certificate-verification-button button" target="_blank">%s</a>',
        esc_url($verification_link),
        __('Verify Certificate', 'nias-course-widget')
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
    $verification_link = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user_id
        ), 
        home_url('/verify-certificate')
    );
    
    // Create a button HTML
    $button_html = sprintf(
        '<a href="%s" class="certificate-verification-button button" target="_blank" style="padding: 20px;">%s</a>',
        esc_url($verification_link),
        __('دریافت مدرک  دارای qrcode', 'nias-course-widget')
    );
    
    return $button_html;
}
add_shortcode('user_certificate', 'userbutton_certificate_shortcode');

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
    // Assumes the page with the shortcode is at /verify-certificate
$verification_link = add_query_arg(
    array(
        'code' => $certificate_code,
        'user_id' => $user_id // مقدار مستقیماً از URL دریافت شده است
    ), 
    home_url('/verify-certificate')
);
    
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
add_shortcode('user_certificate_iframe', 'user_certificate_iframe_shortcode');

/**
 * Shortcode to display certificate verification information
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML content with certificate information
 */
function user_certificate_shortcode($atts) {
    // Get the certificate code and user ID from URL parameters
    $certificate_code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    // If no code is provided, show an error message
    if (empty($certificate_code)) {
        return '<p>Error: No certificate code provided.</p>';
    }
    
    // If user ID is provided, directly fetch the user
    if ($user_id > 0) {
        $user = get_userdata($user_id);
        
        // Check if the user exists and the certificate code matches
        if ($user && get_user_meta($user_id, 'usercertificate_code', true) === $certificate_code) {
            $users = array($user);
        } else {
            $users = array();
        }
    } else {
        // Query users with this certificate code
        $user_query = new WP_User_Query(array(
            'meta_key'   => 'usercertificate_code',
            'meta_value' => $certificate_code,
            'number'     => 1
        ));
        
        $users = $user_query->get_results();
    }
    
    // Start output buffering
    ob_start();
    
    if (!empty($users)) {
        $user = $users[0];
        $user_info = get_userdata($user->ID);
        
        // Prepare certificate information
        $name = $user_info->display_name;
        if (empty($name)) {
            $name = $user_info->user_login;
        }
        
        // Get course from usercertificate_meta, or use a default
        $course = get_user_meta($user->ID, 'usercertificate_course', true);
        if (empty($course)) {
            $course = 'WordPress + Elementor + Coding';
        }
        
        // Get date from usercertificate_date, or use current date
        $date = get_user_meta($user->ID, 'usercertificate_date', true);
        if (empty($date)) {
            $date = date('F j, Y');
        }
        
        // Generate the verification link
        $verification_link = add_query_arg(
            array(
                'code' => $certificate_code,
                'user_id' => $user->ID
            ), 
            home_url('/verify-certificate')
        );
        
        // Attempt to generate and display the certificate
        try {
            // Create certificate with verification link
            $certificate = new CertificateWithQRCode();
            
            ?>
            <div class="certificate-verification-container">
                <h2>Certificate Verification</h2>
                <div class="certificate-details">
                    <p><strong>Name:</strong> <?php echo esc_html($name); ?></p>
                    <p><strong>Certificate Code:</strong> <?php echo esc_html($certificate_code); ?></p>
                    <p><strong>User ID:</strong> <?php echo esc_html($user->ID); ?></p>
                    <p><strong>Course:</strong> <?php echo esc_html($course); ?></p>
                    <p><strong>Issue Date:</strong> <?php echo esc_html($date); ?></p>
                    
                    <p><strong>Verification Status:</strong> <span class="verification-status verified">Verified</span></p>
                    
                    <div class="certificate-actions">
                        <button onclick="generateCertificate()">View Certificate</button>
                    </div>
                </div>
            </div>
            
            <script>
            function generateCertificate() {
                // Redirect to download the certificate with necessary parameters
                window.location.href = '<?php echo add_query_arg(
                    array(
                        'download_certificate' => '1', 
                        'code' => $certificate_code, 
                        'user_id' => $user->ID
                    ), 
                    home_url()
                ); ?>';
            }
            </script>
            
            <style>
                .certificate-verification-container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    background-color: #f9f9f9;
                }
                .certificate-details {
                    margin-top: 15px;
                }
                .verification-status.verified {
                    color: green;
                    font-weight: bold;
                }
                .certificate-actions {
                    margin-top: 20px;
                    text-align: center;
                }
                .certificate-actions button {
                    padding: 10px 20px;
                    background-color: #4CAF50;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                }
            </style>
           <?php
        } catch (Exception $e) {
            // If certificate generation fails, show an error
            ?>
            <div class="certificate-verification-container">
                <h2>Certificate Verification</h2>
                <p class="error-message">Error generating certificate: <?php echo esc_html($e->getMessage()); ?></p>
            </div>
            <?php
        }
    } else {
        ?>
        <div class="certificate-verification-container">
            <h2>Certificate Verification</h2>
            <p class="error-message">No certificate found with the provided code: <?php echo esc_html($certificate_code); ?></p>
            <style>
                .certificate-verification-container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    background-color: #fff5f5;
                }
                .error-message {
                    color: red;
                    text-align: center;
                }
            </style>
         </div>
        <?php
    }
    
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
        <h3>Certificate Verification</h3>
        <table class="form-table">
            <tr>
                <th>Verification Link</th>
                <td>
                    <?php echo $verification_button; ?>
                    <p class="description">Share this link to verify your certificate online.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}
add_action('show_user_profile', 'add_certificate_verification_button_to_profile');
add_action('edit_user_profile', 'add_certificate_verification_button_to_profile');