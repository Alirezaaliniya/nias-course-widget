<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «بستن دسترسی مستقیم به فایل‌ها» (protected course files).
 *
 *   - Course files live in uploads/nias-protected/ (same YYYY/MM layout).
 *     The folder refuses direct requests: .htaccess (Apache / LiteSpeed),
 *     web.config (IIS); Nginx needs one location rule, shown in the settings.
 *   - Files there are reachable only through the signed links of
 *     inc/secure-media.php: per user for private lessons, a short-lived
 *     "public" link for public lessons. Lesson text (e.g. a [video]
 *     shortcode) is rewritten the same way.
 *   - Uploads made from the curriculum editor's video/file fields go straight
 *     to the folder. A tool moves the files of existing courses (attachment
 *     records and lesson addresses are updated).
 *   - Big files: X-Sendfile (Apache) or X-Accel-Redirect (Nginx) can send
 *     them instead of PHP.
 *
 * @package nias-course-widget
 */

define('NIAS_PROTECT_DIR', 'nias-protected');

function nias_protect_enabled()
{
    return (bool) apply_filters('nias_protect_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_protect_enabled') === 'on');
}

/** Path + URL of the protected folder. */
function nias_protect_base()
{
    static $b = null;
    if ($b === null) {
        $up = wp_get_upload_dir();
        $b  = array(
            'dir' => wp_normalize_path(trailingslashit($up['basedir']) . NIAS_PROTECT_DIR),
            'url' => trailingslashit($up['baseurl']) . NIAS_PROTECT_DIR,
        );
    }
    return $b;
}

/** Is this address a file of the protected folder? (always, even with the module off) */
function nias_protect_is_url($url)
{
    $url = (string) $url;
    if ($url === '' || strpos($url, '/' . NIAS_PROTECT_DIR . '/') === false) {
        return false;
    }
    $strip = function ($u) {
        return preg_replace('#^https?://(www\.)?#i', '', $u);
    };
    return strpos($strip($url), $strip(nias_protect_base()['url']) . '/') === 0;
}

/** Create the folder and its guard files (idempotent). */
function nias_protect_ensure_folder()
{
    $dir = nias_protect_base()['dir'];
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return false;
    }
    $files = array(
        '.htaccess'  => "# Nias Course: files here are served only through signed links.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nOptions -Indexes\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        'index.php'  => "<?php\n// Silence is golden.\n",
        'nias-protect-test.txt' => "nias-protect-check\n",
    );
    foreach ($files as $name => $body) {
        $f = $dir . '/' . $name;
        if (!file_exists($f) || ($name === '.htaccess' && strpos((string) file_get_contents($f), 'Nias Course') === false)) {
            @file_put_contents($f, $body);
        }
    }
    return true;
}

add_action('update_option__nias_protect_enabled', function ($old, $new) {
    if ($new === 'on') {
        nias_protect_ensure_folder();
        delete_transient('nias_protect_status');
    }
}, 10, 2);
add_action('add_option__nias_protect_enabled', function ($n, $v) {
    if ($v === 'on') {
        nias_protect_ensure_folder();
    }
}, 10, 2);

/** Live check: does a direct request to the folder get refused? (cached 10 min) */
function nias_protect_status($fresh = false)
{
    $st = $fresh ? false : get_transient('nias_protect_status');
    if (is_array($st)) {
        return $st;
    }
    nias_protect_ensure_folder();
    $url = nias_protect_base()['url'] . '/nias-protect-test.txt';
    $r   = wp_remote_get(add_query_arg('t', time(), $url), array('timeout' => 8, 'sslverify' => false, 'redirection' => 0));
    if (is_wp_error($r)) {
        $st = array('state' => 'unknown', 'code' => 0, 'msg' => $r->get_error_message());
    } else {
        $code = (int) wp_remote_retrieve_response_code($r);
        $open = $code === 200 && strpos((string) wp_remote_retrieve_body($r), 'nias-protect-check') !== false;
        $st   = array('state' => $open ? 'open' : 'closed', 'code' => $code, 'msg' => '');
    }
    $st['server'] = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
    set_transient('nias_protect_status', $st, 10 * MINUTE_IN_SECONDS);
    return $st;
}

