<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quiz results & essay grading (نتایج و تصحیح آزمون‌ها).
 *
 *   - Overview: every quiz with participants, average, pass rate, and the
 *     grading queue (attempts whose essay answers wait for the instructor).
 *   - Quiz view: one row per student (best/last score, attempts, status), CSV.
 *   - Student view: every stored attempt with its per-question review; essay
 *     answers get a score + comment form. Grading recomputes the final score,
 *     updates pass status (and so gated lessons / certificate) and emails the
 *     student. Extra attempt / reset are available per student.
 *
 * The same renderer runs in wp-admin (site admins: every quiz) and inside the
 * front-end instructor dashboard (instructors: quizzes of their own courses).
 * Every action goes through admin-post.php with a nonce + scope check.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Scope / permissions
 * ---------------------------------------------------------------------- */

/**
 * Quiz ids a user may see and grade: null = all (site admin).
 *
 * @return int[]|null
 */
function nias_quiz_grader_scope($user_id)
{
    if (user_can($user_id, 'manage_options')) {
        return null;
    }
    if (!function_exists('nias_user_is_instructor') || !nias_user_is_instructor($user_id) || !function_exists('nias_get_instructor_courses')) {
        return array();
    }
    $ids = array();
    foreach (nias_get_instructor_courses($user_id) as $p) {
        $ids = array_merge($ids, nias_quiz_for_course((int) $p->ID));
    }
    if (function_exists('nias_inst_panel_user') && nias_inst_panel_user($user_id)) {
        $ids = array_merge($ids, nias_inst_quiz_ids($user_id)); // quizzes they built in the panel
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

function nias_quiz_can_grade($user_id, $quiz_id)
{
    $scope = nias_quiz_grader_scope($user_id);
    return $scope === null || in_array((int) $quiz_id, $scope, true);
}

/* -------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------- */

/** Rows per page in the results lists. */
if (!defined('NIAS_QR_PER_PAGE')) {
    define('NIAS_QR_PER_PAGE', 25);
}

/** "AND quiz_id IN (…)" for a scope (null = all, [] = nothing). */
function nias_quiz_scope_sql($scope, $col = 'quiz_id')
{
    if ($scope === null) {
        return '';
    }
    $ids = array_filter(array_map('intval', (array) $scope));
    return $ids ? " AND {$col} IN (" . implode(',', $ids) . ')' : ' AND 1=0';
}

/** Number of attempts waiting for grading (cheap: indexed SUM). */
function nias_quiz_pending_count($scope)
{
    global $wpdb;
    if (!nias_quiz_index_ready()) {
        return 0;
    }
    $t = nias_quiz_table();
    return (int) $wpdb->get_var("SELECT COALESCE(SUM(pending),0) FROM {$t} WHERE pending > 0" . nias_quiz_scope_sql($scope));
}

/**
 * One page of the grading queue: (user, quiz) pairs with pending attempts,
 * newest first, then their pending attempts (only those users' meta is read).
 *
 * @return array { items: [ {user, quiz, attempt} ], total: int (pairs) }
 */
function nias_quiz_pending_page($scope, $page = 1, $per = NIAS_QR_PER_PAGE)
{
    global $wpdb;
    if (!nias_quiz_index_ready()) {
        return array('items' => array(), 'total' => 0);
    }
    $t     = nias_quiz_table();
    $where = 'WHERE pending > 0' . nias_quiz_scope_sql($scope);
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} {$where}");
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, quiz_id FROM {$t} {$where} ORDER BY updated DESC LIMIT %d OFFSET %d",
        $per, max(0, ($page - 1) * $per)
    ));
    $items = array();
    if ($rows) {
        update_meta_cache('user', array_unique(array_map('intval', wp_list_pluck($rows, 'user_id'))));
    }
    foreach ((array) $rows as $r) {
        foreach (nias_quiz_user_attempts((int) $r->user_id, (int) $r->quiz_id) as $a) {
            if ($a['status'] === 'pending') {
                $items[] = array('user' => (int) $r->user_id, 'quiz' => (int) $r->quiz_id, 'attempt' => $a);
            }
        }
    }
    usort($items, function ($x, $y) {
        return strcmp($y['attempt']['date'], $x['attempt']['date']);
    });
    return array('items' => $items, 'total' => $total);
}

/** Back-compat: first page of the queue as a plain list. */
function nias_quiz_pending_queue($scope)
{
    $p = nias_quiz_pending_page($scope, 1, 50);
    return $p['items'];
}

/**
 * Participants, average best, pass rate, pending for several quizzes in one query.
 *
 * @param int[] $quiz_ids
 * @return array quiz id => stats
 */
function nias_quiz_stats_many($quiz_ids)
{
    global $wpdb;
    $ids = array_filter(array_map('intval', (array) $quiz_ids));
    $out = array();
    foreach ($ids as $id) {
        $out[$id] = array('participants' => 0, 'avg' => null, 'passRate' => null, 'passed' => 0, 'pending' => 0);
    }
    if (!$ids || !nias_quiz_index_ready()) {
        return $out;
    }
    $t = nias_quiz_table();
    $rows = $wpdb->get_results("SELECT quiz_id, COUNT(*) n, AVG(best) avg_best, SUM(passed) passed, SUM(pending > 0) pend FROM {$t} WHERE quiz_id IN (" . implode(',', $ids) . ') GROUP BY quiz_id');
    foreach ((array) $rows as $r) {
        $n = (int) $r->n;
        $out[(int) $r->quiz_id] = array(
            'participants' => $n,
            'avg'          => $r->avg_best === null ? null : (int) round($r->avg_best),
            'passRate'     => $n ? (int) round($r->passed / $n * 100) : null,
            'passed'       => (int) $r->passed,
            'pending'      => (int) $r->pend,
        );
    }
    return $out;
}

function nias_quiz_stats($quiz_id)
{
    $s = nias_quiz_stats_many(array($quiz_id));
    return $s[(int) $quiz_id];
}

/** Totals over a scope: distinct students, average best. */
function nias_quiz_scope_totals($scope)
{
    global $wpdb;
    if (!nias_quiz_index_ready()) {
        return array('students' => 0, 'avg' => null);
    }
    $t = nias_quiz_table();
    $r = $wpdb->get_row("SELECT COUNT(DISTINCT user_id) students, AVG(best) avg_best FROM {$t} WHERE 1=1" . nias_quiz_scope_sql($scope));
    return array('students' => (int) $r->students, 'avg' => $r->avg_best === null ? null : (int) round($r->avg_best));
}

/**
 * One page of a quiz's students (filter + search on name/email/login).
 *
 * @return array { rows: [ {user_id, best, last, attempts, passed, pending, updated} ], total }
 */
