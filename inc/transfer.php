<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «کپی و انتقال دوره» (duplicate / export / import).
 *
 *   - «کپی دوره» (row action on products and «دوره»): a draft with the same
 *     content, curriculum, course settings and its own copies of the quizzes
 *     used in the lessons (ids remapped), so results stay separate.
 *     Products are copied with WooCommerce's own duplicator (prices, images,
 *     categories); per-course statistics are not copied.
 *   - «خروجی» → JSON file: course text, curriculum, quizzes, course settings.
 *     «ورودی» on another site creates a draft course + quizzes from it.
 *     Media keep their original addresses (re-upload on the new site if the
 *     old one goes away).
 *
 * @package nias-course-widget
 */

function nias_transfer_enabled()
{
    return (bool) apply_filters('nias_transfer_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_transfer_enabled') === 'on');
}

/** Course settings worth carrying (per-site statistics are left out). */
function nias_transfer_meta_keys()
{
    return apply_filters('nias_transfer_meta_keys', array('_nias_access_len', '_nias_access_unit', '_nias_renew_days', '_nias_prereq_need', '_nias_prereq_mode', '_nias_spot_course', '_nias_spot_devices', '_nias_spot_devices_plat', '_nias_spot_offline', '_nias_spot_limit', '_nias_spot_expires', '_nias_meta_values'));
}

/** Per-course data that must never follow a copy. */
function nias_transfer_drop_keys()
{
    return array('_nias_rating_summary', '_nias_rating_avg', '_nias_rating_count', '_nias_access_since', '_nias_pending', '_nias_course_quiz_ids', '_wc_average_rating', '_wc_rating_count', '_wc_review_count', 'total_sales', '_nias_course_trust');
}

/** Quiz ids used by a course's lessons (quiz + score gate). */
function nias_transfer_quiz_ids($sections)
{
    $ids = array();
    foreach ((array) $sections as $s) {
        foreach ((array) (isset($s['lessons']) ? $s['lessons'] : array()) as $l) {
            foreach (array('lesson_quiz', 'lesson_gate') as $k) {
                if (!empty($l[$k]) && ctype_digit((string) $l[$k])) {
                    $ids[(int) $l[$k]] = true;
                }
            }
        }
    }
    return array_keys($ids);
}

/** Create a quiz from a model; returns the new id. */
function nias_transfer_make_quiz($model)
{
    if (!function_exists('nias_quiz_sanitize_model') || !defined('NIAS_QUIZ_CPT')) {
        return 0;
    }
    $model = nias_quiz_sanitize_model($model);
    $model['courseId'] = 0; // re-linked by its lessons
    $id = wp_insert_post(array('post_type' => NIAS_QUIZ_CPT, 'post_status' => 'publish', 'post_title' => $model['title']));
    if (!$id || is_wp_error($id)) {
        return 0;
    }
    nias_quiz_store($id, $model);
    return (int) $id;
}

/** Store sections (raw Carbon format) with quiz ids remapped, through the normal sanitizer. */
function nias_transfer_put_sections($post_id, $sections, $map)
{
    foreach ($sections as &$s) {
        foreach ($s['lessons'] as &$l) {
            foreach (array('lesson_quiz', 'lesson_gate') as $k) {
                if (!empty($l[$k]) && ctype_digit((string) $l[$k])) {
                    $l[$k] = isset($map[(int) $l[$k]]) ? (string) $map[(int) $l[$k]] : '';
                }
            }
        }
        unset($l);
    }
    unset($s);
    $clean = nias_course_sanitize_sections(nias_curriculum_js_to_input(nias_curriculum_sections_to_js($sections)));
    carbon_set_post_meta($post_id, 'course_sections', $clean);
    do_action('nias_course_sections_saved', $post_id);
}

/* -------------------------------------------------------------------------
 * Duplicate
 * ---------------------------------------------------------------------- */

