<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «کلاس آنلاین» (live sessions as lessons).
 *
 *   - Lesson option «کلاس زنده»: class link (Skyroom, BigBlueButton, Google
 *     Meet, …), start (site time) and duration.
 *   - Before: countdown + «افزودن به تقویم» (.ics). The link itself reaches the
 *     browser only from 10 minutes before the start until the end, and only for
 *     people who can open the lesson. After: "recording soon" — once the
 *     instructor puts the recording in the lesson's video, that plays instead.
 *   - «یادآوری کلاس زنده» (SMS event) ~1 hour before, to the course's students.
 *
 * @package nias-course-widget
 */

function nias_live_enabled()
{
    return (bool) apply_filters('nias_live_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_live_enabled') === 'on');
}

/** Live settings of a lesson (editor shape) → {url, start, end} or null. */
function nias_live_cfg($lesson)
{
    if (empty($lesson['live']) || empty($lesson['liveStart'])) {
        return null;
    }
    $d = date_create_immutable(str_replace('T', ' ', (string) $lesson['liveStart']), wp_timezone());
    if (!$d) {
        return null;
    }
    $start = $d->getTimestamp();
    $dur   = max(10, min(600, (int) (isset($lesson['liveDur']) ? $lesson['liveDur'] : 90)));
    return array('url' => isset($lesson['liveUrl']) ? (string) $lesson['liveUrl'] : '', 'start' => $start, 'end' => $start + $dur * MINUTE_IN_SECONDS, 'dur' => $dur);
}

function nias_live_label($ts)
{
    return function_exists('nias_drip_when_label') ? nias_drip_when_label($ts) : nias_fa_digits(wp_date('Y-m-d H:i', $ts));
}

/** Client payload for a lesson the user may open. */
function nias_live_payload($cfg, $post_id, $lesson_id)
{
    $now   = time();
    $open  = $now >= $cfg['start'] - 10 * MINUTE_IN_SECONDS && $now < $cfg['end'];
    return array(
        'start' => $cfg['start'],
        'end'   => $cfg['end'],
        'label' => nias_live_label($cfg['start']),
        'dur'   => nias_fa_digits($cfg['dur']),
        'state' => $now < $cfg['start'] - 10 * MINUTE_IN_SECONDS ? 'soon' : ($open ? 'open' : 'ended'),
        'url'   => $open ? esc_url_raw($cfg['url']) : '',
        'ics'   => add_query_arg(array('nias_live_ics' => (int) $post_id, 'l' => $lesson_id), home_url('/')),
    );
}

/* .ics «افزودن به تقویم» */
add_action('init', function () {
    if (empty($_GET['nias_live_ics']) || empty($_GET['l']) || !nias_live_enabled()) {
        return;
    }
    $pid = (int) $_GET['nias_live_ics'];
    $lid = sanitize_key(wp_unslash($_GET['l']));
    if (!nias_is_course_post($pid) || !preg_match('/^c(\d+)l(\d+)$/', $lid, $m)) {
        return;
    }
    $js  = nias_curriculum_sections_to_js((array) carbon_get_post_meta($pid, 'course_sections'));
    $l   = $js[(int) $m[1]]['lessons'][(int) $m[2]] ?? null;
    $cfg = $l ? nias_live_cfg($l) : null;
    if (!$cfg) {
        return;
    }
    $fmt = function ($ts) { return gmdate('Ymd\THis\Z', $ts); };
    $esc = function ($s) { return str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\;', '\,', '\n'), $s); };
    $url = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid);
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nias Course//Live//FA\r\nBEGIN:VEVENT\r\nUID:nias-live-{$pid}-{$lid}@" . wp_parse_url(home_url(), PHP_URL_HOST) . "\r\nDTSTAMP:" . $fmt(time()) . "\r\nDTSTART:" . $fmt($cfg['start']) . "\r\nDTEND:" . $fmt($cfg['end'])
        . "\r\nSUMMARY:" . $esc($l['title'] . ' — ' . get_the_title($pid)) . "\r\nDESCRIPTION:" . $esc(__('کلاس آنلاین؛ لینک ورود ۱۰ دقیقه قبل از شروع در صفحهٔ دوره:', 'nias-course-widget') . ' ' . $url) . "\r\nURL:" . $url
        . "\r\nBEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nDESCRIPTION:" . $esc($l['title']) . "\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="live-class.ics"');
    echo $ics; // phpcs:ignore -- calendar file
    exit;
}, 4);

/* -------------------------------------------------------------------------
 * Reminders ~1 hour before (index of upcoming sessions, hourly tick)
 * ---------------------------------------------------------------------- */

add_action('nias_course_sections_saved', 'nias_live_reindex', 30);
function nias_live_reindex($post_id)
{
    $idx = get_option('nias_live_index', array());
    $idx = is_array($idx) ? $idx : array();
    unset($idx[(int) $post_id]);
    foreach (nias_curriculum_sections_to_js((array) carbon_get_post_meta($post_id, 'course_sections')) as $ci => $ch) {
        foreach ((array) $ch['lessons'] as $li => $l) {
            $cfg = nias_live_cfg($l);
            if ($cfg && $cfg['end'] > time()) {
                $idx[(int) $post_id]['c' . $ci . 'l' . $li] = array('start' => $cfg['start'], 'title' => $l['title']);
            }
        }
    }
    update_option('nias_live_index', $idx, false);
}

add_action('init', function () {
    $on = nias_live_enabled();
    $at = wp_next_scheduled('nias_live_tick');
    if ($on && !$at) {
        wp_schedule_event(time() + 300, 'nias_5min', 'nias_live_tick');
    } elseif (!$on && $at) {
        wp_clear_scheduled_hook('nias_live_tick');
    }
}, 31);

add_action('nias_live_tick', 'nias_live_tick');
function nias_live_tick()
{
    if (!nias_live_enabled()) {
        return 0;
    }
    $idx  = get_option('nias_live_index', array());
    $sent = get_option('nias_live_sent', array());
    $sent = is_array($sent) ? $sent : array();
    $now  = time();
    $n    = 0;
    foreach ((array) $idx as $pid => $lessons) {
        foreach ($lessons as $lid => $s) {
            $key = $pid . ':' . $lid . ':' . $s['start'];
            if (isset($sent[$key]) || $s['start'] <= $now || $s['start'] > $now + 65 * MINUTE_IN_SECONDS) {
                continue;
            }
            $sent[$key] = $now;
            foreach (nias_live_students((int) $pid) as $uid) {
                do_action('nias_live_reminder', (int) $uid, (int) $pid, $s['title'], (int) $s['start']);
                $n++;
            }
        }
    }
    update_option('nias_live_sent', array_slice($sent, -500, null, true), false);
    return $n;
}

/** Students of a course (buyers + free enrolments), staff excluded. */
function nias_live_students($post_id)
{
    $ids = array();
    if (function_exists('nias_idash_order_rows') && get_post_type($post_id) === 'product') {
        foreach (nias_idash_order_rows(array($post_id)) as $r) {
            if ($r['buyerId']) {
                $ids[$r['buyerId']] = true;
            }
        }
    }
    foreach (get_users(array('fields' => 'ID', 'meta_key' => '_nias_enroll_' . (int) $post_id, 'number' => 5000)) as $u) {
        $ids[(int) $u] = true;
    }
    return array_values(array_filter(array_keys($ids), function ($u) use ($post_id) {
        return function_exists('nias_user_has_course_access') ? nias_user_has_course_access($u, $post_id) && !(function_exists('nias_can_edit_course') && nias_can_edit_course($u, $post_id)) : true;
    }));
}