function nias_quiz_participants_page($quiz_id, $filter, $search, $page, $per = NIAS_QR_PER_PAGE)
{
    global $wpdb;
    $t = nias_quiz_table();
    $where = $wpdb->prepare('WHERE r.quiz_id = %d', $quiz_id);
    if ($filter === 'pending') {
        $where .= ' AND r.pending > 0';
    } elseif ($filter === 'passed') {
        $where .= ' AND r.passed = 1';
    } elseif ($filter === 'failed') {
        $where .= ' AND r.passed = 0 AND r.best IS NOT NULL';
    }
    $join = '';
    if ($search !== '') {
        $like  = '%' . $wpdb->esc_like($search) . '%';
        $join  = " INNER JOIN {$wpdb->users} u ON u.ID = r.user_id";
        $where .= $wpdb->prepare(' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)', $like, $like, $like);
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} r{$join} {$where}");
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT r.* FROM {$t} r{$join} {$where} ORDER BY r.updated DESC LIMIT %d OFFSET %d",
        $per, max(0, ($page - 1) * $per)
    ), ARRAY_A);
    if ($rows) {
        cache_users(wp_list_pluck($rows, 'user_id')); // one query for the page's users
    }
    return array('rows' => (array) $rows, 'total' => $total);
}

/** Back-compat: user ids that took a quiz (avoid on large sites; use the paged query). */
function nias_quiz_participants($quiz_id)
{
    global $wpdb;
    $t = nias_quiz_table();
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$t} WHERE quiz_id = %d", $quiz_id)));
}

/** Courses a quiz belongs to (titles), from the quiz → course index. */
function nias_quiz_course_titles($quiz_id)
{
    return array_map('get_the_title', nias_quiz_course_ids($quiz_id));
}

/** Pager: «قبلی» on the left, «بعدی» on the right (site convention). */
function nias_quiz_results_pager($base, $page, $total, $per = NIAS_QR_PER_PAGE, $arg = 'pg')
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav class="nqr-pager">';
    $h .= $page > 1 ? '<a class="nqr-btn" href="' . esc_url(add_query_arg($arg, $page - 1, $base)) . '">‹ ' . esc_html__('قبلی', 'nias-course-widget') . '</a>' : '<span class="nqr-btn off">‹ ' . esc_html__('قبلی', 'nias-course-widget') . '</span>';
    $h .= '<span class="nqr-pager-i">' . esc_html(sprintf(__('صفحهٔ %1$s از %2$s', 'nias-course-widget'), nias_fa_digits($page), nias_fa_digits($pages))) . '</span>';
    $h .= $page < $pages ? '<a class="nqr-btn" href="' . esc_url(add_query_arg($arg, $page + 1, $base)) . '">' . esc_html__('بعدی', 'nias-course-widget') . ' ›</a>' : '<span class="nqr-btn off">' . esc_html__('بعدی', 'nias-course-widget') . ' ›</span>';
    return $h . '</nav>';
}

function nias_quiz_results_page_arg($arg = 'pg')
{
    return isset($_GET[$arg]) ? max(1, (int) $_GET[$arg]) : 1;
}

/** Jalali date + time for a stored mysql datetime. */
function nias_quiz_dt($mysql)
{
    if (!$mysql) {
        return '—';
    }
    $day  = nias_quiz_date_label(substr($mysql, 0, 10));
    $time = strlen($mysql) >= 16 ? nias_fa_digits(substr($mysql, 11, 5)) : '';
    return trim($day . ' ' . $time);
}

/* -------------------------------------------------------------------------
 * Actions (admin-post.php)
 * ---------------------------------------------------------------------- */

/** Common guard: logged in, valid nonce, quiz in the grader's scope. */
function nias_quiz_results_guard($action)
{
    $qid = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : (isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0);
    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
    if (!is_user_logged_in() || !wp_verify_nonce($nonce, $action . '_' . $qid) || !nias_quiz_can_grade(get_current_user_id(), $qid)) {
        wp_die(esc_html__('دسترسی لازم را ندارید یا درخواست منقضی شده است.', 'nias-course-widget'), '', array('response' => 403, 'back_link' => true));
    }
    return $qid;
}

function nias_quiz_results_back($notice)
{
    $back = isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '';
    $back = wp_validate_redirect($back, admin_url('admin.php?page=nias-course-quiz-results'));
    wp_safe_redirect(add_query_arg('nqr_notice', $notice, $back));
    exit;
}

add_action('admin_post_nias_quiz_grade', 'nias_quiz_results_do_grade');
function nias_quiz_results_do_grade()
{
    $qid = nias_quiz_results_guard('nias_quiz_grade');
    $uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $att = isset($_POST['attempt']) ? sanitize_key(wp_unslash($_POST['attempt'])) : '';
    $scores   = isset($_POST['score']) && is_array($_POST['score']) ? array_map('sanitize_text_field', wp_unslash($_POST['score'])) : array();
    $feedback = isset($_POST['feedback']) && is_array($_POST['feedback']) ? wp_unslash($_POST['feedback']) : array();
    $note     = isset($_POST['note']) ? wp_unslash($_POST['note']) : '';
    $res = nias_quiz_grade_attempt($uid, $qid, $att, $scores, $feedback, $note, get_current_user_id());
    nias_quiz_results_back(is_wp_error($res) ? 'error' : 'graded');
}

add_action('admin_post_nias_quiz_bonus', 'nias_quiz_results_do_bonus');
function nias_quiz_results_do_bonus()
{
    $qid = nias_quiz_results_guard('nias_quiz_bonus');
    $uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $rec = get_user_meta($uid, nias_quiz_result_meta_key($qid), true);
    if (is_array($rec)) {
        $rec['bonus'] = (isset($rec['bonus']) ? (int) $rec['bonus'] : 0) + 1;
        update_user_meta($uid, nias_quiz_result_meta_key($qid), $rec);
        nias_quiz_index_sync($uid, $qid);
    }
    nias_quiz_results_back('bonus');
}

add_action('admin_post_nias_quiz_reset', 'nias_quiz_results_do_reset');
function nias_quiz_results_do_reset()
{
    $qid = nias_quiz_results_guard('nias_quiz_reset');
    $uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    delete_user_meta($uid, nias_quiz_result_meta_key($qid));
    delete_user_meta($uid, nias_quiz_attempts_key($qid));
    nias_quiz_index_sync($uid, $qid); // removes the row
    $back = isset($_POST['back']) ? remove_query_arg('user', esc_url_raw(wp_unslash($_POST['back']))) : '';
    $_POST['back'] = $back;
    nias_quiz_results_back('reset');
}

