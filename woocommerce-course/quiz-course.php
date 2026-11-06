<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quizzes inside a course: lesson quizzes, score-gated lessons, gradebook.
 *
 *   - A lesson can carry a quiz (lesson_quiz). A lesson with only a quiz shows
 *     the quiz in the player area; a lesson with media shows it in a tab.
 *   - A lesson can be gated (lesson_gate): it opens only after the student
 *     scores at least lesson_gate_min% (or the quiz's pass score) in a given
 *     quiz, or in every quiz of the earlier lessons ("prev"). Gated lessons
 *     are stripped on the server, so their media never reaches the browser.
 *   - The student copy of a quiz never contains the answers (correct options,
 *     accepted text, explanations; matching answers are permuted per user).
 *   - Results: best/last score, attempts and dates per user and quiz
 *     (user meta _nias_quiz_result_{id}); shown in the course page and in a
 *     "نمرات من" tab of the WooCommerce account.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Student copy of a quiz (no answers)
 * ---------------------------------------------------------------------- */

/**
 * Permutation used for the right-hand side of a matching question, stable per
 * user + quiz + question so a page reload (or the server) gets the same order.
 *
 * @return int[] position => original pair index
 */
function nias_quiz_match_perm($quiz_id, $question_id, $count, $user_id)
{
    $keys = array();
    for ($i = 0; $i < $count; $i++) {
        $keys[$i] = hash_hmac('sha256', $quiz_id . '|' . $question_id . '|' . $i . '|' . $user_id, wp_salt('nonce'));
    }
    asort($keys);
    $perm = array_keys($keys);
    // Never hand out the identity order (that would reveal every answer).
    if ($count > 1 && $perm === range(0, $count - 1)) {
        $perm[] = array_shift($perm);
    }
    return $perm;
}

/**
 * The quiz model sent to students: same shape the student component reads,
 * without anything that gives the answers away.
 *
 * @param array $quiz    model from nias_quiz_get()
 * @param int   $user_id
 * @return array
 */
function nias_quiz_public_model($quiz, $user_id)
{
    // Question bank: only this student's drawn questions leave the server.
    $bank = count((array) $quiz['questions']);
    $ids  = nias_quiz_draw_ids($quiz, $user_id);
    if ($ids !== null) {
        $quiz = nias_quiz_apply_draw($quiz, $ids);
    }
    $out = $quiz;
    unset($out['category']);
    $out['questions'] = array();
    foreach ((array) $quiz['questions'] as $q) {
        $pq = array(
            'id'     => $q['id'],
            'type'   => $q['type'],
            'text'   => $q['text'],
            'media'  => isset($q['media']) ? $q['media'] : '',
            'points' => isset($q['points']) ? $q['points'] : 1,
        );
        if (in_array($q['type'], array('choice', 'multi', 'tf'), true)) {
            $pq['options'] = isset($q['options']) ? array_values($q['options']) : array();
        } elseif ($q['type'] === 'match') {
            $pairs = isset($q['pairs']) ? array_values($q['pairs']) : array();
            $perm  = nias_quiz_match_perm((int) $quiz['id'], $q['id'], count($pairs), (int) $user_id);
            $pq['pairs'] = array();
            foreach ($pairs as $i => $p) {
                $pq['pairs'][] = array('left' => $p['left'], 'right' => $pairs[$perm[$i]]['right']);
            }
        }
        $out['questions'][] = $pq;
    }
    $out['qCount']    = count($out['questions']);
    $out['bankCount'] = $bank;
    if ($ids !== null) {
        $out['drawToken'] = nias_quiz_draw_token((int) $quiz['id'], $ids);
    }
    unset($out['drawCount']);
    return $out;
}

/* -------------------------------------------------------------------------
 * Question bank: N random questions per student and per attempt
 *
 *   - drawCount > 0 (and less than the questions): each student gets that
 *     many questions; questions marked «همیشه در آزمون» are always in.
 *   - Logged-in: the draw is kept in user meta _nias_quiz_draw_{id} until the
 *     attempt is sent, so reloading the page does not change the questions
 *     (no picking easy ones). After each attempt a new draw is made.
 *   - Guests: the draw travels with the quiz as a signed token.
 *   - Grading uses only the drawn questions; the score is out of them.
 * ---------------------------------------------------------------------- */

/** Questions per student (0 = all of them). */
function nias_quiz_draw_size($quiz)
{
    $n   = isset($quiz['drawCount']) ? (int) $quiz['drawCount'] : 0;
    $all = count((array) (isset($quiz['questions']) ? $quiz['questions'] : array()));
    if ($n <= 0 || $n >= $all) {
        return 0;
    }
    $fixed = count(array_filter((array) $quiz['questions'], function ($q) { return !empty($q['always']); }));
    return min($all, max($n, $fixed));
}

/** How many questions a student sees. */
function nias_quiz_display_count($quiz)
{
    $n = nias_quiz_draw_size($quiz);
    return $n ? $n : count((array) $quiz['questions']);
}

/** Changes when the bank changes (an old draw is then dropped). */
function nias_quiz_bank_sig($quiz)
{
    $ids = array();
    foreach ((array) $quiz['questions'] as $q) {
        $ids[] = $q['id'] . (!empty($q['always']) ? '!' : '');
    }
    return substr(md5(implode(',', $ids) . '|' . (int) $quiz['drawCount']), 0, 12);
}

/** A fresh random draw (ids, in the bank's order). */
function nias_quiz_new_draw($quiz)
{
    $n      = nias_quiz_draw_size($quiz);
    $fixed  = array();
    $others = array();
    foreach ((array) $quiz['questions'] as $q) {
        if (!empty($q['always'])) {
            $fixed[] = $q['id'];
        } else {
            $others[] = $q['id'];
        }
    }
    // Fisher–Yates with a CSPRNG.
    for ($i = count($others) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $t = $others[$i];
        $others[$i] = $others[$j];
        $others[$j] = $t;
    }
    $pick = array_flip(array_merge($fixed, array_slice($others, 0, max(0, $n - count($fixed)))));
    $ids  = array();
    foreach ((array) $quiz['questions'] as $q) {
        if (isset($pick[$q['id']])) {
            $ids[] = $q['id'];
        }
    }
    return $ids;
}

/** This student's draw (null = the quiz has no bank: all questions). */
function nias_quiz_draw_ids($quiz, $user_id)
{
    if (!nias_quiz_draw_size($quiz)) {
        return null;
    }
    $key = '_nias_quiz_draw_' . (int) $quiz['id'];
    $sig = nias_quiz_bank_sig($quiz);
    if ($user_id) {
        $m = get_user_meta($user_id, $key, true);
        if (is_array($m) && isset($m['sig'], $m['ids']) && $m['sig'] === $sig) {
            return $m['ids'];
        }
        $ids = nias_quiz_new_draw($quiz);
        update_user_meta($user_id, $key, array('sig' => $sig, 'ids' => $ids, 't' => time()));
        return $ids;
    }
    static $guest = array(); // the same draw for the whole page
    if (!isset($guest[(int) $quiz['id']])) {
        $guest[(int) $quiz['id']] = nias_quiz_new_draw($quiz);
    }
    return $guest[(int) $quiz['id']];
}

/** Forget the draw (after an attempt): the next attempt gets new questions. */
function nias_quiz_draw_reset($user_id, $quiz_id)
{
    delete_user_meta($user_id, '_nias_quiz_draw_' . (int) $quiz_id);
}

function nias_quiz_apply_draw($quiz, $ids)
{
    if ($ids === null) {
        return $quiz;
    }
    $want = array_flip($ids);
    $quiz['questions'] = array_values(array_filter((array) $quiz['questions'], function ($q) use ($want) {
        return isset($want[$q['id']]);
    }));
    return $quiz;
}

function nias_quiz_draw_token($quiz_id, $ids)
{
    $p = (int) $quiz_id . '|' . implode(',', $ids) . '|' . (time() + DAY_IN_SECONDS);
    return rtrim(strtr(base64_encode($p), '+/', '-_'), '=') . '.' . substr(hash_hmac('sha256', $p, wp_salt('nonce') . 'nias-draw'), 0, 24);
}

/** Ids from a token sent back with the answers (null = invalid / other quiz / expired). */
function nias_quiz_draw_from_token($quiz, $token)
{
    $parts = explode('.', (string) $token);
    if (count($parts) !== 2) {
        return null;
    }
    $p = base64_decode(strtr($parts[0], '-_', '+/'), true);
    if ($p === false || !hash_equals(substr(hash_hmac('sha256', $p, wp_salt('nonce') . 'nias-draw'), 0, 24), $parts[1])) {
        return null;
    }
    list($qid, $list, $exp) = array_pad(explode('|', $p), 3, '');
    if ((int) $qid !== (int) $quiz['id'] || (int) $exp < time()) {
        return null;
    }
    $ids   = array_filter(explode(',', $list), 'strlen');
    $known = wp_list_pluck((array) $quiz['questions'], 'id');
    return $ids && !array_diff($ids, $known) ? array_values($ids) : null;
}

/**
 * Translate matching answers from the student's (permuted) order back to the
 * original pair indexes before grading.
 */
function nias_quiz_unpermute_answers($quiz, $answers, $user_id)
{
    foreach ((array) $quiz['questions'] as $q) {
        if ($q['type'] !== 'match' || !isset($answers[$q['id']]) || !is_array($answers[$q['id']])) {
            continue;
        }
        $pairs = isset($q['pairs']) ? $q['pairs'] : array();
        $perm  = nias_quiz_match_perm((int) $quiz['id'], $q['id'], count($pairs), (int) $user_id);
        $map   = array();
        foreach ($answers[$q['id']] as $left => $pos) {
            $pos = (int) $pos;
            if (isset($perm[$pos])) {
                $map[(int) $left] = $perm[$pos];
            }
        }
        $answers[$q['id']] = $map;
    }
    return $answers;
}

/* -------------------------------------------------------------------------
 * Results
 * ---------------------------------------------------------------------- */

/*
 * Storage
 *   _nias_quiz_result_{id}   summary (best/last/passed/attempts/pending/bonus…)
 *   _nias_quiz_attempts_{id} the last NIAS_QUIZ_KEEP_ATTEMPTS attempts, each with
 *                            its auto score, per-question review, essay answers
 *                            and — once the instructor grades them — essay
 *                            scores, feedback and the final score.
 * An attempt with essay questions is "pending" until graded: it does not count
 * as passed (and opens no gated lesson) before that.
 */
if (!defined('NIAS_QUIZ_KEEP_ATTEMPTS')) {
    define('NIAS_QUIZ_KEEP_ATTEMPTS', 20);
}

function nias_quiz_attempts_key($quiz_id)
{
    return '_nias_quiz_attempts_' . (int) $quiz_id;
}

/** A user's stored attempts for a quiz (oldest first). */
function nias_quiz_user_attempts($user_id, $quiz_id)
{
    $a = $user_id ? get_user_meta($user_id, nias_quiz_attempts_key($quiz_id), true) : array();
    return is_array($a) ? array_values($a) : array();
}

/**
 * Build an attempt from a graded result (nias_quiz_grade()).
 *
 * @return array
 */
function nias_quiz_make_attempt($result)
{
    $essays = isset($result['essays']) ? $result['essays'] : array();
    $pending = !empty($essays);
    $review = array();
    foreach ((array) $result['review'] as $rv) {
        $review[] = array(
            'text'   => isset($rv['text']) ? $rv['text'] : '',
            'ok'     => !empty($rv['ok']),
            'isAuto' => !empty($rv['isAuto']),
            'detail' => isset($rv['detail']) ? $rv['detail'] : '',
            'score'  => isset($rv['score']) ? $rv['score'] : '',
            'type'   => isset($rv['type']) ? $rv['type'] : '',
        );
    }
    return array(
        'id'         => strtolower(wp_generate_password(10, false, false)),
        'date'       => current_time('mysql'),
        'pct'        => (int) $result['pct'],          // auto part only
        'earned'     => (float) $result['earned'],
        'autoTotal'  => (float) $result['autoTotal'],
        'essayTotal' => (float) (isset($result['essayTotal']) ? $result['essayTotal'] : 0),
        'essays'     => $essays,
        'review'     => $review,
        'status'     => $pending ? 'pending' : 'auto',
        'final'      => $pending ? null : (int) $result['pct'],
        'points'     => $pending ? null : (float) $result['earned'],
        'total'      => (float) $result['autoTotal'] + (float) (isset($result['essayTotal']) ? $result['essayTotal'] : 0),
        'note'       => '',
        'gradedBy'   => 0,
        'gradedAt'   => '',
    );
}

/**
 * Recompute the summary record from the stored attempts (plus what earlier
 * records knew: the best score and "passed" are sticky, attempts is a counter).
 *
 * @param array|mixed $prev     current summary meta
 * @param array       $attempts stored attempts
 * @param int         $pass     quiz pass score
 * @param int         $bump     attempts to add to the counter (1 on submit)
 * @return array
 */
function nias_quiz_compose_record($prev, $attempts, $pass, $bump)
{
    $has = is_array($prev) && isset($prev['pct']);
    $r = array_merge(array(
        'pct' => 0, 'passed' => false, 'earned' => 0, 'autoTotal' => 0, 'date' => '', 'last' => 0,
        'passed_date' => '', 'pending' => 0, 'bonus' => 0,
    ), $has ? $prev : array());
    // Records written before attempts were kept were always graded.
    $r['graded']   = $has ? (isset($prev['graded']) ? (bool) $prev['graded'] : true) : false;
    $r['attempts'] = ($has ? (isset($prev['attempts']) ? (int) $prev['attempts'] : 1) : 0) + (int) $bump;

    $pending = 0;
    $last = null;
    foreach ($attempts as $a) {
        $day = substr((string) $a['date'], 0, 10);
        $r['date'] = $day;
        if ($a['status'] === 'pending') {
            $pending++;
            continue;
        }
        $f = (int) $a['final'];
        if (!$r['graded'] || $f > (int) $r['pct']) {
            $r['pct']       = $f;
            $r['earned']    = $a['points'];
            $r['autoTotal'] = $a['total'];
        }
        $r['graded'] = true;
        $last = $f;
        if ($f >= (int) $pass && empty($r['passed'])) {
            $r['passed']      = true;
            $r['passed_date'] = $day;
        }
    }
    if ($last !== null) {
        $r['last'] = $last;
    }
    $r['pending'] = $pending;
    return $r;
}

/** Save attempts + rebuilt summary for a user and quiz. Returns the summary. */
function nias_quiz_save_user_results($user_id, $quiz_id, $attempts, $bump)
{
    $quiz = nias_quiz_get($quiz_id);
    $pass = $quiz ? (int) $quiz['passScore'] : 0;
    $attempts = array_slice(array_values($attempts), -NIAS_QUIZ_KEEP_ATTEMPTS);
    update_user_meta($user_id, nias_quiz_attempts_key($quiz_id), $attempts);
    $record = nias_quiz_compose_record(get_user_meta($user_id, nias_quiz_result_meta_key($quiz_id), true), $attempts, $pass, $bump);
    update_user_meta($user_id, nias_quiz_result_meta_key($quiz_id), $record);
    if (function_exists('nias_quiz_index_sync')) {
        nias_quiz_index_sync($user_id, $quiz_id);
    }
    return $record;
}

/**
 * Grade the essay answers of one attempt (instructor).
 *
 * @param int    $user_id
 * @param int    $quiz_id
 * @param string $attempt_id
 * @param array  $scores   question id => points
 * @param array  $feedback question id => text
 * @param string $note     overall comment
 * @param int    $grader   user id of the instructor
 * @return array|WP_Error the updated attempt
 */
function nias_quiz_grade_attempt($user_id, $quiz_id, $attempt_id, $scores, $feedback, $note, $grader)
{
    $attempts = nias_quiz_user_attempts($user_id, $quiz_id);
    $found = null;
    foreach ($attempts as $i => $a) {
        if ($a['id'] === $attempt_id) {
            $found = $i;
        }
    }
    if ($found === null || empty($attempts[$found]['essays'])) {
        return new WP_Error('nias_quiz_attempt', __('این شرکت در آزمون پیدا نشد.', 'nias-course-widget'));
    }
    $a = $attempts[$found];
    $sum = 0.0;
    foreach ($a['essays'] as $k => $e) {
        $max = (float) $e['max'];
        $val = isset($scores[$e['qid']]) && $scores[$e['qid']] !== '' ? (float) str_replace(array('٫', '/'), '.', (string) $scores[$e['qid']]) : 0.0;
        $val = max(0, min($max, round($val, 2)));
        $a['essays'][$k]['score']    = $val;
        $a['essays'][$k]['feedback'] = isset($feedback[$e['qid']]) ? sanitize_textarea_field($feedback[$e['qid']]) : '';
        $sum += $val;
    }
    $a['points']   = round((float) $a['earned'] + $sum, 2);
    $a['final']    = $a['total'] > 0 ? (int) round($a['points'] / $a['total'] * 100) : 0;
    $was_pending   = $a['status'] === 'pending';
    $a['status']   = 'graded';
    $a['note']     = sanitize_textarea_field((string) $note);
    $a['gradedBy'] = (int) $grader;
    $a['gradedAt'] = current_time('mysql');
    $attempts[$found] = $a;

    $record = nias_quiz_save_user_results($user_id, $quiz_id, $attempts, 0);
    do_action('nias_quiz_result_saved', $user_id, $quiz_id, $record);
    do_action('nias_quiz_attempt_graded', $user_id, $quiz_id, $a, $was_pending);
    return $a;
}

/**
 * A user's stored result for a quiz, normalised. "best"/"last" are null while
 * no attempt has a final score yet (only pending essays).
 *
 * @return array|null
 */
function nias_quiz_user_record($user_id, $quiz_id)
{
    if (!$user_id) {
        return null;
    }
    $rec = get_user_meta($user_id, nias_quiz_result_meta_key($quiz_id), true);
    if (!is_array($rec) || !isset($rec['pct'])) {
        return null;
    }
    $graded = isset($rec['graded']) ? (bool) $rec['graded'] : true;
    return array(
        'best'       => $graded ? (int) $rec['pct'] : null,
        'last'       => $graded ? (isset($rec['last']) ? (int) $rec['last'] : (int) $rec['pct']) : null,
        'passed'     => !empty($rec['passed']),
        'attempts'   => isset($rec['attempts']) ? (int) $rec['attempts'] : 1,
        'bonus'      => isset($rec['bonus']) ? (int) $rec['bonus'] : 0,
        'date'       => isset($rec['date']) ? (string) $rec['date'] : '',
        'passedDate' => isset($rec['passed_date']) ? (string) $rec['passed_date'] : '',
        'pending'    => isset($rec['pending']) ? (int) $rec['pending'] : 0,
    );
}

/** Attempts left (null = unlimited). Extra attempts granted by the instructor count. */
function nias_quiz_attempts_left($quiz, $record)
{
    $max = isset($quiz['maxAttempts']) ? (int) $quiz['maxAttempts'] : 0;
    if ($max <= 0) {
        return null;
    }
    return max(0, $max + ($record ? (int) $record['bonus'] : 0) - ($record ? (int) $record['attempts'] : 0));
}

/**
 * The instructor's answer to the student's latest graded essays (for the
 * student's own view), or null.
 */
function nias_quiz_latest_feedback($user_id, $quiz_id)
{
    $attempts = nias_quiz_user_attempts($user_id, $quiz_id);
    for ($i = count($attempts) - 1; $i >= 0; $i--) {
        $a = $attempts[$i];
        if ($a['status'] !== 'graded') {
            continue;
        }
        $essays = array();
        foreach ($a['essays'] as $e) {
            $essays[] = array('text' => $e['text'], 'score' => $e['score'], 'max' => $e['max'], 'feedback' => $e['feedback']);
        }
        return array('date' => substr($a['gradedAt'], 0, 10), 'final' => (int) $a['final'], 'note' => $a['note'], 'essays' => $essays);
    }
    return null;
}

/** The per-user part handed to the student component next to the quiz. */
function nias_quiz_client_state($quiz, $user_id)
{
    $rec = nias_quiz_user_record($user_id, (int) $quiz['id']);
    return array(
        'record'       => $rec,
        'attemptsLeft' => nias_quiz_attempts_left($quiz, $rec),
        'feedback'     => $user_id ? nias_quiz_latest_feedback($user_id, (int) $quiz['id']) : null,
    );
}

/* -------------------------------------------------------------------------
 * Email the student when their essays are graded
 * ---------------------------------------------------------------------- */

add_action('nias_quiz_attempt_graded', 'nias_quiz_notify_graded', 10, 4);
function nias_quiz_notify_graded($user_id, $quiz_id, $attempt, $was_pending)
{
    if (!$was_pending || !apply_filters('nias_quiz_graded_email', true, $user_id, $quiz_id)) {
        return;
    }
    $user = get_userdata($user_id);
    $quiz = nias_quiz_get($quiz_id);
    if (!$user || !$user->user_email || !$quiz) {
        return;
    }
    $passed = (int) $attempt['final'] >= (int) $quiz['passScore'];
    $url = function_exists('wc_get_account_endpoint_url') && nias_quiz_grades_enabled() ? wc_get_account_endpoint_url(nias_quiz_grades_slug()) : home_url('/');
    $subject = sprintf(__('نتیجهٔ آزمون «%s» اعلام شد', 'nias-course-widget'), $quiz['title']);
    $body = sprintf(__("%s عزیز،\n\nپاسخ‌های تشریحی شما در آزمون «%s» تصحیح شد.\nنمرهٔ نهایی: %s٪ — %s\n", 'nias-course-widget'),
        $user->display_name, $quiz['title'], nias_fa_digits($attempt['final']), $passed ? __('قبول', 'nias-course-widget') : __('مردود', 'nias-course-widget'));
    if ($attempt['note'] !== '') {
        $body .= "\n" . __('نظر مدرس:', 'nias-course-widget') . "\n" . $attempt['note'] . "\n";
    }
    $body .= "\n" . __('مشاهدهٔ نمرات:', 'nias-course-widget') . ' ' . $url . "\n\n" . get_bloginfo('name');
    wp_mail($user->user_email, $subject, $body);
}

/* -------------------------------------------------------------------------
 * Course wiring
 * ---------------------------------------------------------------------- */

/**
 * Quiz ids placed inside a course's lessons, in lesson order.
 *
 * @return int[]
 */
function nias_quiz_lesson_quiz_ids($product_id, $fresh = false)
{
    static $cache = array();
    $product_id = (int) $product_id;
    if (!$fresh && isset($cache[$product_id])) {
        return $cache[$product_id];
    }
    $ids = array();
    $sections = function_exists('carbon_get_post_meta') ? carbon_get_post_meta($product_id, 'course_sections') : array();
    foreach ((array) $sections as $s) {
        foreach ((array) (isset($s['lessons']) ? $s['lessons'] : array()) as $l) {
            $qid = isset($l['lesson_quiz']) ? (int) $l['lesson_quiz'] : 0;
            if ($qid && !in_array($qid, $ids, true) && get_post_type($qid) === NIAS_QUIZ_CPT) {
                $ids[] = $qid;
            }
        }
    }
    return $cache[$product_id] = $ids;
}

/**
 * Quizzes linked to the course as a whole (quiz setting "course") that are not
 * placed in a lesson — these are shown under the course as its final exam.
 *
 * @return int[]
 */
function nias_quiz_course_level_ids($product_id)
{
    $in_lessons = nias_quiz_lesson_quiz_ids($product_id);
    $linked = array_map('intval', get_posts(array(
        'post_type'      => NIAS_QUIZ_CPT,
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft'),
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_key'       => NIAS_QUIZ_COURSE_META,
        'meta_value'     => (int) $product_id,
    )));
    return array_values(array_diff($linked, $in_lessons));
}

/**
 * Minimum score a gate asks for: its own value, else the quiz pass score.
 */
function nias_quiz_gate_min($quiz, $min)
{
    $min = (int) $min;
    if ($min > 0) {
        return min(100, $min);
    }
    return $quiz ? (int) $quiz['passScore'] : 0;
}

/**
 * Average best score over the course quizzes the user has taken ('' if none).
 */
function nias_quiz_course_average($user_id, $product_id)
{
    $sum = 0;
    $n   = 0;
    foreach (nias_quiz_for_course($product_id) as $qid) {
        $rec = nias_quiz_user_record($user_id, $qid);
        if ($rec && $rec['best'] !== null) {
            $sum += $rec['best'];
            $n++;
        }
    }
    return $n ? (int) round($sum / $n) : '';
}

/* -------------------------------------------------------------------------
 * Gradebook (course page + account)
 * ---------------------------------------------------------------------- */

/**
 * Every quiz of a course with where it lives and the user's result.
 *
 * @param int   $user_id
 * @param int   $product_id
 * @param array $lesson_refs quizId => array(lessonId, lessonTitle, chapterTitle)
 * @return array
 */
function nias_quiz_gradebook($user_id, $product_id, $lesson_refs = array())
{
    $rows = array();
    $ids  = array_merge(nias_quiz_lesson_quiz_ids($product_id), nias_quiz_course_level_ids($product_id));
    foreach ($ids as $qid) {
        $quiz = nias_quiz_get($qid);
        if (!$quiz || empty($quiz['questions'])) {
            continue;
        }
        $rec = nias_quiz_user_record($user_id, $qid);
        $ref = isset($lesson_refs[$qid]) ? $lesson_refs[$qid] : null;
        $rows[] = array(
            'id'           => (int) $qid,
            'title'        => $quiz['title'],
            'passScore'    => (int) $quiz['passScore'],
            'qCount'       => nias_quiz_display_count($quiz),
            'lessonId'     => $ref ? $ref['lessonId'] : '',
            'lessonTitle'  => $ref ? $ref['lessonTitle'] : '',
            'chapterTitle' => $ref ? $ref['chapterTitle'] : '',
            'final'        => !$ref,
            'record'       => $rec,
            'attemptsLeft' => nias_quiz_attempts_left($quiz, $rec),
        );
    }
    return $rows;
}

/* -------------------------------------------------------------------------
 * Course data refresh (after a quiz unlocks lessons)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_mc_data', 'nias_quiz_ajax_course_data');
add_action('wp_ajax_nopriv_nias_mc_data', 'nias_quiz_ajax_course_data');
function nias_quiz_ajax_course_data()
{
    $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$pid || !check_ajax_referer('nias_mc_data_' . $pid, 'nonce', false)) {
        wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'nias-course-widget')), 403);
    }
    ob_start(); // lesson content may run shortcodes that print
    $data = function_exists('nias_modern_course_build_data') ? nias_modern_course_build_data($pid) : null;
    ob_end_clean();
    if (!$data) {
        wp_send_json_error(array('message' => __('دوره یافت نشد.', 'nias-course-widget')), 404);
    }
    wp_send_json_success($data);
}

/* -------------------------------------------------------------------------
 * "نمرات من" — WooCommerce account tab + shortcode
 * ---------------------------------------------------------------------- */

function nias_quiz_grades_slug()
{
    return 'my-grades';
}

function nias_quiz_grades_title()
{
    return __('نمرات من', 'nias-course-widget');
}

function nias_quiz_grades_enabled()
{
    return function_exists('nias_quiz_enabled') && nias_quiz_enabled();
}

add_action('init', 'nias_quiz_grades_endpoint', 20);
function nias_quiz_grades_endpoint()
{
    $on   = nias_quiz_grades_enabled();
    $slug = nias_quiz_grades_slug();
    if ($on) {
        add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        add_action('woocommerce_account_' . $slug . '_endpoint', 'nias_quiz_grades_endpoint_content');
        add_filter('woocommerce_endpoint_' . $slug . '_title', 'nias_quiz_grades_title');
    }
    $sig = $on ? $slug : 'off';
    if (get_option('nias_quiz_grades_rewrite') !== $sig) {
        flush_rewrite_rules(false);
        update_option('nias_quiz_grades_rewrite', $sig);
    }
}

add_filter('woocommerce_get_query_vars', function ($vars) {
    if (nias_quiz_grades_enabled()) {
        $vars[nias_quiz_grades_slug()] = nias_quiz_grades_slug();
    }
    return $vars;
});

add_filter('woocommerce_account_menu_items', function ($items) {
    if (!nias_quiz_grades_enabled()) {
        return $items;
    }
    $slug = nias_quiz_grades_slug();
    $out  = array();
    $anchor = get_option('nias_course_endpoint', 'my-courses');
    $anchor = isset($items[$anchor]) ? $anchor : null;
    foreach ($items as $k => $v) {
        if ($anchor === null && $k === 'customer-logout') {
            $out[$slug] = nias_quiz_grades_title();
        }
        $out[$k] = $v;
        if ($anchor !== null && $k === $anchor) {
            $out[$slug] = nias_quiz_grades_title();
        }
    }
    if (!isset($out[$slug])) {
        $out[$slug] = nias_quiz_grades_title();
    }
    return $out;
}, 25);

function nias_quiz_grades_endpoint_content()
{
    echo nias_quiz_grades_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
}

add_shortcode('nias_my_grades', 'nias_quiz_grades_shortcode');

/** Jalali date (falls back to the stored Y-m-d). */
function nias_quiz_date_label($ymd)
{
    if (!$ymd) {
        return '';
    }
    if (function_exists('nias_cert_jalali')) {
        return nias_fa_digits(nias_cert_jalali($ymd, 'numeric'));
    }
    return nias_fa_digits($ymd);
}

/**
 * Lesson references (quiz id => lesson) for the gradebook, from the stored
 * curriculum (no rendering needed).
 */
function nias_quiz_lesson_refs($product_id)
{
    $refs = array();
    $sections = carbon_get_post_meta($product_id, 'course_sections');
    foreach ((array) $sections as $ci => $s) {
        foreach ((array) (isset($s['lessons']) ? $s['lessons'] : array()) as $li => $l) {
            $qid = isset($l['lesson_quiz']) ? (int) $l['lesson_quiz'] : 0;
            if ($qid && !isset($refs[$qid])) {
                $refs[$qid] = array(
                    'lessonId'     => 'c' . $ci . 'l' . $li,
                    'lessonTitle'  => isset($l['lesson_title']) ? $l['lesson_title'] : '',
                    'chapterTitle' => isset($s['section_title']) ? $s['section_title'] : '',
                );
            }
        }
    }
    return $refs;
}

function nias_quiz_grades_shortcode($atts = array())
{
    if (!nias_quiz_grades_enabled()) {
        return '';
    }
    if (!is_user_logged_in()) {
        return '<div class="nqg-empty">' . esc_html__('برای دیدن نمرات، وارد حساب کاربری شوید.', 'nias-course-widget') . '</div>';
    }
    $uid = get_current_user_id();
    $course_ids = function_exists('nias_course_get_purchased_course_ids') ? nias_course_get_purchased_course_ids($uid) : array();

    // Courses the user took a quiz in, even if not (or no longer) purchased.
    global $wpdb;
    $taken = function_exists('nias_quiz_index_ready') && nias_quiz_index_ready()
        ? $wpdb->get_col($wpdb->prepare('SELECT quiz_id FROM ' . nias_quiz_table() . ' WHERE user_id = %d', $uid))
        : array();
    if ($taken) {
        update_meta_cache('post', array_map('intval', $taken));
    }
    foreach ($taken as $q) {
        $course_ids = array_merge($course_ids, nias_quiz_course_ids((int) $q));
    }
    $course_ids = array_values(array_unique(array_map('intval', $course_ids)));

    $blocks = '';
    foreach ($course_ids as $pid) {
        if (!nias_is_course_post($pid)) {
            continue;
        }
        $rows = nias_quiz_gradebook($uid, $pid, nias_quiz_lesson_refs($pid));
        if (!$rows) {
            continue;
        }
        $done = 0;
        $passed = 0;
        $sum = 0;
        foreach ($rows as $r) {
            if ($r['record'] && $r['record']['best'] !== null) {
                $done++;
                $sum += $r['record']['best'];
                if ($r['record']['passed']) {
                    $passed++;
                }
            }
        }
        $avg = $done ? (int) round($sum / $done) : null;
        $url = function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid);

        $list = '';
        foreach ($rows as $r) {
            $rec = $r['record'];
            if (!$rec) {
                $status = '<span class="nqg-st none">' . esc_html__('شرکت نکرده', 'nias-course-widget') . '</span>';
            } elseif ($rec['passed']) {
                $status = '<span class="nqg-st pass">' . esc_html__('قبول', 'nias-course-widget') . '</span>';
            } elseif ($rec['best'] === null) {
                $status = '<span class="nqg-st wait">' . esc_html__('در انتظار تصحیح', 'nias-course-widget') . '</span>';
            } else {
                $status = '<span class="nqg-st fail">' . esc_html__('مردود', 'nias-course-widget') . '</span>';
            }
            $where = $r['final'] ? __('آزمون پایانی دوره', 'nias-course-widget') : trim($r['chapterTitle'] . ' › ' . $r['lessonTitle'], ' ›');
            $scored = $rec && $rec['best'] !== null;
            $score = $scored ? nias_fa_digits($rec['best']) . '٪' : '—';
            $meta  = array();
            if ($rec) {
                $meta[] = sprintf(__('%s بار شرکت', 'nias-course-widget'), nias_fa_digits($rec['attempts']));
                if ($scored && $rec['last'] !== $rec['best']) {
                    $meta[] = sprintf(__('آخرین: %s٪', 'nias-course-widget'), nias_fa_digits($rec['last']));
                }
                if ($rec['date']) {
                    $meta[] = nias_quiz_date_label($rec['date']);
                }
                if ($rec['pending']) {
                    $meta[] = __('سوال تشریحی در انتظار تصحیح', 'nias-course-widget');
                }
            }
            $meta[] = sprintf(__('قبولی از %s٪', 'nias-course-widget'), nias_fa_digits($r['passScore']));
            $bar = $scored ? (int) $rec['best'] : 0;
            $col = !$scored ? '#c2cacf' : ($rec['passed'] ? '#48af3b' : '#e05656');
            $fb  = $rec ? nias_quiz_latest_feedback($uid, $r['id']) : null;
            $list .= '<div class="nqg-row">'
                . '<div class="nqg-row-main"><div class="nqg-row-t">' . esc_html($r['title']) . '</div>'
                . '<div class="nqg-row-w">' . esc_html($where) . '</div>'
                . '<div class="nqg-row-m">' . esc_html(implode(' · ', $meta)) . '</div>'
                . ($fb ? '<details class="nqg-fb"><summary>' . esc_html(sprintf(__('نظر مدرس (نمرهٔ نهایی %s٪)', 'nias-course-widget'), nias_fa_digits($fb['final']))) . '</summary>'
                    . ($fb['note'] !== '' ? '<p>' . nl2br(esc_html($fb['note'])) . '</p>' : '')
                    . implode('', array_map(function ($e) {
                        return '<p><b>' . esc_html($e['text']) . '</b> — ' . esc_html(nias_fa_digits($e['score']) . ' از ' . nias_fa_digits($e['max'])) . ($e['feedback'] !== '' ? '<br>' . nl2br(esc_html($e['feedback'])) : '') . '</p>';
                    }, $fb['essays'])) . '</details>' : '')
                . '</div>'
                . '<div class="nqg-row-s"><div class="nqg-score" style="color:' . esc_attr($col) . '">' . esc_html($score) . '</div>'
                . '<div class="nqg-bar"><span style="width:' . $bar . '%;background:' . esc_attr($col) . '"></span><i style="right:' . (int) $r['passScore'] . '%"></i></div>' . $status . '</div>'
                . '</div>';
        }

        $blocks .= '<section class="nqg-course">'
            . '<header class="nqg-head"><div><h3>' . esc_html(get_the_title($pid)) . '</h3>'
            . '<div class="nqg-sum">' . sprintf(esc_html__('%1$s از %2$s آزمون قبول', 'nias-course-widget'), nias_fa_digits($passed), nias_fa_digits(count($rows)))
            . ($avg !== null ? ' · ' . sprintf(esc_html__('میانگین نمره %s٪', 'nias-course-widget'), nias_fa_digits($avg)) : '') . '</div></div>'
            . '<a class="nqg-go" href="' . esc_url($url) . '">' . esc_html__('رفتن به دوره', 'nias-course-widget') . '</a></header>'
            . '<div class="nqg-list">' . $list . '</div></section>';
    }

    if ($blocks === '') {
        $blocks = '<div class="nqg-empty">' . esc_html__('هنوز آزمونی در دوره‌های شما وجود ندارد.', 'nias-course-widget') . '</div>';
    }
    return nias_quiz_grades_css() . '<div class="nqg" dir="rtl">' . $blocks . '</div>';
}

