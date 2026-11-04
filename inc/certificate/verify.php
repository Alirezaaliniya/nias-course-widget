<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate verification (استعلام مدرک).
 *
 * - [nias_certificate_verify] renders a code form plus the result. It answers
 *   the QR links ?cert_code=CODE (current) and ?code=CODE&user_id=ID (legacy
 *   links printed on older certificates).
 * - GET /wp-json/nias/v1/certificates/{code} returns the same result as JSON
 *   for third parties (employers, LMS integrations).
 * - admin-ajax.php?action=nias_cert_view&code=CODE shows the PDF of a valid
 *   certificate to whoever holds its code (can be disabled with the
 *   nias_certificate_public_pdf filter).
 *
 * Brute-force protection: every lookup is counted per IP, and failed
 * lookups much more strictly, so scanning QR codes is never blocked while
 * guessing codes is.
 *
 * @package nias-course-widget
 */

add_shortcode('nias_certificate_verify', 'nias_cert_verify_shortcode');

/* -------------------------------------------------------------------------
 * Rate limiting
 * ---------------------------------------------------------------------- */

/** Client IP for rate limiting (REMOTE_ADDR only; proxies can spoof headers). */
function nias_cert_client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    return apply_filters('nias_certificate_client_ip', $ip);
}

/** Transient key of a rate bucket for this IP. */
function nias_cert_rate_key($bucket)
{
    return 'nias_cv_' . $bucket . '_' . md5(nias_cert_client_ip());
}

/** Whether a bucket is still under its limit. */
function nias_cert_rate_under($bucket, $limit)
{
    return (int) get_transient(nias_cert_rate_key($bucket)) < $limit;
}

/** Count one hit in a bucket (10-minute window). */
function nias_cert_rate_hit($bucket)
{
    $key = nias_cert_rate_key($bucket);
    set_transient($key, (int) get_transient($key) + 1, 10 * MINUTE_IN_SECONDS);
}

/**
 * Count a lookup for this IP; false once a limit is exceeded.
 *
 * @return bool
 */
function nias_cert_verify_rate_ok()
{
    if (current_user_can('manage_options')) {
        return true;
    }
    $all  = (int) apply_filters('nias_certificate_verify_rate_limit', 60);       // lookups per 10 min
    $miss = (int) apply_filters('nias_certificate_verify_miss_limit', 15);       // failed lookups per 10 min
    if (!nias_cert_rate_under('all', $all) || !nias_cert_rate_under('miss', $miss)) {
        return false;
    }
    nias_cert_rate_hit('all');
    return true;
}

/* -------------------------------------------------------------------------
 * Lookup
 * ---------------------------------------------------------------------- */

/** "علیرضا محمدی" → "ع***ا م***ی". */
function nias_cert_mask_name($name)
{
    $parts = preg_split('/\s+/u', trim((string) $name));
    foreach ($parts as &$p) {
        $len = mb_strlen($p, 'UTF-8');
        if ($len <= 2) {
            $p = mb_substr($p, 0, 1, 'UTF-8') . '*';
        } else {
            $p = mb_substr($p, 0, 1, 'UTF-8') . str_repeat('*', min(4, $len - 2)) . mb_substr($p, -1, 1, 'UTF-8');
        }
    }
    unset($p);
    return implode(' ', $parts);
}

/**
 * Look up a code (optionally bound to a legacy user id).
 *
 * @return array{state:string,row:array|null}
 *   state: empty | limited | notfound | valid | pending | revoked
 */
function nias_cert_verify_lookup($code, $user_id = 0, $source = 'web')
{
    $code = nias_cert_normalize_code($code);
    if ($code === '') {
        return array('state' => 'empty', 'row' => null);
    }
    if (!nias_cert_verify_rate_ok()) {
        return array('state' => 'limited', 'row' => null);
    }
    $row = nias_cert_get_by_code($code);
    if (!$row || ($user_id && (int) $row['user_id'] !== (int) $user_id)) {
        nias_cert_rate_hit('miss');
        nias_cert_log_verification($code, 'notfound', null, $source);
        do_action('nias_certificate_verify_failed', $code);
        return array('state' => 'notfound', 'row' => null);
    }
    nias_cert_log_verification($code, $row['status'], $row, $source);
    do_action('nias_certificate_verified', $row);
    return array('state' => $row['status'], 'row' => $row);
}

