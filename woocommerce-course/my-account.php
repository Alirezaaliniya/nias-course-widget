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
    add_rewrite_endpoint('my-courses', EP_ROOT | EP_PAGES);
}

// Add new menu item to my account menu
add_filter('woocommerce_account_menu_items', 'nias_course_add_menu_item');
function nias_course_add_menu_item($items) {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return $items;
    }
    
    $new_items = array();
    foreach ($items as $key => $item) {
        $new_items[$key] = $item;
        if ($key === 'dashboard') {
            $new_items['my-courses'] = 'دوره های من';
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
}

// Add endpoint to WooCommerce Settings Advanced Tab
add_filter('woocommerce_get_settings_advanced', 'nias_course_add_settings_endpoint', 20, 1);
function nias_course_add_settings_endpoint($settings) {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return $settings;
    }

    $custom_settings = array();

    foreach ($settings as $setting) {
        $custom_settings[] = $setting;

        // Add our setting after the Account Endpoints section title
        if (isset($setting['id']) && $setting['id'] === 'account_endpoint_options') {
            $custom_settings[] = array(
                'title'    => __('دوره های من', 'nias-course-widget'),
                'desc'     => __('پیکربندی نقطه پایانی برای صفحه دوره های من', 'nias-course-widget'),
                'id'       => 'woocommerce_myaccount_my-courses_endpoint',
                'type'     => 'text',
                'default'  => 'my-courses',
                'desc_tip' => true,
            );
        }
    }

    return $custom_settings;
}

// Register settings
add_filter('woocommerce_settings_tabs_array', 'nias_course_add_settings_tab', 50);
function nias_course_add_settings_tab($settings_tabs) {
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        return $settings_tabs;
    }
    
    $settings_tabs['my_courses'] = __('دوره های من', 'nias-course-widget');
    return $settings_tabs;
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

