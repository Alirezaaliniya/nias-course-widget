<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «گزارش تحلیلی» (course analytics) for admins and instructors.
 *
 * Built from data the plugin already keeps — no tracking of its own:
 *   - students: completed orders / free-course enrolment, progress
 *     (_nias_course_progress), last activity;
 *   - lesson funnel: real watched % per lesson (_nias_watch_{course});
 *   - quizzes: the results index table; assignments: their table;
 *   - sales: completed orders of the course, last 12 months.
 * Each course report is computed once and cached for 6 hours
 * (button «به‌روزرسانی» recomputes it).
 *
 * @package nias-course-widget
 */

function nias_stats_enabled()
{
    return (bool) apply_filters('nias_stats_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_stats_enabled') === 'on');
}

/** Courses a user may see reports for. */
function nias_stats_course_ids($user_id)
{
    if (user_can($user_id, 'manage_options')) {
        return array_map('intval', get_posts(array('post_type' => nias_course_post_types(), 'post_status' => array('publish', 'private', 'draft', 'pending'), 'posts_per_page' => 300, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true)));
    }
    return function_exists('nias_inst_course_ids') ? array_map('intval', nias_inst_course_ids($user_id)) : array();
}

/** The report of one course (cached 6 h). */
function nias_stats_course($post_id, $fresh = false)
{
    global $wpdb;
    $post_id = (int) $post_id;
    $key     = 'nias_stats_' . $post_id;
    $hit     = $fresh ? false : get_transient($key);
    if (is_array($hit)) {
        return $hit;
    }
    $now  = time();
    $done = function_exists('nias_watch_complete_pct') ? (float) nias_watch_complete_pct() : 90;

    // Students: buyers (completed orders) ∪ anyone with progress / watch data in the course.
    $students = array();
    $sales    = array();
    if (function_exists('nias_idash_order_rows') && get_post_type($post_id) === 'product') {
        foreach (nias_idash_order_rows(array($post_id)) as $row) {
            if ($row['buyerId']) {
                $students[$row['buyerId']] = true;
            }
            $m = wp_date('Y-m', $row['ts']);
            $sales[$m] = array('n' => (isset($sales[$m]) ? $sales[$m]['n'] : 0) + 1, 'sum' => (isset($sales[$m]) ? $sales[$m]['sum'] : 0) + $row['total']);
        }
    }
    $progress = array();
    $like     = '%' . $wpdb->esc_like('i:' . $post_id . ';') . '%';
    foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_nias_course_progress' AND meta_value LIKE %s", $like)) as $r) {
        $v = maybe_unserialize($r->meta_value);
        if (is_array($v) && isset($v[$post_id])) {
            $progress[(int) $r->user_id] = (int) $v[$post_id];
            $students[(int) $r->user_id] = true;
        }
    }
    $watch = array();
    foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", '_nias_watch_' . $post_id)) as $r) {
        $v = maybe_unserialize($r->meta_value);
        if (is_array($v)) {
            $watch[(int) $r->user_id] = $v;
            $students[(int) $r->user_id] = true;
        }
    }
    // Staff are not students.
    foreach (array_keys($students) as $uid) {
        if (user_can($uid, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($uid, $post_id))) {
            unset($students[$uid], $progress[$uid], $watch[$uid]);
        }
    }
    $n_students = count($students);
    $started    = 0;
    $completed  = 0;
    $sum_pct    = 0;
    foreach (array_keys($students) as $uid) {
        $p = isset($progress[$uid]) ? $progress[$uid] : 0;
        if (!$p && !empty($watch[$uid])) {
            $p = 1; // watched something but no overall % yet
        }
        $started   += $p > 0 ? 1 : 0;
        $completed += $p >= 100 ? 1 : 0;
        $sum_pct   += min(100, $p);
    }

    // Lesson funnel.
    $lessons = function_exists('nias_watch_lessons') ? nias_watch_lessons($post_id) : array();
    $funnel  = array();
    foreach ($lessons as $lid => $title) {
        $seen = 0;
        $full = 0;
        $avg  = 0;
        foreach ($watch as $w) {
            if (isset($w[$lid])) {
                $seen++;
                $avg += (float) $w[$lid]['p'];
                $full += (float) $w[$lid]['p'] >= $done ? 1 : 0;
            }
        }
        $funnel[] = array('id' => $lid, 'title' => $title, 'seen' => $seen, 'full' => $full, 'avg' => $seen ? round($avg / $seen) : 0);
    }
    // Only lessons with video data form the funnel; find the biggest drop.
    $video  = array_values(array_filter($funnel, function ($f) { return $f['seen'] > 0; }));
    $drop   = null;
    for ($i = 1; $i < count($video); $i++) {
        $d = $video[$i - 1]['seen'] - $video[$i]['seen'];
        if ($d > 0 && (!$drop || $d > $drop['n'])) {
            $drop = array('n' => $d, 'title' => $video[$i]['title'], 'from' => $video[$i - 1]['title']);
        }
    }

    // Quizzes.
    $quizzes = array();
    if (function_exists('nias_quiz_course_level_ids') && function_exists('nias_quiz_table') && get_option('nias_quiz_db_version')) {
        $ids = array_values(array_unique(array_merge(nias_quiz_lesson_quiz_ids($post_id), nias_quiz_course_level_ids($post_id))));
        foreach ($ids as $qid) {
            $r = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, AVG(best) avg, SUM(passed) pass, SUM(pending > 0) pend FROM ' . nias_quiz_table() . ' WHERE quiz_id = %d', $qid), ARRAY_A);
            $quizzes[] = array('title' => get_the_title($qid), 'n' => (int) $r['n'], 'avg' => $r['avg'] !== null ? (int) round($r['avg']) : null, 'pass' => (int) $r['pass'], 'pending' => (int) $r['pend']);
        }
    }

    // Assignments.
    $assign = null;
    if (function_exists('nias_assign_table') && get_option('nias_assign_db_version')) {
        $assign = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) n, SUM(status = "pending") pending, SUM(status = "passed") passed, SUM(status IN ("failed","resubmit")) other FROM ' . nias_assign_table() . ' WHERE post_id = %d', $post_id), ARRAY_A);
    }

    // At-risk students: started, under 50%, no activity for 14+ days.
    $risk = array();
    foreach (array_keys($students) as $uid) {
        $p    = isset($progress[$uid]) ? $progress[$uid] : 0;
        $last = (int) get_user_meta($uid, '_nias_last_seen_' . $post_id, true);
        if (!$last && !empty($watch[$uid])) {
            foreach ($watch[$uid] as $w) {
                $last = max($last, isset($w['u']) ? (int) $w['u'] : 0);
            }
        }
        if ($p < 50 && $last && $now - $last > 14 * DAY_IN_SECONDS) {
            $risk[] = array('uid' => $uid, 'pct' => $p, 'days' => (int) floor(($now - $last) / DAY_IN_SECONDS));
        }
    }
    usort($risk, function ($a, $b) { return $b['days'] - $a['days']; });
    $risk = array_slice($risk, 0, 15);
    foreach ($risk as &$r) {
        $u = get_userdata($r['uid']);
        $r['name'] = $u ? $u->display_name : '#' . $r['uid'];
    }
    unset($r);

    // Sales: last 12 months.
    $months = array();
    for ($i = 11; $i >= 0; $i--) {
        $m = wp_date('Y-m', strtotime('first day of -' . $i . ' month', $now));
        $months[] = array('m' => $m, 'label' => nias_fa_digits(function_exists('nias_cert_jalali') ? preg_replace('/^\S+\s+/u', '', nias_cert_jalali($m . '-15', 'long')) : $m), 'n' => isset($sales[$m]) ? $sales[$m]['n'] : 0, 'sum' => isset($sales[$m]) ? $sales[$m]['sum'] : 0);
    }

    $out = array(
        'title'     => get_the_title($post_id),
        'students'  => $n_students,
        'started'   => $started,
        'completed' => $completed,
        'avg'       => $n_students ? (int) round($sum_pct / $n_students) : 0,
        'funnel'    => $funnel,
        'drop'      => $drop,
        'quizzes'   => $quizzes,
        'assign'    => $assign,
        'risk'      => $risk,
        'months'    => $months,
        'revenue'   => array_sum(wp_list_pluck($sales, 'sum')),
        'orders'    => array_sum(wp_list_pluck($sales, 'n')),
        'at'        => $now,
    );
    set_transient($key, $out, 6 * HOUR_IN_SECONDS);
    return $out;
}