/** Nginx rules shown in the settings. */
function nias_protect_nginx_rules()
{
    $path = wp_parse_url(nias_protect_base()['url'], PHP_URL_PATH);
    $rules = "location ^~ " . $path . "/ {\n    deny all;\n    return 403;\n}";
    if (nias_protect_send_mode() === 'xaccel') {
        $rules .= "\n\n# X-Accel-Redirect (ارسال فایل توسط Nginx)\nlocation ^~ /nias-protected-internal/ {\n    internal;\n    alias " . trailingslashit(nias_protect_base()['dir']) . ";\n}";
    }
    return $rules;
}

/* -------------------------------------------------------------------------
 * Sending through the web server
 * ---------------------------------------------------------------------- */

function nias_protect_send_mode()
{
    $m = function_exists('carbon_get_theme_option') ? carbon_get_theme_option('nias_protect_send') : '';
    return in_array($m, array('xsendfile', 'xaccel'), true) ? $m : 'php';
}

add_filter('nias_secure_media_sendfile', function ($v, $file) {
    $mode = nias_protect_send_mode();
    if ($mode === 'php' || !nias_protect_enabled()) {
        return $v;
    }
    if ($mode === 'xsendfile') {
        return array('header' => 'X-Sendfile', 'value' => $file);
    }
    $base = nias_protect_base()['dir'];
    $nf   = wp_normalize_path($file);
    if (strpos($nf, $base . '/') === 0) {
        return array('header' => 'X-Accel-Redirect', 'value' => '/nias-protected-internal/' . implode('/', array_map('rawurlencode', explode('/', substr($nf, strlen($base) + 1)))));
    }
    return $v;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Signed links in lesson text and in the older views
 * ---------------------------------------------------------------------- */

/** Replace protected addresses in HTML with signed links. */
function nias_protect_wrap_html($html, $post_id, $private)
{
    $html = (string) $html;
    $base = nias_protect_base()['url'];
    if ($html === '' || strpos($html, '/' . NIAS_PROTECT_DIR . '/') === false || !function_exists('nias_secure_media_url')) {
        return $html;
    }
    $host = preg_quote(preg_replace('#^https?://(www\.)?#i', '', $base), '#');
    return preg_replace_callback('#https?://(?:www\.)?' . $host . '/[^\s"\'<>\]\[]+#i', function ($m) use ($post_id, $private) {
        $u    = html_entity_decode($m[0]);
        $kind = preg_match('/\.(mp4|m4v|webm|ogv|ogg|mov|mp3|m4a|aac|wav|oga|flac|opus)(\?|$)/i', $u) ? 'video' : 'file';
        return esc_url(nias_secure_media_url($u, $post_id, $kind, !$private));
    }, $html);
}

add_filter('nias_lesson_content_html', function ($html, $post_id, $private) {
    return nias_protect_wrap_html($html, $post_id, $private);
}, 10, 3);

/** Elementor widget / account view: wrap the stored addresses of a lesson. */
add_filter('nias_course_raw_lesson', function ($lesson, $post_id) {
    if (!is_array($lesson) || !$post_id || !function_exists('nias_secure_media_url')) {
        return $lesson;
    }
    $private = !empty($lesson['lesson_private']);
    foreach (array('lesson_preview_video' => array('video', array('video_upload', 'video_url')), 'lesson_download' => array('file', array('file_upload', 'file_url'))) as $field => $cfg) {
        if (empty($lesson[$field][0]) || !is_array($lesson[$field][0])) {
            continue;
        }
        foreach ($cfg[1] as $k) {
            if (!empty($lesson[$field][0][$k]) && nias_protect_is_url($lesson[$field][0][$k])) {
                $lesson[$field][0][$k] = nias_secure_media_url($lesson[$field][0][$k], $post_id, $cfg[0], !$private);
            }
        }
    }
    if (!empty($lesson['lesson_content'])) {
        $lesson['lesson_content'] = nias_protect_wrap_html($lesson['lesson_content'], $post_id, $private);
    }
    return $lesson;
}, 20, 2);

/* -------------------------------------------------------------------------
 * Uploads from the curriculum editor go to the protected folder
 * ---------------------------------------------------------------------- */

add_filter('upload_dir', function ($u) {
    static $busy = false; // nias_protect_base() reads the upload dir too
    if ($busy || empty($_POST['nias_protected']) || !nias_protect_enabled() || !is_user_logged_in()) {
        return $u;
    }
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    if ($action !== 'upload-attachment' && !(defined('DOING_AJAX') && DOING_AJAX)) {
        return $u;
    }
    $busy = true;
    nias_protect_ensure_folder();
    $busy = false;
    $u['subdir'] = '/' . NIAS_PROTECT_DIR . $u['subdir'];
    $u['path']   = $u['basedir'] . $u['subdir'];
    $u['url']    = $u['baseurl'] . $u['subdir'];
    return $u;
});

/** Protected attachments: no image sizes (they could not be shown anyway). */
add_filter('intermediate_image_sizes_advanced', function ($sizes, $meta) {
    return (!empty($meta['file']) && strpos($meta['file'], NIAS_PROTECT_DIR . '/') === 0) ? array() : $sizes;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Moving the files of existing courses
 * ---------------------------------------------------------------------- */

/** Local files of courses that are not protected yet: url => [course ids]. */
function nias_protect_scan()
{
    $up     = wp_get_upload_dir();
    $base   = preg_replace('#^https?://(www\.)?#i', '', trailingslashit($up['baseurl']));
    $found  = array();
    $ids    = get_posts(array('post_type' => nias_course_post_types(), 'post_status' => array('publish', 'draft', 'pending', 'private', 'future'), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true));
    $ids    = (array) apply_filters('nias_protect_scan_courses', $ids); // e.g. limit the move to some courses
    foreach ($ids as $pid) {
        $sections = carbon_get_post_meta($pid, 'course_sections');
        foreach ((array) $sections as $s) {
            foreach ((array) (isset($s['lessons']) ? $s['lessons'] : array()) as $l) {
                $urls = array();
                foreach (array('lesson_preview_video' => array('video_upload', 'video_url'), 'lesson_download' => array('file_upload', 'file_url')) as $f => $keys) {
                    foreach ($keys as $k) {
                        if (!empty($l[$f][0][$k])) {
                            $urls[] = (string) $l[$f][0][$k];
                        }
                    }
                }
                foreach ($urls as $u) {
                    $plain = preg_replace('#^https?://(www\.)?#i', '', strtok($u, '?#'));
                    if (strpos($plain, $base) !== 0 || nias_protect_is_url($u) || preg_match('/\.(php\d?|phtml|htaccess)$/i', $plain)) {
                        continue;
                    }
                    $rel  = rawurldecode(substr($plain, strlen($base)));
                    $file = wp_normalize_path(trailingslashit($up['basedir']) . $rel);
                    if (!is_file($file)) {
                        continue;
                    }
                    if (!isset($found[$u])) {
                        $found[$u] = array('rel' => $rel, 'size' => (int) filesize($file), 'courses' => array());
                    }
                    $found[$u]['courses'][(int) $pid] = (int) $pid;
                }
            }
        }
    }
    return $found;
}

/** Replace an address everywhere a course stores it (curriculum + pending changes). */
function nias_protect_replace_in_course($pid, $old, $new)
{
    $walk = function ($v) use (&$walk, $old, $new) {
        if (is_array($v)) {
            return array_map($walk, $v);
        }
        return is_string($v) ? str_replace($old, $new, $v) : $v;
    };
    $sections = carbon_get_post_meta($pid, 'course_sections');
    $changed  = $walk($sections);
    if ($changed !== $sections) {
        carbon_set_post_meta($pid, 'course_sections', $changed);
        do_action('nias_course_sections_saved', $pid);
    }
    $pending = get_post_meta($pid, '_nias_pending', true);
    if (is_array($pending)) {
        $np = $walk($pending);
        if ($np !== $pending) {
            update_post_meta($pid, '_nias_pending', $np);
        }
    }
}

/** Move one file into the protected folder. Returns the new URL or WP_Error. */
function nias_protect_move($url, $row)
{
    $up   = wp_get_upload_dir();
    $from = wp_normalize_path(trailingslashit($up['basedir']) . $row['rel']);
    $to   = nias_protect_base()['dir'] . '/' . $row['rel'];
    if (!is_file($from)) {
        return new WP_Error('missing', __('فایل پیدا نشد.', 'nias-course-widget'));
    }
    if (file_exists($to)) {
        $info = pathinfo($to);
        $to   = $info['dirname'] . '/' . wp_unique_filename($info['dirname'], $info['basename']);
    }
    if (!wp_mkdir_p(dirname($to))) {
        return new WP_Error('mkdir', __('ساخت پوشه ممکن نشد.', 'nias-course-widget'));
    }
    if (!@rename($from, $to)) {
        if (!@copy($from, $to) || !@unlink($from)) {
            return new WP_Error('move', __('جابه‌جایی فایل ممکن نشد (دسترسی نوشتن را بررسی کنید).', 'nias-course-widget'));
        }
    }
    $new_rel = NIAS_PROTECT_DIR . '/' . ltrim(substr($to, strlen(nias_protect_base()['dir'])), '/');
    $new_url = trailingslashit($up['baseurl']) . implode('/', array_map('rawurlencode', explode('/', $new_rel)));
    // Keep the stored spelling (encoded or not) of the old address.
    if (strpos($url, '%') === false) {
        $new_url = trailingslashit($up['baseurl']) . $new_rel;
    }
    $att = attachment_url_to_postid(strtok($url, '?#'));
    if ($att) {
        update_post_meta($att, '_wp_attached_file', $new_rel);
        $meta = wp_get_attachment_metadata($att);
        if (is_array($meta) && isset($meta['file'])) {
            $meta['file'] = $new_rel;
            wp_update_attachment_metadata($att, $meta);
        }
    }
    foreach ($row['courses'] as $pid) {
        nias_protect_replace_in_course($pid, $url, $new_url);
    }
    do_action('nias_protect_moved', $url, $new_url, $att);
    return $new_url;
}

add_action('wp_ajax_nias_protect_scan', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('nias_protect_tool', 'nonce', false)) {
        wp_send_json_error(array('message' => __('دسترسی ندارید.', 'nias-course-widget')), 403);
    }
    $found = nias_protect_scan();
    set_transient('nias_protect_queue', $found, HOUR_IN_SECONDS);
    $size = array_sum(wp_list_pluck($found, 'size'));
    $list = array();
    foreach (array_slice($found, 0, 15, true) as $u => $r) {
        $list[] = array('name' => basename($r['rel']), 'size' => size_format($r['size'], 1), 'courses' => count($r['courses']));
    }
    wp_send_json_success(array('count' => count($found), 'size' => size_format($size, 1), 'sample' => $list));
});

add_action('wp_ajax_nias_protect_move', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('nias_protect_tool', 'nonce', false)) {
        wp_send_json_error(array('message' => __('دسترسی ندارید.', 'nias-course-widget')), 403);
    }
    if (!nias_protect_enabled()) {
        wp_send_json_error(array('message' => __('ابتدا ماژول را فعال و ذخیره کنید.', 'nias-course-widget')));
    }
    nias_protect_ensure_folder();
    $queue = get_transient('nias_protect_queue');
    $queue = is_array($queue) ? $queue : nias_protect_scan();
    $done  = array();
    $errs  = array();
    foreach (array_slice($queue, 0, 3, true) as $url => $row) {
        $r = nias_protect_move($url, $row);
        unset($queue[$url]);
        if (is_wp_error($r)) {
            $errs[] = basename($row['rel']) . ': ' . $r->get_error_message();
        } else {
            $done[] = basename($row['rel']);
        }
    }
    set_transient('nias_protect_queue', $queue, HOUR_IN_SECONDS);
    wp_send_json_success(array('done' => $done, 'errors' => $errs, 'left' => count($queue)));
});

