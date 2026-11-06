<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «لینک اختصاصی فایل‌ها» — per-user links for the videos and files of private lessons.
 *
 * When the option is on, the real address of a private lesson's video, audio
 * or download file never reaches the browser. The page gets a signed link
 * instead:  https://site/?nias_media=<token>
 *
 *   - The token is encrypted and signed with the site salts, and carries the
 *     user id, the course id, the kind (video|file), an expiry and the real URL.
 *   - Opening it checks, on every request, that the visitor is logged in, is
 *     the same user the link was made for, the link has not expired and the
 *     user still has access to the course. Otherwise a clear error is shown:
 *     «وارد سایت نشده‌اید» / «این لینک متعلق به شما نیست» / «منقضی شده».
 *   - Files on this site are streamed by PHP (with Range support, so seeking
 *     works); X-Sendfile / X-Accel-Redirect can take over through the
 *     nias_secure_media_sendfile filter. External files are redirected to
 *     after the checks pass.
 *   - Public lessons, embeds (Aparat/YouTube…) and HLS/DASH streams keep their
 *     own address: their parts cannot be protected one by one this way.
 *
 * @package nias-course-widget
 */

/** Is the option on? */
function nias_secure_media_enabled()
{
    return (bool) apply_filters('nias_secure_media_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_secure_media') === 'on');
}

/** Lifetime of a link, in seconds (default 12 hours). */
function nias_secure_media_ttl()
{
    return max(300, (int) apply_filters('nias_secure_media_ttl', 12 * HOUR_IN_SECONDS));
}

/** Can this URL be wrapped? (http(s), not an HLS/DASH manifest.) */
function nias_secure_media_wrappable($url)
{
    $url = (string) $url;
    if (!preg_match('#^https?://#i', $url) || strpos($url, 'nias_media=') !== false) {
        return false; // not http(s), or already a signed link
    }
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    return !preg_match('/\.(m3u8|mpd|ts|m4s)$/', $path);
}

