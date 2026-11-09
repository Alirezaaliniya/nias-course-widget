<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «تکلیف با بارگذاری فایل» (lesson assignments).
 *
 *   - A lesson can carry an assignment: instructions, allowed file types,
 *     max size and an optional pass score (curriculum editor, admin and
 *     instructor panel).
 *   - The student sends a file (+ a note) from the «تکلیف» tab of the lesson.
 *     Files go to uploads/nias-protected/assignments/{user}/ (never public)
 *     and are only served to the student, the course's instructors and admins.
 *   - Review queue («تکالیف» admin tab + instructor panel): score 0–100,
 *     feedback, «قبول» / «نیاز به ارسال دوباره». The student is told by e-mail
 *     and SMS (SMS module); instructors get «تکلیف جدید».
 *   - Table {prefix}nias_assign.
 *
 * @package nias-course-widget
 */

define('NIAS_ASSIGN_DB_VERSION', '1');

function nias_assign_enabled()
{
    return (bool) apply_filters('nias_assign_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_assign_enabled') === 'on' && get_option('nias_assign_db_version') === NIAS_ASSIGN_DB_VERSION);
}

function nias_assign_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_assign';
}

add_action('plugins_loaded', function () {
    if (get_option('nias_assign_db_version') === NIAS_ASSIGN_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = nias_assign_table();
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        lesson varchar(40) NOT NULL DEFAULT '',
        lesson_title varchar(190) NOT NULL DEFAULT '',
        file varchar(255) NOT NULL DEFAULT '',
        file_name varchar(190) NOT NULL DEFAULT '',
        file_size bigint(20) unsigned NOT NULL DEFAULT 0,
        note text NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'pending',
        score smallint(5) NOT NULL DEFAULT -1,
        feedback text NOT NULL,
        grader bigint(20) unsigned NOT NULL DEFAULT 0,
        created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        graded datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        KEY queue (status,post_id,created),
        KEY user_lesson (user_id,post_id,lesson,created)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('nias_assign_db_version', NIAS_ASSIGN_DB_VERSION, false);
}, 22);

/* -------------------------------------------------------------------------
 * Lesson settings
 * ---------------------------------------------------------------------- */

/** Assignment of a lesson (editor shape) or null. */
function nias_assign_cfg($lesson)
{
    if (empty($lesson['assign'])) {
        return null;
    }
    $types = strtolower((string) (isset($lesson['assignTypes']) ? $lesson['assignTypes'] : ''));
    $types = array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', $types !== '' ? $types : 'pdf,zip,rar,jpg,jpeg,png,docx'))));
    // Allow-list: only document / media / archive types, whatever the lesson
    // asks for (a block-list always misses some executable extension).
    $safe  = (array) apply_filters('nias_assign_safe_types', array(
        'pdf', 'zip', 'rar', '7z', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'srt',
        'mp3', 'wav', 'm4a', 'ogg', 'mp4', 'mov', 'avi', 'mkv', 'webm',
        'psd', 'ai', 'aep', 'prproj', 'dwg', 'dxf', 'skp', 'blend', 'fbx', 'obj',
    ));
    $types = array_values(array_intersect(array_unique($types), array_map('strtolower', $safe)));
    if (!$types) {
        $types = array('pdf', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'docx');
    }
    $max   = (int) (isset($lesson['assignMax']) ? $lesson['assignMax'] : 0);
    $limit = (int) floor(wp_max_upload_size() / MB_IN_BYTES);
    return array(
        'text'  => isset($lesson['assignText']) ? (string) $lesson['assignText'] : '',
        'types' => $types,
        'max'   => max(1, min($max > 0 ? $max : 20, max(1, $limit))),
        'pass'  => isset($lesson['assignPass']) && $lesson['assignPass'] !== '' ? max(0, min(100, (int) $lesson['assignPass'])) : 0,
    );
}

/** The assignment of lesson $lesson_id in a course (null = none). */
function nias_assign_lesson($post_id, $lesson_id)
{
    if (!preg_match('/^c(\d+)l(\d+)$/', (string) $lesson_id, $m) || !function_exists('nias_curriculum_sections_to_js')) {
        return null;
    }
    $js = nias_curriculum_sections_to_js((array) carbon_get_post_meta($post_id, 'course_sections'));
    $l  = isset($js[(int) $m[1]]['lessons'][(int) $m[2]]) ? $js[(int) $m[1]]['lessons'][(int) $m[2]] : null;
    if (!$l) {
        return null;
    }
    $cfg = nias_assign_cfg($l);
    if ($cfg) {
        $cfg['title']   = $l['title'];
        $cfg['private'] = !empty($l['private']);
        $cfg['lesson']  = $l;
    }
    return $cfg;
}

/** May this user review the course's assignments? */
function nias_assign_can_review($user_id, $post_id)
{
    return $user_id && (user_can($user_id, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id)));
}