/* -------------------------------------------------------------------------
 * Settings (rendered inside the «ماژول‌ها» page)
 * ---------------------------------------------------------------------- */

function nias_protect_settings_html()
{
    $on = nias_protect_enabled();
    ob_start();
    if ($on) {
        $st = nias_protect_status(isset($_GET['nias_protect_check']));
        $cls = $st['state'] === 'closed' ? 'ok' : ($st['state'] === 'open' ? 'off' : 'warn');
        $nginx = stripos($st['server'], 'nginx') !== false;
        echo '<div class="npr-st ' . esc_attr($cls) . '"><b>';
        if ($st['state'] === 'closed') {
            echo esc_html__('پوشهٔ فایل‌های دوره بسته است: درخواست مستقیم رد می‌شود', 'nias-course-widget') . ' (HTTP ' . (int) $st['code'] . ').';
        } elseif ($st['state'] === 'open') {
            echo esc_html__('هشدار: پوشهٔ محافظت‌شده هنوز با آدرس مستقیم باز می‌شود!', 'nias-course-widget');
        } else {
            echo esc_html__('وضعیت پوشه قابل بررسی نبود', 'nias-course-widget') . ($st['msg'] ? ' (' . esc_html($st['msg']) . ')' : '');
        }
        echo '</b> <a href="' . esc_url(add_query_arg('nias_protect_check', '1')) . '">' . esc_html__('بررسی دوباره', 'nias-course-widget') . '</a>';
        echo '<div class="npr-path">' . esc_html__('پوشه:', 'nias-course-widget') . ' <code dir="ltr">' . esc_html(nias_protect_base()['dir']) . '</code>' . ($st['server'] ? ' · ' . esc_html__('وب‌سرور:', 'nias-course-widget') . ' <code dir="ltr">' . esc_html($st['server']) . '</code>' : '') . '</div>';
        if ($st['state'] !== 'closed' || $nginx) {
            echo '<div class="npr-nginx">' . esc_html__('روی Nginx فایل .htaccess خوانده نمی‌شود؛ این قانون را در تنظیمات سایت (server block) قرار دهید و Nginx را reload کنید:', 'nias-course-widget') . '<pre dir="ltr">' . esc_html(nias_protect_nginx_rules()) . '</pre></div>';
        }
        echo '</div>';
    }
    ?>
    <style>
    .npr-st{border-radius:13px;padding:12px 14px;margin:6px 0 4px;font-size:13.5px;line-height:1.9}
    .npr-st.ok{background:#ecf8ea;border:1px solid #cfeccb;color:#215f1b}
    .npr-st.off{background:#fdeeee;border:1px solid #f3c9c9;color:#8f2727}
    .npr-st.warn{background:#fff7e6;border:1px solid #f3dfb0;color:#7a5410}
    .npr-path{font-size:12.5px;opacity:.85}
    .npr-nginx pre{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:10px 12px;margin:8px 0 0;font-size:12px;overflow:auto;text-align:left}
    .npr-tool{display:flex;flex-direction:column;gap:10px}
    .npr-tool .npr-btns{display:flex;gap:8px;flex-wrap:wrap}
    .npr-tool button{border:1px solid #dbe3ef;background:#fff;border-radius:10px;padding:7px 14px;font:inherit;font-weight:700;cursor:pointer}
    .npr-tool button.pri{background:#3858e9;color:#fff;border-color:#3858e9}
    .npr-tool button:disabled{opacity:.55;cursor:default}
    .npr-out{font-size:13px;line-height:1.9;color:#334155}
    .npr-out ul{margin:4px 0;padding-inline-start:18px}
    .npr-bar{height:8px;border-radius:99px;background:#e3e9f6;overflow:hidden}
    .npr-bar i{display:block;height:100%;width:0;background:#3858e9;transition:width .3s}
    </style>
    <?php
    return ob_get_clean();
}

function nias_protect_tool_html()
{
    $nonce = wp_create_nonce('nias_protect_tool');
    ob_start();
    ?>
    <div class="npr-tool" data-npr data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <div class="nias-row-desc"><?php echo esc_html__('ویدیوها و فایل‌هایی که قبلاً در جلسات دوره‌ها بارگذاری شده‌اند (روی همین سایت) به پوشهٔ محافظت‌شده منتقل می‌شوند؛ آدرس جلسات و رکورد کتابخانهٔ رسانه خودکار به‌روز می‌شود. اگر همین فایل‌ها جای دیگری از سایت (نوشته‌ها، برگه‌ها) هم استفاده شده‌اند، آن‌جا دیگر مستقیم باز نمی‌شوند. پیش از انتقال از سایت پشتیبان بگیرید.', 'nias-course-widget'); ?></div>
        <div class="npr-btns"><button type="button" data-npr-scan><?php echo esc_html__('بررسی فایل‌های دوره‌ها', 'nias-course-widget'); ?></button><button type="button" class="pri" data-npr-move disabled><?php echo esc_html__('شروع انتقال', 'nias-course-widget'); ?></button></div>
        <div class="npr-bar" hidden><i></i></div>
        <div class="npr-out" data-npr-out></div>
    </div>
    <script>
    (function () {
        var root = document.querySelector('[data-npr]'); if (!root) return;
        var ajax = root.getAttribute('data-ajax'), nonce = root.getAttribute('data-nonce'), out = root.querySelector('[data-npr-out]');
        var scan = root.querySelector('[data-npr-scan]'), move = root.querySelector('[data-npr-move]'), bar = root.querySelector('.npr-bar'), total = 0, moved = 0, errs = [];
        function post(a) { var b = new URLSearchParams(); b.append('action', a); b.append('nonce', nonce); return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }); }
        function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
        scan.addEventListener('click', function () {
            scan.disabled = true; out.textContent = 'در حال بررسی…';
            post('nias_protect_scan').then(function (r) {
                scan.disabled = false;
                if (!r || !r.success) { out.textContent = (r && r.data && r.data.message) || 'خطا'; return; }
                total = r.data.count;
                if (!total) { out.innerHTML = '<b>همهٔ فایل‌های دوره‌ها محافظت‌شده‌اند</b> (یا فایل محلی‌ای در جلسات نیست).'; move.disabled = true; return; }
                out.innerHTML = '<b>' + fa(total) + ' فایل</b> (' + esc(r.data.size) + ') در جلسات دوره‌ها هنوز با آدرس مستقیم باز می‌شوند:<ul>' + r.data.sample.map(function (s) { return '<li>' + esc(s.name) + ' · ' + esc(s.size) + (s.courses > 1 ? ' · ' + fa(s.courses) + ' دوره' : '') + '</li>'; }).join('') + (total > r.data.sample.length ? '<li>…</li>' : '') + '</ul>';
                move.disabled = false;
            });
        });
        function step() {
            post('nias_protect_move').then(function (r) {
                if (!r || !r.success) { out.innerHTML += '<div>' + esc((r && r.data && r.data.message) || 'خطا') + '</div>'; move.disabled = false; scan.disabled = false; return; }
                moved += r.data.done.length; errs = errs.concat(r.data.errors);
                bar.firstChild.style.width = Math.round((total - r.data.left) / total * 100) + '%';
                out.innerHTML = 'منتقل شد: <b>' + fa(moved) + '</b> از ' + fa(total) + (errs.length ? '<div style="color:#b91c1c">' + errs.map(esc).join('<br>') + '</div>' : '');
                if (r.data.left > 0) { step(); } else { out.innerHTML += '<div><b>تمام شد.</b> از این پس این فایل‌ها فقط با لینک امضاشده باز می‌شوند.</div>'; scan.disabled = false; }
            }).catch(function () { out.innerHTML += '<div>خطای ارتباط؛ دوباره «شروع انتقال» را بزنید تا از همان‌جا ادامه دهد.</div>'; move.disabled = false; scan.disabled = false; });
        }
        move.addEventListener('click', function () {
            if (!confirm('فایل‌های فهرست‌شده به پوشهٔ محافظت‌شده منتقل شوند؟ پیش از ادامه از سایت پشتیبان گرفته باشید.')) return;
            move.disabled = true; scan.disabled = true; moved = 0; errs = []; bar.hidden = false; step();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
