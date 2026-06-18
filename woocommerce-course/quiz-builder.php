<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quiz builder (آزمون‌ساز).
 *
 * Recreated from the Claude Design handoff ("آزمون‌ساز نیاس"). Activated from the
 * main plugin settings ("فعالسازی آزمون‌ساز"). When enabled it adds an "آزمون‌ساز"
 * sub-page under the plugin settings where an admin can:
 *   - manage quizzes (list, filter by category, create/duplicate/delete),
 *   - edit a quiz and its questions (6 types: چندگزینه‌ای، چندپاسخی، صحیح/غلط،
 *     جای‌خالی، تشریحی، تطبیقی), with per-quiz settings (timing, pass score,
 *     shuffling, explanation display, and a linked WooCommerce course),
 *   - preview the quiz in the student-facing view (client-side grading).
 *
 * Each quiz is stored as a `nias_quiz` custom post: the post title holds the quiz
 * title and a single JSON post meta (NIAS_QUIZ_META) holds the settings +
 * questions. The linked course id is mirrored into NIAS_QUIZ_COURSE_META so the
 * certificate gate can query it cheaply.
 *
 * The student-facing rendering, server-side grading and certificate gating live
 * in woocommerce-course/quiz-frontend.php.
 *
 * @package nias-course-widget
 */

if (!defined('NIAS_QUIZ_CPT')) {
    define('NIAS_QUIZ_CPT', 'nias_quiz');
}
if (!defined('NIAS_QUIZ_META')) {
    define('NIAS_QUIZ_META', '_nias_quiz_data');
}
if (!defined('NIAS_QUIZ_COURSE_META')) {
    define('NIAS_QUIZ_COURSE_META', '_nias_quiz_course');
}

/* -------------------------------------------------------------------------
 * Feature flag + storage
 * ---------------------------------------------------------------------- */

/** Is the quiz builder enabled in the plugin settings? */
function nias_quiz_enabled()
{
    return carbon_get_theme_option('nias_quiz_enabled') === 'on';
}

/** Register the storage post type (private; managed via the custom page). */
add_action('init', 'nias_quiz_register_cpt');
function nias_quiz_register_cpt()
{
    register_post_type(NIAS_QUIZ_CPT, array(
        'label'           => __('آزمون‌ها', 'nias-course-widget'),
        'public'          => false,
        'show_ui'         => false,
        'show_in_menu'    => false,
        'hierarchical'    => false,
        'supports'        => array('title'),
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ));
}

/** The 6 supported question types. */
function nias_quiz_types()
{
    return array('choice', 'multi', 'tf', 'fill', 'essay', 'match');
}

/** Default quiz settings used for a brand-new quiz. */
function nias_quiz_default_settings()
{
    return array(
        'category'   => __('دسته‌بندی نشده', 'nias-course-widget'),
        'timeMode'   => 'total',
        'timeValue'  => 15,
        'passScore'  => 60,
        'shuffleQ'    => false,
        'shuffleOpt'  => false,
        'showExp'     => true,
        'showAnswers' => true,
        'courseId'    => 0,
    );
}

/**
 * Sanitize one question coming from the editor into the stored shape.
 *
 * @param array $q
 * @return array|null null when the type is unknown
 */
function nias_quiz_sanitize_question($q)
{
    if (!is_array($q)) {
        return null;
    }
    $type = isset($q['type']) ? sanitize_key($q['type']) : '';
    if (!in_array($type, nias_quiz_types(), true)) {
        return null;
    }

    $out = array(
        'id'          => isset($q['id']) ? sanitize_key($q['id']) : 'x' . wp_generate_password(6, false, false),
        'type'        => $type,
        'text'        => isset($q['text']) ? sanitize_textarea_field($q['text']) : '',
        'media'       => isset($q['media']) ? esc_url_raw($q['media']) : '',
        'points'      => isset($q['points']) ? max(0, (float) $q['points']) : 1,
        'explanation' => isset($q['explanation']) ? sanitize_textarea_field($q['explanation']) : '',
    );

    if ($type === 'choice' || $type === 'multi' || $type === 'tf') {
        $options = array();
        $valid_ids = array();
        foreach ((array) (isset($q['options']) ? $q['options'] : array()) as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $oid = isset($opt['id']) ? sanitize_key($opt['id']) : 'o' . wp_generate_password(5, false, false);
            $options[] = array(
                'id'   => $oid,
                'text' => isset($opt['text']) ? sanitize_text_field($opt['text']) : '',
            );
            $valid_ids[] = $oid;
        }
        $out['options'] = $options;
        $correct = array();
        foreach ((array) (isset($q['correct']) ? $q['correct'] : array()) as $cid) {
            $cid = sanitize_key($cid);
            if (in_array($cid, $valid_ids, true)) {
                $correct[] = $cid;
            }
        }
        $out['correct'] = array_values(array_unique($correct));
    } elseif ($type === 'fill' || $type === 'essay') {
        $out['answerText'] = isset($q['answerText']) ? sanitize_textarea_field($q['answerText']) : '';
    } elseif ($type === 'match') {
        $pairs = array();
        foreach ((array) (isset($q['pairs']) ? $q['pairs'] : array()) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pairs[] = array(
                'left'  => isset($p['left']) ? sanitize_text_field($p['left']) : '',
                'right' => isset($p['right']) ? sanitize_text_field($p['right']) : '',
            );
        }
        $out['pairs'] = $pairs;
    }

    return $out;
}

/**
 * Sanitize a full quiz model (settings + questions) from the editor.
 *
 * @param array $model
 * @return array
 */
function nias_quiz_sanitize_model($model)
{
    $model = is_array($model) ? $model : array();
    $def   = nias_quiz_default_settings();

    $time_mode = isset($model['timeMode']) ? sanitize_key($model['timeMode']) : $def['timeMode'];
    if (!in_array($time_mode, array('total', 'perQuestion', 'none'), true)) {
        $time_mode = 'total';
    }

    $questions = array();
    foreach ((array) (isset($model['questions']) ? $model['questions'] : array()) as $q) {
        $sq = nias_quiz_sanitize_question($q);
        if ($sq) {
            $questions[] = $sq;
        }
    }

    return array(
        'title'      => isset($model['title']) ? sanitize_text_field($model['title']) : __('آزمون بدون عنوان', 'nias-course-widget'),
        'category'   => isset($model['category']) ? sanitize_text_field($model['category']) : $def['category'],
        'timeMode'   => $time_mode,
        'timeValue'  => isset($model['timeValue']) ? max(1, (int) $model['timeValue']) : $def['timeValue'],
        'passScore'  => isset($model['passScore']) ? max(0, min(100, (int) $model['passScore'])) : $def['passScore'],
        'shuffleQ'    => !empty($model['shuffleQ']),
        'shuffleOpt'  => !empty($model['shuffleOpt']),
        'showExp'     => !empty($model['showExp']),
        'showAnswers' => !empty($model['showAnswers']),
        'courseId'    => isset($model['courseId']) ? (int) $model['courseId'] : 0,
        'questions'  => $questions,
    );
}

