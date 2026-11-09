<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Course post type + "free mode" (no WooCommerce).
 *
 *   - Without WooCommerce the plugin registers its own «دوره» post type
 *     (nias_course) and every course feature works on it: curriculum editor,
 *     modern course view, instructors, quizzes, certificates, watch tracking.
 *   - With WooCommerce, courses stay WooCommerce products; the «دوره» type can
 *     still be switched on in the main settings to publish free courses next
 *     to the paid ones.
 *   - A «دوره» is free: no purchase. Public lessons are open to everyone,
 *     lessons marked «خصوصی» need a logged-in user (filter nias_free_course_access).
 *
 * Everything that used to check post_type === 'product' asks
 * nias_is_course_post() / nias_course_post_types() instead.
 *
 * @package nias-course-widget
 */

if (!defined('NIAS_COURSE_CPT')) {
    define('NIAS_COURSE_CPT', 'nias_course');
}
if (!defined('NIAS_COURSE_TAX')) {
    define('NIAS_COURSE_TAX', 'nias_course_cat');
}

/** Is WooCommerce active? (evaluated lazily: WooCommerce loads after this plugin.) */
function nias_wc_active()
{
    return (bool) apply_filters('nias_wc_active', class_exists('WooCommerce') && function_exists('wc_get_orders'));
}

/** Is the «دوره» post type on? Always without WooCommerce; optional with it. */
function nias_course_cpt_enabled()
{
    if (!nias_wc_active()) {
        return true;
    }
    return function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_course_cpt') === 'on';
}

/**
 * Post types that are courses.
 *
 * @return string[]
 */
function nias_course_post_types()
{
    $types = array();
    if (nias_wc_active()) {
        $types[] = 'product';
    }
    if (nias_course_cpt_enabled()) {
        $types[] = NIAS_COURSE_CPT;
    }
    return (array) apply_filters('nias_course_post_types', $types);
}

function nias_is_course_type($type)
{
    return in_array((string) $type, nias_course_post_types(), true);
}

/** Is this post a course (product or «دوره»)? */
function nias_is_course_post($post_id)
{
    $post_id = (int) $post_id;
    return $post_id > 0 && nias_is_course_type(get_post_type($post_id));
}

/** Free course = a «دوره» (no WooCommerce sale). */
function nias_course_is_free($post_id)
{
    return (bool) apply_filters('nias_course_is_free', get_post_type((int) $post_id) === NIAS_COURSE_CPT, (int) $post_id);
}

/** Label of a course post type for messages ("محصول" / "دوره"). */
function nias_course_type_label($post_id = 0)
{
    return ($post_id && get_post_type($post_id) === NIAS_COURSE_CPT) || !nias_wc_active() ? __('Course', 'nias-course-widget') : __('Product', 'nias-course-widget');
}

/**
 * Can this user open the private parts of a course?
 * Paid course: a completed order. Free course: being logged in.
 */
function nias_user_has_course_access($user_id, $post_id)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (nias_course_is_free($post_id)) {
        return (bool) apply_filters('nias_free_course_access', $user_id > 0, $user_id, $post_id);
    }
    if (!$user_id || !nias_wc_active() || !function_exists('nias_course_user_completed')) {
        return false;
    }
    // Bought, and (time-limited access module) not expired.
    return nias_course_user_completed($user_id, $post_id) && (bool) apply_filters('nias_course_access_active', true, $user_id, $post_id);
}

/** Where a visitor gets access: the product page (paid) or the login page (free). */
function nias_course_access_url($post_id)
{
    return nias_course_is_free($post_id) ? wp_login_url(get_permalink($post_id)) : get_permalink($post_id);
}

/* -------------------------------------------------------------------------
 * Registration
 * ---------------------------------------------------------------------- */

