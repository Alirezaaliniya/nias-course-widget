<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatic certificate e-mail (ارسال خودکار مدرک).
 *
 * When a certificate becomes valid — issued directly, or approved by an
 * admin — the student gets an e-mail with the verification link and,
 * optionally, the PDF attached. Sending happens in WP-Cron (async), so a
 * checkout or "complete order" click is never slowed down by PDF
 * generation. Every certificate is e-mailed at most once (emailed_at);
 * admins can resend from «مدارک صادرشده».
 *
 * Certificates are normally issued lazily when the student opens their
 * certificate list. With e-mail on, issuance is also evaluated right after
 * the events that can make a student eligible: an order turning
 * processing/completed, a quiz result, course progress.
 *
 * @package nias-course-widget
 */

function nias_cert_email_enabled()
{
    return nias_cert_feature_on() && nias_cert_setting('certificate_email_on_issue', 'off') === 'on';
}

function nias_cert_email_default_subject()
{
    return __('مدرک دورهٔ «{course_name}» صادر شد', 'nias-course-widget');
}

function nias_cert_email_default_body()
{
    return __("سلام {student_name} عزیز،\n\nتبریک! مدرک پایان دورهٔ «{course_name}» به نام شما صادر شد.\n\nکد مدرک: {code}\nتاریخ صدور: {date}\n\nهر کسی (مثلاً کارفرما) می‌تواند اصالت مدرک شما را از این نشانی بررسی کند:\n{verify_url}\n\nبا آرزوی موفقیت\n{site_name}", 'nias-course-widget');
}

/* -------------------------------------------------------------------------
 * Triggers
 * ---------------------------------------------------------------------- */

add_action('nias_certificate_issued', 'nias_cert_email_on_issued');
function nias_cert_email_on_issued($row)
{
    // Legacy imports are old certificates, not new ones.
    if ($row && $row['status'] === 'valid' && $row['note'] !== 'legacy') {
        nias_cert_queue_email((int) $row['id']);
    }
}

add_action('nias_certificate_status_changed', 'nias_cert_email_on_status', 10, 2);
function nias_cert_email_on_status($id, $status)
{
    if ($status === 'valid') {
        nias_cert_queue_email((int) $id);
    }
}

function nias_cert_queue_email($id)
{
    if (!nias_cert_email_enabled() || !$id) {
        return;
    }
    $args = array((int) $id);
    if (!wp_next_scheduled('nias_cert_send_issue_email', $args)) {
        wp_schedule_single_event(time(), 'nias_cert_send_issue_email', $args);
    }
}

/** Evaluate (and thereby issue) a user's certificates after relevant events. */
function nias_cert_email_evaluate_user($user_id)
{
    if ($user_id && nias_cert_email_enabled() && function_exists('nias_cert_user_courses')) {
        nias_cert_user_courses((int) $user_id, true);
    }
}

add_action('woocommerce_order_status_completed', 'nias_cert_email_on_order', 20);
add_action('woocommerce_order_status_processing', 'nias_cert_email_on_order', 20);
function nias_cert_email_on_order($order_id)
{
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if ($order) {
        nias_cert_email_evaluate_user((int) $order->get_customer_id());
    }
}

add_action('nias_quiz_result_saved', 'nias_cert_email_evaluate_user', 20);
add_action('nias_course_progress_updated', 'nias_cert_email_evaluate_user', 20);

/* -------------------------------------------------------------------------
 * Sending
 * ---------------------------------------------------------------------- */

add_action('nias_cert_send_issue_email', 'nias_cert_send_issue_email');

/**
 * Send the "your certificate is ready" e-mail.
 *
 * @param int  $id
 * @param bool $force send even when e-mail is off (admin "resend")
 * @return bool
 */
