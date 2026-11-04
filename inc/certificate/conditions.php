<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate conditions (شرایط دریافت مدرک).
 *
 * Global rules come from the certificate settings page; a product can
 * override them from the "مدرک دوره" meta box on its edit screen. Defaults
 * reproduce the pre-1.4 behaviour exactly: completed or processing orders
 * count, and a linked quiz must be passed. Every other rule is off.
 *
 * @package nias-course-widget
 */

/** Keys of the overridable rules and their defaults. */
function nias_cert_condition_defaults()
{
    return array(
        'order_status' => 'completed_processing', // or 'completed'
        'quiz'         => 'on',
        'min_progress' => 0,
        'wait_days'    => 0,
        'require_name' => 'off',
        'approval'     => 'off',
    );
}

/**
 * Effective rules for a product (global settings, then product override).
 *
 * @param int $product_id
 * @return array
 */
function nias_cert_conditions_for($product_id)
{
    $rules = array();
    foreach (nias_cert_condition_defaults() as $key => $default) {
        $rules[$key] = nias_cert_setting('certificate_cond_' . $key, $default);
    }

    $pid = nias_cert_parent_product_id($product_id);
    if ($pid && get_post_meta($pid, '_nias_cert_cond_override', true) === 'on') {
        $custom = get_post_meta($pid, '_nias_cert_conditions', true);
        if (is_array($custom)) {
            foreach ($rules as $key => $val) {
                if (isset($custom[$key]) && $custom[$key] !== '') {
                    $rules[$key] = $custom[$key];
                }
            }
        }
    }

    $rules['min_progress'] = max(0, min(100, (int) $rules['min_progress']));
    $rules['wait_days']    = max(0, (int) $rules['wait_days']);
    return apply_filters('nias_certificate_conditions', $rules, $product_id);
}

/** Variations share their parent's certificate settings. */
function nias_cert_parent_product_id($product_id)
{
    $product_id = (int) $product_id;
    if (!$product_id) {
        return 0;
    }
    $parent = (int) wp_get_post_parent_id($product_id);
    return ($parent && get_post_type($product_id) === 'product_variation') ? $parent : $product_id;
}

/* -------------------------------------------------------------------------
 * Server-side course progress
 * ---------------------------------------------------------------------- */

/**
 * A user's overall progress (0–100) in a course, as reported by the modern
 * course view.
 */
function nias_cert_user_progress($user_id, $product_id)
{
    $all = get_user_meta((int) $user_id, '_nias_course_progress', true);
    $pid = (int) $product_id;
    if (is_array($all) && isset($all[$pid])) {
        return (int) $all[$pid];
    }
    $parent = nias_cert_parent_product_id($pid);
    return (is_array($all) && isset($all[$parent])) ? (int) $all[$parent] : 0;
}

add_action('wp_ajax_nias_course_progress_sync', 'nias_cert_ajax_progress_sync');
function nias_cert_ajax_progress_sync()
{
    if (!check_ajax_referer('nias_course_progress', 'nonce', false)) {
        wp_send_json_error();
    }
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $pct        = isset($_POST['pct']) ? max(0, min(100, (int) $_POST['pct'])) : 0;
    if (!$product_id || get_post_type($product_id) !== 'product') {
        wp_send_json_error();
    }
    $user_id = get_current_user_id();
    $all     = get_user_meta($user_id, '_nias_course_progress', true);
    $all     = is_array($all) ? $all : array();
    // Progress only moves forward.
    if (!isset($all[$product_id]) || $pct > (int) $all[$product_id]) {
        $all[$product_id] = $pct;
        update_user_meta($user_id, '_nias_course_progress', $all);
        do_action('nias_course_progress_updated', $user_id, $product_id, $pct);
    }
    wp_send_json_success(array('pct' => (int) $all[$product_id]));
}

/* -------------------------------------------------------------------------
 * Evaluation
 * ---------------------------------------------------------------------- */

