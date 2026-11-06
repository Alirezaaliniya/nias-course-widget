<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «محتوای قطره‌ای» (drip content): lessons open one by one, a number of days
 * after the student enrolled, at a chosen time of day.
 *
 *   - Each lesson: «قطره‌ای» on/off + «روز» (1 = the day of enrolment) +
 *     «ساعت» (HH:MM, empty = 00:00), in the site's time zone.
 *     Example: day 4 at 07:05 → three days after the enrolment date, at 07:05.
 *   - Enrolment = the first completed order of the course (paid courses) or
 *     the first visit of a logged-in user (free «دوره»). Kept in user meta
 *     _nias_enroll_{course} (unix time) and never moved by a later purchase.
 *   - A closed lesson is stripped on the server (video, file, content, quiz),
 *     like a score-gated one; the course page counts down and opens it without
 *     a reload. Older views (Elementor widget, account) hide it too.
 *   - Admins and the course's instructors always see everything. They can open
 *     every lesson for one student, or move that student's start.
 *   - «جلسهٔ جدید باز شد»: a light 5-minute tick finds only the students whose
 *     next opening has passed (user meta _nias_drip_next) and notifies them
 *     (SMS event of the SMS module, and/or e-mail).
 *
 * @package nias-course-widget
 */

/** Module on? */
function nias_drip_enabled()
{
    return (bool) apply_filters('nias_drip_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_drip_enabled') === 'on');
}

/* -------------------------------------------------------------------------
 * Lesson settings
 * ---------------------------------------------------------------------- */

/** Clean a lesson's drip values. */
function nias_drip_clean($on, $day, $time)
{
    $day  = max(1, min(3650, (int) $day));
    $time = trim((string) $time);
    $time = strtr($time, array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => ':', '.' => ':'));
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
        $time = sprintf('%02d:%02d', $m[1], $m[2]);
    } else {
        $time = '';
    }
    return array('on' => (bool) $on, 'day' => $day, 'time' => $time);
}

/** Drip settings of a stored (Carbon-format) lesson. */
function nias_drip_cfg_raw($lesson)
{
    return nias_drip_clean(!empty($lesson['lesson_drip']), isset($lesson['lesson_drip_day']) ? $lesson['lesson_drip_day'] : 1, isset($lesson['lesson_drip_time']) ? $lesson['lesson_drip_time'] : '');
}

/** Drip settings of a lesson in the editor (JS) shape. */
function nias_drip_cfg_js($lesson)
{
    return nias_drip_clean(!empty($lesson['drip']), isset($lesson['dripDay']) ? $lesson['dripDay'] : 1, isset($lesson['dripTime']) ? $lesson['dripTime'] : '');
}

/** Drip lessons of a course: list of {title, cfg} (editor shape), cached per request. */
function nias_drip_course_lessons($post_id, $fresh = false)
{
    static $cache = array();
    $post_id = (int) $post_id;
    if ($fresh || !isset($cache[$post_id])) {
        $out = array();
        if (function_exists('nias_curriculum_sections_to_js')) {
            foreach (nias_curriculum_sections_to_js((array) carbon_get_post_meta($post_id, 'course_sections')) as $ci => $ch) {
                foreach ((array) $ch['lessons'] as $li => $l) {
                    $cfg = nias_drip_cfg_js($l);
                    $out[] = array('id' => 'c' . $ci . 'l' . $li, 'title' => $l['title'], 'chapter' => $ch['title'], 'cfg' => $cfg);
                }
            }
        }
        $cache[$post_id] = $out;
    }
    return $cache[$post_id];
}

/* -------------------------------------------------------------------------
 * Enrolment date
 * ---------------------------------------------------------------------- */

function nias_drip_key($what, $post_id)
{
    return '_nias_' . $what . '_' . (int) $post_id;
}

/** Time of the first completed order of this course (0 = none). */
function nias_drip_first_order_ts($user_id, $post_id)
{
    if (!function_exists('wc_get_orders')) {
        return 0;
    }
    $first = 0;
    foreach (wc_get_orders(array('customer_id' => (int) $user_id, 'status' => 'wc-completed', 'limit' => -1, 'orderby' => 'date', 'order' => 'ASC')) as $order) {
        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() === (int) $post_id) {
                $d  = $order->get_date_completed() ? $order->get_date_completed() : ($order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created());
                $ts = $d ? $d->getTimestamp() : 0;
                if ($ts && (!$first || $ts < $first)) {
                    $first = $ts;
                }
            }
        }
    }
    return $first;
}

