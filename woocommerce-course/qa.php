<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Course Q&A (پرسش و پاسخ) — optional, switched on in the main settings.
 *
 *   - Students ask under a lesson or about the course in general; the course's
 *     instructors (and site admins) answer; the asker can follow up and mark
 *     the question as resolved.
 *   - Its own table ({prefix}nias_qa), not WordPress comments: comments would
 *     mix into WooCommerce reviews / comment counts of the product.
 *   - Settings: scope (lessons + general / general only / lessons only), who can
 *     ask (course access / any logged-in user), who can read (everyone / course
 *     access), approval before publishing, e-mail notifications.
 *   - Management: «پرسش و پاسخ» in wp-admin (all courses) and inside the
 *     instructor dashboard (instructor's own courses): unanswered queue,
 *     approval queue, answer inline, hide / delete.
 *
 * Lessons are referenced by their position key (c{chapter}l{lesson}) like the
 * rest of the course data, plus a title snapshot for display.
 *
 * @package nias-course-widget
 */

define('NIAS_QA_DB_VERSION', '1');
if (!defined('NIAS_QA_PER_PAGE')) {
    define('NIAS_QA_PER_PAGE', 10);
}

function nias_qa_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_qa';
}

add_action('plugins_loaded', function () {
    if (get_option('nias_qa_db_version') === NIAS_QA_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = nias_qa_table();
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        lesson varchar(40) NOT NULL DEFAULT '',
        lesson_title varchar(190) NOT NULL DEFAULT '',
        parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL,
        content text NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'approved',
        is_staff tinyint(1) unsigned NOT NULL DEFAULT 0,
        resolved tinyint(1) unsigned NOT NULL DEFAULT 0,
        replies int(11) unsigned NOT NULL DEFAULT 0,
        staff_replied tinyint(1) unsigned NOT NULL DEFAULT 0,
        created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        last_activity datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        KEY post_lesson (post_id,parent_id,status,lesson,created),
        KEY parent (parent_id,created),
        KEY queue (parent_id,staff_replied,status,last_activity),
        KEY user_q (user_id,parent_id,created)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('nias_qa_db_version', NIAS_QA_DB_VERSION, false);
}, 21);

/* -------------------------------------------------------------------------
 * Settings + permissions
 * ---------------------------------------------------------------------- */

function nias_qa_enabled()
{
    return function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_qa_enabled') === 'on' && get_option('nias_qa_db_version') === NIAS_QA_DB_VERSION;
}

/** Setting with default. */
function nias_qa_opt($key, $default)
{
    $v = carbon_get_theme_option($key);
    return ($v === '' || $v === false || $v === null) ? $default : $v;
}

/** both | general | lessons */
function nias_qa_scope()
{
    $v = nias_qa_opt('nias_qa_scope', 'both');
    return in_array($v, array('both', 'general', 'lessons'), true) ? $v : 'both';
}

/** Instructors of the course, or a site admin. */
function nias_qa_is_staff($user_id, $post_id)
{
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }
    if (user_can($user_id, 'manage_options')) {
        return true;
    }
    return function_exists('nias_product_instructors') && in_array($user_id, nias_product_instructors((int) $post_id), true);
}

function nias_qa_has_access($user_id, $post_id)
{
    return function_exists('nias_user_has_course_access') ? nias_user_has_course_access($user_id, $post_id) : false;
}

function nias_qa_can_view($user_id, $post_id)
{
    if (nias_qa_opt('nias_qa_view', 'all') === 'all') {
        return true;
    }
    return nias_qa_is_staff($user_id, $post_id) || nias_qa_has_access($user_id, $post_id);
}

function nias_qa_can_ask($user_id, $post_id)
{
    if (!$user_id) {
        return false;
    }
    if (nias_qa_is_staff($user_id, $post_id)) {
        return true;
    }
    return nias_qa_opt('nias_qa_ask', 'access') === 'logged' ? true : nias_qa_has_access($user_id, $post_id);
}

/**
 * Course ids a user manages in Q&A: null = all (admin), [] = none.
 *
 * @return int[]|null
 */
function nias_qa_staff_scope($user_id)
{
    if (user_can($user_id, 'manage_options')) {
        return null;
    }
    if (!function_exists('nias_user_is_instructor') || !nias_user_is_instructor($user_id)) {
        return array();
    }
    return array_map(function ($p) {
        return (int) $p->ID;
    }, nias_get_instructor_courses($user_id));
}

/* -------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------- */

function nias_qa_get($id)
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . nias_qa_table() . ' WHERE id = %d', $id), ARRAY_A);
    return $r ? $r : null;
}

/** Published question counts per lesson ('' = general) for a course. */
function nias_qa_counts($post_id)
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT lesson, COUNT(*) n FROM ' . nias_qa_table() . " WHERE post_id = %d AND parent_id = 0 AND status = 'approved' GROUP BY lesson",
        $post_id
    ));
    $out = array();
    foreach ((array) $rows as $r) {
        $out[$r->lesson] = (int) $r->n;
    }
    return $out;
}

/** Date label: relative for recent, Jalali date otherwise. */
function nias_qa_when($mysql)
{
    $ts = strtotime($mysql);
    if (!$ts) {
        return '';
    }
    $diff = current_time('timestamp') - $ts;
    if ($diff < DAY_IN_SECONDS * 7) {
        return sprintf(__('%s ago', 'nias-course-widget'), human_time_diff($ts, current_time('timestamp')));
    }
    return function_exists('nias_quiz_date_label') ? nias_quiz_date_label(substr($mysql, 0, 10)) : substr($mysql, 0, 10);
}