/**
 * Evaluate the rules for one purchased course.
 *
 * @param int   $user_id
 * @param int   $product_id
 * @param array $ctx order_status (wc status of the best order), order_date (Y-m-d)
 * @return array{ok:bool,checks:array<int,array{key:string,label:string,ok:bool,hint:string}>,rules:array}
 */
function nias_cert_evaluate($user_id, $product_id, $ctx)
{
    $rules  = nias_cert_conditions_for($product_id);
    $checks = array();

    // Purchase / order status.
    $status_ok = $rules['order_status'] === 'completed'
        ? ($ctx['order_status'] === 'completed')
        : in_array($ctx['order_status'], array('completed', 'processing'), true);
    $checks[] = array(
        'key'   => 'order',
        'label' => $rules['order_status'] === 'completed'
            ? __('تکمیل سفارش خرید دوره', 'nias-course-widget')
            : __('خرید دوره', 'nias-course-widget'),
        'ok'    => $status_ok,
        'hint'  => $status_ok ? '' : __('سفارش شما هنوز تکمیل نشده است.', 'nias-course-widget'),
    );

    // Linked quiz.
    if ($rules['quiz'] === 'on' && function_exists('nias_quiz_for_course') && nias_quiz_for_course($product_id)) {
        $passed   = nias_quiz_course_gate_passed($user_id, $product_id);
        $checks[] = array(
            'key'   => 'quiz',
            'label' => __('قبولی در آزمون دوره', 'nias-course-widget'),
            'ok'    => $passed,
            'hint'  => $passed ? '' : __('ابتدا در آزمون پایانی دوره قبول شوید.', 'nias-course-widget'),
        );
    }

    // Progress.
    if ($rules['min_progress'] > 0) {
        $pct      = nias_cert_user_progress($user_id, $product_id);
        $ok       = $pct >= $rules['min_progress'];
        $checks[] = array(
            'key'   => 'progress',
            /* translators: %d: required percent */
            'label' => sprintf(__('مشاهدهٔ حداقل %d٪ از دوره', 'nias-course-widget'), $rules['min_progress']),
            'ok'    => $ok,
            /* translators: %d: current percent */
            'hint'  => $ok ? '' : sprintf(__('پیشرفت فعلی شما %d٪ است.', 'nias-course-widget'), $pct),
        );
    }

    // Waiting period.
    if ($rules['wait_days'] > 0) {
        $bought   = !empty($ctx['order_date']) ? strtotime($ctx['order_date']) : 0;
        $ready_ts = $bought ? $bought + $rules['wait_days'] * DAY_IN_SECONDS : 0;
        $ok       = $bought && current_time('timestamp') >= $ready_ts;
        $left     = $ok || !$bought ? 0 : (int) ceil(($ready_ts - current_time('timestamp')) / DAY_IN_SECONDS);
        $checks[] = array(
            'key'   => 'wait',
            /* translators: %d: days */
            'label' => sprintf(__('گذشت %d روز از خرید دوره', 'nias-course-widget'), $rules['wait_days']),
            'ok'    => $ok,
            /* translators: %d: days left */
            'hint'  => $ok ? '' : sprintf(__('%d روز دیگر باقی مانده است.', 'nias-course-widget'), $left),
        );
    }

    // Name on file.
    if ($rules['require_name'] === 'on') {
        $ok       = nias_cert_user_has_real_name($user_id);
        $checks[] = array(
            'key'   => 'name',
            'label' => __('ثبت نام و نام خانوادگی در حساب کاربری', 'nias-course-widget'),
            'ok'    => $ok,
            'hint'  => $ok ? '' : __('نام شما روی مدرک چاپ می‌شود؛ آن را کامل کنید.', 'nias-course-widget'),
        );
    }

    $all_ok = true;
    foreach ($checks as $c) {
        if (!$c['ok']) {
            $all_ok = false;
            break;
        }
    }

    $result = array('ok' => $all_ok, 'checks' => $checks, 'rules' => $rules);
    return apply_filters('nias_certificate_evaluate', $result, $user_id, $product_id, $ctx);
}

