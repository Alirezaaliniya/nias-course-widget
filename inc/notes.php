<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «یادداشت و نشانک زمان‌دار» (timestamped notes on lesson videos).
 *
 *   - Tab «یادداشت‌ها» on video lessons: "یادداشت در ۰۳:۱۲" saves a note at the
 *     player's current time; clicking a note jumps back to that moment.
 *   - «یادداشت‌های من» in the account (WooCommerce tab or [nias_my_notes]):
 *     every note of every course, searchable, with a link back to the lesson
 *     and a plain-text export.
 *   - Private to the student: user meta _nias_notes_{course} (≤ 500 notes).
 *
 * @package nias-course-widget
 */

function nias_notes_enabled()
{
    return (bool) apply_filters('nias_notes_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_notes_enabled') === 'on');
}

function nias_notes_get($user_id, $post_id)
{
    $v = get_user_meta((int) $user_id, '_nias_notes_' . (int) $post_id, true);
    return is_array($v) ? $v : array();
}

function nias_notes_save($user_id, $post_id, $notes)
{
    $notes = array_slice(array_values($notes), -500);
    if ($notes) {
        update_user_meta((int) $user_id, '_nias_notes_' . (int) $post_id, $notes);
    } else {
        delete_user_meta((int) $user_id, '_nias_notes_' . (int) $post_id);
    }
}

function nias_notes_time($t)
{
    $t = max(0, (int) $t);
    $h = floor($t / 3600);
    $s = ($h ? $h . ':' : '') . sprintf($h ? '%02d:%02d' : '%d:%02d', floor($t % 3600 / 60), $t % 60);
    return nias_fa_digits($s);
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function nias_notes_request()
{
    $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!nias_notes_enabled() || !$pid || !is_user_logged_in() || !nias_is_course_post($pid) || !check_ajax_referer('nias_notes_' . $pid, 'nonce', false)) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    return $pid;
}

add_action('wp_ajax_nias_notes_list', function () {
    $pid    = nias_notes_request();
    $lesson = isset($_POST['lesson']) ? sanitize_key(wp_unslash($_POST['lesson'])) : '';
    $out    = array();
    foreach (nias_notes_get(get_current_user_id(), $pid) as $n) {
        if ($lesson === '' || $n['lesson'] === $lesson) {
            $out[] = array('id' => $n['id'], 'lesson' => $n['lesson'], 't' => (int) $n['t'], 'time' => nias_notes_time($n['t']), 'text' => $n['text']);
        }
    }
    usort($out, function ($a, $b) { return $a['t'] - $b['t']; });
    wp_send_json_success(array('items' => $out));
});

add_action('wp_ajax_nias_notes_add', function () {
    $pid    = nias_notes_request();
    $uid    = get_current_user_id();
    $lesson = isset($_POST['lesson']) ? sanitize_key(wp_unslash($_POST['lesson'])) : '';
    $text   = isset($_POST['text']) ? trim(sanitize_textarea_field(wp_unslash($_POST['text']))) : '';
    $t      = isset($_POST['t']) ? max(0, min(86400, (int) round((float) $_POST['t']))) : 0;
    if (!preg_match('/^c\d+l\d+$/', $lesson) || $text === '') {
        wp_send_json_error(array('message' => __('Write the note text.', 'nias-course-widget')));
    }
    $notes = nias_notes_get($uid, $pid);
    if (count($notes) >= 500) {
        wp_send_json_error(array('message' => __('Maximum 500 notes per course.', 'nias-course-widget')));
    }
    $titles = function_exists('nias_watch_lessons') ? nias_watch_lessons($pid) : array();
    $n = array('id' => substr(md5(uniqid('', true)), 0, 10), 'lesson' => $lesson, 'title' => isset($titles[$lesson]) ? $titles[$lesson] : '', 't' => $t, 'text' => mb_substr($text, 0, 2000), 'at' => time());
    $notes[] = $n;
    nias_notes_save($uid, $pid, $notes);
    wp_send_json_success(array('item' => array('id' => $n['id'], 'lesson' => $lesson, 't' => $t, 'time' => nias_notes_time($t), 'text' => $n['text'])));
});

add_action('wp_ajax_nias_notes_delete', function () {
    $pid = nias_notes_request();
    $id  = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
    $uid = get_current_user_id();
    nias_notes_save($uid, $pid, array_filter(nias_notes_get($uid, $pid), function ($n) use ($id) { return $n['id'] !== $id; }));
    wp_send_json_success();
});

/** Plain-text export of all the user's notes. */
add_action('wp_ajax_nias_notes_export', function () {
    if (!nias_notes_enabled() || !check_ajax_referer('nias_notes_export', 'n', false)) {
        wp_die(esc_html__('You do not have access.', 'nias-course-widget'), 403);
    }
    $uid = get_current_user_id();
    $out = '';
    foreach (nias_notes_courses($uid) as $pid) {
        $out .= '# ' . get_the_title($pid) . "\r\n\r\n";
        $notes = nias_notes_get($uid, $pid);
        usort($notes, function ($a, $b) { return strcmp($a['lesson'], $b['lesson']) ?: ($a['t'] - $b['t']); });
        $cur = null;
        foreach ($notes as $n) {
            if ($cur !== $n['lesson']) {
                $out .= '## ' . ($n['title'] !== '' ? $n['title'] : $n['lesson']) . "\r\n";
                $cur = $n['lesson'];
            }
            $out .= '[' . nias_notes_time($n['t']) . '] ' . str_replace(array("\r", "\n"), ' ', $n['text']) . "\r\n";
        }
        $out .= "\r\n";
    }
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('یادداشت‌های من.txt'));
    echo "\xEF\xBB\xBF" . $out; // BOM so Notepad shows Persian
    exit;
});