/** Client shape of one row. */
function nias_qa_item($r, $viewer, $staff_ids = null)
{
    $u = get_userdata((int) $r['user_id']);
    $avatar = $u ? get_avatar_url($u->ID, array('size' => 64)) : '';
    return array(
        'id'          => (int) $r['id'],
        'lesson'      => $r['lesson'],
        'lessonTitle' => $r['lesson_title'],
        'name'        => $u ? $u->display_name : __('User', 'nias-course-widget'),
        'avatar'      => get_option('show_avatars') ? $avatar : '',
        'staff'       => (bool) $r['is_staff'],
        'mine'        => $viewer && (int) $r['user_id'] === (int) $viewer,
        'content'     => $r['content'],
        'when'        => nias_qa_when($r['created']),
        'pending'     => $r['status'] === 'pending',
        'resolved'    => (bool) $r['resolved'],
        'replyCount'  => (int) $r['replies'],
    );
}

/**
 * One page of questions with their answers.
 *
 * @param array $a scope lesson|general|course|mine, lesson, page, viewer
 * @return array { items, total, page, pages }
 */
function nias_qa_list($post_id, $a)
{
    global $wpdb;
    $t      = nias_qa_table();
    $viewer = (int) $a['viewer'];
    $page   = max(1, (int) $a['page']);
    $staff  = nias_qa_is_staff($viewer, $post_id);
    $where  = $wpdb->prepare('WHERE post_id = %d AND parent_id = 0', $post_id);
    // Published, plus the viewer's own pending ones (staff see everything).
    if (!$staff) {
        $where .= $viewer ? $wpdb->prepare(" AND (status = 'approved' OR user_id = %d)", $viewer) : " AND status = 'approved'";
    } else {
        $where .= " AND status IN ('approved','pending')";
    }
    if ($a['scope'] === 'lesson') {
        $where .= $wpdb->prepare(' AND lesson = %s', (string) $a['lesson']);
    } elseif ($a['scope'] === 'general') {
        $where .= " AND lesson = ''";
    } elseif ($a['scope'] === 'mine') {
        $where .= $wpdb->prepare(' AND user_id = %d', $viewer);
    } elseif ($a['scope'] === 'unanswered' && $staff) {
        $where .= " AND staff_replied = 0 AND resolved = 0";
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} {$where}");
    $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} {$where} ORDER BY last_activity DESC LIMIT %d OFFSET %d", NIAS_QA_PER_PAGE, ($page - 1) * NIAS_QA_PER_PAGE), ARRAY_A);
    $items = array();
    if ($rows) {
        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $rw  = $staff ? "status IN ('approved','pending')" : ($viewer ? $wpdb->prepare("(status = 'approved' OR user_id = %d)", $viewer) : "status = 'approved'");
        $reps = $wpdb->get_results("SELECT * FROM {$t} WHERE parent_id IN (" . implode(',', $ids) . ") AND {$rw} ORDER BY created ASC", ARRAY_A);
        cache_users(array_unique(array_merge(wp_list_pluck($rows, 'user_id'), wp_list_pluck((array) $reps, 'user_id'))));
        $by = array();
        foreach ((array) $reps as $r) {
            $by[(int) $r['parent_id']][] = nias_qa_item($r, $viewer);
        }
        foreach ($rows as $r) {
            $it = nias_qa_item($r, $viewer);
            $it['answers'] = isset($by[(int) $r['id']]) ? $by[(int) $r['id']] : array();
            $it['canReply'] = $viewer && ($staff || (int) $r['user_id'] === $viewer) && $r['status'] === 'approved';
            $it['canResolve'] = $viewer && ($staff || (int) $r['user_id'] === $viewer);
            $it['canDelete'] = $viewer && ($staff || ((int) $r['user_id'] === $viewer && (int) $r['replies'] === 0));
            $items[] = $it;
        }
    }
    return array('items' => $items, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / NIAS_QA_PER_PAGE));
}

/** Clean user text (plain text, max 3000 chars). */
function nias_qa_clean($text)
{
    $text = trim(sanitize_textarea_field((string) $text));
    return function_exists('mb_substr') ? mb_substr($text, 0, 3000) : substr($text, 0, 3000);
}

/**
 * Ask a question.
 *
 * @return array|WP_Error the new row
 */
