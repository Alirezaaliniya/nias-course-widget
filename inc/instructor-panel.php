<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instructor panel (پنل مدرس) — optional, switched on in «مدرسین».
 *
 * Inside the front-end instructor dashboard an instructor gets tabs to manage
 * their own courses without wp-admin:
 *   - «دوره‌های من»: their courses → the same curriculum editor (chapters,
 *     lessons, media, lesson quiz / score gate, content editor).
 *   - «آزمون‌ها»: the same quiz builder, limited to quizzes they made or that
 *     belong to their courses; a quiz can only be linked to their courses.
 *   - «نتایج و تصحیح» and «پرسش و پاسخ» (already scoped to their courses).
 *
 * Security model: instructors get NO WordPress post-editing capability (that
 * would also open the WooCommerce product/REST API: price, stock…). Each plugin
 * endpoint asks nias_can_edit_course() / nias_can_manage_quiz() instead, and
 * the curriculum save ignores the admin-only parts (SpotPlayer, instructors,
 * custom meta) for them. Media: upload_files is granted only while the panel
 * allows it, and the media library then lists only the instructor's own files.
 *
 * @package nias-course-widget
 */

/** Panel switched on (and instructors feature on)? */
function nias_inst_panel_on()
{
    return function_exists('nias_instructors_enabled') && nias_instructors_enabled() && get_option('_nias_inst_panel') === 'on';
}

/** Panel feature flags: curriculum | quiz | upload (default on once the panel is on). */
function nias_inst_panel_allows($what)
{
    if (!nias_inst_panel_on()) {
        return false;
    }
    $v = get_option('_nias_inst_panel_' . $what, 'on');
    return $v !== 'off';
}

/** An instructor (not a site admin) using the panel. */
function nias_inst_panel_user($user_id = 0)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    return $user_id && nias_inst_panel_on() && function_exists('nias_user_is_instructor') && nias_user_is_instructor($user_id) && !user_can($user_id, 'manage_options');
}

/** Course ids of an instructor (cached per request). */
function nias_inst_course_ids($user_id)
{
    static $cache = array();
    $user_id = (int) $user_id;
    if (!isset($cache[$user_id])) {
        $cache[$user_id] = function_exists('nias_get_instructor_courses') ? array_map(function ($p) {
            return (int) $p->ID;
        }, nias_get_instructor_courses($user_id)) : array();
    }
    return $cache[$user_id];
}

/** May this user edit the curriculum of this course (admin rights, or panel + assigned instructor)? */
function nias_can_edit_course($user_id, $post_id)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (!$user_id || !function_exists('nias_is_course_post') || !nias_is_course_post($post_id)) {
        return false;
    }
    if (user_can($user_id, 'edit_post', $post_id)) {
        return true;
    }
    return nias_inst_panel_allows('curriculum') && nias_inst_panel_user($user_id) && in_array($post_id, nias_inst_course_ids($user_id), true);
}

/**
 * Quiz ids an instructor manages in the panel: their own + those of their courses.
 *
 * @return int[]
 */
function nias_inst_quiz_ids($user_id)
{
    static $cache = array();
    $user_id = (int) $user_id;
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    $ids = array_map('intval', get_posts(array(
        'post_type' => NIAS_QUIZ_CPT, 'post_status' => array('publish', 'draft'), 'author' => $user_id,
        'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
    )));
    foreach (nias_inst_course_ids($user_id) as $pid) {
        $ids = array_merge($ids, function_exists('nias_quiz_for_course') ? nias_quiz_for_course($pid) : array());
    }
    return $cache[$user_id] = array_values(array_unique(array_map('intval', $ids)));
}

/** May this user use the quiz builder at all? */
function nias_can_use_quiz_builder($user_id = 0)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    return user_can($user_id, 'manage_options') || (nias_inst_panel_allows('quiz') && nias_inst_panel_user($user_id));
}

/** May this user edit / delete / copy this quiz? */
function nias_can_manage_quiz($user_id, $quiz_id)
{
    $user_id = (int) $user_id;
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    return nias_can_use_quiz_builder($user_id) && in_array((int) $quiz_id, nias_inst_quiz_ids($user_id), true);
}

