<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Purchased-course lookup, cached per request.
 *
 * Access is granted only by 'wc-completed' orders (see inc/functions.php).
 * Several parts of a page (widget, modern view, account tab, private-lesson
 * checks) ask the same question; each used to load every order of the user
 * again. They now share one lookup per user per request.
 *
 * @package nias-course-widget
 */

/**
 * Product ids (parent ids, as WC_Order_Item_Product::get_product_id()) found
 * in the user's completed orders.
 *
 * @param int $user_id
 * @return int[]
 */
function nias_course_completed_product_ids($user_id)
{
    static $cache = array();
    $user_id = intval($user_id);
    if (!$user_id || !function_exists('wc_get_orders')) {
        return array();
    }
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    $ids    = array();
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'limit'       => -1,
        'status'      => 'wc-completed',
    ));
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $pid = (int) $item->get_product_id();
            if ($pid && !in_array($pid, $ids, true)) {
                $ids[] = $pid;
            }
        }
    }
    $cache[$user_id] = $ids;
    return $ids;
}

/** Has the user a completed order containing this product? */
function nias_course_user_completed($user_id, $product_id)
{
    return in_array((int) $product_id, nias_course_completed_product_ids($user_id), true);
}
