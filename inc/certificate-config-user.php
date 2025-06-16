<?php
// Make sure we are in WordPress context
if (!defined('ABSPATH')) {
    exit;
}

// Include Carbon Fields
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Carbon_Fields;

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
    // Define characters to use in the random code
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code_length = 10; // Length of the code
    $code = '';
    
    // Generate random code
    for ($i = 0; $i < $code_length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    // Add user ID and product ID to make it unique
    $unique_prefix = substr(md5($user_id . '-' . $product_id), 0, 4);
    
    // Add a prefix to make it look more like a certificate
    return 'CERT-' . $unique_prefix . '-' . $code;
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
    ?>
    <h3><?php _e('اطلاعات مدرک', 'nias-course-widget'); ?></h3>
    
    <table class="form-table">
        <tr>
            <th><label for="usercertificate_code"><?php _e('کد گواهی', 'nias-course-widget'); ?></label></th>
            <td>
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
    // Check if current user has permission to edit users or is editing their own profile
    if (!current_user_can('edit_users') && get_current_user_id() != $user_id) {
        return false;
    }
    
    // Update the certificate code if it's set in the form
    if (isset($_POST['usercertificate_code'])) {
        $code = sanitize_text_field($_POST['usercertificate_code']);
        
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
        // All products are allowed
        return true;
    } elseif ($display_type === 'selected') {
        // Get specifically selected products
        $allowed_product_ids = carbon_get_theme_option('certificate_selected_products') ?: array();
    } elseif ($display_type === 'category') {
        // Get products from selected categories
        $selected_categories = carbon_get_theme_option('certificate_selected_categories') ?: array();
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

    // Get customer orders
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => 'completed',
        'limit' => -1,
    ));
    
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
        $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);
        
        // If empty but user has purchased certificate product, generate code now
        if (empty($certificate_code) && has_user_purchased_certificate($user_id)) {
            $certificate_code = generate_certificate_code($user_id, 0);
            update_user_meta($user_id, 'usercertificate_code', $certificate_code);
            error_log("Auto-generated certificate code for user ID: $user_id during user list display");
        }
        
        return $certificate_code ? $certificate_code : '—';
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
    $verification_link = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user_id
        ), 
        get_certificate_display_page_url()
    );
    
    // Create a button HTML
    $button_html = sprintf(
        '<a href="%s" class="certificate-verification-button button" target="_blank">%s</a>',
        esc_url($verification_link),
        __('تایید مدرک', 'nias-course-widget')
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
        get_certificate_display_page_url()
    );
    
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
    $verification_link = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user_id
        ), 
        get_certificate_display_page_url()
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
        
        // دریافت نام و نام خانوادگی از user_meta
        $first_name = get_user_meta($user->ID, 'first_name', true);
        $last_name = get_user_meta($user->ID, 'last_name', true);
        
        // پاک کردن فاصله‌های اضافی
        $first_name = trim($first_name);
        $last_name = trim($last_name);
        
        // ترکیب نام و نام خانوادگی
        $name = '';
        if (!empty($first_name) && !empty($last_name)) {
            $name = $first_name . ' ' . $last_name;
        } elseif (!empty($first_name)) {
            $name = $first_name;
        } elseif (!empty($last_name)) {
            $name = $last_name;
        } else {
            // اگر نام و نام خانوادگی خالی بود، از display_name استفاده کن
            $name = $user_info->display_name;
            
            // اگر display_name هم خالی بود، از username استفاده کن
            if (empty($name)) {
                $name = $user_info->user_login;
            }
        }
        
        // اگر هنوز نام خالی است، از مقدار پیش‌فرض استفاده کن
        if (empty($name)) {
            $name = 'مهمان(نام شما در سایت ثبت نشده این موضوع را از پشتیبان سایت پیگیری کنید)';
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
        
        // Generate the verification link using the selected page
        $verification_link = add_query_arg(
            array(
                'code' => $certificate_code,
                'user_id' => $user->ID
            ), 
            get_certificate_display_page_url()
        );
        
// Attempt to generate and display the certificate
try {
    // Generate verification URL
    $verification_url = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user->ID
        ), 
        get_certificate_display_page_url()
    );
    
    // Create QR code using Endroid library
    $qr = new QrCode($verification_url);
    $qr->setSize(300);
    $qr->setMargin(10);
    $writer = new PngWriter();
    $result = $writer->write($qr);
    
    ?>
    <div class="certificate-verification-container">
        <h2>تاییدیه مدرک</h2>
        <div class="certificate-details">
            <p><strong>نام:</strong> <?php echo esc_html($name); ?></p>
            <p><strong>کد مدرک:</strong> <?php echo esc_html($certificate_code); ?></p>
            <p><strong>شناسه کاربری:</strong> <?php echo esc_html($user->ID); ?></p>
            <p><strong>دوره:</strong> <?php echo esc_html($course); ?></p>
            <p><strong>تاریخ صدور:</strong> <?php echo esc_html($date); ?></p>
            
            <p><strong>وضعیت تأیید:</strong> <span class="verification-status verified">تأیید شده</span></p>
            
            <div class="certificate-actions">
                <button onclick="generateCertificate()">مشاهده گواهی</button>
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
    // If certificate generation fails, show basic verification info
    ?>
    <div class="certificate-verification-container">
        <h2>تاییدیه مدرک</h2>
        <div class="certificate-details">
            <p><strong>نام:</strong> <?php echo esc_html($name); ?></p>
            <p><strong>کد مدرک:</strong> <?php echo esc_html($certificate_code); ?></p>
            <p><strong>شناسه کاربری:</strong> <?php echo esc_html($user->ID); ?></p>
            <p><strong>دوره:</strong> <?php echo esc_html($course); ?></p>
            <p><strong>تاریخ صدور:</strong> <?php echo esc_html($date); ?></p>
            
            <p><strong>وضعیت تأیید:</strong> <span class="verification-status verified">تأیید شده</span></p>
            
            <p class="info-message">گواهی با موفقیت تأیید شد. تولید گواهی به طور موقت در دسترس نیست.</p>
        </div>
    </div>
    
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
        .info-message {
            color: #666;
            font-style: italic;
            margin-top: 15px;
        }
    </style>
    <?php
    
    // Log the error for debugging
    error_log("Certificate generation error: " . $e->getMessage());
}
    } else {
        // If no user found, show an error message
        echo '<p>Error: No user found with the provided certificate code.</p>';
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
    // Check if Carbon Fields function exists
    if (!function_exists('carbon_get_theme_option')) {
        // Attempt to initialize Carbon Fields
        if (class_exists('Carbon_Fields\\Carbon_Fields')) {
            \Carbon_Fields\Carbon_Fields::boot();
        } else {
            return home_url('/verify1'); // Fallback if Carbon Fields is not available
        }
    }
    
    $page_id = carbon_get_theme_option('certificate_display_page');
    
    if (!empty($page_id) && get_post_status($page_id) === 'publish') {
        return get_permalink($page_id);
    }
    
    // Fallback to home URL with /verify-certificate if no page is selected
    return home_url('/verify2');
}