add_action('admin_post_nias_quiz_csv', 'nias_quiz_results_do_csv');
function nias_quiz_results_do_csv()
{
    $qid  = nias_quiz_results_guard('nias_quiz_csv');
    $quiz = nias_quiz_get($qid);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz-' . $qid . '-results.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, array('نام', 'ایمیل', 'بهترین نمره', 'آخرین نمره', 'دفعات شرکت', 'وضعیت', 'در انتظار تصحیح', 'تاریخ قبولی', 'آخرین شرکت'));
    // Streamed in chunks: memory stays flat however many students took the quiz.
    global $wpdb;
    $t = nias_quiz_table();
    $offset = 0;
    do {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name, u.user_email FROM {$t} r INNER JOIN {$wpdb->users} u ON u.ID = r.user_id WHERE r.quiz_id = %d ORDER BY r.user_id ASC LIMIT 500 OFFSET %d",
            $qid, $offset
        ), ARRAY_A);
        foreach ((array) $rows as $r) {
            $pd = get_user_meta((int) $r['user_id'], nias_quiz_result_meta_key($qid), true);
            fputcsv($out, array(
                $r['display_name'], $r['user_email'],
                $r['best'] === null ? '' : $r['best'], $r['last'] === null ? '' : $r['last'], $r['attempts'],
                $r['passed'] ? 'قبول' : ($r['best'] === null ? 'در انتظار تصحیح' : 'مردود'),
                $r['pending'], is_array($pd) && !empty($pd['passed_date']) ? $pd['passed_date'] : '', substr($r['updated'], 0, 10),
            ));
        }
        $offset += 500;
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime(); // drop the chunk's user-meta from memory
        }
    } while ($rows && count($rows) === 500);
    fclose($out);
    exit;
}

/* -------------------------------------------------------------------------
 * Renderer
 * ---------------------------------------------------------------------- */

/**
 * @param array $ctx base (URL of the view), scope (quiz ids|null), front (bool)
 * @return string
 */
function nias_quiz_results_render($ctx)
{
    $base  = $ctx['base'];
    $scope = $ctx['scope'];
    $qid   = isset($_GET['quiz']) ? (int) $_GET['quiz'] : 0;
    $uid   = isset($_GET['user']) ? (int) $_GET['user'] : 0;
    if ($qid && $scope !== null && !in_array($qid, $scope, true)) {
        $qid = 0;
    }

    $html = nias_quiz_results_css();
    $notice = isset($_GET['nqr_notice']) ? sanitize_key(wp_unslash($_GET['nqr_notice'])) : '';
    $notices = array(
        'graded' => __('نمره ثبت شد و نتیجه به دانشجو اعلام شد.', 'nias-course-widget'),
        'bonus'  => __('یک فرصت شرکت دیگر به دانشجو داده شد.', 'nias-course-widget'),
        'reset'  => __('نتایج این دانشجو در این آزمون پاک شد.', 'nias-course-widget'),
        'error'  => __('ثبت انجام نشد؛ دوباره تلاش کنید.', 'nias-course-widget'),
    );
    if (isset($notices[$notice])) {
        $html .= '<div class="nqr-notice ' . ($notice === 'error' ? 'err' : '') . '">' . esc_html($notices[$notice]) . '</div>';
    }

    if ($qid && $uid) {
        $html .= nias_quiz_results_student($base, $qid, $uid);
    } elseif ($qid) {
        $html .= nias_quiz_results_quiz($base, $qid);
    } else {
        $html .= nias_quiz_results_overview($base, $scope);
    }
    return '<div class="nqr" dir="rtl">' . $html . '</div>';
}

function nias_quiz_results_kpi($label, $value, $tone = '')
{
    return '<div class="nqr-kpi ' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><b>' . esc_html($value) . '</b></div>';
}

function nias_quiz_results_user_cell($uid)
{
    $u = get_userdata($uid);
    if (!$u) {
        return '<span class="nqr-muted">' . esc_html__('کاربر حذف‌شده', 'nias-course-widget') . '</span>';
    }
    return '<div class="nqr-user">' . get_avatar($uid, 32) . '<div><b>' . esc_html($u->display_name) . '</b><span>' . esc_html($u->user_email) . '</span></div></div>';
}

function nias_quiz_results_status($r)
{
    if (!$r) {
        return '<span class="nqr-st">' . esc_html__('شرکت نکرده', 'nias-course-widget') . '</span>';
    }
    $s = $r['passed'] ? '<span class="nqr-st ok">' . esc_html__('قبول', 'nias-course-widget') . '</span>'
        : ($r['best'] === null ? '' : '<span class="nqr-st no">' . esc_html__('مردود', 'nias-course-widget') . '</span>');
    if ($r['pending']) {
        $s .= ' <span class="nqr-st wait">' . esc_html__('در انتظار تصحیح', 'nias-course-widget') . '</span>';
    }
    return $s;
}

