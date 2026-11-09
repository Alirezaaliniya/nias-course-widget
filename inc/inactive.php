<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «یادآوری به دانشجوی غیرفعال» (re-engagement reminders).
 *
 *   - Activity per user and course: user meta _nias_last_seen_{course}
 *     (opening the course, watching, progress, a quiz). Enrolment counts as
 *     activity, so "bought but never started" is reminded too.
 *   - Steps (default 3, 7, 14 days without activity): one reminder per step,
 *     with the next unfinished lesson ("از «جلسهٔ X» ادامه بده") and a link.
 *     Any activity starts the steps again.
 *   - Not sent when the course is finished (100%), access ended, the user
 *     opted out (link in every e-mail), or for admins / the course's
 *     instructors. Sent only in the allowed hours (default 9–21).
 *   - SMS event of the SMS module + optional e-mail. An hourly tick reads only
 *     due users (user meta _nias_inactive_next).
 *
 * @package nias-course-widget
 */

function nias_inactive_enabled()
{
    return (bool) apply_filters('nias_inactive_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_inactive_enabled') === 'on');
}

/** Reminder steps in days, ascending (e.g. [3, 7, 14]). */
function nias_inactive_steps()
{
    $raw  = function_exists('carbon_get_theme_option') ? (string) carbon_get_theme_option('nias_inactive_days') : '';
    $raw  = $raw === '' ? '3,7,14' : $raw;
    $raw  = strtr($raw, array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'));
    $days = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,،]+/u', $raw)), function ($d) { return $d > 0 && $d <= 365; })));
    sort($days);
    return array_slice($days, 0, 6);
}

/** Allowed sending hours [from, to] in the site's time zone (default 9–21). */
function nias_inactive_hours()
{
    $raw = function_exists('carbon_get_theme_option') ? (string) carbon_get_theme_option('nias_inactive_hours') : '';
    $raw = strtr($raw === '' ? '9-21' : $raw, array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'));
    if (preg_match('/(\d{1,2})\D+(\d{1,2})/', $raw, $m)) {
        return array(min(23, (int) $m[1]), min(24, (int) $m[2]));
    }
    return array(0, 24);
}

function nias_inactive_key($what, $post_id)
{
    return '_nias_' . $what . '_' . (int) $post_id;
}

/* -------------------------------------------------------------------------
 * Activity
 * ---------------------------------------------------------------------- */

/** Record activity (at most every 10 minutes per course). */
function nias_inactive_touch($user_id, $post_id, $when = 0)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (!$user_id || !$post_id || !nias_inactive_enabled()) {
        return;
    }
    $when = $when ? (int) $when : time();
    $last = (int) get_user_meta($user_id, nias_inactive_key('last_seen', $post_id), true);
    if ($last && $when - $last < 10 * MINUTE_IN_SECONDS) {
        return;
    }
    update_user_meta($user_id, nias_inactive_key('last_seen', $post_id), $when);
    delete_user_meta($user_id, nias_inactive_key('inactive_sent', $post_id));
    $list = get_user_meta($user_id, '_nias_activity_courses', true);
    $list = is_array($list) ? $list : array();
    if (!in_array($post_id, $list, true)) {
        $list[] = $post_id;
        update_user_meta($user_id, '_nias_activity_courses', array_slice($list, -100));
    }
    nias_inactive_recompute_next($user_id);
}

add_action('nias_course_viewed', 'nias_inactive_touch', 10, 2);
add_action('nias_watch_updated', function ($uid, $pid) { nias_inactive_touch($uid, $pid); }, 10, 2);
add_action('nias_course_progress_updated', function ($uid, $pid) { nias_inactive_touch($uid, $pid); }, 10, 2);
// Enrolment = activity; an old enrolment (backfill of past orders) counts from today.
add_action('nias_course_enrolled', function ($uid, $pid, $ts) { nias_inactive_touch($uid, $pid, $ts > time() - DAY_IN_SECONDS ? $ts : time()); }, 10, 3);
add_action('nias_quiz_attempt_submitted', function ($uid, $quiz_id) {
    foreach (array_map('intval', (array) get_post_meta($quiz_id, '_nias_quiz_in_course', false)) as $pid) {
        nias_inactive_touch($uid, $pid);
    }
}, 10, 2);