function nias_cert_send_issue_email($id, $force = false)
{
    global $wpdb;
    $row = nias_cert_get((int) $id);
    if (!$row || $row['status'] !== 'valid' || (!$force && !nias_cert_email_enabled())) {
        return false;
    }
    // Claim the row first so parallel cron runs cannot both send.
    $claimed = $wpdb->query($wpdb->prepare(
        'UPDATE ' . nias_cert_table() . ' SET emailed_at = %s WHERE id = %d AND emailed_at IS NULL',
        current_time('mysql'),
        (int) $id
    ));
    if (!$claimed) {
        return false;
    }
    $user = get_userdata((int) $row['user_id']);
    if (!$user || !is_email($user->user_email)) {
        return false;
    }
    $data = nias_cert_row_data($row);
    $ok   = nias_cert_mail_certificate($user->user_email, $data, nias_cert_design_for_row($row));
    if (!$ok) {
        $wpdb->update(nias_cert_table(), array('emailed_at' => null), array('id' => (int) $id)); // retry later / resend
    }
    do_action('nias_certificate_emailed', $row, $ok);
    return $ok;
}

/**
 * Compose and send the e-mail for one certificate.
 *
 * @param string    $to
 * @param array     $data     nias_cert_build_data() output
 * @param int|array $template what the PDF renders with
 * @return bool
 */
function nias_cert_mail_certificate($to, $data, $template)
{
    $tokens = $data + array(
        'account_url' => (function_exists('nias_cert_account_url') && nias_cert_account_url()) ? nias_cert_account_url() : get_certificate_display_page_url(),
    );
    $subject = nias_cert_apply_tokens(nias_cert_setting('certificate_email_subject', nias_cert_email_default_subject()), $tokens);
    $body    = nias_cert_apply_tokens(nias_cert_setting('certificate_email_body', nias_cert_email_default_body()), $tokens);

    $btn  = 'display:inline-block;padding:11px 22px;border-radius:10px;text-decoration:none;font-weight:bold;margin:4px';
    $html = '<div dir="rtl" style="text-align:right;line-height:2;font-size:14px">' . nl2br(make_clickable(esc_html($body)))
        . '<p style="margin-top:22px">'
        . '<a href="' . esc_url($tokens['account_url']) . '" style="' . $btn . ';background:#3858e9;color:#ffffff">' . esc_html__('دریافت مدرک', 'nias-course-widget') . '</a>'
        . '<a href="' . esc_url($data['verify_url']) . '" style="' . $btn . ';background:#eef1fd;color:#3858e9">' . esc_html__('صفحهٔ استعلام مدرک', 'nias-course-widget') . '</a>'
        . '</p></div>';
    $html = apply_filters('nias_certificate_email_html', $html, $data);

    $attachments = array();
    if (nias_cert_setting('certificate_email_attach', 'on') === 'on') {
        try {
            $file = trailingslashit(nias_cert_temp_dir()) . nias_cert_pdf_filename($data);
            file_put_contents($file, nias_cert_pdf_bytes($data, $template));
            $attachments[] = $file;
        } catch (\Throwable $e) {
            error_log('[nias-cert] e-mail attachment failed: ' . $e->getMessage());
        }
    }

    if (function_exists('WC') && WC()->mailer()) {
        $mailer = WC()->mailer();
        $ok     = $mailer->send($to, $subject, $mailer->wrap_message($subject, $html), array('Content-Type: text/html; charset=UTF-8'), $attachments);
    } else {
        $ok = wp_mail($to, $subject, '<html><body>' . $html . '</body></html>', array('Content-Type: text/html; charset=UTF-8'), $attachments);
    }
    foreach ($attachments as $f) {
        @unlink($f);
    }
    return (bool) $ok;
}

/* -------------------------------------------------------------------------
 * Test e-mail (settings page)
 * ---------------------------------------------------------------------- */

add_action('admin_post_nias_cert_test_email', 'nias_cert_handle_test_email');
function nias_cert_handle_test_email()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('دسترسی لازم را ندارید.', 'nias-course-widget'));
    }
    check_admin_referer('nias_cert_test_email');
    $me   = wp_get_current_user();
    $tpl  = nias_cert_template_for(0);
    $ok   = nias_cert_mail_certificate($me->user_email, nias_cert_sample_data(), $tpl ? $tpl : 0);
    wp_safe_redirect(add_query_arg('nias_test_mail', $ok ? 'ok' : 'fail', admin_url('admin.php?page=nias-course-certificate#nias-cert-email')));
    exit;
}