/**
 * When the user enrolled in the course (unix time, 0 = not enrolled).
 *
 * @param bool $create For a free «دوره»: record "now" on the first visit.
 */
function nias_course_enrolled_at($user_id, $post_id, $create = false)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (!$user_id || !$post_id) {
        return 0;
    }
    $ts = (int) get_user_meta($user_id, nias_drip_key('enroll', $post_id), true);
    if ($ts) {
        return $ts;
    }
    if (function_exists('nias_course_is_free') && nias_course_is_free($post_id)) {
        // The old "first opened" day, when there is one.
        $started = get_user_meta($user_id, '_nias_course_started', true);
        if (is_array($started) && !empty($started[$post_id])) {
            $d  = date_create_immutable((string) $started[$post_id], wp_timezone());
            $ts = $d ? $d->setTime(0, 0)->getTimestamp() : 0;
        } elseif ($create && function_exists('nias_user_has_course_access') && nias_user_has_course_access($user_id, $post_id)) {
            $ts = time();
        }
    } elseif (function_exists('nias_course_user_completed') && nias_course_user_completed($user_id, $post_id)) {
        $ts = nias_drip_first_order_ts($user_id, $post_id);
        $ts = $ts ? $ts : time();
    }
    if ($ts) {
        nias_drip_set_enrolled($user_id, $post_id, $ts);
    }
    return $ts;
}

/** Store the enrolment time (and start watching the student's openings). */
function nias_drip_set_enrolled($user_id, $post_id, $ts)
{
    update_user_meta($user_id, nias_drip_key('enroll', $post_id), (int) $ts);
    $list = get_user_meta($user_id, '_nias_drip_courses', true);
    $list = is_array($list) ? $list : array();
    if (!in_array((int) $post_id, $list, true)) {
        $list[] = (int) $post_id;
        update_user_meta($user_id, '_nias_drip_courses', $list);
        // Only openings from now on are announced (no burst for old students).
        update_user_meta($user_id, nias_drip_key('driplast', $post_id), time());
    }
    nias_drip_recompute_next($user_id);
    do_action('nias_course_enrolled', (int) $user_id, (int) $post_id, (int) $ts);
}

// A completed order enrols (the first one only: re-purchases keep the date).
add_action('woocommerce_order_status_completed', 'nias_drip_on_order_completed', 20);
function nias_drip_on_order_completed($order_id)
{
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order || !$order->get_customer_id()) {
        return;
    }
    $uid = (int) $order->get_customer_id();
    foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        if ($pid && nias_is_course_post($pid) && !get_user_meta($uid, nias_drip_key('enroll', $pid), true)) {
            nias_drip_set_enrolled($uid, $pid, time());
        }
    }
}

add_action('nias_free_course_started', function ($uid, $pid) {
    if (!get_user_meta($uid, nias_drip_key('enroll', $pid), true)) {
        nias_drip_set_enrolled($uid, $pid, time());
    }
}, 10, 2);

/* -------------------------------------------------------------------------
 * Opening times
 * ---------------------------------------------------------------------- */

/** When a lesson with this day/time opens for someone enrolled at $enrolled. */
function nias_drip_open_ts($enrolled, $day, $time)
{
    $d = (new DateTimeImmutable('@' . (int) $enrolled))->setTimezone(wp_timezone())->setTime(0, 0);
    if ($day > 1) {
        $d = $d->modify('+' . ((int) $day - 1) . ' days');
    }
    $h = $m = 0;
    if ($time !== '' && strpos($time, ':') !== false) {
        list($h, $m) = array_map('intval', explode(':', $time));
    }
    return $d->setTime($h, $m)->getTimestamp();
}

/** Does this user skip drip for the course? (admins, the course's instructors, "open all".) */
function nias_drip_bypass($user_id, $post_id)
{
    static $cache = array();
    $k = (int) $user_id . '|' . (int) $post_id;
    if (!isset($cache[$k])) {
        $cache[$k] = $user_id && (
            user_can($user_id, 'manage_options')
            || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id))
            || get_user_meta($user_id, nias_drip_key('dripopen', $post_id), true) === '1'
        );
    }
    return (bool) apply_filters('nias_drip_bypass', $cache[$k], $user_id, $post_id);
}