/** Steps already sent since the last activity (ints). */
function nias_inactive_sent_steps($user_id, $post_id)
{
    $v = get_user_meta($user_id, nias_inactive_key('inactive_sent', $post_id), true);
    return is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_numeric'))) : array();
}

/** When the next reminder of this user is due (across their courses). */
function nias_inactive_recompute_next($user_id)
{
    $steps = nias_inactive_steps();
    $next  = 0;
    foreach ((array) get_user_meta($user_id, '_nias_activity_courses', true) as $pid) {
        $last = (int) get_user_meta($user_id, nias_inactive_key('last_seen', $pid), true);
        if (!$last) {
            continue;
        }
        $sent = nias_inactive_sent_steps($user_id, $pid);
        foreach ($steps as $d) {
            if (!in_array($d, $sent, true)) {
                $at   = $last + $d * DAY_IN_SECONDS;
                $next = (!$next || $at < $next) ? $at : $next;
                break;
            }
        }
    }
    if ($next) {
        update_user_meta($user_id, '_nias_inactive_next', $next);
    } else {
        delete_user_meta($user_id, '_nias_inactive_next');
    }
}

/* -------------------------------------------------------------------------
 * Who gets a reminder, and where to continue
 * ---------------------------------------------------------------------- */

function nias_inactive_eligible($user_id, $post_id)
{
    if (get_user_meta($user_id, '_nias_inactive_optout', true) === '1' || get_post_status($post_id) !== 'publish') {
        return false;
    }
    if (user_can($user_id, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id))) {
        return false;
    }
    if (function_exists('nias_user_has_course_access') && !nias_user_has_course_access($user_id, $post_id)) {
        return false; // not bought / access ended (the access module reminds about that)
    }
    if (function_exists('nias_cert_user_progress') && nias_cert_user_progress($user_id, $post_id) >= 100) {
        return false;
    }
    return (bool) apply_filters('nias_inactive_eligible', true, $user_id, $post_id);
}

/** First video lesson not watched to the end (and open): {id, title} or null. */
function nias_inactive_next_lesson($user_id, $post_id)
{
    if (!function_exists('nias_curriculum_sections_to_js')) {
        return null;
    }
    $watch = function_exists('nias_watch_get') ? nias_watch_get($user_id, $post_id) : array();
    $done  = function_exists('nias_watch_complete_pct') ? (float) nias_watch_complete_pct() : 90;
    $drip  = function_exists('nias_drip_enabled') && nias_drip_enabled();
    foreach (nias_curriculum_sections_to_js((array) carbon_get_post_meta($post_id, 'course_sections')) as $ci => $ch) {
        foreach ((array) $ch['lessons'] as $li => $l) {
            $id = 'c' . $ci . 'l' . $li;
            if (empty($l['video']['upload']) && empty($l['video']['url']) && empty($l['video']['embed'])) {
                continue;
            }
            if ($drip && nias_drip_state($user_id, $post_id, nias_drip_cfg_js($l))) {
                return null; // the next one is still closed: no point pushing
            }
            if (!isset($watch[$id]) || (float) $watch[$id]['p'] < $done) {
                return array('id' => $id, 'title' => $l['title']);
            }
        }
    }
    return null;
}

/* -------------------------------------------------------------------------
 * Tick
 * ---------------------------------------------------------------------- */

add_action('init', function () {
    $on = nias_inactive_enabled();
    $at = wp_next_scheduled('nias_inactive_tick');
    if ($on && !$at) {
        wp_schedule_event(time() + 300, 'hourly', 'nias_inactive_tick');
    } elseif (!$on && $at) {
        wp_clear_scheduled_hook('nias_inactive_tick');
    }
}, 30);

