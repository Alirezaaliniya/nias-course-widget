<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Video watch tracking (ردیابی تماشای ویدیو).
 *
 * The Nias player records which ~1-second buckets of a lesson video were
 * actually played. Each student's maps are stored per course in user meta
 * _nias_watch_{product_id}:
 *
 *   lessonId => array(
 *       'n' => buckets, 'm' => base64 bitmap, 'p' => watched %,
 *       't' => last position (s), 'd' => duration (s), 'u' => updated (unix)
 *   )
 *
 * Maps from several devices are OR-merged, so watching half a lesson on the
 * phone and the other half on the laptop counts as the whole lesson. The
 * percentage is always recomputed on the server from the bitmap.
 *
 * @package nias-course-widget
 */

define('NIAS_WATCH_MAX_BUCKETS', 4000);

function nias_watch_meta_key($product_id)
{
    return '_nias_watch_' . (int) $product_id;
}

/** @return array lessonId => record */
function nias_watch_get($user_id, $product_id)
{
    $v = get_user_meta((int) $user_id, nias_watch_meta_key($product_id), true);
    return is_array($v) ? $v : array();
}

/** Lesson completion threshold (watched %). */
function nias_watch_complete_pct()
{
    $v = function_exists('nias_player_opt') ? (int) nias_player_opt('nias_video_complete_pct') : 90;
    return $v > 0 ? min(100, max(10, $v)) : 90;
}

/** Number of set bits in a binary string. */
function nias_watch_popcount($bin)
{
    static $t = null;
    if ($t === null) {
        $t = array();
        for ($i = 0; $i < 256; $i++) {
            $t[$i] = substr_count(decbin($i), '1');
        }
    }
    $c   = 0;
    $len = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $c += $t[ord($bin[$i])];
    }
    return $c;
}

/**
 * Validate one map coming from the browser.
 *
 * @return array{n:int,bin:string}|null
 */
function nias_watch_clean_map($n, $m)
{
    $n = (int) $n;
    if ($n < 1 || $n > NIAS_WATCH_MAX_BUCKETS) {
        return null;
    }
    $bin = base64_decode((string) $m, true);
    if ($bin === false || strlen($bin) !== (int) ceil($n / 8)) {
        return null;
    }
    // Clear padding bits past n so they can never count.
    $extra = strlen($bin) * 8 - $n;
    if ($extra > 0) {
        $last = ord($bin[strlen($bin) - 1]) & ((1 << (8 - $extra)) - 1);
        $bin[strlen($bin) - 1] = chr($last);
    }
    return array('n' => $n, 'bin' => $bin);
}

/**
 * Merge a browser map into the stored record (same bucket count → OR;
 * different → the video changed, start over with the new map).
 */
function nias_watch_merge($old, $n, $bin, $t, $d)
{
    if (is_array($old) && isset($old['n'], $old['m']) && (int) $old['n'] === $n) {
        $prev = base64_decode($old['m'], true);
        if ($prev !== false && strlen($prev) === strlen($bin)) {
            $bin = $bin | $prev;
        }
    }
    return array(
        'n' => $n,
        'm' => base64_encode($bin),
        'p' => round(nias_watch_popcount($bin) / $n * 100, 1),
        't' => max(0, round((float) $t, 1)),
        'd' => max(0, round((float) $d, 1)),
        'u' => time(),
    );
}

/* -------------------------------------------------------------------------
 * AJAX: save maps (batched)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_watch_sync', 'nias_watch_ajax_sync');
/**
 * May this user record progress on the course? Same rule as the course view
 * (it only sends progress for buyers): bought / enrolled, or staff of the
 * course. Without it anyone could mark a course they never bought as
 * finished (prerequisites, points, …).
 */
function nias_progress_user_allowed($user_id, $post_id)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (!$user_id || !$post_id) {
        return false;
    }
    if (user_can($user_id, 'manage_options')
        || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id))
        || (function_exists('nias_user_has_course_access') && nias_user_has_course_access($user_id, $post_id))) {
        return true;
    }
    if (!function_exists('nias_has_bought_course') && defined('NIAS_FUNCTIONS') && file_exists(NIAS_FUNCTIONS)) {
        include_once NIAS_FUNCTIONS;
    }
    $u = get_userdata($user_id);
    return $u && function_exists('nias_has_bought_course') && (bool) nias_has_bought_course($user_id, $u->user_login, $post_id);
}