/** The report screen (admin tab + instructor panel). */
function nias_stats_html($user_id, $base_url)
{
    $ids = nias_stats_course_ids($user_id);
    if (!$ids) {
        return '<div class="nst-empty">' . esc_html__('دوره‌ای برای گزارش ندارید.', 'nias-course-widget') . '</div>';
    }
    $pid = isset($_GET['course']) ? (int) $_GET['course'] : $ids[0];
    if (!in_array($pid, $ids, true)) {
        $pid = $ids[0];
    }
    $fresh = isset($_GET['nst_fresh']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nst_fresh'])), 'nias_stats_fresh');
    $s     = nias_stats_course($pid, $fresh);
    $fa    = function ($n) { return nias_fa_digits(number_format_i18n((float) $n)); };
    $pct   = function ($a, $b) { return $b ? (int) round($a / $b * 100) : 0; };
    ob_start();
    ?>
    <div class="nst" dir="rtl">
    <style>
    .nst{font-size:14px;color:#1f2733}
    .nst-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px}
    .nst-top select{min-width:260px;border:1px solid #dbe3ef;border-radius:10px;padding:7px 10px;font:inherit;background:#fff}
    .nst-top .nst-at{color:#64748b;font-size:12.5px}
    .nst-top a{font-weight:700;text-decoration:none}
    .nst-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
    .nst-kpi{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:14px}
    .nst-kpi b{display:block;font-size:24px;font-weight:800;color:#1f2733;margin-top:4px}
    .nst-kpi span{color:#64748b;font-size:12.5px}
    .nst-kpi small{color:#3858e9;font-weight:700}
    .nst-card{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:16px;margin-bottom:16px}
    .nst-card h3{margin:0 0 12px;font-size:15px}
    .nst-hint{font-size:12.5px;color:#64748b;margin:-4px 0 10px;line-height:1.9}
    .nst-bar{display:grid;grid-template-columns:minmax(120px,240px) 1fr 70px;gap:10px;align-items:center;margin:6px 0;font-size:13px}
    .nst-bar .t{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nst-bar .tr{height:12px;border-radius:99px;background:#eef2f8;overflow:hidden}
    .nst-bar .tr i{display:block;height:100%;border-radius:99px;background:#3858e9}
    .nst-bar .v{text-align:left;color:#475569;font-variant-numeric:tabular-nums}
    .nst-drop{background:#fff4e5;color:#92400e;border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px}
    .nst-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .nst-tbl th,.nst-tbl td{padding:8px 10px;border-bottom:1px solid #eef1f6;text-align:right}
    .nst-tbl th{color:#64748b;font-weight:700;background:#f8fafc}
    .nst-months{display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:10px}
    .nst-months .m{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;height:100%;font-size:11px;color:#64748b}
    .nst-months .m i{display:block;width:100%;max-width:34px;background:#3858e9;border-radius:6px 6px 0 0;min-height:2px}
    .nst-months .m b{font-size:11px;color:#1f2733}
    .nst-empty{color:#64748b;padding:12px;text-align:center}
    .nst-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:900px){.nst-grid{grid-template-columns:1fr}.nst-bar{grid-template-columns:110px 1fr 60px}}
    </style>
    <form class="nst-top" method="get" action="">
        <?php foreach ($_GET as $k => $v) { if (!in_array($k, array('course', 'nst_fresh'), true) && is_string($v)) { echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($v))) . '">'; } } ?>
        <select name="course" onchange="this.form.submit()">
            <?php foreach ($ids as $id) : ?><option value="<?php echo (int) $id; ?>" <?php selected($id, $pid); ?>><?php echo esc_html(get_the_title($id)); ?></option><?php endforeach; ?>
        </select>
        <span class="nst-at"><?php echo esc_html(sprintf(__('محاسبه: %s', 'nias-course-widget'), nias_fa_digits((function_exists('nias_cert_jalali') ? nias_cert_jalali(wp_date('Y-m-d', $s['at']), 'numeric') : wp_date('Y/m/d', $s['at'])) . ' ' . wp_date('H:i', $s['at'])))); ?></span>
        <a href="<?php echo esc_url(add_query_arg(array('course' => $pid, 'nst_fresh' => wp_create_nonce('nias_stats_fresh')), $base_url)); ?>"><?php echo esc_html__('به‌روزرسانی', 'nias-course-widget'); ?></a>
    </form>

    <div class="nst-kpis">
        <div class="nst-kpi"><span><?php echo esc_html__('دانشجو', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa($s['students'])); ?></b></div>
        <div class="nst-kpi"><span><?php echo esc_html__('شروع کرده‌اند', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa($s['started'])); ?></b><small><?php echo esc_html($fa($pct($s['started'], $s['students'])) . '٪'); ?></small></div>
        <div class="nst-kpi"><span><?php echo esc_html__('دوره را تمام کرده‌اند', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa($s['completed'])); ?></b><small><?php echo esc_html(sprintf(__('نرخ تکمیل %s٪', 'nias-course-widget'), $fa($pct($s['completed'], $s['students'])))); ?></small></div>
        <div class="nst-kpi"><span><?php echo esc_html__('میانگین پیشرفت', 'nias-course-widget'); ?></span><b><?php echo esc_html($fa($s['avg']) . '٪'); ?></b></div>
        <?php if ($s['orders']) : ?><div class="nst-kpi"><span><?php echo esc_html__('فروش ۱۲ ماه', 'nias-course-widget'); ?></span><b><?php echo esc_html(function_exists('nias_idash_money') ? nias_idash_money($s['revenue']) : $fa($s['revenue'])); ?></b><small><?php echo esc_html(sprintf(__('%s سفارش', 'nias-course-widget'), $fa($s['orders']))); ?></small></div><?php endif; ?>
    </div>

    <div class="nst-card">
        <h3><?php echo esc_html__('ریزش جلسه‌به‌جلسه', 'nias-course-widget'); ?></h3>
        <div class="nst-hint"><?php echo esc_html__('چند نفر از دانشجوها هر جلسهٔ ویدیویی را کامل دیده‌اند (درصد واقعی تماشا در پلیر نیاس). افت ناگهانی یعنی آن جلسه دانشجوها را از دوره بیرون می‌برد.', 'nias-course-widget'); ?></div>
        <?php if ($s['drop']) : ?><div class="nst-drop"><?php echo esc_html(sprintf(__('بیشترین ریزش: %1$s نفر بین «%2$s» و «%3$s»', 'nias-course-widget'), $fa($s['drop']['n']), $s['drop']['from'], $s['drop']['title'])); ?></div><?php endif; ?>
        <?php
        $any = false;
        foreach ($s['funnel'] as $f) {
            if (!$f['seen']) {
                continue;
            }
            $any = true;
            $w   = $pct($f['full'], max(1, $s['students']));
            echo '<div class="nst-bar"><span class="t" title="' . esc_attr($f['title']) . '">' . esc_html($f['title']) . '</span><span class="tr"><i style="width:' . (int) $w . '%"></i></span><span class="v">' . esc_html($fa($f['full']) . ' · ' . $fa($w) . '٪') . '</span></div>';
        }
        if (!$any) {
            echo '<div class="nst-empty">' . esc_html__('هنوز داده‌ای از تماشای ویدیوهای این دوره ثبت نشده است.', 'nias-course-widget') . '</div>';
        }
        ?>
    </div>

    <div class="nst-grid">
        <div class="nst-card">
            <h3><?php echo esc_html__('آزمون‌ها', 'nias-course-widget'); ?></h3>
            <?php if ($s['quizzes']) : ?>
                <table class="nst-tbl"><thead><tr><th><?php echo esc_html__('آزمون', 'nias-course-widget'); ?></th><th><?php echo esc_html__('شرکت‌کننده', 'nias-course-widget'); ?></th><th><?php echo esc_html__('میانگین بهترین نمره', 'nias-course-widget'); ?></th><th><?php echo esc_html__('قبولی', 'nias-course-widget'); ?></th></tr></thead><tbody>
                <?php foreach ($s['quizzes'] as $q) : ?>
                    <tr><td><?php echo esc_html($q['title']); ?></td><td><?php echo esc_html($fa($q['n'])); ?></td><td><?php echo esc_html($q['avg'] !== null ? $fa($q['avg']) . '٪' : '—'); ?></td><td><?php echo esc_html($fa($pct($q['pass'], $q['n'])) . '٪' . ($q['pending'] ? ' · ' . sprintf(__('%s در انتظار تصحیح', 'nias-course-widget'), $fa($q['pending'])) : '')); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else : ?><div class="nst-empty"><?php echo esc_html__('این دوره آزمونی ندارد.', 'nias-course-widget'); ?></div><?php endif; ?>
            <?php if ($s['assign'] && (int) $s['assign']['n']) : ?>
                <h3 style="margin-top:16px"><?php echo esc_html__('تکالیف', 'nias-course-widget'); ?></h3>
                <div><?php echo esc_html(sprintf(__('%1$s ارسال · %2$s قبول · %3$s در انتظار تصحیح · %4$s مردود/ارسال دوباره', 'nias-course-widget'), $fa($s['assign']['n']), $fa($s['assign']['passed']), $fa($s['assign']['pending']), $fa($s['assign']['other']))); ?></div>
            <?php endif; ?>
        </div>
        <div class="nst-card">
            <h3><?php echo esc_html__('دانشجوهای عقب‌افتاده', 'nias-course-widget'); ?></h3>
            <div class="nst-hint"><?php echo esc_html__('زیر ۵۰٪ پیشرفت و بیش از ۱۴ روز بدون فعالیت.', 'nias-course-widget'); ?></div>
            <?php if ($s['risk']) : ?>
                <table class="nst-tbl"><thead><tr><th><?php echo esc_html__('دانشجو', 'nias-course-widget'); ?></th><th><?php echo esc_html__('پیشرفت', 'nias-course-widget'); ?></th><th><?php echo esc_html__('بدون فعالیت', 'nias-course-widget'); ?></th></tr></thead><tbody>
                <?php foreach ($s['risk'] as $r) : ?><tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($fa($r['pct']) . '٪'); ?></td><td><?php echo esc_html(sprintf(__('%s روز', 'nias-course-widget'), $fa($r['days']))); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php else : ?><div class="nst-empty"><?php echo esc_html__('موردی نیست.', 'nias-course-widget'); ?></div><?php endif; ?>
        </div>
    </div>

    <?php if ($s['orders']) : $max = max(1, max(wp_list_pluck($s['months'], 'n'))); ?>
    <div class="nst-card">
        <h3><?php echo esc_html__('فروش ماهانه (تعداد سفارش تکمیل‌شده)', 'nias-course-widget'); ?></h3>
        <div class="nst-months">
            <?php foreach ($s['months'] as $m) : ?>
                <div class="m" title="<?php echo esc_attr($m['label'] . ': ' . (function_exists('nias_idash_money') ? nias_idash_money($m['sum']) : $m['sum'])); ?>"><b><?php echo $m['n'] ? esc_html($fa($m['n'])) : ''; ?></b><i style="height:<?php echo (int) round($m['n'] / $max * 100); ?>%"></i><span><?php echo esc_html($m['label']); ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_action('admin_menu', function () {
    if (!nias_stats_enabled()) {
        return;
    }
    add_submenu_page('nias-course-settings', __('گزارش‌ها', 'nias-course-widget'), __('گزارش‌ها', 'nias-course-widget'), 'manage_options', 'nias-course-stats', function () {
        echo '<div class="nias-settings-app" dir="rtl">';
        nias_settings_topbar('stats');
        echo '<div class="nias-set-shell"><div class="nias-set-main">' . nias_stats_html(get_current_user_id(), admin_url('admin.php?page=nias-course-stats')) . '</div></div></div>'; // phpcs:ignore -- escaped inside
    });
}, 41);

// Order changes: drop cached reports of the courses in the order.
add_action('woocommerce_order_status_changed', function ($order_id) {
    $o = wc_get_order($order_id);
    if ($o) {
        foreach ($o->get_items() as $it) {
            delete_transient('nias_stats_' . (int) $it->get_product_id());
        }
    }
});
