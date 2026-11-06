<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Course SMS notifications for students and instructors — through Nias Login.
 *
 * Nothing here talks to an SMS gateway directly: every message goes through
 * the developer API of the «نیاس لاگین» plugin (nias_sms_send_pattern /
 * nias_sms_send_text), so the gateway, API key, line number and message log
 * are the ones configured in Nias Login. Without Nias Login (or with an older
 * version without that API) no event sends anything and the settings page
 * says so.
 *
 * Each event: on/off, pattern code + ordered variables (name=token lines; named
 * and positional gateways both work when the order matches the pattern), or
 * free text when the gateway supports it. Messages are queued with
 * wp_schedule_single_event so pages never wait for the gateway.
 *
 * @package nias-course-widget
 */

define('NIAS_SMS_OPTION', '_nias_sms_events');

/** Nias Login with its SMS developer API is active. */
function nias_sms_available()
{
    return function_exists('nias_sms_send_pattern') && function_exists('nias_sms_send_text') && function_exists('nias_user_mobile');
}

/** …and a gateway is selected in Nias Login. */
function nias_sms_ready()
{
    return nias_sms_available() && function_exists('nias_sms_is_configured') && nias_sms_is_configured();
}

/** Does the selected gateway accept free text? */
function nias_sms_supports_text()
{
    $gw = function_exists('nias_sms_gateway') ? nias_sms_gateway() : null;
    return $gw && !empty($gw['supports_text']);
}

/**
 * Event catalogue: label, who receives it, tokens, defaults.
 *
 * @return array
 */
