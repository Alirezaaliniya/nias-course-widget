<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «محدودیت دستگاه و ورود همزمان» (device limit + concurrent login).
 *
 *   - Every browser gets a device id (cookie nias_did, httpOnly, 2 years).
 *     A user's devices are kept in user meta _nias_devices
 *     (id => label, ua, ip, first, last). Each WordPress session is tagged
 *     with the device it was opened on (session field nias_did).
 *   - Max devices (default 2). A new device when the list is full:
 *       «block»  → the login is refused (password form) or the new session is
 *                  closed on its first page view (OTP / Google / any other
 *                  login method), with a clear message;
 *       «oldest» → the least recently used device is removed and its sessions
 *                  are closed.
 *   - «ورود همزمان مجاز نیست»: one device at a time; activity on a device
 *     closes the sessions of the others.
 *   - Students see «دستگاه‌های من» in their account (WooCommerce tab or the
 *     [nias_my_devices] shortcode) and may remove devices (limited per 30 days).
 *     Admins see and reset a user's devices on the user's profile screen.
 *   - Administrators and shop managers are never limited; instructors
 *     optionally.
 *   - Works with every login method (Nias Login OTP/Google/magic link,
 *     WooCommerce, wp-login.php), as all of them open a WordPress session.
 *
 * @package nias-course-widget
 */

define('NIAS_DEV_COOKIE', 'nias_did');

function nias_dev_enabled()
{
    return (bool) apply_filters('nias_dev_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_dev_enabled') === 'on');
}

/** Module settings (with defaults). */
function nias_dev_opt($key)
{
    $def = array('max' => 2, 'mode' => 'block', 'single' => 'off', 'exempt_inst' => 'on', 'self' => 'on', 'removals' => 2);
    $map = array('max' => 'nias_dev_max', 'mode' => 'nias_dev_mode', 'single' => 'nias_dev_single', 'exempt_inst' => 'nias_dev_exempt_inst', 'self' => 'nias_dev_self', 'removals' => 'nias_dev_removals');
    $v = function_exists('carbon_get_theme_option') ? carbon_get_theme_option($map[$key]) : '';
    if ($v === '' || $v === null || $v === false) {
        $v = $def[$key];
    }
    if ($key === 'max') {
        return max(1, min(20, (int) $v));
    }
    if ($key === 'removals') {
        return max(0, min(50, (int) $v));
    }
    if ($key === 'mode') {
        return $v === 'oldest' ? 'oldest' : 'block';
    }
    return $v;
}

/** Users the limit never applies to. */
function nias_dev_exempt($user_id)
{
    $ex = user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce');
    if (!$ex && nias_dev_opt('exempt_inst') === 'on' && function_exists('nias_user_is_instructor') && nias_user_is_instructor($user_id)) {
        $ex = true;
    }
    return (bool) apply_filters('nias_dev_exempt', $ex, $user_id);
}

/* -------------------------------------------------------------------------
 * Device id + list
 * ---------------------------------------------------------------------- */