function nias_watch_ajax_sync()
{
    if (!check_ajax_referer('nias_course_progress', 'nonce', false)) {
        wp_send_json_error(array('message' => 'nonce'), 403);
    }
    $user_id    = get_current_user_id();
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$user_id || !$product_id || !nias_is_course_post($product_id)) {
        wp_send_json_error(array('message' => 'bad request'), 400);
    }
    if (!nias_progress_user_allowed($user_id, $product_id)) {
        wp_send_json_error(array('message' => 'no access'), 403);
    }
    $items = isset($_POST['items']) ? json_decode(wp_unslash($_POST['items']), true) : null; // phpcs:ignore -- validated below
    if (!is_array($items)) {
        wp_send_json_error(array('message' => 'bad items'), 400);
    }

    $all     = nias_watch_get($user_id, $product_id);
    $changed = 0;
    foreach (array_slice($items, 0, 50) as $it) {
        if (!is_array($it) || empty($it['id']) || !preg_match('/^c\d{1,4}l\d{1,4}$/', (string) $it['id'])) {
            continue;
        }
        $map = nias_watch_clean_map(isset($it['n']) ? $it['n'] : 0, isset($it['m']) ? $it['m'] : '');
        if (!$map) {
            continue;
        }
        $id       = (string) $it['id'];
        $all[$id] = nias_watch_merge(isset($all[$id]) ? $all[$id] : null, $map['n'], $map['bin'], isset($it['t']) ? $it['t'] : 0, isset($it['d']) ? $it['d'] : 0);
        $changed++;
    }
    if ($changed) {
        if (count($all) > 600) {
            $all = array_slice($all, -600, null, true); // defensive cap per course
        }
        update_user_meta($user_id, nias_watch_meta_key($product_id), $all);
        do_action('nias_watch_updated', $user_id, $product_id, $all);
    }
    // Merged state back to the browser (other devices' viewing included).
    $out = array();
    foreach ($items as $it) {
        if (is_array($it) && !empty($it['id']) && isset($all[$it['id']])) {
            $out[$it['id']] = array_intersect_key($all[$it['id']], array_flip(array('n', 'm', 'p')));
        }
    }
    wp_send_json_success(array('watch' => $out));
}

/** Maps for the browser (what the modern course view needs). */
function nias_watch_for_client($user_id, $product_id)
{
    $out = array();
    foreach (nias_watch_get($user_id, $product_id) as $id => $r) {
        if (isset($r['n'], $r['m'])) {
            $out[$id] = array('n' => (int) $r['n'], 'm' => $r['m'], 'p' => (float) $r['p'], 't' => isset($r['t']) ? (float) $r['t'] : 0);
        }
    }
    return $out;
}

/** Watermark text for the current user ('' when the option is off). See admin/player-settings.php. */
function nias_watch_watermark_text()
{
    return (function_exists('nias_player_on') && nias_player_on('nias_video_watermark')) ? nias_player_watermark_text() : '';
}

/* -------------------------------------------------------------------------
 * Report on the product screen
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes', 'nias_watch_register_metabox', 10, 2);
function nias_watch_register_metabox($post_type, $post = null)
{
    if (!$post || !nias_is_course_type($post_type) || !current_user_can('edit_post', $post->ID)) {
        return;
    }
    add_meta_box('nias_watch_report', __('گزارش تماشای ویدیو', 'nias-course-widget'), 'nias_watch_render_metabox', $post_type, 'normal', 'low');
}

/** Lessons of a course as id => "chapter › title". */
function nias_watch_lessons($product_id)
{
    $sections = function_exists('carbon_get_post_meta') ? carbon_get_post_meta($product_id, 'course_sections') : array();
    $chapters = function_exists('nias_curriculum_sections_to_js') ? nias_curriculum_sections_to_js(is_array($sections) ? $sections : array()) : array();
    $out = array();
    foreach ((array) $chapters as $ci => $ch) {
        foreach ((array) (isset($ch['lessons']) ? $ch['lessons'] : array()) as $li => $l) {
            $out['c' . $ci . 'l' . $li] = isset($l['title']) && $l['title'] !== '' ? $l['title'] : sprintf(__('درس %d', 'nias-course-widget'), $li + 1);
        }
    }
    return $out;
}