/**
 * Read one quiz as the flat JS model the editor/front-end use.
 *
 * @param int $post_id
 * @return array|null
 */
function nias_quiz_get($post_id)
{
    $post = get_post($post_id);
    if (!$post || $post->post_type !== NIAS_QUIZ_CPT) {
        return null;
    }
    $stored = json_decode((string) get_post_meta($post_id, NIAS_QUIZ_META, true), true);
    $stored = is_array($stored) ? $stored : array();
    $model  = array_merge(nias_quiz_default_settings(), $stored);

    $model['id']        = (int) $post_id;
    $model['title']     = $post->post_title !== '' ? $post->post_title : __('آزمون بدون عنوان', 'nias-course-widget');
    $model['questions'] = isset($stored['questions']) && is_array($stored['questions']) ? $stored['questions'] : array();
    $model['courseId']  = (int) $model['courseId'];

    return $model;
}

/**
 * All quizzes as JS models (newest first).
 *
 * @return array
 */
function nias_quiz_all()
{
    $ids = get_posts(array(
        'post_type'      => NIAS_QUIZ_CPT,
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft'),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ));
    $out = array();
    foreach ($ids as $id) {
        $q = nias_quiz_get($id);
        if ($q) {
            $out[] = $q;
        }
    }
    return $out;
}

/**
 * Persist a quiz model to its post + meta.
 *
 * @param int   $post_id
 * @param array $model sanitized model
 */
function nias_quiz_store($post_id, $model)
{
    wp_update_post(array(
        'ID'         => $post_id,
        'post_title' => $model['title'],
    ));
    update_post_meta($post_id, NIAS_QUIZ_META, wp_slash(wp_json_encode($model)));
    update_post_meta($post_id, NIAS_QUIZ_COURSE_META, (int) $model['courseId']);
}

/** Create an empty quiz and return its JS model. */
function nias_quiz_create_new()
{
    $model = nias_quiz_sanitize_model(array_merge(nias_quiz_default_settings(), array(
        'title'     => __('آزمون بدون عنوان', 'nias-course-widget'),
        'questions' => array(),
    )));
    $post_id = wp_insert_post(array(
        'post_type'   => NIAS_QUIZ_CPT,
        'post_status' => 'publish',
        'post_title'  => $model['title'],
    ));
    if (is_wp_error($post_id) || !$post_id) {
        return null;
    }
    nias_quiz_store($post_id, $model);
    return nias_quiz_get($post_id);
}

/** Product options (id => title) for the "linked course" select. */
function nias_quiz_course_options()
{
    $options = array();
    foreach (get_posts(array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft', 'private'),
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    )) as $pid) {
        $options[] = array('value' => (int) $pid, 'label' => get_the_title($pid));
    }
    return $options;
}

/* -------------------------------------------------------------------------
 * Admin page registration
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_quiz_register_page', 20);
function nias_quiz_register_page()
{
    if (!current_user_can('manage_options') || !nias_quiz_enabled()) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('آزمون‌ساز', 'nias-course-widget'),
        __('آزمون‌ساز', 'nias-course-widget'),
        'manage_options',
        'nias-course-quiz',
        'nias_quiz_render_page'
    );
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

/** Shared guard for the builder AJAX endpoints. */
function nias_quiz_ajax_guard()
{
    if (!current_user_can('manage_options') || !nias_quiz_enabled()) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'nias-course-widget')));
    }
    if (!check_ajax_referer('nias_quiz_admin', 'nonce', false)) {
        wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'nias-course-widget')));
    }
}

add_action('wp_ajax_nias_quiz_create', 'nias_quiz_ajax_create');
function nias_quiz_ajax_create()
{
    nias_quiz_ajax_guard();
    $quiz = nias_quiz_create_new();
    if (!$quiz) {
        wp_send_json_error(array('message' => __('ساخت آزمون ناموفق بود.', 'nias-course-widget')));
    }
    wp_send_json_success(array('quiz' => $quiz));
}

add_action('wp_ajax_nias_quiz_save', 'nias_quiz_ajax_save');
function nias_quiz_ajax_save()
{
    nias_quiz_ajax_guard();
    $id   = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0;
    $post = $id ? get_post($id) : null;
    if (!$post || $post->post_type !== NIAS_QUIZ_CPT) {
        wp_send_json_error(array('message' => __('آزمون یافت نشد.', 'nias-course-widget')));
    }
    $raw   = isset($_POST['quiz']) ? json_decode(wp_unslash($_POST['quiz']), true) : array();
    $model = nias_quiz_sanitize_model($raw);
    nias_quiz_store($id, $model);
    wp_send_json_success(array('message' => __('تغییرات ذخیره شد.', 'nias-course-widget')));
}

add_action('wp_ajax_nias_quiz_delete', 'nias_quiz_ajax_delete');
function nias_quiz_ajax_delete()
{
    nias_quiz_ajax_guard();
    $id   = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0;
    $post = $id ? get_post($id) : null;
    if (!$post || $post->post_type !== NIAS_QUIZ_CPT) {
        wp_send_json_error(array('message' => __('آزمون یافت نشد.', 'nias-course-widget')));
    }
    wp_delete_post($id, true);
    wp_send_json_success(array('message' => __('آزمون حذف شد.', 'nias-course-widget')));
}

add_action('wp_ajax_nias_quiz_duplicate', 'nias_quiz_ajax_duplicate');
function nias_quiz_ajax_duplicate()
{
    nias_quiz_ajax_guard();
    $id   = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0;
    $src  = nias_quiz_get($id);
    if (!$src) {
        wp_send_json_error(array('message' => __('آزمون یافت نشد.', 'nias-course-widget')));
    }
    $src['title'] = $src['title'] . __(' (کپی)', 'nias-course-widget');
    $model = nias_quiz_sanitize_model($src);
    $post_id = wp_insert_post(array(
        'post_type'   => NIAS_QUIZ_CPT,
        'post_status' => 'publish',
        'post_title'  => $model['title'],
    ));
    if (is_wp_error($post_id) || !$post_id) {
        wp_send_json_error(array('message' => __('تکثیر ناموفق بود.', 'nias-course-widget')));
    }
    nias_quiz_store($post_id, $model);
    wp_send_json_success(array('quiz' => nias_quiz_get($post_id)));
}

/* -------------------------------------------------------------------------
 * Page render
 * ---------------------------------------------------------------------- */

