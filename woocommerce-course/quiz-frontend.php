<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quiz builder — student-facing rendering, grading, storage, gating.
 *
 * Companion to woocommerce-course/quiz-builder.php (which owns the data layer and
 * the admin builder). This file provides:
 *   - a shared student component (window.NiasQuizStudent) used by both the
 *     in-admin preview (client-side grading) and the front-end (server grading),
 *   - the [nias_quiz] shortcode that renders a quiz for visitors,
 *   - server-side grading (nias_quiz_grade) + the submit AJAX endpoint that
 *     records each logged-in user's best result,
 *   - certificate gating helpers (a course's certificate requires passing every
 *     quiz linked to it),
 *   - modern-course integration ([nias_modern_course_quiz] + auto-append).
 *
 * Per-user results are stored in user meta keyed by quiz id:
 *   _nias_quiz_result_{quizId} => array(pct, passed, earned, autoTotal, date)
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Grading (server-side, mirrors the client-side grader)
 * ---------------------------------------------------------------------- */

/** Option text for a given option id. */
function nias_quiz_opt_text($q, $oid)
{
    foreach ((array) (isset($q['options']) ? $q['options'] : array()) as $o) {
        if ($o['id'] === $oid) {
            return $o['text'];
        }
    }
    return '—';
}

/**
 * Grade a quiz against a set of answers.
 *
 * @param array $quiz    quiz model (from nias_quiz_get)
 * @param array $answers questionId => answer (array|string|map per type)
 * @return array result { pct, passed, earned, autoTotal, correctCount, wrongCount, essayCount, review[] }
 */
function nias_quiz_grade($quiz, $answers)
{
    $answers = is_array($answers) ? $answers : array();
    $earned = 0;
    $autoTotal = 0;
    $correctCount = 0;
    $wrongCount = 0;
    $essayCount = 0;
    $review = array();

    $questions = isset($quiz['questions']) && is_array($quiz['questions']) ? $quiz['questions'] : array();
    $show_ans  = !empty($quiz['showAnswers']);

    foreach ($questions as $i => $q) {
        $a = isset($answers[$q['id']]) ? $answers[$q['id']] : null;
        $ok = false;
        $detail = '';
        $isAuto = true;
        $partial = 0;
        $points = (float) (isset($q['points']) ? $q['points'] : 0);
        $correct = isset($q['correct']) && is_array($q['correct']) ? $q['correct'] : array();

        if ($q['type'] === 'choice' || $q['type'] === 'tf') {
            $aArr = is_array($a) ? array_values($a) : array();
            $ok = count($aArr) === 1 && in_array($aArr[0], $correct, true);
            $detail = 'پاسخ شما: ' . ($aArr && isset($aArr[0]) ? nias_quiz_opt_text($q, $aArr[0]) : '—');
            if ($show_ans) {
                $detail .= ' | صحیح: ' . (isset($correct[0]) ? nias_quiz_opt_text($q, $correct[0]) : '—');
            }
        } elseif ($q['type'] === 'multi') {
            $aArr = is_array($a) ? array_map('strval', $a) : array();
            sort($aArr);
            $cArr = array_map('strval', $correct);
            sort($cArr);
            $ok = !empty($aArr) && $aArr === $cArr;
            if ($show_ans) {
                $names = array();
                foreach ($correct as $c) {
                    $names[] = nias_quiz_opt_text($q, $c);
                }
                $detail = 'صحیح: ' . implode('، ', $names);
            } else {
                $detail = '';
            }
        } elseif ($q['type'] === 'fill') {
            $accepts = array_filter(array_map(function ($s) {
                return trim(mb_strtolower($s));
            }, preg_split('/[،,]/u', (string) (isset($q['answerText']) ? $q['answerText'] : ''))));
            $ua = trim(mb_strtolower((string) (is_array($a) ? '' : $a)));
            $ok = $ua !== '' && in_array($ua, $accepts, true);
            $detail = 'پاسخ شما: ' . ($a !== null && $a !== '' ? (is_array($a) ? '' : $a) : '—');
            if ($show_ans) {
                $detail .= ' | پذیرفته: ' . (isset($q['answerText']) && $q['answerText'] !== '' ? $q['answerText'] : '—');
            }
        } elseif ($q['type'] === 'match') {
            $map = is_array($a) ? $a : array();
            $pairs = isset($q['pairs']) && is_array($q['pairs']) ? $q['pairs'] : array();
            $c = 0;
            foreach ($pairs as $idx => $p) {
                if (isset($map[$idx]) && (int) $map[$idx] === (int) $idx) {
                    $c++;
                }
            }
            $partial = count($pairs) ? $c / count($pairs) : 0;
            $ok = $partial === (float) 1;
            $detail = 'تطبیق درست: ' . nias_fa_digits($c) . ' از ' . nias_fa_digits(count($pairs));
        } elseif ($q['type'] === 'essay') {
            $isAuto = false;
            $essayCount++;
            $detail = 'پاسخ تشریحی ثبت شد و در انتظار تصحیح مدرس است.';
        }

        if ($isAuto) {
            $autoTotal += $points;
            $got = $q['type'] === 'match' ? round($points * $partial * 10) / 10 : ($ok ? $points : 0);
            $earned += $got;
            if ($ok) {
                $correctCount++;
            } else {
                $wrongCount++;
            }
            $score = nias_fa_digits($got) . '/' . nias_fa_digits($points);
        } else {
            $score = 'دستی';
        }

        $review[] = array(
            'num'         => nias_fa_digits($i + 1),
            'text'        => $q['text'],
            'ok'          => $ok,
            'isAuto'      => $isAuto,
            'detail'      => $detail,
            'score'       => $score,
            'explanation' => !empty($quiz['showExp']) ? (isset($q['explanation']) ? $q['explanation'] : '') : '',
            'type'        => $q['type'],
            'partial'     => $partial,
        );
    }

    $pct = $autoTotal ? (int) round($earned / $autoTotal * 100) : 0;
    $passed = $pct >= (int) (isset($quiz['passScore']) ? $quiz['passScore'] : 0);

    return array(
        'pct'          => $pct,
        'passed'       => $passed,
        'earned'       => $earned,
        'autoTotal'    => $autoTotal,
        'correctCount' => $correctCount,
        'wrongCount'   => $wrongCount,
        'essayCount'   => $essayCount,
        'review'       => $review,
    );
}