/** Courses where the user has notes. */
function nias_notes_courses($user_id)
{
    global $wpdb;
    $keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, $wpdb->esc_like('_nias_notes_') . '%'));
    return array_values(array_filter(array_map(function ($k) { return (int) substr($k, 12); }, $keys), function ($pid) { return $pid && get_post_status($pid); }));
}

/* -------------------------------------------------------------------------
 * Course view data
 * ---------------------------------------------------------------------- */

add_filter('nias_modern_notes_client', function ($v, $post_id) {
    if (!nias_notes_enabled() || !is_user_logged_in()) {
        return null;
    }
    return array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('nias_notes_' . (int) $post_id));
}, 10, 2);

/* -------------------------------------------------------------------------
 * «یادداشت‌های من»
 * ---------------------------------------------------------------------- */

function nias_notes_account_html($user_id = 0)
{
    $user_id = $user_id ? $user_id : get_current_user_id();
    $courses = nias_notes_courses($user_id);
    ob_start();
    ?>
    <div class="nnt" dir="rtl">
    <style>
    .nnt{font-size:14px}
    .nnt-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
    .nnt-top input{flex:1 1 220px;border:1px solid #dbe3ef;border-radius:10px;padding:8px 12px;font:inherit}
    .nnt-top a{border:1px solid #dbe3ef;border-radius:10px;padding:8px 14px;text-decoration:none;font-weight:700}
    .nnt-c{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:12px 14px;margin-bottom:12px}
    .nnt-c h3{margin:0 0 8px;font-size:15px}
    .nnt-l{font-weight:700;color:#475569;margin:10px 0 4px;font-size:13px}
    .nnt-n{display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-top:1px solid #f1f5f9}
    .nnt-n a{flex:none;font-weight:800;text-decoration:none;background:#eef5ff;color:#1e4fb8;border-radius:8px;padding:2px 8px;font-size:12.5px}
    .nnt-n span{line-height:1.9;white-space:pre-wrap}
    .nnt-empty{color:#64748b}
    </style>
    <?php if (!$courses) : ?>
        <p class="nnt-empty"><?php echo esc_html__('You have not written any notes yet. In video lessons you can add a note at any moment from the "Notes" tab.', 'nias-course-widget'); ?></p>
    <?php else : ?>
        <div class="nnt-top"><input type="search" placeholder="<?php echo esc_attr__('Search notes…', 'nias-course-widget'); ?>" oninput="var q=this.value.trim();document.querySelectorAll('.nnt-n').forEach(function(n){n.style.display=!q||n.textContent.indexOf(q)>-1?'':'none';});">
            <a href="<?php echo esc_url(add_query_arg(array('action' => 'nias_notes_export', 'n' => wp_create_nonce('nias_notes_export')), admin_url('admin-ajax.php'))); ?>"><?php echo esc_html__('Download text file', 'nias-course-widget'); ?></a></div>
        <?php foreach ($courses as $pid) :
            $notes = nias_notes_get($user_id, $pid);
            usort($notes, function ($a, $b) { return strcmp($a['lesson'], $b['lesson']) ?: ($a['t'] - $b['t']); });
            $base = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid);
            $cur  = null; ?>
            <div class="nnt-c"><h3><?php echo esc_html(get_the_title($pid)); ?></h3>
            <?php foreach ($notes as $n) :
                if ($cur !== $n['lesson']) { $cur = $n['lesson']; echo '<div class="nnt-l">' . esc_html($n['title'] !== '' ? $n['title'] : $n['lesson']) . '</div>'; } ?>
                <div class="nnt-n"><a href="<?php echo esc_url(add_query_arg(array('nias_at' => $n['lesson'] . '-' . (int) $n['t']), $base)); ?>"><?php echo esc_html(nias_notes_time($n['t'])); ?></a><span><?php echo esc_html($n['text']); ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('nias_my_notes', function () {
    return nias_notes_enabled() && is_user_logged_in() ? nias_notes_account_html() : '';
});

add_action('init', function () {
    if (nias_notes_enabled() && function_exists('nias_wc_active') && nias_wc_active()) {
        add_rewrite_endpoint('my-notes', EP_ROOT | EP_PAGES);
        $rules = get_option('rewrite_rules');
        if (is_array($rules) && strpos(implode(' ', array_keys($rules)), 'my-notes') === false) {
            add_action('wp_loaded', function () { flush_rewrite_rules(false); });
        }
    }
}, 20);
add_filter('woocommerce_get_query_vars', function ($vars) {
    if (nias_notes_enabled()) {
        $vars['my-notes'] = 'my-notes';
    }
    return $vars;
});
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!nias_notes_enabled()) {
        return $items;
    }
    $out = array();
    foreach ($items as $k => $v) {
        if ($k === 'customer-logout') {
            $out['my-notes'] = __('My notes', 'nias-course-widget');
        }
        $out[$k] = $v;
    }
    return $out;
}, 31);
add_action('woocommerce_account_my-notes_endpoint', function () {
    echo '<h3>' . esc_html__('My notes', 'nias-course-widget') . '</h3>' . nias_notes_account_html(); // phpcs:ignore -- escaped inside
});
add_filter('woocommerce_endpoint_my-notes_title', function () {
    return __('My notes', 'nias-course-widget');
});