function nias_course_duplicate($post_id)
{
    $post = get_post($post_id);
    if (!$post || !nias_is_course_post($post_id)) {
        return new WP_Error('nias_dup', __('Course not found.', 'nias-course-widget'));
    }
    $sections = (array) carbon_get_post_meta($post_id, 'course_sections');
    if ($post->post_type === 'product' && class_exists('WC_Admin_Duplicate_Product') && function_exists('wc_get_product')) {
        $dup  = new WC_Admin_Duplicate_Product();
        $prod = $dup->product_duplicate(wc_get_product($post_id));
        $new  = $prod ? (int) $prod->get_id() : 0;
    } else {
        $new = wp_insert_post(array('post_type' => $post->post_type, 'post_status' => 'draft', 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt, 'post_author' => get_current_user_id()));
        if ($new && !is_wp_error($new)) {
            foreach (get_post_meta($post_id) as $k => $vals) {
                if (in_array($k, array_merge(nias_transfer_drop_keys(), array('_edit_lock', '_edit_last')), true) || strpos($k, '_course_sections') !== false) {
                    continue;
                }
                foreach ($vals as $v) {
                    add_post_meta($new, $k, maybe_unserialize($v));
                }
            }
            foreach (get_object_taxonomies($post->post_type) as $tax) {
                wp_set_object_terms($new, wp_get_object_terms($post_id, $tax, array('fields' => 'ids')), $tax);
            }
        }
    }
    if (!$new || is_wp_error($new)) {
        return new WP_Error('nias_dup', __('The copy could not be created.', 'nias-course-widget'));
    }
    wp_update_post(array('ID' => $new, 'post_title' => sprintf(__('%s (copy)', 'nias-course-widget'), $post->post_title), 'post_status' => 'draft'));
    foreach (nias_transfer_drop_keys() as $k) {
        delete_post_meta($new, $k);
    }
    // Own copies of the quizzes.
    $map = array();
    foreach (nias_transfer_quiz_ids($sections) as $qid) {
        $q = function_exists('nias_quiz_get') ? nias_quiz_get($qid) : null;
        if ($q) {
            $q['title'] = sprintf(__('%s (copy)', 'nias-course-widget'), $q['title']);
            $map[$qid]  = nias_transfer_make_quiz($q);
        }
    }
    nias_transfer_put_sections($new, $sections, $map);
    do_action('nias_course_duplicated', $new, $post_id, $map);
    return (int) $new;
}

/* -------------------------------------------------------------------------
 * Export / import
 * ---------------------------------------------------------------------- */

function nias_course_export($post_id)
{
    $post     = get_post($post_id);
    $sections = (array) carbon_get_post_meta($post_id, 'course_sections');
    $quizzes  = array();
    foreach (nias_transfer_quiz_ids($sections) as $qid) {
        $q = function_exists('nias_quiz_get') ? nias_quiz_get($qid) : null;
        if ($q) {
            unset($q['id']);
            $quizzes[(string) $qid] = $q;
        }
    }
    $meta = array();
    foreach (nias_transfer_meta_keys() as $k) {
        $v = get_post_meta($post_id, $k, true);
        if ($v !== '' && $v !== null) {
            $meta[$k] = $v;
        }
    }
    $price = '';
    if ($post->post_type === 'product' && function_exists('wc_get_product') && ($p = wc_get_product($post_id))) {
        $price = $p->get_regular_price();
    }
    return array(
        'format'   => 'nias-course-export',
        'version'  => 1,
        'plugin'   => defined('NIAS_COURSE_VERSION') ? NIAS_COURSE_VERSION : '',
        'site'     => home_url('/'),
        'date'     => gmdate('c'),
        'type'     => $post->post_type,
        'title'    => $post->post_title,
        'content'  => $post->post_content,
        'excerpt'  => $post->post_excerpt,
        'price'    => $price,
        'image'    => (string) get_the_post_thumbnail_url($post_id, 'full'),
        'sections' => $sections,
        'quizzes'  => $quizzes,
        'meta'     => $meta,
    );
}