function nias_sms_events()
{
    return array(
        's_enrolled' => array(
            'to' => 'student', 'label' => __('ثبت‌نام در دوره', 'nias-course-widget'),
            'desc' => __('پس از تکمیل سفارش دوره (یا شروع دورهٔ رایگان) برای دانشجو.', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'link'),
            'text' => __('{name} عزیز، ثبت‌نام شما در دورهٔ «{course}» انجام شد. ورود به دوره: {link}', 'nias-course-widget'),
        ),
        's_essay_graded' => array(
            'to' => 'student', 'label' => __('اعلام نمرهٔ آزمون', 'nias-course-widget'),
            'desc' => __('وقتی مدرس پاسخ تشریحی دانشجو را تصحیح می‌کند.', 'nias-course-widget'),
            'tokens' => array('name', 'quiz', 'score', 'status', 'course', 'link'),
            'text' => __('{name} عزیز، نمرهٔ شما در «{quiz}»: {score}٪ ({status}). {link}', 'nias-course-widget'),
        ),
        's_qa_answered' => array(
            'to' => 'student', 'label' => __('پاسخ مدرس به پرسش', 'nias-course-widget'),
            'desc' => __('وقتی مدرس به پرسش دانشجو در پرسش و پاسخ پاسخ می‌دهد.', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'lesson', 'link'),
            'text' => __('{name} عزیز، مدرس به پرسش شما در دورهٔ «{course}» پاسخ داد. {link}', 'nias-course-widget'),
        ),
        's_certificate' => array(
            'to' => 'student', 'label' => __('صدور مدرک', 'nias-course-widget'),
            'desc' => __('وقتی مدرک پایان دوره برای دانشجو صادر می‌شود.', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'code', 'link'),
            'text' => __('{name} عزیز، مدرک دورهٔ «{course}» صادر شد. کد مدرک: {code} {link}', 'nias-course-widget'),
        ),
        's_drip_open' => array(
            'to' => 'student', 'label' => __('باز شدن جلسهٔ قطره‌ای', 'nias-course-widget'),
            'desc' => __('وقتی جلسهٔ جدیدی از دورهٔ قطره‌ای برای دانشجو باز می‌شود (ماژول «محتوای قطره‌ای»).', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'lesson', 'link'),
            'text' => __('{name} عزیز، جلسهٔ «{lesson}» از دورهٔ «{course}» برای شما باز شد. {link}', 'nias-course-widget'),
        ),
        's_access_expiring' => array(
            'to' => 'student', 'label' => __('یادآوری پایان دسترسی', 'nias-course-widget'),
            'desc' => __('چند روز مانده به پایان دسترسی زمان‌دار (ماژول «دسترسی زمان‌دار و تمدید»).', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'days', 'date', 'link'),
            'text' => __('{name} عزیز، دسترسی شما به دورهٔ «{course}» {days} روز دیگر ({date}) تمام می‌شود. تمدید: {link}', 'nias-course-widget'),
        ),
        's_access_expired' => array(
            'to' => 'student', 'label' => __('پایان دسترسی', 'nias-course-widget'),
            'desc' => __('روزی که دسترسی زمان‌دار دانشجو تمام می‌شود.', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'link'),
            'text' => __('{name} عزیز، دسترسی شما به دورهٔ «{course}» به پایان رسید. برای تمدید: {link}', 'nias-course-widget'),
        ),
        't_new_student' => array(
            'to' => 'teacher', 'label' => __('دانشجوی جدید', 'nias-course-widget'),
            'desc' => __('وقتی کسی در یکی از دوره‌های مدرس ثبت‌نام می‌کند.', 'nias-course-widget'),
            'tokens' => array('name', 'student', 'course'),
            'text' => __('{name} عزیز، {student} در دورهٔ «{course}» ثبت‌نام کرد.', 'nias-course-widget'),
        ),
        't_new_question' => array(
            'to' => 'teacher', 'label' => __('پرسش جدید', 'nias-course-widget'),
            'desc' => __('وقتی دانشجو در دورهٔ مدرس پرسش (یا ادامهٔ پرسش) ثبت می‌کند.', 'nias-course-widget'),
            'tokens' => array('name', 'student', 'course', 'lesson', 'link'),
            'text' => __('{name} عزیز، {student} در دورهٔ «{course}» پرسشی ثبت کرد. پاسخ: {link}', 'nias-course-widget'),
        ),
        't_essay_submitted' => array(
            'to' => 'teacher', 'label' => __('آزمون منتظر تصحیح', 'nias-course-widget'),
            'desc' => __('وقتی دانشجو آزمونِ دارای سوال تشریحی را می‌فرستد.', 'nias-course-widget'),
            'tokens' => array('name', 'student', 'quiz', 'course', 'link'),
            'text' => __('{name} عزیز، {student} آزمون «{quiz}» را فرستاد و منتظر تصحیح شماست. {link}', 'nias-course-widget'),
        ),
        't_review' => array(
            'to' => 'teacher', 'label' => __('نتیجهٔ بررسی دوره', 'nias-course-widget'),
            'desc' => __('وقتی مدیر دوره یا تغییرات مدرس را تأیید یا رد می‌کند («نیاز به تأیید»).', 'nias-course-widget'),
            'tokens' => array('name', 'course', 'status', 'link'),
            'text' => __('{name} عزیز، {status}: «{course}». {link}', 'nias-course-widget'),
        ),
    );
}

/** Token labels for the settings page. */
function nias_sms_token_labels()
{
    return array(
        'name' => __('نام گیرنده', 'nias-course-widget'), 'student' => __('نام دانشجو', 'nias-course-widget'), 'course' => __('نام دوره', 'nias-course-widget'),
        'lesson' => __('نام جلسه', 'nias-course-widget'), 'quiz' => __('نام آزمون', 'nias-course-widget'), 'score' => __('نمره', 'nias-course-widget'),
        'status' => __('وضعیت', 'nias-course-widget'), 'code' => __('کد مدرک', 'nias-course-widget'), 'link' => __('لینک', 'nias-course-widget'),
        'days' => __('روزهای باقی‌مانده', 'nias-course-widget'), 'date' => __('تاریخ پایان', 'nias-course-widget'),
    );
}

/** Settings of one event (merged with defaults). */
function nias_sms_event_settings($key)
{
    $all = get_option(NIAS_SMS_OPTION, array());
    $ev  = nias_sms_events();
    $def = array(
        'on' => 'off', 'mode' => 'pattern', 'pattern' => '',
        'params' => implode("\n", array_map(function ($t) {
            return $t . '={' . $t . '}';
        }, isset($ev[$key]) ? $ev[$key]['tokens'] : array())),
        'text' => isset($ev[$key]) ? $ev[$key]['text'] : '',
    );
    return array_merge($def, isset($all[$key]) && is_array($all[$key]) ? $all[$key] : array());
}