/** May this user send an assignment for the lesson right now? */
function nias_assign_can_submit($user_id, $post_id, $cfg)
{
    if (!$user_id || !$cfg) {
        return false;
    }
    if (!empty($cfg['private']) && function_exists('nias_user_has_course_access') && !nias_user_has_course_access($user_id, $post_id) && !nias_assign_can_review($user_id, $post_id)) {
        return false;
    }
    if (function_exists('nias_drip_enabled') && nias_drip_enabled() && nias_drip_state($user_id, $post_id, nias_drip_cfg_js($cfg['lesson']))) {
        return false;
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Rows
 * ---------------------------------------------------------------------- */

function nias_assign_row($id)
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . nias_assign_table() . ' WHERE id = %d', $id), ARRAY_A);
}

function nias_assign_user_rows($user_id, $post_id, $lesson)
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . nias_assign_table() . ' WHERE user_id = %d AND post_id = %d AND lesson = %s ORDER BY id DESC LIMIT 20', $user_id, $post_id, $lesson), ARRAY_A);
}

function nias_assign_status_label($st)
{
    $l = array('pending' => __('Awaiting grading', 'nias-course-widget'), 'passed' => __('Passed', 'nias-course-widget'), 'failed' => __('Failed', 'nias-course-widget'), 'resubmit' => __('Resubmission needed', 'nias-course-widget'));
    return isset($l[$st]) ? $l[$st] : $st;
}

function nias_assign_date($mysql)
{
    if (!$mysql || strpos($mysql, '0000') === 0) {
        return '';
    }
    $ts = strtotime($mysql);
    $d  = function_exists('nias_cert_jalali') ? nias_cert_jalali(wp_date('Y-m-d', $ts), 'long') : wp_date('Y-m-d', $ts);
    return nias_fa_digits($d . '، ' . wp_date('H:i', $ts));
}