/** Whether names are masked for the current viewer. */
function nias_cert_should_mask($row)
{
    if (nias_cert_setting('certificate_verify_mask_name', 'off') !== 'on') {
        return false;
    }
    $is_owner = is_user_logged_in() && get_current_user_id() === (int) $row['user_id'];
    return !$is_owner && !current_user_can('manage_options');
}

/** Whether the PDF of a valid certificate may be opened by anyone holding its code. */
function nias_cert_public_pdf_allowed($row)
{
    // With masking on, the PDF (full name) would defeat the point.
    $allowed = $row && $row['status'] === 'valid' && !nias_cert_should_mask($row);
    return (bool) apply_filters('nias_certificate_public_pdf', $allowed, $row);
}

/** Public URL of a certificate PDF. */
function nias_cert_public_pdf_url($code)
{
    return add_query_arg(array('action' => 'nias_cert_view', 'code' => rawurlencode($code)), admin_url('admin-ajax.php'));
}

/**
 * What a verification shows about a certificate — shared by the HTML result
 * and the REST endpoint so both always agree.
 *
 * @param array $row
 * @return array
 */
function nias_cert_public_record($row)
{
    $name = $row['student_name'] !== '' ? $row['student_name'] : nias_cert_student_name((int) $row['user_id']);
    if (nias_cert_should_mask($row)) {
        $name = nias_cert_mask_name($name);
    }
    $course = $row['course_name'] !== '' ? $row['course_name'] : ((int) $row['product_id'] ? get_the_title((int) $row['product_id']) : '');
    $date   = substr((string) $row['issued_at'], 0, 10);
    if ((int) $row['product_id'] && $row['user_id']) {
        // Honor the "منبع تاریخ" setting so the date matches the printed certificate.
        $date = nias_certificate_resolve_date((int) $row['user_id'], (int) $row['product_id'], $date);
    }
    $record = array(
        'code'        => $row['code'],
        'status'      => $row['status'],
        'holder'      => $name,
        'course'      => $course,
        'issued'      => $date,
        'issued_text' => $date ? nias_cert_jalali($date, 'long') : '',
        'issuer'      => get_bloginfo('name'),
        'issuer_url'  => home_url('/'),
        'verify_url'  => nias_cert_verify_url($row['code']),
    );
    if (function_exists('nias_cert_signing_active') && nias_cert_signing_active()) {
        $id   = nias_cert_signing_identity();
        $info = nias_cert_sign_cert_info($id['cert']);
        if ($info) {
            // Lets anyone match the signature inside the PDF to this issuer.
            $record['signature'] = array('signer' => $info['subject'], 'fingerprint_sha256' => $info['fingerprint']);
        }
    }
    return apply_filters('nias_certificate_public_record', $record, $row);
}

/* -------------------------------------------------------------------------
 * Caching / indexing of result pages
 * ---------------------------------------------------------------------- */

/**
 * A result page must never be cached (a revoked certificate would keep
 * showing "valid") nor indexed by search engines (holder names).
 */
add_action('template_redirect', 'nias_cert_verify_no_cache', 1);
function nias_cert_verify_no_cache()
{
    $has_code = isset($_GET['cert_code']) || (isset($_GET['code']) && isset($_GET['user_id']));
    if (!$has_code) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    add_filter('wp_robots', 'wp_robots_no_robots');
}

/* -------------------------------------------------------------------------
 * Shortcode
 * ---------------------------------------------------------------------- */