/** Replace {tokens}. */
function nias_sms_fill($tpl, $vars)
{
    return strtr((string) $tpl, array_combine(array_map(function ($k) {
        return '{' . $k . '}';
    }, array_keys($vars)), array_map('strval', array_values($vars))));
}

/** Ordered pattern variables from "key={token}" lines. */
function nias_sms_pattern_params($lines, $vars)
{
    $out = array();
    foreach (preg_split('~\r?\n~', (string) $lines) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        if ($k !== '') {
            $out[$k] = nias_sms_fill($v, $vars);
        }
    }
    return $out;
}

/** A user's mobile: the Nias Login account number, else the billing phone. */
function nias_sms_user_phone($user_id)
{
    if (!$user_id || !nias_sms_available()) {
        return '';
    }
    $p = nias_user_mobile((int) $user_id);
    if ($p === '' && function_exists('nias_normalize_mobile')) {
        $p = nias_normalize_mobile((string) get_user_meta((int) $user_id, 'billing_phone', true));
    }
    return $p;
}

/**
 * Queue an event for a user (sent by WP-Cron through Nias Login).
 *
 * @param string $key  event key
 * @param int    $user_id recipient
 * @param array  $vars token values ('name' is filled from the user)
 */
function nias_sms_queue($key, $user_id, $vars)
{
    if (!nias_sms_ready()) {
        return false;
    }
    $s = nias_sms_event_settings($key);
    if ($s['on'] !== 'on' || !$user_id) {
        return false;
    }
    $u = get_userdata((int) $user_id);
    if (!$u) {
        return false;
    }
    $vars = array_merge(array('name' => $u->display_name), (array) $vars);
    $vars = apply_filters('nias_course_sms_vars', $vars, $key, $user_id);
    wp_schedule_single_event(time(), 'nias_course_sms_job', array($key, (int) $user_id, $vars, wp_generate_password(6, false)));
    return true;
}

add_action('nias_course_sms_job', 'nias_sms_run_job', 10, 4);
function nias_sms_run_job($key, $user_id, $vars)
{
    if (!nias_sms_ready()) {
        return;
    }
    $phone = nias_sms_user_phone($user_id);
    if ($phone === '') {
        return;
    }
    nias_sms_deliver($key, $phone, $vars, (int) $user_id);
}

/**
 * Send now (job or «ارسال آزمایشی»).
 *
 * @return true|WP_Error
 */
function nias_sms_deliver($key, $phone, $vars, $user_id = 0)
{
    $ev = nias_sms_events();
    if (!isset($ev[$key]) || !nias_sms_available()) {
        return new WP_Error('nias_sms_off', __('نیاس لاگین فعال نیست.', 'nias-course-widget'));
    }
    $s     = nias_sms_event_settings($key);
    $label = sprintf(__('دوره ساز نیاس: %s', 'nias-course-widget'), $ev[$key]['label']);
    if ($s['mode'] === 'text' && nias_sms_supports_text()) {
        return nias_sms_send_text($phone, nias_sms_fill($s['text'], $vars), array('label' => $label, 'user_id' => $user_id));
    }
    if (trim($s['pattern']) === '') {
        return new WP_Error('nias_sms_no_pattern', __('کد پترن این رویداد وارد نشده است.', 'nias-course-widget'));
    }
    return nias_sms_send_pattern($phone, trim($s['pattern']), nias_sms_pattern_params($s['params'], $vars), array('label' => $label, 'user_id' => $user_id));
}

/* -------------------------------------------------------------------------
 * Events → messages
 * ---------------------------------------------------------------------- */

/** Course link for a student (modern view) and for an instructor (panel). */
function nias_sms_course_link($post_id)
{
    $url = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url((int) $post_id) : get_permalink((int) $post_id);
    return apply_filters('nias_course_sms_link', $url, $post_id);
}
function nias_sms_panel_link($view = '')
{
    $page = function_exists('nias_instructor_dashboard_page_id') ? nias_instructor_dashboard_page_id() : 0;
    $url  = $page ? get_permalink($page) : home_url('/');
    $map  = array('qa' => array('nias_qa' => 1), 'results' => array('nias_qr' => 1));
    return isset($map[$view]) ? add_query_arg($map[$view], $url) : $url;
}

