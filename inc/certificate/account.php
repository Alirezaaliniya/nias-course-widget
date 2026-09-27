<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "مدارک من" tab in the WooCommerce My Account page.
 *
 * Enabled from the certificate settings (certificate_account_endpoint). The
 * endpoint slug and menu title are configurable; the tab renders the same
 * list as [nias_certificate] for the logged-in user. Rewrite rules are flushed
 * automatically whenever the endpoint is switched on/off or renamed.
 *
 * @package nias-course-widget
 */

/** Whether the account tab is enabled. */
function nias_cert_account_enabled()
{
    return nias_cert_feature_on() && nias_cert_setting('certificate_account_endpoint', 'off') === 'on';
}

/** Endpoint slug (URL part), e.g. my-certificates. */
function nias_cert_account_slug()
{
    $slug = sanitize_title((string) nias_cert_setting('certificate_account_slug', 'my-certificates'));
    return $slug !== '' ? $slug : 'my-certificates';
}

/** Menu / page title. */
function nias_cert_account_title()
{
    $title = trim((string) nias_cert_setting('certificate_account_title', ''));
    return $title !== '' ? $title : __('مدارک من', 'nias-course-widget');
}

add_action('init', 'nias_cert_account_register_endpoint', 20);
function nias_cert_account_register_endpoint()
{
    $enabled = nias_cert_account_enabled();
    $slug    = nias_cert_account_slug();
    if ($enabled) {
        add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        add_action('woocommerce_account_' . $slug . '_endpoint', 'nias_cert_account_endpoint_content');
        add_filter('woocommerce_endpoint_' . $slug . '_title', 'nias_cert_account_title');
    }

    // Flush once whenever the endpoint state changes, so the URL works
    // without re-saving permalinks.
    $signature = $enabled ? $slug : 'off';
    if (get_option('nias_cert_account_rewrite') !== $signature) {
        flush_rewrite_rules(false);
        update_option('nias_cert_account_rewrite', $signature);
    }
}

// Let WooCommerce treat it as an account endpoint (active menu state, title).
add_filter('woocommerce_get_query_vars', 'nias_cert_account_query_vars');
function nias_cert_account_query_vars($vars)
{
    if (nias_cert_account_enabled()) {
        $slug        = nias_cert_account_slug();
        $vars[$slug] = $slug;
    }
    return $vars;
}

add_filter('woocommerce_account_menu_items', 'nias_cert_account_menu_item', 20);
function nias_cert_account_menu_item($items)
{
    if (!nias_cert_account_enabled()) {
        return $items;
    }
    $slug  = nias_cert_account_slug();
    $title = nias_cert_account_title();

    // Place it after "my courses" when present, otherwise before logout.
    $courses = get_option('nias_course_endpoint', 'my-courses');
    $anchor  = isset($items[$courses]) ? $courses : null;
    $out     = array();
    foreach ($items as $key => $label) {
        if ($anchor === null && $key === 'customer-logout') {
            $out[$slug] = $title;
        }
        $out[$key] = $label;
        if ($anchor !== null && $key === $anchor) {
            $out[$slug] = $title;
        }
    }
    if (!isset($out[$slug])) {
        $out[$slug] = $title;
    }
    return $out;
}

function nias_cert_account_endpoint_content()
{
    if (!is_user_logged_in()) {
        return;
    }
    echo nias_certificate_shortcode(array()); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping
}

/** Public URL of the tab for the current user (empty when disabled). */
function nias_cert_account_url()
{
    if (!nias_cert_account_enabled() || !function_exists('wc_get_account_endpoint_url')) {
        return '';
    }
    return wc_get_account_endpoint_url(nias_cert_account_slug());
}