function nias_watch_render_metabox($post)
{
    $pid = (int) $post->ID;
    $done = nias_watch_complete_pct();
    $nonce = wp_create_nonce('nias_watch_report_' . $pid);
    ?>
    <style>
    .nwr{direction:rtl}
    .nwr table{width:100%;border-collapse:collapse;font-size:13px}
    .nwr th{text-align:right;color:#646970;font-weight:600;padding:8px;border-bottom:1px solid #dcdcde;white-space:nowrap}
    .nwr td{padding:8px;border-bottom:1px solid #f0f0f1;vertical-align:top}
    .nwr-bar{display:inline-block;width:110px;height:7px;border-radius:7px;background:#eef0f3;vertical-align:middle;overflow:hidden;margin-left:6px}
    .nwr-bar i{display:block;height:100%;border-radius:7px}
    .nwr-p{font-variant-numeric:tabular-nums}
    .nwr details summary{cursor:pointer;color:#2271b1}
    .nwr .nwr-l{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px dashed #f0f0f1}
    .nwr h4{margin:18px 0 8px}
    .nwr-muted{color:#8c8f94;font-size:12px}
    .nwr-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
    .nwr-tools input{min-width:240px}
    .nwr-pager{display:flex;justify-content:space-between;align-items:center;margin-top:10px;direction:ltr}
    .nwr-pager span{direction:rtl}
    .nwr-loading{opacity:.5;pointer-events:none}
    </style>
    <div class="nwr" id="nwr" data-pid="<?php echo (int) $pid; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
        <p class="nwr-muted"><?php echo esc_html(sprintf(__('درصدها سهمی از هر ویدیو است که دانشجو واقعاً تماشا کرده (نه موقعیت نوار پخش). درس با %s٪ تماشا «تکمیل» حساب می‌شود.', 'nias-course-widget'), number_format_i18n($done))); ?></p>
        <div class="nwr-tools"><input type="search" class="regular-text" id="nwr-s" placeholder="<?php esc_attr_e('جستجوی نام یا ایمیل دانشجو…', 'nias-course-widget'); ?>"></div>
        <div id="nwr-list"><p class="nwr-muted"><?php esc_html_e('در حال بارگذاری…', 'nias-course-widget'); ?></p></div>
        <h4><?php esc_html_e('میانگین تماشای هر درس (برای پیدا کردن جایی که دانشجوها رها می‌کنند)', 'nias-course-widget'); ?></h4>
        <div id="nwr-avg"><button type="button" class="button" id="nwr-avg-btn"><?php esc_html_e('محاسبهٔ میانگین هر درس', 'nias-course-widget'); ?></button> <span class="nwr-muted"><?php esc_html_e('(روی همهٔ دانشجوها حساب می‌شود و تا ۳۰ دقیقه ذخیره می‌ماند)', 'nias-course-widget'); ?></span></div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('nwr'); if (!box) return;
        var list = document.getElementById('nwr-list'), s = document.getElementById('nwr-s'), t = null, req = 0;
        function call(action, extra) {
            var b = new URLSearchParams();
            b.append('action', action); b.append('product_id', box.getAttribute('data-pid')); b.append('nonce', box.getAttribute('data-nonce'));
            Object.keys(extra || {}).forEach(function (k) { b.append(k, extra[k]); });
            return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); });
        }
        function load(page) {
            var my = ++req;
            list.classList.add('nwr-loading');
            call('nias_watch_report', { page: page || 1, s: s.value.trim() }).then(function (res) {
                if (my !== req) return;
                list.classList.remove('nwr-loading');
                list.innerHTML = res && res.success ? res.data.html : '<p class="nwr-muted"><?php echo esc_js(__('بارگذاری گزارش انجام نشد.', 'nias-course-widget')); ?></p>';
            });
        }
        s.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { load(1); }, 300); });
        s.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
        list.addEventListener('click', function (e) { var a = e.target.closest('[data-nwr-page]'); if (a) { e.preventDefault(); load(+a.getAttribute('data-nwr-page')); } });
        document.getElementById('nwr-avg-btn').addEventListener('click', function () {
            var w = document.getElementById('nwr-avg'); w.classList.add('nwr-loading');
            call('nias_watch_lesson_avg').then(function (res) { w.classList.remove('nwr-loading'); w.innerHTML = res && res.success ? res.data.html : ''; });
        });
        load(1);
    })();
    </script>
    <?php
}