/** Each instructor of a course. */
function nias_sms_to_instructors($key, $post_id, $vars, $except = 0)
{
    foreach (function_exists('nias_product_instructors') ? nias_product_instructors((int) $post_id) : array() as $iid) {
        if ((int) $iid !== (int) $except) {
            nias_sms_queue($key, $iid, $vars);
        }
    }
}

// Enrolment: completed WooCommerce order with courses (once per order + course).
add_action('woocommerce_order_status_completed', function ($order_id) {
    if (!nias_sms_ready() || !function_exists('wc_get_order') || !($order = wc_get_order($order_id))) {
        return;
    }
    $uid  = (int) $order->get_customer_id();
    $done = (array) $order->get_meta('_nias_sms_enrolled');
    foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        if (!$pid || in_array($pid, $done, true) || !function_exists('nias_modern_course_has_curriculum') || !nias_modern_course_has_curriculum($pid)) {
            continue;
        }
        $done[] = $pid;
        $course = get_the_title($pid);
        if ($uid) {
            nias_sms_queue('s_enrolled', $uid, array('course' => $course, 'link' => nias_sms_course_link($pid)));
        }
        $student = $uid && ($u = get_userdata($uid)) ? $u->display_name : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        nias_sms_to_instructors('t_new_student', $pid, array('student' => $student, 'course' => $course), $uid);
    }
    $order->update_meta_data('_nias_sms_enrolled', $done);
    $order->save();
}, 30);

// Free «دوره»: the first time a student opens it.
add_action('nias_free_course_started', function ($user_id, $post_id) {
    $course = get_the_title($post_id);
    nias_sms_queue('s_enrolled', $user_id, array('course' => $course, 'link' => nias_sms_course_link($post_id)));
    $u = get_userdata($user_id);
    nias_sms_to_instructors('t_new_student', $post_id, array('student' => $u ? $u->display_name : '', 'course' => $course), $user_id);
}, 10, 2);

// Essay graded → student.
add_action('nias_quiz_attempt_graded', function ($user_id, $quiz_id, $attempt, $was_pending) {
    if (!$was_pending) {
        return;
    }
    $quiz = function_exists('nias_quiz_get') ? nias_quiz_get($quiz_id) : null;
    $courses = function_exists('nias_quiz_course_ids') ? nias_quiz_course_ids($quiz_id) : array();
    $passed = $quiz && (int) $attempt['final'] >= (int) $quiz['passScore'];
    nias_sms_queue('s_essay_graded', $user_id, array(
        'quiz' => $quiz ? $quiz['title'] : '', 'score' => (string) (int) $attempt['final'],
        'status' => $passed ? __('قبول', 'nias-course-widget') : __('مردود', 'nias-course-widget'),
        'course' => $courses ? get_the_title($courses[0]) : '', 'link' => $courses ? nias_sms_course_link($courses[0]) : home_url('/'),
    ));
}, 20, 4);

// Essay submitted → instructors of the quiz's courses.
add_action('nias_quiz_attempt_submitted', function ($user_id, $quiz_id, $attempt) {
    if (empty($attempt['status']) || $attempt['status'] !== 'pending') {
        return;
    }
    $quiz = function_exists('nias_quiz_get') ? nias_quiz_get($quiz_id) : null;
    $u = get_userdata($user_id);
    foreach (function_exists('nias_quiz_course_ids') ? nias_quiz_course_ids($quiz_id) : array() as $pid) {
        nias_sms_to_instructors('t_essay_submitted', $pid, array('student' => $u ? $u->display_name : '', 'quiz' => $quiz ? $quiz['title'] : '', 'course' => get_the_title($pid), 'link' => nias_sms_panel_link('results')), $user_id);
    }
}, 20, 3);