function nias_cert_verify_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'title' => __('استعلام اصالت مدرک', 'nias-course-widget'),
    ), $atts, 'nias_certificate_verify');

    $code    = '';
    $user_id = 0;
    if (isset($_GET['cert_code'])) {
        $code = sanitize_text_field(wp_unslash($_GET['cert_code']));
    } elseif (isset($_GET['code'])) {
        $code    = sanitize_text_field(wp_unslash($_GET['code']));
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    }

    $result = $code !== '' ? nias_cert_verify_lookup($code, $user_id) : array('state' => 'empty', 'row' => null);
    $action = get_permalink() ? get_permalink() : home_url(add_query_arg(array()));

    ob_start();
    nias_cert_verify_styles();
    ?>
    <div class="ncv" dir="rtl">
        <div class="ncv-card">
            <div class="ncv-head">
                <span class="ncv-head-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span>
                <div>
                    <div class="ncv-title"><?php echo esc_html($atts['title']); ?></div>
                    <div class="ncv-sub"><?php echo esc_html__('کد درج‌شده روی مدرک را وارد کنید یا QR آن را اسکن کنید.', 'nias-course-widget'); ?></div>
                </div>
            </div>
            <form class="ncv-form" method="get" action="<?php echo esc_url($action); ?>">
                <input class="ncv-input" type="text" name="cert_code" dir="ltr" autocomplete="off" autocapitalize="characters" spellcheck="false" required maxlength="64"
                       value="<?php echo esc_attr($code); ?>" placeholder="CERT-XXXX-XXXXXXXXXX" aria-label="<?php echo esc_attr__('کد مدرک', 'nias-course-widget'); ?>">
                <button class="ncv-btn" type="submit"><?php echo esc_html__('استعلام', 'nias-course-widget'); ?></button>
            </form>
            <?php echo nias_cert_verify_result_html($result); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Result box HTML. */
function nias_cert_verify_result_html($result)
{
    $state = $result['state'];
    if ($state === 'empty') {
        return '';
    }
    if ($state === 'limited') {
        return '<div class="ncv-res ncv-warn" role="status"><div class="ncv-res-t">' . esc_html__('تعداد استعلام‌ها بیش از حد مجاز است', 'nias-course-widget') . '</div><div class="ncv-res-m">' . esc_html__('لطفاً چند دقیقه بعد دوباره تلاش کنید.', 'nias-course-widget') . '</div></div>';
    }
    if ($state === 'notfound') {
        return '<div class="ncv-res ncv-bad" role="status"><div class="ncv-res-t">✖ ' . esc_html__('مدرکی با این کد یافت نشد', 'nias-course-widget') . '</div><div class="ncv-res-m">' . esc_html__('کد را دوباره بررسی کنید. این مدرک توسط این مرکز صادر نشده یا کد اشتباه وارد شده است.', 'nias-course-widget') . '</div></div>';
    }

    $row  = $result['row'];
    $map  = array(
        'valid'   => array('ncv-ok', '✔ ' . __('این مدرک معتبر است', 'nias-course-widget'), __('این مدرک توسط این مرکز صادر شده و معتبر است.', 'nias-course-widget')),
        'pending' => array('ncv-warn', __('مدرک در انتظار تأیید است', 'nias-course-widget'), __('این مدرک ثبت شده اما هنوز توسط مدیر تأیید نهایی نشده است.', 'nias-course-widget')),
        'revoked' => array('ncv-bad', '✖ ' . __('این مدرک باطل شده است', 'nias-course-widget'), __('این مدرک پیش‌تر صادر شده بود اما اعتبار آن لغو شده است.', 'nias-course-widget')),
    );
    $info = isset($map[$state]) ? $map[$state] : $map['revoked'];
    $rec  = nias_cert_public_record($row);

    $rows = array(
        array(__('نام دارنده', 'nias-course-widget'), $rec['holder'], false),
        array(__('عنوان دوره', 'nias-course-widget'), $rec['course'], false),
        array(__('تاریخ صدور', 'nias-course-widget'), $rec['issued_text'], false),
        array(__('کد مدرک', 'nias-course-widget'), $rec['code'], true),
        array(__('صادرکننده', 'nias-course-widget'), $rec['issuer'], false),
        array(__('زمان استعلام', 'nias-course-widget'), nias_cert_jalali(current_time('Y-m-d'), 'long') . ' — ' . current_time('H:i'), false),
    );

    $html  = '<div class="ncv-res ' . esc_attr($info[0]) . '" role="status">';
    $html .= '<div class="ncv-res-t">' . esc_html($info[1]) . '</div><div class="ncv-res-m">' . esc_html($info[2]) . '</div>';
    if ($state !== 'revoked') {
        $html .= '<dl class="ncv-dl">';
        foreach ($rows as $r) {
            if ((string) $r[1] === '') {
                continue;
            }
            $html .= '<div><dt>' . esc_html($r[0]) . '</dt><dd' . ($r[2] ? ' dir="ltr"' : '') . '>' . esc_html($r[1]) . '</dd></div>';
        }
        if (!empty($rec['signature'])) {
            $fp    = $rec['signature']['fingerprint_sha256'];
            $html .= '<div><dt>' . esc_html__('امضای دیجیتال', 'nias-course-widget') . '</dt><dd><span title="' . esc_attr('SHA-256: ' . $fp) . '">✔ ' . esc_html($rec['signature']['signer']) . '</span>'
                . '<small class="ncv-fp" dir="ltr">' . esc_html(substr($fp, 0, 23)) . '…</small></dd></div>';
        }
        $html .= '</dl>';
        if (!empty($rec['signature'])) {
            $html .= '<div class="ncv-res-m" style="margin-top:10px">' . esc_html__('فایل PDF این مدرک امضای دیجیتال دارد. در Adobe Acrobat یا Foxit، پنل امضا باید همین امضاکننده و اثرانگشت را نشان دهد و هر تغییری در فایل، امضا را نامعتبر می‌کند.', 'nias-course-widget') . '</div>';
        }

        $btns     = '';
        $is_owner = is_user_logged_in() && get_current_user_id() === (int) $row['user_id'];
        if (nias_cert_public_pdf_allowed($row)) {
            $btns .= '<a class="ncv-btn ncv-btn-sm" target="_blank" rel="noopener" href="' . esc_url(nias_cert_public_pdf_url($row['code'])) . '">' . esc_html__('مشاهدهٔ مدرک (PDF)', 'nias-course-widget') . '</a>';
        }
        if ($is_owner && $state === 'valid') {
            $btns .= '<a class="ncv-btn ncv-btn-sm ncv-btn-soft" href="' . esc_url((function_exists('nias_cert_account_url') && nias_cert_account_url()) ? nias_cert_account_url() : get_certificate_display_page_url()) . '">' . esc_html__('مدارک من', 'nias-course-widget') . '</a>';
        }
        if ($btns) {
            $html .= '<div class="ncv-actions">' . $btns . '</div>';
        }
    }
    $html .= '</div>';
    return apply_filters('nias_certificate_verify_result_html', $html, $result);
}