/** Row as sent to a browser (no paths). */
function nias_assign_public($r, $with_user = false)
{
    $out = array(
        'id'       => (int) $r['id'],
        'file'     => $r['file_name'],
        'size'     => size_format((int) $r['file_size'], 1),
        'url'      => nias_assign_file_url((int) $r['id']),
        'note'     => $r['note'],
        'status'   => $r['status'],
        'statusLabel' => nias_assign_status_label($r['status']),
        'score'    => (int) $r['score'] >= 0 ? (int) $r['score'] : null,
        'feedback' => $r['feedback'],
        'date'     => nias_assign_date($r['created']),
        'graded'   => nias_assign_date($r['graded']),
    );
    if ($with_user) {
        $u = get_userdata((int) $r['user_id']);
        $out['user']   = $u ? $u->display_name : '#' . (int) $r['user_id'];
        $out['course'] = get_the_title((int) $r['post_id']);
        $out['lesson'] = $r['lesson_title'];
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Files
 * ---------------------------------------------------------------------- */

function nias_assign_dir($user_id)
{
    $up  = wp_get_upload_dir();
    $dir = wp_normalize_path(trailingslashit($up['basedir']) . 'nias-protected/assignments/' . (int) $user_id);
    if (function_exists('nias_protect_ensure_folder')) {
        nias_protect_ensure_folder(); // the parent folder refuses direct requests
    }
    wp_mkdir_p($dir);
    return $dir;
}

function nias_assign_file_url($id)
{
    return add_query_arg(array('action' => 'nias_assign_file', 'id' => (int) $id, 'n' => wp_create_nonce('nias_assign_file_' . (int) $id)), admin_url('admin-ajax.php'));
}

add_action('wp_ajax_nias_assign_file', function () {
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $r   = $id ? nias_assign_row($id) : null;
    $uid = get_current_user_id();
    if (!$r || !wp_verify_nonce(isset($_GET['n']) ? sanitize_text_field(wp_unslash($_GET['n'])) : '', 'nias_assign_file_' . $id)
        || ((int) $r['user_id'] !== $uid && !nias_assign_can_review($uid, (int) $r['post_id']))) {
        wp_die(esc_html__('You do not have access.', 'nias-course-widget'), 403);
    }
    $up   = wp_get_upload_dir();
    $path = wp_normalize_path(trailingslashit($up['basedir']) . $r['file']);
    if (!is_file($path) || strpos($path, wp_normalize_path($up['basedir']) . '/nias-protected/assignments/') !== 0) {
        wp_die(esc_html__('File not found.', 'nias-course-widget'), 404);
    }
    nocache_headers();
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($r['file_name']));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    exit;
});

/* -------------------------------------------------------------------------
 * Student AJAX
 * ---------------------------------------------------------------------- */

function nias_assign_request($check_review = false)
{
    $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!nias_assign_enabled() || !$pid || !check_ajax_referer('nias_assign_' . $pid, 'nonce', false) || !is_user_logged_in()) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    return $pid;
}

add_action('wp_ajax_nias_assign_list', function () {
    $pid    = nias_assign_request();
    $lesson = isset($_POST['lesson']) ? sanitize_key(wp_unslash($_POST['lesson'])) : '';
    $cfg    = nias_assign_lesson($pid, $lesson);
    if (!$cfg) {
        wp_send_json_error(array('message' => __('This lesson has no assignment.', 'nias-course-widget')));
    }
    $rows = nias_assign_user_rows(get_current_user_id(), $pid, $lesson);
    $last = $rows ? $rows[0]['status'] : '';
    wp_send_json_success(array(
        'items'  => array_map('nias_assign_public', $rows),
        // A new file is welcome unless the last one passed (a pending one is replaced).
        'canSend' => nias_assign_can_submit(get_current_user_id(), $pid, $cfg) && $last !== 'passed',
        'replace' => $last === 'pending',
    ));
});