// Q&A.
add_action('nias_qa_question', function ($q) {
    if (!$q || $q['is_staff']) {
        return;
    }
    $u = get_userdata((int) $q['user_id']);
    nias_sms_to_instructors('t_new_question', $q['post_id'], array('student' => $u ? $u->display_name : '', 'course' => get_the_title((int) $q['post_id']), 'lesson' => $q['lesson_title'], 'link' => nias_sms_panel_link('qa')), (int) $q['user_id']);
}, 20);
add_action('nias_qa_reply', function ($reply, $q, $staff) {
    if ($staff && (int) $reply['user_id'] !== (int) $q['user_id']) {
        nias_sms_queue('s_qa_answered', (int) $q['user_id'], array('course' => get_the_title((int) $q['post_id']), 'lesson' => $q['lesson_title'], 'link' => nias_sms_course_link($q['post_id'])));
    } elseif (!$staff) {
        $u = get_userdata((int) $reply['user_id']);
        nias_sms_to_instructors('t_new_question', $q['post_id'], array('student' => $u ? $u->display_name : '', 'course' => get_the_title((int) $q['post_id']), 'lesson' => $q['lesson_title'], 'link' => nias_sms_panel_link('qa')), (int) $reply['user_id']);
    }
}, 20, 3);

// Certificate issued → student.
add_action('nias_certificate_issued', function ($row) {
    if (!is_array($row) || empty($row['user_id'])) {
        return;
    }
    $link = function_exists('nias_cert_verify_url') ? nias_cert_verify_url($row['code'], (int) $row['user_id']) : home_url('/');
    nias_sms_queue('s_certificate', (int) $row['user_id'], array('course' => get_the_title((int) $row['product_id']), 'code' => (string) $row['code'], 'link' => $link));
}, 20);

// Time-limited access: reminders before the end, and on the end day.
add_action('nias_access_expiring_notice', function ($user_id, $post_id, $until, $days) {
    nias_sms_queue('s_access_expiring', (int) $user_id, array('course' => get_the_title((int) $post_id), 'days' => nias_fa_digits($days), 'date' => function_exists('nias_access_date') ? nias_access_date($until) : '', 'link' => function_exists('nias_access_renew_url') ? nias_access_renew_url($post_id) : get_permalink($post_id)));
}, 20, 4);
add_action('nias_access_expired_notice', function ($user_id, $post_id) {
    nias_sms_queue('s_access_expired', (int) $user_id, array('course' => get_the_title((int) $post_id), 'link' => function_exists('nias_access_renew_url') ? nias_access_renew_url($post_id) : get_permalink($post_id)));
}, 20, 2);

// Drip content: a new lesson opened for the student.
add_action('nias_drip_lessons_opened', function ($user_id, $post_id, $titles) {
    $link = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($post_id) : get_permalink($post_id);
    nias_sms_queue('s_drip_open', (int) $user_id, array('course' => get_the_title((int) $post_id), 'lesson' => implode('، ', (array) $titles), 'link' => $link));
}, 20, 3);

// Instructor course review (approval).
add_action('nias_inst_reviewed', function ($user_id, $post_id, $approved, $kind) {
    $status = $kind === 'new'
        ? ($approved ? __('دورهٔ شما منتشر شد', 'nias-course-widget') : __('دورهٔ شما منتشر نشد', 'nias-course-widget'))
        : ($approved ? __('تغییرات شما تأیید شد', 'nias-course-widget') : __('تغییرات شما تأیید نشد', 'nias-course-widget'));
    nias_sms_queue('t_review', $user_id, array('course' => get_the_title($post_id), 'status' => $status, 'link' => nias_sms_panel_link()));
}, 20, 4);

/* -------------------------------------------------------------------------
 * Settings page «پیامک»
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    add_submenu_page('nias-course-settings', __('پیامک', 'nias-course-widget'), __('پیامک', 'nias-course-widget'), 'manage_options', 'nias-course-sms', 'nias_sms_settings_page');
}, 24);

/** Save the settings form. */
function nias_sms_settings_save()
{
    if (!isset($_POST['nias_sms_save']) || !current_user_can('manage_options') || !check_admin_referer('nias_sms_settings', 'nias_sms_nonce')) {
        return false;
    }
    $in  = isset($_POST['sms']) && is_array($_POST['sms']) ? wp_unslash($_POST['sms']) : array();
    $out = array();
    foreach (array_keys(nias_sms_events()) as $key) {
        $e = isset($in[$key]) && is_array($in[$key]) ? $in[$key] : array();
        $out[$key] = array(
            'on'      => (isset($e['on']) && $e['on'] === 'on') ? 'on' : 'off',
            'mode'    => (isset($e['mode']) && $e['mode'] === 'text') ? 'text' : 'pattern',
            'pattern' => sanitize_text_field(isset($e['pattern']) ? $e['pattern'] : ''),
            'params'  => sanitize_textarea_field(isset($e['params']) ? $e['params'] : ''),
            'text'    => sanitize_textarea_field(isset($e['text']) ? $e['text'] : ''),
        );
    }
    update_option(NIAS_SMS_OPTION, $out, false);
    return true;
}