function nias_course_import($data)
{
    if (!is_array($data) || ($data['format'] ?? '') !== 'nias-course-export' || empty($data['title']) || !isset($data['sections']) || !is_array($data['sections'])) {
        return new WP_Error('nias_import', __('This file is not a Nias Course Builder export.', 'nias-course-widget'));
    }
    $type = (($data['type'] ?? '') === 'product' && function_exists('nias_wc_active') && nias_wc_active()) ? 'product' : (defined('NIAS_COURSE_CPT') && function_exists('nias_course_cpt_enabled') && nias_course_cpt_enabled() ? NIAS_COURSE_CPT : 'product');
    if ($type === 'product' && class_exists('WC_Product_Simple')) {
        $p = new WC_Product_Simple();
        $p->set_name(sanitize_text_field($data['title']));
        $p->set_description(wp_kses_post($data['content'] ?? ''));
        $p->set_short_description(wp_kses_post($data['excerpt'] ?? ''));
        $p->set_virtual(true);
        $p->set_status('draft');
        if (isset($data['price']) && is_numeric($data['price'])) {
            $p->set_regular_price((string) $data['price']);
        }
        $new = (int) $p->save();
    } else {
        $new = (int) wp_insert_post(array('post_type' => $type, 'post_status' => 'draft', 'post_title' => sanitize_text_field($data['title']), 'post_content' => wp_kses_post($data['content'] ?? ''), 'post_excerpt' => wp_kses_post($data['excerpt'] ?? '')));
    }
    if (!$new) {
        return new WP_Error('nias_import', __('The course could not be created.', 'nias-course-widget'));
    }
    $map = array();
    foreach ((array) ($data['quizzes'] ?? array()) as $old => $model) {
        if (is_array($model)) {
            $map[(int) $old] = nias_transfer_make_quiz($model);
        }
    }
    foreach ((array) ($data['meta'] ?? array()) as $k => $v) {
        if (in_array($k, nias_transfer_meta_keys(), true)) {
            update_post_meta($new, $k, is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field((string) $v));
        }
    }
    nias_transfer_put_sections($new, (array) $data['sections'], $map);
    do_action('nias_course_imported', $new, $data, $map);
    return $new;
}

/* -------------------------------------------------------------------------
 * Admin: row actions + handlers + page
 * ---------------------------------------------------------------------- */