/* -------------------------------------------------------------------------
 * Public PDF
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_cert_view', 'nias_cert_handle_public_view');
add_action('wp_ajax_nopriv_nias_cert_view', 'nias_cert_handle_public_view');
function nias_cert_handle_public_view()
{
    $code   = isset($_GET['code']) ? wp_unslash($_GET['code']) : '';
    $result = nias_cert_verify_lookup($code, 0, 'pdf');
    if ($result['state'] === 'limited') {
        wp_die(esc_html__('تعداد درخواست‌ها بیش از حد مجاز است؛ چند دقیقه بعد تلاش کنید.', 'nias-course-widget'), '', array('response' => 429));
    }
    if (!$result['row'] || !nias_cert_public_pdf_allowed($result['row'])) {
        wp_die(esc_html__('این مدرک قابل نمایش نیست.', 'nias-course-widget'), '', array('response' => 404));
    }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    try {
        nias_cert_output_pdf(nias_cert_row_data($result['row']), nias_cert_design_for_row($result['row']), 'I');
    } catch (\Throwable $e) {
        wp_die(esc_html__('خطا در ساخت مدرک.', 'nias-course-widget'));
    }
    exit;
}

/* -------------------------------------------------------------------------
 * REST
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', 'nias_cert_register_rest');
function nias_cert_register_rest()
{
    register_rest_route('nias/v1', '/certificates/(?P<code>[A-Za-z0-9\-_]{4,64})', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'nias_cert_rest_verify',
    ));
}

function nias_cert_rest_verify(WP_REST_Request $request)
{
    $result = nias_cert_verify_lookup($request->get_param('code'), 0, 'api');
    if ($result['state'] === 'limited') {
        return new WP_REST_Response(array('code' => 'rate_limited', 'message' => 'Too many requests'), 429);
    }
    if (!$result['row']) {
        return new WP_REST_Response(array('valid' => false, 'status' => 'notfound'), 404);
    }
    $rec = nias_cert_public_record($result['row']);
    if ($rec['status'] === 'revoked') {
        // Nothing about the holder for a revoked certificate.
        $rec = array('code' => $rec['code'], 'status' => 'revoked', 'issuer' => $rec['issuer'], 'issuer_url' => $rec['issuer_url']);
    }
    $rec['valid'] = $result['state'] === 'valid';
    unset($rec['issued_text']);
    $res = new WP_REST_Response($rec, 200);
    $res->header('Cache-Control', 'no-store');
    return $res;
}

/* -------------------------------------------------------------------------
 * Styles
 * ---------------------------------------------------------------------- */

