<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server side of NiasPicker (assets/js/nias-picker.js): a search endpoint that
 * returns at most NIAS_PICK_LIMIT matches per request, so selection UIs never
 * load every quiz / product / user of the site.
 *
 *   POST admin-ajax.php?action=nias_pick  source=quiz|product|user  q=term
 *     quiz:    &course=ID   (optional) — quizzes of that course first
 *     user:    &exclude=1,2 &role__not_in=role
 *
 * @package nias-course-widget
 */

if (!defined('NIAS_PICK_LIMIT')) {
    define('NIAS_PICK_LIMIT', 10);
}

/** Print the picker script + its endpoint config (once). */
function nias_picker_enqueue()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $src = plugins_url('assets/js/nias-picker.js', NIAS_COURSE . 'nias-course-widget.php');
    $cfg = array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('nias_pick'));
    if (did_action('wp_print_scripts') || did_action('admin_print_scripts') || doing_action('admin_footer') || doing_action('wp_footer')) {
        echo '<script>window.NIAS_PICK=' . wp_json_encode($cfg) . ';</script>';
        echo '<script src="' . esc_url(add_query_arg('ver', NIAS_COURSE_VERSION, $src)) . '"></script>';
        return;
    }
    wp_enqueue_script('nias-picker', $src, array(), NIAS_COURSE_VERSION, false);
    wp_add_inline_script('nias-picker', 'window.NIAS_PICK=' . wp_json_encode($cfg) . ';', 'before');
}

/** Who may search what. */
function nias_picker_can($source)
{
    switch ($source) {
        case 'quiz':
        case 'product':
            return current_user_can('manage_options') || current_user_can('edit_products') || current_user_can('edit_posts')
                || (function_exists('nias_inst_panel_user') && nias_inst_panel_user()); // instructor panel (scoped below)
        case 'user':
            return current_user_can('manage_options') || current_user_can('list_users');
    }
    return false;
}

add_action('wp_ajax_nias_pick', 'nias_picker_ajax');
function nias_picker_ajax()
{
    $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
    if (!check_ajax_referer('nias_pick', 'nonce', false) || !nias_picker_can($source)) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'nias-course-widget')), 403);
    }
    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 80) : substr($q, 0, 80);
    $fn = 'nias_picker_search_' . $source;
    wp_send_json_success(function_exists($fn) ? $fn($q) : array('items' => array(), 'more' => false));
}

/** Run a post search: title match (+ exact id), newest first, limit + 1 to know "more". */
function nias_picker_post_ids($post_type, $q, $statuses)
{
    $args = array(
        'post_type'              => $post_type,
        'post_status'            => $statuses,
        'posts_per_page'         => NIAS_PICK_LIMIT + 1,
        'fields'                 => 'ids',
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'suppress_filters'       => true,
    );
    if ($q !== '') {
        $args['s'] = $q;
        $args['search_columns'] = array('post_title');
        $args['orderby'] = 'relevance';
    }
    $ids = get_posts($args);
    // Instructor panel: only their courses / their quizzes.
    if (!current_user_can('edit_posts') && function_exists('nias_inst_panel_user') && nias_inst_panel_user()) {
        $uid  = get_current_user_id();
        $mine = in_array(NIAS_QUIZ_CPT, (array) $post_type, true) ? nias_inst_quiz_ids($uid) : nias_inst_course_ids($uid);
        $args['post__in'] = $mine ? $mine : array(0);
        $ids = get_posts($args);
        $num = (int) ltrim($q, '#');
        if ($num > 0 && in_array($num, $mine, true) && !in_array($num, $ids, true)) {
            array_unshift($ids, $num);
        }
        return array_map('intval', $ids);
    }
    // "#123" or "123" also finds by id.
    $num = (int) ltrim($q, '#');
    if ($num > 0 && ctype_digit(ltrim($q, '#')) && in_array(get_post_type($num), (array) $post_type, true) && !in_array($num, $ids, true)) {
        array_unshift($ids, $num);
    }
    return array_map('intval', $ids);
}

