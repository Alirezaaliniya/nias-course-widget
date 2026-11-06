<?php
// Make sure we are in WordPress context
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native settings pages (Carbon Fields replacement).
 *
 * Data is read/written through the Carbon-compatible helpers defined in
 * inc/nias-meta.php so previously stored options keep working untouched.
 */

/* -------------------------------------------------------------------------
 * Field definitions (name => type)
 * ---------------------------------------------------------------------- */

function nias_settings_main_fields()
{
    return array(
        'nias_two_way_verification'   => 'radio',
        'nias_course_account_display' => 'radio',
        'nias_modern_account'         => 'radio',
        'nias_course_certificate'     => 'radio',
        'nias_spotplayer_enabled'     => 'radio',
        'nias_instructors_enabled'    => 'radio',
        'nias_quiz_enabled'           => 'radio',
        'nias_course_cpt'             => 'radio',
        'nias_qa_enabled'             => 'radio',
        'nias_qa_scope'               => 'text',
        'nias_qa_ask'                 => 'text',
        'nias_qa_view'                => 'text',
        'nias_qa_moderation'          => 'radio',
        'nias_qa_notify'              => 'radio',
        'nias_meta_enabled'           => 'radio',
        'nias_modern_course'          => 'radio',
        'nias_lock_private_preview'   => 'radio',
        'nias_lock_private_content'   => 'radio',
        'nias_lock_private_attachments' => 'radio',
    );
}

/**
 * Which parts of a private lesson are locked for users who have not purchased.
 *
 * Each part maps to one underlying lesson field, so the rule is consistent
 * across every view (modern course view, account view and the Elementor widget):
 *   - preview     => lesson_preview_video  (پیش‌نمایش / ویدیوی درس)
 *   - content     => lesson_content        (توضیحات / محتوای درس)
 *   - attachments => lesson_download       (منابع و پیوست / فایل دانلودی)
 *
 * Default is "locked" (on) when the option was never saved, so existing sites
 * keep the previous fully-locked behaviour untouched.
 *
 * @param string $part preview|content|attachments
 * @return bool true when that part must be hidden from non-purchasers.
 */
function nias_course_lock_part($part)
{
    $map = array(
        'preview'     => 'nias_lock_private_preview',
        'content'     => 'nias_lock_private_content',
        'attachments' => 'nias_lock_private_attachments',
    );
    if (!isset($map[$part]) || !function_exists('carbon_get_theme_option')) {
        return true;
    }
    $val = carbon_get_theme_option($map[$part]);
    if ($val === '' || $val === false || $val === null) {
        return true; // never saved → keep the default locked behaviour
    }
    return ($val === 'on');
}

function nias_settings_certificate_fields()
{
    return array(
        'certificate_display_type'        => 'radio',
        'certificate_selected_products'   => 'multiselect',
        'certificate_display_page'        => 'select',
        'certificate_selected_categories' => 'multiselect',
        'certificate_watermark'           => 'image',
        'certificate_header_bg'           => 'image',
        'certificate_footer_bg'           => 'image',
        'certificate_icon'                => 'image',
        'certificate_first_title'         => 'text',
        'certificate_before_name_title'   => 'text',
        'certificate_after_name_title'    => 'text',
        'certificate_show_date'           => 'radio',
        'certificate_date_source'         => 'radio',
        'certificate_manual_date'         => 'date',
        'certificate_seal_image'          => 'image',
        'certificate_signature_image'     => 'image',
        'certificate_signer_name'         => 'text',
        // 1.4: designer, verification and conditions.
        'certificate_design_mode'         => 'radio',
        'certificate_default_template'    => 'select',
        'certificate_verify_page'         => 'select',
        'certificate_verify_mask_name'    => 'radio',
        'certificate_cond_order_status'   => 'radio',
        'certificate_cond_quiz'           => 'radio',
        'certificate_cond_min_progress'   => 'number',
        'certificate_cond_wait_days'      => 'number',
        'certificate_cond_require_name'   => 'radio',
        'certificate_cond_approval'       => 'radio',
        'certificate_account_endpoint'    => 'radio',
        'certificate_account_slug'        => 'text',
        'certificate_account_title'       => 'text',
        // 1.5.1: frozen designs + e-mail on issue.
        'certificate_freeze_design'       => 'radio',
        'certificate_email_on_issue'      => 'radio',
        'certificate_email_attach'        => 'radio',
        'certificate_email_subject'       => 'text',
        'certificate_email_body'          => 'textarea',
    );
}

/* -------------------------------------------------------------------------
 * Menu registration
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_course_register_settings_pages');
function nias_course_register_settings_pages()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    add_menu_page(
        __('تنظیمات دوره ساز نیاس', 'nias-course-widget'),
        __('دوره ساز نیاس', 'nias-course-widget'),
        'manage_options',
        'nias-course-settings',
        'nias_course_render_main_settings',
        NIASADMIN_URL . '/nias-course.png'
    );

    // Certificate sub-page only appears when the feature is enabled.
    if (carbon_get_theme_option('nias_course_certificate') === 'on') {
        add_submenu_page(
            'nias-course-settings',
            __('تنظیمات مدرک دوره', 'nias-course-widget'),
            __('تنظیمات مدرک دوره', 'nias-course-widget'),
            'manage_options',
            'nias-course-certificate',
            'nias_course_render_certificate_settings'
        );
    }

    // SpotPlayer license sub-page only appears when the feature is enabled.
    if (carbon_get_theme_option('nias_spotplayer_enabled') === 'on' && function_exists('nias_spot_render_license_settings')) {
        add_submenu_page(
            'nias-course-settings',
            __('لایسنس اسپات پلیر', 'nias-course-widget'),
            __('لایسنس اسپات پلیر', 'nias-course-widget'),
            'manage_options',
            'nias-spotplayer-license',
            'nias_spot_render_license_settings'
        );
    }
}

/* -------------------------------------------------------------------------
 * Save handler
 * ---------------------------------------------------------------------- */

/**
 * Persist submitted theme options in the Carbon Fields storage format.
 *
 * @param array $fields name => type
 */
function nias_course_save_theme_options($fields)
{
    foreach ($fields as $name => $type) {
        if ($type === 'multiselect') {
            $values = isset($_POST[$name]) ? (array) wp_unslash($_POST[$name]) : array();
            $values = array_map('sanitize_text_field', $values);
            nias_cf_write_option_multivalue($name, $values);
            continue;
        }

        $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
        switch ($type) {
            case 'image':
                $value = esc_url_raw($raw);
                break;
            case 'number':
                $value = (string) max(0, (int) $raw);
                break;
            case 'textarea':
                $value = sanitize_textarea_field($raw);
                break;
            default:
                $value = sanitize_text_field($raw);
                break;
        }
        update_option('_' . $name, $value);
    }
}

/* -------------------------------------------------------------------------
 * Render helpers
 * ---------------------------------------------------------------------- */

function nias_field_row($label, $help, $control, $extra_attr = '')
{
    echo '<tr class="nias-field-row" ' . $extra_attr . '>';
    echo '<th scope="row">' . esc_html($label) . '</th>';
    echo '<td>' . $control;
    if ($help) {
        echo '<p class="description">' . wp_kses_post($help) . '</p>';
    }
    echo '</td></tr>';
}

function nias_render_radio($name, $label, $options, $default, $help, $classes = '', $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false) {
        $current = $default;
    }
    $control = '<fieldset class="' . esc_attr($classes) . '">';
    foreach ($options as $val => $text) {
        $control .= '<label style="margin-left:15px;"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" ' . checked($current, $val, false) . ' data-nias-field="' . esc_attr($name) . '"> ' . esc_html($text) . '</label> ';
    }
    $control .= '</fieldset>';
    nias_field_row($label, $help, $control, $extra_attr);
}

function nias_render_text($name, $label, $help, $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    $control = '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '">';
    nias_field_row($label, $help, $control, $extra_attr);
}

function nias_render_date($name, $label, $help, $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    $control = '<input type="text" class="regular-text nias-datepicker" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '" placeholder="YYYY-MM-DD">';
    nias_field_row($label, $help, $control, $extra_attr);
}

function nias_render_image($name, $label, $help, $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    $control  = '<div class="nias-image-field">';
    $control .= '<input type="text" class="regular-text nias-image-url" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '">';
    $control .= ' <button type="button" class="button nias-image-upload">' . esc_html__('انتخاب تصویر', 'nias-course-widget') . '</button>';
    $control .= ' <button type="button" class="button nias-image-clear">' . esc_html__('حذف', 'nias-course-widget') . '</button>';
    $control .= '<div class="nias-image-preview">';
    if ($current) {
        $control .= '<img src="' . esc_url($current) . '" style="max-width:120px;height:auto;margin-top:8px;display:block;">';
    }
    $control .= '</div></div>';
    nias_field_row($label, $help, $control, $extra_attr);
}

function nias_render_select($name, $label, $options, $help, $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    $control = '<select name="' . esc_attr($name) . '" class="regular-text">';
    foreach ($options as $val => $text) {
        $control .= '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($text) . '</option>';
    }
    $control .= '</select>';
    nias_field_row($label, $help, $control, $extra_attr);
}

function nias_render_multiselect($name, $label, $options, $help, $extra_attr = '')
{
    $current = carbon_get_theme_option($name);
    $current = is_array($current) ? array_map('strval', $current) : array();
    $control = '<select name="' . esc_attr($name) . '[]" multiple size="8" class="regular-text" style="min-width:300px;">';
    foreach ($options as $val => $text) {
        $selected = in_array((string) $val, $current, true) ? ' selected' : '';
        $control .= '<option value="' . esc_attr($val) . '"' . $selected . '>' . esc_html($text) . '</option>';
    }
    $control .= '</select>';
    nias_field_row($label, $help, $control, $extra_attr);
}