add_action('wp_ajax_nias_assign_submit', function () {
    global $wpdb;
    $pid    = nias_assign_request();
    $uid    = get_current_user_id();
    $lesson = isset($_POST['lesson']) ? sanitize_key(wp_unslash($_POST['lesson'])) : '';
    $cfg    = nias_assign_lesson($pid, $lesson);
    if (!$cfg || !nias_assign_can_submit($uid, $pid, $cfg)) {
        wp_send_json_error(array('message' => __('Submitting an assignment for this lesson is not possible.', 'nias-course-widget')));
    }
    $rows = nias_assign_user_rows($uid, $pid, $lesson);
    if ($rows && $rows[0]['status'] === 'passed') {
        wp_send_json_error(array('message' => __('This assignment has already been passed.', 'nias-course-widget')));
    }
    // Rate limit: 10 sends per hour per user.
    $rk = 'nias_assign_rl_' . $uid;
    $n  = (int) get_transient($rk);
    if ($n >= 10) {
        wp_send_json_error(array('message' => __('Too many submissions in one hour; try again a bit later.', 'nias-course-widget')));
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        wp_send_json_error(array('message' => __('No file was received.', 'nias-course-widget')));
    }
    $f    = $_FILES['file'];
    $name = sanitize_file_name(wp_unslash($f['name']));
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!$ext || !in_array($ext, $cfg['types'], true)) {
        wp_send_json_error(array('message' => sprintf(__('File type not allowed. Allowed: %s', 'nias-course-widget'), implode('، ', $cfg['types']))));
    }
    if ((int) $f['size'] > $cfg['max'] * MB_IN_BYTES) {
        wp_send_json_error(array('message' => sprintf(__('The file is larger than %s MB.', 'nias-course-widget'), nias_fa_digits($cfg['max']))));
    }
    // Content must match the name: real images, and no script inside any file.
    $head = (string) @file_get_contents($f['tmp_name'], false, null, 0, 4096);
    $bad  = preg_match('/<\?(php|=)|<script\b|<%@|#!\/(usr\/)?bin\//i', $head);
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), true) && !@getimagesize($f['tmp_name'])) {
        $bad = true;
    }
    $check = wp_check_filetype_and_ext($f['tmp_name'], $name);
    if ($bad || !empty($check['proper_filename']) || ($check['ext'] && $check['ext'] !== $ext && !in_array($ext, array('rar', 'zip', '7z'), true))) {
        wp_send_json_error(array('message' => __('The file content does not match its extension or is not allowed.', 'nias-course-widget')));
    }
    $dir    = nias_assign_dir($uid);
    $stored = wp_generate_password(20, false, false) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        wp_send_json_error(array('message' => __('The file could not be saved.', 'nias-course-widget')));
    }
    $rel  = 'nias-protected/assignments/' . $uid . '/' . $stored;
    $note = isset($_POST['note']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['note'])), 0, 2000) : '';
    $now  = current_time('mysql');
    if ($rows && $rows[0]['status'] === 'pending') {
        // Replace the file of the pending one (no pile of unread sends).
        $old = wp_normalize_path(trailingslashit(wp_get_upload_dir()['basedir']) . $rows[0]['file']);
        if (is_file($old)) {
            @unlink($old);
        }
        $wpdb->update(nias_assign_table(), array('file' => $rel, 'file_name' => $name, 'file_size' => (int) $f['size'], 'note' => $note, 'created' => $now), array('id' => (int) $rows[0]['id']));
        $id = (int) $rows[0]['id'];
    } else {
        $wpdb->insert(nias_assign_table(), array('user_id' => $uid, 'post_id' => $pid, 'lesson' => $lesson, 'lesson_title' => mb_substr($cfg['title'], 0, 190), 'file' => $rel, 'file_name' => $name, 'file_size' => (int) $f['size'], 'note' => $note, 'status' => 'pending', 'score' => -1, 'feedback' => '', 'created' => $now));
        $id = (int) $wpdb->insert_id;
    }
    set_transient($rk, $n + 1, HOUR_IN_SECONDS);
    $row = nias_assign_row($id);
    do_action('nias_assign_submitted', $row);
    wp_send_json_success(array('item' => nias_assign_public($row)));
});

/* -------------------------------------------------------------------------
 * Review
 * ---------------------------------------------------------------------- */

/** Course ids this user reviews (null = all, for admins). */
function nias_assign_scope($user_id)
{
    if (user_can($user_id, 'manage_options')) {
        return null;
    }
    return function_exists('nias_inst_course_ids') ? array_map('intval', nias_inst_course_ids($user_id)) : array();
}

function nias_assign_pending_count($scope)
{
    global $wpdb;
    if (!nias_assign_enabled()) {
        return 0;
    }
    if (is_array($scope)) {
        if (!$scope) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . nias_assign_table() . " WHERE status = 'pending' AND post_id IN (" . implode(',', array_map('intval', $scope)) . ')');
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . nias_assign_table() . " WHERE status = 'pending'");
}

