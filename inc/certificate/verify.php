<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate verification form (فرم استعلام مدرک).
 *
 * [nias_certificate_verify] renders a code form plus the result. It also
 * answers the QR links: ?cert_code=CODE (new) and ?code=CODE&user_id=ID
 * (legacy links printed on older certificates).
 *
 * @package nias-course-widget
 */

add_shortcode('nias_certificate_verify', 'nias_cert_verify_shortcode');

/** Client IP for rate limiting (REMOTE_ADDR only; proxies can spoof headers). */
function nias_cert_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
}

/**
 * Count a lookup for this IP; false once the limit is exceeded.
 *
 * @return bool
 */
function nias_cert_verify_rate_ok()
{
    if (current_user_can('manage_options')) {
        return true;
    }
    $limit  = (int) apply_filters('nias_certificate_verify_rate_limit', 30); // per 10 minutes
    $key    = 'nias_cv_' . md5(nias_cert_client_ip());
    $count  = (int) get_transient($key);
    if ($count >= $limit) {
        return false;
    }
    set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
    return true;
}

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
function nias_cert_verify_lookup($code, $user_id = 0)
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
        return array('state' => 'notfound', 'row' => null);
    }
    return array('state' => $row['status'], 'row' => $row);
}

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
                <input class="ncv-input" type="text" name="cert_code" dir="ltr" autocomplete="off" required
                       value="<?php echo esc_attr($code); ?>" placeholder="CERT-XXXX-XXXXXXXXXX">
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
        return '<div class="ncv-res ncv-warn"><div class="ncv-res-t">' . esc_html__('تعداد استعلام‌ها بیش از حد مجاز است', 'nias-course-widget') . '</div><div class="ncv-res-m">' . esc_html__('لطفاً چند دقیقه بعد دوباره تلاش کنید.', 'nias-course-widget') . '</div></div>';
    }
    if ($state === 'notfound') {
        return '<div class="ncv-res ncv-bad"><div class="ncv-res-t">✖ ' . esc_html__('مدرکی با این کد یافت نشد', 'nias-course-widget') . '</div><div class="ncv-res-m">' . esc_html__('کد را دوباره بررسی کنید. این مدرک توسط این مرکز صادر نشده یا کد اشتباه وارد شده است.', 'nias-course-widget') . '</div></div>';
    }

    $row  = $result['row'];
    $map  = array(
        'valid'   => array('ncv-ok', '✔ ' . __('این مدرک معتبر است', 'nias-course-widget'), __('این مدرک توسط این مرکز صادر شده و معتبر است.', 'nias-course-widget')),
        'pending' => array('ncv-warn', __('مدرک در انتظار تأیید است', 'nias-course-widget'), __('این مدرک ثبت شده اما هنوز توسط مدیر تأیید نهایی نشده است.', 'nias-course-widget')),
        'revoked' => array('ncv-bad', '✖ ' . __('این مدرک باطل شده است', 'nias-course-widget'), __('این مدرک پیش‌تر صادر شده بود اما اعتبار آن لغو شده است.', 'nias-course-widget')),
    );
    $info = isset($map[$state]) ? $map[$state] : $map['revoked'];

    $name = $row['student_name'] !== '' ? $row['student_name'] : nias_cert_student_name((int) $row['user_id']);
    $is_owner = is_user_logged_in() && get_current_user_id() === (int) $row['user_id'];
    if (nias_cert_setting('certificate_verify_mask_name', 'off') === 'on' && !$is_owner && !current_user_can('manage_options')) {
        $name = nias_cert_mask_name($name);
    }
    $course = $row['course_name'] !== '' ? $row['course_name'] : ((int) $row['product_id'] ? get_the_title((int) $row['product_id']) : '');
    $date   = $row['issued_at'] ? nias_cert_jalali(substr($row['issued_at'], 0, 10), 'long') : '';
    if ((int) $row['product_id'] && $row['user_id']) {
        // Honor the "منبع تاریخ" setting so the date matches the printed certificate.
        $printed = nias_certificate_resolve_date((int) $row['user_id'], (int) $row['product_id'], substr((string) $row['issued_at'], 0, 10));
        $date    = nias_cert_jalali($printed, 'long');
    }

    $rows = array(
        __('نام دارنده', 'nias-course-widget')  => $name,
        __('عنوان دوره', 'nias-course-widget')  => $course,
        __('تاریخ صدور', 'nias-course-widget')  => $date,
        __('کد مدرک', 'nias-course-widget')     => $row['code'],
        __('صادرکننده', 'nias-course-widget')   => get_bloginfo('name'),
    );

    $html  = '<div class="ncv-res ' . esc_attr($info[0]) . '">';
    $html .= '<div class="ncv-res-t">' . esc_html($info[1]) . '</div><div class="ncv-res-m">' . esc_html($info[2]) . '</div>';
    if ($state !== 'revoked') {
        $html .= '<dl class="ncv-dl">';
        foreach ($rows as $label => $val) {
            if ((string) $val === '') {
                continue;
            }
            $html .= '<div><dt>' . esc_html($label) . '</dt><dd' . ($label === __('کد مدرک', 'nias-course-widget') ? ' dir="ltr"' : '') . '>' . esc_html($val) . '</dd></div>';
        }
        $html .= '</dl>';
        if ($is_owner && $state === 'valid') {
            $html .= '<a class="ncv-btn ncv-btn-sm" href="' . esc_url((function_exists('nias_cert_account_url') && nias_cert_account_url()) ? nias_cert_account_url() : get_certificate_display_page_url()) . '">' . esc_html__('مشاهده و دانلود مدارک من', 'nias-course-widget') . '</a>';
        }
    }
    $html .= '</div>';
    return apply_filters('nias_certificate_verify_result_html', $html, $result);
}

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
    .ncv-input{flex:1;min-width:200px;height:48px;border:1px solid #d9dee8;border-radius:12px;padding:0 14px;font-size:15px;letter-spacing:.5px;text-align:left;font-family:monospace,inherit}
    .ncv-input:focus{outline:none;border-color:#3858e9;box-shadow:0 0 0 3px rgba(56,88,233,.15)}
    .ncv-btn{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 26px;border:0;border-radius:12px;background:#3858e9;color:#fff !important;font-weight:700;font-size:15px;cursor:pointer;text-decoration:none;font-family:inherit}
    .ncv-btn:hover{background:#2c4bd6}
    .ncv-btn-sm{height:40px;font-size:13px;margin-top:14px;padding:0 18px}
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
    </style>
    <?php
}
