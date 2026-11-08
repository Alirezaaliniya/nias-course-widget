<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «امتیاز و نشان» (points, badges, leaderboard).
 *
 *   Points (each event once per user):
 *     lesson watched to the end +10, quiz passed +20, assignment passed +30,
 *     course finished +50, review +5, question asked +2.
 *   Badges: شروع‌کننده, کوشا, آزمون‌دان, پروژه‌محور, فارغ‌التحصیل, منتقد, ستارهٔ کلاس.
 *   Shown under the course view (my points, badges, top 10 of the course with
 *   names full / first name / hidden) and with [nias_my_badges].
 *   Storage: user meta _nias_gm_points, _nias_gm_p_{course}, _nias_gm_keys,
 *   _nias_gm_c (counters), _nias_gm_badges, _nias_gm_new, _nias_gm_log.
 *
 * @package nias-course-widget
 */

function nias_gm_enabled()
{
    return (bool) apply_filters('nias_gm_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_gm_enabled') === 'on');
}

function nias_gm_points_table()
{
    return apply_filters('nias_gm_points', array('lesson' => 10, 'quiz' => 20, 'assign' => 30, 'course' => 50, 'review' => 5, 'question' => 2));
}

function nias_gm_badges()
{
    return apply_filters('nias_gm_badges', array(
        'starter'   => array('t' => __('شروع‌کننده', 'nias-course-widget'), 'd' => __('اولین جلسه را کامل دیدید', 'nias-course-widget'), 'c' => 'lesson', 'n' => 1, 'i' => '🚀'),
        'diligent'  => array('t' => __('کوشا', 'nias-course-widget'), 'd' => __('۱۰ جلسه را کامل دیدید', 'nias-course-widget'), 'c' => 'lesson', 'n' => 10, 'i' => '📚'),
        'quizzer'   => array('t' => __('آزمون‌دان', 'nias-course-widget'), 'd' => __('در ۵ آزمون قبول شدید', 'nias-course-widget'), 'c' => 'quiz', 'n' => 5, 'i' => '🧠'),
        'builder'   => array('t' => __('پروژه‌محور', 'nias-course-widget'), 'd' => __('اولین تکلیف قبول شد', 'nias-course-widget'), 'c' => 'assign', 'n' => 1, 'i' => '🛠️'),
        'graduate'  => array('t' => __('فارغ‌التحصیل', 'nias-course-widget'), 'd' => __('یک دوره را تمام کردید', 'nias-course-widget'), 'c' => 'course', 'n' => 1, 'i' => '🎓'),
        'critic'    => array('t' => __('منتقد', 'nias-course-widget'), 'd' => __('نظرتان را دربارهٔ یک دوره نوشتید', 'nias-course-widget'), 'c' => 'review', 'n' => 1, 'i' => '✍️'),
        'star'      => array('t' => __('ستارهٔ کلاس', 'nias-course-widget'), 'd' => __('۵۰۰ امتیاز گرفتید', 'nias-course-widget'), 'c' => 'points', 'n' => 500, 'i' => '⭐'),
    ));
}

/** Give points for an event (once per $key). */
function nias_gm_award($user_id, $post_id, $type, $key)
{
    $user_id = (int) $user_id;
    if (!$user_id || !nias_gm_enabled() || user_can($user_id, 'manage_options')) {
        return 0;
    }
    $table = nias_gm_points_table();
    $pts   = isset($table[$type]) ? (int) $table[$type] : 0;
    $keys  = get_user_meta($user_id, '_nias_gm_keys', true);
    $keys  = is_array($keys) ? $keys : array();
    if (!$pts || isset($keys[$key])) {
        return 0;
    }
    $keys[$key] = 1;
    update_user_meta($user_id, '_nias_gm_keys', array_slice($keys, -5000, null, true));
    update_user_meta($user_id, '_nias_gm_points', (int) get_user_meta($user_id, '_nias_gm_points', true) + $pts);
    if ($post_id) {
        update_user_meta($user_id, '_nias_gm_p_' . (int) $post_id, (int) get_user_meta($user_id, '_nias_gm_p_' . (int) $post_id, true) + $pts);
    }
    $c = get_user_meta($user_id, '_nias_gm_c', true);
    $c = is_array($c) ? $c : array();
    $c[$type] = (isset($c[$type]) ? $c[$type] : 0) + 1;
    update_user_meta($user_id, '_nias_gm_c', $c);
    $log = get_user_meta($user_id, '_nias_gm_log', true);
    $log = is_array($log) ? $log : array();
    $log[] = array('t' => $type, 'p' => $pts, 'c' => (int) $post_id, 'at' => time());
    update_user_meta($user_id, '_nias_gm_log', array_slice($log, -30));
    nias_gm_check_badges($user_id, $c);
    do_action('nias_gm_awarded', $user_id, $post_id, $type, $pts);
    return $pts;
}