function nias_quiz_render_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $boot = array(
        'quizzes'       => nias_quiz_all(),
        'courseOptions' => nias_quiz_course_options(),
        'ajaxUrl'       => admin_url('admin-ajax.php'),
        'nonce'         => wp_create_nonce('nias_quiz_admin'),
        'shortcodeBase' => '[nias_quiz id="%ID%"]',
    );
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('quiz'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">
            <div id="nias-quiz-app" class="nqz-app"><div class="nqz-boot"><?php echo esc_html__('در حال بارگذاری آزمون‌ساز…', 'nias-course-widget'); ?></div></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>
    <script>window.NIAS_QUIZ = <?php echo wp_json_encode($boot); ?>;</script>
    <?php
    nias_quiz_builder_styles();
    // Shared student component (used by the in-admin preview, local grading mode).
    if (function_exists('nias_quiz_student_assets')) {
        nias_quiz_student_assets();
    }
    nias_quiz_builder_script();
}

/* -------------------------------------------------------------------------
 * Builder styles
 * ---------------------------------------------------------------------- */

function nias_quiz_builder_styles()
{
    ?>
    <style>
    .nqz-app,.nqz-app *{box-sizing:border-box}
    .nqz-app{--acc:#3858e9;color:#1f2a30;font-family:inherit}
    .nqz-boot{padding:40px;text-align:center;color:#8a90a6}
    .nqz-app input,.nqz-app textarea,.nqz-app select,.nqz-app button{font-family:inherit}
    .nqz-app textarea{resize:vertical}
    .nqz-in{width:100%;border:1.5px solid #e1e6e9;border-radius:10px;padding:10px 12px;font-size:14px;color:#2b363c;background:#fff;outline:none;transition:border-color .15s}
    .nqz-in:focus{border-color:var(--acc)}
    .nqz-scroll::-webkit-scrollbar{width:8px;height:8px}
    .nqz-scroll::-webkit-scrollbar-thumb{background:#cdd5da;border-radius:99px}

    /* topbar */
    .nqz-top{display:flex;align-items:center;gap:14px;margin-bottom:18px;flex-wrap:wrap}
    .nqz-top-l{display:flex;align-items:center;gap:11px;flex:1;min-width:200px}
    .nqz-top-ic{width:40px;height:40px;border-radius:12px;background:var(--acc);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
    .nqz-top-title{font-size:17px;font-weight:800;line-height:1.2}
    .nqz-top-sub{font-size:12px;color:#7b868a;margin-top:2px}
    .nqz-top-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    .nqz-titlein{border:1px solid transparent;border-radius:9px;padding:8px 12px;font-size:15px;font-weight:700;background:#f1f4f6;color:#1f2a30;width:min(320px,44vw);outline:none}
    .nqz-titlein:focus{border-color:var(--acc);background:#fff}

    .nqz-btn{display:inline-flex;align-items:center;gap:7px;border:0;border-radius:11px;font-size:14px;font-weight:700;padding:10px 17px;cursor:pointer}
    .nqz-btn-primary{background:var(--acc);color:#fff}
    .nqz-btn-soft{background:#f1f4f6;color:#3a464c;font-weight:600}
    .nqz-btn-ghost{background:#fff;border:1px solid #e1e6e9;color:#3a464c;font-weight:600}

    /* list */
    .nqz-cats{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap}
    .nqz-cat{background:#fff;color:#5b666c;border:1px solid #e1e6e9;font-size:13px;font-weight:600;padding:7px 14px;border-radius:99px;cursor:pointer}
    .nqz-cat.on{background:var(--acc);color:#fff;border-color:var(--acc)}
    .nqz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px}
    .nqz-card{background:#fff;border:1px solid #e9ecef;border-radius:16px;padding:18px;box-shadow:0 4px 16px rgba(31,42,48,.04);display:flex;flex-direction:column;gap:13px}
    .nqz-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .nqz-card-cat{align-self:flex-start;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:7px}
    .nqz-card-title{margin:0;font-size:15.5px;font-weight:700;line-height:1.5}
    .nqz-card-status{font-size:11px;font-weight:600;padding:4px 9px;border-radius:7px;white-space:nowrap}
    .nqz-card-meta{display:flex;align-items:center;gap:14px;font-size:12.5px;color:#7b868a;flex-wrap:wrap}
    .nqz-card-meta span{display:inline-flex;align-items:center;gap:5px}
    .nqz-sep{height:1px;background:#eef1f4}
    .nqz-card-actions{display:flex;align-items:center;gap:8px}
    .nqz-card-actions .ed{flex:1;justify-content:center;background:var(--acc);color:#fff;border:0;font-size:13px;font-weight:700;padding:9px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
    .nqz-card-actions .pv{background:#f1f4f6;color:#3a464c;border:0;font-size:13px;font-weight:600;padding:9px 13px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
    .nqz-iconbtn{width:36px;height:36px;flex:none;display:flex;align-items:center;justify-content:center;border:0;border-radius:10px;cursor:pointer}
    .nqz-iconbtn.cp{background:#f1f4f6;color:#7b868a}
    .nqz-iconbtn.dl{background:#fff0f0;color:#e05656}
    .nqz-new{background:transparent;border:2px dashed #cdd5da;border-radius:16px;padding:20px;min-height:170px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:#7b868a;cursor:pointer}
    .nqz-new span.ic{width:44px;height:44px;border-radius:50%;background:#fff;border:1px solid #e1e6e9;display:flex;align-items:center;justify-content:center;color:var(--acc)}

    /* editor */
    .nqz-elayout{display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start}
    @media (max-width:880px){.nqz-elayout{grid-template-columns:1fr}}
    .nqz-rail{background:#fff;border:1px solid #e9ecef;border-radius:16px;overflow:hidden;position:sticky;top:16px}
    .nqz-railtabs{display:flex;border-bottom:1px solid #eef1f4}
    .nqz-railtab{flex:1;background:none;border:0;border-bottom:2px solid transparent;color:#7b868a;font-size:13.5px;font-weight:700;padding:13px;cursor:pointer}
    .nqz-railtab.on{border-bottom-color:var(--acc);color:var(--acc)}
    .nqz-qlist{max-height:46vh;overflow-y:auto;padding:8px}
    .nqz-qrow{width:100%;display:flex;align-items:center;gap:9px;padding:9px 10px;background:transparent;border:0;border-radius:10px;cursor:pointer;margin-bottom:2px;text-align:right}
    .nqz-qrow.on{background:rgba(56,88,233,.06)}
    .nqz-qnum{width:26px;height:26px;flex:none;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;background:#eef1f4;color:#7b868a}
    .nqz-qrow.on .nqz-qnum{background:var(--acc);color:#fff}
    .nqz-qbody{flex:1;min-width:0}
    .nqz-qtext{display:block;font-size:13px;font-weight:600;color:#2b363c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .nqz-qrow.on .nqz-qtext{color:var(--acc)}
    .nqz-qsub{display:block;font-size:11px;color:#9aa4a9;margin-top:2px}
    .nqz-qdel{width:24px;height:24px;flex:none;display:flex;align-items:center;justify-content:center;color:#c2cacf;border:0;background:none;border-radius:6px;cursor:pointer}
    .nqz-qdel:hover{color:#e05656;background:#fff0f0}
    .nqz-railfoot{padding:10px;border-top:1px solid #eef1f4}
    .nqz-addbtn{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:rgba(56,88,233,.08);color:var(--acc);border:0;font-size:13.5px;font-weight:700;padding:11px;border-radius:10px;cursor:pointer}
    .nqz-typemenu{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px}
    .nqz-typemenu button{display:flex;align-items:center;gap:7px;background:#f8fafb;border:1px solid #eef1f4;border-radius:9px;padding:9px;font-size:12px;font-weight:600;color:#3a464c;cursor:pointer}
    .nqz-railtotals{padding:0 12px 14px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#7b868a}
    .nqz-settings{max-height:64vh;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:16px}
    .nqz-flabel{font-size:12.5px;font-weight:600;color:#5b666c}
    .nqz-field{display:flex;flex-direction:column;gap:6px}
    .nqz-segrow{display:flex;gap:6px}
    .nqz-seg{flex:1;border:0;font-size:12px;font-weight:600;padding:9px 4px;border-radius:9px;cursor:pointer;background:#f1f4f6;color:#5b666c}
    .nqz-seg.on{background:var(--acc);color:#fff}
    .nqz-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;background:none;border:0;padding:0;cursor:pointer;text-align:right;width:100%}
    .nqz-toggle .lbl{font-size:13px;color:#3a464c}
    .nqz-track{width:40px;height:23px;border-radius:99px;background:#d4dadd;position:relative;flex:none;transition:background .2s}
    .nqz-track.on{background:var(--acc)}
    .nqz-knob{position:absolute;top:3px;right:3px;width:17px;height:17px;border-radius:50%;background:#fff;transition:.2s}
    .nqz-track.on .nqz-knob{right:auto;left:3px}
    .nqz-hint{font-size:11.5px;color:#9aa4a9;line-height:1.7}

    /* main editor */
    .nqz-main{background:#fff;border:1px solid #e9ecef;border-radius:16px;overflow:hidden;min-height:420px}
    .nqz-mpad{padding:20px 22px}
    .nqz-mhead{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap}
    .nqz-mhead-l{display:flex;align-items:center;gap:9px}
    .nqz-mnum{width:30px;height:30px;border-radius:8px;background:rgba(56,88,233,.1);color:var(--acc);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
    .nqz-mtype{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#5b666c;background:#f1f4f6;padding:6px 11px;border-radius:8px}
    .nqz-points{display:flex;align-items:center;gap:7px;font-size:13px;color:#5b666c}
    .nqz-points input{width:74px}
    .nqz-block{margin-bottom:16px}
    .nqz-block > .nqz-flabel{display:block;margin-bottom:6px}
    .nqz-opt{display:flex;align-items:center;gap:10px;margin-bottom:9px}
    .nqz-mark{width:36px;height:36px;flex:none;display:flex;align-items:center;justify-content:center;border:1.5px solid #d4dadd;background:#fff;color:#fff;cursor:pointer}
    .nqz-mark.on{border-color:#48af3b;background:#48af3b}
    .nqz-mark.radio{border-radius:50%}
    .nqz-mark.box{border-radius:8px}
    .nqz-optdel{width:36px;height:36px;flex:none;display:flex;align-items:center;justify-content:center;background:#fff0f0;color:#e05656;border:0;border-radius:9px;cursor:pointer}
    .nqz-addopt{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;background:none;border:0;color:var(--acc);font-size:13px;font-weight:700;cursor:pointer;padding:4px 0}
    .nqz-tf{display:flex;gap:10px}
    .nqz-tfbtn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1.5px solid #d4dadd;background:#fff;color:#5b666c;font-size:14px;font-weight:700;padding:12px;border-radius:11px;cursor:pointer}
    .nqz-tfbtn.on{border-color:#48af3b;background:rgba(72,175,59,.1);color:#2f7a24}
    .nqz-essaynote{background:#fff8ec;border:1px solid #f3e3c0;border-radius:12px;padding:14px;display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:#946f23;line-height:1.8}
    .nqz-pair{display:flex;align-items:center;gap:8px;margin-bottom:9px}
    .nqz-empty{height:420px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#9aa4a9;text-align:center;padding:30px}
    .nqz-empty .ic{width:60px;height:60px;border-radius:16px;background:#f1f4f6;display:flex;align-items:center;justify-content:center;color:#c2cacf}

    /* shortcode chip */
    .nqz-sc{display:inline-flex;align-items:center;gap:8px;background:#f1f4f6;border-radius:9px;padding:7px 11px;font-size:12.5px;color:#5b666c}
    .nqz-sc code{direction:ltr;font-weight:700;color:#3a464c}
    .nqz-sc button{background:#fff;border:1px solid #e1e6e9;border-radius:7px;padding:4px 8px;font-size:11.5px;cursor:pointer;color:#5b666c}

    /* preview overlay */
    .nqz-ov{position:fixed;inset:0;z-index:100000;background:rgba(20,28,40,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 14px;overflow:auto}
    .nqz-ov-box{background:#eef1f4;border-radius:18px;width:min(820px,100%);box-shadow:0 24px 70px rgba(0,0,0,.35)}
    .nqz-ov-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;background:#fff;border-radius:18px 18px 0 0;border-bottom:1px solid #e9ecef}
    .nqz-ov-bar b{font-size:15px}
    .nqz-ov-x{width:34px;height:34px;border:0;border-radius:9px;background:#f1f4f6;color:#3a464c;cursor:pointer;font-size:18px}
    .nqz-ov-body{padding:18px}
    .nqz-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1f2a30;color:#fff;padding:11px 20px;border-radius:11px;font-size:13.5px;z-index:100001;opacity:0;transition:opacity .2s;pointer-events:none}
    .nqz-toast.on{opacity:1}
    </style>
    <?php
}

/* -------------------------------------------------------------------------
 * Builder JS app
 * ---------------------------------------------------------------------- */

function nias_quiz_builder_script()
{
    ?>
    <script>
    (function () {
        var BOOT = window.NIAS_QUIZ || {};
        var ROOT = document.getElementById('nias-quiz-app');
        if (!ROOT) { return; }

        function fa(n){ return String(n).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];}); }
        function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }
        function uid(){ return 'x'+Math.random().toString(36).slice(2,8); }

        var ICON = {
            logo:'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
            list:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
            clock:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            target:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>',
            edit:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
            play:'<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
            copy:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9h10v10H9zM5 15V5h10"/></svg>',
            trash:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M6 7l1 13h10l1-13"/></svg>',
            trashSm:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M6 7l1 13h10l1-13M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>',
            plus:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
            check:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>',
            prev:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>',
            info:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5v.5"/></svg>',
            link:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6M10 8H8a4 4 0 0 0 0 8h2M14 8h2a4 4 0 0 1 0 8h-2"/></svg>',
            save:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>'
        };
        var TYPES = [
            { k:'choice', label:'چندگزینه‌ای' },
            { k:'multi',  label:'چندپاسخی' },
            { k:'tf',     label:'صحیح/غلط' },
            { k:'fill',   label:'جای‌خالی' },
            { k:'essay',  label:'تشریحی' },
            { k:'match',  label:'تطبیقی' }
        ];
        function typeLabel(k){ for (var i=0;i<TYPES.length;i++){ if(TYPES[i].k===k) return TYPES[i].label; } return k; }

        var state = { screen:'list', catFilter:'all', editId:null, selQ:null, railTab:'questions', addMenu:false };
        var quizzes = Array.isArray(BOOT.quizzes) ? BOOT.quizzes : [];
        var saveTimers = {};

        function byId(id){ for (var i=0;i<quizzes.length;i++){ if(String(quizzes[i].id)===String(id)) return quizzes[i]; } return null; }
        function editing(){ return byId(state.editId); }
        function selQuestion(){ var ed=editing(); if(!ed) return null; for(var i=0;i<ed.questions.length;i++){ if(ed.questions[i].id===state.selQ) return ed.questions[i]; } return null; }

        /* ---------- server ---------- */
        function ajax(action, data){
            data = data || {};
            data.action = action;
            data.nonce = BOOT.nonce;
            var body = Object.keys(data).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(data[k]); }).join('&');
            return fetch(BOOT.ajaxUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body })
                .then(function(r){ return r.json(); });
        }
        function scheduleSave(id){
            if (saveTimers[id]) clearTimeout(saveTimers[id]);
            saveTimers[id] = setTimeout(function(){ saveNow(id); }, 700);
        }
        function saveNow(id){
            var q = byId(id); if(!q) return;
            ajax('nias_quiz_save', { quiz_id:id, quiz:JSON.stringify(q) }).then(function(res){
                if (res && res.success) { toast('ذخیره شد'); }
            }).catch(function(){});
        }
        var toastT;
        function toast(msg){
            var t = document.getElementById('nqz-toast');
            if(!t){ t=document.createElement('div'); t.id='nqz-toast'; t.className='nqz-toast'; document.body.appendChild(t); }
            t.textContent = msg; t.classList.add('on');
            clearTimeout(toastT); toastT=setTimeout(function(){ t.classList.remove('on'); },1500);
        }

        /* ---------- mutations ---------- */
        function touch(){ var ed=editing(); if(ed) scheduleSave(ed.id); }
        function updQuiz(patch){ var ed=editing(); if(!ed) return; for(var k in patch){ ed[k]=patch[k]; } touch(); }
        function updQ(qid, patch){ var ed=editing(); if(!ed) return; ed.questions.forEach(function(q){ if(q.id===qid){ for(var k in patch){ q[k]=patch[k]; } } }); touch(); }

        function blankQ(type){
            var b = { id:uid(), type:type, text:'', media:'', points:1, explanation:'' };
            if (type==='choice'||type==='multi'){ b.options=[{id:uid(),text:''},{id:uid(),text:''}]; b.correct=[]; }
            else if (type==='tf'){ b.options=[{id:'t',text:'صحیح'},{id:'f',text:'غلط'}]; b.correct=[]; }
            else if (type==='fill'||type==='essay'){ b.answerText=''; }
            else if (type==='match'){ b.pairs=[{left:'',right:''},{left:'',right:''}]; }
            return b;
        }
        function addQ(type){ var ed=editing(); if(!ed) return; var q=blankQ(type); ed.questions.push(q); state.selQ=q.id; state.addMenu=false; touch(); render(); }
        function delQ(qid){ var ed=editing(); if(!ed) return; ed.questions=ed.questions.filter(function(q){return q.id!==qid;}); if(state.selQ===qid){ state.selQ=ed.questions[0]?ed.questions[0].id:null; } touch(); render(); }

        /* ---------- list screen ---------- */
        function renderList(){
            var cats = []; quizzes.forEach(function(q){ if(cats.indexOf(q.category)<0) cats.push(q.category); });
            var catBtns = '<button class="nqz-cat '+(state.catFilter==='all'?'on':'')+'" data-act="filter" data-v="all">همه آزمون‌ها</button>';
            cats.forEach(function(c){ catBtns += '<button class="nqz-cat '+(state.catFilter===c?'on':'')+'" data-act="filter" data-v="'+esc(c)+'">'+esc(c)+'</button>'; });

            var palette = ['#3858e9','#48af3b','#8b5cf6','#f59e0b','#e05656'];
            function hash(s){ var h=0; s=s||''; for(var i=0;i<s.length;i++){ h=(h<<5)-h+s.charCodeAt(i); h|=0; } return h; }
            var filtered = quizzes.filter(function(q){ return state.catFilter==='all'||q.category===state.catFilter; });
            var cards = '';
            filtered.forEach(function(q){
                var col = palette[Math.abs(hash(q.category))%palette.length];
                var time = q.timeMode==='none' ? 'بدون محدودیت' : (q.timeMode==='total'? fa(q.timeValue)+' دقیقه' : fa(q.timeValue)+' ثانیه/سوال');
                var hasEssay = q.questions.some(function(x){return x.type==='essay';});
                cards +=
                    '<div class="nqz-card">'+
                        '<div class="nqz-card-top"><div style="display:flex;flex-direction:column;gap:7px">'+
                            '<span class="nqz-card-cat" style="background:'+col+'1a;color:'+col+'">'+esc(q.category)+'</span>'+
                            '<h3 class="nqz-card-title">'+esc(q.title)+'</h3></div>'+
                            '<span class="nqz-card-status" style="background:'+(hasEssay?'#fff4e2':'#eaf7ea')+';color:'+(hasEssay?'#c2841a':'#3e9a32')+'">'+(hasEssay?'تصحیح ترکیبی':'تصحیح خودکار')+'</span>'+
                        '</div>'+
                        '<div class="nqz-card-meta"><span>'+ICON.list+' '+fa(q.questions.length)+' سوال</span><span>'+ICON.clock+' '+time+'</span><span>'+ICON.target+' قبولی '+fa(q.passScore)+'٪</span></div>'+
                        '<div class="nqz-sep"></div>'+
                        '<div class="nqz-card-actions">'+
                            '<button class="ed" data-act="edit" data-id="'+q.id+'">'+ICON.edit+' ویرایش</button>'+
                            '<button class="pv" data-act="preview" data-id="'+q.id+'">'+ICON.play+' پیش‌نمایش</button>'+
                            '<button class="nqz-iconbtn cp" data-act="dup" data-id="'+q.id+'" title="تکثیر">'+ICON.copy+'</button>'+
                            '<button class="nqz-iconbtn dl" data-act="del" data-id="'+q.id+'" title="حذف">'+ICON.trash+'</button>'+
                        '</div>'+
                    '</div>';
            });
            cards += '<button class="nqz-new" data-act="new"><span class="ic">'+ICON.plus+'</span><span style="font-size:14px;font-weight:600">ساخت آزمون جدید</span></button>';

            return ''+
                '<div class="nqz-top"><div class="nqz-top-l"><span class="nqz-top-ic">'+ICON.logo+'</span><div>'+
                    '<div class="nqz-top-title">آزمون‌ساز نیاس</div><div class="nqz-top-sub">ساخت و مدیریت آزمون‌های دوره</div></div></div>'+
                    '<div class="nqz-top-actions"><button class="nqz-btn nqz-btn-primary" data-act="new">'+ICON.plus+' آزمون جدید</button></div></div>'+
                '<div class="nqz-cats">'+catBtns+'</div>'+
                '<div class="nqz-grid">'+cards+'</div>';
        }

        /* ---------- editor screen ---------- */
        function renderEditor(){
            var ed = editing();
            if (!ed) { state.screen='list'; return renderList(); }

            var sc = BOOT.shortcodeBase.replace('%ID%', ed.id);
            var top = ''+
                '<div class="nqz-top"><div class="nqz-top-l"><span class="nqz-top-ic">'+ICON.logo+'</span><div>'+
                    '<div class="nqz-top-title">ویرایش آزمون</div><div class="nqz-top-sub">آزمون‌ساز نیاس</div></div></div>'+
                '<div class="nqz-top-actions">'+
                    '<input class="nqz-titlein" data-input="title" value="'+esc(ed.title)+'" placeholder="عنوان آزمون">'+
                    '<span class="nqz-sc"><code>'+esc(sc)+'</code><button data-act="copysc" data-v="'+esc(sc)+'">کپی</button></span>'+
                    '<button class="nqz-btn nqz-btn-primary" data-act="preview" data-id="'+ed.id+'">'+ICON.play+' پیش‌نمایش</button>'+
                    '<button class="nqz-btn nqz-btn-soft" data-act="back">'+ICON.prev+' آزمون‌ها</button>'+
                '</div></div>';

            /* rail */
            var rail = '<div class="nqz-railtabs">'+
                '<button class="nqz-railtab '+(state.railTab==='questions'?'on':'')+'" data-act="railtab" data-v="questions">سوال‌ها</button>'+
                '<button class="nqz-railtab '+(state.railTab==='settings'?'on':'')+'" data-act="railtab" data-v="settings">تنظیمات</button></div>';

            if (state.railTab==='questions'){
                var rows='';
                ed.questions.forEach(function(q,i){
                    var on = q.id===state.selQ;
                    rows += '<button class="nqz-qrow '+(on?'on':'')+'" data-act="selq" data-id="'+q.id+'">'+
                        '<span class="nqz-qnum">'+fa(i+1)+'</span>'+
                        '<span class="nqz-qbody"><span class="nqz-qtext">'+esc(q.text||'(بدون عنوان)')+'</span>'+
                        '<span class="nqz-qsub">'+typeLabel(q.type)+' · '+fa(q.points)+' نمره</span></span>'+
                        '<span class="nqz-qdel" data-act="delq" data-id="'+q.id+'">'+ICON.trashSm+'</span></button>';
                });
                var menu='';
                if (state.addMenu){
                    menu='<div class="nqz-typemenu">'+TYPES.map(function(t){ return '<button data-act="addq" data-v="'+t.k+'">'+ICON.plus+' '+t.label+'</button>'; }).join('')+'</div>';
                }
                var pts = ed.questions.reduce(function(a,q){return a+(+q.points||0);},0);
                rail += '<div><div class="nqz-qlist nqz-scroll">'+rows+'</div>'+
                    '<div class="nqz-railfoot"><button class="nqz-addbtn" data-act="toggleadd">'+ICON.plus+' افزودن سوال</button>'+menu+'</div>'+
                    '<div class="nqz-railtotals"><span>'+fa(ed.questions.length)+' سوال</span><span>مجموع '+fa(pts)+' نمره</span></div></div>';
            } else {
                var courseOpts = '<option value="0">بدون اتصال</option>';
                (BOOT.courseOptions||[]).forEach(function(c){ courseOpts += '<option value="'+c.value+'" '+(String(c.value)===String(ed.courseId)?'selected':'')+'>'+esc(c.label)+'</option>'; });
                function seg(v,l){ return '<button class="nqz-seg '+(ed.timeMode===v?'on':'')+'" data-act="timemode" data-v="'+v+'">'+l+'</button>'; }
                function toggle(label,key){ return '<button class="nqz-toggle" data-act="toggle" data-v="'+key+'"><span class="lbl">'+label+'</span><span class="nqz-track '+(ed[key]?'on':'')+'"><span class="nqz-knob"></span></span></button>'; }
                var timeIn = ed.timeMode!=='none' ?
                    '<label style="display:flex;align-items:center;gap:8px;margin-top:4px"><input class="nqz-in" type="number" style="width:90px" data-input="timeValue" value="'+esc(ed.timeValue)+'"><span style="font-size:12.5px;color:#7b868a">'+(ed.timeMode==='total'?'دقیقه (کل آزمون)':'ثانیه (هر سوال)')+'</span></label>' : '';
                rail += '<div class="nqz-settings nqz-scroll">'+
                    '<label class="nqz-field"><span class="nqz-flabel">دسته‌بندی</span><input class="nqz-in" data-input="category" value="'+esc(ed.category)+'" placeholder="مثلاً: ریاضی پایه"></label>'+
                    '<div class="nqz-field"><span class="nqz-flabel">زمان‌بندی</span><div class="nqz-segrow">'+seg('total','کل آزمون')+seg('perQuestion','هر سوال')+seg('none','بدون زمان')+'</div>'+timeIn+'</div>'+
                    '<label class="nqz-field"><span class="nqz-flabel">حد نصاب قبولی (٪)</span><input class="nqz-in" type="number" data-input="passScore" value="'+esc(ed.passScore)+'"></label>'+
                    '<div style="display:flex;flex-direction:column;gap:10px">'+toggle('برزدن ترتیب سوال‌ها','shuffleQ')+toggle('برزدن ترتیب گزینه‌ها','shuffleOpt')+toggle('نمایش پاسخ صحیح در پایان','showAnswers')+toggle('نمایش توضیح پاسخ‌ها','showExp')+'</div>'+
                    '<label class="nqz-field"><span class="nqz-flabel">اتصال به دوره</span>'+
                        '<select class="nqz-in" data-input="courseId">'+courseOpts+'</select>'+
                        '<span class="nqz-hint">قبولی در این آزمون برای صدور گواهی دوره لازم می‌شود.</span></label>'+
                    '</div>';
            }

            var main = renderQuestionEditor(ed);

            return top + '<div class="nqz-elayout"><aside class="nqz-rail">'+rail+'</aside><main class="nqz-main">'+main+'</main></div>';
        }

        function renderQuestionEditor(ed){
            var q = selQuestion();
            if (!q){
                return '<div class="nqz-empty"><span class="ic">'+ICON.list+'</span>'+
                    '<span style="font-size:15px;font-weight:600">سوالی برای ویرایش انتخاب نشده</span>'+
                    '<span style="font-size:13px">از پنل کناری یک سوال اضافه یا انتخاب کنید.</span></div>';
            }
            var idx = ed.questions.indexOf(q);
            var html = '<div class="nqz-mpad">'+
                '<div class="nqz-mhead"><div class="nqz-mhead-l"><span class="nqz-mnum">'+fa(idx+1)+'</span>'+
                    '<span class="nqz-mtype">'+typeLabel(q.type)+'</span></div>'+
                    '<label class="nqz-points">بارم<input class="nqz-in" type="number" data-input="q.points" value="'+esc(q.points)+'"></label></div>'+
                '<div class="nqz-block"><span class="nqz-flabel">صورت سوال</span><textarea class="nqz-in" rows="2" data-input="q.text" placeholder="متن سوال را بنویسید…">'+esc(q.text)+'</textarea></div>'+
                '<div class="nqz-block"><span class="nqz-flabel">پیوست تصویر / ویدیو (اختیاری)</span><input class="nqz-in" data-input="q.media" value="'+esc(q.media)+'" placeholder="آدرس URL تصویر یا ویدیو"></div>';

            if (q.type==='choice'||q.type==='multi'){
                var multi = q.type==='multi';
                var opts='';
                (q.options||[]).forEach(function(o){
                    var on = (q.correct||[]).indexOf(o.id)>=0;
                    opts += '<div class="nqz-opt">'+
                        '<button class="nqz-mark '+(multi?'box':'radio')+' '+(on?'on':'')+'" data-act="mark" data-id="'+o.id+'" title="پاسخ صحیح">'+(on?ICON.check:'')+'</button>'+
                        '<input class="nqz-in" data-input="opt.'+o.id+'" value="'+esc(o.text)+'" placeholder="متن گزینه">'+
                        '<button class="nqz-optdel" data-act="rmopt" data-id="'+o.id+'">'+ICON.trashSm+'</button></div>';
                });
                html += '<div class="nqz-block"><span class="nqz-flabel">'+(multi?'گزینه‌ها (چند پاسخ صحیح ممکن است)':'گزینه‌ها (یک پاسخ صحیح)')+'</span>'+
                    opts+'<button class="nqz-addopt" data-act="addopt">'+ICON.plus+' افزودن گزینه</button></div>';
            } else if (q.type==='tf'){
                var tf='';
                (q.options||[]).forEach(function(o){
                    var on=(q.correct||[]).indexOf(o.id)>=0;
                    tf += '<button class="nqz-tfbtn '+(on?'on':'')+'" data-act="mark" data-id="'+o.id+'">'+(on?ICON.check:'')+' '+esc(o.text)+'</button>';
                });
                html += '<div class="nqz-block"><span class="nqz-flabel">پاسخ صحیح را انتخاب کنید</span><div class="nqz-tf">'+tf+'</div></div>';
            } else if (q.type==='fill'){
                html += '<div class="nqz-block"><span class="nqz-flabel">پاسخ‌های صحیح (با ، جدا کنید)</span><input class="nqz-in" data-input="q.answerText" value="'+esc(q.answerText||'')+'" placeholder="مثلاً: تهران، پایتخت ایران"></div>';
            } else if (q.type==='essay'){
                html += '<div class="nqz-block"><div class="nqz-essaynote"><span style="color:#d39a2b;flex:none">'+ICON.info+'</span><span>سوال تشریحی به‌صورت دستی تصحیح می‌شود. می‌توانید یک پاسخ نمونه برای راهنمای تصحیح وارد کنید.</span></div></div>'+
                    '<div class="nqz-block"><span class="nqz-flabel">پاسخ نمونه (اختیاری)</span><textarea class="nqz-in" rows="3" data-input="q.answerText" placeholder="پاسخ نمونه برای تصحیح‌کننده…">'+esc(q.answerText||'')+'</textarea></div>';
            } else if (q.type==='match'){
                var pairs='';
                (q.pairs||[]).forEach(function(p,i){
                    pairs += '<div class="nqz-pair"><input class="nqz-in" data-input="pair.'+i+'.left" value="'+esc(p.left)+'" placeholder="مورد">'+
                        '<span style="color:#a9b3b8;flex:none">'+ICON.link+'</span>'+
                        '<input class="nqz-in" data-input="pair.'+i+'.right" value="'+esc(p.right)+'" placeholder="تطبیق صحیح">'+
                        '<button class="nqz-optdel" data-act="rmpair" data-i="'+i+'">'+ICON.trashSm+'</button></div>';
                });
                html += '<div class="nqz-block"><span class="nqz-flabel">جفت‌های تطبیق (ستون راست با ستون چپ)</span>'+pairs+
                    '<button class="nqz-addopt" data-act="addpair">'+ICON.plus+' افزودن جفت</button></div>';
            }

            html += '<div class="nqz-block" style="margin-bottom:0"><span class="nqz-flabel">توضیح پاسخ صحیح (پس از آزمون نمایش داده می‌شود)</span><textarea class="nqz-in" rows="2" data-input="q.explanation" placeholder="توضیح اختیاری…">'+esc(q.explanation)+'</textarea></div></div>';
            return html;
        }

        /* ---------- render + events ---------- */
        function render(){
            ROOT.innerHTML = state.screen==='editor' ? renderEditor() : renderList();
        }

        ROOT.addEventListener('click', function(e){
            var t = e.target.closest('[data-act]');
            if (!t) return;
            var act = t.getAttribute('data-act');
            var id = t.getAttribute('data-id');
            var v = t.getAttribute('data-v');

            if (act==='filter'){ state.catFilter=v; render(); }
            else if (act==='new'){ createQuiz(); }
            else if (act==='edit'){ openEditor(id); }
            else if (act==='back'){ state.screen='list'; render(); }
            else if (act==='dup'){ duplicateQuiz(id); }
            else if (act==='del'){ deleteQuiz(id); }
            else if (act==='preview'){ openPreview(id); }
            else if (act==='copysc'){ copyText(v); }
            else if (act==='railtab'){ state.railTab=v; render(); }
            else if (act==='selq'){ state.selQ=id; render(); }
            else if (act==='delq'){ e.stopPropagation(); delQ(id); }
            else if (act==='toggleadd'){ state.addMenu=!state.addMenu; render(); }
            else if (act==='addq'){ addQ(v); }
            else if (act==='timemode'){ updQuiz({timeMode:v}); render(); }
            else if (act==='toggle'){ var ed=editing(); if(ed){ updQuiz({}); ed[v]=!ed[v]; touch(); render(); } }
            else if (act==='mark'){ markCorrect(id); }
            else if (act==='addopt'){ addOption(); }
            else if (act==='rmopt'){ rmOption(id); }
            else if (act==='addpair'){ addPair(); }
            else if (act==='rmpair'){ rmPair(parseInt(t.getAttribute('data-i'),10)); }
        });

        // Live input binding (mutates model without re-render to preserve focus).
        ROOT.addEventListener('input', function(e){
            var el = e.target.closest('[data-input]');
            if (!el) return;
            var key = el.getAttribute('data-input');
            var val = el.value;
            var ed = editing();
            if (key==='title'){ if(ed){ ed.title=val; touch(); } return; }
            if (key==='category'){ if(ed){ ed.category=val; touch(); } return; }
            if (key==='timeValue'){ if(ed){ ed.timeValue=parseInt(val,10)||0; touch(); } return; }
            if (key==='passScore'){ if(ed){ ed.passScore=parseInt(val,10)||0; touch(); } return; }
            if (key==='courseId'){ if(ed){ ed.courseId=parseInt(val,10)||0; touch(); } return; }
            var q = selQuestion();
            if (!q) return;
            if (key==='q.points'){ q.points=parseFloat(val)||0; touch(); updateTotals(); }
            else if (key==='q.text'){ q.text=val; touch(); }
            else if (key==='q.media'){ q.media=val; touch(); }
            else if (key==='q.answerText'){ q.answerText=val; touch(); }
            else if (key==='q.explanation'){ q.explanation=val; touch(); }
            else if (key.indexOf('opt.')===0){ var oid=key.slice(4); (q.options||[]).forEach(function(o){ if(o.id===oid){ o.text=val; } }); touch(); }
            else if (key.indexOf('pair.')===0){ var parts=key.split('.'); var pi=parseInt(parts[1],10); var side=parts[2]; if(q.pairs&&q.pairs[pi]){ q.pairs[pi][side]=val; touch(); } }
        });

        // changing the linked-course <select> fires 'change' not 'input' in some browsers
        ROOT.addEventListener('change', function(e){
            var el = e.target.closest('[data-input="courseId"]');
            if (!el) return;
            var ed = editing(); if(ed){ ed.courseId=parseInt(el.value,10)||0; touch(); }
        });

        function updateTotals(){
            var ed = editing(); if(!ed) return;
            var tot = ROOT.querySelector('.nqz-railtotals');
            if (tot){ var pts=ed.questions.reduce(function(a,q){return a+(+q.points||0);},0); tot.innerHTML='<span>'+fa(ed.questions.length)+' سوال</span><span>مجموع '+fa(pts)+' نمره</span>'; }
        }

        function markCorrect(oid){
            var q=selQuestion(); if(!q) return;
            var correct;
            if (q.type==='multi'){ correct=(q.correct||[]).indexOf(oid)>=0 ? q.correct.filter(function(c){return c!==oid;}) : (q.correct||[]).concat([oid]); }
            else { correct=[oid]; }
            updQ(q.id,{correct:correct}); render();
        }
        function addOption(){ var q=selQuestion(); if(!q) return; q.options=(q.options||[]).concat([{id:uid(),text:''}]); touch(); render(); }
        function rmOption(oid){ var q=selQuestion(); if(!q) return; q.options=(q.options||[]).filter(function(o){return o.id!==oid;}); q.correct=(q.correct||[]).filter(function(c){return c!==oid;}); touch(); render(); }
        function addPair(){ var q=selQuestion(); if(!q) return; q.pairs=(q.pairs||[]).concat([{left:'',right:''}]); touch(); render(); }
        function rmPair(i){ var q=selQuestion(); if(!q) return; q.pairs=(q.pairs||[]).filter(function(_,idx){return idx!==i;}); touch(); render(); }

        /* ---------- quiz CRUD over AJAX ---------- */
        function createQuiz(){
            ajax('nias_quiz_create').then(function(res){
                if (res&&res.success&&res.data&&res.data.quiz){ quizzes.unshift(res.data.quiz); openEditor(res.data.quiz.id); }
                else { toast('ساخت آزمون ناموفق بود'); }
            });
        }
        function openEditor(id){
            var q=byId(id); if(!q) return;
            state.screen='editor'; state.editId=id; state.railTab='questions'; state.addMenu=false;
            state.selQ = q.questions[0]?q.questions[0].id:null;
            render();
        }
        function duplicateQuiz(id){
            ajax('nias_quiz_duplicate',{quiz_id:id}).then(function(res){
                if (res&&res.success&&res.data&&res.data.quiz){ quizzes.unshift(res.data.quiz); render(); toast('کپی شد'); }
            });
        }
        function deleteQuiz(id){
            if (!window.confirm('این آزمون حذف شود؟')) return;
            ajax('nias_quiz_delete',{quiz_id:id}).then(function(res){
                if (res&&res.success){ quizzes=quizzes.filter(function(q){return String(q.id)!==String(id);}); if(String(state.editId)===String(id)){ state.screen='list'; } render(); toast('حذف شد'); }
            });
        }
        function copyText(txt){
            if (navigator.clipboard){ navigator.clipboard.writeText(txt).then(function(){toast('کپی شد');}); }
            else { var ta=document.createElement('textarea'); ta.value=txt; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');toast('کپی شد');}catch(e){} document.body.removeChild(ta); }
        }

        /* ---------- preview (client-side grading) ---------- */
        function openPreview(id){
            var q=byId(id); if(!q) return;
            if (!q.questions.length){ toast('این آزمون سوالی ندارد'); return; }
            if (!window.NiasQuizStudent){ toast('اجزای پیش‌نمایش بارگذاری نشد'); return; }
            var ov=document.createElement('div'); ov.className='nqz-ov';
            ov.innerHTML='<div class="nqz-ov-box"><div class="nqz-ov-bar"><b>پیش‌نمایش: '+esc(q.title)+'</b><button class="nqz-ov-x" data-close>×</button></div><div class="nqz-ov-body"><div id="nqz-prev-mount"></div></div></div>';
            document.body.appendChild(ov);
            ov.addEventListener('click', function(e){ if(e.target===ov||e.target.hasAttribute('data-close')){ document.body.removeChild(ov); } });
            window.NiasQuizStudent.mount(ov.querySelector('#nqz-prev-mount'), q, { mode:'local' });
        }

        render();
    })();
    </script>
    <?php
}