function nias_secure_media_b64e($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function nias_secure_media_b64d($s)
{
    $s = strtr((string) $s, '-_', '+/');
    return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** Encryption + signing keys, derived from the site salts. */
function nias_secure_media_keys()
{
    $salt = wp_salt('auth') . wp_salt('secure_auth');
    return array(hash_hmac('sha256', 'nias-media-enc', $salt, true), hash_hmac('sha256', 'nias-media-mac', $salt, true));
}

/** Seal a payload: AES-256-CTR + HMAC-SHA256 (openssl), or signed + stored URL as a fallback. */
function nias_secure_media_seal(array $payload)
{
    list($ek, $mk) = nias_secure_media_keys();
    $json = wp_json_encode($payload);
    if (function_exists('openssl_encrypt') && in_array('aes-256-ctr', openssl_get_cipher_methods(), true)) {
        $iv  = random_bytes(16);
        $raw = $iv . openssl_encrypt($json, 'aes-256-ctr', $ek, OPENSSL_RAW_DATA, $iv);
        return 'e' . nias_secure_media_b64e($raw . substr(hash_hmac('sha256', $raw, $mk, true), 0, 16));
    }
    // No openssl: keep the URL on the server, keyed by its hash.
    $key = substr(md5($payload['url']), 0, 16);
    set_transient('nias_media_' . $key, $payload['url'], nias_secure_media_ttl() + HOUR_IN_SECONDS);
    $payload['url'] = $key;
    $raw = wp_json_encode($payload);
    return 's' . nias_secure_media_b64e($raw . substr(hash_hmac('sha256', $raw, $mk, true), 0, 16));
}

/** Open a token. Returns the payload, or null when it was forged / damaged. */
function nias_secure_media_open($token)
{
    $token = (string) $token;
    if (strlen($token) < 20 || strlen($token) > 4096) {
        return null;
    }
    list($ek, $mk) = nias_secure_media_keys();
    $bin = nias_secure_media_b64d(substr($token, 1));
    if ($bin === false || strlen($bin) < 33) {
        return null;
    }
    $raw = substr($bin, 0, -16);
    if (!hash_equals(substr(hash_hmac('sha256', $raw, $mk, true), 0, 16), substr($bin, -16))) {
        return null;
    }
    if ($token[0] === 'e') {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }
        $json = openssl_decrypt(substr($raw, 16), 'aes-256-ctr', $ek, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    } elseif ($token[0] === 's') {
        $json = $raw;
    } else {
        return null;
    }
    $p = json_decode((string) $json, true);
    if (!is_array($p) || !isset($p['u'], $p['p'], $p['k'], $p['e'], $p['url'])) {
        return null;
    }
    if ($token[0] === 's') {
        $p['url'] = (string) get_transient('nias_media_' . preg_replace('/[^a-f0-9]/', '', $p['url']));
    }
    return $p;
}

/**
 * The per-user link of a private lesson's media, or the URL unchanged when the
 * option is off, nobody is logged in, or the URL cannot be wrapped.
 *
 * @param string $url     Real address.
 * @param int    $post_id Course (product / «دوره»).
 * @param string $kind    video|file (file = download).
 */
function nias_secure_media_url($url, $post_id, $kind = 'file', $public = false)
{
    $uid = get_current_user_id();
    if (!$url || !nias_secure_media_wrappable($url)) {
        return $url;
    }
    // Files in the protected folder can only be reached through a signed link:
    // always wrapped (a public lesson gets a "public" link, valid for anyone).
    $protected = function_exists('nias_protect_is_url') && nias_protect_is_url($url);
    if (!$protected && ($public || !$uid || !nias_secure_media_enabled())) {
        return $url;
    }
    $public = $public || !$uid;
    $exp = time() + nias_secure_media_ttl();
    // One token per user/url/kind for the same hour: pages and repeated
    // renders reuse it instead of encrypting again.
    static $memo = array();
    $mk = $uid . '|' . $post_id . '|' . $kind . '|' . ($public ? 1 : 0) . '|' . $url;
    if (!isset($memo[$mk])) {
        $memo[$mk] = add_query_arg('nias_media', nias_secure_media_seal(array(
            'pub' => $public ? 1 : 0,
            'u'   => (int) $uid,
            'p'   => (int) $post_id,
            'k'   => $kind === 'video' ? 'video' : 'file',
            'e'   => $exp,
            'url' => (string) $url,
        )), home_url('/'));
    }
    return $memo[$mk];
}

/** Does this user still have the right to this course's private media? */
function nias_secure_media_user_can($uid, $post_id, $kind)
{
    $uid = (int) $uid;
    $post_id = (int) $post_id;
    if (user_can($uid, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($uid, $post_id))) {
        $ok = true;
    } elseif (function_exists('nias_course_lock_part') && !nias_course_lock_part($kind === 'video' ? 'preview' : 'attachments')) {
        $ok = true; // the admin chose to show this part to non-buyers too
    } elseif (function_exists('nias_course_is_free') && nias_course_is_free($post_id)) {
        $ok = function_exists('nias_user_has_course_access') ? nias_user_has_course_access($uid, $post_id) : true;
    } else {
        if (!function_exists('nias_has_bought_course') && defined('NIAS_FUNCTIONS') && file_exists(NIAS_FUNCTIONS)) {
            include_once NIAS_FUNCTIONS;
        }
        $u  = get_userdata($uid);
        $ok = false;
        if (function_exists('nias_has_bought_course') && $u) {
            $ok = (bool) nias_has_bought_course($uid, $u->user_login, $post_id);
        } elseif (function_exists('nias_course_user_completed')) {
            $ok = function_exists('nias_user_has_course_access') ? nias_user_has_course_access($uid, $post_id) : (bool) nias_course_user_completed($uid, $post_id);
        }
    }
    return (bool) apply_filters('nias_secure_media_user_can', $ok, $uid, $post_id, $kind);
}

/* -------------------------------------------------------------------------
 * Serving
 * ---------------------------------------------------------------------- */

// wp_loaded: after every plugin's init (WooCommerce order types are needed for the access check).
add_action('wp_loaded', 'nias_secure_media_serve', 1);
function nias_secure_media_serve()
{
    if (!isset($_GET['nias_media'])) {
        return;
    }
    $p = nias_secure_media_open(sanitize_text_field(wp_unslash($_GET['nias_media'])));
    if (!$p || $p['url'] === '') {
        nias_secure_media_fail('invalid');
    }
    $uid = get_current_user_id();
    $pub = !empty($p['pub']); // a public lesson's protected file: no login needed
    if (!$pub && !$uid) {
        nias_secure_media_fail('login');
    }
    if (!$pub && (int) $p['u'] !== $uid) {
        nias_secure_media_fail('owner');
    }
    if ((int) $p['e'] < time()) {
        nias_secure_media_fail('expired', (int) $p['p']);
    }
    if (!nias_is_course_post((int) $p['p']) || (!$pub && !nias_secure_media_user_can($uid, (int) $p['p'], $p['k']))) {
        nias_secure_media_fail('access', (int) $p['p']);
    }
    do_action('nias_secure_media_served', $uid, (int) $p['p'], $p['k'], $p['url']);

    $path = nias_secure_media_local_path($p['url']);
    if ($path) {
        nias_secure_media_stream($path, $p['k']);
    }
    // An address on this site that is not a servable file (PHP, outside the site…): never.
    if (nias_secure_media_is_own($p['url'])) {
        nias_secure_media_fail('invalid', (int) $p['p']);
    }
    // External file: send the owner on after the checks.
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    wp_redirect(esc_url_raw($p['url']), 302, 'Nias Course');
    exit;
}

/** A clear error (a page for people, a bare 403 for the player's own requests). */
function nias_secure_media_fail($why, $post_id = 0)
{
    $msgs = array(
        'login'   => array(__('شما وارد سایت نشده‌اید', 'nias-course-widget'), __('این لینک اختصاصی است. برای مشاهدهٔ فایل ابتدا با حساب خودتان وارد سایت شوید.', 'nias-course-widget')),
        'owner'   => array(__('این لینک متعلق به شما نیست', 'nias-course-widget'), __('لینک‌های فایل‌های دوره برای هر کاربر جداگانه ساخته می‌شوند و فقط برای صاحب همان حساب کار می‌کنند. فایل را از صفحهٔ دوره در حساب خودتان باز کنید.', 'nias-course-widget')),
        'expired' => array(__('اعتبار این لینک تمام شده است', 'nias-course-widget'), __('صفحهٔ دوره را دوباره باز کنید تا لینک تازه ساخته شود.', 'nias-course-widget')),
        'access'  => array(__('به این دوره دسترسی ندارید', 'nias-course-widget'), __('این فایل فقط برای دانشجویان این دوره است.', 'nias-course-widget')),
        'invalid' => array(__('لینک نامعتبر است', 'nias-course-widget'), __('این لینک کامل نیست یا تغییر کرده است.', 'nias-course-widget')),
    );
    $m = isset($msgs[$why]) ? $msgs[$why] : $msgs['invalid'];
    do_action('nias_secure_media_denied', $why, get_current_user_id(), (int) $post_id);

    nocache_headers();
    header('X-Robots-Tag: noindex');
    // The player / <video> asks with a Range header or for media: no HTML page then.
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
    if (isset($_SERVER['HTTP_RANGE']) || (strpos($accept, 'text/html') === false && $accept !== '' && $accept !== '*/*')) {
        status_header($why === 'login' ? 401 : 403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m[0];
        exit;
    }
    $here = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '') . (isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/');
    $link = '';
    if ($why === 'login') {
        $link = '<p><a class="button" href="' . esc_url(wp_login_url($here)) . '">' . esc_html__('ورود به سایت', 'nias-course-widget') . '</a></p>';
    } elseif ($post_id && get_post_status($post_id) === 'publish') {
        $link = '<p><a class="button" href="' . esc_url(get_permalink($post_id)) . '">' . esc_html__('رفتن به صفحهٔ دوره', 'nias-course-widget') . '</a></p>';
    } else {
        $link = '<p><a class="button" href="' . esc_url(home_url('/')) . '">' . esc_html__('صفحهٔ اصلی سایت', 'nias-course-widget') . '</a></p>';
    }
    wp_die(
        '<h1>' . esc_html($m[0]) . '</h1><p>' . esc_html($m[1]) . '</p>' . $link,
        esc_html($m[0]),
        array('response' => $why === 'login' ? 401 : 403, 'text_direction' => 'rtl')
    );
}

/** Is this address on this site? */
function nias_secure_media_is_own($url)
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $own  = array(strtolower((string) parse_url(home_url(), PHP_URL_HOST)), strtolower((string) parse_url(site_url(), PHP_URL_HOST)));
    return $host !== '' && in_array(preg_replace('/^www\./', '', $host), array_map(function ($h) { return preg_replace('/^www\./', '', $h); }, $own), true);
}

/** Real path of a file of this site, or '' (outside the site / missing / PHP). */
function nias_secure_media_local_path($url)
{
    $strip = function ($u) {
        return preg_replace('#^https?://(www\.)?#i', '', strtok((string) $u, '?#'));
    };
    $u   = $strip($url);
    $up  = wp_get_upload_dir();
    $map = array(
        $strip($up['baseurl']) => $up['basedir'],
        $strip(content_url())  => WP_CONTENT_DIR,
        $strip(site_url())     => ABSPATH,
    );
    foreach ($map as $base => $dir) {
        $base = rtrim($base, '/') . '/';
        if (strpos($u, $base) === 0) {
            $file = realpath(rtrim($dir, '/\\') . '/' . rawurldecode(substr($u, strlen($base))));
            $root = realpath(ABSPATH);
            if ($file && $root && is_file($file) && strpos(wp_normalize_path($file), wp_normalize_path($root)) === 0
                && !preg_match('/\.(php\d?|phtml|phar|htaccess|ini|log|sql)$/i', $file)) {
                return $file;
            }
            return '';
        }
    }
    return '';
}

/** Stream a local file (Range requests for seeking). */
function nias_secure_media_stream($file, $kind)
{
    $size = filesize($file);
    $type = wp_check_filetype($file);
    $mime = $type['type'] ? $type['type'] : 'application/octet-stream';
    $name = basename($file);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Expires');
    header_remove('Pragma');
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    header('Accept-Ranges: bytes');
    // Lesson video/audio plays in the page; a lesson file is a download.
    $inline = ($kind === 'video');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));

    // Hand off to the web server when it can send files itself.
    $sendfile = apply_filters('nias_secure_media_sendfile', '', $file);
    if (is_array($sendfile) && !empty($sendfile['header'])) {
        header($sendfile['header'] . ': ' . $sendfile['value']);
        exit;
    }

    $start = 0;
    $end   = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
        if ($start > $end || $start >= $size) {
            status_header(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        status_header(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    } else {
        status_header(200);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    $fh = fopen($file, 'rb');
    if (!$fh) {
        exit;
    }
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int) min(1048576, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}