/** "پنجشنبه ۱۵ آبان ۱۴۰۵، ساعت ۰۷:۰۵" */
function nias_drip_when_label($ts)
{
    $days = array('یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه');
    $ymd  = wp_date('Y-m-d', $ts);
    $date = function_exists('nias_cert_jalali') ? nias_cert_jalali($ymd, 'long') : $ymd;
    $out  = $days[(int) wp_date('w', $ts)] . ' ' . $date . '، ' . __('ساعت', 'nias-course-widget') . ' ' . wp_date('H:i', $ts);
    return function_exists('nias_fa_digits') ? nias_fa_digits($out) : $out;
}

/** "۱۵ آبان، ۰۷:۰۵" (lesson list). */
function nias_drip_short_label($ts)
{
    $ymd  = wp_date('Y-m-d', $ts);
    $date = function_exists('nias_cert_jalali') ? preg_replace('/\s*\S+$/u', '', nias_cert_jalali($ymd, 'long')) : wp_date('j M', $ts);
    $out  = $date . '، ' . wp_date('H:i', $ts);
    return function_exists('nias_fa_digits') ? nias_fa_digits($out) : $out;
}

/** "روز ۴، ساعت ۰۷:۰۵" (relative, for people who did not enrol yet). */
function nias_drip_rel_label($cfg)
{
    $out = sprintf(__('روز %s پس از ثبت‌نام', 'nias-course-widget'), $cfg['day']) . ($cfg['time'] !== '' ? '، ' . __('ساعت', 'nias-course-widget') . ' ' . $cfg['time'] : '');
    return function_exists('nias_fa_digits') ? nias_fa_digits($out) : $out;
}

/**
 * Is the lesson still closed for this user?
 *
 * @return array|null null = open; else {at (0 = not enrolled), day, time, label}
 */
function nias_drip_state($user_id, $post_id, $cfg, $enrolled = null)
{
    if (empty($cfg['on']) || !nias_drip_enabled() || nias_drip_bypass($user_id, $post_id)) {
        return null;
    }
    if ($enrolled === null) {
        $enrolled = nias_course_enrolled_at($user_id, $post_id);
    }
    if (!$enrolled) {
        return array('at' => 0, 'day' => $cfg['day'], 'time' => $cfg['time'], 'label' => nias_drip_rel_label($cfg));
    }
    $at = nias_drip_open_ts($enrolled, $cfg['day'], $cfg['time']);
    if ($at <= time()) {
        return null;
    }
    return array('at' => $at, 'day' => $cfg['day'], 'time' => $cfg['time'], 'label' => nias_drip_when_label($at));
}

/**
 * Older views (Elementor widget, account tab) loop over stored lessons: a
 * closed drip lesson loses its media and content there.
 */
function nias_drip_filter_raw_lesson($lesson, $post_id)
{
    if (!is_array($lesson) || !$post_id) {
        return $lesson;
    }
    $st = nias_drip_state(get_current_user_id(), $post_id, nias_drip_cfg_raw($lesson));
    if (!$st) {
        return $lesson;
    }
    $lesson['lesson_preview_video'] = array();
    $lesson['lesson_download']      = array();
    $lesson['lesson_content']       = '<p class="nias-drip-note">' . esc_html($st['at'] ? sprintf(__('این جلسه %s باز می‌شود.', 'nias-course-widget'), $st['label']) : sprintf(__('این جلسه طبق برنامهٔ دوره (%s) باز می‌شود.', 'nias-course-widget'), $st['label'])) . '</p>';
    $lesson['lesson_label']         = $st['at'] ? sprintf(__('باز می‌شود: %s', 'nias-course-widget'), $st['label']) : $st['label'];
    return $lesson;
}

/* -------------------------------------------------------------------------
 * Lesson quizzes: no answers to a quiz whose lessons are all still closed
 * ---------------------------------------------------------------------- */

add_filter('nias_quiz_submit_blocked', 'nias_drip_quiz_blocked', 10, 3);
function nias_drip_quiz_blocked($blocked, $quiz_id, $user_id)
{
    if ($blocked || !nias_drip_enabled()) {
        return $blocked;
    }
    $courses = array_map('intval', (array) get_post_meta($quiz_id, '_nias_quiz_in_course', false));
    $closed  = null;
    foreach ($courses as $pid) {
        foreach (nias_curriculum_sections_to_js((array) carbon_get_post_meta($pid, 'course_sections')) as $ch) {
            foreach ((array) $ch['lessons'] as $l) {
                if ((int) $l['quiz'] !== (int) $quiz_id) {
                    continue;
                }
                $st = nias_drip_state($user_id, $pid, nias_drip_cfg_js($l));
                if (!$st) {
                    return $blocked; // open in at least one lesson
                }
                $closed = $st;
            }
        }
    }
    if ($closed) {
        return $closed['at']
            ? sprintf(__('این آزمون %s باز می‌شود.', 'nias-course-widget'), $closed['label'])
            : __('این آزمون پس از ثبت‌نام در دوره، طبق برنامهٔ دوره باز می‌شود.', 'nias-course-widget');
    }
    return $blocked;
}