function nias_qa_ask($user_id, $post_id, $lesson, $content)
{
    global $wpdb;
    if (!nias_qa_can_ask($user_id, $post_id)) {
        return new WP_Error('nias_qa', __('You are not allowed to ask questions in this course.', 'nias-course-widget'));
    }
    $content = nias_qa_clean($content);
    if (function_exists('mb_strlen') ? mb_strlen($content) < 5 : strlen($content) < 5) {
        return new WP_Error('nias_qa', __('The question text is too short.', 'nias-course-widget'));
    }
    $scope = nias_qa_scope();
    $lessons = function_exists('nias_watch_lessons') ? nias_watch_lessons($post_id) : array();
    $lesson = (string) $lesson;
    if ($lesson !== '' && !isset($lessons[$lesson])) {
        $lesson = '';
    }
    if ($scope === 'general') {
        $lesson = '';
    } elseif ($scope === 'lessons' && $lesson === '') {
        return new WP_Error('nias_qa', __('Post the question under a lesson.', 'nias-course-widget'));
    }
    $staff = nias_qa_is_staff($user_id, $post_id);
    // Flood guard: 10 questions per hour per user.
    if (!$staff) {
        $recent = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . nias_qa_table() . ' WHERE user_id = %d AND created > %s', $user_id, gmdate('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS)));
        if ($recent >= (int) apply_filters('nias_qa_hourly_limit', 10)) {
            return new WP_Error('nias_qa', __('You have asked too many questions in the last hour; try again a bit later.', 'nias-course-widget'));
        }
    }
    $now = current_time('mysql');
    $wpdb->insert(nias_qa_table(), array(
        'post_id' => (int) $post_id, 'lesson' => $lesson, 'lesson_title' => $lesson !== '' ? mb_substr((string) $lessons[$lesson], 0, 190) : '',
        'parent_id' => 0, 'user_id' => (int) $user_id, 'content' => $content,
        'status' => (!$staff && nias_qa_opt('nias_qa_moderation', 'off') === 'on') ? 'pending' : 'approved',
        'is_staff' => $staff ? 1 : 0, 'created' => $now, 'last_activity' => $now,
    ));
    $row = nias_qa_get($wpdb->insert_id);
    do_action('nias_qa_question', $row);
    return $row;
}

/** Answer (staff) or follow-up (the asker). */
function nias_qa_reply($user_id, $question_id, $content)
{
    global $wpdb;
    $q = nias_qa_get($question_id);
    if (!$q || (int) $q['parent_id'] !== 0) {
        return new WP_Error('nias_qa', __('Question not found.', 'nias-course-widget'));
    }
    $staff = nias_qa_is_staff($user_id, $q['post_id']);
    if (!$staff && (int) $q['user_id'] !== (int) $user_id) {
        return new WP_Error('nias_qa', __('Only the course instructor or the person who asked can reply.', 'nias-course-widget'));
    }
    $content = nias_qa_clean($content);
    if ($content === '') {
        return new WP_Error('nias_qa', __('The reply text is empty.', 'nias-course-widget'));
    }
    $now = current_time('mysql');
    $wpdb->insert(nias_qa_table(), array(
        'post_id' => (int) $q['post_id'], 'lesson' => $q['lesson'], 'lesson_title' => $q['lesson_title'], 'parent_id' => (int) $q['id'],
        'user_id' => (int) $user_id, 'content' => $content, 'status' => 'approved', 'is_staff' => $staff ? 1 : 0, 'created' => $now, 'last_activity' => $now,
    ));
    $rid = $wpdb->insert_id;
    $upd = array('replies' => (int) $q['replies'] + 1, 'last_activity' => $now);
    if ($staff) {
        $upd['staff_replied'] = 1;
        if ($q['status'] === 'pending') {
            $upd['status'] = 'approved'; // answering publishes it
        }
    } else {
        $upd['staff_replied'] = 0; // the asker followed up: back in the queue
    }
    $wpdb->update(nias_qa_table(), $upd, array('id' => (int) $q['id']));
    $reply = nias_qa_get($rid);
    do_action('nias_qa_reply', $reply, $q, $staff);
    return $reply;
}

/** Delete a question (with its answers) or an answer. */
function nias_qa_delete($user_id, $id)
{
    global $wpdb;
    $r = nias_qa_get($id);
    if (!$r) {
        return false;
    }
    $staff = nias_qa_is_staff($user_id, $r['post_id']);
    $own   = (int) $r['user_id'] === (int) $user_id;
    if (!$staff && !($own && ((int) $r['parent_id'] > 0 || (int) $r['replies'] === 0))) {
        return false;
    }
    $t = nias_qa_table();
    if ((int) $r['parent_id'] === 0) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE id = %d OR parent_id = %d", $r['id'], $r['id']));
    } else {
        $wpdb->delete($t, array('id' => (int) $r['id']));
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE parent_id = %d", $r['parent_id']));
        $st   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE parent_id = %d AND is_staff = 1", $r['parent_id']));
        $wpdb->update($t, array('replies' => $left, 'staff_replied' => $st ? 1 : 0), array('id' => (int) $r['parent_id']));
    }
    return true;
}

/** approve | hide (back to pending) | resolve | unresolve */
function nias_qa_set_state($user_id, $id, $state)
{
    global $wpdb;
    $r = nias_qa_get($id);
    if (!$r || (int) $r['parent_id'] !== 0) {
        return false;
    }
    $staff = nias_qa_is_staff($user_id, $r['post_id']);
    $own   = (int) $r['user_id'] === (int) $user_id;
    if (in_array($state, array('approve', 'hide'), true) && !$staff) {
        return false;
    }
    if (in_array($state, array('resolve', 'unresolve'), true) && !$staff && !$own) {
        return false;
    }
    $map = array('approve' => array('status' => 'approved'), 'hide' => array('status' => 'pending'), 'resolve' => array('resolved' => 1), 'unresolve' => array('resolved' => 0));
    $wpdb->update(nias_qa_table(), $map[$state], array('id' => (int) $r['id']));
    if ($state === 'approve' && $r['status'] === 'pending') {
        do_action('nias_qa_approved', nias_qa_get($id));
    }
    return true;
}