/** «ارسال آزمایشی» (AJAX). */
add_action('wp_ajax_nias_sms_test', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('nias_sms_test', 'nonce', false)) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'nias-course-widget')));
    }
    if (!nias_sms_ready()) {
        wp_send_json_error(array('message' => __('نیاس لاگین فعال نیست یا درگاه پیامک آن تنظیم نشده است.', 'nias-course-widget')));
    }
    // A few test sends per minute at most (each SMS costs money).
    $k = 'nias_sms_test_' . get_current_user_id();
    $n = (int) get_transient($k);
    if ($n >= 5) {
        wp_send_json_error(array('message' => __('کمی صبر کنید و دوباره امتحان کنید.', 'nias-course-widget')));
    }
    set_transient($k, $n + 1, MINUTE_IN_SECONDS);
    $key   = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
    $phone = nias_normalize_mobile(isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '');
    if ($phone === '') {
        wp_send_json_error(array('message' => __('شماره موبایل معتبر نیست.', 'nias-course-widget')));
    }
    $sample = array('name' => wp_get_current_user()->display_name, 'student' => __('علی رضایی', 'nias-course-widget'), 'course' => __('دورهٔ نمونه', 'nias-course-widget'),
        'lesson' => __('جلسهٔ اول', 'nias-course-widget'), 'quiz' => __('آزمون فصل اول', 'nias-course-widget'), 'score' => '85', 'status' => __('قبول', 'nias-course-widget'), 'code' => 'CERT-DEMO', 'link' => home_url('/'));
    $r = nias_sms_deliver($key, $phone, $sample, get_current_user_id());
    is_wp_error($r) ? wp_send_json_error(array('message' => $r->get_error_message())) : wp_send_json_success(array('message' => __('ارسال شد.', 'nias-course-widget')));
});