/** This browser's device id ('' = none yet). */
function nias_dev_current_id($create = false)
{
    $id = isset($_COOKIE[NIAS_DEV_COOKIE]) ? (string) $_COOKIE[NIAS_DEV_COOKIE] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = '';
    }
    if ($id === '' && $create) {
        $id = bin2hex(random_bytes(16));
        $_COOKIE[NIAS_DEV_COOKIE] = $id;
        if (!headers_sent()) {
            setcookie(NIAS_DEV_COOKIE, $id, array(
                'expires'  => time() + 2 * YEAR_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
    }
    return $id;
}

function nias_dev_list($user_id)
{
    $l = get_user_meta((int) $user_id, '_nias_devices', true);
    return is_array($l) ? $l : array();
}

function nias_dev_save_list($user_id, $list)
{
    if ($list) {
        update_user_meta((int) $user_id, '_nias_devices', $list);
    } else {
        delete_user_meta((int) $user_id, '_nias_devices');
    }
}

/** "Chrome · Windows" + kind (mobile|tablet|desktop). */
function nias_dev_describe($ua)
{
    $ua = (string) $ua;
    $browser = 'مرورگر';
    foreach (array('Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'YaBrowser' => 'Yandex', 'Firefox/' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari') as $k => $n) {
        if (stripos($ua, $k) !== false) {
            $browser = $n;
            break;
        }
    }
    $os = __('سیستم نامشخص', 'nias-course-widget');
    foreach (array('iPad' => 'iPad', 'iPhone' => 'iPhone', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux') as $k => $n) {
        if (stripos($ua, $k) !== false) {
            $os = $n;
            break;
        }
    }
    $kind = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobile|iPhone|Android/i', $ua) ? 'mobile' : 'desktop');
    return array('label' => $browser . ' · ' . $os, 'kind' => $kind);
}

function nias_dev_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return (string) apply_filters('nias_dev_ip', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '');
}

/**
 * Close sessions of a user. $keep: callable(verifier, session) → true to keep.
 * (Works on WordPress's default session storage.)
 */
function nias_dev_filter_sessions($user_id, $keep, $reason = '')
{
    $all = get_user_meta((int) $user_id, 'session_tokens', true);
    if (!is_array($all)) {
        return 0;
    }
    $n = 0;
    foreach ($all as $verifier => $s) {
        if (!call_user_func($keep, $verifier, $s)) {
            unset($all[$verifier]);
            $n++;
            if (!empty($s['nias_did']) && $reason !== '') {
                nias_dev_mark_kicked($user_id, $s['nias_did'], $reason);
            }
        }
    }
    if ($n) {
        if ($all) {
            update_user_meta((int) $user_id, 'session_tokens', $all);
        } else {
            delete_user_meta((int) $user_id, 'session_tokens');
        }
    }
    return $n;
}

/** A device was signed out by the module: tell the person on its next visit. */
function nias_dev_mark_kicked($user_id, $device_id, $reason)
{
    $k = get_user_meta((int) $user_id, '_nias_dev_kicked', true);
    $k = is_array($k) ? $k : array();
    foreach ($k as $id => $row) {
        if ((int) $row['t'] < time() - 3 * DAY_IN_SECONDS) {
            unset($k[$id]);
        }
    }
    $k[$device_id] = array('r' => $reason, 't' => time());
    update_user_meta((int) $user_id, '_nias_dev_kicked', array_slice($k, -20, null, true));
}

function nias_dev_token_verifier($token)
{
    return function_exists('hash') ? hash('sha256', $token) : sha1($token);
}

/** Remove a device (and close its sessions). */
function nias_dev_remove($user_id, $device_id, $by = 'user')
{
    $list = nias_dev_list($user_id);
    if (!isset($list[$device_id])) {
        return false;
    }
    unset($list[$device_id]);
    nias_dev_save_list($user_id, $list);
    nias_dev_filter_sessions($user_id, function ($v, $s) use ($device_id) {
        return !isset($s['nias_did']) || $s['nias_did'] !== $device_id;
    }, $by === 'auto' ? 'oldest' : 'removed');
    do_action('nias_device_removed', (int) $user_id, $device_id, $by);
    return true;
}

/* -------------------------------------------------------------------------
 * Sessions are tagged with the device they were opened on
 * ---------------------------------------------------------------------- */

add_filter('attach_session_information', function ($info, $user_id) {
    if (nias_dev_enabled()) {
        $info['nias_did'] = nias_dev_current_id(true);
    }
    return $info;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Enforcement
 * ---------------------------------------------------------------------- */

function nias_dev_limit_message($user_id = 0)
{
    $msg = sprintf(
        __('حساب کاربری شما روی حداکثر تعداد دستگاه مجاز (%s دستگاه) فعال است. برای ورود از این دستگاه، با یکی از دستگاه‌های قبلی وارد شوید و از «حساب کاربری › دستگاه‌های من» یک دستگاه را حذف کنید، یا با پشتیبانی تماس بگیرید.', 'nias-course-widget'),
        function_exists('nias_fa_digits') ? nias_fa_digits(nias_dev_opt('max')) : nias_dev_opt('max')
    );
    return (string) apply_filters('nias_dev_limit_message', $msg, $user_id);
}

/** Password logins: refuse right away when this would be one device too many. */
add_filter('authenticate', function ($user) {
    if (!($user instanceof WP_User) || !nias_dev_enabled() || nias_dev_opt('mode') !== 'block' || nias_dev_exempt($user->ID)) {
        return $user;
    }
    $did  = nias_dev_current_id();
    $list = nias_dev_list($user->ID);
    if (($did === '' || !isset($list[$did])) && count($list) >= nias_dev_opt('max')) {
        do_action('nias_device_refused', $user->ID, $did);
        return new WP_Error('nias_device_limit', nias_dev_limit_message($user->ID));
    }
    return $user;
}, 99);

/** Every logged-in request: register / check this device (covers OTP and other logins). */
add_action('init', 'nias_dev_enforce', 1);
function nias_dev_enforce()
{
    if (!nias_dev_enabled() || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
        return;
    }
    $uid = get_current_user_id();
    if (!$uid || nias_dev_exempt($uid)) {
        return;
    }
    $did  = nias_dev_current_id(true);
    $list = nias_dev_list($uid);
    $now  = time();
    $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300) : '';

    if (!isset($list[$did])) {
        $max = nias_dev_opt('max');
        if (count($list) >= $max) {
            if (nias_dev_opt('mode') === 'block') {
                do_action('nias_device_refused', $uid, $did);
                nias_dev_reject();
                return;
            }
            // «oldest»: the least recently used devices make room.
            uasort($list, function ($a, $b) { return (int) $a['last'] - (int) $b['last']; });
            while (count($list) >= $max) {
                reset($list);
                $old = key($list);
                nias_dev_remove($uid, $old, 'auto');
                unset($list[$old]);
            }
        }
        $d = nias_dev_describe($ua);
        $list[$did] = array('label' => $d['label'], 'kind' => $d['kind'], 'ua' => $ua, 'ip' => nias_dev_ip(), 'first' => $now, 'last' => $now);
        nias_dev_save_list($uid, $list);
        do_action('nias_device_added', $uid, $did, $list[$did]);
    } elseif ($now - (int) $list[$did]['last'] > 10 * MINUTE_IN_SECONDS) {
        $list[$did]['last'] = $now;
        $list[$did]['ip']   = nias_dev_ip();
        nias_dev_save_list($uid, $list);
    }

    // Sessions opened before the module (or by a login that skipped the tag) get tagged now.
    $token = wp_get_session_token();
    if ($token && class_exists('WP_Session_Tokens')) {
        $mgr = WP_Session_Tokens::get_instance($uid);
        $s   = $mgr->get($token);
        if (is_array($s) && (!isset($s['nias_did']) || $s['nias_did'] !== $did)) {
            $s['nias_did'] = $did;
            $mgr->update($token, $s);
        }
    }

    // One device at a time: activity here closes the others.
    if (nias_dev_opt('single') === 'on' && get_user_meta($uid, '_nias_dev_active', true) !== $did) {
        $cur = $token ? nias_dev_token_verifier($token) : '';
        $closed = nias_dev_filter_sessions($uid, function ($v, $s) use ($did, $cur) {
            return $v === $cur || (isset($s['nias_did']) && $s['nias_did'] === $did);
        }, 'other');
        update_user_meta($uid, '_nias_dev_active', $did);
        if ($closed) {
            do_action('nias_device_sessions_closed', $uid, $did, $closed);
        }
    }
}

/** A visitor whose session the module closed: show why (once). */
add_action('init', function () {
    if (!nias_dev_enabled() || is_user_logged_in() || empty($_COOKIE[LOGGED_IN_COOKIE]) || wp_doing_ajax()) {
        return;
    }
    $did = nias_dev_current_id();
    $c   = wp_parse_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in');
    $u   = ($did && $c && !empty($c['username'])) ? get_user_by('login', $c['username']) : null;
    if (!$u) {
        return;
    }
    $k = get_user_meta($u->ID, '_nias_dev_kicked', true);
    if (!is_array($k) || empty($k[$did])) {
        return;
    }
    $GLOBALS['nias_dev_kicked'] = $k[$did]['r'];
    unset($k[$did]);
    update_user_meta($u->ID, '_nias_dev_kicked', $k);
    // The stale cookie is no longer useful.
    if (!headers_sent()) {
        wp_clear_auth_cookie();
    }
}, 2);

function nias_dev_kicked_message($reason)
{
    $m = array(
        'other'   => __('حساب شما روی دستگاه دیگری فعال شد و چون ورود همزمان مجاز نیست، از این دستگاه خارج شدید. برای ادامه دوباره وارد شوید.', 'nias-course-widget'),
        'removed' => __('این دستگاه از فهرست «دستگاه‌های من» حذف شد و از حساب خارج شدید.', 'nias-course-widget'),
        'oldest'  => __('حساب شما روی دستگاه جدیدی وارد شد و چون سقف دستگاه‌ها پر بود، این دستگاه (که کمتر استفاده شده بود) از حساب خارج شد.', 'nias-course-widget'),
    );
    return isset($m[$reason]) ? $m[$reason] : $m['removed'];
}

add_action('wp_footer', function () {
    if (empty($GLOBALS['nias_dev_kicked'])) {
        return;
    }
    echo nias_dev_notice_css() . '<div class="nias-dev-notice nias-dev-float" role="alert"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="5" y="2" width="14" height="20" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M11 18h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><div><b>' . esc_html__('از حساب خارج شدید', 'nias-course-widget') . '</b><br>' . esc_html(nias_dev_kicked_message($GLOBALS['nias_dev_kicked'])) . '</div></div>'; // phpcs:ignore
});

/** Close this session and send the person to the login page with the reason. */
function nias_dev_reject()
{
    wp_logout();
    wp_set_current_user(0);
    $ajax = wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/wp-json/') !== false);
    if ($ajax || headers_sent() || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET')) {
        return;
    }
    wp_safe_redirect(add_query_arg('nias_device_limit', '1', nias_dev_login_url()));
    exit;
}

function nias_dev_login_url()
{
    if (function_exists('wc_get_page_permalink') && function_exists('nias_wc_active') && nias_wc_active()) {
        $u = wc_get_page_permalink('myaccount');
        if ($u) {
            return $u;
        }
    }
    return wp_login_url();
}

/* Messages after a refusal ------------------------------------------------ */

function nias_dev_notice_html()
{
    return '<div class="nias-dev-notice" role="alert"><svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="5" y="2" width="14" height="20" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M11 18h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><div><b>' . esc_html__('ورود از این دستگاه ممکن نیست', 'nias-course-widget') . '</b><br>' . esc_html(nias_dev_limit_message()) . '</div></div>';
}

function nias_dev_notice_css()
{
    return '<style>.nias-dev-notice{display:flex;gap:12px;align-items:flex-start;direction:rtl;text-align:right;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:14px;padding:14px 16px;margin:0 0 16px;font-size:14px;line-height:1.9}.nias-dev-notice svg{flex:none;margin-top:3px}.nias-dev-float{position:fixed;z-index:99999;bottom:18px;left:50%;transform:translateX(-50%);width:min(560px,calc(100% - 24px));box-shadow:0 18px 40px -18px rgba(0,0,0,.35)}</style>';
}

add_filter('login_message', function ($m) {
    if (!empty($GLOBALS['nias_dev_kicked'])) {
        $m .= nias_dev_notice_css() . '<div class="nias-dev-notice" role="alert"><div><b>' . esc_html__('از حساب خارج شدید', 'nias-course-widget') . '</b><br>' . esc_html(nias_dev_kicked_message($GLOBALS['nias_dev_kicked'])) . '</div></div>';
        $GLOBALS['nias_dev_kicked'] = '';
    }
    return isset($_GET['nias_device_limit']) ? $m . nias_dev_notice_css() . nias_dev_notice_html() : $m;
});
add_action('woocommerce_before_customer_login_form', function () {
    if (isset($_GET['nias_device_limit'])) {
        echo nias_dev_notice_css() . nias_dev_notice_html(); // phpcs:ignore -- escaped
        $GLOBALS['nias_dev_notice_done'] = true;
    }
});
// Any other login page (e.g. Nias Login's own form): a floating notice.
add_action('wp_footer', function () {
    if (isset($_GET['nias_device_limit']) && empty($GLOBALS['nias_dev_notice_done']) && !is_user_logged_in()) {
        echo nias_dev_notice_css() . str_replace('class="nias-dev-notice"', 'class="nias-dev-notice nias-dev-float"', nias_dev_notice_html()); // phpcs:ignore
    }
});

/* -------------------------------------------------------------------------
 * «دستگاه‌های من» (student)
 * ---------------------------------------------------------------------- */

/** Removals left in the last 30 days (null = unlimited). */
function nias_dev_removals_left($user_id)
{
    $limit = nias_dev_opt('removals');
    if (!$limit) {
        return null;
    }
    $log = array_filter((array) get_user_meta($user_id, '_nias_dev_removals', true), function ($t) {
        return (int) $t > time() - 30 * DAY_IN_SECONDS;
    });
    return max(0, $limit - count($log));
}

function nias_dev_date($ts)
{
    if (!$ts) {
        return '—';
    }
    $ymd = wp_date('Y-m-d', $ts);
    $d   = function_exists('nias_cert_jalali') ? nias_cert_jalali($ymd, 'long') : $ymd;
    $out = $d . '، ' . wp_date('H:i', $ts);
    return function_exists('nias_fa_digits') ? nias_fa_digits($out) : $out;
}

function nias_dev_icon($kind)
{
    if ($kind === 'mobile') {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="6" y="2" width="12" height="20" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M11 18h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    if ($kind === 'tablet') {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="4" y="2" width="16" height="20" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="M11 18h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="4" width="20" height="13" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
}

/** Handle «حذف دستگاه» from the account page. */
add_action('template_redirect', function () {
    if (!isset($_POST['nias_dev_remove']) || !is_user_logged_in() || !nias_dev_enabled()) {
        return;
    }
    $uid = get_current_user_id();
    $did = sanitize_key(wp_unslash($_POST['nias_dev_remove']));
    $ok  = 'err';
    if (check_admin_referer('nias_dev_remove_' . $uid, 'nias_dev_nonce') && nias_dev_opt('self') === 'on' && $did !== nias_dev_current_id()) {
        $left = nias_dev_removals_left($uid);
        if ($left === null || $left > 0) {
            if (nias_dev_remove($uid, $did, 'user')) {
                $log   = (array) get_user_meta($uid, '_nias_dev_removals', true);
                $log[] = time();
                update_user_meta($uid, '_nias_dev_removals', array_slice($log, -50));
                $ok = 'ok';
            }
        } else {
            $ok = 'limit';
        }
    }
    // Back to the same page (the form posts to itself).
    $back = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '') . (isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/');
    wp_safe_redirect(add_query_arg('nias_dev', $ok, remove_query_arg('nias_dev', $back)));
    exit;
});

function nias_dev_account_html($user_id = 0)
{
    $user_id = $user_id ? $user_id : get_current_user_id();
    if (!$user_id) {
        return '';
    }
    $list = nias_dev_list($user_id);
    $cur  = nias_dev_current_id();
    uasort($list, function ($a, $b) { return (int) $b['last'] - (int) $a['last']; });
    $max  = nias_dev_opt('max');
    $self = nias_dev_opt('self') === 'on';
    $left = nias_dev_removals_left($user_id);
    $fa   = function ($n) { return function_exists('nias_fa_digits') ? nias_fa_digits($n) : $n; };
    $flash = isset($_GET['nias_dev']) ? sanitize_key(wp_unslash($_GET['nias_dev'])) : '';

    ob_start();
    ?>
    <style>
    .ndv{direction:rtl;font-size:14px}
    .ndv-sum{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;background:#f5f8ff;border:1px solid #e1e9fb;border-radius:14px;padding:12px 14px;margin-bottom:14px;line-height:1.9}
    .ndv-sum b{font-size:15px}
    .ndv-bar{flex:1 1 140px;height:8px;border-radius:99px;background:#e3e9f6;overflow:hidden;min-width:120px}
    .ndv-bar i{display:block;height:100%;background:#3858e9;border-radius:99px}
    .ndv-list{display:flex;flex-direction:column;gap:10px;margin:0;padding:0;list-style:none}
    .ndv-it{display:flex;align-items:center;gap:12px;border:1px solid #e6ecf5;border-radius:14px;padding:12px 14px;background:#fff}
    .ndv-it.cur{border-color:#b7c8f7;background:#fafcff}
    .ndv-ic{flex:none;width:42px;height:42px;border-radius:12px;background:#eef3ff;color:#3858e9;display:flex;align-items:center;justify-content:center}
    .ndv-b{flex:1 1 auto;min-width:0}
    .ndv-t{font-weight:800;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
    .ndv-cur{font-size:11.5px;font-weight:700;background:#e7f7ec;color:#137a3a;border-radius:99px;padding:1px 9px}
    .ndv-m{font-size:12.5px;color:#64748b;line-height:1.9}
    .ndv-rm{flex:none;border:1px solid #f3c9c9;background:#fff;color:#c0392b;border-radius:10px;padding:6px 12px;font:inherit;font-weight:700;cursor:pointer}
    .ndv-rm:hover{background:#fdf0f0}
    .ndv-note{font-size:12.5px;color:#64748b;margin-top:12px;line-height:1.9}
    .ndv-flash{border-radius:12px;padding:10px 14px;margin-bottom:12px;font-weight:700}
    .ndv-flash.ok{background:#e7f7ec;color:#137a3a}.ndv-flash.limit,.ndv-flash.err{background:#fdeeee;color:#9b2c2c}
    </style>
    <div class="ndv">
        <?php if ($flash === 'ok') : ?><div class="ndv-flash ok"><?php echo esc_html__('دستگاه حذف شد و از حساب شما خارج شد.', 'nias-course-widget'); ?></div><?php endif; ?>
        <?php if ($flash === 'limit') : ?><div class="ndv-flash limit"><?php echo esc_html__('سقف حذف دستگاه در ۳۰ روز گذشته پر شده است؛ برای حذف بیشتر با پشتیبانی تماس بگیرید.', 'nias-course-widget'); ?></div><?php endif; ?>
        <?php if ($flash === 'err') : ?><div class="ndv-flash err"><?php echo esc_html__('حذف دستگاه انجام نشد.', 'nias-course-widget'); ?></div><?php endif; ?>
        <div class="ndv-sum">
            <b><?php echo esc_html(sprintf(__('%1$s از %2$s دستگاه', 'nias-course-widget'), $fa(count($list)), $fa($max))); ?></b>
            <span class="ndv-bar"><i style="width:<?php echo (int) min(100, round(count($list) / $max * 100)); ?>%"></i></span>
            <span><?php echo esc_html(nias_dev_opt('single') === 'on' ? __('ورود همزمان مجاز نیست: با ورود از یک دستگاه، بقیه خارج می‌شوند.', 'nias-course-widget') : __('حساب شما روی این دستگاه‌ها فعال است.', 'nias-course-widget')); ?></span>
        </div>
        <ul class="ndv-list">
            <?php if (!$list) : ?><li class="ndv-m"><?php echo esc_html__('هنوز دستگاهی ثبت نشده است.', 'nias-course-widget'); ?></li><?php endif; ?>
            <?php foreach ($list as $id => $d) : $is = ($id === $cur); ?>
                <li class="ndv-it<?php echo $is ? ' cur' : ''; ?>">
                    <span class="ndv-ic"><?php echo nias_dev_icon(isset($d['kind']) ? $d['kind'] : 'desktop'); // phpcs:ignore -- static svg ?></span>
                    <div class="ndv-b">
                        <div class="ndv-t"><?php echo esc_html($d['label']); ?><?php if ($is) : ?><span class="ndv-cur"><?php echo esc_html__('همین دستگاه', 'nias-course-widget'); ?></span><?php endif; ?></div>
                        <div class="ndv-m"><?php echo esc_html(sprintf(__('اولین ورود: %1$s · آخرین فعالیت: %2$s', 'nias-course-widget'), nias_dev_date($d['first']), nias_dev_date($d['last']))); ?></div>
                    </div>
                    <?php if ($self && !$is && ($left === null || $left > 0)) : ?>
                        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('این دستگاه حذف شود؟ حساب شما روی آن دستگاه خارج می‌شود.', 'nias-course-widget')); ?>')">
                            <?php wp_nonce_field('nias_dev_remove_' . $user_id, 'nias_dev_nonce'); ?>
                            <button type="submit" class="ndv-rm" name="nias_dev_remove" value="<?php echo esc_attr($id); ?>"><?php echo esc_html__('حذف', 'nias-course-widget'); ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="ndv-note">
            <?php
            if (!$self) {
                echo esc_html__('برای حذف یک دستگاه با پشتیبانی تماس بگیرید.', 'nias-course-widget');
            } elseif ($left !== null) {
                echo esc_html(sprintf(__('در هر ۳۰ روز می‌توانید %1$s دستگاه حذف کنید (باقی‌مانده: %2$s). پاک کردن کوکی‌ها یا حالت ناشناس مرورگر هم یک دستگاه جدید حساب می‌شود.', 'nias-course-widget'), $fa(nias_dev_opt('removals')), $fa($left)));
            } else {
                echo esc_html__('پاک کردن کوکی‌ها یا حالت ناشناس مرورگر هم یک دستگاه جدید حساب می‌شود.', 'nias-course-widget');
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('nias_my_devices', function () {
    if (!nias_dev_enabled()) {
        return '';
    }
    return is_user_logged_in() ? nias_dev_account_html() : '<p>' . esc_html__('برای دیدن دستگاه‌های خود وارد حساب کاربری شوید.', 'nias-course-widget') . '</p>';
});

// WooCommerce account tab.
function nias_dev_endpoint()
{
    return (string) apply_filters('nias_dev_endpoint', 'my-devices');
}
add_action('init', function () {
    if (nias_dev_enabled() && function_exists('nias_wc_active') && nias_wc_active()) {
        add_rewrite_endpoint(nias_dev_endpoint(), EP_ROOT | EP_PAGES);
        if (get_option('nias_dev_rewrite') !== 'on') {
            add_action('wp_loaded', function () { flush_rewrite_rules(false); update_option('nias_dev_rewrite', 'on'); });
        }
    }
}, 20);
add_filter('woocommerce_get_query_vars', function ($vars) {
    if (nias_dev_enabled()) {
        $vars[nias_dev_endpoint()] = nias_dev_endpoint();
    }
    return $vars;
});
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!nias_dev_enabled() || nias_dev_exempt(get_current_user_id())) {
        return $items;
    }
    $out = array();
    foreach ($items as $k => $v) {
        if ($k === 'customer-logout') {
            $out[nias_dev_endpoint()] = __('دستگاه‌های من', 'nias-course-widget');
        }
        $out[$k] = $v;
    }
    if (!isset($out[nias_dev_endpoint()])) {
        $out[nias_dev_endpoint()] = __('دستگاه‌های من', 'nias-course-widget');
    }
    return $out;
}, 30);
add_action('woocommerce_account_my-devices_endpoint', function () {
    echo '<h3>' . esc_html__('دستگاه‌های من', 'nias-course-widget') . '</h3>' . nias_dev_account_html(); // phpcs:ignore -- escaped inside
});
add_filter('woocommerce_endpoint_my-devices_title', function () {
    return __('دستگاه‌های من', 'nias-course-widget');
});

/* -------------------------------------------------------------------------
 * Admin: a user's devices on the profile screen
 * ---------------------------------------------------------------------- */

add_action('edit_user_profile', 'nias_dev_profile_box');
add_action('show_user_profile', 'nias_dev_profile_box');
function nias_dev_profile_box($user)
{
    if (!nias_dev_enabled() || !current_user_can('edit_users')) {
        return;
    }
    $list = nias_dev_list($user->ID);
    uasort($list, function ($a, $b) { return (int) $b['last'] - (int) $a['last']; });
    $url = function ($did) use ($user) {
        return wp_nonce_url(admin_url('admin-post.php?action=nias_dev_admin_remove&user=' . (int) $user->ID . '&device=' . rawurlencode($did)), 'nias_dev_admin_' . $user->ID);
    };
    echo '<h2 id="nias-devices">' . esc_html__('دستگاه‌های کاربر (دوره ساز نیاس)', 'nias-course-widget') . '</h2>';
    if (isset($_GET['nias_dev_done'])) {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('انجام شد.', 'nias-course-widget') . '</p></div>';
    }
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('دستگاه', 'nias-course-widget') . '</th><th>' . esc_html__('اولین ورود', 'nias-course-widget') . '</th><th>' . esc_html__('آخرین فعالیت', 'nias-course-widget') . '</th><th>IP</th><th></th></tr></thead><tbody>';
    if (!$list) {
        echo '<tr><td colspan="5">' . esc_html__('دستگاهی ثبت نشده است.', 'nias-course-widget') . '</td></tr>';
    }
    foreach ($list as $id => $d) {
        echo '<tr><td><b>' . esc_html($d['label']) . '</b><br><small style="color:#64748b">' . esc_html(wp_html_excerpt($d['ua'], 90, '…')) . '</small></td><td>' . esc_html(nias_dev_date($d['first'])) . '</td><td>' . esc_html(nias_dev_date($d['last'])) . '</td><td dir="ltr">' . esc_html($d['ip']) . '</td><td><a class="button button-small" href="' . esc_url($url($id)) . '">' . esc_html__('حذف', 'nias-course-widget') . '</a></td></tr>';
    }
    echo '</tbody></table>';
    $left = nias_dev_removals_left($user->ID);
    echo '<p>' . esc_html(sprintf(__('سقف: %1$s دستگاه.', 'nias-course-widget'), nias_dev_opt('max')))
        . ($left !== null ? ' ' . esc_html(sprintf(__('حذف توسط خود کاربر در ۳۰ روز اخیر: %s باقی‌مانده.', 'nias-course-widget'), $left)) : '')
        . ($list ? ' <a class="button" href="' . esc_url($url('*')) . '" onclick="return confirm(\'' . esc_js(__('همهٔ دستگاه‌های این کاربر حذف شوند؟ از همه‌جا خارج می‌شود.', 'nias-course-widget')) . '\')">' . esc_html__('حذف همهٔ دستگاه‌ها', 'nias-course-widget') . '</a>' : '')
        . ' <a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_dev_admin_remove&user=' . (int) $user->ID . '&device=reset-removals'), 'nias_dev_admin_' . $user->ID)) . '">' . esc_html__('صفر کردن سهمیهٔ حذف', 'nias-course-widget') . '</a></p>';
}

add_action('admin_post_nias_dev_admin_remove', function () {
    $uid = isset($_GET['user']) ? (int) $_GET['user'] : 0;
    if (!$uid || !current_user_can('edit_user', $uid) || !check_admin_referer('nias_dev_admin_' . $uid)) {
        wp_die(esc_html__('دسترسی ندارید.', 'nias-course-widget'), 403);
    }
    $did = isset($_GET['device']) ? sanitize_text_field(wp_unslash($_GET['device'])) : '';
    if ($did === '*') {
        foreach (array_keys(nias_dev_list($uid)) as $id) {
            nias_dev_remove($uid, $id, 'admin');
        }
        delete_user_meta($uid, '_nias_dev_active');
    } elseif ($did === 'reset-removals') {
        delete_user_meta($uid, '_nias_dev_removals');
    } else {
        nias_dev_remove($uid, sanitize_key($did), 'admin');
    }
    wp_safe_redirect(add_query_arg('nias_dev_done', '1', get_edit_user_link($uid)) . '#nias-devices');
    exit;
});