/* -------------------------------------------------------------------------
 * «جلسهٔ جدید باز شد» notifications
 * ---------------------------------------------------------------------- */

/** Earliest future opening of a user across their courses → _nias_drip_next. */
function nias_drip_recompute_next($user_id)
{
    $next = 0;
    $now  = time();
    foreach ((array) get_user_meta($user_id, '_nias_drip_courses', true) as $pid) {
        $pid = (int) $pid;
        $en  = (int) get_user_meta($user_id, nias_drip_key('enroll', $pid), true);
        if (!$pid || !$en || get_user_meta($user_id, nias_drip_key('dripopen', $pid), true) === '1' || get_post_status($pid) !== 'publish') {
            continue;
        }
        foreach (nias_drip_course_lessons($pid) as $l) {
            if (!$l['cfg']['on']) {
                continue;
            }
            $at = nias_drip_open_ts($en, $l['cfg']['day'], $l['cfg']['time']);
            if ($at > $now && (!$next || $at < $next)) {
                $next = $at;
            }
        }
    }
    if ($next) {
        update_user_meta($user_id, '_nias_drip_next', $next);
    } else {
        delete_user_meta($user_id, '_nias_drip_next');
    }
    return $next;
}

add_filter('cron_schedules', function ($s) {
    $s['nias_5min'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Nias: every 5 minutes');
    return $s;
});

add_action('init', function () {
    $on = nias_drip_enabled();
    $at = wp_next_scheduled('nias_drip_tick');
    if ($on && !$at) {
        wp_schedule_event(time() + 60, 'nias_5min', 'nias_drip_tick');
    } elseif (!$on && $at) {
        wp_clear_scheduled_hook('nias_drip_tick');
    }
}, 30);

/** Students whose next opening has passed: announce it, find the next one. */
add_action('nias_drip_tick', 'nias_drip_tick');
function nias_drip_tick()
{
    if (!nias_drip_enabled()) {
        return;
    }
    $now = time();
    $ids = get_users(array(
        'fields'     => 'ID',
        'number'     => (int) apply_filters('nias_drip_tick_batch', 200),
        'meta_query' => array(array('key' => '_nias_drip_next', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC')),
        'orderby'    => 'ID',
        'count_total'=> false,
    ));
    foreach ($ids as $uid) {
        nias_drip_process_user((int) $uid, $now);
    }
}

function nias_drip_process_user($user_id, $now)
{
    foreach ((array) get_user_meta($user_id, '_nias_drip_courses', true) as $pid) {
        $pid  = (int) $pid;
        $en   = (int) get_user_meta($user_id, nias_drip_key('enroll', $pid), true);
        $last = (int) get_user_meta($user_id, nias_drip_key('driplast', $pid), true);
        $last = $last ? $last : $en;
        if (!$en || get_post_status($pid) !== 'publish' || get_user_meta($user_id, nias_drip_key('dripopen', $pid), true) === '1') {
            continue;
        }
        $opened = array();
        foreach (nias_drip_course_lessons($pid) as $l) {
            if (!$l['cfg']['on']) {
                continue;
            }
            $at = nias_drip_open_ts($en, $l['cfg']['day'], $l['cfg']['time']);
            if ($at > $last && $at <= $now) {
                $opened[] = $l['title'];
            }
        }
        update_user_meta($user_id, nias_drip_key('driplast', $pid), $now);
        if ($opened && function_exists('nias_user_has_course_access') && nias_user_has_course_access($user_id, $pid)) {
            do_action('nias_drip_lessons_opened', $user_id, $pid, $opened);
        }
    }
    nias_drip_recompute_next($user_id);
}

/** E-mail (optional; the SMS event lives in the SMS module). */
add_action('nias_drip_lessons_opened', 'nias_drip_mail', 10, 3);
function nias_drip_mail($user_id, $post_id, $titles)
{
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_drip_email') !== 'on') {
        return;
    }
    $u = get_userdata($user_id);
    if (!$u || !is_email($u->user_email)) {
        return;
    }
    $course = get_the_title($post_id);
    $link   = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($post_id) : get_permalink($post_id);
    $subj   = sprintf(__('جلسهٔ جدید دورهٔ «%s» باز شد', 'nias-course-widget'), $course);
    $items  = '';
    foreach ($titles as $t) {
        $items .= '<li>' . esc_html($t) . '</li>';
    }
    $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:2;color:#1f2a30">'
        . '<p>' . esc_html(sprintf(__('%s عزیز،', 'nias-course-widget'), $u->display_name)) . '</p>'
        . '<p>' . esc_html(sprintf(_n('این جلسه از دورهٔ «%s» برای شما باز شد:', 'این جلسه‌ها از دورهٔ «%s» برای شما باز شدند:', count($titles), 'nias-course-widget'), $course)) . '</p>'
        . '<ul>' . $items . '</ul>'
        . '<p><a href="' . esc_url($link) . '" style="display:inline-block;background:#1e83f0;color:#fff;text-decoration:none;padding:8px 18px;border-radius:9px">' . esc_html__('ادامهٔ دوره', 'nias-course-widget') . '</a></p></div>';
    wp_mail($u->user_email, $subj, $body, array('Content-Type: text/html; charset=UTF-8'));
}

/** The schedule of a course changed: students waiting on it are re-checked at the next tick. */
add_action('nias_course_sections_saved', function ($post_id) {
    global $wpdb;
    nias_drip_course_lessons($post_id, true);
    $key = nias_drip_key('enroll', $post_id);
    // One query: everyone enrolled in this course gets "check now".
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->usermeta} n JOIN {$wpdb->usermeta} e ON e.user_id = n.user_id AND e.meta_key = %s SET n.meta_value = %d WHERE n.meta_key = '_nias_drip_next'",
        $key,
        time()
    ));
    // Students without a pending opening (all open so far) may get new ones.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT e.user_id, '_nias_drip_next', %d FROM {$wpdb->usermeta} e LEFT JOIN {$wpdb->usermeta} n ON n.user_id = e.user_id AND n.meta_key = '_nias_drip_next' WHERE e.meta_key = %s AND n.umeta_id IS NULL",
        time(),
        $key
    ));
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('user_meta');
    }
}, 20);