/* -------------------------------------------------------------------------
 * AJAX (front-end course view)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_qa', 'nias_qa_ajax');
add_action('wp_ajax_nopriv_nias_qa', 'nias_qa_ajax');
function nias_qa_ajax()
{
    $pid = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!nias_qa_enabled() || !$pid || !nias_is_course_post($pid) || !check_ajax_referer('nias_qa_' . $pid, 'nonce', false)) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    $uid = get_current_user_id();
    $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'list';
    if (!nias_qa_can_view($uid, $pid)) {
        wp_send_json_error(array('message' => __('This course\'s Q&A is for course participants.', 'nias-course-widget')));
    }
    $text = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($op !== 'list' && !$uid) {
        wp_send_json_error(array('message' => __('Log in to your account first.', 'nias-course-widget')));
    }
    if ($op === 'ask') {
        $r = nias_qa_ask($uid, $pid, isset($_POST['lesson']) ? sanitize_text_field(wp_unslash($_POST['lesson'])) : '', $text);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('pending' => $r['status'] === 'pending', 'counts' => nias_qa_counts($pid)));
    }
    if ($op === 'reply') {
        $q = nias_qa_get($id);
        if (!$q || (int) $q['post_id'] !== $pid) {
            wp_send_json_error(array('message' => __('Question not found.', 'nias-course-widget')));
        }
        $r = nias_qa_reply($uid, $id, $text);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('ok' => true));
    }
    if (in_array($op, array('delete', 'resolve', 'unresolve', 'approve', 'hide'), true)) {
        $r = nias_qa_get($id);
        $ok = $r && (int) $r['post_id'] === $pid && ($op === 'delete' ? nias_qa_delete($uid, $id) : nias_qa_set_state($uid, $id, $op));
        $ok ? wp_send_json_success(array('counts' => nias_qa_counts($pid))) : wp_send_json_error(array('message' => __('You are not allowed to do this.', 'nias-course-widget')));
    }
    $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'course';
    wp_send_json_success(nias_qa_list($pid, array(
        'scope'  => in_array($scope, array('lesson', 'general', 'course', 'mine', 'unanswered'), true) ? $scope : 'course',
        'lesson' => isset($_POST['lesson']) ? sanitize_text_field(wp_unslash($_POST['lesson'])) : '',
        'page'   => isset($_POST['page']) ? (int) $_POST['page'] : 1,
        'viewer' => $uid,
    )));
}

/** Data handed to the modern course view. */
function nias_qa_client_data($post_id)
{
    if (!nias_qa_enabled()) {
        return null;
    }
    $uid = get_current_user_id();
    return array(
        'scope'    => nias_qa_scope(),
        'canView'  => nias_qa_can_view($uid, $post_id),
        'canAsk'   => nias_qa_can_ask($uid, $post_id),
        'isStaff'  => nias_qa_is_staff($uid, $post_id),
        'loggedIn' => (bool) $uid,
        'approval' => nias_qa_opt('nias_qa_moderation', 'off') === 'on',
        'counts'   => (object) nias_qa_counts($post_id),
        'url'      => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('nias_qa_' . (int) $post_id),
        'loginUrl' => wp_login_url(get_permalink($post_id)),
    );
}

/* -------------------------------------------------------------------------
 * E-mail notifications
 * ---------------------------------------------------------------------- */

function nias_qa_notify_on()
{
    return nias_qa_opt('nias_qa_notify', 'on') === 'on';
}

/** Course link pointing at the lesson. */
function nias_qa_course_link($row)
{
    $url = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url((int) $row['post_id']) : get_permalink((int) $row['post_id']);
    return $url;
}

add_action('nias_qa_question', function ($q) {
    if (!$q || !nias_qa_notify_on() || $q['is_staff']) {
        return;
    }
    $to = array();
    foreach ((array) nias_product_instructors((int) $q['post_id']) as $iid) {
        $u = get_userdata($iid);
        if ($u && $u->user_email) {
            $to[] = $u->user_email;
        }
    }
    if (!$to) {
        $to[] = get_option('admin_email');
    }
    $asker = get_userdata((int) $q['user_id']);
    $course = get_the_title((int) $q['post_id']);
    wp_mail($to, sprintf(__('New question in the course "%s"', 'nias-course-widget'), $course),
        sprintf(__("%1\$s asked in the course \"%2\$s\"%3\$s:\n\n%4\$s\n\n%5\$sReply: %6\$s", 'nias-course-widget'),
            $asker ? $asker->display_name : '—', $course, $q['lesson_title'] !== '' ? ' (' . $q['lesson_title'] . ')' : '', $q['content'],
            $q['status'] === 'pending' ? __("This question is awaiting approval.\n", 'nias-course-widget') : '', admin_url('admin.php?page=nias-course-qa')));
});

add_action('nias_qa_reply', function ($reply, $q, $staff) {
    if (!nias_qa_notify_on() || !$staff || (int) $reply['user_id'] === (int) $q['user_id']) {
        return;
    }
    $asker = get_userdata((int) $q['user_id']);
    if (!$asker || !$asker->user_email) {
        return;
    }
    $course = get_the_title((int) $q['post_id']);
    wp_mail($asker->user_email, sprintf(__('Reply to your question in the course "%s"', 'nias-course-widget'), $course),
        sprintf(__("Dear %1\$s,\n\nThe instructor replied to your question:\n\n\"%2\$s\"\n\n%3\$s\n\nView in the course: %4\$s\n\n%5\$s", 'nias-course-widget'),
            $asker->display_name, $q['content'], $reply['content'], nias_qa_course_link($q), get_bloginfo('name')));
}, 10, 3);