/** Shared guard for the report endpoints. */
function nias_watch_report_guard()
{
    $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$pid || !check_ajax_referer('nias_watch_report_' . $pid, 'nonce', false) || !current_user_can('edit_post', $pid)) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'nias-course-widget')), 403);
    }
    return $pid;
}

function nias_watch_bar_html($p)
{
    $done = nias_watch_complete_pct();
    $c = $p >= $done ? '#16a34a' : ($p >= 40 ? '#d97706' : '#dc2626');
    return '<span class="nwr-bar"><i style="width:' . esc_attr(min(100, $p)) . '%;background:' . $c . '"></i></span><span class="nwr-p">' . esc_html(number_format_i18n($p, 0)) . '٪</span>';
}

/** One page of students (20), newest watchers first, optional name/email search. */
add_action('wp_ajax_nias_watch_report', 'nias_watch_report_ajax');
function nias_watch_report_ajax()
{
    global $wpdb;
    $pid  = nias_watch_report_guard();
    $page = max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
    $s    = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '';
    $per  = 20;
    $key  = nias_watch_meta_key($pid);

    $join  = '';
    $where = $wpdb->prepare('WHERE um.meta_key = %s', $key);
    if ($s !== '') {
        $like  = '%' . $wpdb->esc_like($s) . '%';
        $join  = " INNER JOIN {$wpdb->users} u ON u.ID = um.user_id";
        $where .= $wpdb->prepare(' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)', $like, $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} um{$join} {$where}");
    $ids   = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT um.user_id FROM {$wpdb->usermeta} um{$join} {$where} ORDER BY um.umeta_id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per)));

    if (!$ids) {
        $msg = $s !== '' ? sprintf(__('دانشجویی با «%s» پیدا نشد.', 'nias-course-widget'), $s)
            : __('هنوز هیچ دانشجویی ویدیوهای این دوره را در پلیر نیاس تماشا نکرده است. پس از تماشا، درصد واقعی دیده‌شدهٔ هر درس برای هر دانشجو اینجا نمایش داده می‌شود.', 'nias-course-widget');
        wp_send_json_success(array('html' => '<p class="nwr-muted">' . esc_html($msg) . '</p>'));
    }
    cache_users($ids);
    $lessons = nias_watch_lessons($pid);
    $done    = nias_watch_complete_pct();
    $h = '<p class="nwr-muted">' . esc_html(sprintf(__('%s دانشجو', 'nias-course-widget'), nias_fa_digits(number_format_i18n($total)))) . '</p><table><thead><tr>'
        . '<th>' . esc_html__('دانشجو', 'nias-course-widget') . '</th><th>' . esc_html__('میانگین تماشای ویدیوها', 'nias-course-widget') . '</th><th>' . esc_html__('درس‌های کامل دیده‌شده', 'nias-course-widget') . '</th><th>' . esc_html__('آخرین تماشا', 'nias-course-widget') . '</th><th>' . esc_html__('جزئیات', 'nias-course-widget') . '</th></tr></thead><tbody>';
    foreach ($ids as $uid) {
        $u = get_userdata($uid);
        $w = nias_watch_get($uid, $pid);
        if (!$u || !$w) {
            continue;
        }
        $sum = 0;
        $last = 0;
        $complete = 0;
        foreach ($w as $r) {
            $sum += (float) $r['p'];
            $last = max($last, (int) $r['u']);
            if ((float) $r['p'] >= $done) {
                $complete++;
            }
        }
        $det = '';
        foreach ($lessons as $lid => $title) {
            if (isset($w[$lid])) {
                $det .= '<div class="nwr-l"><span>' . esc_html($title) . '</span><span>' . nias_watch_bar_html((float) $w[$lid]['p']) . '</span></div>';
            }
        }
        $h .= '<tr><td><a href="' . esc_url(get_edit_user_link($uid)) . '">' . esc_html($u->display_name) . '</a><div class="nwr-muted">' . esc_html($u->user_email) . '</div></td>'
            . '<td>' . nias_watch_bar_html($sum / max(1, count($w))) . '</td>'
            . '<td>' . esc_html(number_format_i18n($complete) . ' / ' . number_format_i18n(count($w))) . '</td>'
            . '<td>' . esc_html($last ? sprintf(__('%s پیش', 'nias-course-widget'), human_time_diff($last)) : '—') . '</td>'
            . '<td><details><summary>' . esc_html__('هر درس', 'nias-course-widget') . '</summary>' . $det . '</details></td></tr>';
    }
    $h .= '</tbody></table>';
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        $h .= '<div class="nwr-pager">'
            . ($page > 1 ? '<a href="#" class="button" data-nwr-page="' . ($page - 1) . '">‹ ' . esc_html__('قبلی', 'nias-course-widget') . '</a>' : '<span class="button disabled">‹ ' . esc_html__('قبلی', 'nias-course-widget') . '</span>')
            . '<span>' . esc_html(sprintf(__('صفحهٔ %1$s از %2$s', 'nias-course-widget'), nias_fa_digits($page), nias_fa_digits($pages))) . '</span>'
            . ($page < $pages ? '<a href="#" class="button" data-nwr-page="' . ($page + 1) . '">' . esc_html__('بعدی', 'nias-course-widget') . ' ›</a>' : '<span class="button disabled">' . esc_html__('بعدی', 'nias-course-widget') . ' ›</span>')
            . '</div>';
    }
    wp_send_json_success(array('html' => $h));
}