function nias_quiz_results_overview($base, $scope)
{
    $s_term = isset($_GET['qs']) ? sanitize_text_field(wp_unslash($_GET['qs'])) : '';
    $qpage  = nias_quiz_results_page_arg('qpg');
    $ppage  = nias_quiz_results_page_arg('ppg');

    // Quizzes: one page, optional title search (never the whole list).
    $qargs = array(
        'post_type'      => NIAS_QUIZ_CPT,
        'post_status'    => array('publish', 'draft'),
        'posts_per_page' => NIAS_QR_PER_PAGE,
        'paged'          => $qpage,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    );
    if ($s_term !== '') {
        $qargs['s'] = $s_term;
        $qargs['search_columns'] = array('post_title');
    }
    if ($scope !== null) {
        $qargs['post__in'] = $scope ? $scope : array(0);
    }
    $qq       = new WP_Query($qargs);
    $quiz_ids = array_map('intval', $qq->posts);
    $n_quiz   = (int) $qq->found_posts;
    _prime_post_caches($quiz_ids, false, true); // posts + meta in two queries
    $stats    = nias_quiz_stats_many($quiz_ids);
    $totals   = nias_quiz_scope_totals($scope);
    $pending  = nias_quiz_pending_count($scope);
    $queue    = nias_quiz_pending_page($scope, $ppage);
    if ($s_term === '') {
        $all_quizzes = $scope === null ? (int) wp_count_posts(NIAS_QUIZ_CPT)->publish + (int) wp_count_posts(NIAS_QUIZ_CPT)->draft : count($scope);
    } else {
        $all_quizzes = $n_quiz;
    }

    $h = '<div class="nqr-head"><h2>' . esc_html__('نتایج و تصحیح آزمون‌ها', 'nias-course-widget') . '</h2></div>';
    if (function_exists('nias_quiz_index_complete') && !nias_quiz_index_complete()) {
        $h .= '<div class="nqr-notice">' . esc_html__('در حال آماده‌سازی فهرست نتایج قبلی… با هر بار باز کردن پیشخوان بخشی از آن انجام می‌شود.', 'nias-course-widget') . '</div>';
    }
    $h .= '<div class="nqr-kpis">'
        . nias_quiz_results_kpi(__('آزمون', 'nias-course-widget'), nias_fa_digits($all_quizzes))
        . nias_quiz_results_kpi(__('دانشجوی شرکت‌کرده', 'nias-course-widget'), nias_fa_digits($totals['students']))
        . nias_quiz_results_kpi(__('در انتظار تصحیح', 'nias-course-widget'), nias_fa_digits($pending), $pending ? 'wait' : '')
        . nias_quiz_results_kpi(__('میانگین نمره', 'nias-course-widget'), $totals['avg'] === null ? '—' : nias_fa_digits($totals['avg']) . '٪')
        . '</div>';

    // Grading queue (paged).
    $h .= '<section class="nqr-card" id="nqr-queue"><header><h3>' . esc_html__('صف تصحیح سوال‌های تشریحی', 'nias-course-widget') . '</h3><span class="nqr-count">' . nias_fa_digits($pending) . '</span></header>';
    if (!$queue['items']) {
        $h .= '<div class="nqr-empty">' . esc_html__('پاسخی در انتظار تصحیح نیست. 🎉', 'nias-course-widget') . '</div>';
    } else {
        $qids = array_unique(wp_list_pluck($queue['items'], 'quiz'));
        cache_users(array_unique(wp_list_pluck($queue['items'], 'user')));
        $h .= '<table class="nqr-table"><thead><tr><th>' . esc_html__('دانشجو', 'nias-course-widget') . '</th><th>' . esc_html__('آزمون', 'nias-course-widget') . '</th><th>' . esc_html__('سوال تشریحی', 'nias-course-widget') . '</th><th>' . esc_html__('زمان ارسال', 'nias-course-widget') . '</th><th></th></tr></thead><tbody>';
        foreach ($queue['items'] as $item) {
            $url = add_query_arg(array('quiz' => $item['quiz'], 'user' => $item['user']), $base) . '#a-' . $item['attempt']['id'];
            $h .= '<tr><td>' . nias_quiz_results_user_cell($item['user']) . '</td>'
                . '<td>' . esc_html(get_the_title($item['quiz'])) . '</td>'
                . '<td>' . esc_html(nias_fa_digits(count($item['attempt']['essays']))) . '</td>'
                . '<td>' . esc_html(nias_quiz_dt($item['attempt']['date'])) . '</td>'
                . '<td class="nqr-act"><a class="nqr-btn pri" href="' . esc_url($url) . '">' . esc_html__('تصحیح', 'nias-course-widget') . '</a></td></tr>';
        }
        $h .= '</tbody></table>' . nias_quiz_results_pager(add_query_arg('qs', $s_term, $base) . '#nqr-queue', $ppage, $queue['total'], NIAS_QR_PER_PAGE, 'ppg');
    }
    $h .= '</section>';

    // Quizzes (paged + search).
    $h .= '<section class="nqr-card" id="nqr-quizzes"><header><h3>' . esc_html__('آزمون‌ها', 'nias-course-widget') . '</h3>'
        . '<form class="nqr-hsearch" method="get" action="' . esc_url(strtok($base, '?')) . '">' . nias_quiz_results_hidden($base, array('qs', 'qpg'))
        . '<input class="nqr-search" type="search" name="qs" value="' . esc_attr($s_term) . '" placeholder="' . esc_attr__('جستجوی نام آزمون یا شماره…', 'nias-course-widget') . '"></form></header>';
    if (!$quiz_ids) {
        $h .= '<div class="nqr-empty">' . esc_html($s_term !== '' ? sprintf(__('آزمونی با «%s» پیدا نشد.', 'nias-course-widget'), $s_term) : __('آزمونی وجود ندارد.', 'nias-course-widget')) . '</div>';
    } else {
        $h .= '<table class="nqr-table"><thead><tr><th>' . esc_html__('آزمون', 'nias-course-widget') . '</th><th>' . esc_html__('دوره', 'nias-course-widget') . '</th><th>' . esc_html__('شرکت‌کننده', 'nias-course-widget') . '</th><th>' . esc_html__('میانگین', 'nias-course-widget') . '</th><th>' . esc_html__('قبولی', 'nias-course-widget') . '</th><th>' . esc_html__('در انتظار', 'nias-course-widget') . '</th><th></th></tr></thead><tbody>';
        foreach ($quiz_ids as $qid) {
            $s = $stats[$qid];
            $courses = nias_quiz_course_titles($qid);
            $qc = get_post_meta($qid, '_nias_quiz_qcount', true);
            $ps = get_post_meta($qid, '_nias_quiz_pass', true);
            $h .= '<tr><td><b>' . esc_html(get_the_title($qid)) . '</b><span class="nqr-sub">' . esc_html('#' . nias_fa_digits($qid) . ($qc !== '' ? ' · ' . sprintf(__('%1$s سوال · قبولی از %2$s٪', 'nias-course-widget'), nias_fa_digits((int) $qc), nias_fa_digits((int) $ps)) : '')) . '</span></td>'
                . '<td>' . ($courses ? esc_html(implode('، ', array_slice($courses, 0, 3)) . (count($courses) > 3 ? '، …' : '')) : '<span class="nqr-muted">—</span>') . '</td>'
                . '<td>' . esc_html(nias_fa_digits($s['participants'])) . '</td>'
                . '<td>' . ($s['avg'] === null ? '—' : esc_html(nias_fa_digits($s['avg']) . '٪')) . '</td>'
                . '<td>' . ($s['passRate'] === null ? '—' : esc_html(nias_fa_digits($s['passRate']) . '٪')) . '</td>'
                . '<td>' . ($s['pending'] ? '<span class="nqr-st wait">' . esc_html(nias_fa_digits($s['pending'])) . '</span>' : '—') . '</td>'
                . '<td class="nqr-act"><a class="nqr-btn" href="' . esc_url(add_query_arg('quiz', $qid, remove_query_arg(array('qs', 'qpg', 'ppg'), $base))) . '">' . esc_html__('نتایج', 'nias-course-widget') . '</a></td></tr>';
        }
        $h .= '</tbody></table>' . nias_quiz_results_pager(add_query_arg('qs', $s_term, $base) . '#nqr-quizzes', $qpage, $n_quiz, NIAS_QR_PER_PAGE, 'qpg');
    }
    return $h . '</section>';
}