/* -------------------------------------------------------------------------
 * Management (wp-admin + instructor dashboard)
 * ---------------------------------------------------------------------- */

/** Unanswered published questions in a scope (for badges). */
function nias_qa_unanswered_count($scope)
{
    global $wpdb;
    if (!nias_qa_enabled()) {
        return 0;
    }
    $in = $scope === null ? '' : ($scope ? ' AND post_id IN (' . implode(',', array_map('intval', $scope)) . ')' : ' AND 1=0');
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . nias_qa_table() . " WHERE parent_id = 0 AND staff_replied = 0 AND resolved = 0 AND status IN ('approved','pending'){$in}");
}

add_action('admin_menu', function () {
    if (!current_user_can('manage_options') || !nias_qa_enabled()) {
        return;
    }
    $n = nias_qa_unanswered_count(null);
    add_submenu_page('nias-course-settings', __('Q&A', 'nias-course-widget'), __('Q&A', 'nias-course-widget') . ($n ? ' <span class="awaiting-mod">' . nias_fa_digits($n) . '</span>' : ''), 'manage_options', 'nias-course-qa', function () {
        echo '<div class="nias-settings-app" dir="rtl">';
        if (function_exists('nias_settings_topbar')) {
            nias_settings_topbar('qa');
        }
        echo '<div class="nias-set-shell"><div class="nias-set-main">';
        echo nias_qa_manage_render(admin_url('admin.php?page=nias-course-qa'), null); // phpcs:ignore -- escaped inside
        echo '</div></div></div>';
    });
}, 22);

/** Form actions from the management screen (admin-post.php). */
add_action('admin_post_nias_qa_manage', function () {
    $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    $uid = get_current_user_id();
    if (!$uid || !wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'nias_qa_manage_' . $id)) {
        wp_die(esc_html__('The request has expired.', 'nias-course-widget'), '', array('response' => 403, 'back_link' => true));
    }
    $r = nias_qa_get($id);
    $ok = false;
    if ($r && nias_qa_is_staff($uid, $r['post_id'])) {
        if ($op === 'reply') {
            $ok = !is_wp_error(nias_qa_reply($uid, $id, isset($_POST['content']) ? wp_unslash($_POST['content']) : ''));
        } elseif ($op === 'delete') {
            $ok = nias_qa_delete($uid, $id);
        } elseif (in_array($op, array('approve', 'hide', 'resolve', 'unresolve'), true)) {
            $ok = nias_qa_set_state($uid, $id, $op);
        }
    }
    $back = wp_validate_redirect(isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '', admin_url('admin.php?page=nias-course-qa'));
    wp_safe_redirect(add_query_arg('qa_notice', $ok ? $op : 'error', $back) . '#qa-' . (int) ($r ? ((int) $r['parent_id'] ?: $r['id']) : 0));
    exit;
});

/**
 * Management list: tabs (بی‌پاسخ / منتظر تأیید / همه), course + text filter, paging.
 *
 * @param string     $base  URL of this view
 * @param int[]|null $scope course ids (null = all)
 */