function nias_quiz_grades_css()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
    .nqg{display:flex;flex-direction:column;gap:18px;font-family:inherit}
    .nqg-course{background:#fff;border:1px solid #e8ecef;border-radius:16px;overflow:hidden}
    .nqg-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 18px;border-bottom:1px solid #eef1f4;background:#fafbfc}
    .nqg-head h3{margin:0 0 4px;font-size:16px;font-weight:800;color:#1f2a30}
    .nqg-sum{font-size:13px;color:#6b777d}
    .nqg-go{display:inline-flex;align-items:center;height:36px;padding:0 14px;border-radius:10px;background:#1e83f0;color:#fff!important;font-size:13px;font-weight:700;text-decoration:none!important}
    .nqg-row{display:flex;align-items:center;gap:16px;padding:14px 18px;border-bottom:1px solid #f1f3f5}
    .nqg-row:last-child{border-bottom:0}
    .nqg-row-main{flex:1;min-width:0}
    .nqg-row-t{font-size:14.5px;font-weight:700;color:#2b363c}
    .nqg-row-w{font-size:12.5px;color:#1e83f0;margin-top:3px}
    .nqg-row-m{font-size:12px;color:#8a979e;margin-top:4px}
    .nqg-row-s{width:150px;flex:none;display:flex;flex-direction:column;align-items:flex-start;gap:6px}
    .nqg-score{font-size:20px;font-weight:800;line-height:1}
    .nqg-bar{position:relative;width:100%;height:6px;border-radius:99px;background:#eef1f4}
    .nqg-bar span{position:absolute;right:0;top:0;bottom:0;border-radius:99px}
    .nqg-bar i{position:absolute;top:-3px;width:2px;height:12px;background:#2b363c;opacity:.35;border-radius:2px}
    .nqg-st{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px}
    .nqg-st.pass{background:rgba(72,175,59,.13);color:#3e9a32}
    .nqg-st.fail{background:#fdeaea;color:#d64545}
    .nqg-st.none{background:#eef1f4;color:#7b868a}
    .nqg-st.wait{background:#eef5ff;color:#2563c9}
    .nqg-fb{margin-top:8px;font-size:13px;background:#f7faf7;border:1px solid #e2efe2;border-radius:10px;padding:6px 10px}
    .nqg-fb summary{cursor:pointer;font-weight:700;color:#2f7d27}
    .nqg-fb p{margin:6px 0 0;color:#2b363c;line-height:1.8}
    .nqg-empty{padding:28px;text-align:center;color:#7b868a;background:#fafbfc;border:1px dashed #dfe4e8;border-radius:14px}
    @media (max-width:560px){.nqg-row{flex-direction:column;align-items:stretch;gap:10px}.nqg-row-s{width:auto}}
    </style>';
}