function nias_cert_verify_styles()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <style>
    .ncv,.ncv *{box-sizing:border-box}
    .ncv{max-width:640px;margin:24px auto;font-family:inherit}
    .ncv-card{background:#fff;border:1px solid #e5e8ef;border-radius:18px;padding:24px;box-shadow:0 10px 30px rgba(20,30,60,.06)}
    .ncv-head{display:flex;gap:14px;align-items:center;margin-bottom:18px}
    .ncv-head-ic{width:52px;height:52px;flex:none;border-radius:14px;background:#eef1fd;color:#3858e9;display:flex;align-items:center;justify-content:center}
    .ncv-title{font-size:19px;font-weight:800;color:#1f2a30}
    .ncv-sub{font-size:13px;color:#7b8497;margin-top:4px;line-height:1.8}
    .ncv-form{display:flex;gap:8px;flex-wrap:wrap}
    .ncv-input{flex:1;min-width:200px;height:48px;border:1px solid #d9dee8;border-radius:12px;padding:0 14px;font-size:15px;letter-spacing:.5px;text-align:left;font-family:monospace,inherit;text-transform:uppercase}
    .ncv-input:focus{outline:none;border-color:#3858e9;box-shadow:0 0 0 3px rgba(56,88,233,.15)}
    .ncv-btn{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 26px;border:0;border-radius:12px;background:#3858e9;color:#fff !important;font-weight:700;font-size:15px;cursor:pointer;text-decoration:none;font-family:inherit}
    .ncv-btn:hover{background:#2c4bd6}
    .ncv-btn-sm{height:40px;font-size:13px;padding:0 18px}
    .ncv-btn-soft{background:#fff;color:#3858e9 !important;border:1px solid #c7d0fb}
    .ncv-btn-soft:hover{background:#eef1fd}
    .ncv-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
    .ncv-res{margin-top:18px;border-radius:14px;padding:18px;border:1px solid}
    .ncv-res-t{font-size:16px;font-weight:800}
    .ncv-res-m{font-size:13px;margin-top:6px;line-height:1.9;opacity:.9}
    .ncv-ok{background:#ecfbf1;border-color:#bfe9cf;color:#146c3a}
    .ncv-warn{background:#fff8e6;border-color:#f6dfa1;color:#7a5a00}
    .ncv-bad{background:#fff1f1;border-color:#f5c2c4;color:#a1161c}
    .ncv-dl{margin:14px 0 0;display:grid;gap:0;background:#fff;border-radius:10px;overflow:hidden;border:1px solid rgba(0,0,0,.06)}
    .ncv-dl>div{display:flex;justify-content:space-between;gap:12px;padding:10px 14px;border-top:1px solid #f0f2f6}
    .ncv-dl>div:first-child{border-top:0}
    .ncv-dl dt{color:#7b8497;font-size:13px;margin:0}
    .ncv-dl dd{margin:0;color:#1f2a30;font-weight:700;font-size:14px;text-align:left}
    .ncv-fp{display:block;font-weight:400;font-size:11px;color:#7b8497;font-family:monospace}
    </style>
    <?php
}