/* -------------------------------------------------------------------------
 * Media for instructors
 * ---------------------------------------------------------------------- */

// upload_files while the panel allows uploads.
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (in_array('upload_files', (array) $caps, true) && empty($allcaps['upload_files']) && $user instanceof WP_User
        && in_array(defined('NIAS_INSTRUCTOR_ROLE') ? NIAS_INSTRUCTOR_ROLE : 'nias_instructor', (array) $user->roles, true)
        && nias_inst_panel_allows('upload')) {
        $allcaps['upload_files'] = true;
    }
    return $allcaps;
}, 10, 4);

// The media library shows an instructor only their own uploads.
add_filter('ajax_query_attachments_args', function ($q) {
    if (nias_inst_panel_user()) {
        $q['author'] = get_current_user_id();
    }
    return $q;
});

// WooCommerce keeps customers out of wp-admin; uploads go through
// wp-admin/async-upload.php, so let instructors reach just that file.
add_filter('woocommerce_prevent_admin_access', function ($prevent) {
    if ($prevent && nias_inst_panel_user() && isset($_SERVER['SCRIPT_FILENAME']) && basename((string) $_SERVER['SCRIPT_FILENAME']) === 'async-upload.php' && nias_inst_panel_allows('upload')) {
        return false;
    }
    return $prevent;
});

/* -------------------------------------------------------------------------
 * Settings card (rendered on the «مدرسین» page)
 * ---------------------------------------------------------------------- */

function nias_inst_panel_handle_save()
{
    if (!isset($_POST['nias_save_inst_panel']) || !check_admin_referer('nias_inst_panel', 'nias_inst_panel_nonce') || !current_user_can('manage_options')) {
        return false;
    }
    foreach (array('_nias_inst_panel' => 'nias_inst_panel', '_nias_inst_panel_curriculum' => 'nias_inst_panel_curriculum', '_nias_inst_panel_quiz' => 'nias_inst_panel_quiz', '_nias_inst_panel_upload' => 'nias_inst_panel_upload', '_nias_inst_panel_create' => 'nias_inst_panel_create', '_nias_inst_panel_approval' => 'nias_inst_panel_approval') as $opt => $field) {
        update_option($opt, (isset($_POST[$field]) && $_POST[$field] === 'on') ? 'on' : 'off');
    }
    return true;
}

function nias_inst_panel_settings_card()
{
    $saved = nias_inst_panel_handle_save();
    if (function_exists('nias_set_saved_banner')) {
        nias_set_saved_banner($saved);
    }
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M3 9h18M8 4v5" stroke="#3858e9" stroke-width="1.8"/></svg>', __('Instructor panel', 'nias-course-widget'));
    echo '<form method="post">';
    wp_nonce_field('nias_inst_panel', 'nias_inst_panel_nonce');
    nias_set_toggle_row('nias_inst_panel', __('Enable instructor panel', 'nias-course-widget'),
        __('The "My courses", "Quizzes", "Results & grading" and "Q&A" tabs are shown in the "Instructor dashboard" so instructors can manage their own courses without entering the WordPress dashboard. Instructors only have access to courses they teach; price, stock and WooCommerce settings, SpotPlayer and instructor selection stay with the site admin.', 'nias-course-widget'), 'off');
    echo '<div data-nias-show-when="nias_inst_panel=on">';
    nias_set_toggle_row('nias_inst_panel_curriculum', __('Edit chapters and lessons', 'nias-course-widget'), __('Full lessons and chapters editor (content, video, file, private lesson, lesson quiz and prerequisite) for the instructor\'s own courses.', 'nias-course-widget'), 'on');
    nias_set_toggle_row('nias_inst_panel_quiz', __('Build and configure quizzes', 'nias-course-widget'), __('Quiz builder with all settings; instructors see their own quizzes and their courses\' quizzes and can only link them to their own courses.', 'nias-course-widget'), 'on');
    nias_set_toggle_row('nias_inst_panel_upload', __('File upload', 'nias-course-widget'), __('Instructors can upload images, videos and attachments; in the media library they only see their own files.', 'nias-course-widget'), 'on');
    nias_set_toggle_row('nias_inst_panel_create', __('Create new course', 'nias-course-widget'), __('Instructors can create new courses (with WooCommerce: a WooCommerce product; without WooCommerce: a "Course") and edit their courses\' details (title, description, image).', 'nias-course-widget'), 'on');
    nias_set_toggle_row('nias_inst_panel_approval', __('Requires approval', 'nias-course-widget'), __('An instructor\'s changes to a published course do not apply until the admin approves them, and the site shows the previous version; a newly created course also stays "Pending review" until the admin publishes it. Requests are reviewed in the "Instructor requests" submenu. With this option instructors can also propose the product price.', 'nias-course-widget'), 'off');
    echo '</div>';
    nias_set_save_button('nias_save_inst_panel');
    echo '</form>';
    nias_set_card_close();
}