/** Hidden inputs that keep the base URL's query (minus some keys) in a GET form. */
function nias_quiz_results_hidden($base, $drop)
{
    $h = '';
    foreach (wp_parse_args((string) wp_parse_url($base, PHP_URL_QUERY)) as $k => $v) {
        if (!in_array($k, $drop, true) && is_scalar($v)) {
            $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
    }
    return $h;
}

function nias_quiz_results_quiz($base, $qid)
{
    $quiz = nias_quiz_get($qid);
    if (!$quiz) {
        return '<div class="nqr-empty">' . esc_html__('آزمون یافت نشد.', 'nias-course-widget') . '</div>';
    }
    $filter = isset($_GET['f']) ? sanitize_key(wp_unslash($_GET['f'])) : 'all';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $page   = nias_quiz_results_page_arg('pg');
    $s      = nias_quiz_stats($qid);
    $list   = nias_quiz_participants_page($qid, $filter, $search, $page);
    $here   = add_query_arg(array('quiz' => $qid, 'f' => $filter, 's' => $search), remove_query_arg(array('pg', 'nqr_notice'), $base));

    $h = '<div class="nqr-head"><a class="nqr-back" href="' . esc_url(remove_query_arg(array('quiz', 'user', 'f', 's', 'pg', 'nqr_notice'), $base)) . '">→ ' . esc_html__('همهٔ آزمون‌ها', 'nias-course-widget') . '</a>'
        . '<h2>' . esc_html($quiz['title']) . '</h2>'
        . '<a class="nqr-btn" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_quiz_csv&quiz_id=' . $qid), 'nias_quiz_csv_' . $qid)) . '">' . esc_html__('خروجی اکسل (CSV)', 'nias-course-widget') . '</a></div>';
    $h .= '<div class="nqr-kpis">'
        . nias_quiz_results_kpi(__('شرکت‌کننده', 'nias-course-widget'), nias_fa_digits($s['participants']))
        . nias_quiz_results_kpi(__('قبول', 'nias-course-widget'), nias_fa_digits($s['passed']) . ($s['passRate'] !== null ? ' (' . nias_fa_digits($s['passRate']) . '٪)' : ''), 'ok')
        . nias_quiz_results_kpi(__('میانگین بهترین نمره', 'nias-course-widget'), $s['avg'] === null ? '—' : nias_fa_digits($s['avg']) . '٪')
        . nias_quiz_results_kpi(__('در انتظار تصحیح', 'nias-course-widget'), nias_fa_digits($s['pending']), $s['pending'] ? 'wait' : '')
        . '</div>';

    $chips = array('all' => __('همه', 'nias-course-widget'), 'pending' => __('در انتظار تصحیح', 'nias-course-widget'), 'passed' => __('قبول', 'nias-course-widget'), 'failed' => __('مردود', 'nias-course-widget'));
    $h .= '<form class="nqr-filter" method="get" action="' . esc_url(strtok($base, '?')) . '">' . nias_quiz_results_hidden($base, array('quiz', 'f', 's', 'pg', 'user', 'nqr_notice'));
    $h .= '<input type="hidden" name="quiz" value="' . (int) $qid . '"><input type="hidden" name="f" value="' . esc_attr($filter) . '">';
    foreach ($chips as $k => $label) {
        $h .= '<a class="nqr-chip ' . ($filter === $k ? 'on' : '') . '" href="' . esc_url(add_query_arg(array('quiz' => $qid, 'f' => $k, 's' => $search), remove_query_arg(array('pg', 'nqr_notice'), $base))) . '">' . esc_html($label) . '</a>';
    }
    $h .= '<input class="nqr-search" type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('جستجوی نام، ایمیل یا نام کاربری…', 'nias-course-widget') . '"></form>';

    $h .= '<section class="nqr-card">';
    if (!$list['rows']) {
        $h .= '<div class="nqr-empty">' . esc_html__('موردی پیدا نشد.', 'nias-course-widget') . '</div>';
    } else {
        $h .= '<div class="nqr-count-line">' . esc_html(sprintf(__('%s دانشجو', 'nias-course-widget'), nias_fa_digits($list['total']))) . '</div>';
        $h .= '<table class="nqr-table"><thead><tr><th>' . esc_html__('دانشجو', 'nias-course-widget') . '</th><th>' . esc_html__('بهترین', 'nias-course-widget') . '</th><th>' . esc_html__('آخرین', 'nias-course-widget') . '</th><th>' . esc_html__('دفعات', 'nias-course-widget') . '</th><th>' . esc_html__('وضعیت', 'nias-course-widget') . '</th><th>' . esc_html__('آخرین شرکت', 'nias-course-widget') . '</th><th></th></tr></thead><tbody>';
        foreach ($list['rows'] as $row) {
            $uid = (int) $row['user_id'];
            $r = array(
                'best' => $row['best'] === null ? null : (int) $row['best'], 'last' => $row['last'] === null ? null : (int) $row['last'],
                'passed' => (bool) $row['passed'], 'pending' => (int) $row['pending'], 'attempts' => (int) $row['attempts'],
            );
            $left = null;
            if ((int) $quiz['maxAttempts'] > 0) {
                $left = nias_quiz_attempts_left($quiz, nias_quiz_user_record($uid, $qid));
            }
            $h .= '<tr><td>' . nias_quiz_results_user_cell($uid) . '</td>'
                . '<td class="nqr-score">' . ($r['best'] === null ? '—' : esc_html(nias_fa_digits($r['best']) . '٪')) . '</td>'
                . '<td>' . ($r['last'] === null ? '—' : esc_html(nias_fa_digits($r['last']) . '٪')) . '</td>'
                . '<td>' . esc_html(nias_fa_digits($r['attempts'])) . ($left !== null ? '<span class="nqr-sub">' . esc_html(sprintf(__('%s فرصت مانده', 'nias-course-widget'), nias_fa_digits($left))) . '</span>' : '') . '</td>'
                . '<td>' . nias_quiz_results_status($r) . '</td>'
                . '<td>' . esc_html(nias_quiz_dt($row['updated'])) . '</td>'
                . '<td class="nqr-act"><a class="nqr-btn ' . ($r['pending'] ? 'pri' : '') . '" href="' . esc_url(add_query_arg('user', $uid, $here)) . '">' . esc_html($r['pending'] ? __('تصحیح', 'nias-course-widget') : __('جزئیات', 'nias-course-widget')) . '</a></td></tr>';
        }
        $h .= '</tbody></table>' . nias_quiz_results_pager($here, $page, $list['total']);
    }
    return $h . '</section>';
}