/* -------------------------------------------------------------------------
 * Main settings page
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Modern (redesigned) settings UI helpers
 * ---------------------------------------------------------------------- */

/** Convert ASCII digits to Persian digits. */
function nias_fa_digits($str)
{
    return strtr((string) $str, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
}

/** Sticky top bar with brand + version + page tabs. */
function nias_settings_topbar($active)
{
    $cert_on  = carbon_get_theme_option('nias_course_certificate') === 'on';
    $spot_on  = carbon_get_theme_option('nias_spotplayer_enabled') === 'on';
    $inst_on  = function_exists('nias_instructors_enabled') && nias_instructors_enabled();
    $quiz_on  = function_exists('nias_quiz_enabled') && nias_quiz_enabled();
    $meta_on  = function_exists('nias_meta_enabled') && nias_meta_enabled();
    $main_url = admin_url('admin.php?page=nias-course-settings');
    $cert_url = admin_url('admin.php?page=nias-course-certificate');
    $spot_url = admin_url('admin.php?page=nias-spotplayer-license');
    $inst_url = admin_url('admin.php?page=nias-course-instructors');
    $quiz_url = admin_url('admin.php?page=nias-course-quiz');
    $meta_url = admin_url('admin.php?page=nias-course-meta');
    ?>
    <div class="nias-set-bar">
        <div class="nias-set-bar-inner">
            <div class="nias-brand">
                <div class="nias-brand-logo">
                    <img src="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/logons.gif'); ?>" alt="<?php echo esc_attr__('دوره ساز نیاس', 'nias-course-widget'); ?>" width="24" height="24" style="display:block">
                </div>
                <div>
                    <div class="nias-brand-title"><?php echo esc_html__('دوره ساز نیاس', 'nias-course-widget'); ?></div>
                    <div class="nias-brand-sub"><?php echo esc_html__('پنل تنظیمات افزونه', 'nias-course-widget'); ?></div>
                </div>
            </div>
            <div><span class="nias-ver"><?php echo esc_html__('نگارش', 'nias-course-widget') . ' ' . esc_html(nias_fa_digits(NIAS_COURSE_VERSION)); ?></span></div>
        </div>
        <div class="nias-set-tabs-wrap">
            <div class="nias-set-tabs">
                <a href="<?php echo esc_url($main_url); ?>" class="nias-tab <?php echo $active === 'main' ? 'active' : ''; ?>"><?php echo esc_html__('تنظیمات اصلی', 'nias-course-widget'); ?></a>
                <?php if ($cert_on) : ?>
                    <a href="<?php echo esc_url($cert_url); ?>" class="nias-tab <?php echo $active === 'cert' ? 'active' : ''; ?>"><?php echo esc_html__('تنظیمات مدرک دوره', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-designer')); ?>" class="nias-tab <?php echo $active === 'designer' ? 'active' : ''; ?>"><?php echo esc_html__('طراح مدرک', 'nias-course-widget'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-issued')); ?>" class="nias-tab <?php echo $active === 'issued' ? 'active' : ''; ?>"><?php echo esc_html__('مدارک صادرشده', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <?php if ($spot_on) : ?>
                    <a href="<?php echo esc_url($spot_url); ?>" class="nias-tab <?php echo $active === 'spot' ? 'active' : ''; ?>"><?php echo esc_html__('لایسنس اسپات پلیر', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($inst_on) : ?>
                    <a href="<?php echo esc_url($inst_url); ?>" class="nias-tab <?php echo $active === 'instructors' ? 'active' : ''; ?>"><?php echo esc_html__('مدرسین', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($quiz_on) : ?>
                    <a href="<?php echo esc_url($quiz_url); ?>" class="nias-tab <?php echo $active === 'quiz' ? 'active' : ''; ?>"><?php echo esc_html__('آزمون‌ساز', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                    <?php $nqr_pending = function_exists('nias_quiz_pending_count') ? nias_quiz_pending_count(null) : 0; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-quiz-results')); ?>" class="nias-tab <?php echo $active === 'quizres' ? 'active' : ''; ?>"><?php echo esc_html__('نتایج و تصحیح', 'nias-course-widget'); ?><?php if ($nqr_pending) : ?> <span class="nias-tab-badge" style="background:#2563c9;color:#fff"><?php echo esc_html(nias_fa_digits($nqr_pending)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_qa_enabled') && nias_qa_enabled()) : $nqa_n = nias_qa_unanswered_count(null); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-qa')); ?>" class="nias-tab <?php echo $active === 'qa' ? 'active' : ''; ?>"><?php echo esc_html__('پرسش و پاسخ', 'nias-course-widget'); ?><?php if ($nqa_n) : ?> <span class="nias-tab-badge" style="background:#c26a1a;color:#fff"><?php echo esc_html(nias_fa_digits($nqa_n)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_inst_approval_on') && nias_inst_panel_on()) : $nir_n = nias_inst_requests_count(); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-approvals')); ?>" class="nias-tab <?php echo $active === 'approvals' ? 'active' : ''; ?>"><?php echo esc_html__('درخواست‌های مدرسین', 'nias-course-widget'); ?><?php if ($nir_n) : ?> <span class="nias-tab-badge" style="background:#b45309;color:#fff"><?php echo esc_html(nias_fa_digits($nir_n)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-player')); ?>" class="nias-tab <?php echo $active === 'player' ? 'active' : ''; ?>"><?php echo esc_html__('پلیر ویدیو', 'nias-course-widget'); ?></a>
                <?php if ($meta_on) : ?>
                    <a href="<?php echo esc_url($meta_url); ?>" class="nias-tab <?php echo $active === 'meta' ? 'active' : ''; ?>"><?php echo esc_html__('متا', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function nias_set_card_open($icon, $title)
{
    echo '<div class="nias-card"><div class="nias-card-head">' . $icon . '<div class="nias-card-title">' . esc_html($title) . '</div></div>';
}
function nias_set_card_close()
{
    echo '</div>';
}

function nias_set_saved_banner($saved)
{
    if (!$saved) {
        return;
    }
    echo '<div class="nias-saved"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m20 6-11 11-5-5" stroke="#0a8a44" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' . esc_html__('تنظیمات ذخیره شد.', 'nias-course-widget') . '</div>';
}

/** Toggle switch row (stores on/off). */
function nias_set_toggle_row($name, $title, $desc, $default, $badge = '', $wrap_attr = '')
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false) {
        $current = $default;
    }
    $on = ($current === 'on');
    ?>
    <div class="nias-row" <?php echo $wrap_attr; ?>>
        <div class="nias-row-main">
            <div class="nias-row-title"><?php echo esc_html($title); ?><?php if ($badge) echo ' <span class="nias-chip">' . esc_html($badge) . '</span>'; ?></div>
            <?php if ($desc) : ?><div class="nias-row-desc"><?php echo wp_kses_post($desc); ?></div><?php endif; ?>
        </div>
        <label class="nias-switch">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="off">
            <input type="checkbox" class="nias-switch-cb" name="<?php echo esc_attr($name); ?>" value="on" data-nias-field="<?php echo esc_attr($name); ?>" <?php checked($on); ?>>
            <span class="nias-switch-track"><span class="nias-switch-knob"></span></span>
        </label>
    </div>
    <?php
}

/** Segmented control (single value radio group). */
function nias_set_segmented($name, $options, $default)
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false) {
        $current = $default;
    }
    echo '<div class="nias-seg">';
    foreach ($options as $val => $text) {
        echo '<label class="nias-seg-item"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" data-nias-field="' . esc_attr($name) . '" ' . checked($current, $val, false) . '><span>' . esc_html($text) . '</span></label>';
    }
    echo '</div>';
}

/** Radio cards (single value radio group, card style). */
function nias_set_radio_cards($name, $options, $default)
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false) {
        $current = $default;
    }
    echo '<div class="nias-rcards">';
    foreach ($options as $val => $text) {
        echo '<label class="nias-rcard"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" data-nias-field="' . esc_attr($name) . '" ' . checked($current, $val, false) . '><span class="nias-rcard-dot"></span><span class="nias-rcard-txt">' . esc_html($text) . '</span></label>';
    }
    echo '</div>';
}

function nias_set_img_placeholder()
{
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#c1c7da" stroke-width="1.6"/><circle cx="8.5" cy="8.5" r="1.5" fill="#c1c7da"/><path d="m21 15-5-5L5 21" stroke="#c1c7da" stroke-width="1.6" stroke-linejoin="round"/></svg>';
}

/** Image upload row (media library). Reuses .nias-image-field JS hooks. */
function nias_set_image_row($name, $label, $hint)
{
    $current = carbon_get_theme_option($name);
    ?>
    <div class="nias-imgrow nias-image-field">
        <div class="nias-imgrow-info">
            <div class="nias-imgrow-label"><?php echo esc_html($label); ?></div>
            <div class="nias-imgrow-hint"><?php echo esc_html($hint); ?></div>
        </div>
        <div class="nias-imgrow-ctrl">
            <div class="nias-imgbox nias-image-preview"><?php echo $current ? '<img src="' . esc_url($current) . '">' : nias_set_img_placeholder(); ?></div>
            <input type="hidden" class="nias-image-url" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current); ?>">
            <button type="button" class="nias-btn-soft nias-image-upload"><?php echo esc_html__('انتخاب تصویر', 'nias-course-widget'); ?></button>
            <button type="button" class="nias-btn-del nias-image-clear"><?php echo esc_html__('حذف', 'nias-course-widget'); ?></button>
        </div>
    </div>
    <?php
}

function nias_set_text_field($name, $label, $placeholder = '')
{
    $current = carbon_get_theme_option($name);
    echo '<div class="nias-field"><label class="nias-flabel">' . esc_html($label) . '</label><input type="text" class="nias-input" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '" placeholder="' . esc_attr($placeholder) . '"></div>';
}