/** Per-lesson averages over every student: read in chunks of 500, cached 30 min. */
add_action('wp_ajax_nias_watch_lesson_avg', 'nias_watch_lesson_avg_ajax');
function nias_watch_lesson_avg_ajax()
{
    global $wpdb;
    $pid = nias_watch_report_guard();
    $ck  = 'nias_watch_avg_' . $pid;
    $per = get_transient($ck);
    if (!is_array($per)) {
        $per = array();
        $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND umeta_id > %d ORDER BY umeta_id ASC LIMIT 500",
                nias_watch_meta_key($pid), $last
            ));
            foreach ((array) $rows as $r) {
                $last = (int) $r->umeta_id;
                foreach ((array) maybe_unserialize($r->meta_value) as $lid => $x) {
                    if (!isset($per[$lid])) {
                        $per[$lid] = array(0, 0);
                    }
                    $per[$lid][0] += (float) (isset($x['p']) ? $x['p'] : 0);
                    $per[$lid][1]++;
                }
            }
        } while ($rows && count($rows) === 500);
        set_transient($ck, $per, 30 * MINUTE_IN_SECONDS);
    }
    if (!$per) {
        wp_send_json_success(array('html' => '<p class="nwr-muted">' . esc_html__('داده‌ای وجود ندارد.', 'nias-course-widget') . '</p>'));
    }
    $h = '';
    foreach (nias_watch_lessons($pid) as $lid => $title) {
        if (isset($per[$lid])) {
            $h .= '<div class="nwr-l"><span>' . esc_html($title) . ' <span class="nwr-muted">(' . esc_html(sprintf(__('%s دانشجو', 'nias-course-widget'), number_format_i18n($per[$lid][1]))) . ')</span></span><span>' . nias_watch_bar_html($per[$lid][0] / $per[$lid][1]) . '</span></div>';
        }
    }
    wp_send_json_success(array('html' => $h));
}