add_action('wp_ajax_nias_assign_queue', function () {
    global $wpdb;
    if (!check_ajax_referer('nias_assign_review', 'nonce', false) || !nias_assign_enabled()) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    $uid   = get_current_user_id();
    $scope = nias_assign_scope($uid);
    $st    = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'pending';
    $page  = max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
    $where = $st === 'all' ? '1=1' : $wpdb->prepare('status = %s', $st);
    if (is_array($scope)) {
        if (!$scope) {
            wp_send_json_success(array('items' => array(), 'more' => false));
        }
        $where .= ' AND post_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
    $rows = $wpdb->get_results('SELECT * FROM ' . nias_assign_table() . " WHERE $where ORDER BY " . ($st === 'pending' ? 'created ASC' : 'id DESC') . ' LIMIT ' . (($page - 1) * 20) . ', 21', ARRAY_A);
    $more = count($rows) > 20;
    wp_send_json_success(array('items' => array_map(function ($r) { return nias_assign_public($r, true); }, array_slice($rows, 0, 20)), 'more' => $more));
});

add_action('wp_ajax_nias_assign_grade', function () {
    global $wpdb;
    if (!check_ajax_referer('nias_assign_review', 'nonce', false) || !nias_assign_enabled()) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $r   = $id ? nias_assign_row($id) : null;
    $uid = get_current_user_id();
    if (!$r || !nias_assign_can_review($uid, (int) $r['post_id'])) {
        wp_send_json_error(array('message' => __('You do not have access.', 'nias-course-widget')), 403);
    }
    $score    = isset($_POST['score']) && $_POST['score'] !== '' ? max(0, min(100, (int) $_POST['score'])) : -1;
    $feedback = isset($_POST['feedback']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['feedback'])), 0, 4000) : '';
    $op       = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'grade';
    if ($op === 'resubmit') {
        $status = 'resubmit';
    } else {
        if ($score < 0) {
            wp_send_json_error(array('message' => __('Enter the score.', 'nias-course-widget')));
        }
        $cfg    = nias_assign_lesson((int) $r['post_id'], $r['lesson']);
        $pass   = $cfg ? (int) $cfg['pass'] : 0;
        $status = $score >= $pass ? 'passed' : 'failed';
    }
    $wpdb->update(nias_assign_table(), array('status' => $status, 'score' => $score, 'feedback' => $feedback, 'grader' => $uid, 'graded' => current_time('mysql')), array('id' => $id));
    $row = nias_assign_row($id);
    do_action('nias_assign_graded', $row, $uid);
    wp_send_json_success(array('item' => nias_assign_public($row, true)));
});

/* Notifications ----------------------------------------------------------- */

add_action('nias_assign_graded', function ($row) {
    $u = get_userdata((int) $row['user_id']);
    if (!$u || !is_email($u->user_email)) {
        return;
    }
    $course = get_the_title((int) $row['post_id']);
    $link   = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url((int) $row['post_id']) : get_permalink((int) $row['post_id']);
    $state  = nias_assign_status_label($row['status']) . ((int) $row['score'] >= 0 ? ' — ' . sprintf(__('Score: %s', 'nias-course-widget'), nias_fa_digits($row['score'])) : '');
    $body   = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:2;color:#1f2a30">'
        . '<p>' . esc_html(sprintf(__('Dear %s,', 'nias-course-widget'), $u->display_name)) . '</p>'
        . '<p>' . esc_html(sprintf(__('The assignment of the lesson "%1$s" in the course "%2$s" was reviewed: %3$s', 'nias-course-widget'), $row['lesson_title'], $course, $state)) . '</p>'
        . ($row['feedback'] !== '' ? '<p style="background:#f5f8ff;border-radius:10px;padding:10px 14px">' . nl2br(esc_html($row['feedback'])) . '</p>' : '')
        . '<p><a href="' . esc_url($link) . '" style="display:inline-block;background:#1e83f0;color:#fff;text-decoration:none;padding:8px 18px;border-radius:9px">' . esc_html__('View course', 'nias-course-widget') . '</a></p></div>';
    wp_mail($u->user_email, sprintf(__('Assignment result "%s"', 'nias-course-widget'), $row['lesson_title']), $body, array('Content-Type: text/html; charset=UTF-8'));
}, 10, 1);