/* -------------------------------------------------------------------------
 * Per-user result storage + gating
 * ---------------------------------------------------------------------- */

/** User-meta key for a quiz's best result. */
function nias_quiz_result_meta_key($quiz_id)
{
    return '_nias_quiz_result_' . (int) $quiz_id;
}

/** Store a user's result, keeping the best (highest pct / first pass). */
function nias_quiz_store_result($user_id, $quiz_id, $result)
{
    $key = nias_quiz_result_meta_key($quiz_id);
    $prev = get_user_meta($user_id, $key, true);
    $record = array(
        'pct'       => (int) $result['pct'],
        'passed'    => !empty($result['passed']),
        'earned'    => $result['earned'],
        'autoTotal' => $result['autoTotal'],
        'date'      => current_time('Y-m-d'),
    );
    if (is_array($prev)) {
        // Keep passed status sticky and the best percentage.
        if (!empty($prev['passed'])) {
            $record['passed'] = true;
        }
        if ((int) $prev['pct'] > $record['pct']) {
            $record['pct'] = (int) $prev['pct'];
        }
    }
    update_user_meta($user_id, $key, $record);
}

/** Has a user passed a given quiz? */
function nias_quiz_user_passed($user_id, $quiz_id)
{
    $rec = get_user_meta($user_id, nias_quiz_result_meta_key($quiz_id), true);
    return is_array($rec) && !empty($rec['passed']);
}

/**
 * Quiz post ids linked to a course (product).
 *
 * @param int $product_id
 * @return int[]
 */
function nias_quiz_for_course($product_id)
{
    $product_id = (int) $product_id;
    if (!$product_id || !function_exists('nias_quiz_enabled') || !nias_quiz_enabled()) {
        return array();
    }
    return array_map('intval', get_posts(array(
        'post_type'      => NIAS_QUIZ_CPT,
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft'),
        'fields'         => 'ids',
        'meta_key'       => NIAS_QUIZ_COURSE_META,
        'meta_value'     => $product_id,
    )));
}

/**
 * Certificate gate: has the user passed every quiz linked to this course?
 * Returns true when the feature is off or no quiz is linked.
 *
 * @param int $user_id
 * @param int $product_id
 * @return bool
 */
function nias_quiz_course_gate_passed($user_id, $product_id)
{
    $quizzes = nias_quiz_for_course($product_id);
    if (empty($quizzes)) {
        return true;
    }
    foreach ($quizzes as $quiz_id) {
        if (!nias_quiz_user_passed($user_id, $quiz_id)) {
            return false;
        }
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Submit AJAX (server-side grading + storage)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_quiz_submit', 'nias_quiz_ajax_submit');
add_action('wp_ajax_nopriv_nias_quiz_submit', 'nias_quiz_ajax_submit');
function nias_quiz_ajax_submit()
{
    if (!check_ajax_referer('nias_quiz_take', 'nonce', false)) {
        wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'nias-course-widget')));
    }
    $quiz_id = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0;
    $quiz = function_exists('nias_quiz_get') ? nias_quiz_get($quiz_id) : null;
    if (!$quiz) {
        wp_send_json_error(array('message' => __('آزمون یافت نشد.', 'nias-course-widget')));
    }

    $answers = isset($_POST['answers']) ? json_decode(wp_unslash($_POST['answers']), true) : array();
    $answers = is_array($answers) ? $answers : array();

    $result = nias_quiz_grade($quiz, $answers);

    if (is_user_logged_in()) {
        nias_quiz_store_result(get_current_user_id(), $quiz_id, $result);
        $result['stored'] = true;
    } else {
        $result['stored'] = false;
    }

    wp_send_json_success(array('result' => $result));
}

/* -------------------------------------------------------------------------
 * Front-end shortcode
 * ---------------------------------------------------------------------- */

