<?php

// Add new endpoint for My Courses
add_action('init', 'add_my_courses_endpoint');
function add_my_courses_endpoint() {
    add_rewrite_endpoint('my-courses', EP_ROOT | EP_PAGES);
}

// Add new menu item to my account menu
add_filter('woocommerce_account_menu_items', 'add_my_courses_menu_item');
function add_my_courses_menu_item($menu_items) {
    $menu_items['my-courses'] = 'My Courses';
    return $menu_items;
}

// Register new query vars
add_filter('query_vars', 'my_courses_query_vars');
function my_courses_query_vars($vars) {
    $vars[] = 'my-courses';
    return $vars;
}