function nias_gm_check_badges($user_id, $c)
{
    $have = get_user_meta($user_id, '_nias_gm_badges', true);
    $have = is_array($have) ? $have : array();
    $pts  = (int) get_user_meta($user_id, '_nias_gm_points', true);
    $new  = array();
    foreach (nias_gm_badges() as $k => $b) {
        $val = $b['c'] === 'points' ? $pts : (isset($c[$b['c']]) ? $c[$b['c']] : 0);
        if (!isset($have[$k]) && $val >= $b['n']) {
            $have[$k] = time();
            $new[] = $k;
        }
    }
    if ($new) {
        update_user_meta($user_id, '_nias_gm_badges', $have);
        $queue = get_user_meta($user_id, '_nias_gm_new', true);
        update_user_meta($user_id, '_nias_gm_new', array_values(array_unique(array_merge(is_array($queue) ? $queue : array(), $new))));
        foreach ($new as $k) {
            do_action('nias_gm_badge', $user_id, $k);
        }
    }
}

/* Events ------------------------------------------------------------------ */

add_action('nias_watch_updated', function ($uid, $pid, $all) {
    $done = function_exists('nias_watch_complete_pct') ? (float) nias_watch_complete_pct() : 90;
    foreach ((array) $all as $lid => $r) {
        if (isset($r['p']) && (float) $r['p'] >= $done) {
            nias_gm_award($uid, $pid, 'lesson', 'l:' . $pid . ':' . $lid);
        }
    }
}, 20, 3);
add_action('nias_quiz_result_saved', function ($uid, $quiz_id, $record) {
    if (is_array($record) && !empty($record['passed'])) {
        $courses = array_map('intval', (array) get_post_meta($quiz_id, '_nias_quiz_in_course', false));
        nias_gm_award($uid, $courses ? $courses[0] : 0, 'quiz', 'q:' . $quiz_id);
    }
}, 20, 3);
add_action('nias_assign_graded', function ($row) {
    if ($row['status'] === 'passed') {
        nias_gm_award((int) $row['user_id'], (int) $row['post_id'], 'assign', 'a:' . $row['post_id'] . ':' . $row['lesson']);
    }
}, 20);
add_action('nias_course_progress_updated', function ($uid, $pid, $pct = 0) {
    if ((int) $pct >= 100) {
        nias_gm_award($uid, $pid, 'course', 'c:' . $pid);
    }
}, 20, 3);
add_action('nias_review_saved', function ($cid, $pid, $uid) {
    nias_gm_award($uid, $pid, 'review', 'r:' . $pid);
}, 20, 3);
add_action('nias_qa_question', function ($row) {
    if (is_array($row) && !empty($row['user_id']) && empty($row['parent_id'])) {
        nias_gm_award((int) $row['user_id'], (int) $row['post_id'], 'question', 'qa:' . (int) $row['id']);
    }
}, 20);

/* Display ----------------------------------------------------------------- */

function nias_gm_name($user_id)
{
    $mode = function_exists('carbon_get_theme_option') ? carbon_get_theme_option('nias_gm_names') : '';
    $u    = get_userdata($user_id);
    if (!$u || $mode === 'hidden') {
        return __('دانشجو', 'nias-course-widget');
    }
    if ($mode === 'full') {
        return $u->display_name;
    }
    $first = trim((string) get_user_meta($user_id, 'first_name', true));
    $first = $first !== '' ? $first : strtok($u->display_name, ' ');
    return $first . ' ' . mb_substr(trim((string) get_user_meta($user_id, 'last_name', true)), 0, 1) . (get_user_meta($user_id, 'last_name', true) ? '.' : '');
}