function nias_picker_search_quiz($q)
{
    $ids  = nias_picker_post_ids(NIAS_QUIZ_CPT, $q, array('publish', 'draft'));
    $more = count($ids) > NIAS_PICK_LIMIT;
    $ids  = array_slice($ids, 0, NIAS_PICK_LIMIT);
    _prime_post_caches($ids, false, true);
    $items = array();
    foreach ($ids as $id) {
        $items[] = nias_picker_quiz_item($id);
    }
    return array('items' => $items, 'more' => $more);
}

/** Label data for one quiz (cheap: uses the summary meta, not the full model). */
function nias_picker_quiz_item($id)
{
    $id = (int) $id;
    $count = get_post_meta($id, '_nias_quiz_qcount', true);
    $pass  = get_post_meta($id, '_nias_quiz_pass', true);
    if ($count === '' || $pass === '') {
        $quiz  = function_exists('nias_quiz_get') ? nias_quiz_get($id) : null;
        $count = $quiz ? count($quiz['questions']) : 0;
        $pass  = $quiz ? (int) $quiz['passScore'] : 0;
    }
    $title = get_the_title($id);
    return array(
        'id'    => $id,
        'label' => ($title !== '' ? $title : __('آزمون بدون عنوان', 'nias-course-widget')) . ' (#' . $id . ')',
        'sub'   => sprintf(__('%1$s سوال · قبولی از %2$s٪', 'nias-course-widget'), nias_fa_digits((int) $count), nias_fa_digits((int) $pass)),
        'pass'  => (int) $pass,
    );
}

function nias_picker_search_product($q)
{
    // "product" = any course: WooCommerce products and/or «دوره» posts.
    $ids  = nias_picker_post_ids(nias_course_post_types(), $q, array('publish', 'draft', 'private', 'pending'));
    $more = count($ids) > NIAS_PICK_LIMIT;
    $ids  = array_slice($ids, 0, NIAS_PICK_LIMIT);
    _prime_post_caches($ids, false, false);
    $items = array();
    foreach ($ids as $id) {
        $items[] = nias_picker_product_item($id);
    }
    return array('items' => $items, 'more' => $more);
}

function nias_picker_product_item($id)
{
    $status = get_post_status($id);
    $labels = array('publish' => '', 'draft' => __('پیش‌نویس', 'nias-course-widget'), 'private' => __('خصوصی', 'nias-course-widget'), 'pending' => __('در انتظار بررسی', 'nias-course-widget'));
    $sub = isset($labels[$status]) ? $labels[$status] : $status;
    if (count(nias_course_post_types()) > 1) {
        $sub = trim(nias_course_type_label($id) . ($sub ? ' · ' . $sub : ''));
    }
    return array(
        'id'    => (int) $id,
        'label' => get_the_title($id) . ' (#' . (int) $id . ')',
        'sub'   => $sub,
    );
}

function nias_picker_search_user($q)
{
    $args = array(
        'number'      => NIAS_PICK_LIMIT + 1,
        'orderby'     => 'display_name',
        'order'       => 'ASC',
        'fields'      => array('ID', 'display_name', 'user_email', 'user_login'),
        'count_total' => false,
    );
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = array('user_login', 'user_email', 'display_name', 'user_nicename');
    }
    $exclude = isset($_POST['exclude']) ? array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['exclude'])))) : array();
    if ($exclude) {
        $args['exclude'] = $exclude;
    }
    $not_role = isset($_POST['role__not_in']) ? sanitize_key(wp_unslash($_POST['role__not_in'])) : '';
    if ($not_role) {
        $args['role__not_in'] = array($not_role);
    }
    $users = get_users($args);
    $more  = count($users) > NIAS_PICK_LIMIT;
    $items = array();
    foreach (array_slice($users, 0, NIAS_PICK_LIMIT) as $u) {
        $items[] = array('id' => (int) $u->ID, 'label' => $u->display_name, 'sub' => $u->user_email);
    }
    return array('items' => $items, 'more' => $more);
}