/* -------------------------------------------------------------------------
 * Course page data
 * ---------------------------------------------------------------------- */

add_filter('nias_modern_assign_client', function ($v, $post_id) {
    if (!nias_assign_enabled() || !is_user_logged_in()) {
        return null;
    }
    return array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('nias_assign_' . (int) $post_id));
}, 10, 2);

/* -------------------------------------------------------------------------
 * Review screen (admin tab + instructor panel)
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!nias_assign_enabled()) {
        return;
    }
    $n = nias_assign_pending_count(null);
    add_submenu_page('nias-course-settings', __('Assignments', 'nias-course-widget'), __('Assignments', 'nias-course-widget') . ($n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : ''), 'manage_options', 'nias-course-assignments', function () {
        echo '<div class="nias-settings-app" dir="rtl">';
        nias_settings_topbar('assign');
        echo '<div class="nias-set-shell"><div class="nias-set-main">' . nias_assign_review_html() . '</div></div></div>'; // phpcs:ignore -- escaped inside
    });
}, 40);

function nias_assign_review_html()
{
    $nonce = wp_create_nonce('nias_assign_review');
    ob_start();
    ?>
    <div class="nas" data-nas data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" dir="rtl">
        <style>
        .nas{font-size:14px}
        .nas-h{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
        .nas-h h2{margin:0;font-size:18px}
        .nas-f{display:flex;gap:6px;flex-wrap:wrap}
        .nas-f button{border:1px solid #dbe3ef;background:#fff;border-radius:99px;padding:5px 12px;font:inherit;font-weight:700;cursor:pointer;color:#475569}
        .nas-f button.on{background:#3858e9;border-color:#3858e9;color:#fff}
        .nas-list{display:flex;flex-direction:column;gap:12px}
        .nas-it{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:14px 16px}
        .nas-top{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center}
        .nas-top b{font-size:15px}
        .nas-meta{color:#64748b;font-size:12.5px}
        .nas-st{font-size:12px;font-weight:700;border-radius:99px;padding:2px 10px;background:#eef5ff;color:#1e4fb8}
        .nas-st.passed{background:#e7f7ec;color:#137a3a}.nas-st.failed{background:#fdeeee;color:#b42318}.nas-st.resubmit{background:#fff4e5;color:#b45309}
        .nas-file{display:inline-flex;align-items:center;gap:6px;margin:10px 0 6px;font-weight:700;text-decoration:none;color:#3858e9}
        .nas-note{background:#f8fafc;border-radius:10px;padding:8px 12px;white-space:pre-wrap;margin:6px 0;color:#334155}
        .nas-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;margin-top:8px}
        .nas-form input{width:90px;border:1px solid #dbe3ef;border-radius:9px;padding:7px 10px;font:inherit}
        .nas-form textarea{flex:1 1 280px;min-height:42px;border:1px solid #dbe3ef;border-radius:9px;padding:7px 10px;font:inherit}
        .nas-form button{border:1px solid #dbe3ef;background:#fff;border-radius:9px;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer}
        .nas-form button.pri{background:#3858e9;border-color:#3858e9;color:#fff}
        .nas-empty{color:#64748b;padding:20px;text-align:center;background:#fff;border:1px dashed #dbe3ef;border-radius:14px}
        .nas-more{align-self:center;border:1px solid #dbe3ef;background:#fff;border-radius:9px;padding:8px 16px;font:inherit;cursor:pointer}
        </style>
        <div class="nas-h"><h2><?php echo esc_html__('Student assignments', 'nias-course-widget'); ?></h2>
            <div class="nas-f"><button type="button" class="on" data-f="pending"><?php echo esc_html__('Awaiting grading', 'nias-course-widget'); ?></button><button type="button" data-f="passed"><?php echo esc_html__('Passed', 'nias-course-widget'); ?></button><button type="button" data-f="failed"><?php echo esc_html__('Failed', 'nias-course-widget'); ?></button><button type="button" data-f="resubmit"><?php echo esc_html__('Resubmit', 'nias-course-widget'); ?></button><button type="button" data-f="all"><?php echo esc_html__('All', 'nias-course-widget'); ?></button></div>
        </div>
        <div class="nas-list" data-nas-list></div>
    </div>
    <script>
    (function () {
        var root = document.querySelector('[data-nas]'); if (!root || root._b) return; root._b = 1;
        var list = root.querySelector('[data-nas-list]'), st = 'pending', page = 1;
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
        function post(d) { var b = new URLSearchParams(); b.append('nonce', root.getAttribute('data-nonce')); Object.keys(d).forEach(function (k) { b.append(k, d[k]); }); return fetch(root.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }); }
        function card(it) {
            return '<div class="nas-it" data-id="' + it.id + '"><div class="nas-top"><b>' + esc(it.user) + '</b><span class="nas-meta">' + esc(it.course) + ' › ' + esc(it.lesson) + '</span><span class="nas-st ' + esc(it.status) + '">' + esc(it.statusLabel) + (it.score != null ? ' · ' + fa(it.score) : '') + '</span><span class="nas-meta">' + esc(it.date) + '</span></div>' +
                '<a class="nas-file" href="' + esc(it.url) + '">⬇ ' + esc(it.file) + ' <span class="nas-meta">(' + esc(it.size) + ')</span></a>' +
                (it.note ? '<div class="nas-note">' + esc(it.note) + '</div>' : '') +
                '<div class="nas-form"><input type="number" min="0" max="100" placeholder="نمره" value="' + (it.score != null ? it.score : '') + '" data-score><textarea placeholder="بازخورد به دانشجو (اختیاری)" data-fb>' + esc(it.feedback || '') + '</textarea>' +
                '<button type="button" class="pri" data-op="grade">ثبت نمره</button><button type="button" data-op="resubmit">نیاز به ارسال دوباره</button></div></div>';
        }
        function load(reset) {
            if (reset) { page = 1; list.innerHTML = '<div class="nas-empty">در حال بارگذاری…</div>'; }
            post({ action: 'nias_assign_queue', status: st, page: page }).then(function (r) {
                if (reset) list.innerHTML = '';
                var old = list.querySelector('.nas-more'); if (old) old.remove();
                var items = (r && r.success && r.data.items) || [];
                if (!items.length && page === 1) { list.innerHTML = '<div class="nas-empty">' + (st === 'pending' ? 'تکلیفی در انتظار تصحیح نیست.' : 'موردی نیست.') + '</div>'; return; }
                list.insertAdjacentHTML('beforeend', items.map(card).join(''));
                if (r.data.more) { list.insertAdjacentHTML('beforeend', '<button type="button" class="nas-more">بیشتر</button>'); }
            });
        }
        root.addEventListener('click', function (e) {
            var f = e.target.closest('[data-f]'); if (f) { root.querySelectorAll('[data-f]').forEach(function (b) { b.classList.toggle('on', b === f); }); st = f.getAttribute('data-f'); load(true); return; }
            if (e.target.closest('.nas-more')) { page++; load(false); return; }
            var b = e.target.closest('[data-op]'); if (!b) return;
            var it = b.closest('.nas-it'); b.disabled = true;
            post({ action: 'nias_assign_grade', id: it.getAttribute('data-id'), op: b.getAttribute('data-op'), score: it.querySelector('[data-score]').value, feedback: it.querySelector('[data-fb]').value }).then(function (r) {
                if (r && r.success) { it.outerHTML = card(r.data.item); } else { b.disabled = false; alert((r && r.data && r.data.message) || 'خطا'); }
            });
        });
        load(true);
    })();
    </script>
    <?php
    return ob_get_clean();
}