/** Top users of a course + the user's rank. */
function nias_gm_board($post_id, $user_id = 0, $n = 10)
{
    global $wpdb;
    $key  = '_nias_gm_p_' . (int) $post_id;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, CAST(meta_value AS UNSIGNED) pts FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY pts DESC, user_id ASC LIMIT %d", $key, $n), ARRAY_A);
    $mine = $user_id ? (int) get_user_meta($user_id, $key, true) : 0;
    $rank = $mine ? 1 + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", $key, $mine)) : 0;
    return array('rows' => $rows, 'mine' => $mine, 'rank' => $rank);
}

add_filter('nias_modern_course_extra', 'nias_gm_block', 30, 2);
function nias_gm_block($html, $post_id)
{
    if (!nias_gm_enabled() || !nias_is_course_post($post_id)) {
        return $html;
    }
    $uid   = get_current_user_id();
    $board = nias_gm_board($post_id, $uid);
    if (!$uid && !$board['rows']) {
        return $html;
    }
    $badges = nias_gm_badges();
    $have   = $uid ? get_user_meta($uid, '_nias_gm_badges', true) : array();
    $have   = is_array($have) ? $have : array();
    $new    = $uid ? get_user_meta($uid, '_nias_gm_new', true) : array();
    if ($uid && $new) {
        delete_user_meta($uid, '_nias_gm_new');
    }
    $show_board = !function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_gm_board') !== 'off';
    $fa = function ($n) { return nias_fa_digits(number_format_i18n((int) $n)); };
    ob_start();
    ?>
    <section class="ngm" dir="rtl">
        <style>
        .ngm{max-width:calc(100% - 34px);margin:22px auto;padding:20px;background:#fff;border:1px solid #e6ecf5;border-radius:18px;color:#1f2733;box-sizing:border-box;font-family:inherit}
        .ngm h2{margin:0 0 14px;font-size:18px}
        .ngm-top{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}
        .ngm-k{flex:1 1 150px;background:linear-gradient(135deg,#eef4ff,#fff);border:1px solid #e1e9fb;border-radius:14px;padding:12px 14px}
        .ngm-k span{color:#64748b;font-size:12.5px}.ngm-k b{display:block;font-size:24px;margin-top:2px}
        .ngm-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:18px}
        .ngm-badges{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
        .ngm-b{border:1px solid #e6ecf5;border-radius:14px;padding:10px;text-align:center}
        .ngm-b i{display:block;font-style:normal;font-size:28px;line-height:1.2}
        .ngm-b b{display:block;font-size:13px;margin-top:4px}.ngm-b small{display:block;color:#64748b;font-size:11.5px;line-height:1.6;margin-top:2px}
        .ngm-b.off{filter:grayscale(1);opacity:.45}
        .ngm-lb{list-style:none;margin:0;padding:0}
        .ngm-lb li{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9}
        .ngm-lb .r{flex:none;width:26px;height:26px;border-radius:50%;background:#eef2f8;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12.5px}
        .ngm-lb li:nth-child(1) .r{background:#fde68a}.ngm-lb li:nth-child(2) .r{background:#e2e8f0}.ngm-lb li:nth-child(3) .r{background:#fed7aa}
        .ngm-lb li.me{background:#f5f8ff;border-radius:10px}
        .ngm-lb .n{flex:1}.ngm-lb .p{font-weight:800;color:#3858e9}
        .ngm-pop{position:fixed;z-index:99999;bottom:20px;left:50%;transform:translateX(-50%) translateY(30px);opacity:0;transition:.4s;background:#1f2733;color:#fff;border-radius:14px;padding:12px 18px;display:flex;gap:10px;align-items:center;box-shadow:0 16px 40px -14px rgba(0,0,0,.5);direction:rtl}
        .ngm-pop.on{transform:translateX(-50%);opacity:1}.ngm-pop i{font-style:normal;font-size:26px}
        @media(max-width:800px){.ngm-grid{grid-template-columns:1fr}}
        </style>
        <h2><?php echo esc_html__('امتیاز و نشان‌ها', 'nias-course-widget'); ?></h2>
        <?php if ($uid) : ?>
        <div class="ngm-top">
            <div class="ngm-k"><span><?php echo esc_html__('امتیاز شما در این دوره', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa($board['mine'])); ?></b></div>
            <div class="ngm-k"><span><?php echo esc_html__('رتبهٔ شما', 'nias-course-widget'); ?></span><b><?php echo esc_html($board['rank'] ? $fa($board['rank']) : '—'); ?></b></div>
            <div class="ngm-k"><span><?php echo esc_html__('امتیاز کل', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa(get_user_meta($uid, '_nias_gm_points', true))); ?></b></div>
            <div class="ngm-k"><span><?php echo esc_html__('نشان‌ها', 'nias-course-widget'); ?></span><b><?php echo esc_html(sprintf(__('%1$s از %2$s', 'nias-course-widget'), $fa(count($have)), $fa(count($badges)))); ?></b></div>
        </div>
        <?php endif; ?>
        <div class="ngm-grid">
            <div class="ngm-badges">
                <?php foreach ($badges as $k => $b) : ?><div class="ngm-b <?php echo isset($have[$k]) ? '' : 'off'; ?>" title="<?php echo esc_attr($b['d']); ?>"><i><?php echo esc_html($b['i']); ?></i><b><?php echo esc_html($b['t']); ?></b><small><?php echo esc_html($b['d']); ?></small></div><?php endforeach; ?>
            </div>
            <?php if ($show_board) : ?>
            <div><b style="display:block;margin-bottom:6px"><?php echo esc_html__('برترین‌های این دوره', 'nias-course-widget'); ?></b>
                <?php if ($board['rows']) : ?><ol class="ngm-lb"><?php foreach ($board['rows'] as $i => $r) : ?><li class="<?php echo (int) $r['user_id'] === $uid ? 'me' : ''; ?>"><span class="r"><?php echo esc_html($fa($i + 1)); ?></span><span class="n"><?php echo esc_html((int) $r['user_id'] === $uid ? __('شما', 'nias-course-widget') : nias_gm_name((int) $r['user_id'])); ?></span><span class="p"><?php echo esc_html($fa($r['pts'])); ?></span></li><?php endforeach; ?></ol>
                <?php else : ?><p style="color:#64748b;font-size:13px"><?php echo esc_html__('هنوز کسی امتیاز نگرفته؛ اولین نفر باشید!', 'nias-course-widget'); ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($new) :
            $first = $badges[$new[0]] ?? null;
            if ($first) : ?>
            <div class="ngm-pop" role="status"><i><?php echo esc_html($first['i']); ?></i><div><b><?php echo esc_html(sprintf(__('نشان جدید: %s', 'nias-course-widget'), $first['t'])); ?></b><br><small><?php echo esc_html($first['d']); ?></small></div></div>
            <script>(function(){var p=document.querySelector('.ngm-pop');if(!p)return;setTimeout(function(){p.classList.add('on');},600);setTimeout(function(){p.classList.remove('on');},6000);})();</script>
        <?php endif; endif; ?>
    </section>
    <?php
    return $html . ob_get_clean();
}

add_shortcode('nias_my_badges', function () {
    if (!nias_gm_enabled() || !is_user_logged_in()) {
        return '';
    }
    $uid  = get_current_user_id();
    $have = get_user_meta($uid, '_nias_gm_badges', true);
    $have = is_array($have) ? $have : array();
    $h    = '<div dir="rtl" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center"><b>' . esc_html(sprintf(__('امتیاز شما: %s', 'nias-course-widget'), nias_fa_digits(number_format_i18n((int) get_user_meta($uid, '_nias_gm_points', true))))) . '</b>';
    foreach (nias_gm_badges() as $k => $b) {
        $h .= '<span title="' . esc_attr($b['d']) . '" style="border:1px solid #e6ecf5;border-radius:99px;padding:4px 10px;' . (isset($have[$k]) ? '' : 'opacity:.4;filter:grayscale(1)') . '">' . esc_html($b['i'] . ' ' . $b['t']) . '</span>';
    }
    return $h . '</div>';
});