function nias_set_textarea_field($name, $label, $default = '', $rows = 8)
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false || $current === null) {
        $current = $default;
    }
    echo '<div class="nias-field"><label class="nias-flabel">' . esc_html($label) . '</label><textarea class="nias-input" style="height:auto;line-height:1.9;padding:10px 12px" rows="' . (int) $rows . '" name="' . esc_attr($name) . '">' . esc_textarea($current) . '</textarea></div>';
}

function nias_set_number_field($name, $label, $desc, $default = 0, $max = '')
{
    $current = carbon_get_theme_option($name);
    if ($current === '' || $current === false) {
        $current = $default;
    }
    echo '<div class="nias-field" style="max-width:260px"><label class="nias-flabel">' . esc_html($label) . '</label>';
    if ($desc) {
        echo '<div class="nias-fdesc">' . esc_html($desc) . '</div>';
    }
    echo '<input type="number" min="0"' . ($max !== '' ? ' max="' . esc_attr($max) . '"' : '') . ' class="nias-input" dir="ltr" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '"></div>';
}

function nias_set_date_field($name, $label, $wrap_attr = '')
{
    $current = carbon_get_theme_option($name);
    echo '<div class="nias-field" ' . $wrap_attr . ' style="max-width:240px"><label class="nias-flabel">' . esc_html($label) . '</label><input type="text" class="nias-input nias-datepicker" dir="ltr" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '" placeholder="YYYY-MM-DD"></div>';
}

function nias_set_select_field($name, $label, $desc, $options)
{
    $current = carbon_get_theme_option($name);
    echo '<div class="nias-field"><label class="nias-flabel">' . esc_html($label) . '</label>';
    if ($desc) {
        echo '<div class="nias-fdesc">' . esc_html($desc) . '</div>';
    }
    echo '<div class="nias-select-wrap"><select class="nias-select" name="' . esc_attr($name) . '" data-nias-field="' . esc_attr($name) . '">';
    foreach ($options as $val => $text) {
        echo '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($text) . '</option>';
    }
    echo '</select><span class="nias-select-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="m6 9 6 6 6-6" stroke="#8a90a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div></div>';
}

function nias_set_multiselect_field($name, $label, $options, $wrap_attr = '')
{
    $current = carbon_get_theme_option($name);
    $current = is_array($current) ? array_map('strval', $current) : array();
    echo '<div class="nias-field" ' . $wrap_attr . '><label class="nias-flabel">' . esc_html($label) . '</label>';
    echo '<select class="nias-multiselect" name="' . esc_attr($name) . '[]" multiple size="6">';
    foreach ($options as $val => $text) {
        $sel = in_array((string) $val, $current, true) ? ' selected' : '';
        echo '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($text) . '</option>';
    }
    echo '</select><div class="nias-fhint">' . esc_html__('برای انتخاب چند مورد، کلید Ctrl را نگه دارید.', 'nias-course-widget') . '</div></div>';
}

/**
 * Searchable multi-select bound to a theme option (stored like a multiselect).
 * Only the saved items are rendered; everything else is found by searching,
 * so the page never lists every product/user of the site.
 *
 * @param string $source product|quiz|user (see inc/nias-picker.php)
 */
function nias_set_picker_field($name, $label, $source, $wrap_attr = '', $hint = '')
{
    $current = carbon_get_theme_option($name);
    $current = is_array($current) ? array_values(array_filter(array_map('intval', $current))) : array();
    $values  = array();
    if ($source === 'product') {
        _prime_post_caches($current, false, false);
        foreach ($current as $id) {
            if (get_post($id) && function_exists('nias_picker_product_item')) {
                $values[] = nias_picker_product_item($id);
            }
        }
    }
    $cfg = array('source' => $source, 'multiple' => true, 'name' => $name, 'value' => $values, 'placeholder' => __('برای افزودن، نام را جستجو کنید…', 'nias-course-widget'));
    echo '<div class="nias-field" ' . $wrap_attr . '><label class="nias-flabel">' . esc_html($label) . '</label>';
    echo '<div class="nias-picker" data-nias-picker="' . esc_attr(wp_json_encode($cfg)) . '"></div>';
    echo '<div class="nias-fhint">' . esc_html($hint !== '' ? $hint : __('چند حرف از نام را بنویسید تا نتایج نمایش داده شود؛ با × هر مورد حذف می‌شود.', 'nias-course-widget')) . '</div></div>';
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    static $boot = false;
    if (!$boot) {
        $boot = true;
        echo "<script>document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-nias-picker]').forEach(function(el){try{window.NiasPicker.attach(el,JSON.parse(el.getAttribute('data-nias-picker')));}catch(e){}});});</script>";
    }
}

function nias_set_save_button($name)
{
    echo '<div class="nias-savebar"><button type="submit" name="' . esc_attr($name) . '" class="nias-btn-primary"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/><path d="M17 21v-8H7v8M7 3v5h8" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg> ' . esc_html__('ذخیره تغییرات', 'nias-course-widget') . '</button></div>';
}

/**
 * Left ad/promo sidebar shown on the plugin settings pages.
 *
 * To manage the promo cards, edit the $ads array below — each item has:
 * badge, title, desc, cta (button text), url, accent (hex color) and icon (SVG).
 */
/**
 * The Nias Login promo, shared by the settings sidebar and the dashboard widget.
 * "desc" may contain <b> for the highlighted features.
 */
function nias_login_promo()
{
    return array(
        'badge'    => __('پیشنهاد ویژه', 'nias-course-widget'),
        'title'    => __('نیاس لاگین؛ بهینه‌ترین افزونه ورود و ثبت‌نام', 'nias-course-widget'),
        'desc'     => __('<b>ثبت‌نام و ورود با موبایل، ایمیل و حساب گوگل</b>، <b>ارسال پیامک سفارش‌های ووکامرس</b>، <b>پشتیبانی از ارسال لایسنس اسپات پلیر</b> و ده‌ها امکان کاربردی دیگر برای بهتر شدن تجربهٔ کاربری و <b>افزایش فروش</b>؛ سبک، سازگار با ووکامرس و بهینه برای سرعت سایت.', 'nias-course-widget'),
        'features' => array(
            __('ثبت‌نام و ورود با موبایل، ایمیل و حساب گوگل', 'nias-course-widget'),
            __('ارسال پیامک وضعیت سفارش‌های ووکامرس', 'nias-course-widget'),
            __('پشتیبانی از ارسال لایسنس اسپات پلیر', 'nias-course-widget'),
            __('ده‌ها امکان کاربردی برای تجربهٔ کاربری بهتر و افزایش فروش', 'nias-course-widget'),
        ),
        'cta'      => __('مشاهده افزونه', 'nias-course-widget'),
        'url'      => 'https://nias.ir/product/nias-login-signup/',
        'accent'   => '#16a34a',
        'icon'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h.01M12 11h.01M16 11h.01"/></svg>',
    );
}

/* WordPress dashboard (پیشخوان) widget for Nias Login — admins only, placed first. */
add_action('wp_dashboard_setup', 'nias_login_dashboard_widget');
function nias_login_dashboard_widget()
{
    if (!current_user_can('manage_options') || !apply_filters('nias_login_dashboard_widget', true)) {
        return;
    }
    wp_add_dashboard_widget('nias_login_promo', __('پیشنهاد نیاس: افزونه نیاس لاگین', 'nias-course-widget'), 'nias_login_dashboard_widget_render');
}
// Move it to the top of the main column after other plugins added theirs
// (a user who drags boxes around keeps their own order; it can be hidden from «تنظیمات صفحه»).
add_action('wp_dashboard_setup', 'nias_login_dashboard_widget_first', 999);
function nias_login_dashboard_widget_first()
{
    global $wp_meta_boxes;
    if (isset($wp_meta_boxes['dashboard']['normal']['core']['nias_login_promo'])) {
        $box = array('nias_login_promo' => $wp_meta_boxes['dashboard']['normal']['core']['nias_login_promo']);
        $wp_meta_boxes['dashboard']['normal']['core'] = $box + $wp_meta_boxes['dashboard']['normal']['core'];
    }
}