/* -------------------------------------------------------------------------
 * Name form (for the "require name" rule)
 * ---------------------------------------------------------------------- */

add_action('init', 'nias_cert_handle_name_form');
function nias_cert_handle_name_form()
{
    if (empty($_POST['nias_cert_name_submit']) || !is_user_logged_in()) {
        return;
    }
    if (!isset($_POST['nias_cert_name_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_cert_name_nonce'])), 'nias_cert_name')) {
        return;
    }
    $uid   = get_current_user_id();
    $first = isset($_POST['nias_cert_first_name']) ? sanitize_text_field(wp_unslash($_POST['nias_cert_first_name'])) : '';
    $last  = isset($_POST['nias_cert_last_name']) ? sanitize_text_field(wp_unslash($_POST['nias_cert_last_name'])) : '';
    if ($first !== '' && $last !== '') {
        update_user_meta($uid, 'first_name', $first);
        update_user_meta($uid, 'last_name', $last);
        wp_safe_redirect(add_query_arg('nias_cert_name', 'saved', wp_get_referer() ? wp_get_referer() : home_url('/')));
        exit;
    }
}

/** Small form shown above the certificate list when the name is missing. */
function nias_cert_name_form_html($user_id)
{
    $first = (string) get_user_meta($user_id, 'first_name', true);
    $last  = (string) get_user_meta($user_id, 'last_name', true);
    ob_start();
    ?>
    <form method="post" class="nias-cert-nameform">
        <div class="nias-cert-nameform-t"><?php echo esc_html__('نام خود را برای درج روی مدرک وارد کنید', 'nias-course-widget'); ?></div>
        <div class="nias-cert-nameform-row">
            <input type="text" name="nias_cert_first_name" value="<?php echo esc_attr($first); ?>" placeholder="<?php echo esc_attr__('نام', 'nias-course-widget'); ?>" required>
            <input type="text" name="nias_cert_last_name" value="<?php echo esc_attr($last); ?>" placeholder="<?php echo esc_attr__('نام خانوادگی', 'nias-course-widget'); ?>" required>
            <?php wp_nonce_field('nias_cert_name', 'nias_cert_name_nonce'); ?>
            <button type="submit" name="nias_cert_name_submit" value="1" class="button"><?php echo esc_html__('ذخیره نام', 'nias-course-widget'); ?></button>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Product meta box
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes_product', 'nias_cert_add_product_metabox');
function nias_cert_add_product_metabox()
{
    if (!nias_cert_feature_on()) {
        return;
    }
    add_meta_box('nias_cert_product', __('مدرک دوره (نیاس)', 'nias-course-widget'), 'nias_cert_render_product_metabox', 'product', 'side', 'default');
}

function nias_cert_render_product_metabox($post)
{
    wp_nonce_field('nias_cert_product', 'nias_cert_product_nonce');
    $tpl      = (int) get_post_meta($post->ID, '_nias_cert_template', true);
    $override = get_post_meta($post->ID, '_nias_cert_cond_override', true) === 'on';
    $custom   = get_post_meta($post->ID, '_nias_cert_conditions', true);
    $custom   = is_array($custom) ? $custom : array();
    $rules    = array_merge(nias_cert_condition_defaults(), $custom);
    $templates = function_exists('nias_cert_templates_list') ? nias_cert_templates_list() : array();
    ?>
    <div class="nias-cert-mb" style="display:flex;flex-direction:column;gap:10px">
        <label><strong><?php echo esc_html__('قالب مدرک', 'nias-course-widget'); ?></strong><br>
            <select name="nias_cert_template" style="width:100%">
                <option value="0"><?php echo esc_html__('پیش‌فرض (از تنظیمات مدرک)', 'nias-course-widget'); ?></option>
                <?php foreach ($templates as $id => $title) : ?>
                    <option value="<?php echo (int) $id; ?>" <?php selected($tpl, $id); ?>><?php echo esc_html($title); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="description"><?php echo esc_html__('فقط در حالت «طراح پیشرفته» استفاده می‌شود.', 'nias-course-widget'); ?></span>
        </label>
        <label><input type="checkbox" name="nias_cert_cond_override" value="on" <?php checked($override); ?> onchange="document.getElementById('nias-cert-mb-rules').style.display=this.checked?'flex':'none'"> <?php echo esc_html__('شرایط اختصاصی برای این دوره', 'nias-course-widget'); ?></label>
        <div id="nias-cert-mb-rules" style="display:<?php echo $override ? 'flex' : 'none'; ?>;flex-direction:column;gap:8px;padding:8px;background:#f6f7f7;border-radius:6px">
            <label><?php echo esc_html__('وضعیت سفارش', 'nias-course-widget'); ?><br>
                <select name="nias_cert_cond[order_status]" style="width:100%">
                    <option value="completed_processing" <?php selected($rules['order_status'], 'completed_processing'); ?>><?php echo esc_html__('تکمیل‌شده یا در حال انجام', 'nias-course-widget'); ?></option>
                    <option value="completed" <?php selected($rules['order_status'], 'completed'); ?>><?php echo esc_html__('فقط تکمیل‌شده', 'nias-course-widget'); ?></option>
                </select>
            </label>
            <label><input type="checkbox" name="nias_cert_cond[quiz]" value="on" <?php checked($rules['quiz'], 'on'); ?>> <?php echo esc_html__('الزام قبولی در آزمون متصل', 'nias-course-widget'); ?></label>
            <label><?php echo esc_html__('حداقل درصد پیشرفت (۰ = غیرفعال)', 'nias-course-widget'); ?><br>
                <input type="number" min="0" max="100" name="nias_cert_cond[min_progress]" value="<?php echo (int) $rules['min_progress']; ?>" style="width:100%"></label>
            <label><?php echo esc_html__('حداقل روز پس از خرید (۰ = غیرفعال)', 'nias-course-widget'); ?><br>
                <input type="number" min="0" name="nias_cert_cond[wait_days]" value="<?php echo (int) $rules['wait_days']; ?>" style="width:100%"></label>
            <label><input type="checkbox" name="nias_cert_cond[require_name]" value="on" <?php checked($rules['require_name'], 'on'); ?>> <?php echo esc_html__('الزام ثبت نام و نام خانوادگی', 'nias-course-widget'); ?></label>
            <label><input type="checkbox" name="nias_cert_cond[approval]" value="on" <?php checked($rules['approval'], 'on'); ?>> <?php echo esc_html__('نیاز به تأیید دستی مدیر', 'nias-course-widget'); ?></label>
        </div>
    </div>
    <?php
}

add_action('save_post_product', 'nias_cert_save_product_metabox');
function nias_cert_save_product_metabox($post_id)
{
    if (!isset($_POST['nias_cert_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_cert_product_nonce'])), 'nias_cert_product')) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
        return;
    }
    update_post_meta($post_id, '_nias_cert_template', isset($_POST['nias_cert_template']) ? (int) $_POST['nias_cert_template'] : 0);
    update_post_meta($post_id, '_nias_cert_cond_override', !empty($_POST['nias_cert_cond_override']) ? 'on' : 'off');

    $raw   = isset($_POST['nias_cert_cond']) ? (array) wp_unslash($_POST['nias_cert_cond']) : array();
    $rules = array(
        'order_status' => (isset($raw['order_status']) && $raw['order_status'] === 'completed') ? 'completed' : 'completed_processing',
        'quiz'         => !empty($raw['quiz']) ? 'on' : 'off',
        'min_progress' => isset($raw['min_progress']) ? max(0, min(100, (int) $raw['min_progress'])) : 0,
        'wait_days'    => isset($raw['wait_days']) ? max(0, (int) $raw['wait_days']) : 0,
        'require_name' => !empty($raw['require_name']) ? 'on' : 'off',
        'approval'     => !empty($raw['approval']) ? 'on' : 'off',
    );
    update_post_meta($post_id, '_nias_cert_conditions', $rules);
}