function nias_transfer_row_actions($actions, $post)
{
    if (nias_transfer_enabled() && nias_is_course_post($post->ID) && current_user_can('edit_post', $post->ID)) {
        $actions['nias_dup'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_course_dup&post=' . (int) $post->ID), 'nias_course_dup_' . $post->ID)) . '">' . esc_html__('Copy course', 'nias-course-widget') . '</a>';
        $actions['nias_exp'] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_course_export&post=' . (int) $post->ID), 'nias_course_export_' . $post->ID)) . '">' . esc_html__('Export course', 'nias-course-widget') . '</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'nias_transfer_row_actions', 20, 2);
add_filter('page_row_actions', 'nias_transfer_row_actions', 20, 2);

add_action('admin_post_nias_course_dup', function () {
    $pid = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!nias_transfer_enabled() || !$pid || !current_user_can('edit_post', $pid) || !check_admin_referer('nias_course_dup_' . $pid)) {
        wp_die(esc_html__('You do not have access.', 'nias-course-widget'), 403);
    }
    $new = nias_course_duplicate($pid);
    if (is_wp_error($new)) {
        wp_die(esc_html($new->get_error_message()));
    }
    wp_safe_redirect(add_query_arg('nias_dup_done', 1, get_edit_post_link($new, 'raw')));
    exit;
});

add_action('admin_post_nias_course_export', function () {
    $pid = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!nias_transfer_enabled() || !$pid || !current_user_can('edit_post', $pid) || !check_admin_referer('nias_course_export_' . $pid)) {
        wp_die(esc_html__('You do not have access.', 'nias-course-widget'), 403);
    }
    $json = wp_json_encode(nias_course_export($pid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('nias-course-' . sanitize_title(get_the_title($pid)) . '.json'));
    echo $json; // phpcs:ignore -- JSON file
    exit;
});

add_action('admin_notices', function () {
    if (isset($_GET['nias_dup_done'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The course copy was created (draft). Lesson quizzes were also copied separately so the two courses\' results do not mix.', 'nias-course-widget') . '</p></div>';
    }
});

add_action('admin_menu', function () {
    if (!nias_transfer_enabled()) {
        return;
    }
    add_submenu_page('nias-course-settings', __('Course transfer', 'nias-course-widget'), __('Course transfer', 'nias-course-widget'), 'manage_options', 'nias-course-transfer', 'nias_transfer_page');
}, 42);

function nias_transfer_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $msg = '';
    if (isset($_POST['nias_import']) && check_admin_referer('nias_course_import', 'nias_import_nonce')) {
        $raw = !empty($_FILES['nias_file']['tmp_name']) && is_uploaded_file($_FILES['nias_file']['tmp_name']) && (int) $_FILES['nias_file']['size'] < 20 * MB_IN_BYTES ? file_get_contents($_FILES['nias_file']['tmp_name']) : '';
        $res = nias_course_import(json_decode((string) $raw, true));
        $msg = is_wp_error($res)
            ? '<div class="notice notice-error inline"><p>' . esc_html($res->get_error_message()) . '</p></div>'
            : '<div class="notice notice-success inline"><p>' . esc_html__('The course was created as a draft.', 'nias-course-widget') . ' <a href="' . esc_url(get_edit_post_link($res)) . '">' . esc_html__('Edit course', 'nias-course-widget') . '</a></p></div>';
    }
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    echo '<div class="nias-settings-app" dir="rtl">';
    nias_settings_topbar('transfer');
    echo '<div class="nias-set-shell"><div class="nias-set-main">' . $msg; // phpcs:ignore -- escaped above
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Export a course', 'nias-course-widget'));
    ?>
    <div class="nias-card-pad nias-stack">
        <div class="nias-row-desc"><?php echo esc_html__('A JSON file with the course text, chapters and lessons (with all settings such as drip, assignment and qualities), lesson quizzes and course settings. "Copy course" and "Export course" are also in the courses list (under each course name). Video and file addresses stay the same.', 'nias-course-widget'); ?></div>
        <div data-nias-exp-pick style="max-width:480px"></div>
        <div><a class="nias-btn-primary" data-nias-exp-go href="#" style="text-decoration:none;display:inline-flex"><?php echo esc_html__('Download export file', 'nias-course-widget'); ?></a></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var h = document.querySelector('[data-nias-exp-pick]'), go = document.querySelector('[data-nias-exp-go]'), id = 0;
        var urls = <?php echo wp_json_encode(array('base' => admin_url('admin-post.php?action=nias_course_export&post='), 'nonce' => '')); ?>;
        if (window.NiasPicker) window.NiasPicker.attach(h, { source: 'product', emptyLabel: 'یک دوره انتخاب کنید…', placeholder: 'جستجوی دوره…', clearable: false, value: [], onChange: function (v) { id = v[0] ? v[0].id : 0; } });
        go.addEventListener('click', function (e) {
            e.preventDefault(); if (!id) { alert('ابتدا یک دوره انتخاب کنید.'); return; }
            var b = new URLSearchParams(); b.append('action', 'nias_course_export_link'); b.append('post', id); b.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('nias_course_export_link')); ?>');
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }).then(function (r) { if (r && r.success) location.href = r.data.url; else alert('دسترسی ندارید.'); });
        });
    });
    </script>
    <?php
    nias_set_card_close();
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 15V3m0 0L8 7m4-4 4 4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Import a course from a file', 'nias-course-widget'));
    ?>
    <form method="post" enctype="multipart/form-data" class="nias-card-pad nias-stack">
        <?php wp_nonce_field('nias_course_import', 'nias_import_nonce'); ?>
        <div class="nias-row-desc"><?php echo esc_html__('A Nias Course Builder export JSON file (from this site or another site). The course is created as a draft and its quizzes as new quizzes.', 'nias-course-widget'); ?></div>
        <input type="file" name="nias_file" accept=".json,application/json" required>
        <div><button type="submit" name="nias_import" value="1" class="nias-btn-primary" style="border:0"><?php echo esc_html__('Import course', 'nias-course-widget'); ?></button></div>
    </form>
    <?php
    nias_set_card_close();
    echo '</div></div></div>';
}

add_action('wp_ajax_nias_course_export_link', function () {
    $pid = isset($_POST['post']) ? (int) $_POST['post'] : 0;
    if (!check_ajax_referer('nias_course_export_link', '_wpnonce', false) || !$pid || !current_user_can('edit_post', $pid) || !nias_is_course_post($pid)) {
        wp_send_json_error(null, 403);
    }
    wp_send_json_success(array('url' => wp_nonce_url(admin_url('admin-post.php?action=nias_course_export&post=' . $pid), 'nias_course_export_' . $pid)));
});