function nias_login_dashboard_widget_render()
{
    $p = nias_login_promo();
    $check = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>';
    ?>
    <div class="nlp" dir="rtl">
        <style>
        .nlp{font-family:inherit}
        .nlp-top{display:flex;align-items:center;gap:12px;margin-bottom:12px}
        .nlp-ic{width:44px;height:44px;flex:none;border-radius:13px;background:linear-gradient(135deg,#22c55e,#15803d);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px -8px rgba(22,163,74,.8)}
        .nlp-t{font-size:15px;font-weight:800;color:#1d2327;line-height:1.6}
        .nlp-badge{display:inline-block;font-size:11px;font-weight:700;color:#15803d;background:#dcfce7;border-radius:99px;padding:1px 9px;margin-bottom:3px}
        .nlp ul{margin:0 0 14px;padding:0;list-style:none}
        .nlp li{display:flex;align-items:flex-start;gap:8px;margin:0 0 8px;font-size:13.5px;line-height:1.7;color:#2c3338}
        .nlp li svg{flex:none;margin-top:3px}
        .nlp li b{font-weight:700}
        .nlp-cta{display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff!important;text-decoration:none!important;font-weight:700;font-size:13.5px;padding:9px 18px;border-radius:10px}
        .nlp-cta:hover{background:#15803d}
        </style>
        <div class="nlp-top">
            <span class="nlp-ic"><?php echo $p['icon']; // phpcs:ignore -- static SVG ?></span>
            <div><span class="nlp-badge"><?php echo esc_html($p['badge']); ?></span><div class="nlp-t"><?php echo esc_html($p['title']); ?></div></div>
        </div>
        <ul>
            <?php foreach ($p['features'] as $i => $f) : ?>
                <li><?php echo $check; // phpcs:ignore -- static SVG ?><span><?php echo $i < 3 ? '<b>' . esc_html($f) . '</b>' : esc_html($f); ?></span></li>
            <?php endforeach; ?>
        </ul>
        <a class="nlp-cta" href="<?php echo esc_url($p['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($p['cta']); ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg></a>
    </div>
    <?php
}

function nias_settings_ads_sidebar()
{
    $ads = array(nias_login_promo());

    /** Allow other code to add/replace promo cards. */
    if (function_exists('apply_filters')) {
        $ads = apply_filters('nias_settings_ads', $ads);
    }
    if (empty($ads) || !is_array($ads)) {
        return;
    }
    ?>
    <aside class="nias-set-aside">
        <div class="nias-ads-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 11l18-8-8 18-2-7-8-3Z" stroke="#3858e9" stroke-width="1.7" stroke-linejoin="round"/></svg>
            <span><?php echo esc_html__('پیشنهادهای نیاس', 'nias-course-widget'); ?></span>
        </div>
        <?php foreach ($ads as $ad) :
            $accent = isset($ad['accent']) ? $ad['accent'] : '#3858e9'; ?>
            <a class="nias-ad" href="<?php echo esc_url(isset($ad['url']) ? $ad['url'] : '#'); ?>" target="_blank" rel="noopener" style="--ad-accent:<?php echo esc_attr($accent); ?>">
                <?php if (!empty($ad['badge'])) : ?><span class="nias-ad-badge"><?php echo esc_html($ad['badge']); ?></span><?php endif; ?>
                <?php if (!empty($ad['icon'])) : ?><span class="nias-ad-ic"><?php echo $ad['icon']; ?></span><?php endif; ?>
                <span class="nias-ad-title"><?php echo esc_html(isset($ad['title']) ? $ad['title'] : ''); ?></span>
                <?php if (!empty($ad['desc'])) : ?><span class="nias-ad-desc"><?php echo wp_kses($ad['desc'], array('b' => array(), 'strong' => array())); ?></span><?php endif; ?>
                <?php if (!empty($ad['cta'])) : ?>
                    <span class="nias-ad-cta"><?php echo esc_html($ad['cta']); ?>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </aside>
    <?php
}

/* -------------------------------------------------------------------------
 * Main settings page
 * ---------------------------------------------------------------------- */

function nias_course_render_main_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = false;
    if (isset($_POST['nias_save_main_settings']) && check_admin_referer('nias_main_settings', 'nias_main_nonce')) {
        nias_course_save_theme_options(nias_settings_main_fields());
        $saved = true;
    }
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('main'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>

            <!-- Migration alert -->
            <div class="nias-alert">
                <span class="nias-alert-bar"></span>
                <div class="nias-alert-ic">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z" stroke="#ef4444" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nias-alert-body">
                    <div class="nias-alert-title"><?php echo esc_html__(' انتقال دیتا ها به دوره ساز جدید (برای نسخه های قبل از  1.1.5) ', 'nias-course-widget'); ?></div>
                    <div class="nias-alert-desc"><?php echo esc_html__('چنانچه از ویجت ووکامرس دوره ساز استفاده می‌کردید و مشکلاتی را در آپدیت جدید مشاهده می‌کنید، جهت انتقال داده‌ها به ویرایشگر جدید کلیک کنید. حتماً پیش از انتقال از سایت بک‌اپ تهیه کنید.', 'nias-course-widget'); ?></div>
                    <form method="post" style="margin:0">
                        <?php wp_nonce_field('migrate_courses_nonce', '_wpnonce', true, true); ?>
                        <button type="submit" name="migrate_courses" class="nias-btn-danger">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php echo esc_html__('شروع انتقال و همگام‌سازی', 'nias-course-widget'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('nias_main_settings', 'nias_main_nonce'); ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('گزینه‌های فعالسازی', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_two_way_verification',
                        __('فعالسازی حالت دوجانبه بررسی خرید دوره‌ها', 'nias-course-widget'),
                        __('این حالت تنها در صورتی استفاده شود که دوره‌های خریداری‌شده برای کاربران شما باز نمی‌شود.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_course_account_display',
                        __('فعالسازی نمایش دوره در حساب کاربری', 'nias-course-widget'),
                        __('این گزینه فقط در صورتی که حساب کاربری شما استاندارد باشد عمل می‌کند و باید از ویجت ووکامرسی استفاده کرده باشید. اگر تغییری حاصل نشد، یکبار روی ذخیره در صفحه <a href="/wp-admin/options-permalink.php">پیوندهای یکتا</a> کلیک کنید.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_modern_account',
                        __('فعالسازی نمایش مدرن در حساب', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، در صفحهٔ «دوره های من» حساب کاربری، دوره‌های خریداری‌شده به‌جای آکاردئون قبلی به‌صورت کارتی نمایش داده می‌شوند و هر کارت به صفحهٔ نمایش همان دوره (نمای مدرن) لینک می‌شود. برای نمایش دستی نیز می‌توانید از شورت‌کد <code dir="ltr">[nias_purchased_courses]</code> استفاده کنید.', 'nias-course-widget'),
                        'off',
                        __('مدرن', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_course_certificate',
                        __('فعالسازی مدرک دوره با قابلیت استعلام', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، امکان صدور و استعلام مدرک دوره فعال خواهد شد. پس از ذخیره، زیرمنوی «تنظیمات مدرک دوره» نمایش داده می‌شود.', 'nias-course-widget'),
                        'off',
                        __('مدرک', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_spotplayer_enabled',
                        __('فعالسازی لایسنس اسپات پلیر', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، امکانات اسپات پلیر (ساخت خودکار لایسنس پس از خرید، نمایش پلیر و دانلود) فعال شده و تب «لایسنس اسپات پلیر» نمایش داده می‌شود. برای جلوگیری از تداخل، افزونه مجزای اسپات پلیر را غیرفعال کنید.', 'nias-course-widget'),
                        'off',
                        __('اسپات پلیر', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_instructors_enabled',
                        __('فعالسازی مدرسین', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، نقش «مدرس» ساخته شده و زیرمنوی «مدرسین» نمایش داده می‌شود؛ از آنجا می‌توانید مدرسین را مدیریت کرده و دوره‌های هر مدرس را ببینید. سپس از صفحهٔ «ویرایش جلسات و فصل‌ها» هر محصول، مدرسین آن را انتخاب کنید.', 'nias-course-widget'),
                        'off',
                        __('مدرسین', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_quiz_enabled',
                        __('فعالسازی آزمون‌ساز', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، بخش «آزمون‌ساز» نمایش داده می‌شود؛ از آنجا می‌توانید آزمون بسازید (۶ نوع سوال)، آن را به یک دوره متصل کنید و با شورت‌کد <code dir="ltr">[nias_quiz id="..."]</code> یا داخل نمای دوره مدرن نمایش دهید. قبولی در آزمونِ متصل می‌تواند شرط صدور گواهی دوره شود.', 'nias-course-widget'),
                        'off',
                        __('آزمون', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_meta_enabled',
                        __('فعالسازی متا', 'nias-course-widget'),
                        __('با فعال کردن این گزینه، تب «متا» نمایش داده می‌شود؛ از آنجا می‌توانید متاهای سفارشی (نام و کلید) بسازید. سپس مقدار هر متا را در صفحهٔ «ویرایش جلسات و فصل‌ها» هر محصول وارد کرده و با شورت‌کد <code dir="ltr">[nias_meta key="..."]</code> نمایش دهید.', 'nias-course-widget'),
                        'off',
                        __('متا', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_qa_enabled',
                        __('فعالسازی پرسش و پاسخ', 'nias-course-widget'),
                        __('دانشجوها زیر هر جلسه یا دربارهٔ کل دوره پرسش می‌کنند و مدرس دوره پاسخ می‌دهد. پس از فعال‌سازی، تب «پرسش و پاسخ» در نمای مدرن دوره، زیرمنوی «پرسش و پاسخ» و کارت آن در پیشخوان مدرس نمایش داده می‌شود.', 'nias-course-widget'),
                        'off',
                        __('پرسش و پاسخ', 'nias-course-widget')
                    );
                    echo '<div class="nias-qa-opts" data-nias-show-when="nias_qa_enabled=on">';
                    echo '<div class="nias-row-block"><div class="nias-row-title">' . esc_html__('محل پرسش', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_scope', array('both' => __('هر جلسه + کل دوره', 'nias-course-widget'), 'lessons' => __('فقط زیر جلسات', 'nias-course-widget'), 'general' => __('فقط کلی برای دوره', 'nias-course-widget')), 'both');
                    echo '</div><div class="nias-row-block"><div class="nias-row-title">' . esc_html__('چه کسی می‌تواند بپرسد', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_ask', array('access' => __('شرکت‌کنندگان دوره (خریداران / در دورهٔ رایگان کاربران واردشده)', 'nias-course-widget'), 'logged' => __('همهٔ کاربران واردشده', 'nias-course-widget')), 'access');
                    echo '</div><div class="nias-row-block"><div class="nias-row-title">' . esc_html__('چه کسی پرسش‌ها را می‌بیند', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_view', array('all' => __('همه (حتی مهمان)', 'nias-course-widget'), 'access' => __('فقط شرکت‌کنندگان دوره', 'nias-course-widget')), 'all');
                    echo '</div>';
                    nias_set_toggle_row('nias_qa_moderation', __('انتشار پس از تأیید', 'nias-course-widget'), __('پرسش دانشجو تا تأیید (یا پاسخ) مدرس فقط برای خودش نمایش داده می‌شود.', 'nias-course-widget'), 'off');
                    nias_set_toggle_row('nias_qa_notify', __('اطلاع‌رسانی ایمیلی', 'nias-course-widget'), __('پرسش جدید برای مدرس(های) دوره (یا مدیر سایت اگر مدرسی تعیین نشده) و پاسخ مدرس برای پرسشگر ایمیل می‌شود.', 'nias-course-widget'), 'on');
                    echo '</div>';
                    if (function_exists('nias_wc_active') && nias_wc_active()) {
                        nias_set_toggle_row(
                            'nias_course_cpt',
                            __('نوع نوشتهٔ «دوره» (دوره‌های رایگان)', 'nias-course-widget'),
                            __('منوی «دوره‌ها» اضافه می‌شود تا کنار محصولات ووکامرس، دوره‌های رایگان (بدون فروش) هم بسازید. همهٔ امکانات دوره (جلسات و فصل‌ها، نمای مدرن، مدرسین، آزمون، مدرک، گزارش تماشا) روی آن کار می‌کند. جلسات عمومی برای همه و جلسات «خصوصی» برای کاربران واردشده نمایش داده می‌شوند.', 'nias-course-widget'),
                            'off',
                            __('دوره رایگان', 'nias-course-widget')
                        );
                    } else {
                        echo '<div class="nias-row"><div class="nias-row-main"><div class="nias-row-title">' . esc_html__('حالت رایگان (بدون ووکامرس)', 'nias-course-widget') . ' <span class="nias-chip">' . esc_html__('فعال', 'nias-course-widget') . '</span></div>'
                            . '<div class="nias-row-desc">' . wp_kses_post(sprintf(__('ووکامرس نصب نیست؛ دوره‌ها را از منوی <a href="%s">«دوره‌ها»</a> بسازید. جلسات عمومی برای همه و جلسات «خصوصی» برای کاربران واردشده نمایش داده می‌شوند. برای فروش دوره، ووکامرس را نصب کنید.', 'nias-course-widget'), esc_url(admin_url('edit.php?post_type=' . NIAS_COURSE_CPT)))) . '</div></div></div>';
                    }
                    ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M10 9.5v3l3-1.5-3-1.5ZM8 21h8M12 17v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('نمایش دوره مدرن', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-title"><?php echo esc_html__('فعالسازی نمایش دوره مدرن', 'nias-course-widget'); ?> <span class="nias-chip"><?php echo esc_html__('جدید', 'nias-course-widget'); ?></span></div>
                        <div class="nias-row-desc"><?php echo esc_html__('نمای مدرن دوره شامل پلیر، نوار پیشرفت، سرفصل‌ها و گواهی است که از همان جلسات و فصل‌های محصول و تنظیمات افزونه (مدرسین، گواهی، درس‌های خصوصی) ساخته می‌شود. حالت نمایش را انتخاب کنید:', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('nias_modern_course', array(
                            'off'       => __('خاموش', 'nias-course-widget'),
                            'auto'      => __('نمایش خودکار در صفحه محصول', 'nias-course-widget'),
                            'shortcode' => __('نمایش دستی با شورت‌کد', 'nias-course-widget'),
                        ), 'off');
                        ?>
                        <div class="nias-row-desc" style="margin-top:12px" data-nias-show-when="nias_modern_course=auto">
                            <?php echo esc_html__('در این حالت، نمای مدرن دوره به‌صورت خودکار در صفحهٔ تک‌محصول ووکامرس (پایین خلاصهٔ محصول) برای محصولاتی که فصل و جلسه دارند نمایش داده می‌شود.', 'nias-course-widget'); ?>
                        </div>
                        <div class="nias-shortcodes" style="margin-top:14px" data-nias-show-when="nias_modern_course=shortcode">
                            <div class="nias-sc-head">
                                <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span><?php echo esc_html__('شورت‌کد نمایش دوره مدرن', 'nias-course-widget'); ?></span>
                            </div>
                            <div class="nias-sc-sub"><?php echo esc_html__('شورت‌کد کامل کل دوره را نمایش می‌دهد. برای چیدمان دلخواه، هر بخش شورت‌کد جداگانه‌ای دارد و همهٔ بخش‌ها با هم هماهنگ می‌مانند (درس انتخابی و پیشرفت مشترک). هر شورت‌کد می‌تواند id="123" بگیرد.', 'nias-course-widget'); ?></div>
                            <div class="nias-sc-list">
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course]</code><span><?php echo esc_html__('نمایش کامل دوره', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_header]</code><span><?php echo esc_html__('سربرگ دوره + نوار پیشرفت + دکمهٔ گواهی', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_player]</code><span><?php echo esc_html__('پلیر درس جاری', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_lesson]</code><span><?php echo esc_html__('نوار اطلاعات درس (ناوبری و توضیحات/منابع)', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_curriculum]</code><span><?php echo esc_html__('فهرست فصل‌ها و جلسات', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_certificate]</code><span><?php echo esc_html__('بخش گواهی پایان دوره', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_button]</code><span><?php echo esc_html__('دکمهٔ ورود به محیط دوره مدرن (مستقل از حالت نمایش)', 'nias-course-widget'); ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 11h14v9H5z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('قفل درس‌های خصوصی', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('برای درس‌هایی که «خصوصی» هستند و کاربر هنوز دوره را خریداری نکرده، انتخاب کنید کدام بخش‌ها قفل (مخفی) شوند. این تنظیم روی نمای دوره مدرن، نمایش حساب کاربری و ویجت ووکامرس المنتور اعمال می‌شود. پیش‌فرض همهٔ موارد «قفل» است.', 'nias-course-widget'); ?></div>
                    </div>
                    <?php
                    nias_set_toggle_row(
                        'nias_lock_private_preview',
                        __('قفل پیش‌نمایش / ویدیوی درس', 'nias-course-widget'),
                        __('در نمای حساب کاربری و ویجت، لینک «پیش‌نمایش» و در نمای دوره مدرن، پلیر ویدیوی درس را برای کاربری که دوره را نخریده مخفی می‌کند.', 'nias-course-widget'),
                        'on'
                    );
                    nias_set_toggle_row(
                        'nias_lock_private_content',
                        __('قفل توضیحات / محتوای درس', 'nias-course-widget'),
                        __('محتوای متنی درس (توضیحات و درس‌های متنی) را برای کاربری که دوره را نخریده مخفی می‌کند.', 'nias-course-widget'),
                        'on'
                    );
                    nias_set_toggle_row(
                        'nias_lock_private_attachments',
                        __('قفل منابع و پیوست (فایل دانلودی)', 'nias-course-widget'),
                        __('فایل دانلودی و منابع و پیوست‌های درس را برای کاربری که دوره را نخریده مخفی می‌کند.', 'nias-course-widget'),
                        'on'
                    );
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- Video player: settings live on their own page -->
                <div class="nias-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px 18px">
                    <div>
                        <div class="nias-row-title"><?php echo esc_html__('پلیر ویدیو و ردیابی تماشا', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('ظاهر، کنترل‌ها، سرعت‌ها، واترمارک، جلوگیری از جلو زدن، درصد تکمیل درس و تنظیمات HLS/DASH با پیش‌نمایش زنده.', 'nias-course-widget'); ?></div>
                    </div>
                    <a class="nias-btn-soft" style="text-decoration:none;display:inline-flex" href="<?php echo esc_url(admin_url('admin.php?page=nias-course-player')); ?>"><?php echo esc_html__('تنظیمات پلیر', 'nias-course-widget'); ?></a>
                </div>
                <?php nias_set_save_button('nias_save_main_settings'); ?>
            </form>

            <!-- Help / tutorials -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="m10 8 6 4-6 4V8Z" fill="#3858e9"/><rect x="2" y="4" width="20" height="16" rx="3" stroke="#3858e9" stroke-width="1.8"/></svg>', __('آموزش استفاده از افزونه', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nias-vid-note" style="display:flex;flex-wrap:wrap;align-items:center;justify-content: space-between;gap:14px;padding:16px 18px;background:#f1f4f9;border:1px solid #e2e6ee;border-radius:12px">
                        <span style="font-size:15px;font-weight:700;color:#33415c;line-height:2;text-align:center"><?php echo esc_html__('آموزش های کارکردن با پلاگین دوره ساز را از آپارات نیاس و یوتیوب نیاس دنبال کنید', 'nias-course-widget'); ?></span>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <a href="https://www.aparat.com/playlist/26525943/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 16px;border-radius:10px;background:#ed145b;color:#fff;font-size:14px;font-weight:700;text-decoration:none">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4.2a5.8 5.8 0 1 1 0 11.6 5.8 5.8 0 0 1 0-11.6zm0 2.3a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/></svg>
                                <?php echo esc_html__('آپارات نیاس', 'nias-course-widget'); ?>
                            </a>
                            <a href="https://www.youtube.com/watch?v=WyBlPnjvVv4&list=PLl88gtSh81bU57NKRpZqoAZaFJCok7-mx" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 16px;border-radius:10px;background:#ff0000;color:#fff;font-size:14px;font-weight:700;text-decoration:none">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>
                                <?php echo esc_html__('یوتیوب نیاس', 'nias-course-widget'); ?>
                            </a>
                        </div>
                    </div>

                    <div class="nias-tg">
                        <div class="nias-tg-text"><?php echo esc_html__('در صورت وجود مشکل یا سوال از طریق تلگرام با ما در ارتباط باشید.', 'nias-course-widget'); ?></div>
                        <a href="https://t.me/niasir" target="_blank" rel="noopener" class="nias-tg-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="#3858e9"><path d="M23.9 4.3 20.5 20c-.25 1.1-.92 1.38-1.86.86l-5.14-3.79-2.48 2.39c-.27.27-.5.5-1.03.5l.37-5.23 9.5-8.59c.42-.37-.09-.57-.65-.21L6.97 12.8l-5.06-1.58c-1.1-.34-1.12-1.1.23-1.63L21.92 1.97c.92-.34 1.72.21 1.42 1.63Z"/></svg>
                            T.me/niasir
                        </a>
                    </div>

                    <div class="nias-banners">
                        <a href="https://nias.ir" target="_blank" rel="noopener" class="nias-banner nias-banner-blue">
                            <div>
                                <div class="nias-banner-t"><?php echo esc_html__('آموزش‌های طراحی سایت نیاس', 'nias-course-widget'); ?></div>
                                <div class="nias-banner-s">nias.ir</div>
                            </div>
                            <span class="nias-banner-cta"><?php echo esc_html__('ورود', 'nias-course-widget'); ?></span>
                        </a>
                        <a href="https://proelement.ir" target="_blank" rel="noopener" class="nias-banner nias-banner-red">
                            <div>
                                <div class="nias-banner-t" style="color:#401f24"><?php echo esc_html__('پرو المنت — دانلود قالب', 'nias-course-widget'); ?></div>
                                <div class="nias-banner-s" style="color:#9a7b7e">proelement.ir</div>
                            </div>
                            <span class="nias-banner-cta nias-banner-cta-red"><?php echo esc_html__('ورود', 'nias-course-widget'); ?></span>
                        </a>
                    </div>
                </div>
            <?php nias_set_card_close(); ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('سپاسگزاریم از اینکه سایت خود را با <span style="color:#3858e9;font-weight:600">وردپرس</span> ساخته‌اید.', 'nias-course-widget')); ?></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Certificate settings page
 * ---------------------------------------------------------------------- */

function nias_course_render_certificate_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = false;
    if (isset($_POST['nias_save_certificate_settings']) && check_admin_referer('nias_certificate_settings', 'nias_certificate_nonce')) {
        nias_course_save_theme_options(nias_settings_certificate_fields());
        $saved = true;
    }

    // Build option lists.
    // (selected products: searchable picker — no list of every product)

    $page_options = array('' => __('انتخاب صفحه', 'nias-course-widget'));
    foreach (get_pages(array('post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC')) as $page) {
        $page_options[$page->ID] = $page->post_title;
    }

    $template_options = function_exists('nias_cert_templates_list') ? nias_cert_templates_list() : array();

    $category_options = array();
    // WooCommerce product categories and/or «دوره» categories (whichever exist).
    $cat_taxes = array_values(array_filter(array('product_cat', defined('NIAS_COURSE_TAX') ? NIAS_COURSE_TAX : ''), 'taxonomy_exists'));
    $categories = $cat_taxes ? get_terms(array('taxonomy' => $cat_taxes, 'hide_empty' => false, 'number' => 300)) : array();
    if (!is_wp_error($categories)) {
        foreach ($categories as $category) {
            $category_options[$category->term_id] = $category->name . (count($cat_taxes) > 1 && $category->taxonomy !== 'product_cat' ? ' ' . __('(دوره)', 'nias-course-widget') : '');
        }
    }
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('cert'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>

            <form method="post">
                <?php wp_nonce_field('nias_certificate_settings', 'nias_certificate_nonce'); ?>

                <!-- Display card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="5" stroke="#3858e9" stroke-width="1.8"/><path d="m8.5 13-1.5 8 5-2.5 5 2.5-1.5-8" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('نمایش مدرک', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-title"><?php echo esc_html__('نحوه نمایش مدرک', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('نحوه نمایش مدرک برای محصولات را انتخاب کنید.', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('certificate_display_type', array(
                            'all'      => __('همه محصولات', 'nias-course-widget'),
                            'selected' => __('محصول انتخابی', 'nias-course-widget'),
                            'category' => __('از دسته‌بندی', 'nias-course-widget'),
                            'none'     => __('هیچکدام', 'nias-course-widget'),
                        ), 'none');
                        nias_set_picker_field('certificate_selected_products', __('محصولات انتخابی', 'nias-course-widget'), 'product', 'data-nias-show-when="certificate_display_type=selected"');
                        nias_set_multiselect_field('certificate_selected_categories', __('دسته‌بندی‌های انتخابی', 'nias-course-widget'), $category_options, 'data-nias-show-when="certificate_display_type=category"');
                        ?>
                    </div>
                    <div class="nias-row-block nias-row-sep">
                        <?php nias_set_select_field('certificate_display_page', __('صفحه نمایش مدرک', 'nias-course-widget'), __('صفحه‌ای که می‌خواهید مدرک در آن نمایش داده شود را انتخاب کنید.', 'nias-course-widget'), $page_options); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Design mode card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('حالت طراحی مدرک', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('در حالت «کلاسیک» مدرک با همان طرح قبلی و تصاویر و متن‌های پایین همین صفحه ساخته می‌شود. در حالت «طراح پیشرفته» مدرک از قالبی ساخته می‌شود که خودتان در «طراح مدرک» با کشیدن و رها کردن طراحی کرده‌اید.', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('certificate_design_mode', array(
                            'classic'  => __('کلاسیک (طرح فعلی)', 'nias-course-widget'),
                            'designer' => __('طراح پیشرفته (بوم)', 'nias-course-widget'),
                        ), 'classic');
                        ?>
                    </div>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_design_mode=designer">
                        <?php
                        if ($template_options) {
                            nias_set_select_field('certificate_default_template', __('قالب پیش‌فرض', 'nias-course-widget'), __('برای هر دوره می‌توانید در صفحهٔ ویرایش محصول (باکس «مدرک دوره») قالب جداگانه انتخاب کنید.', 'nias-course-widget'), array('' => __('— انتخاب قالب —', 'nias-course-widget')) + $template_options);
                        } else {
                            echo '<div class="nias-row-desc">' . esc_html__('هنوز قالبی نساخته‌اید. تا زمان ساخت قالب، مدارک با طرح کلاسیک صادر می‌شوند.', 'nias-course-widget') . '</div>';
                        }
                        ?>
                        <a class="nias-btn-soft" style="display:inline-flex;margin-top:12px;text-decoration:none" href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-designer')); ?>"><?php echo esc_html__('باز کردن طراح مدرک', 'nias-course-widget'); ?></a>
                    </div>
                    <?php nias_set_toggle_row('certificate_freeze_design', __('ثابت ماندن طرح مدارک صادرشده', 'nias-course-widget'), __('هر مدرک با طرحی که هنگام صدور داشت ساخته می‌شود؛ ویرایش یا تعویض قالب فقط روی مدارک جدید اثر دارد (مثل مدرک کاغذی که پس از چاپ تغییر نمی‌کند). برای یک مدرک خاص می‌توانید در «مدارک صادرشده» قالب فعلی را اعمال کنید. مدارک صادرشده پیش از نسخهٔ 1.5.1 همچنان از قالب فعلی پیروی می‌کنند.', 'nias-course-widget'), 'on', '', 'data-nias-show-when="certificate_design_mode=designer"'); ?>
                <?php nias_set_card_close(); ?>

                <!-- Conditions card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3 8-8" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('شرایط دریافت مدرک', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('این شرایط برای همهٔ دوره‌ها اعمال می‌شود و در صفحهٔ ویرایش هر محصول (باکس «مدرک دوره») می‌توان شرایط اختصاصی تعریف کرد. دانشجو در لیست مدارک خود می‌بیند کدام شرط هنوز کامل نشده است. مدارکی که قبلاً صادر شده‌اند با سخت‌تر شدن شرایط باطل نمی‌شوند.', 'nias-course-widget'); ?></div>
                        <div class="nias-row-title" style="margin:14px 0 10px"><?php echo esc_html__('وضعیت سفارش قابل قبول', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_radio_cards('certificate_cond_order_status', array(
                            'completed_processing' => __('تکمیل‌شده یا در حال انجام', 'nias-course-widget'),
                            'completed'            => __('فقط سفارش‌های تکمیل‌شده', 'nias-course-widget'),
                        ), 'completed_processing');
                        ?>
                    </div>
                    <?php
                    nias_set_toggle_row('certificate_cond_quiz', __('الزام قبولی در آزمون متصل به دوره', 'nias-course-widget'), __('اگر آزمونی به دوره متصل باشد، تا قبولی در آن مدرک صادر نمی‌شود.', 'nias-course-widget'), 'on');
                    ?>
                    <div class="nias-row-block nias-row-sep">
                        <div style="display:flex;gap:16px;flex-wrap:wrap">
                            <?php
                            nias_set_number_field('certificate_cond_min_progress', __('حداقل درصد پیشرفت دوره', 'nias-course-widget'), __('۰ یعنی غیرفعال. پیشرفت از نمای مدرن دوره ثبت می‌شود.', 'nias-course-widget'), 0, 100);
                            nias_set_number_field('certificate_cond_wait_days', __('حداقل روز پس از خرید', 'nias-course-widget'), __('۰ یعنی غیرفعال.', 'nias-course-widget'), 0);
                            ?>
                        </div>
                    </div>
                    <?php
                    nias_set_toggle_row('certificate_cond_require_name', __('الزام ثبت نام و نام خانوادگی', 'nias-course-widget'), __('تا زمانی که دانشجو نام و نام خانوادگی خود را کامل نکند مدرک صادر نمی‌شود؛ فرم تکمیل نام در همان صفحهٔ مدارک نمایش داده می‌شود.', 'nias-course-widget'), 'off');
                    nias_set_toggle_row('certificate_cond_approval', __('نیاز به تأیید دستی مدیر', 'nias-course-widget'), __('مدارک جدید با وضعیت «در انتظار تأیید» ثبت می‌شوند و پس از تأیید شما در صفحهٔ «مدارک صادرشده» قابل دانلود و استعلام خواهند بود.', 'nias-course-widget'), 'off');
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- My Account tab card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21a8 8 0 0 1 16 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('نمایش مدارک در حساب کاربری', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('certificate_account_endpoint', __('افزودن تب «مدارک من» به حساب کاربری', 'nias-course-widget'), __('یک برگهٔ جدید (اندپوینت) به منوی حساب کاربری ووکامرس اضافه می‌شود که دانشجو در آن مدارک دوره‌های خود، وضعیت شرایط دریافت و دکمهٔ دانلود را می‌بیند. نیازی به ذخیرهٔ دوبارهٔ پیوندهای یکتا نیست.', 'nias-course-widget'), 'off'); ?>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_account_endpoint=on">
                        <div style="display:flex;gap:16px;flex-wrap:wrap">
                            <div style="flex:1;min-width:220px"><?php nias_set_text_field('certificate_account_title', __('عنوان منو', 'nias-course-widget'), __('مدارک من', 'nias-course-widget')); ?></div>
                            <div style="flex:1;min-width:220px"><?php nias_set_text_field('certificate_account_slug', __('آدرس اندپوینت (لاتین)', 'nias-course-widget'), 'my-certificates'); ?></div>
                        </div>
                        <?php if (function_exists('nias_cert_account_url') && nias_cert_account_url()) : ?>
                            <div class="nias-row-desc" style="margin-top:10px"><?php echo esc_html__('آدرس فعلی:', 'nias-course-widget'); ?> <a href="<?php echo esc_url(nias_cert_account_url()); ?>" target="_blank" dir="ltr"><?php echo esc_html(nias_cert_account_url()); ?></a></div>
                        <?php endif; ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Verification card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('استعلام مدرک', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <?php nias_set_select_field('certificate_verify_page', __('صفحهٔ استعلام مدرک', 'nias-course-widget'), __('صفحه‌ای که شورت‌کد [nias_certificate_verify] را در آن قرار داده‌اید. با انتخاب آن، QR مدارک جدید به فرم استعلام اشاره می‌کند (لینک‌های QR مدارک قدیمی همچنان کار می‌کنند). اگر خالی بماند، صفحهٔ نمایش مدرک و اگر آن هم انتخاب نشده باشد، صفحهٔ استعلام داخلی افزونه (/?nias_verify=کد) استفاده می‌شود؛ پس QR مدارک هیچ‌وقت به صفحهٔ خطا نمی‌رود.', 'nias-course-widget'), $page_options); ?>
                    </div>
                    <?php nias_set_toggle_row('certificate_verify_mask_name', __('پوشاندن بخشی از نام در نتیجهٔ استعلام', 'nias-course-widget'), __('برای حفظ حریم خصوصی، نام دارنده به شکل «ع***ا م***ی» نمایش داده می‌شود. با روشن بودن این گزینه، دکمهٔ «مشاهدهٔ مدرک (PDF)» در نتیجهٔ استعلام نمایش داده نمی‌شود.', 'nias-course-widget'), 'off'); ?>
                    <div class="nias-row-block nias-row-sep">
                        <div class="nias-row-title"><?php echo esc_html__('استعلام از طریق API', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('سامانه‌های دیگر (کارفرما، LMS) می‌توانند با این آدرس اعتبار مدرک را به‌صورت JSON بررسی کنند. محدودیت تعداد درخواست و پوشاندن نام روی آن هم اعمال می‌شود.', 'nias-course-widget'); ?></div>
                        <code dir="ltr" style="display:inline-block;margin-top:8px"><?php echo esc_html(rest_url('nias/v1/certificates/')); ?>{CODE}</code>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- E-mail card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="m4 7 8 6 8-6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('ارسال خودکار مدرک با ایمیل', 'nias-course-widget')); ?>
                    <div id="nias-cert-email"></div>
                    <?php if (isset($_GET['nias_test_mail'])) : ?>
                        <div class="nias-row-block"><div class="nias-sign-msg <?php echo $_GET['nias_test_mail'] === 'ok' ? 'ok' : 'bad'; ?>" style="padding:10px 14px;border-radius:10px;font-size:13px;background:<?php echo $_GET['nias_test_mail'] === 'ok' ? '#ecfbf1' : '#fff1f1'; ?>"><?php echo $_GET['nias_test_mail'] === 'ok' ? esc_html(sprintf(__('ایمیل آزمایشی به %s ارسال شد.', 'nias-course-widget'), wp_get_current_user()->user_email)) : esc_html__('ارسال ایمیل ناموفق بود؛ تنظیمات SMTP سایت را بررسی کنید.', 'nias-course-widget'); ?></div></div>
                    <?php endif; ?>
                    <?php nias_set_toggle_row('certificate_email_on_issue', __('ارسال ایمیل هنگام صدور مدرک', 'nias-course-widget'), __('به‌محض اینکه دانشجو شرایط را کامل کند (تکمیل سفارش، قبولی در آزمون، پیشرفت دوره) یا مدیر مدرک را تأیید کند، ایمیل تبریک همراه لینک استعلام برایش ارسال می‌شود. هر مدرک فقط یک بار ایمیل می‌شود و ارسال در پس‌زمینه انجام می‌شود.', 'nias-course-widget'), 'off'); ?>
                    <div data-nias-show-when="certificate_email_on_issue=on">
                        <?php nias_set_toggle_row('certificate_email_attach', __('پیوست کردن فایل PDF مدرک', 'nias-course-widget'), __('اگر امضای دیجیتال فعال باشد، PDF پیوست هم امضاشده است.', 'nias-course-widget'), 'on'); ?>
                        <div class="nias-row-block nias-row-sep">
                            <?php nias_set_text_field('certificate_email_subject', __('موضوع ایمیل', 'nias-course-widget'), nias_cert_email_default_subject()); ?>
                            <?php nias_set_textarea_field('certificate_email_body', __('متن ایمیل', 'nias-course-widget'), nias_cert_email_default_body()); ?>
                            <div class="nias-row-desc"><?php echo esc_html__('فیلدهای قابل استفاده: {student_name} {course_name} {code} {date} {verify_url} {account_url} {instructor} {site_name}. دکمه‌های «دریافت مدرک» و «صفحهٔ استعلام» خودکار به انتهای ایمیل اضافه می‌شوند.', 'nias-course-widget'); ?></div>
                            <a class="nias-btn-soft" style="display:inline-flex;margin-top:12px;text-decoration:none" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_cert_test_email'), 'nias_cert_test_email')); ?>"><?php echo esc_html__('ارسال ایمیل آزمایشی به من (با تنظیمات ذخیره‌شده)', 'nias-course-widget'); ?></a>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Shortcodes card -->
                <div class="nias-shortcodes">
                    <div class="nias-sc-head">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php echo esc_html__('شورت‌کدها', 'nias-course-widget'); ?></span>
                    </div>
                    <div class="nias-sc-sub"><?php echo esc_html__('شورت‌کد را در صفحه‌ای که انتخاب کردی استفاده کن.', 'nias-course-widget'); ?></div>
                    <div class="nias-sc-list">
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate]</code><span><?php echo esc_html__('نمایش مدرک', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate_preview]</code><span><?php echo esc_html__('پیش‌نمایش', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_button_certificate]</code><span><?php echo esc_html__('دکمه هدایت به دریافت مدرک', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate_verify]</code><span><?php echo esc_html__('فرم استعلام مدرک با کد', 'nias-course-widget'); ?></span></div>
                    </div>
                    <div class="nias-sc-warn"><?php echo esc_html__('⚠ حواست باشه بعد از تست، شورت‌کد پیش‌نمایش رو برداری.', 'nias-course-widget'); ?></div>
                </div>

                <!-- Images card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#3858e9" stroke-width="1.8"/><circle cx="8.5" cy="8.5" r="1.5" fill="#3858e9"/><path d="m21 15-5-5L5 21" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('تصاویر مدرک', 'nias-course-widget')); ?>
                    <div class="nias-card-padx">
                        <?php
                        nias_set_image_row('certificate_watermark', __('تصویر لوگوی شما', 'nias-course-widget'), __('مارک یا لوگوی مدرک را آپلود کنید (svg نباشد).', 'nias-course-widget'));
                        nias_set_image_row('certificate_header_bg', __('تصویر پس‌زمینه هدر', 'nias-course-widget'), __('پس‌زمینه بخش بالای مدرک (svg نباشد).', 'nias-course-widget'));
                        nias_set_image_row('certificate_footer_bg', __('تصویر پس‌زمینه فوتر', 'nias-course-widget'), __('پس‌زمینه بخش پایین مدرک (svg نباشد).', 'nias-course-widget'));
                        nias_set_image_row('certificate_icon', __('نماد سرتیفیکت', 'nias-course-widget'), __('نماد یا آیکون مدرک را آپلود کنید (svg نباشد).', 'nias-course-widget'));
                        ?>
                        <div class="nias-dlsamples">
                            <div>
                                <div class="nias-dls-title"><?php echo esc_html__('دانلود نمونه تصاویر مدرک', 'nias-course-widget'); ?></div>
                                <div class="nias-dls-sub"><?php echo esc_html__('نمونه تصاویر آماده شامل هدر، فوتر، واترمارک و آیکون.', 'nias-course-widget'); ?></div>
                            </div>
                            <a href="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/images/certificate.zip'); ?>" class="nias-dls-btn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php echo esc_html__('دانلود نمونه تصاویر', 'nias-course-widget'); ?>
                            </a>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Texts card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 7V5h16v2M9 19h6M12 5v14" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('متن‌های مدرک', 'nias-course-widget')); ?>
                    <div class="nias-card-pad nias-stack">
                        <?php
                        nias_set_text_field('certificate_first_title', __('تایتل اول مدرک', 'nias-course-widget'), __('عنوان اصلی بالای مدرک', 'nias-course-widget'));
                        nias_set_text_field('certificate_before_name_title', __('تایتل قبل از نام دانشجو', 'nias-course-widget'), __('متن قبل از نام دانشجو', 'nias-course-widget'));
                        nias_set_text_field('certificate_after_name_title', __('تایتل بعد از نام دانشجو', 'nias-course-widget'), __('متن بعد از نام دانشجو', 'nias-course-widget'));
                        ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Date card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M3 9h18M8 3v4M16 3v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('تاریخ مدرک', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('certificate_show_date', __('نمایش تاریخ', 'nias-course-widget'), '', 'on'); ?>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_show_date=on">
                        <div class="nias-row-title" style="margin-bottom:12px"><?php echo esc_html__('منبع تاریخ', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_radio_cards('certificate_date_source', array(
                            'purchase_date'         => __('تاریخ خرید دوره', 'nias-course-widget'),
                            'manual_date'           => __('تاریخ دستی', 'nias-course-widget'),
                            'user_certificate_date' => __('تاریخ از فیلد nias_certificate_date کاربر', 'nias-course-widget'),
                        ), 'purchase_date');
                        nias_set_date_field('certificate_manual_date', __('تاریخ دستی', 'nias-course-widget'), 'data-nias-show-when="certificate_date_source=manual_date"');
                        ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Signature card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 17c4-1 4-9 7-9s2 6 5 6 3-3 6-3" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/><path d="M3 21h18" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('مهر و امضا', 'nias-course-widget')); ?>
                    <div class="nias-card-padx">
                        <?php
                        nias_set_image_row('certificate_seal_image', __('تصویر مهر شما', 'nias-course-widget'), __('مهر رسمی خود را آپلود کنید (svg نباشد).', 'nias-course-widget'));
                        nias_set_image_row('certificate_signature_image', __('تصویر امضا', 'nias-course-widget'), __('امضای مسئول صدور مدرک را آپلود کنید (svg نباشد).', 'nias-course-widget'));
                        ?>
                        <div class="nias-signer"><?php nias_set_text_field('certificate_signer_name', __('نام امضا کننده', 'nias-course-widget'), __('نام شخص امضا کننده مدرک', 'nias-course-widget')); ?></div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_save_button('nias_save_certificate_settings'); ?>
            </form>

            <?php if (function_exists('nias_cert_sign_settings_card')) { nias_cert_sign_settings_card(); } ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('سپاسگزاریم از اینکه سایت خود را با <span style="color:#3858e9;font-weight:600">وردپرس</span> ساخته‌اید.', 'nias-course-widget')); ?></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Admin assets (media uploader, conditional logic, styles)
 * ---------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', 'nias_course_settings_assets');
function nias_course_settings_assets($hook)
{
    if (
        strpos($hook, 'nias-course-settings') === false &&
        strpos($hook, 'nias-course-certificate') === false &&
        strpos($hook, 'nias-course-cert-designer') === false &&
        strpos($hook, 'nias-course-cert-issued') === false &&
        strpos($hook, 'nias-spotplayer-license') === false &&
        strpos($hook, 'nias-course-instructors') === false &&
        strpos($hook, 'nias-course-quiz') === false &&
        strpos($hook, 'nias-course-qa') === false &&
        strpos($hook, 'nias-course-approvals') === false &&
        strpos($hook, 'nias-course-meta') === false &&
        strpos($hook, 'nias-course-player') === false
    ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style('nias-admin-css', NIASADMIN_URL . '/adminstyle.css', array(), NIAS_COURSE_VERSION . '.' . (int) @filemtime(__DIR__ . '/adminstyle.css'));
    add_action('admin_footer', 'nias_course_settings_inline_js');
}

function nias_course_settings_inline_js()
{
    ?>
    <script>
    jQuery(function ($) {
        var imgPlaceholder = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#c1c7da" stroke-width="1.6"/><circle cx="8.5" cy="8.5" r="1.5" fill="#c1c7da"/><path d="m21 15-5-5L5 21" stroke="#c1c7da" stroke-width="1.6" stroke-linejoin="round"/></svg>';

        // Media uploader for image fields.
        $(document).on('click', '.nias-image-upload', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.nias-image-field');
            var frame = wp.media({ title: 'انتخاب تصویر', multiple: false });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $wrap.find('.nias-image-url').val(attachment.url);
                $wrap.find('.nias-image-preview').html('<img src="' + attachment.url + '">');
            });
            frame.open();
        });
        $(document).on('click', '.nias-image-clear', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.nias-image-field');
            $wrap.find('.nias-image-url').val('');
            $wrap.find('.nias-image-preview').html(imgPlaceholder);
        });

        // Conditional rows: data-nias-show-when="field=value".
        function evaluateConditions() {
            $('[data-nias-show-when]').each(function () {
                var cond = $(this).data('nias-show-when').split('=');
                var field = cond[0], expected = cond[1];
                var actual = $('[data-nias-field="' + field + '"]:checked').val();
                if (actual === undefined) {
                    actual = $('[name="' + field + '"]').val();
                }
                $(this).toggle(actual === expected);
            });
        }
        $(document).on('change', 'input[type=radio], input[type=checkbox], select', evaluateConditions);
        evaluateConditions();
    });
    </script>
    <?php
}

// Keep the Vazirmatn font on plugin admin pages, and constrain the custom
// top-level menu icon (a PNG passed to add_menu_page renders at natural size
// otherwise).
function nias_course_admin_style()
{
    ?>
    <style>
        <?php echo nias_course_font_face_css(); ?>
        #adminmenu #toplevel_page_nias-course-settings .wp-menu-image img {
            width: 20px;
            height: 20px;
            object-fit: contain;
            padding: 7px 0;
            opacity: .85;
        }
        #adminmenu #toplevel_page_nias-course-settings:hover .wp-menu-image img,
        #adminmenu #toplevel_page_nias-course-settings.current .wp-menu-image img,
        #adminmenu #toplevel_page_nias-course-settings.wp-has-current-submenu .wp-menu-image img {
            opacity: 1;
        }
    </style>
    <?php
}
add_action('admin_head', 'nias_course_admin_style');

/* -------------------------------------------------------------------------
 * Migration handling (unchanged behaviour, now backed by the new data layer)
 * ---------------------------------------------------------------------- */

add_action('admin_init', 'handle_course_migration');
function handle_course_migration()
{
    if (isset($_POST['migrate_courses']) && check_admin_referer('migrate_courses_nonce')) {
        migrate_course_data_to_carbon();
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success"><p>' .
                esc_html__('انتقال و همگام سازی اطلاعات موفق بود لطفاً صفحات را پس از پاکسازی کش بررسی کنید', 'nias-course-widget') .
                '</p></div>';
        });
    }
}

/**
 * Migrate legacy serialized course data into the (Carbon-compatible) storage.
 */
function migrate_course_data_to_carbon()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!function_exists('carbon_set_post_meta')) {
        error_log("Course data layer not initialized - migration cancelled");
        return false;
    }
    global $wpdb;

    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => [
            'publish',
            'draft',
            'pending',
            'private',
            'future',
            'auto-draft',
            'inherit',
            'trash'
        ]
    ]);

    error_log("Starting migration process. Total products found: " . count($products));

    foreach ($products as $product) {
        try {
            if (get_post_meta($product->ID, '_course_data_migrated', true) === 'yes') {
                error_log("Skipping product ID {$product->ID} - Already migrated");
                continue;
            }

            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                WHERE post_id = %d AND meta_key = %s",
                $product->ID,
                'nias_course_sections_list'
            ));

            if (empty($meta_rows)) {
                continue;
            }

            foreach ($meta_rows as $row) {
                $sections = maybe_unserialize($row->meta_value);

                if (!is_array($sections)) {
                    continue;
                }

                $carbon_fields_data = [];
                foreach ($sections as $section) {
                    $section_data = [
                        'section_title'    => sanitize_text_field($section['section_title'] ?? ''),
                        'section_subtitle' => sanitize_text_field($section['section_subtitle'] ?? ''),
                        'section_icon'     => [
                            [
                                'icon_type' => 'url',
                                'icon_url'  => esc_url_raw($section['section_icon'] ?? ''),
                            ]
                        ],
                        'lessons'          => [],
                    ];

                    if (isset($section['lessons']) && is_array($section['lessons'])) {
                        foreach ($section['lessons'] as $lesson) {
                            $lesson_data = [
                                'lesson_title'    => sanitize_text_field($lesson['lesson_title'] ?? ''),
                                'lesson_icon'     => [
                                    [
                                        'icon_type' => 'url',
                                        'icon_url'  => esc_url_raw($lesson['lesson_icon'] ?? ''),
                                    ]
                                ],
                                'lesson_label'    => sanitize_text_field($lesson['lesson_label'] ?? ''),
                                'lesson_preview_video' => [
                                    [
                                        'video_type' => 'url',
                                        'video_url'  => esc_url_raw($lesson['lesson_preview_video'] ?? ''),
                                    ]
                                ],
                                'lesson_download' => [
                                    [
                                        'file_type' => 'url',
                                        'file_url'  => esc_url_raw($lesson['lesson_download'] ?? ''),
                                    ]
                                ],
                                'lesson_content'  => wp_kses_post($lesson['lesson_content'] ?? ''),
                                'lesson_private'  => (bool) ($lesson['lesson_private'] ?? false),
                            ];

                            if (empty($lesson_data['lesson_icon'][0]['icon_url'])) {
                                $lesson_data['lesson_icon'] = [];
                            }
                            if (empty($lesson_data['lesson_preview_video'][0]['video_url'])) {
                                $lesson_data['lesson_preview_video'] = [];
                            }
                            if (empty($lesson_data['lesson_download'][0]['file_url'])) {
                                $lesson_data['lesson_download'] = [];
                            }

                            $section_data['lessons'][] = $lesson_data;
                        }
                    }

                    if (empty($section_data['section_icon'][0]['icon_url'])) {
                        $section_data['section_icon'] = [];
                    }

                    $carbon_fields_data[] = $section_data;
                }

                try {
                    carbon_set_post_meta($product->ID, 'course_sections', $carbon_fields_data);
                    update_post_meta($product->ID, '_course_data_migrated', 'yes');
                    error_log("Successfully migrated course data for product ID: {$product->ID}");
                } catch (Exception $e) {
                    error_log("Error saving course data for product ID {$product->ID}: " . $e->getMessage());
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log("Error processing product ID {$product->ID}: " . $e->getMessage());
            continue;
        }
    }

    error_log("Course data migration completed");
    return count($products);
}