function nias_quiz_results_student($base, $qid, $uid)
{
    $quiz = nias_quiz_get($qid);
    $u    = get_userdata($uid);
    if (!$quiz || !$u) {
        return '<div class="nqr-empty">' . esc_html__('موردی یافت نشد.', 'nias-course-widget') . '</div>';
    }
    $r        = nias_quiz_user_record($uid, $qid);
    $attempts = array_reverse(nias_quiz_user_attempts($uid, $qid));
    $here     = add_query_arg(array('quiz' => $qid, 'user' => $uid), remove_query_arg('nqr_notice', $base));
    $post     = admin_url('admin-post.php');
    $left     = nias_quiz_attempts_left($quiz, $r);

    $h = '<div class="nqr-head"><a class="nqr-back" href="' . esc_url(add_query_arg('quiz', $qid, remove_query_arg(array('user', 'nqr_notice'), $base))) . '">→ ' . esc_html($quiz['title']) . '</a></div>';
    $h .= '<section class="nqr-card nqr-stu"><div class="nqr-stu-id">' . nias_quiz_results_user_cell($uid) . '<div class="nqr-stu-st">' . nias_quiz_results_status($r) . '</div></div>';
    if ($r) {
        $h .= '<div class="nqr-kpis in">'
            . nias_quiz_results_kpi(__('بهترین نمره', 'nias-course-widget'), $r['best'] === null ? '—' : nias_fa_digits($r['best']) . '٪', $r['passed'] ? 'ok' : '')
            . nias_quiz_results_kpi(__('آخرین نمره', 'nias-course-widget'), $r['last'] === null ? '—' : nias_fa_digits($r['last']) . '٪')
            . nias_quiz_results_kpi(__('دفعات شرکت', 'nias-course-widget'), nias_fa_digits($r['attempts']) . ($left !== null ? ' / ' . nias_fa_digits($left) . ' ' . __('مانده', 'nias-course-widget') : ''))
            . nias_quiz_results_kpi(__('تاریخ قبولی', 'nias-course-widget'), $r['passedDate'] ? nias_quiz_date_label($r['passedDate']) : '—')
            . '</div>';
        $h .= '<div class="nqr-stu-actions">';
        if ($left !== null) {
            $h .= '<form method="post" action="' . esc_url($post) . '">' . wp_nonce_field('nias_quiz_bonus_' . $qid, '_wpnonce', false, false)
                . '<input type="hidden" name="action" value="nias_quiz_bonus"><input type="hidden" name="quiz_id" value="' . (int) $qid . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="back" value="' . esc_attr($here) . '">'
                . '<button class="nqr-btn" type="submit">' . esc_html__('+ یک فرصت شرکت دیگر', 'nias-course-widget') . '</button></form>';
        }
        $h .= '<form method="post" action="' . esc_url($post) . '" onsubmit="return confirm(\'' . esc_js(__('همهٔ نتایج و پاسخ‌های این دانشجو در این آزمون پاک شود؟', 'nias-course-widget')) . '\')">' . wp_nonce_field('nias_quiz_reset_' . $qid, '_wpnonce', false, false)
            . '<input type="hidden" name="action" value="nias_quiz_reset"><input type="hidden" name="quiz_id" value="' . (int) $qid . '"><input type="hidden" name="user_id" value="' . (int) $uid . '"><input type="hidden" name="back" value="' . esc_attr($here) . '">'
            . '<button class="nqr-btn danger" type="submit">' . esc_html__('پاک کردن نتایج (شرکت از نو)', 'nias-course-widget') . '</button></form>';
        $h .= '</div>';
    }
    $h .= '</section>';

    if (!$attempts) {
        return $h . '<div class="nqr-empty">' . esc_html__('جزئیات شرکت در آزمون برای این دانشجو ثبت نشده است (نتایج قدیمی‌تر فقط نمرهٔ کلی دارند).', 'nias-course-widget') . '</div>';
    }

    $n = count($attempts);
    foreach ($attempts as $i => $a) {
        $num = $n - $i;
        $st = $a['status'] === 'pending' ? '<span class="nqr-st wait">' . esc_html__('در انتظار تصحیح', 'nias-course-widget') . '</span>'
            : ((int) $a['final'] >= (int) $quiz['passScore'] ? '<span class="nqr-st ok">' . esc_html__('قبول', 'nias-course-widget') . '</span>' : '<span class="nqr-st no">' . esc_html__('مردود', 'nias-course-widget') . '</span>');
        $open = $a['status'] === 'pending' || $i === 0;
        $h .= '<details class="nqr-card nqr-att" id="a-' . esc_attr($a['id']) . '"' . ($open ? ' open' : '') . '><summary>'
            . '<b>' . esc_html(sprintf(__('شرکت %s', 'nias-course-widget'), nias_fa_digits($num))) . '</b>'
            . '<span class="nqr-muted">' . esc_html(nias_quiz_dt($a['date'])) . '</span>' . $st
            . '<span class="nqr-att-score">' . ($a['final'] === null
                ? esc_html(sprintf(__('بخش خودکار: %s٪', 'nias-course-widget'), nias_fa_digits($a['pct'])))
                : esc_html(sprintf(__('نمرهٔ نهایی: %s٪', 'nias-course-widget'), nias_fa_digits($a['final'])))) . '</span></summary>';

        // Per-question review.
        $h .= '<div class="nqr-review">';
        foreach ((array) $a['review'] as $k => $rv) {
            $cls = !$rv['isAuto'] ? 'man' : ($rv['ok'] ? 'ok' : 'no');
            $h .= '<div class="nqr-rv ' . $cls . '"><span class="nqr-rv-n">' . esc_html(nias_fa_digits($k + 1)) . '</span><div><div class="nqr-rv-t">' . esc_html($rv['text']) . '</div>'
                . ($rv['detail'] !== '' && $rv['isAuto'] ? '<div class="nqr-rv-d">' . esc_html($rv['detail']) . '</div>' : '') . '</div>'
                . '<span class="nqr-rv-s">' . esc_html($rv['isAuto'] ? $rv['score'] : __('تشریحی', 'nias-course-widget')) . '</span></div>';
        }
        $h .= '</div>';

        // Essay grading form.
        if (!empty($a['essays'])) {
            $h .= '<form class="nqr-grade" method="post" action="' . esc_url($post) . '">' . wp_nonce_field('nias_quiz_grade_' . $qid, '_wpnonce', false, false)
                . '<input type="hidden" name="action" value="nias_quiz_grade"><input type="hidden" name="quiz_id" value="' . (int) $qid . '"><input type="hidden" name="user_id" value="' . (int) $uid . '">'
                . '<input type="hidden" name="attempt" value="' . esc_attr($a['id']) . '"><input type="hidden" name="back" value="' . esc_attr($here . '#a-' . $a['id']) . '">'
                . '<div class="nqr-grade-t">' . esc_html__('تصحیح سوال‌های تشریحی', 'nias-course-widget') . '<span class="nqr-muted">' . esc_html(sprintf(__('بخش خودکار: %1$s از %2$s نمره', 'nias-course-widget'), nias_fa_digits($a['earned'] + 0), nias_fa_digits($a['autoTotal'] + 0))) . '</span></div>';
            foreach ($a['essays'] as $e) {
                $qkey = esc_attr($e['qid']);
                $h .= '<div class="nqr-essay"><div class="nqr-essay-q">' . esc_html($e['text']) . '</div>'
                    . '<div class="nqr-essay-a">' . ($e['answer'] !== '' ? nl2br(esc_html($e['answer'])) : '<span class="nqr-muted">' . esc_html__('(بدون پاسخ)', 'nias-course-widget') . '</span>') . '</div>'
                    . (!empty($e['model']) ? '<div class="nqr-essay-m"><b>' . esc_html__('پاسخ مرجع:', 'nias-course-widget') . '</b> ' . nl2br(esc_html($e['model'])) . '</div>' : '')
                    . '<div class="nqr-essay-in"><label>' . esc_html__('نمره', 'nias-course-widget') . ' <input type="number" step="0.25" min="0" max="' . esc_attr($e['max']) . '" name="score[' . $qkey . ']" value="' . esc_attr($e['score'] === null ? '' : $e['score']) . '" required> ' . esc_html(sprintf(__('از %s', 'nias-course-widget'), nias_fa_digits($e['max'] + 0))) . '</label>'
                    . '<textarea name="feedback[' . $qkey . ']" rows="2" placeholder="' . esc_attr__('بازخورد به دانشجو (اختیاری)…', 'nias-course-widget') . '">' . esc_textarea($e['feedback']) . '</textarea></div></div>';
            }
            $h .= '<textarea class="nqr-note" name="note" rows="2" placeholder="' . esc_attr__('نظر کلی دربارهٔ این آزمون (اختیاری)…', 'nias-course-widget') . '">' . esc_textarea($a['note']) . '</textarea>'
                . '<div class="nqr-grade-foot"><button class="nqr-btn pri" type="submit">' . esc_html($a['status'] === 'pending' ? __('ثبت نمره و اعلام به دانشجو', 'nias-course-widget') : __('به‌روزرسانی نمره', 'nias-course-widget')) . '</button>'
                . ($a['gradedAt'] ? '<span class="nqr-muted">' . esc_html(sprintf(__('تصحیح‌شده در %1$s توسط %2$s', 'nias-course-widget'), nias_quiz_dt($a['gradedAt']), ($g = get_userdata($a['gradedBy'])) ? $g->display_name : '—')) . '</span>' : '')
                . '</div></form>';
        }
        $h .= '</details>';
    }
    return $h;
}