add_shortcode('nias_quiz', 'nias_quiz_shortcode');
function nias_quiz_shortcode($atts)
{
    if (!function_exists('nias_quiz_enabled') || !nias_quiz_enabled()) {
        return '';
    }
    $atts = shortcode_atts(array('id' => 0), $atts, 'nias_quiz');
    $quiz_id = (int) $atts['id'];
    $quiz = $quiz_id ? nias_quiz_get($quiz_id) : null;
    if (!$quiz) {
        return '';
    }
    return nias_quiz_render_student($quiz);
}

/**
 * Render a quiz in the student view (server-graded).
 *
 * @param array $quiz
 * @return string
 */
function nias_quiz_render_student($quiz)
{
    if (empty($quiz['questions'])) {
        return '<div class="nqs" dir="rtl"><div class="nqs-note">' . esc_html__('این آزمون هنوز سوالی ندارد.', 'nias-course-widget') . '</div></div>';
    }

    static $seq = 0;
    $seq++;
    $uid = 'nqs-' . (int) $quiz['id'] . '-' . $seq;

    $config = array(
        'mode'      => 'server',
        'ajaxUrl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('nias_quiz_take'),
        'loggedIn'  => is_user_logged_in(),
    );

    ob_start();
    nias_quiz_student_assets();
    ?>
    <div class="nqs" id="<?php echo esc_attr($uid); ?>" dir="rtl"></div>
    <script>
        (function () {
            function boot() {
                if (!window.NiasQuizStudent) { return; }
                window.NiasQuizStudent.mount(
                    document.getElementById(<?php echo wp_json_encode($uid); ?>),
                    <?php echo wp_json_encode($quiz); ?>,
                    <?php echo wp_json_encode($config); ?>
                );
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
            else { boot(); }
        })();
    </script>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Modern course integration
 * ---------------------------------------------------------------------- */

/** Render every quiz linked to a product as student views (used inside modern course). */
function nias_quiz_render_for_course($product_id)
{
    $ids = nias_quiz_for_course($product_id);
    if (empty($ids)) {
        return '';
    }
    $out = '';
    foreach ($ids as $qid) {
        $quiz = nias_quiz_get($qid);
        if ($quiz && !empty($quiz['questions'])) {
            $out .= nias_quiz_render_student($quiz);
        }
    }
    if ($out === '') {
        return '';
    }
    return '<div class="nqs-course-wrap" dir="rtl"><div class="nqs-course-head">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'
        . '<span>' . esc_html__('آزمون دوره', 'nias-course-widget') . '</span></div>' . $out . '</div>';
}

/** Manual placement inside a split modern-course layout. */
add_shortcode('nias_modern_course_quiz', function ($atts) {
    if (!function_exists('nias_modern_course_resolve_pid')) {
        return '';
    }
    $pid = nias_modern_course_resolve_pid($atts);
    return $pid ? nias_quiz_render_for_course($pid) : '';
});

/**
 * Auto-append the linked quiz after the full modern-course shortcode output.
 */
add_filter('do_shortcode_tag', 'nias_quiz_append_to_modern_course', 10, 4);
function nias_quiz_append_to_modern_course($output, $tag, $attr, $m)
{
    if ($tag !== 'nias_modern_course' || $output === '') {
        return $output;
    }
    if (!function_exists('nias_modern_course_resolve_pid')) {
        return $output;
    }
    $pid = nias_modern_course_resolve_pid($attr);
    if (!$pid) {
        return $output;
    }
    return $output . nias_quiz_render_for_course($pid);
}

/* -------------------------------------------------------------------------
 * Shared student component (CSS + JS), printed once per request.
 * ---------------------------------------------------------------------- */

function nias_quiz_student_assets()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    nias_quiz_student_styles();
    nias_quiz_student_script();
}

function nias_quiz_student_styles()
{
    ?>
    <style>
    .nqs,.nqs *{box-sizing:border-box}
    .nqs{--acc:#1e83f0;font-family:inherit;color:#1f2a30;max-width:780px;margin:0 auto;line-height:1.6}
    .nqs .nqs-in{width:100%;border:1.5px solid #e1e6e9;border-radius:10px;padding:11px 13px;font-size:14px;color:#2b363c;background:#fff;outline:none;font-family:inherit}
    .nqs .nqs-in:focus{border-color:var(--acc)}
    .nqs textarea.nqs-in{resize:vertical}
    .nqs-note{background:#fff;border:1px solid #e9ecef;border-radius:14px;padding:20px;text-align:center;color:#7b868a}
    .nqs-card{background:#fff;border:1px solid #e9ecef;border-radius:16px}
    .nqs-head{padding:16px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px}
    .nqs-head-t{font-size:16px;font-weight:700}
    .nqs-head-s{font-size:12.5px;color:#7b868a;margin-top:3px}
    .nqs-timer{display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:700;padding:8px 14px;border-radius:10px;background:#f1f4f6;color:#3a464c}
    .nqs-timer.low{background:#fff0f0;color:#e05656}
    .nqs-prog{height:6px;background:#e3e8ec;border-radius:99px;overflow:hidden;margin-bottom:16px}
    .nqs-prog > span{display:block;height:100%;background:var(--acc);border-radius:99px;transition:width .3s}
    .nqs-q{padding:24px;margin-bottom:16px}
    .nqs-q-top{display:flex;align-items:flex-start;gap:11px;margin-bottom:16px}
    .nqs-q-num{width:30px;height:30px;flex:none;border-radius:8px;background:var(--acc);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px}
    .nqs-q-text{font-size:16px;font-weight:700;line-height:1.8}
    .nqs-q-meta{font-size:12px;color:#9aa4a9;margin-top:4px}
    .nqs-q-media{max-width:100%;border-radius:12px;margin-bottom:16px;border:1px solid #eef1f4}
    .nqs-opts{display:flex;flex-direction:column;gap:10px}
    .nqs-opt{width:100%;display:flex;align-items:center;gap:12px;background:#fff;border:1.5px solid #e1e6e9;border-radius:12px;padding:14px 16px;cursor:pointer;font-family:inherit;font-size:14.5px;color:#2b363c;text-align:right}
    .nqs-opt.on{background:rgba(30,131,240,.06);border-color:var(--acc)}
    .nqs-opt-box{width:22px;height:22px;flex:none;border:2px solid #cdd5da;background:#fff;color:#fff;display:flex;align-items:center;justify-content:center}
    .nqs-opt-box.radio{border-radius:50%}
    .nqs-opt-box.box{border-radius:6px}
    .nqs-opt.on .nqs-opt-box{border-color:var(--acc);background:var(--acc)}
    .nqs-tf{display:flex;gap:12px}
    .nqs-tfbtn{flex:1;background:#fff;border:1.5px solid #e1e6e9;color:#3a464c;font-size:15px;font-weight:700;padding:16px;border-radius:12px;cursor:pointer;font-family:inherit}
    .nqs-tfbtn.on{background:var(--acc);border-color:var(--acc);color:#fff}
    .nqs-essay-info{font-size:12px;color:#9aa4a9;margin-top:8px;display:flex;align-items:center;gap:6px}
    .nqs-match-rows{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
    .nqs-match-row{display:flex;align-items:center;gap:10px}
    .nqs-match-l{flex:1;background:#f8fafb;border:1px solid #eef1f4;border-radius:10px;padding:12px 14px;font-size:14px;font-weight:600}
    .nqs-slot{flex:1;border:1.5px dashed #cdd5da;background:#fafbfc;color:#9aa4a9;border-radius:10px;padding:12px 14px;font-size:14px;cursor:default;text-align:center;min-height:46px;display:flex;align-items:center;justify-content:center}
    .nqs-slot.on{border-color:var(--acc);background:rgba(30,131,240,.06);color:var(--acc);font-weight:700;cursor:pointer}
    .nqs-pool{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px;border-top:1px dashed #e1e6e9}
    .nqs-chip{background:#fff;border:1.5px solid var(--acc);color:var(--acc);border-radius:9px;padding:9px 14px;font-size:13.5px;font-weight:600;cursor:grab}
    .nqs-chip.sel{background:var(--acc);color:#fff}
    .nqs-nav{display:flex;align-items:center;gap:10px}
    .nqs-btn{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:14px;font-weight:700;padding:11px 20px;border-radius:10px;cursor:pointer;border:0}
    .nqs-btn.prev{background:#fff;border:1px solid #e1e6e9;color:#3a464c;font-weight:600}
    .nqs-btn.prev.off{opacity:.45;pointer-events:none}
    .nqs-btn.next{background:var(--acc);color:#fff}
    .nqs-btn.submit{background:#48af3b;color:#fff}
    .nqs-palette{flex:1;display:flex;justify-content:center;gap:6px;flex-wrap:wrap}
    .nqs-pbtn{width:32px;height:32px;border-radius:8px;border:1.5px solid #e1e6e9;background:#fff;color:#9aa4a9;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit}
    .nqs-pbtn.cur{border-color:var(--acc);background:var(--acc);color:#fff}
    .nqs-pbtn.ans{border-color:#9bd58f;background:rgba(72,175,59,.12);color:#3e9a32}

    /* results */
    .nqs-banner{display:flex;align-items:center;gap:24px;flex-wrap:wrap;border-radius:18px;padding:26px 28px;margin-bottom:18px;color:#fff}
    .nqs-banner.pass{background:linear-gradient(135deg,#2fa84a,#1f8f3d)}
    .nqs-banner.fail{background:linear-gradient(135deg,#e0683f,#cf4d4d)}
    .nqs-ring{position:relative;width:120px;height:120px;flex:none}
    .nqs-ring-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff}
    .nqs-ring-pct{font-size:30px;font-weight:800;line-height:1}
    .nqs-ring-l{font-size:11px;opacity:.85}
    .nqs-res-t{font-size:22px;font-weight:800}
    .nqs-res-d{font-size:14px;opacity:.92;margin-top:5px;line-height:1.8}
    .nqs-res-counts{display:flex;gap:18px;margin-top:12px;font-size:13px;flex-wrap:wrap}
    .nqs-review{background:#fff;border:1px solid #e9ecef;border-radius:16px;padding:8px 6px;margin-bottom:16px}
    .nqs-rv{padding:16px 18px;border-bottom:1px solid #f4f6f7;display:flex;align-items:flex-start;gap:10px}
    .nqs-rv:last-child{border-bottom:0}
    .nqs-rv-mark{width:26px;height:26px;flex:none;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800}
    .nqs-rv-t{font-size:14.5px;font-weight:700;line-height:1.7}
    .nqs-rv-d{font-size:13px;color:#5b666c;margin-top:7px;line-height:1.8}
    .nqs-rv-exp{margin-top:9px;background:#f0f7ff;border-radius:9px;padding:10px 12px;font-size:12.5px;color:#2b5d8f;line-height:1.8}
    .nqs-rv-score{font-size:12.5px;color:#9aa4a9;white-space:nowrap}
    .nqs-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
    .nqs-actions .retake{background:var(--acc);color:#fff}
    .nqs-actions .backc{background:#f1f4f6;color:#3a464c;font-weight:600}

    .nqs-course-wrap{max-width:1180px;margin:22px auto 0;background:#fff;border:1px solid #e9ecef;border-radius:18px;padding:18px 18px 24px}
    .nqs-course-head{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:700;margin-bottom:8px;padding:4px 6px}
    </style>
    <?php
}

function nias_quiz_student_script()
{
    ?>
    <script>
    (function () {
        if (window.NiasQuizStudent) { return; }

        function fa(n){ return String(n).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];}); }
        function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }
        var ICON = {
            clock:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            check:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>',
            info:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5v.5"/></svg>',
            link:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6M10 8H8a4 4 0 0 0 0 8h2M14 8h2a4 4 0 0 1 0 8h-2"/></svg>',
            prev:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>',
            next:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
            retry:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/></svg>'
        };
        function shuffle(a){ var r=a.slice(); for(var i=r.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)); var t=r[i]; r[i]=r[j]; r[j]=t; } return r; }
        function optText(q,oid){ var o=(q.options||[]).find(function(x){return x.id===oid;}); return o?o.text:'—'; }

        /* ---- client-side grader (mirrors nias_quiz_grade) ---- */
        function gradeLocal(quiz, ans){
            var earned=0, autoTotal=0, correctCount=0, wrongCount=0, essayCount=0, review=[];
            var showAns = quiz.showAnswers!==false;
            quiz.questions.forEach(function(q,i){
                var a=ans[q.id], ok=false, detail='', isAuto=true, partial=0;
                var correct=q.correct||[];
                if (q.type==='choice'||q.type==='tf'){ ok=a&&a.length===1&&correct.indexOf(a[0])>=0; detail='پاسخ شما: '+(a&&a[0]?optText(q,a[0]):'—')+(showAns?' | صحیح: '+optText(q,correct[0]):''); }
                else if (q.type==='multi'){ var sa=(a||[]).slice().sort().join(','); var sc=correct.slice().sort().join(','); ok=sa===sc&&sa!==''; detail=showAns?('صحیح: '+correct.map(function(c){return optText(q,c);}).join('، ')):''; }
                else if (q.type==='fill'){ var accepts=(q.answerText||'').split(/[،,]/).map(function(s){return s.trim().toLowerCase();}).filter(Boolean); var ua=((a||'')+'').trim().toLowerCase(); ok=ua!==''&&accepts.indexOf(ua)>=0; detail='پاسخ شما: '+((a||'—'))+(showAns?' | پذیرفته: '+(q.answerText||'—'):''); }
                else if (q.type==='match'){ var map=a||{}; var c=0; (q.pairs||[]).forEach(function(p,idx){ if(parseInt(map[idx],10)===idx) c++; }); partial=q.pairs.length?c/q.pairs.length:0; ok=partial===1; detail='تطبیق درست: '+fa(c)+' از '+fa(q.pairs.length); }
                else if (q.type==='essay'){ isAuto=false; essayCount++; detail='پاسخ تشریحی ثبت شد و در انتظار تصحیح مدرس است.'; }
                if (isAuto){ autoTotal+=(+q.points||0); var got=q.type==='match'?Math.round((+q.points||0)*partial*10)/10:(ok?(+q.points||0):0); earned+=got; if(ok)correctCount++; else wrongCount++; var score=fa(got)+'/'+fa(+q.points||0); review.push({num:fa(i+1),text:q.text,ok:ok,isAuto:isAuto,detail:detail,score:score,explanation:quiz.showExp?(q.explanation||''):'',type:q.type,partial:partial}); }
                else { review.push({num:fa(i+1),text:q.text,ok:ok,isAuto:isAuto,detail:detail,score:'دستی',explanation:quiz.showExp?(q.explanation||''):'',type:q.type,partial:partial}); }
            });
            var pct=autoTotal?Math.round(earned/autoTotal*100):0;
            return { pct:pct, passed:pct>=(quiz.passScore||0), earned:earned, autoTotal:autoTotal, correctCount:correctCount, wrongCount:wrongCount, essayCount:essayCount, review:review };
        }

        function ST(root, quiz, opts){
            this.root=root; this.quiz=quiz; this.opts=opts||{}; this.timer=null;
            this.state={ idx:0, answers:{}, phase:'taking', timeLeft:0, result:null, drag:null };
        }
        ST.prototype.start=function(){
            var quiz=this.quiz;
            var qs=quiz.questions.slice();
            if (quiz.shuffleQ) qs=shuffle(qs);
            qs=qs.map(function(q){ if(quiz.shuffleOpt&&(q.type==='choice'||q.type==='multi')) return Object.assign({},q,{options:shuffle(q.options)}); return q; });
            this.qs=qs;
            this.state={ idx:0, answers:{}, phase:'taking', timeLeft:0, result:null, drag:null };
            var tl=0; if(quiz.timeMode==='total') tl=quiz.timeValue*60; else if(quiz.timeMode==='perQuestion') tl=quiz.timeValue;
            this.state.timeLeft=tl;
            this.render();
            if (quiz.timeMode!=='none') this.runTimer();
        };
        ST.prototype.fmt=function(s){ var m=Math.floor(s/60), sec=s%60; return fa(String(m<0?0:m).padStart(2,'0'))+':'+fa(String(sec<0?0:sec).padStart(2,'0')); };
        ST.prototype.runTimer=function(){
            var self=this; if(this.timer) clearInterval(this.timer);
            this.timer=setInterval(function(){
                if (self.state.phase!=='taking'){ clearInterval(self.timer); return; }
                var q=self.quiz;
                var t=self.state.timeLeft-1;
                if (t<=0){
                    if (q.timeMode==='perQuestion'){
                        if (self.state.idx<self.qs.length-1){ self.state.timeLeft=q.timeValue; self.state.idx++; self.render(); return; }
                        clearInterval(self.timer); self.submit(); return;
                    }
                    clearInterval(self.timer); self.submit(); return;
                }
                self.state.timeLeft=t;
                var el=self.root.querySelector('.nqs-timer');
                if (el){ el.innerHTML=ICON.clock+' '+self.fmt(t); el.classList.toggle('low', t<=10); }
            },1000);
        };
        ST.prototype.isAnswered=function(q,a){ if(a==null) return false; if(q.type==='choice'||q.type==='multi'||q.type==='tf') return a.length>0; if(q.type==='match') return Object.keys(a).length>0; return (''+a).trim().length>0; };

        ST.prototype.setAnswer=function(qid,val){ this.state.answers[qid]=val; };
        ST.prototype.pickChoice=function(q,oid){
            if (q.type==='multi'){ var cur=this.state.answers[q.id]||[]; var nv=cur.indexOf(oid)>=0?cur.filter(function(x){return x!==oid;}):cur.concat([oid]); this.setAnswer(q.id,nv); }
            else { this.setAnswer(q.id,[oid]); }
            this.render();
        };
        ST.prototype.matchAssign=function(q,leftIdx,poolIdx){
            var cur=Object.assign({},this.state.answers[q.id]||{});
            Object.keys(cur).forEach(function(k){ if(parseInt(cur[k],10)===poolIdx) delete cur[k]; });
            cur[leftIdx]=poolIdx; this.setAnswer(q.id,cur); this.state.drag=null; this.render();
        };
        ST.prototype.matchClear=function(q,leftIdx){ var cur=Object.assign({},this.state.answers[q.id]||{}); delete cur[leftIdx]; this.setAnswer(q.id,cur); this.render(); };

        ST.prototype.goto=function(i){ this.state.idx=i; if(this.quiz.timeMode==='perQuestion'){ this.state.timeLeft=this.quiz.timeValue; } this.render(); };

        ST.prototype.submit=function(){
            if (this.timer){ clearInterval(this.timer); this.timer=null; }
            var self=this;
            if (this.opts.mode==='server'){
                var body='action=nias_quiz_submit&nonce='+encodeURIComponent(this.opts.nonce)+'&quiz_id='+encodeURIComponent(this.quiz.id)+'&answers='+encodeURIComponent(JSON.stringify(this.state.answers));
                fetch(this.opts.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
                    .then(function(r){return r.json();})
                    .then(function(res){ self.state.phase='done'; self.state.result=(res&&res.success&&res.data)?res.data.result:gradeLocal(self.quiz,self.state.answers); self.render(); })
                    .catch(function(){ self.state.phase='done'; self.state.result=gradeLocal(self.quiz,self.state.answers); self.render(); });
            } else {
                this.state.phase='done'; this.state.result=gradeLocal(this.quiz,this.state.answers); this.render();
            }
        };

        ST.prototype.render=function(){
            if (this.state.phase==='done') this.renderDone();
            else this.renderTaking();
            this.bind();
        };

        ST.prototype.renderTaking=function(){
            var quiz=this.quiz, qs=this.qs, idx=this.state.idx, q=qs[idx], ans=this.state.answers[q.id];
            var showTimer=quiz.timeMode!=='none';
            var low=this.state.timeLeft<=10&&showTimer;
            var progress=Math.round((idx+1)/qs.length*100);

            var body='';
            if (q.type==='choice'||q.type==='multi'){
                var multi=q.type==='multi', arr=ans||[];
                body='<div class="nqs-opts">'+(q.options||[]).map(function(o){ var pk=arr.indexOf(o.id)>=0;
                    return '<button class="nqs-opt '+(pk?'on':'')+'" data-act="pick" data-id="'+o.id+'"><span class="nqs-opt-box '+(multi?'box':'radio')+'">'+(pk?ICON.check:'')+'</span><span style="flex:1;text-align:right">'+esc(o.text)+'</span></button>';
                }).join('')+'</div>';
            } else if (q.type==='tf'){
                var arr2=ans||[];
                body='<div class="nqs-tf">'+(q.options||[]).map(function(o){ var pk=arr2.indexOf(o.id)>=0; return '<button class="nqs-tfbtn '+(pk?'on':'')+'" data-act="pick" data-id="'+o.id+'">'+esc(o.text)+'</button>'; }).join('')+'</div>';
            } else if (q.type==='fill'){
                body='<input class="nqs-in" data-act="text" value="'+esc(ans||'')+'" placeholder="پاسخ خود را تایپ کنید…">';
            } else if (q.type==='essay'){
                body='<textarea class="nqs-in" rows="6" data-act="text" placeholder="پاسخ تشریحی خود را بنویسید…">'+esc(ans||'')+'</textarea>'+
                    '<div class="nqs-essay-info">'+ICON.info+' این سوال پس از آزمون توسط مدرس تصحیح می‌شود.</div>';
            } else if (q.type==='match'){
                var map=ans||{}, used=Object.keys(map).map(function(k){return parseInt(map[k],10);});
                var rows=(q.pairs||[]).map(function(p,i){ var assigned=map[i]; var has=assigned!=null;
                    return '<div class="nqs-match-row"><span class="nqs-match-l">'+esc(p.left)+'</span><span style="color:#a9b3b8;flex:none">'+ICON.link+'</span>'+
                        '<div class="nqs-slot '+(has?'on':'')+'" data-act="slot" data-i="'+i+'">'+(has?esc(q.pairs[assigned].right):'اینجا رها کنید')+'</div></div>';
                }).join('');
                var pool=(q.pairs||[]).map(function(p,i){return i;}).filter(function(i){return used.indexOf(i)<0;});
                var chips=pool.map(function(i){ var sel=this.state.drag===i; return '<span class="nqs-chip '+(sel?'sel':'')+'" draggable="true" data-act="chip" data-i="'+i+'">'+esc(q.pairs[i].right)+'</span>'; },this).join('');
                if (!pool.length) chips='<span style="font-size:12.5px;color:#9aa4a9;padding:9px 0">همه موارد تطبیق داده شد ✓</span>';
                body='<div class="nqs-match-rows">'+rows+'</div><div class="nqs-pool">'+chips+'</div>';
            }

            var palette=qs.map(function(qq,i){ var a=this.state.answers[qq.id]; var answered=this.isAnswered(qq,a); var cur=i===idx;
                return '<button class="nqs-pbtn '+(cur?'cur':(answered?'ans':''))+'" data-act="goto" data-i="'+i+'">'+fa(i+1)+'</button>';
            },this).join('');

            var isLast=idx===qs.length-1;
            this.root.innerHTML=
                '<div class="nqs-card nqs-head"><div style="flex:1;min-width:160px"><div class="nqs-head-t">'+esc(quiz.title)+'</div>'+
                    '<div class="nqs-head-s">سوال '+fa(idx+1)+' از '+fa(qs.length)+'</div></div>'+
                    (showTimer?'<div class="nqs-timer '+(low?'low':'')+'">'+ICON.clock+' '+this.fmt(this.state.timeLeft)+'</div>':'')+'</div>'+
                '<div class="nqs-prog"><span style="width:'+progress+'%"></span></div>'+
                '<div class="nqs-card nqs-q"><div class="nqs-q-top"><span class="nqs-q-num">'+fa(idx+1)+'</span>'+
                    '<div style="flex:1"><div class="nqs-q-text">'+esc(q.text)+'</div>'+
                    '<div class="nqs-q-meta">'+typeLabelJs(q.type)+' · '+fa(q.points)+' نمره</div></div></div>'+
                    (q.media?'<img class="nqs-q-media" src="'+esc(q.media)+'" alt="">':'')+body+'</div>'+
                '<div class="nqs-nav"><button class="nqs-btn prev '+(idx<=0?'off':'')+'" data-act="prev">'+ICON.prev+' قبلی</button>'+
                    '<div class="nqs-palette">'+palette+'</div>'+
                    (isLast?'<button class="nqs-btn submit" data-act="submit">'+ICON.check+' پایان آزمون</button>':'<button class="nqs-btn next" data-act="next">بعدی '+ICON.next+'</button>')+
                '</div>';
        };

        ST.prototype.renderDone=function(){
            var r=this.state.result, quiz=this.quiz, C=326.7;
            var off=C*(1-r.pct/100);
            var desc = r.passed
                ? (quiz.courseId ? 'با قبولی در این آزمون، شرط صدور گواهی دوره را برآورده کردید.' : 'شما حد نصاب قبولی این آزمون را کسب کردید.')
                : ('برای قبولی به حداقل '+fa(quiz.passScore)+'٪ نیاز دارید. می‌توانید دوباره تلاش کنید.');
            var counts='<span>'+ICON.check+' '+fa(r.correctCount)+' درست</span><span>'+fa(r.wrongCount)+' نادرست</span>'+(r.essayCount>0?'<span>'+fa(r.essayCount)+' در انتظار تصحیح</span>':'');
            var notSaved = (this.opts.mode==='server'&&this.opts.loggedIn===false) ? '<div class="nqs-note" style="margin-bottom:16px">برای ثبت نتیجه و صدور گواهی، ابتدا وارد حساب کاربری شوید.</div>' : '';

            var review=(r.review||[]).map(function(rv){ var bg,col,icon;
                if (!rv.isAuto){ bg='#fff4e2';col='#c2841a';icon=ICON.info; }
                else if (rv.ok){ bg='rgba(72,175,59,.14)';col='#3e9a32';icon=ICON.check; }
                else if (rv.type==='match'&&rv.partial>0){ bg='#fff4e2';col='#c2841a';icon='~'; }
                else { bg='#fdeaea';col='#e05656';icon='✕'; }
                var exp = rv.explanation ? '<div class="nqs-rv-exp">'+ICON.info+' '+esc(rv.explanation)+'</div>' : '';
                var det = rv.detail ? '<div class="nqs-rv-d">'+esc(rv.detail)+'</div>' : '';
                return '<div class="nqs-rv"><span class="nqs-rv-mark" style="background:'+bg+';color:'+col+'">'+icon+'</span>'+
                    '<div style="flex:1"><div class="nqs-rv-t">'+rv.num+'. '+esc(rv.text)+'</div>'+det+exp+'</div>'+
                    '<span class="nqs-rv-score">'+esc(rv.score)+'</span></div>';
            }).join('');

            this.root.innerHTML=
                notSaved+
                '<div class="nqs-banner '+(r.passed?'pass':'fail')+'"><div class="nqs-ring">'+
                    '<svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg)"><circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="11"></circle>'+
                    '<circle cx="60" cy="60" r="52" fill="none" stroke="#fff" stroke-width="11" stroke-linecap="round" stroke-dasharray="326.7" stroke-dashoffset="'+off+'"></circle></svg>'+
                    '<div class="nqs-ring-c"><span class="nqs-ring-pct">'+fa(r.pct)+'٪</span><span class="nqs-ring-l">نمره خودکار</span></div></div>'+
                    '<div><div class="nqs-res-t">'+(r.passed?'تبریک! قبول شدید 🎉':'متأسفانه قبول نشدید')+'</div>'+
                    '<div class="nqs-res-d">'+desc+'</div><div class="nqs-res-counts">'+counts+'</div></div></div>'+
                '<div class="nqs-review">'+review+'</div>'+
                '<div class="nqs-actions"><button class="nqs-btn retake" data-act="retake">'+ICON.retry+' تلاش مجدد</button>'+
                    '<button class="nqs-btn backc" data-act="back">بازگشت</button></div>';
        };

        ST.prototype.bind=function(){
            var self=this;
            this.root.querySelectorAll('[data-act]').forEach(function(el){
                var act=el.getAttribute('data-act');
                if (act==='text'){
                    el.addEventListener('input', function(){ self.setAnswer(self.qs[self.state.idx].id, el.value); });
                    return;
                }
                if (act==='chip'){
                    el.addEventListener('dragstart', function(){ self.state.drag=parseInt(el.getAttribute('data-i'),10); });
                    el.addEventListener('click', function(){ // tap-to-assign: fill first empty slot
                        var q=self.qs[self.state.idx]; var map=self.state.answers[q.id]||{};
                        for (var i=0;i<q.pairs.length;i++){ if(map[i]==null){ self.matchAssign(q,i,parseInt(el.getAttribute('data-i'),10)); return; } }
                    });
                    return;
                }
                if (act==='slot'){
                    el.addEventListener('dragover', function(e){ e.preventDefault(); });
                    el.addEventListener('drop', function(e){ e.preventDefault(); var q=self.qs[self.state.idx]; if(self.state.drag!=null) self.matchAssign(q,parseInt(el.getAttribute('data-i'),10),self.state.drag); });
                    el.addEventListener('click', function(){ var q=self.qs[self.state.idx]; var map=self.state.answers[q.id]||{}; var i=parseInt(el.getAttribute('data-i'),10); if(map[i]!=null) self.matchClear(q,i); });
                    return;
                }
                el.addEventListener('click', function(){
                    var q=self.qs?self.qs[self.state.idx]:null;
                    if (act==='pick'){ self.pickChoice(q, el.getAttribute('data-id')); }
                    else if (act==='prev'){ if(self.state.idx>0) self.goto(self.state.idx-1); }
                    else if (act==='next'){ if(self.state.idx<self.qs.length-1) self.goto(self.state.idx+1); }
                    else if (act==='goto'){ self.goto(parseInt(el.getAttribute('data-i'),10)); }
                    else if (act==='submit'){ self.submit(); }
                    else if (act==='retake'){ self.start(); }
                    else if (act==='back'){ if(self.opts.onBack){ self.opts.onBack(); } else { self.start(); } }
                });
            });
        };

        function typeLabelJs(k){ return ({choice:'چندگزینه‌ای',multi:'چندپاسخی',tf:'صحیح/غلط',fill:'جای‌خالی',essay:'تشریحی',match:'تطبیقی'})[k]||k; }

        window.NiasQuizStudent = {
            mount: function (root, quiz, opts) {
                if (!root || !quiz) return;
                if (!root.classList.contains('nqs')) { root.classList.add('nqs'); }
                root.style.setProperty('--acc', (opts && opts.accent) || '#1e83f0');
                var inst = new ST(root, quiz, opts || {});
                inst.start();
                return inst;
            }
        };
    })();
    </script>
    <?php
}