/* -------------------------------------------------------------------------
 * Backfill: students who bought before the module was switched on
 * ---------------------------------------------------------------------- */

add_action('update_option__nias_drip_enabled', function ($old, $new) {
    if ($new === 'on' && $old !== 'on' && function_exists('wc_get_orders') && !wp_next_scheduled('nias_drip_backfill', array(1))) {
        wp_schedule_single_event(time() + 10, 'nias_drip_backfill', array(1));
    }
}, 10, 2);
add_action('add_option__nias_drip_enabled', function ($name, $value) {
    if ($value === 'on' && function_exists('wc_get_orders') && !wp_next_scheduled('nias_drip_backfill', array(1))) {
        wp_schedule_single_event(time() + 10, 'nias_drip_backfill', array(1));
    }
}, 10, 2);

add_action('nias_drip_backfill', 'nias_drip_backfill');
function nias_drip_backfill($page)
{
    if (!function_exists('wc_get_orders') || !nias_drip_enabled()) {
        return;
    }
    $orders = wc_get_orders(array('status' => 'wc-completed', 'limit' => 50, 'paged' => (int) $page, 'orderby' => 'date', 'order' => 'ASC'));
    foreach ($orders as $order) {
        $uid = (int) $order->get_customer_id();
        if (!$uid) {
            continue;
        }
        $d  = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
        $ts = $d ? $d->getTimestamp() : time();
        foreach ($order->get_items() as $item) {
            $pid = (int) $item->get_product_id();
            if ($pid && nias_is_course_post($pid) && !get_user_meta($uid, nias_drip_key('enroll', $pid), true)) {
                nias_drip_set_enrolled($uid, $pid, $ts);
            }
        }
    }
    if (count($orders) === 50) {
        wp_schedule_single_event(time() + 30, 'nias_drip_backfill', array((int) $page + 1));
    }
}

/* -------------------------------------------------------------------------
 * Student schedule tool (admin «ماژول‌ها» page + instructor panel)
 * ---------------------------------------------------------------------- */

function nias_drip_can_manage($post_id, $user_id = 0)
{
    $user_id = $user_id ? $user_id : get_current_user_id();
    return $user_id && $post_id && nias_is_course_post($post_id)
        && (user_can($user_id, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id)));
}