function nias_quiz_results_css()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
    .nqr{font-family:inherit;color:#1f2a30;max-width:1240px;min-width:0;width:100%}
    .nias-set-main{min-width:0}
    .nqr-card{max-width:100%}
    .nqr *{box-sizing:border-box}
    .nqr-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:6px 0 16px}
    .nqr-head h2{margin:0;font-size:20px;font-weight:800;flex:1}
    .nqr-back{font-size:13px;color:#1e83f0;text-decoration:none;font-weight:700}
    .nqr-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
    .nqr-kpis.in{margin:14px 0 0}
    .nqr-kpi{background:#fff;border:1px solid #e8ecef;border-radius:14px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
    .nqr-kpi span{font-size:12.5px;color:#7b868a}
    .nqr-kpi b{font-size:20px;font-weight:800}
    .nqr-kpi.ok b{color:#3e9a32}.nqr-kpi.wait b{color:#2563c9}
    .nqr-card{background:#fff;border:1px solid #e8ecef;border-radius:16px;margin-bottom:16px;overflow:hidden}
    .nqr-card>header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #eef1f4}
    .nqr-card>header h3{margin:0;font-size:15px;font-weight:800}
    .nqr-count{background:#eef5ff;color:#2563c9;font-weight:800;font-size:12px;padding:2px 9px;border-radius:99px}
    .nqr-table{width:100%;border-collapse:collapse;font-size:13.5px}
    .nqr-table th{text-align:right;font-weight:700;color:#7b868a;font-size:12.5px;padding:10px 14px;background:#fafbfc;border-bottom:1px solid #eef1f4}
    .nqr-table td{padding:11px 14px;border-bottom:1px solid #f2f4f6;vertical-align:middle}
    .nqr-table tr:last-child td{border-bottom:0}
    .nqr-act{text-align:left;white-space:nowrap}
    .nqr-sub{display:block;font-size:12px;color:#8a979e;margin-top:2px}
    .nqr-muted{color:#8a979e;font-size:12.5px}
    .nqr-score{font-weight:800}
    .nqr-user{display:flex;align-items:center;gap:10px}
    .nqr-user img{border-radius:50%}
    .nqr-user b{display:block;font-size:13.5px}
    .nqr-user span{font-size:12px;color:#8a979e}
    .nqr-st{display:inline-block;font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:#eef1f4;color:#6b777d;white-space:nowrap}
    .nqr-st.ok{background:rgba(72,175,59,.13);color:#3e9a32}.nqr-st.no{background:#fdeaea;color:#d64545}.nqr-st.wait{background:#eef5ff;color:#2563c9}
    .nqr-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 13px;border-radius:10px;border:1px solid #dbe6f3;background:#f5f9ff;color:#1e6fd0!important;font:inherit;font-size:13px;font-weight:700;text-decoration:none!important;cursor:pointer}
    .nqr-btn.pri{background:#1e83f0;border-color:#1e83f0;color:#fff!important}
    .nqr-btn.danger{background:#fff;border-color:#f3c9c9;color:#d64545!important}
    .nqr-empty{padding:26px;text-align:center;color:#7b868a}
    .nqr-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-top:1px solid #eef1f4;direction:ltr}
    .nqr-pager-i{direction:rtl;font-size:13px;color:#6b777d}
    .nqr-btn.off{opacity:.45;cursor:default}
    .nqr-hsearch{margin-right:auto}
    .nqr-hsearch .nqr-search{margin:0;min-width:240px}
    .nqr-count-line{padding:10px 16px;font-size:12.5px;color:#6b777d;border-bottom:1px solid #f2f4f6}
    .nqr-notice{background:#ecf8ea;border:1px solid #cfeccb;color:#2f7d27;border-radius:12px;padding:11px 14px;margin-bottom:14px;font-weight:600}
    .nqr-notice.err{background:#fdeaea;border-color:#f3c9c9;color:#b93b3b}
    .nqr-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}
    .nqr-chip{padding:6px 13px;border-radius:99px;background:#fff;border:1px solid #e1e6e9;color:#4b575d;font-size:13px;font-weight:600;text-decoration:none}
    .nqr-chip.on{background:#1e83f0;border-color:#1e83f0;color:#fff}
    .nqr-search{margin-right:auto;height:36px;border:1px solid #e1e6e9;border-radius:10px;padding:0 12px;font:inherit;min-width:220px}
    .nqr-stu{padding:16px 18px}
    .nqr-stu-id{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .nqr-stu-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
    .nqr-stu-actions form{margin:0}
    .nqr-att>summary{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 18px;cursor:pointer;list-style:none}
    .nqr-att>summary::-webkit-details-marker{display:none}
    .nqr-att[open]>summary{border-bottom:1px solid #eef1f4}
    .nqr-att-score{margin-right:auto;font-weight:800}
    .nqr-review{padding:8px 18px}
    .nqr-rv{display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px dashed #eef1f4}
    .nqr-rv:last-child{border-bottom:0}
    .nqr-rv>div{flex:1}
    .nqr-rv-n{width:24px;height:24px;flex:none;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;background:#eef1f4}
    .nqr-rv.ok .nqr-rv-n{background:rgba(72,175,59,.15);color:#3e9a32}.nqr-rv.no .nqr-rv-n{background:#fdeaea;color:#d64545}.nqr-rv.man .nqr-rv-n{background:#eef5ff;color:#2563c9}
    .nqr-rv-t{font-size:13.5px;font-weight:600}
    .nqr-rv-d{font-size:12.5px;color:#7b868a;margin-top:2px}
    .nqr-rv-s{font-size:12px;font-weight:700;color:#6b777d}
    .nqr-grade{margin:4px 18px 18px;padding:14px;border:1px solid #dbe6f3;background:#f8fbff;border-radius:14px;display:flex;flex-direction:column;gap:12px}
    .nqr-grade-t{display:flex;align-items:center;gap:10px;justify-content:space-between;font-weight:800}
    .nqr-essay{background:#fff;border:1px solid #e6ecf2;border-radius:12px;padding:12px}
    .nqr-essay-q{font-weight:700;font-size:14px;margin-bottom:8px}
    .nqr-essay-a{background:#fafbfc;border-radius:10px;padding:10px 12px;font-size:14px;line-height:1.9;white-space:normal}
    .nqr-essay-m{margin-top:8px;font-size:13px;color:#5b666c;background:#fffbea;border-radius:10px;padding:8px 12px}
    .nqr-essay-in{display:flex;gap:10px;align-items:flex-start;margin-top:10px;flex-wrap:wrap}
    .nqr-essay-in label{display:flex;align-items:center;gap:6px;font-weight:700;font-size:13px;white-space:nowrap}
    .nqr-essay-in input{width:84px;height:36px;border:1px solid #d6dde3;border-radius:9px;padding:0 8px;font:inherit;text-align:center}
    .nqr-essay-in textarea,.nqr-note{flex:1;min-width:220px;border:1px solid #d6dde3;border-radius:10px;padding:8px 10px;font:inherit;font-size:13px}
    .nqr-grade-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    @media (max-width:782px){.nqr-kpis{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.nqr-search{min-width:0;flex:1 1 100%}.nqr-table{display:block;overflow-x:auto}}
    </style>';
}

/* -------------------------------------------------------------------------
 * wp-admin page
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_quiz_results_menu', 21);
function nias_quiz_results_menu()
{
    if (!current_user_can('manage_options') || !function_exists('nias_quiz_enabled') || !nias_quiz_enabled()) {
        return;
    }
    $n = nias_quiz_pending_count(null);
    $bubble = $n ? ' <span class="awaiting-mod">' . nias_fa_digits($n) . '</span>' : '';
    add_submenu_page(
        'nias-course-settings',
        __('نتایج و تصحیح آزمون‌ها', 'nias-course-widget'),
        __('نتایج آزمون‌ها', 'nias-course-widget') . $bubble,
        'manage_options',
        'nias-course-quiz-results',
        'nias_quiz_results_admin_page'
    );
}

function nias_quiz_results_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    echo '<div class="nias-settings-app" dir="rtl">';
    if (function_exists('nias_settings_topbar')) {
        nias_settings_topbar('quizres');
    }
    echo '<div class="nias-set-shell"><div class="nias-set-main">';
    echo nias_quiz_results_render(array( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
        'base'  => admin_url('admin.php?page=nias-course-quiz-results'),
        'scope' => null,
        'front' => false,
    ));
    echo '</div></div></div>';
}

/* -------------------------------------------------------------------------
 * Front-end instructor dashboard
 * ---------------------------------------------------------------------- */

/** Card for the instructor dashboard (grading queue + link to results). */
function nias_quiz_results_dashboard_card($user_id)
{
    if (!function_exists('nias_quiz_enabled') || !nias_quiz_enabled()) {
        return '';
    }
    $scope = nias_quiz_grader_scope($user_id);
    if ($scope !== null && !$scope) {
        return '';
    }
    $pend  = nias_quiz_pending_page($scope, 1, 5);
    $queue = array_slice($pend['items'], 0, 5);
    $base  = add_query_arg('nias_qr', 1, get_permalink());
    $h = nias_quiz_results_css() . '<section class="nias-idash-card nqr-dash" dir="rtl"><div class="nqr-dash-h"><h3>' . esc_html__('صف تصحیح آزمون‌ها', 'nias-course-widget') . '</h3>'
        . '<span class="nqr-count">' . nias_fa_digits(nias_quiz_pending_count($scope)) . '</span></div>';
    if (!$queue) {
        $h .= '<div class="nqr-empty" style="padding:14px">' . esc_html__('پاسخی در انتظار تصحیح نیست.', 'nias-course-widget') . '</div>';
    } else {
        foreach ($queue as $item) {
            $u = get_userdata($item['user']);
            $q = nias_quiz_get($item['quiz']);
            $h .= '<a class="nqr-dash-row" href="' . esc_url(add_query_arg(array('quiz' => $item['quiz'], 'user' => $item['user']), $base) . '#a-' . $item['attempt']['id']) . '">'
                . '<b>' . esc_html($u ? $u->display_name : '—') . '</b><span>' . esc_html(($q ? $q['title'] : '') . ' · ' . nias_quiz_dt($item['attempt']['date'])) . '</span></a>';
        }
    }
    $h .= '<a class="nqr-btn" style="margin-top:10px" href="' . esc_url($base) . '">' . esc_html__('همهٔ نتایج و نمرات', 'nias-course-widget') . '</a>';
    $h .= '<style>.nqr-dash{padding:16px 18px}.nqr-dash-h{display:flex;align-items:center;gap:8px;margin-bottom:8px}.nqr-dash-h h3{margin:0;font-size:15px;font-weight:800;flex:1}'
        . '.nqr-dash-row{display:flex;flex-direction:column;gap:2px;padding:9px 0;border-bottom:1px solid #f1f3f5;text-decoration:none;color:#1f2a30}.nqr-dash-row span{font-size:12px;color:#8a979e}</style>';
    return $h . '</section>';
}

/** The results view inside the instructor dashboard page (?nias_qr=1). */
function nias_quiz_results_frontend($user_id)
{
    $scope = nias_quiz_grader_scope($user_id);
    $back  = remove_query_arg(array('nias_qr', 'quiz', 'user', 'f', 's', 'nqr_notice'));
    return '<div style="max-width:1240px;margin:0 auto;padding:10px 0" dir="rtl"><a class="nqr-back" style="display:inline-block;margin-bottom:10px;color:#1e83f0;font-weight:700;text-decoration:none" href="' . esc_url($back) . '">→ ' . esc_html__('بازگشت به پیشخوان مدرس', 'nias-course-widget') . '</a>'
        . nias_quiz_results_render(array(
            'base'  => add_query_arg('nias_qr', 1, remove_query_arg(array('quiz', 'user', 'f', 's', 'nqr_notice'))),
            'scope' => $scope === null ? null : $scope,
            'front' => true,
        )) . '</div>';
}