function nias_qa_manage_render($base, $scope)
{
    global $wpdb;
    $t    = nias_qa_table();
    $tab  = isset($_GET['qt']) ? sanitize_key(wp_unslash($_GET['qt'])) : 'open';
    $cid  = isset($_GET['qc']) ? (int) $_GET['qc'] : 0;
    $s    = isset($_GET['qs']) ? sanitize_text_field(wp_unslash($_GET['qs'])) : '';
    $page = isset($_GET['qp']) ? max(1, (int) $_GET['qp']) : 1;
    $per  = 20;
    if ($cid && $scope !== null && !in_array($cid, $scope, true)) {
        $cid = 0;
    }
    $where = 'WHERE parent_id = 0';
    $where .= $scope === null ? '' : ($scope ? ' AND post_id IN (' . implode(',', array_map('intval', $scope)) . ')' : ' AND 1=0');
    if ($cid) {
        $where .= $wpdb->prepare(' AND post_id = %d', $cid);
    }
    if ($s !== '') {
        $where .= $wpdb->prepare(' AND content LIKE %s', '%' . $wpdb->esc_like($s) . '%');
    }
    $count = function ($extra) use ($wpdb, $t, $where) {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} {$where}{$extra}");
    };
    $tabs = array(
        'open'    => array(__('Unanswered', 'nias-course-widget'), " AND staff_replied = 0 AND resolved = 0 AND status IN ('approved','pending')"),
        'pending' => array(__('Pending approval', 'nias-course-widget'), " AND status = 'pending'"),
        'all'     => array(__('All', 'nias-course-widget'), ''),
    );
    if (!isset($tabs[$tab])) {
        $tab = 'open';
    }
    $w     = $where . $tabs[$tab][1];
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} {$w}");
    $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} {$w} ORDER BY last_activity DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per), ARRAY_A);
    $answers = array();
    if ($rows) {
        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        foreach ((array) $wpdb->get_results("SELECT * FROM {$t} WHERE parent_id IN (" . implode(',', $ids) . ') ORDER BY created ASC', ARRAY_A) as $a) {
            $answers[(int) $a['parent_id']][] = $a;
        }
        cache_users(array_unique(array_merge(wp_list_pluck($rows, 'user_id'), wp_list_pluck(call_user_func_array('array_merge', array_values($answers) ?: array(array())), 'user_id'))));
        _prime_post_caches(array_unique(array_map('intval', wp_list_pluck($rows, 'post_id'))), false, false);
    }
    $notice = isset($_GET['qa_notice']) ? sanitize_key(wp_unslash($_GET['qa_notice'])) : '';
    $here = remove_query_arg('qa_notice', add_query_arg(array('qt' => $tab, 'qc' => $cid ?: null, 'qs' => $s !== '' ? $s : null, 'qp' => $page > 1 ? $page : null), $base));

    $h = nias_qa_manage_css() . '<div class="nqa" dir="rtl"><div class="nqa-head"><h2>' . esc_html__('Course Q&A', 'nias-course-widget') . '</h2></div>';
    if ($notice) {
        $h .= '<div class="nqa-notice' . ($notice === 'error' ? ' err' : '') . '">' . esc_html($notice === 'error' ? __('Failed.', 'nias-course-widget') : ($notice === 'reply' ? __('Reply saved.', 'nias-course-widget') : __('Done.', 'nias-course-widget'))) . '</div>';
    }
    $h .= '<div class="nqa-tabs">';
    foreach ($tabs as $k => $tb) {
        $n = $count($tb[1]);
        $h .= '<a class="nqa-tab' . ($k === $tab ? ' on' : '') . '" href="' . esc_url(remove_query_arg(array('qp', 'qa_notice'), add_query_arg('qt', $k, $here))) . '">' . esc_html($tb[0]) . ' <span>' . esc_html(nias_fa_digits($n)) . '</span></a>';
    }
    // Filters: course (searchable picker) + text.
    $h .= '<form class="nqa-filter" method="get" action="' . esc_url(strtok($base, '?')) . '">';
    foreach (wp_parse_args((string) wp_parse_url($base, PHP_URL_QUERY)) as $k => $v) {
        $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    $h .= '<input type="hidden" name="qt" value="' . esc_attr($tab) . '">';
    $cval = $cid && function_exists('nias_picker_product_item') ? array(nias_picker_product_item($cid)) : array();
    $h .= '<div class="nqa-cpick" data-nqa-course="' . esc_attr(wp_json_encode($cval)) . '"></div><input type="hidden" name="qc" value="' . esc_attr($cid ?: '') . '">';
    $h .= '<input type="search" name="qs" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Search question text…', 'nias-course-widget') . '"><button class="nqa-btn">' . esc_html__('Filter', 'nias-course-widget') . '</button></form></div>';

    if (!$rows) {
        $h .= '<div class="nqa-empty">' . esc_html($tab === 'open' ? __('No unanswered questions. 🎉', 'nias-course-widget') : __('Nothing found.', 'nias-course-widget')) . '</div>';
    }
    $post = admin_url('admin-post.php');
    $btn = function ($id, $op, $label, $cls = '', $confirm = '') use ($post, $here) {
        return '<form method="post" action="' . esc_url($post) . '"' . ($confirm ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\')"' : '') . '>' . wp_nonce_field('nias_qa_manage_' . $id, '_wpnonce', false, false)
            . '<input type="hidden" name="action" value="nias_qa_manage"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="op" value="' . esc_attr($op) . '"><input type="hidden" name="back" value="' . esc_attr($here) . '">'
            . '<button class="nqa-btn ' . esc_attr($cls) . '">' . esc_html($label) . '</button></form>';
    };
    foreach ((array) $rows as $q) {
        $u = get_userdata((int) $q['user_id']);
        $h .= '<article class="nqa-q" id="qa-' . (int) $q['id'] . '"><div class="nqa-meta"><b>' . esc_html($u ? $u->display_name : '—') . '</b>'
            . '<span>' . esc_html(get_the_title((int) $q['post_id']) . ($q['lesson_title'] !== '' ? ' › ' . $q['lesson_title'] : ' › ' . __('Whole course', 'nias-course-widget'))) . '</span>'
            . '<span>' . esc_html(nias_qa_when($q['created'])) . '</span>'
            . ($q['status'] === 'pending' ? '<span class="nqa-chip wait">' . esc_html__('Pending approval', 'nias-course-widget') . '</span>' : '')
            . ($q['resolved'] ? '<span class="nqa-chip ok">' . esc_html__('Resolved', 'nias-course-widget') . '</span>' : '')
            . (!$q['staff_replied'] ? '<span class="nqa-chip no">' . esc_html__('Unanswered', 'nias-course-widget') . '</span>' : '') . '</div>'
            . '<div class="nqa-text">' . nl2br(esc_html($q['content'])) . '</div>';
        foreach (isset($answers[(int) $q['id']]) ? $answers[(int) $q['id']] : array() as $a) {
            $au = get_userdata((int) $a['user_id']);
            $h .= '<div class="nqa-a' . ($a['is_staff'] ? ' staff' : '') . '"><div class="nqa-meta"><b>' . esc_html($au ? $au->display_name : '—') . '</b>' . ($a['is_staff'] ? '<span class="nqa-chip ok">' . esc_html__('Instructor', 'nias-course-widget') . '</span>' : '') . '<span>' . esc_html(nias_qa_when($a['created'])) . '</span></div>'
                . '<div class="nqa-text">' . nl2br(esc_html($a['content'])) . '</div>' . $btn((int) $a['id'], 'delete', __('Delete reply', 'nias-course-widget'), 'ghost', __('Delete this reply?', 'nias-course-widget')) . '</div>';
        }
        $h .= '<form class="nqa-reply" method="post" action="' . esc_url($post) . '">' . wp_nonce_field('nias_qa_manage_' . (int) $q['id'], '_wpnonce', false, false)
            . '<input type="hidden" name="action" value="nias_qa_manage"><input type="hidden" name="id" value="' . (int) $q['id'] . '"><input type="hidden" name="op" value="reply"><input type="hidden" name="back" value="' . esc_attr($here) . '">'
            . '<textarea name="content" rows="2" required placeholder="' . esc_attr__('Your reply…', 'nias-course-widget') . '"></textarea><button class="nqa-btn pri">' . esc_html($q['status'] === 'pending' ? __('Reply and publish', 'nias-course-widget') : __('Send reply', 'nias-course-widget')) . '</button></form>';
        $h .= '<div class="nqa-actions">'
            . ($q['status'] === 'pending' ? $btn((int) $q['id'], 'approve', __('Approve and publish', 'nias-course-widget'), 'pri') : $btn((int) $q['id'], 'hide', __('Hide question', 'nias-course-widget'), 'ghost'))
            . $btn((int) $q['id'], $q['resolved'] ? 'unresolve' : 'resolve', $q['resolved'] ? __('Reopen', 'nias-course-widget') : __('Mark as resolved', 'nias-course-widget'), 'ghost')
            . $btn((int) $q['id'], 'delete', __('Delete question', 'nias-course-widget'), 'danger', __('Delete this question and all its replies?', 'nias-course-widget'))
            . '<a class="nqa-btn ghost" href="' . esc_url(nias_qa_course_link($q)) . '" target="_blank" rel="noopener">' . esc_html__('View in the course', 'nias-course-widget') . '</a></div></article>';
    }
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        $h .= '<nav class="nqa-pager">' . ($page > 1 ? '<a class="nqa-btn" href="' . esc_url(add_query_arg('qp', $page - 1, $here)) . '">‹ ' . esc_html__('Previous', 'nias-course-widget') . '</a>' : '<span class="nqa-btn off">‹ ' . esc_html__('Previous', 'nias-course-widget') . '</span>')
            . '<span>' . esc_html(sprintf(__('Page %1$s of %2$s', 'nias-course-widget'), nias_fa_digits($page), nias_fa_digits($pages))) . '</span>'
            . ($page < $pages ? '<a class="nqa-btn" href="' . esc_url(add_query_arg('qp', $page + 1, $here)) . '">' . esc_html__('Next', 'nias-course-widget') . ' ›</a>' : '<span class="nqa-btn off">' . esc_html__('Next', 'nias-course-widget') . ' ›</span>') . '</nav>';
    }
    if (function_exists('nias_picker_enqueue')) {
        ob_start();
        nias_picker_enqueue();
        $h .= ob_get_clean();
    }
    $h .= "<script>document.addEventListener('DOMContentLoaded',function(){var el=document.querySelector('[data-nqa-course]');if(!el||!window.NiasPicker)return;var hid=el.parentNode.querySelector('input[name=qc]');window.NiasPicker.attach(el,{source:'product',emptyLabel:'همهٔ دوره‌ها',placeholder:'جستجوی دوره…',value:JSON.parse(el.getAttribute('data-nqa-course')||'[]'),onChange:function(v){hid.value=v[0]?v[0].id:'';hid.form.submit();}});});</script>";
    return $h . '</div>';
}