/* -------------------------------------------------------------------------
 * Front-end panel: navigation + views
 * ---------------------------------------------------------------------- */

/** Is a panel view requested (and may the user see it)? */
function nias_inst_panel_route()
{
    return isset($_GET['nias_panel']) ? sanitize_key(wp_unslash($_GET['nias_panel'])) : '';
}

/** URL of a panel view on the dashboard page. */
function nias_inst_panel_url($view = '', $args = array())
{
    $base = remove_query_arg(array('nias_panel', 'nias_qr', 'nias_qa', 'course', 'quiz', 'user', 'f', 's', 'pg', 'qt', 'qc', 'qs', 'qp', 'qa_notice', 'nqr_notice', 'qpg', 'ppg'));
    if ($view === 'results') {
        return add_query_arg(array_merge(array('nias_qr' => 1), $args), $base);
    }
    if ($view === 'qa') {
        return add_query_arg(array_merge(array('nias_qa' => 1), $args), $base);
    }
    return $view ? add_query_arg(array_merge(array('nias_panel' => $view), $args), $base) : $base;
}

/**
 * Where «دورهٔ جدید» goes: the panel form for instructors, the WordPress
 * "add product" / "add «دوره»" screen for a site admin ('' = not allowed).
 */
function nias_inst_new_course_url($user_id)
{
    if (function_exists('nias_inst_can_create') && nias_inst_can_create($user_id)) {
        return nias_inst_panel_url('course');
    }
    if (user_can($user_id, 'manage_options')) {
        $type = function_exists('nias_inst_new_course_type') ? nias_inst_new_course_type() : 'product';
        return admin_url('post-new.php?post_type=' . $type);
    }
    return '';
}

/** Tab bar shown on every dashboard view when the panel is on. */
function nias_inst_panel_nav($user_id, $active)
{
    if (!nias_inst_panel_on()) {
        return '';
    }
    $tabs = array('home' => array(__('Dashboard', 'nias-course-widget'), nias_inst_panel_url()));
    $tabs['courses'] = array(__('My courses', 'nias-course-widget'), nias_inst_panel_url('courses'));
    if (nias_can_use_quiz_builder($user_id) && function_exists('nias_quiz_enabled') && nias_quiz_enabled()) {
        $tabs['quizzes'] = array(__('Quizzes', 'nias-course-widget'), nias_inst_panel_url('quizzes'));
        $tabs['results'] = array(__('Results & grading', 'nias-course-widget'), nias_inst_panel_url('results'));
    }
    if (function_exists('nias_qa_enabled') && nias_qa_enabled()) {
        $n = nias_qa_unanswered_count(nias_qa_staff_scope($user_id));
        $tabs['qa'] = array(__('Q&A', 'nias-course-widget') . ($n ? ' <b>' . esc_html(nias_fa_digits($n)) . '</b>' : ''), nias_inst_panel_url('qa'));
    }
    if (function_exists('nias_rev_enabled') && nias_rev_enabled()) {
        $tabs['earnings'] = array(__('Income', 'nias-course-widget'), nias_inst_panel_url('earnings'));
    }
    if (function_exists('nias_stats_enabled') && nias_stats_enabled()) {
        $tabs['stats'] = array(__('Reports', 'nias-course-widget'), nias_inst_panel_url('stats'));
    }
    if (function_exists('nias_assign_enabled') && nias_assign_enabled()) {
        $an = nias_assign_pending_count(nias_assign_scope($user_id));
        $tabs['assign'] = array(__('Assignments', 'nias-course-widget') . ($an ? ' <b>' . esc_html(nias_fa_digits($an)) . '</b>' : ''), nias_inst_panel_url('assign'));
    }
    $h = '<nav class="nip-nav" dir="rtl">';
    foreach ($tabs as $k => $t) {
        $h .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . esc_url($t[1]) . '">' . wp_kses($t[0], array('b' => array())) . '</a>';
    }
    $new = nias_inst_new_course_url($user_id);
    if ($new) {
        $h .= '<a class="nip-new" href="' . esc_url($new) . '">+ ' . esc_html__('New course', 'nias-course-widget') . '</a>';
    }
    return $h . '</nav>' . nias_inst_panel_css();
}