add_action('nias_inactive_tick', 'nias_inactive_tick');
function nias_inactive_tick($force_hours = false)
{
    if (!nias_inactive_enabled()) {
        return 0;
    }
    list($from, $to) = nias_inactive_hours();
    $h = (int) wp_date('G');
    if (!$force_hours && ($h < $from || $h >= $to)) {
        return 0; // quiet hours: try again next hour
    }
    $now   = time();
    $sent  = 0;
    $steps = nias_inactive_steps();
    $ids   = get_users(array('fields' => 'ID', 'number' => (int) apply_filters('nias_inactive_batch', 200), 'count_total' => false, 'meta_query' => array(array('key' => '_nias_inactive_next', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC'))));
    foreach ($ids as $uid) {
        $uid = (int) $uid;
        foreach ((array) get_user_meta($uid, '_nias_activity_courses', true) as $pid) {
            $pid  = (int) $pid;
            $last = (int) get_user_meta($uid, nias_inactive_key('last_seen', $pid), true);
            if (!$last) {
                continue;
            }
            $idle  = ($now - $last) / DAY_IN_SECONDS;
            $done  = nias_inactive_sent_steps($uid, $pid);
            $due   = array();
            foreach ($steps as $d) {
                if ($idle >= $d && !in_array($d, $done, true)) {
                    $due[] = $d;
                }
            }
            if (!$due) {
                continue;
            }
            // All passed steps are marked; only the latest one is sent (no burst).
            update_user_meta($uid, nias_inactive_key('inactive_sent', $pid), array_values(array_unique(array_merge($done, $due))));
            if (!nias_inactive_eligible($uid, $pid)) {
                continue;
            }
            $lesson = nias_inactive_next_lesson($uid, $pid);
            $link   = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid);
            do_action('nias_inactive_reminder', $uid, $pid, (int) floor($idle), $lesson, $link);
            $sent++;
            $stats = get_option('nias_inactive_stats', array());
            $stats = is_array($stats) ? $stats : array();
            $day   = wp_date('Y-m-d');
            $stats[$day] = (isset($stats[$day]) ? (int) $stats[$day] : 0) + 1;
            update_option('nias_inactive_stats', array_slice($stats, -60, null, true), false);
        }
        nias_inactive_recompute_next($uid);
    }
    return $sent;
}

/** Reminders sent in the last N days. */
function nias_inactive_sent_count($days = 30)
{
    $n = 0;
    $since = wp_date('Y-m-d', time() - $days * DAY_IN_SECONDS);
    foreach ((array) get_option('nias_inactive_stats', array()) as $d => $c) {
        if ($d >= $since) {
            $n += (int) $c;
        }
    }
    return $n;
}

/* -------------------------------------------------------------------------
 * E-mail (the SMS event lives in the SMS module) + opt-out
 * ---------------------------------------------------------------------- */

function nias_inactive_unsub_url($user_id)
{
    $sig = substr(hash_hmac('sha256', 'nias-unsub|' . (int) $user_id, wp_salt('auth')), 0, 20);
    return add_query_arg(array('nias_unsub' => (int) $user_id, 's' => $sig), home_url('/'));
}

add_action('init', function () {
    if (empty($_GET['nias_unsub']) || empty($_GET['s'])) {
        return;
    }
    $uid = (int) $_GET['nias_unsub'];
    $ok  = hash_equals(substr(hash_hmac('sha256', 'nias-unsub|' . $uid, wp_salt('auth')), 0, 20), sanitize_text_field(wp_unslash($_GET['s'])));
    if ($ok && get_userdata($uid)) {
        update_user_meta($uid, '_nias_inactive_optout', '1');
        wp_die('<h1>' . esc_html__('Reminders turned off', 'nias-course-widget') . '</h1><p>' . esc_html__('From now on, "Continue the course" messages will not be sent to you.', 'nias-course-widget') . '</p><p><a class="button" href="' . esc_url(home_url('/')) . '">' . esc_html__('Site home page', 'nias-course-widget') . '</a></p>', esc_html__('Reminders turned off', 'nias-course-widget'), array('response' => 200, 'text_direction' => 'rtl'));
    }
}, 3);

add_action('nias_inactive_reminder', function ($uid, $pid, $days, $lesson, $link) {
    if (carbon_get_theme_option('nias_inactive_email') !== 'on') {
        return;
    }
    $u = get_userdata($uid);
    if (!$u || !is_email($u->user_email)) {
        return;
    }
    $course = get_the_title($pid);
    $fa     = function ($n) { return function_exists('nias_fa_digits') ? nias_fa_digits($n) : $n; };
    $subj   = sprintf(__('The course "%s" is waiting for you', 'nias-course-widget'), $course);
    $line   = $lesson
        ? sprintf(__('You have not visited the course "%2$s" for %1$s days. Continue from the lesson "%3$s"; a few minutes a day is enough.', 'nias-course-widget'), $fa($days), $course, $lesson['title'])
        : sprintf(__('You have not visited the course "%2$s" for %1$s days. Continue today; a few minutes a day is enough.', 'nias-course-widget'), $fa($days), $course);
    $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:2;color:#1f2a30">'
        . '<p>' . esc_html(sprintf(__('Dear %s,', 'nias-course-widget'), $u->display_name)) . '</p><p>' . esc_html($line) . '</p>'
        . '<p><a href="' . esc_url($link) . '" style="display:inline-block;background:#1e83f0;color:#fff;text-decoration:none;padding:8px 18px;border-radius:9px">' . esc_html__('Continue the course', 'nias-course-widget') . '</a></p>'
        . '<p style="font-size:12px;color:#94a3b8">' . esc_html__('If you do not want to receive these reminders:', 'nias-course-widget') . ' <a href="' . esc_url(nias_inactive_unsub_url($uid)) . '" style="color:#94a3b8">' . esc_html__('Unsubscribe from reminders', 'nias-course-widget') . '</a></p></div>';
    wp_mail($u->user_email, $subj, $body, array('Content-Type: text/html; charset=UTF-8'));
}, 10, 5);

/* -------------------------------------------------------------------------
 * Switching the module on: start counting for current students from today
 * (no reminder storm for people who were idle before)
 * ---------------------------------------------------------------------- */

add_action('update_option__nias_inactive_enabled', function ($old, $new) {
    if ($new === 'on' && $old !== 'on' && !wp_next_scheduled('nias_inactive_backfill', array(1))) {
        wp_schedule_single_event(time() + 10, 'nias_inactive_backfill', array(1));
    }
}, 10, 2);
add_action('add_option__nias_inactive_enabled', function ($n, $v) {
    if ($v === 'on' && !wp_next_scheduled('nias_inactive_backfill', array(1))) {
        wp_schedule_single_event(time() + 10, 'nias_inactive_backfill', array(1));
    }
}, 10, 2);

add_action('nias_inactive_backfill', 'nias_inactive_backfill');
function nias_inactive_backfill($page)
{
    if (!nias_inactive_enabled()) {
        return;
    }
    $users = get_users(array('fields' => 'ID', 'number' => 200, 'paged' => (int) $page, 'meta_key' => '_nias_course_progress', 'count_total' => false));
    $now   = time();
    foreach ($users as $uid) {
        $progress = get_user_meta($uid, '_nias_course_progress', true);
        foreach ((array) $progress as $pid => $pct) {
            if ((int) $pct < 100 && !get_user_meta($uid, nias_inactive_key('last_seen', $pid), true)) {
                nias_inactive_touch((int) $uid, (int) $pid, $now);
            }
        }
    }
    if (count($users) === 200) {
        wp_schedule_single_event(time() + 30, 'nias_inactive_backfill', array((int) $page + 1));
    }
}