/** The tool's markup. $post_id = 0 shows a course picker (admins). */
function nias_drip_tool_html($post_id = 0)
{
    $nonce = wp_create_nonce('nias_drip_tool');
    $h  = '<div class="ndt" data-ndt data-nonce="' . esc_attr($nonce) . '" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-course="' . (int) $post_id . '">';
    if (!$post_id) {
        $h .= '<div class="ndt-f"><label>' . esc_html__('دوره', 'nias-course-widget') . '</label><div data-ndt-course></div></div>';
    }
    $h .= '<div class="ndt-f"><label>' . esc_html__('دانشجو', 'nias-course-widget') . '</label><input type="search" class="ndt-in" data-ndt-q placeholder="' . esc_attr__('نام، نام کاربری، ایمیل یا موبایل دانشجوی ثبت‌نام‌شده…', 'nias-course-widget') . '"' . ($post_id ? '' : ' disabled') . '><div class="ndt-res" data-ndt-res></div></div>';
    $h .= '<div class="ndt-card" data-ndt-card hidden></div></div>';
    return $h . nias_drip_tool_assets();
}

function nias_drip_tool_assets()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    ob_start();
    ?>
    <style>
    .ndt{display:flex;flex-direction:column;gap:12px;direction:rtl}
    .ndt-f label{display:block;font-weight:700;font-size:13px;margin-bottom:6px;color:#1f2733}
    .ndt-in{width:100%;max-width:520px;box-sizing:border-box;border:1px solid #dbe3ef;border-radius:10px;padding:8px 12px;font:inherit}
    .ndt-res{max-width:520px;margin-top:6px;display:flex;flex-direction:column;gap:4px}
    .ndt-res button{display:flex;justify-content:space-between;gap:8px;text-align:right;border:1px solid #e6ecf5;background:#fff;border-radius:9px;padding:8px 12px;font:inherit;cursor:pointer}
    .ndt-res button:hover{border-color:#3858e9;background:#f5f8ff}
    .ndt-res small{color:#64748b;direction:ltr}
    .ndt-card{border:1px solid #e6ecf5;border-radius:14px;padding:14px;background:#fbfcfe}
    .ndt-head{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin-bottom:10px;font-size:13.5px}
    .ndt-head b{font-size:15px}
    .ndt-chip{display:inline-block;border-radius:99px;padding:2px 10px;font-size:12px;font-weight:700;background:#eef5ff;color:#1e4fb8}
    .ndt-chip.ok{background:#e9f8ee;color:#137a3a}
    .ndt-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:10px;overflow:hidden}
    .ndt-tbl th,.ndt-tbl td{padding:8px 10px;border-bottom:1px solid #eef1f6;text-align:right}
    .ndt-tbl th{background:#f5f7fb;font-weight:700;color:#475569}
    .ndt-st{font-weight:700}.ndt-st.open{color:#137a3a}.ndt-st.wait{color:#b45309}
    .ndt-act{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
    .ndt-act button{border:1px solid #dbe3ef;background:#fff;border-radius:9px;padding:6px 12px;font:inherit;font-weight:700;cursor:pointer;color:#1f2733}
    .ndt-act button.pri{background:#3858e9;border-color:#3858e9;color:#fff}
    .ndt-act input{width:80px;border:1px solid #dbe3ef;border-radius:9px;padding:6px 8px;font:inherit}
    .ndt-msg{font-size:12.5px;color:#137a3a}
    .ndt-empty{color:#64748b;font-size:13px}
    </style>
    <script>
    (function () {
        function boot(root) {
            if (root._ndt) return; root._ndt = 1;
            var ajax = root.getAttribute('data-ajax'), nonce = root.getAttribute('data-nonce'), course = +root.getAttribute('data-course') || 0;
            var q = root.querySelector('[data-ndt-q]'), res = root.querySelector('[data-ndt-res]'), card = root.querySelector('[data-ndt-card]'), cur = 0, t = null;
            function post(data) {
                var b = new URLSearchParams(); b.append('nonce', nonce); b.append('course', course);
                Object.keys(data).forEach(function (k) { b.append(k, data[k]); });
                return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); });
            }
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            var cp = root.querySelector('[data-ndt-course]');
            if (cp && window.NiasPicker) {
                window.NiasPicker.attach(cp, { source: 'product', emptyLabel: 'یک دوره انتخاب کنید…', placeholder: 'جستجوی نام دوره…', clearable: false, value: [],
                    onChange: function (v) { course = v[0] ? +v[0].id : 0; q.disabled = !course; q.value = ''; res.innerHTML = ''; card.hidden = true; if (course) q.focus(); } });
            }
            q.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(function () {
                    if (!course) return;
                    post({ action: 'nias_drip_students', q: q.value }).then(function (r) {
                        var list = (r && r.success && r.data) || [];
                        res.innerHTML = list.length ? list.map(function (u) { return '<button type="button" data-uid="' + u.id + '"><span>' + esc(u.name) + '</span><small>' + esc(u.sub) + '</small></button>'; }).join('')
                            : '<div class="ndt-empty">' + (q.value ? 'دانشجوی ثبت‌نام‌شده‌ای با این مشخصات پیدا نشد.' : 'هنوز دانشجویی در این دوره ثبت‌نام نکرده است.') + '</div>';
                    });
                }, 250);
            });
            q.addEventListener('focus', function () { if (!res.innerHTML) q.dispatchEvent(new Event('input')); });
            res.addEventListener('click', function (e) { var b = e.target.closest('[data-uid]'); if (b) { cur = +b.getAttribute('data-uid'); res.innerHTML = ''; load(); } });
            function load(msg) {
                post({ action: 'nias_drip_student', user: cur }).then(function (r) { if (r && r.success) show(r.data, msg); else { card.hidden = false; card.innerHTML = '<div class="ndt-empty">' + esc(r && r.data && r.data.message || 'خطا') + '</div>'; } });
            }
            function show(d, msg) {
                card.hidden = false;
                var rows = d.lessons.map(function (l) {
                    return '<tr><td>' + esc(l.title) + '</td><td>' + esc(l.rule) + '</td><td>' + esc(l.when) + '</td><td><span class="ndt-st ' + (l.open ? 'open' : 'wait') + '">' + (l.open ? 'باز' : 'در انتظار') + '</span></td></tr>';
                }).join('');
                card.innerHTML =
                    '<div class="ndt-head"><b>' + esc(d.name) + '</b><span>شروع دوره: ' + esc(d.start) + '</span>' + (d.unlocked ? '<span class="ndt-chip ok">همهٔ جلسات برای این دانشجو باز است</span>' : '<span class="ndt-chip">' + d.openCount + ' از ' + d.total + ' جلسهٔ قطره‌ای باز است</span>') + '</div>' +
                    (d.lessons.length ? '<table class="ndt-tbl"><thead><tr><th>جلسه</th><th>برنامه</th><th>زمان باز شدن</th><th>وضعیت</th></tr></thead><tbody>' + rows + '</tbody></table>' : '<div class="ndt-empty">این دوره جلسهٔ قطره‌ای ندارد.</div>') +
                    '<div class="ndt-act">' +
                        (d.unlocked ? '<button type="button" data-do="relock">برگرداندن برنامهٔ قطره‌ای</button>' : '<button type="button" class="pri" data-do="unlock">باز کردن همهٔ جلسات</button>') +
                        '<span>جابه‌جایی شروع:</span><input type="number" data-shift value="1" step="1"><span>روز</span><button type="button" data-do="shift">اعمال</button>' +
                        '<button type="button" data-do="restart">شروع دوباره از همین حالا</button>' +
                        (msg ? '<span class="ndt-msg">' + esc(msg) + '</span>' : '') +
                    '</div>' +
                    '<div class="ndt-empty" style="margin-top:6px">عدد مثبت شروع را عقب می‌برد (جلسات دیرتر باز می‌شوند)، عدد منفی جلو می‌آورد.</div>';
                card.querySelectorAll('[data-do]').forEach(function (b) {
                    b.addEventListener('click', function () {
                        var act = b.getAttribute('data-do'), days = act === 'shift' ? (parseInt(card.querySelector('[data-shift]').value, 10) || 0) : 0;
                        if (act === 'restart' && !confirm('شروع دورهٔ این دانشجو از همین حالا حساب شود؟ جلسات قطره‌ای دوباره به ترتیب باز می‌شوند.')) return;
                        b.disabled = true;
                        post({ action: 'nias_drip_student_set', user: cur, op: act, days: days }).then(function (r) { load(r && r.success ? 'ذخیره شد.' : (r && r.data && r.data.message) || 'خطا'); });
                    });
                });
            }
        }
        function all() { document.querySelectorAll('[data-ndt]').forEach(boot); }
        if (document.readyState !== 'loading') all(); else document.addEventListener('DOMContentLoaded', all);
    })();
    </script>
    <?php
    return ob_get_clean();
}

function nias_drip_tool_request()
{
    $pid = isset($_POST['course']) ? (int) $_POST['course'] : 0;
    if (!check_ajax_referer('nias_drip_tool', 'nonce', false) || !nias_drip_can_manage($pid)) {
        wp_send_json_error(array('message' => __('دسترسی ندارید.', 'nias-course-widget')), 403);
    }
    return $pid;
}

add_action('wp_ajax_nias_drip_students', function () {
    $pid = nias_drip_tool_request();
    $q   = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $args = array(
        'number'      => 10,
        'fields'      => array('ID', 'display_name', 'user_login', 'user_email'),
        'meta_key'    => nias_drip_key('enroll', $pid),
        'orderby'     => 'meta_value_num',
        'order'       => 'DESC',
        'count_total' => false,
    );
    if ($q !== '') {
        $by_phone = function_exists('nias_find_user_by_mobile') && preg_match('/^[0-9۰-۹+]{6,}$/u', $q) ? nias_find_user_by_mobile($q) : null;
        if ($by_phone) {
            $args['include'] = array(is_object($by_phone) ? (int) $by_phone->ID : (int) $by_phone);
        } else {
            $args['search']         = '*' . $q . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }
    }
    $out = array();
    foreach (get_users($args) as $u) {
        $out[] = array('id' => (int) $u->ID, 'name' => $u->display_name, 'sub' => $u->user_login);
    }
    wp_send_json_success($out);
});

add_action('wp_ajax_nias_drip_student', function () {
    $pid = nias_drip_tool_request();
    $uid = isset($_POST['user']) ? (int) $_POST['user'] : 0;
    $en  = $uid ? (int) get_user_meta($uid, nias_drip_key('enroll', $pid), true) : 0;
    $u   = $uid ? get_userdata($uid) : null;
    if (!$u || !$en) {
        wp_send_json_error(array('message' => __('این کاربر در این دوره ثبت‌نام نکرده است.', 'nias-course-widget')));
    }
    $unlocked = get_user_meta($uid, nias_drip_key('dripopen', $pid), true) === '1';
    $rows = array();
    $open = 0;
    foreach (nias_drip_course_lessons($pid) as $l) {
        if (!$l['cfg']['on']) {
            continue;
        }
        $at = nias_drip_open_ts($en, $l['cfg']['day'], $l['cfg']['time']);
        $is = $unlocked || $at <= time();
        $open += $is ? 1 : 0;
        $rows[] = array(
            'title' => $l['title'],
            'rule'  => nias_fa_digits(sprintf(__('روز %s', 'nias-course-widget'), $l['cfg']['day']) . ($l['cfg']['time'] !== '' ? ' · ' . $l['cfg']['time'] : '')),
            'when'  => nias_drip_when_label($at),
            'open'  => $is,
        );
    }
    wp_send_json_success(array(
        'name'      => $u->display_name,
        'start'     => nias_drip_when_label($en),
        'unlocked'  => $unlocked,
        'openCount' => nias_fa_digits($open),
        'total'     => nias_fa_digits(count($rows)),
        'lessons'   => $rows,
    ));
});

add_action('wp_ajax_nias_drip_student_set', function () {
    $pid = nias_drip_tool_request();
    $uid = isset($_POST['user']) ? (int) $_POST['user'] : 0;
    $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    $en  = $uid ? (int) get_user_meta($uid, nias_drip_key('enroll', $pid), true) : 0;
    if (!$en) {
        wp_send_json_error(array('message' => __('این کاربر در این دوره ثبت‌نام نکرده است.', 'nias-course-widget')));
    }
    if ($op === 'unlock') {
        update_user_meta($uid, nias_drip_key('dripopen', $pid), '1');
    } elseif ($op === 'relock') {
        delete_user_meta($uid, nias_drip_key('dripopen', $pid));
        update_user_meta($uid, nias_drip_key('driplast', $pid), time());
    } elseif ($op === 'shift') {
        $days = max(-3650, min(3650, isset($_POST['days']) ? (int) $_POST['days'] : 0));
        update_user_meta($uid, nias_drip_key('enroll', $pid), $en + $days * DAY_IN_SECONDS);
        update_user_meta($uid, nias_drip_key('driplast', $pid), time());
    } elseif ($op === 'restart') {
        update_user_meta($uid, nias_drip_key('enroll', $pid), time());
        update_user_meta($uid, nias_drip_key('driplast', $pid), time());
    } else {
        wp_send_json_error(array('message' => __('عملیات نامعتبر است.', 'nias-course-widget')));
    }
    nias_drip_recompute_next($uid);
    do_action('nias_drip_student_changed', $uid, $pid, $op, get_current_user_id());
    wp_send_json_success();
});