function nias_inst_panel_css()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
    .nip-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center;max-width:1280px;margin:0 auto 18px;padding:6px;background:#fff;border:1px solid #e6eaef;border-radius:14px;box-shadow:0 4px 14px rgba(15,23,42,.04);font-family:inherit}
    .nip-nav a{padding:9px 16px;border-radius:10px;color:#475569;font-weight:700;font-size:14px;text-decoration:none!important}
    .nip-nav a:hover{background:#f1f5f9}
    .nip-nav a.on{background:#3858e9;color:#fff}
    .nip-nav a.nip-new{margin-right:auto;background:#16a34a;color:#fff;box-shadow:0 6px 14px -6px rgba(22,163,74,.7)}
    .nip-nav a.nip-new:hover{background:#15803d}
    .nip-start{display:flex;align-items:center;gap:16px;flex-wrap:wrap;max-width:1280px;margin:0 auto 18px;padding:16px 18px;border-radius:16px;background:linear-gradient(135deg,#eef6ff,#f3fbf4);border:1px solid #dbe7f5;font-family:inherit}
    .nip-start b{display:block;font-size:16px;color:#1f2937;margin-bottom:3px}
    .nip-start span{font-size:13.5px;color:#475569}
    .nip-start .nip-btn{margin-right:auto}
    .nip-nav a b{display:inline-block;margin-right:5px;background:#f59e0b;color:#fff;border-radius:99px;padding:0 7px;font-size:12px}
    .nip-wrap{max-width:1280px;margin:0 auto;font-family:inherit;direction:rtl}
    .nip-h{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 14px}
    .nip-h h2{margin:0;font-size:20px;font-weight:800;flex:1}
    .nip-courses{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px}
    .nip-c{background:#fff;border:1px solid #e6eaef;border-radius:16px;overflow:hidden;display:flex;flex-direction:column}
    .nip-c-img{aspect-ratio:16/8;background:#eef2f7 center/cover no-repeat}
    .nip-c-b{padding:14px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
    .nip-c-t{font-size:15.5px;font-weight:800;color:#1f2937;line-height:1.6}
    .nip-c-m{display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;color:#64748b}
    .nip-c-m span{background:#f1f5f9;border-radius:99px;padding:2px 10px}
    .nip-c-m .warn{background:#fff4e2;color:#b45309}
    .nip-c-m .wait{background:#eef5ff;color:#2563c9}
    .nip-info{border-radius:12px;padding:11px 14px;margin:0 0 14px;font-size:14px;font-weight:600;background:#f1f5f9;color:#334155}
    .nip-info.ok{background:#ecf8ea;color:#2f7d27}.nip-info.wait{background:#eef5ff;color:#1e4fb8}.nip-info.err{background:#fdeaea;color:#b93b3b}
    .nip-form{display:flex;flex-direction:column;gap:14px;background:#fff;border:1px solid #e6eaef;border-radius:16px;padding:18px;max-width:820px}
    .nip-form label{display:flex;flex-direction:column;gap:6px;font-weight:700;font-size:14px;color:#1f2937}
    .nip-form label b{color:#dc2626}
    .nip-form input[type=text],.nip-form textarea{border:1px solid #dbe3ef;border-radius:10px;padding:9px 12px;font:inherit;font-weight:400;font-size:14px;width:100%;box-sizing:border-box}
    .nip-form small{font-weight:400;color:#64748b}
    .nip-img{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-weight:700;font-size:14px}
    .nip-img-box{width:160px;height:90px;border-radius:12px;background:#eef2f7 center/cover no-repeat;border:1px dashed #cbd5e1}
    .nip-pending-bar{max-width:1280px;margin:0 auto 12px}
    .nip-c-a{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto;padding-top:6px}
    .nip-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:10px;border:1px solid #dbe3ef;background:#f5f8ff;color:#3858e9!important;font-weight:700;font-size:13px;text-decoration:none!important}
    .nip-btn.pri{background:#3858e9;border-color:#3858e9;color:#fff!important}
    .nip-empty{padding:30px;text-align:center;color:#64748b;background:#fff;border:1px dashed #d8dee6;border-radius:14px}
    .nip-full{max-width:none}
    .nip-full .nc-app{min-height:0}
    .nip-hide-admin #nc-spot-open,.nip-hide-admin #nc-inst-open,.nip-hide-admin #nc-spot-drawer,.nip-hide-admin .nias-meta-box,.nip-hide-admin .nc-meta-box{display:none!important}
    </style>';
}

/** «دوره‌های من» */
function nias_inst_panel_courses_view($user_id)
{
    $ids = user_can($user_id, 'manage_options') && !nias_user_is_instructor($user_id)
        ? (function_exists('nias_instructor_dashboard_product_ids') ? nias_instructor_dashboard_product_ids($user_id, true) : array())
        : nias_inst_course_ids($user_id);
    _prime_post_caches($ids, false, true);
    $h = '<div class="nip-wrap"><div class="nip-h"><h2>' . esc_html__('My courses', 'nias-course-widget') . '</h2>'
        . (nias_inst_new_course_url($user_id) ? '<a class="nip-btn pri" href="' . esc_url(nias_inst_new_course_url($user_id)) . '">+ ' . esc_html__('New course', 'nias-course-widget') . '</a>' : '') . '</div>';
    if (function_exists('nias_inst_panel_notice')) {
        $h .= nias_inst_panel_notice();
    }
    if (!$ids) {
        $new = nias_inst_new_course_url($user_id);
        return $h . '<div class="nip-empty">' . esc_html($new ? __('You have no courses yet. Create your first course; courses the site admin assigns to you are shown here too.', 'nias-course-widget') : __('No course has been assigned to you yet. The site admin must select you under "Course instructors".', 'nias-course-widget'))
            . ($new ? '<div style="margin-top:14px"><a class="nip-btn pri" href="' . esc_url($new) . '">+ ' . esc_html__('Create your first course', 'nias-course-widget') . '</a></div>' : '') . '</div></div>';
    }
    $qa_counts = array();
    if (function_exists('nias_qa_enabled') && nias_qa_enabled()) {
        global $wpdb;
        foreach ((array) $wpdb->get_results('SELECT post_id, COUNT(*) n FROM ' . nias_qa_table() . " WHERE parent_id = 0 AND staff_replied = 0 AND resolved = 0 AND post_id IN (" . implode(',', array_map('intval', $ids)) . ') GROUP BY post_id') as $r) {
            $qa_counts[(int) $r->post_id] = (int) $r->n;
        }
    }
    $h .= '<div class="nip-courses">';
    foreach ($ids as $pid) {
        $p = get_post($pid);
        if (!$p) {
            continue;
        }
        $lessons = 0;
        foreach ((array) carbon_get_post_meta($pid, 'course_sections') as $s) {
            $lessons += isset($s['lessons']) ? count((array) $s['lessons']) : 0;
        }
        $img = get_the_post_thumbnail_url($pid, 'medium_large');
        $view = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid);
        $status = array('publish' => '', 'draft' => __('Draft (not approved)', 'nias-course-widget'), 'pending' => __('Pending review', 'nias-course-widget'), 'private' => __('Private', 'nias-course-widget'));
        $h .= '<article class="nip-c"><div class="nip-c-img"' . ($img ? ' style="background-image:url(' . esc_url($img) . ')"' : '') . '></div><div class="nip-c-b">'
            . '<div class="nip-c-t">' . esc_html($p->post_title) . '</div><div class="nip-c-m">'
            . '<span>' . esc_html(sprintf(__('%s lessons', 'nias-course-widget'), nias_fa_digits($lessons))) . '</span>'
            . (!empty($status[$p->post_status]) ? '<span class="' . ($p->post_status === 'pending' ? 'wait' : '') . '">' . esc_html($status[$p->post_status]) . '</span>' : '')
            . (!empty($qa_counts[$pid]) ? '<span class="warn">' . esc_html(sprintf(__('%s unanswered questions', 'nias-course-widget'), nias_fa_digits($qa_counts[$pid]))) . '</span>' : '')
            . (function_exists('nias_course_pending_get') && nias_course_pending_get($pid) ? '<span class="wait">' . esc_html__('Changes awaiting approval', 'nias-course-widget') . '</span>' : '')
            . '</div><div class="nip-c-a">'
            . (function_exists('nias_inst_can_create') && nias_inst_can_create($user_id) && nias_can_edit_course($user_id, $pid) ? '<a class="nip-btn" href="' . esc_url(nias_inst_panel_url('course', array('course' => $pid))) . '">' . esc_html__('Info', 'nias-course-widget') . '</a>' : '')
            . (nias_can_edit_course($user_id, $pid) ? '<a class="nip-btn pri" href="' . esc_url(nias_inst_panel_url('curriculum', array('course' => $pid))) . '">' . esc_html__('Edit lessons and chapters', 'nias-course-widget') . '</a>' : '')
            . (function_exists('nias_drip_enabled') && nias_drip_enabled() && nias_can_edit_course($user_id, $pid) ? '<a class="nip-btn" href="' . esc_url(nias_inst_panel_url('drip', array('course' => $pid))) . '">' . esc_html__('Students\' drip schedule', 'nias-course-widget') . '</a>' : '')
            . '<a class="nip-btn" href="' . esc_url($view) . '" target="_blank" rel="noopener">' . esc_html__('View course', 'nias-course-widget') . '</a>'
            . (!empty($qa_counts[$pid]) ? '<a class="nip-btn" href="' . esc_url(nias_inst_panel_url('qa', array('qc' => $pid))) . '">' . esc_html__('Questions', 'nias-course-widget') . '</a>' : '')
            . '</div></div></article>';
    }
    return $h . '</div></div>';
}

/** «ویرایش جلسات و فصل‌ها» inside the panel. */
function nias_inst_panel_curriculum_view($user_id)
{
    $pid = isset($_GET['course']) ? (int) $_GET['course'] : 0;
    if (!$pid || !nias_can_edit_course($user_id, $pid)) {
        return '<div class="nip-wrap"><div class="nip-empty">' . esc_html__('You do not have access to edit this course.', 'nias-course-widget') . '</div></div>';
    }
    ob_start();
    if (function_exists('nias_inst_panel_notice')) {
        echo '<div class="nip-pending-bar">' . nias_inst_panel_notice() . '</div>'; // phpcs:ignore -- escaped inside
    }
    if (function_exists('nias_course_pending_get') && isset(nias_course_pending_get($pid)['sections']) && !user_can($user_id, 'edit_post', $pid)) {
        echo '<div class="nip-pending-bar"><div class="nip-info wait">' . esc_html__('This is your version awaiting admin approval; until approved the site shows the previous lessons. You can keep editing.', 'nias-course-widget') . '</div></div>';
    } elseif (function_exists('nias_inst_needs_approval') && nias_inst_needs_approval($user_id, $pid)) {
        echo '<div class="nip-pending-bar"><div class="nip-info">' . esc_html__('This course is published; your changes apply on the site after admin approval.', 'nias-course-widget') . '</div></div>';
    }
    nias_curriculum_render_editor($pid, array(
        'panel'   => true,
        'back'    => nias_inst_panel_url('courses'),
        'quizUrl' => nias_inst_panel_url('quizzes'),
        'admin'   => user_can($user_id, 'edit_post', $pid), // admins keep SpotPlayer/instructor tools
    ));
    return '<div class="nip-wrap nip-full' . (user_can($user_id, 'edit_post', $pid) ? '' : ' nip-hide-admin') . '">' . ob_get_clean() . '</div>';
}

/** «آزمون‌ها» inside the panel. */
function nias_inst_panel_quizzes_view($user_id)
{
    if (!nias_can_use_quiz_builder($user_id) || !function_exists('nias_quiz_builder_app')) {
        return '<div class="nip-wrap"><div class="nip-empty">' . esc_html__('Quiz building is not enabled for you.', 'nias-course-widget') . '</div></div>';
    }
    ob_start();
    nias_quiz_builder_app();
    return '<div class="nip-wrap nip-full">' . ob_get_clean() . '</div>';
}

/**
 * Route a dashboard request to a panel view (null = not a panel view).
 *
 * @return string|null
 */
function nias_inst_panel_dispatch($user_id)
{
    if (!nias_inst_panel_on()) {
        return null;
    }
    $route = nias_inst_panel_route();
    if ($route === 'courses') {
        return nias_inst_panel_nav($user_id, 'courses') . nias_inst_panel_courses_view($user_id);
    }
    if ($route === 'curriculum') {
        return nias_inst_panel_nav($user_id, 'courses') . nias_inst_panel_curriculum_view($user_id);
    }
    if ($route === 'course' && function_exists('nias_inst_panel_details_view')) {
        return nias_inst_panel_nav($user_id, 'courses') . nias_inst_panel_details_view($user_id);
    }
    if ($route === 'quizzes') {
        return nias_inst_panel_nav($user_id, 'quizzes') . nias_inst_panel_quizzes_view($user_id);
    }
    if ($route === 'earnings' && function_exists('nias_rev_panel_html') && nias_rev_enabled()) {
        return nias_inst_panel_nav($user_id, 'earnings') . '<div class="nip-wrap">' . nias_rev_panel_html($user_id) . '</div>';
    }
    if ($route === 'stats' && function_exists('nias_stats_html') && nias_stats_enabled()) {
        return nias_inst_panel_nav($user_id, 'stats') . '<div class="nip-wrap">' . nias_stats_html($user_id, nias_inst_panel_url('stats')) . '</div>';
    }
    if ($route === 'assign' && function_exists('nias_assign_review_html') && nias_assign_enabled()) {
        return nias_inst_panel_nav($user_id, 'assign') . '<div class="nip-wrap">' . nias_assign_review_html() . '</div>';
    }
    if ($route === 'drip' && function_exists('nias_drip_tool_html')) {
        $pid = isset($_GET['course']) ? (int) $_GET['course'] : 0;
        if (!nias_drip_enabled() || !$pid || !nias_can_edit_course($user_id, $pid)) {
            return nias_inst_panel_nav($user_id, 'courses') . '<div class="nip-wrap"><div class="nip-empty">' . esc_html__('You do not have access to this section.', 'nias-course-widget') . '</div></div>';
        }
        return nias_inst_panel_nav($user_id, 'courses') . '<div class="nip-wrap"><div class="nip-card" style="padding:18px"><h3 style="margin:0 0 4px">' . esc_html(sprintf(__('Students\' drip schedule for "%s"', 'nias-course-widget'), get_the_title($pid))) . '</h3>'
            . '<p style="margin:0 0 14px;color:#64748b;font-size:13px">' . esc_html__('Find a student to see when their lessons open; you can open all lessons for them or move their course start.', 'nias-course-widget') . '</p>'
            . nias_drip_tool_html($pid) . '</div></div>';
    }
    return null;
}