add_action('init', 'nias_course_register_cpt', 5);
function nias_course_register_cpt()
{
    if (!nias_course_cpt_enabled()) {
        return;
    }
    register_post_type(NIAS_COURSE_CPT, array(
        'labels' => array(
            'name'               => __('Courses', 'nias-course-widget'),
            'singular_name'      => __('Course', 'nias-course-widget'),
            'menu_name'          => __('Courses', 'nias-course-widget'),
            'add_new'            => __('Add course', 'nias-course-widget'),
            'add_new_item'       => __('Add new course', 'nias-course-widget'),
            'edit_item'          => __('Edit course', 'nias-course-widget'),
            'new_item'           => __('New course', 'nias-course-widget'),
            'view_item'          => __('View course', 'nias-course-widget'),
            'search_items'       => __('Search courses', 'nias-course-widget'),
            'not_found'          => __('No course found.', 'nias-course-widget'),
            'not_found_in_trash' => __('No course in the trash.', 'nias-course-widget'),
            'all_items'          => __('All courses', 'nias-course-widget'),
            'archives'           => __('Course archives', 'nias-course-widget'),
        ),
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => array('slug' => apply_filters('nias_course_slug', 'courses'), 'with_front' => false),
        'menu_icon'    => 'dashicons-welcome-learn-more',
        'menu_position'=> 26,
        'supports'     => array('title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'revisions', 'elementor'),
        'show_in_rest' => true,
        'taxonomies'   => array(NIAS_COURSE_TAX),
    ));
    register_taxonomy(NIAS_COURSE_TAX, NIAS_COURSE_CPT, array(
        'labels' => array(
            'name'          => __('Course categories', 'nias-course-widget'),
            'singular_name' => __('Course category', 'nias-course-widget'),
            'add_new_item'  => __('Add category', 'nias-course-widget'),
        ),
        'public'            => true,
        'hierarchical'      => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => array('slug' => apply_filters('nias_course_cat_slug', 'course-category'), 'with_front' => false),
    ));

    // Flush permalinks once when the type appears/disappears, so /courses/… works.
    if (get_option('nias_course_cpt_rewrite') !== 'on') {
        add_action('wp_loaded', function () {
            flush_rewrite_rules(false);
            update_option('nias_course_cpt_rewrite', 'on');
        });
    }
}
add_action('init', function () {
    if (!nias_course_cpt_enabled() && get_option('nias_course_cpt_rewrite') === 'on') {
        delete_option('nias_course_cpt_rewrite');
        add_action('wp_loaded', function () { flush_rewrite_rules(false); });
    }
}, 6);

/** Classic editor for «دوره», like WooCommerce products (curriculum button, meta boxes). */
add_filter('use_block_editor_for_post_type', function ($use, $type) {
    return $type === NIAS_COURSE_CPT ? false : $use;
}, 10, 2);

/** Elementor: allow building «دوره» pages with it. */
add_filter('elementor/utils/is_post_support', function ($supported, $post_id, $post_type) {
    return $post_type === NIAS_COURSE_CPT ? true : $supported;
}, 10, 3);

/* -------------------------------------------------------------------------
 * Front end: a «دوره» page shows the course itself
 * ---------------------------------------------------------------------- */

/**
 * On a single «دوره», append the modern course view (and instructors) after the
 * content, unless the author placed a course shortcode / Elementor template.
 */
add_filter('the_content', 'nias_course_single_content', 20);
function nias_course_single_content($content)
{
    if (!is_singular(NIAS_COURSE_CPT) || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (strpos($content, '[nias_modern_course') !== false || strpos($content, 'nmc-') !== false || did_action('nias_course_single_rendered')) {
        return $content;
    }
    if (!apply_filters('nias_course_single_auto', true, get_the_ID())) {
        return $content;
    }
    do_action('nias_course_single_rendered');
    $out = $content;
    if (function_exists('nias_modern_course_has_curriculum') && nias_modern_course_has_curriculum(get_the_ID())) {
        $out .= do_shortcode('[nias_modern_course id="' . (int) get_the_ID() . '"]');
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Admin list: curriculum shortcut + lesson count
 * ---------------------------------------------------------------------- */

add_filter('manage_' . NIAS_COURSE_CPT . '_posts_columns', function ($cols) {
    $out = array();
    foreach ($cols as $k => $v) {
        $out[$k] = $v;
        if ($k === 'title') {
            $out['nias_lessons'] = __('Lessons', 'nias-course-widget');
        }
    }
    return $out;
});
add_action('manage_' . NIAS_COURSE_CPT . '_posts_custom_column', function ($col, $post_id) {
    if ($col !== 'nias_lessons') {
        return;
    }
    $n = 0;
    foreach ((array) carbon_get_post_meta($post_id, 'course_sections') as $s) {
        $n += isset($s['lessons']) ? count((array) $s['lessons']) : 0;
    }
    $url = admin_url('admin.php?page=nias-course-curriculum&product=' . (int) $post_id);
    echo '<a href="' . esc_url($url) . '">' . esc_html($n ? sprintf(__('%s lessons', 'nias-course-widget'), number_format_i18n($n)) : __('Add lessons', 'nias-course-widget')) . '</a>';
}, 10, 2);

/** Admin notice explaining free mode (once WooCommerce is missing). */
add_action('admin_notices', function () {
    if (nias_wc_active() || !current_user_can('manage_options')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || (strpos((string) $screen->id, 'nias-course') === false && $screen->post_type !== NIAS_COURSE_CPT)) {
        return;
    }
    echo '<div class="notice notice-info"><p>' . wp_kses_post(sprintf(
        __('Nias Course Builder is running in <b>free mode (without WooCommerce)</b>: build courses from the <a href="%s">"Courses"</a> menu. Public lessons are shown to everyone and "private" lessons to logged-in users. To sell courses, install WooCommerce.', 'nias-course-widget'),
        esc_url(admin_url('edit.php?post_type=' . NIAS_COURSE_CPT))
    )) . '</p></div>';
});

/* -------------------------------------------------------------------------
 * Enrolment record for free courses (certificates, "my courses")
 * ---------------------------------------------------------------------- */

/** Free courses a user has started (from the synced progress), course id => first-seen date. */
function nias_free_course_enrolments($user_id)
{
    $user_id = (int) $user_id;
    if (!$user_id) {
        return array();
    }
    $started = get_user_meta($user_id, '_nias_course_started', true);
    $started = is_array($started) ? $started : array();
    $progress = get_user_meta($user_id, '_nias_course_progress', true);
    foreach ((array) $progress as $pid => $pct) {
        if (!isset($started[$pid])) {
            $started[$pid] = '';
        }
    }
    $out = array();
    foreach ($started as $pid => $date) {
        if (nias_course_is_free($pid) && get_post_status($pid) === 'publish') {
            $out[(int) $pid] = $date ? $date : current_time('Y-m-d');
        }
    }
    return $out;
}

/** Remember the first day a user opened a free course. */
add_action('nias_course_progress_updated', function ($user_id, $post_id) {
    if (!nias_course_is_free($post_id)) {
        return;
    }
    $started = get_user_meta($user_id, '_nias_course_started', true);
    $started = is_array($started) ? $started : array();
    if (empty($started[$post_id])) {
        $started[$post_id] = current_time('Y-m-d');
        update_user_meta($user_id, '_nias_course_started', $started);
        do_action('nias_free_course_started', (int) $user_id, (int) $post_id);
    }
}, 5, 2);
