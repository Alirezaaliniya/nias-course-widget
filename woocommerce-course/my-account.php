<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add new endpoint
add_action('init', 'nias_course_add_endpoint');
function nias_course_add_endpoint() {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return;
    }
    $endpoint = get_option('nias_course_endpoint', 'my-courses');
    add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
}

// Add new menu item to my account menu
add_filter('woocommerce_account_menu_items', 'nias_course_add_menu_item');
function nias_course_add_menu_item($items) {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return $items;
    }
    
    $endpoint = get_option('nias_course_endpoint', 'my-courses');
    $new_items = array();
    foreach ($items as $key => $item) {
        $new_items[$key] = $item;
        if ($key === 'dashboard') {
            $new_items[$endpoint] = 'دوره های من';
        }
    }
    return $new_items;
}

// Add content to the new endpoint
add_action('woocommerce_account_my-courses_endpoint', 'nias_course_endpoint_content');
function nias_course_endpoint_content() {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return;
    }
    
    echo '<h2>دوره های من</h2>';
    // You can add your course listing logic here
    include_once (NIAS_WOOCOMMERCE . '/show-course-account.php');
}

// Add settings field to WooCommerce Advanced tab
add_filter('woocommerce_get_settings_advanced', 'nias_course_add_settings_field', 10, 2);
function nias_course_add_settings_field($settings, $current_section) {
    $settings[] = array(
        'title' => __('نقاط پایانی دوره', 'woocommerce'),
        'type'  => 'title',
        'id'    => 'nias_course_settings'
    );
    
    $settings[] = array(
        'title'    => __('نقاط پایانی دوره', 'woocommerce'),
        'desc'     => __('نقطه پایانی صفحه دوره‌های من', 'woocommerce'),
        'id'       => 'nias_course_endpoint',
        'type'     => 'text',
        'default'  => 'my-courses',
        'desc_tip' => __('این مقدار در URL حساب کاربری استفاده خواهد شد', 'woocommerce'),
    );

    $settings[] = array(
        'type' => 'sectionend',
        'id'   => 'nias_course_settings'
    );
    
    return $settings;
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, function() {
    if (function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_course_account_display') === 'on') {
        nias_course_add_endpoint();
    }
    flush_rewrite_rules();
});

// Flush rewrite rules on plugin deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