function nias_sms_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = nias_sms_settings_save();
    $avail = nias_sms_available();
    $ready = nias_sms_ready();
    $gw    = $avail && function_exists('nias_sms_gateway') ? nias_sms_gateway() : null;
    $text  = nias_sms_supports_text();
    $tok   = nias_sms_token_labels();
    echo '<div class="nias-settings-app" dir="rtl">';
    if (function_exists('nias_settings_topbar')) {
        nias_settings_topbar('sms');
    }
    echo '<div class="nias-set-shell"><div class="nias-set-main">';
    if (function_exists('nias_set_saved_banner')) {
        nias_set_saved_banner($saved);
    }
    echo '<style>.nsm-status{display:flex;gap:12px;align-items:center;flex-wrap:wrap;border-radius:14px;padding:14px 16px;margin-bottom:16px;font-size:14px;line-height:1.9}.nsm-status.ok{background:#ecf8ea;border:1px solid #cfeccb;color:#215f1b}.nsm-status.warn{background:#fff7e6;border:1px solid #f3dfb0;color:#7a5410}.nsm-status.off{background:#fdeeee;border:1px solid #f3c9c9;color:#8f2727}.nsm-status a{font-weight:700}'
        . '.nsm-ev{background:#fff;border:1px solid #e6eaef;border-radius:14px;padding:14px 16px;margin-bottom:12px}.nsm-ev.dis{opacity:.55}.nsm-ev-h{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.nsm-ev-h b{font-size:15px}.nsm-to{font-size:11.5px;font-weight:700;border-radius:99px;padding:2px 9px}.nsm-to.student{background:#eef5ff;color:#1e4fb8}.nsm-to.teacher{background:#f3eefe;color:#6d3fc9}.nsm-ev-d{font-size:12.5px;color:#64748b;margin:4px 0 10px}'
        . '.nsm-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.nsm-grid label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:700}.nsm-grid input[type=text],.nsm-grid textarea{border:1px solid #dbe3ef;border-radius:9px;padding:7px 10px;font:inherit;font-weight:400;direction:ltr;text-align:left}.nsm-grid textarea.rtl{direction:rtl;text-align:right}.nsm-tokens{font-size:12px;color:#64748b;margin-top:8px}.nsm-tokens code{background:#f1f5f9;border-radius:6px;padding:1px 6px;margin:0 2px;direction:ltr;display:inline-block}'
        . '.nsm-test{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}.nsm-test input{width:160px;border:1px solid #dbe3ef;border-radius:9px;padding:6px 10px;direction:ltr}.nsm-test button{height:34px;border-radius:9px;border:1px solid #dbe3ef;background:#f5f8ff;color:#3858e9;font:inherit;font-weight:700;padding:0 12px;cursor:pointer}.nsm-test span{font-size:12.5px}.nsm-switch{margin-right:auto;display:flex;align-items:center;gap:8px;font-weight:700;font-size:13px;cursor:pointer}.nsm-switch .nias-switch{margin-top:0}.nsm-switch .nias-switch-cb:disabled~.nias-switch-track{opacity:.5;cursor:not-allowed}@media(max-width:782px){.nsm-grid{grid-template-columns:1fr}}</style>';
    if (!$avail) {
        echo '<div class="nsm-status off"><div><b>' . esc_html__('پیامک‌ها غیرفعال است: افزونهٔ «نیاس لاگین» نصب یا فعال نیست.', 'nias-course-widget') . '</b><br>'
            . esc_html__('ارسال پیامک دوره‌ها فقط از طریق درگاهی انجام می‌شود که در نیاس لاگین تنظیم کرده‌اید (نسخهٔ ۱.۴ به بعد، که رابط برنامه‌نویسی پیامک دارد). تا نیاس لاگین فعال نشود، هیچ پیامکی ارسال نمی‌شود.', 'nias-course-widget')
            . ' <a href="https://nias.ir/product/nias-login-signup/" target="_blank" rel="noopener">' . esc_html__('دریافت نیاس لاگین', 'nias-course-widget') . '</a></div></div>';
    } elseif (!$ready) {
        echo '<div class="nsm-status warn"><div><b>' . esc_html__('نیاس لاگین فعال است، ولی درگاه پیامک آن انتخاب نشده.', 'nias-course-widget') . '</b><br>' . esc_html__('در تنظیمات نیاس لاگین درگاه و اطلاعات پنل پیامکی را وارد کنید؛ تا آن زمان پیامکی ارسال نمی‌شود.', 'nias-course-widget') . '</div></div>';
    } else {
        echo '<div class="nsm-status ok"><div><b>' . esc_html(sprintf(__('ارسال از طریق نیاس لاگین · درگاه: %s', 'nias-course-widget'), $gw ? $gw['name'] : '—')) . '</b><br>'
            . esc_html($text ? __('این درگاه هم پترن و هم متن آزاد را پشتیبانی می‌کند.', 'nias-course-widget') : __('این درگاه فقط ارسال با پترن را پشتیبانی می‌کند.', 'nias-course-widget'))
            . ' ' . esc_html__('همهٔ ارسال‌ها در «گزارش پیام‌ها»ی نیاس لاگین با برچسب «دوره ساز نیاس» ثبت می‌شوند.', 'nias-course-widget') . '</div></div>';
    }
    echo '<form method="post">';
    wp_nonce_field('nias_sms_settings', 'nias_sms_nonce');
    $dis = $avail ? '' : ' disabled';
    foreach (nias_sms_events() as $key => $ev) {
        $s = nias_sms_event_settings($key);
        $n = 'sms[' . $key . ']';
        echo '<div class="nsm-ev' . ($avail ? '' : ' dis') . '"><div class="nsm-ev-h"><b>' . esc_html($ev['label']) . '</b><span class="nsm-to ' . esc_attr($ev['to']) . '">' . esc_html($ev['to'] === 'student' ? __('دانشجو', 'nias-course-widget') : __('مدرس', 'nias-course-widget')) . '</span>'
            . '<label class="nsm-switch"><span>' . esc_html__('فعال', 'nias-course-widget') . '</span><span class="nias-switch"><input type="checkbox" class="nias-switch-cb" name="' . esc_attr($n) . '[on]" value="on"' . checked($s['on'], 'on', false) . $dis . '><span class="nias-switch-track"><span class="nias-switch-knob"></span></span></span></label></div>'
            . '<div class="nsm-ev-d">' . esc_html($ev['desc']) . '</div>'
            . '<div class="nsm-grid"><label>' . esc_html__('روش ارسال', 'nias-course-widget') . '<select name="' . esc_attr($n) . '[mode]"' . $dis . '><option value="pattern"' . selected($s['mode'], 'pattern', false) . '>' . esc_html__('پترن (پیشنهادی)', 'nias-course-widget') . '</option>'
            . '<option value="text"' . selected($s['mode'], 'text', false) . ($text ? '' : ' disabled') . '>' . esc_html__('متن آزاد', 'nias-course-widget') . ($text ? '' : ' ' . esc_html__('(درگاه پشتیبانی نمی‌کند)', 'nias-course-widget')) . '</option></select></label>'
            . '<label>' . esc_html__('کد پترن', 'nias-course-widget') . '<input type="text" name="' . esc_attr($n) . '[pattern]" value="' . esc_attr($s['pattern']) . '" placeholder="12345"' . $dis . '></label>'
            . '<label>' . esc_html__('متغیرهای پترن (به همان ترتیب پترن)', 'nias-course-widget') . '<textarea name="' . esc_attr($n) . '[params]" rows="3"' . $dis . '>' . esc_textarea($s['params']) . '</textarea></label>'
            . '<label>' . esc_html__('متن آزاد', 'nias-course-widget') . '<textarea class="rtl" name="' . esc_attr($n) . '[text]" rows="3"' . $dis . '>' . esc_textarea($s['text']) . '</textarea></label></div>'
            . '<div class="nsm-tokens">' . esc_html__('متغیرهای این رویداد:', 'nias-course-widget') . ' ' . implode(' ', array_map(function ($t) use ($tok) {
                return '<code>{' . esc_html($t) . '}</code>' . esc_html(isset($tok[$t]) ? $tok[$t] : $t);
            }, $ev['tokens'])) . '</div>'
            . ($ready ? '<div class="nsm-test" data-ev="' . esc_attr($key) . '"><input type="text" placeholder="09xxxxxxxxx"><button type="button">' . esc_html__('ارسال آزمایشی', 'nias-course-widget') . '</button><span></span></div>' : '')
            . '</div>';
    }
    if ($avail) {
        nias_set_save_button('nias_sms_save');
    }
    echo '</form>';
    echo '<p style="color:#64748b;font-size:12.5px;line-height:2">' . esc_html__('شمارهٔ گیرنده: شمارهٔ موبایل حساب کاربری در نیاس لاگین، و اگر نبود شمارهٔ صورت‌حساب ووکامرس. نام متغیرها را همان نامی بگذارید که در پترن پنل پیامکی نوشته‌اید و ترتیبشان را با ترتیب پترن یکی کنید تا با درگاه‌هایی که متغیر را به‌ترتیب می‌خوانند هم درست کار کند. ارسال‌ها در صف اجرا می‌شوند تا صفحه‌ها منتظر درگاه نمانند.', 'nias-course-widget') . '</p>';
    echo "<script>document.querySelectorAll('.nsm-test').forEach(function(b){b.querySelector('button').addEventListener('click',function(){var o=b.querySelector('span');o.textContent='در حال ارسال…';var f=new URLSearchParams();f.append('action','nias_sms_test');f.append('nonce'," . wp_json_encode(wp_create_nonce('nias_sms_test')) . ");f.append('event',b.getAttribute('data-ev'));f.append('phone',b.querySelector('input').value);fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:f}).then(function(r){return r.json();}).then(function(r){o.textContent=(r&&r.data&&r.data.message)||'خطا';o.style.color=r&&r.success?'#16a34a':'#d64545';});});});</script>";
    echo '</div></div></div>';
}
