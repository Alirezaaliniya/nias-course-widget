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
function nias_watch_ajax_sync()
{
    if (!check_ajax_referer('nias_course_progress', 'nonce', false)) {
        wp_send_json_error(array('message' => 'nonce'), 403);
    }
    $user_id    = get_current_user_id();
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$user_id || !$product_id || get_post_type($product_id) !== 'product') {
        wp_send_json_error(array('message' => 'bad request'), 400);
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

add_action('add_meta_boxes_product', 'nias_watch_register_metabox');
function nias_watch_register_metabox($post)
{
    if (!current_user_can('edit_post', $post->ID)) {
        return;
    }
    add_meta_box('nias_watch_report', __('گزارش تماشای ویدیو', 'nias-course-widget'), 'nias_watch_render_metabox', 'product', 'normal', 'low');
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
    $pid     = (int) $post->ID;
    $lessons = nias_watch_lessons($pid);
    $users   = get_users(array(
        'meta_key' => nias_watch_meta_key($pid),
        'number'   => 300,
        'fields'   => array('ID', 'display_name', 'user_email'),
    ));
    if (!$users) {
        echo '<p style="color:#646970">' . esc_html__('هنوز هیچ دانشجویی ویدیوهای این دوره را در پلیر نیاس تماشا نکرده است. پس از تماشا، درصد واقعی دیده‌شدهٔ هر درس برای هر دانشجو اینجا نمایش داده می‌شود.', 'nias-course-widget') . '</p>';
        return;
    }
    $done    = nias_watch_complete_pct();
    $rows    = array();
    $per     = array(); // lesson => [sum, count]
    foreach ($users as $u) {
        $w = nias_watch_get($u->ID, $pid);
        if (!$w) {
            continue;
        }
        $sum = 0;
        $last = 0;
        foreach ($w as $lid => $r) {
            $sum += (float) $r['p'];
            $last = max($last, (int) $r['u']);
            if (!isset($per[$lid])) {
                $per[$lid] = array(0, 0);
            }
            $per[$lid][0] += (float) $r['p'];
            $per[$lid][1]++;
        }
        $video_count = max(1, count($w));
        $rows[] = array('u' => $u, 'w' => $w, 'avg' => $sum / $video_count, 'last' => $last, 'complete' => count(array_filter($w, function ($r) use ($done) { return (float) $r['p'] >= $done; })));
    }
    usort($rows, function ($a, $b) { return $b['last'] - $a['last']; });
    $bar = function ($p) use ($done) {
        $c = $p >= $done ? '#16a34a' : ($p >= 40 ? '#d97706' : '#dc2626');
        return '<span class="nwr-bar"><i style="width:' . esc_attr(min(100, $p)) . '%;background:' . $c . '"></i></span><span class="nwr-p">' . esc_html(number_format_i18n($p, 0)) . '٪</span>';
    };
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
    </style>
    <div class="nwr">
        <p class="nwr-muted"><?php echo esc_html(sprintf(__('درصدها سهمی از هر ویدیو است که دانشجو واقعاً تماشا کرده (نه موقعیت نوار پخش). درس با %s٪ تماشا «تکمیل» حساب می‌شود.', 'nias-course-widget'), number_format_i18n($done))); ?></p>
        <table>
            <thead><tr>
                <th><?php esc_html_e('دانشجو', 'nias-course-widget'); ?></th>
                <th><?php esc_html_e('میانگین تماشای ویدیوها', 'nias-course-widget'); ?></th>
                <th><?php esc_html_e('درس‌های کامل دیده‌شده', 'nias-course-widget'); ?></th>
                <th><?php esc_html_e('آخرین تماشا', 'nias-course-widget'); ?></th>
                <th><?php esc_html_e('جزئیات', 'nias-course-widget'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_user_link($r['u']->ID)); ?>"><?php echo esc_html($r['u']->display_name); ?></a><div class="nwr-muted"><?php echo esc_html($r['u']->user_email); ?></div></td>
                    <td><?php echo $bar($r['avg']); // phpcs:ignore -- escaped in closure ?></td>
                    <td><?php echo esc_html(number_format_i18n($r['complete']) . ' / ' . number_format_i18n(count($r['w']))); ?></td>
                    <td><?php echo esc_html($r['last'] ? sprintf(__('%s پیش', 'nias-course-widget'), human_time_diff($r['last'])) : '—'); ?></td>
                    <td>
                        <details><summary><?php esc_html_e('هر درس', 'nias-course-widget'); ?></summary>
                            <?php foreach ($lessons as $lid => $title) :
                                if (!isset($r['w'][$lid])) { continue; }
                                $x = $r['w'][$lid]; ?>
                                <div class="nwr-l"><span><?php echo esc_html($title); ?></span><span><?php echo $bar((float) $x['p']); // phpcs:ignore ?></span></div>
                            <?php endforeach; ?>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($per) : ?>
            <h4><?php esc_html_e('میانگین تماشای هر درس (برای پیدا کردن جایی که دانشجوها رها می‌کنند)', 'nias-course-widget'); ?></h4>
            <?php foreach ($lessons as $lid => $title) :
                if (!isset($per[$lid])) { continue; } ?>
                <div class="nwr-l"><span><?php echo esc_html($title); ?> <span class="nwr-muted">(<?php echo esc_html(sprintf(__('%s دانشجو', 'nias-course-widget'), number_format_i18n($per[$lid][1]))); ?>)</span></span><span><?php echo $bar($per[$lid][0] / $per[$lid][1]); // phpcs:ignore ?></span></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