function nias_qa_manage_css()
{
    return '<style>
    .nqa{max-width:1100px;min-width:0;font-family:inherit;color:#1f2a30}
    .nqa *{box-sizing:border-box}
    .nqa-head h2{margin:6px 0 14px;font-size:20px;font-weight:800}
    .nqa-notice{background:#ecf8ea;border:1px solid #cfeccb;color:#2f7d27;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-weight:600}
    .nqa-notice.err{background:#fdeaea;border-color:#f3c9c9;color:#b93b3b}
    .nqa-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px}
    .nqa-tab{padding:7px 14px;border-radius:99px;background:#fff;border:1px solid #e1e6e9;color:#4b575d;font-weight:700;font-size:13px;text-decoration:none}
    .nqa-tab span{background:#eef1f4;border-radius:99px;padding:0 7px;margin-right:4px;font-size:12px}
    .nqa-tab.on{background:#1e83f0;border-color:#1e83f0;color:#fff}.nqa-tab.on span{background:rgba(255,255,255,.25)}
    .nqa-filter{display:flex;gap:8px;align-items:center;margin-right:auto;flex-wrap:wrap}
    .nqa-filter input[type=search]{height:38px;border:1px solid #dfe5ec;border-radius:10px;padding:0 10px;min-width:200px}
    .nqa-cpick{min-width:240px}
    .nqa-q{background:#fff;border:1px solid #e8ecef;border-radius:14px;padding:14px 16px;margin-bottom:12px}
    .nqa-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12.5px;color:#7b868a}
    .nqa-meta b{color:#1f2a30;font-size:14px}
    .nqa-text{margin:8px 0;font-size:14px;line-height:1.9;color:#2b363c}
    .nqa-a{margin:8px 18px 0 0;padding:10px 12px;border-radius:12px;background:#f7f9fb;border-right:3px solid #dfe5ec}
    .nqa-a.staff{background:#f2f8ff;border-right-color:#1e83f0}
    .nqa-a form{display:inline-block}
    .nqa-chip{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:#eef1f4;color:#6b777d}
    .nqa-chip.ok{background:rgba(72,175,59,.13);color:#3e9a32}.nqa-chip.no{background:#fff1e6;color:#c26a1a}.nqa-chip.wait{background:#eef5ff;color:#2563c9}
    .nqa-reply{display:flex;gap:8px;align-items:flex-start;margin-top:10px}
    .nqa-reply textarea{flex:1;border:1px solid #d6dde3;border-radius:10px;padding:8px 10px;font:inherit;font-size:13.5px;min-height:42px}
    .nqa-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
    .nqa-actions form{margin:0}
    .nqa-btn{display:inline-flex;align-items:center;height:34px;padding:0 12px;border-radius:9px;border:1px solid #dbe6f3;background:#f5f9ff;color:#1e6fd0!important;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none!important}
    .nqa-btn.pri{background:#1e83f0;border-color:#1e83f0;color:#fff!important}
    .nqa-btn.ghost{background:#fff;border-color:#e1e6e9;color:#4b575d!important}
    .nqa-btn.danger{background:#fff;border-color:#f3c9c9;color:#d64545!important}
    .nqa-btn.off{opacity:.45;cursor:default}
    .nqa-empty{padding:28px;text-align:center;color:#7b868a;background:#fff;border:1px dashed #dfe4e8;border-radius:14px}
    .nqa-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:14px 0;direction:ltr}
    .nqa-pager span{direction:rtl;font-size:13px;color:#6b777d}
    @media (max-width:700px){.nqa-reply{flex-direction:column}.nqa-reply .nqa-btn{width:100%;justify-content:center}.nqa-filter{margin:0;width:100%}}
    </style>';
}

/** Instructor dashboard: card + full view at ?nias_qa=1. */
function nias_qa_dashboard_card($user_id)
{
    if (!nias_qa_enabled()) {
        return '';
    }
    $scope = nias_qa_staff_scope($user_id);
    if ($scope !== null && !$scope) {
        return '';
    }
    $n = nias_qa_unanswered_count($scope);
    $url = add_query_arg('nias_qa', 1, get_permalink());
    return '<section class="nias-idash-card" dir="rtl" style="padding:16px 18px"><div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><h3 style="margin:0;font-size:15px;font-weight:800;flex:1">' . esc_html__('Q&A', 'nias-course-widget') . '</h3>'
        . '<span style="background:' . ($n ? '#fff1e6;color:#c26a1a' : '#eef1f4;color:#6b777d') . ';font-weight:800;font-size:12px;padding:2px 9px;border-radius:99px">' . esc_html(nias_fa_digits($n)) . '</span></div>'
        . '<p style="margin:0 0 10px;font-size:13px;color:#6b777d">' . esc_html($n ? sprintf(__('%s questions are waiting for your reply.', 'nias-course-widget'), nias_fa_digits($n)) : __('You have no unanswered questions.', 'nias-course-widget')) . '</p>'
        . '<a href="' . esc_url($url) . '" style="display:inline-flex;height:34px;align-items:center;padding:0 13px;border-radius:10px;background:#1e83f0;color:#fff;font-weight:700;font-size:13px;text-decoration:none">' . esc_html__('Answer questions', 'nias-course-widget') . '</a></section>';
}

function nias_qa_dashboard_view($user_id)
{
    $back = remove_query_arg(array('nias_qa', 'qt', 'qc', 'qs', 'qp', 'qa_notice'));
    return '<div style="max-width:1100px;margin:0 auto;padding:10px 0" dir="rtl"><a style="display:inline-block;margin-bottom:10px;color:#1e83f0;font-weight:700;text-decoration:none" href="' . esc_url($back) . '">→ ' . esc_html__('Back to the instructor dashboard', 'nias-course-widget') . '</a>'
        . nias_qa_manage_render(add_query_arg('nias_qa', 1, remove_query_arg(array('qt', 'qc', 'qs', 'qp', 'qa_notice'))), nias_qa_staff_scope($user_id)) . '</div>';
}

/* Remove a course's Q&A with it. */
add_action('before_delete_post', function ($post_id) {
    global $wpdb;
    if (get_option('nias_qa_db_version') === NIAS_QA_DB_VERSION && function_exists('nias_is_course_post') && nias_is_course_post($post_id)) {
        $wpdb->delete(nias_qa_table(), array('post_id' => (int) $post_id), array('%d'));
    }
});

/*
 * On «دوره» pages Q&A replaces the theme's comment form (two parallel forms
 * would confuse students). Product reviews are untouched.
 * Keep comments with: add_filter('nias_qa_replace_course_comments', '__return_false');
 */
add_filter('comments_open', function ($open, $post_id) {
    if ($open && defined('NIAS_COURSE_CPT') && get_post_type($post_id) === NIAS_COURSE_CPT && nias_qa_enabled() && apply_filters('nias_qa_replace_course_comments', true)) {
        return false;
    }
    return $open;
}, 20, 2);
add_filter('comments_array', function ($comments, $post_id) {
    if (defined('NIAS_COURSE_CPT') && get_post_type($post_id) === NIAS_COURSE_CPT && nias_qa_enabled() && apply_filters('nias_qa_replace_course_comments', true)) {
        return array();
    }
    return $comments;
}, 20, 2);
