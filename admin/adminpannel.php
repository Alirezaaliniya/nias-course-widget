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
        'nias_secure_media'           => 'radio',
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
        __('Nias Course Builder Settings', 'nias-course-widget'),
        __('Nias Course Builder', 'nias-course-widget'),
        'manage_options',
        'nias-course-settings',
        'nias_course_render_main_settings',
        NIASADMIN_URL . '/nias-course.png'
    );

    // Certificate sub-page only appears when the feature is enabled.
    if (carbon_get_theme_option('nias_course_certificate') === 'on') {
        add_submenu_page(
            'nias-course-settings',
            __('Course Certificate Settings', 'nias-course-widget'),
            __('Course Certificate Settings', 'nias-course-widget'),
            'manage_options',
            'nias-course-certificate',
            'nias_course_render_certificate_settings'
        );
    }

    // SpotPlayer license sub-page only appears when the feature is enabled.
    if (carbon_get_theme_option('nias_spotplayer_enabled') === 'on' && function_exists('nias_spot_render_license_settings')) {
        add_submenu_page(
            'nias-course-settings',
            __('SpotPlayer License', 'nias-course-widget'),
            __('SpotPlayer License', 'nias-course-widget'),
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
    $control .= ' <button type="button" class="button nias-image-upload">' . esc_html__('Choose image', 'nias-course-widget') . '</button>';
    $control .= ' <button type="button" class="button nias-image-clear">' . esc_html__('Remove', 'nias-course-widget') . '</button>';
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
                    <img src="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/logons.gif'); ?>" alt="<?php echo esc_attr__('Nias Course Builder', 'nias-course-widget'); ?>" width="24" height="24" style="display:block">
                </div>
                <div>
                    <div class="nias-brand-title"><?php echo esc_html__('Nias Course Builder', 'nias-course-widget'); ?></div>
                    <div class="nias-brand-sub"><?php echo esc_html__('Plugin settings panel', 'nias-course-widget'); ?></div>
                </div>
            </div>
            <div><span class="nias-ver"><?php echo esc_html__('Version', 'nias-course-widget') . ' ' . esc_html(nias_fa_digits(NIAS_COURSE_VERSION)); ?></span></div>
        </div>
        <div class="nias-set-tabs-wrap">
            <div class="nias-set-tabs">
                <a href="<?php echo esc_url($main_url); ?>" class="nias-tab <?php echo $active === 'main' ? 'active' : ''; ?>"><?php echo esc_html__('Main settings', 'nias-course-widget'); ?></a>
                <?php if ($cert_on) : ?>
                    <a href="<?php echo esc_url($cert_url); ?>" class="nias-tab <?php echo $active === 'cert' ? 'active' : ''; ?>"><?php echo esc_html__('Course Certificate Settings', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-designer')); ?>" class="nias-tab <?php echo $active === 'designer' ? 'active' : ''; ?>"><?php echo esc_html__('Certificate designer', 'nias-course-widget'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-issued')); ?>" class="nias-tab <?php echo $active === 'issued' ? 'active' : ''; ?>"><?php echo esc_html__('Issued certificates', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <?php if ($spot_on) : ?>
                    <a href="<?php echo esc_url($spot_url); ?>" class="nias-tab <?php echo $active === 'spot' ? 'active' : ''; ?>"><?php echo esc_html__('SpotPlayer License', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($inst_on) : ?>
                    <a href="<?php echo esc_url($inst_url); ?>" class="nias-tab <?php echo $active === 'instructors' ? 'active' : ''; ?>"><?php echo esc_html__('Instructors', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($quiz_on) : ?>
                    <a href="<?php echo esc_url($quiz_url); ?>" class="nias-tab <?php echo $active === 'quiz' ? 'active' : ''; ?>"><?php echo esc_html__('Quiz builder', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span></a>
                    <?php $nqr_pending = function_exists('nias_quiz_pending_count') ? nias_quiz_pending_count(null) : 0; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-quiz-results')); ?>" class="nias-tab <?php echo $active === 'quizres' ? 'active' : ''; ?>"><?php echo esc_html__('Results & grading', 'nias-course-widget'); ?><?php if ($nqr_pending) : ?> <span class="nias-tab-badge" style="background:#2563c9;color:#fff"><?php echo esc_html(nias_fa_digits($nqr_pending)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_qa_enabled') && nias_qa_enabled()) : $nqa_n = nias_qa_unanswered_count(null); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-qa')); ?>" class="nias-tab <?php echo $active === 'qa' ? 'active' : ''; ?>"><?php echo esc_html__('Q&A', 'nias-course-widget'); ?><?php if ($nqa_n) : ?> <span class="nias-tab-badge" style="background:#c26a1a;color:#fff"><?php echo esc_html(nias_fa_digits($nqa_n)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_inst_approval_on') && nias_inst_panel_on()) : $nir_n = nias_inst_requests_count(); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-approvals')); ?>" class="nias-tab <?php echo $active === 'approvals' ? 'active' : ''; ?>"><?php echo esc_html__('Instructor requests', 'nias-course-widget'); ?><?php if ($nir_n) : ?> <span class="nias-tab-badge" style="background:#b45309;color:#fff"><?php echo esc_html(nias_fa_digits($nir_n)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_rev_enabled') && nias_rev_enabled()) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-revenue')); ?>" class="nias-tab <?php echo $active === 'revenue' ? 'active' : ''; ?>"><?php echo esc_html__('Instructor earnings', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_transfer_enabled') && nias_transfer_enabled()) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-transfer')); ?>" class="nias-tab <?php echo $active === 'transfer' ? 'active' : ''; ?>"><?php echo esc_html__('Course transfer', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_stats_enabled') && nias_stats_enabled()) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-stats')); ?>" class="nias-tab <?php echo $active === 'stats' ? 'active' : ''; ?>"><?php echo esc_html__('Reports', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <?php if (function_exists('nias_assign_enabled') && nias_assign_enabled()) : $nas_n = nias_assign_pending_count(null); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-assignments')); ?>" class="nias-tab <?php echo $active === 'assign' ? 'active' : ''; ?>"><?php echo esc_html__('Assignments', 'nias-course-widget'); ?><?php if ($nas_n) : ?> <span class="nias-tab-badge"><?php echo esc_html(nias_fa_digits($nas_n)); ?></span><?php endif; ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-modules')); ?>" class="nias-tab <?php echo $active === 'modules' ? 'active' : ''; ?>"><?php echo esc_html__('Modules', 'nias-course-widget'); ?><?php if (function_exists('nias_drip_enabled') && nias_drip_enabled()) : ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span><?php endif; ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-sms')); ?>" class="nias-tab <?php echo $active === 'sms' ? 'active' : ''; ?>"><?php echo esc_html__('SMS', 'nias-course-widget'); ?><?php if (function_exists('nias_sms_ready') && nias_sms_ready()) : ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span><?php endif; ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-player')); ?>" class="nias-tab <?php echo $active === 'player' ? 'active' : ''; ?>"><?php echo esc_html__('Video player', 'nias-course-widget'); ?></a>
                <?php if ($meta_on) : ?>
                    <a href="<?php echo esc_url($meta_url); ?>" class="nias-tab <?php echo $active === 'meta' ? 'active' : ''; ?>"><?php echo esc_html__('Meta', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('Active', 'nias-course-widget'); ?></span></a>
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
    echo '<div class="nias-saved"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m20 6-11 11-5-5" stroke="#0a8a44" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' . esc_html__('Settings saved.', 'nias-course-widget') . '</div>';
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
            <button type="button" class="nias-btn-soft nias-image-upload"><?php echo esc_html__('Choose image', 'nias-course-widget'); ?></button>
            <button type="button" class="nias-btn-del nias-image-clear"><?php echo esc_html__('Remove', 'nias-course-widget'); ?></button>
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
    echo '</select><div class="nias-fhint">' . esc_html__('Hold down Ctrl to select multiple items.', 'nias-course-widget') . '</div></div>';
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
    $cfg = array('source' => $source, 'multiple' => true, 'name' => $name, 'value' => $values, 'placeholder' => __('Search a name to add…', 'nias-course-widget'));
    echo '<div class="nias-field" ' . $wrap_attr . '><label class="nias-flabel">' . esc_html($label) . '</label>';
    echo '<div class="nias-picker" data-nias-picker="' . esc_attr(wp_json_encode($cfg)) . '"></div>';
    echo '<div class="nias-fhint">' . esc_html($hint !== '' ? $hint : __('Type a few letters of the name to see results; remove an item with ×.', 'nias-course-widget')) . '</div></div>';
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
    echo '<div class="nias-savebar"><button type="submit" name="' . esc_attr($name) . '" class="nias-btn-primary"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/><path d="M17 21v-8H7v8M7 3v5h8" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg> ' . esc_html__('Save changes', 'nias-course-widget') . '</button></div>';
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
        'badge'    => __('Special offer', 'nias-course-widget'),
        'title'    => __('Nias Login; beyond sign-in and sign-up', 'nias-course-widget'),
        'coupon'   => 'DM4FJ9YDNIASCOURSE',
        'coupon_label' => __('Special purchase discount code:', 'nias-course-widget'),
        'desc'     => __('<b>Sign up and log in with mobile, email and Google account</b>, <b>WooCommerce order SMS</b>, <b>SpotPlayer license delivery</b> and dozens of other handy features for a better user experience and <b>more sales</b>; lightweight, WooCommerce-compatible and optimized for site speed.', 'nias-course-widget'),
        'features' => array(
            __('Sign up and log in with mobile, email and Google account', 'nias-course-widget'),
            __('SMS for WooCommerce order status', 'nias-course-widget'),
            __('SpotPlayer license delivery support', 'nias-course-widget'),
            __('Dozens of handy features for a better user experience and more sales', 'nias-course-widget'),
        ),
        'cta'      => __('View plugin', 'nias-course-widget'),
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
    wp_add_dashboard_widget('nias_login_promo', __('Nias suggests: the Nias Login plugin', 'nias-course-widget'), 'nias_login_dashboard_widget_render');
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
        <?php nias_promo_coupon_style(); ?>
        <div class="nlp-top">
            <span class="nlp-ic"><?php echo $p['icon']; // phpcs:ignore -- static SVG ?></span>
            <div><span class="nlp-badge"><?php echo esc_html($p['badge']); ?></span><div class="nlp-t"><?php echo esc_html($p['title']); ?></div></div>
        </div>
        <ul>
            <?php foreach ($p['features'] as $i => $f) : ?>
                <li><?php echo $check; // phpcs:ignore -- static SVG ?><span><?php echo $i < 3 ? '<b>' . esc_html($f) . '</b>' : esc_html($f); ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php nias_promo_coupon($p); ?>
        <a class="nlp-cta" href="<?php echo esc_url($p['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($p['cta']); ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg></a>
    </div>
    <?php
}

/** Shared styles + copy script for the promo discount code (printed once). */
function nias_promo_coupon_style()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <style>
    .nias-cpn{display:flex;align-items:center;flex-wrap:wrap;gap:8px;width:100%;box-sizing:border-box;margin:2px 0 12px;padding:9px 11px;border:1.5px dashed #16a34a;border-radius:11px;background:#f0fdf4}
    .nias-cpn-l{font-size:12px;font-weight:700;color:#15803d}
    .nias-cpn-c{font:800 13px/1.4 ui-monospace,Consolas,monospace;letter-spacing:.5px;color:#14532d;direction:ltr;user-select:all}
    .nias-cpn-b{margin-inline-start:auto;border:0;border-radius:8px;background:#16a34a;color:#fff;font:inherit;font-size:12px;font-weight:700;padding:4px 11px;cursor:pointer}
    .nias-cpn-b:hover{background:#15803d}
    .nias-ad .nias-cpn{margin:0}
    </style>
    <script>
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('.nias-cpn-b');
        if (!b) { return; }
        e.preventDefault(); e.stopPropagation();
        var code = b.getAttribute('data-code'), done = function () { var t = b.textContent; b.textContent = b.getAttribute('data-ok'); setTimeout(function () { b.textContent = t; }, 1600); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(code).then(done); return; }
        var i = document.createElement('textarea'); i.value = code; document.body.appendChild(i); i.select();
        try { document.execCommand('copy'); done(); } catch (x) {} document.body.removeChild(i);
    }, true);
    </script>
    <?php
}

/** Discount-code box of a promo card. Inside the sidebar <a> card it is built from spans. */
function nias_promo_coupon($p, $tag = 'div')
{
    if (empty($p['coupon'])) {
        return;
    }
    $btn = $tag === 'span' ? 'span role="button" tabindex="0"' : 'button type="button"';
    $end = $tag === 'span' ? 'span' : 'button';
    printf(
        '<%1$s class="nias-cpn"><span class="nias-cpn-l">%2$s</span><span class="nias-cpn-c">%3$s</span><%4$s class="nias-cpn-b" data-code="%3$s" data-ok="%5$s">%6$s</%7$s></%1$s>',
        $tag === 'span' ? 'span' : 'div',
        esc_html(isset($p['coupon_label']) ? $p['coupon_label'] : __('Discount code:', 'nias-course-widget')),
        esc_attr($p['coupon']),
        $btn,
        esc_attr__('Copied ✓', 'nias-course-widget'),
        esc_html__('Copy', 'nias-course-widget'),
        $end
    );
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
    <?php nias_promo_coupon_style(); ?>
    <aside class="nias-set-aside">
        <div class="nias-ads-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 11l18-8-8 18-2-7-8-3Z" stroke="#3858e9" stroke-width="1.7" stroke-linejoin="round"/></svg>
            <span><?php echo esc_html__('Nias suggestions', 'nias-course-widget'); ?></span>
        </div>
        <?php foreach ($ads as $ad) :
            $accent = isset($ad['accent']) ? $ad['accent'] : '#3858e9'; ?>
            <a class="nias-ad" href="<?php echo esc_url(isset($ad['url']) ? $ad['url'] : '#'); ?>" target="_blank" rel="noopener" style="--ad-accent:<?php echo esc_attr($accent); ?>">
                <?php if (!empty($ad['badge'])) : ?><span class="nias-ad-badge"><?php echo esc_html($ad['badge']); ?></span><?php endif; ?>
                <?php if (!empty($ad['icon'])) : ?><span class="nias-ad-ic"><?php echo $ad['icon']; ?></span><?php endif; ?>
                <span class="nias-ad-title"><?php echo esc_html(isset($ad['title']) ? $ad['title'] : ''); ?></span>
                <?php if (!empty($ad['desc'])) : ?><span class="nias-ad-desc"><?php echo wp_kses($ad['desc'], array('b' => array(), 'strong' => array())); ?></span><?php endif; ?>
                <?php nias_promo_coupon($ad, 'span'); ?>
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
                    <div class="nias-alert-title"><?php echo esc_html__(' Move data to the new course builder (for versions before 1.1.5) ', 'nias-course-widget'); ?></div>
                    <div class="nias-alert-desc"><?php echo esc_html__('If you used the course builder\'s WooCommerce widget and see problems after the update, click to move the data to the new editor. Be sure to back up the site before moving.', 'nias-course-widget'); ?></div>
                    <form method="post" style="margin:0">
                        <?php wp_nonce_field('migrate_courses_nonce', '_wpnonce', true, true); ?>
                        <button type="submit" name="migrate_courses" class="nias-btn-danger">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php echo esc_html__('Start moving and syncing', 'nias-course-widget'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('nias_main_settings', 'nias_main_nonce'); ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Activation options', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_two_way_verification',
                        __('Enable two-way course purchase check', 'nias-course-widget'),
                        __('Use this mode only if purchased courses do not open for your users.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_course_account_display',
                        __('Show courses in the user account', 'nias-course-widget'),
                        __('This option only works if your user account is standard and you have used the WooCommerce widget. If nothing changes, click Save once on the <a href="/wp-admin/options-permalink.php">Permalinks</a> page.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_modern_account',
                        __('Enable modern view in the account', 'nias-course-widget'),
                        __('With this option, purchased courses on the account\'s "My courses" page are shown as cards instead of the old accordion, and each card links to that course\'s display page (modern view). To show them manually you can also use the shortcode <code dir="ltr">[nias_purchased_courses]</code>.', 'nias-course-widget'),
                        'off',
                        __('Modern', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_course_certificate',
                        __('Enable course certificate with verification', 'nias-course-widget'),
                        __('With this option, issuing and verifying course certificates is enabled. After saving, the "Course Certificate Settings" submenu appears.', 'nias-course-widget'),
                        'off',
                        __('Certificate', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_spotplayer_enabled',
                        __('Enable SpotPlayer license', 'nias-course-widget'),
                        __('With this option, SpotPlayer features (automatic license after purchase, player and download display) are enabled and the "SpotPlayer License" tab appears. To avoid conflicts, deactivate the separate SpotPlayer plugin.', 'nias-course-widget'),
                        'off',
                        __('SpotPlayer', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_instructors_enabled',
                        __('Enable instructors', 'nias-course-widget'),
                        __('With this option, the "Instructor" role is created and the "Instructors" submenu appears; there you can manage instructors and see each instructor\'s courses. Then choose each product\'s instructors on its "Edit lessons and chapters" page.', 'nias-course-widget'),
                        'off',
                        __('Instructors', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_quiz_enabled',
                        __('Enable quiz builder', 'nias-course-widget'),
                        __('With this option, the "Quiz builder" section appears; there you can build quizzes (6 question types), link them to a course and show them with the shortcode <code dir="ltr">[nias_quiz id="..."]</code> or inside the modern course view. Passing the linked quiz can be a condition for the course certificate.', 'nias-course-widget'),
                        'off',
                        __('Quiz', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_meta_enabled',
                        __('Enable meta', 'nias-course-widget'),
                        __('With this option, the "Meta" tab appears; there you can create custom metas (name and key). Then enter each meta\'s value on the product\'s "Edit lessons and chapters" page and show it with the shortcode <code dir="ltr">[nias_meta key="..."]</code>.', 'nias-course-widget'),
                        'off',
                        __('Meta', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_qa_enabled',
                        __('Enable Q&A', 'nias-course-widget'),
                        __('Students ask questions under each lesson or about the whole course and the course instructor answers. After enabling, the "Q&A" tab in the modern course view, the "Q&A" submenu and its card in the instructor dashboard appear.', 'nias-course-widget'),
                        'off',
                        __('Q&A', 'nias-course-widget')
                    );
                    echo '<div class="nias-qa-opts" data-nias-show-when="nias_qa_enabled=on">';
                    echo '<div class="nias-row-block"><div class="nias-row-title">' . esc_html__('Where to ask', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_scope', array('both' => __('Each lesson + whole course', 'nias-course-widget'), 'lessons' => __('Only under lessons', 'nias-course-widget'), 'general' => __('Only general for the course', 'nias-course-widget')), 'both');
                    echo '</div><div class="nias-row-block"><div class="nias-row-title">' . esc_html__('Who can ask', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_ask', array('access' => __('Course participants (buyers / logged-in users in a free course)', 'nias-course-widget'), 'logged' => __('All logged-in users', 'nias-course-widget')), 'access');
                    echo '</div><div class="nias-row-block"><div class="nias-row-title">' . esc_html__('Who sees the questions', 'nias-course-widget') . '</div>';
                    nias_set_segmented('nias_qa_view', array('all' => __('Everyone (even guests)', 'nias-course-widget'), 'access' => __('Only course participants', 'nias-course-widget')), 'all');
                    echo '</div>';
                    nias_set_toggle_row('nias_qa_moderation', __('Publish after approval', 'nias-course-widget'), __('A student\'s question is shown only to them until the instructor approves (or answers) it.', 'nias-course-widget'), 'off');
                    nias_set_toggle_row('nias_qa_notify', __('Email notifications', 'nias-course-widget'), __('New questions are emailed to the course instructor(s) (or the site admin if no instructor is set) and the instructor\'s answer to the person who asked.', 'nias-course-widget'), 'on');
                    echo '</div>';
                    if (function_exists('nias_wc_active') && nias_wc_active()) {
                        nias_set_toggle_row(
                            'nias_course_cpt',
                            __('"Course" post type (free courses)', 'nias-course-widget'),
                            __('A "Courses" menu is added so that next to WooCommerce products you can build free (not for sale) courses too. All course features (lessons and chapters, modern view, instructors, quiz, certificate, watch report) work on it. Public lessons are shown to everyone and "private" lessons to logged-in users.', 'nias-course-widget'),
                            'off',
                            __('Free course', 'nias-course-widget')
                        );
                    } else {
                        echo '<div class="nias-row"><div class="nias-row-main"><div class="nias-row-title">' . esc_html__('Free mode (without WooCommerce)', 'nias-course-widget') . ' <span class="nias-chip">' . esc_html__('Active', 'nias-course-widget') . '</span></div>'
                            . '<div class="nias-row-desc">' . wp_kses_post(sprintf(__('WooCommerce is not installed; build courses from the <a href="%s">"Courses"</a> menu. Public lessons are shown to everyone and "private" lessons to logged-in users. To sell courses, install WooCommerce.', 'nias-course-widget'), esc_url(admin_url('edit.php?post_type=' . NIAS_COURSE_CPT)))) . '</div></div></div>';
                    }
                    ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M10 9.5v3l3-1.5-3-1.5ZM8 21h8M12 17v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Modern course view', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-title"><?php echo esc_html__('Enable modern course view', 'nias-course-widget'); ?> <span class="nias-chip"><?php echo esc_html__('New', 'nias-course-widget'); ?></span></div>
                        <div class="nias-row-desc"><?php echo esc_html__('The modern course view includes the player, progress bar, curriculum and certificate, built from the product\'s own lessons and chapters and the plugin settings (instructors, certificate, private lessons). Choose the display mode:', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('nias_modern_course', array(
                            'off'       => __('Off', 'nias-course-widget'),
                            'auto'      => __('Automatic on the product page', 'nias-course-widget'),
                            'shortcode' => __('Manual with shortcode', 'nias-course-widget'),
                        ), 'off');
                        ?>
                        <div class="nias-row-desc" style="margin-top:12px" data-nias-show-when="nias_modern_course=auto">
                            <?php echo esc_html__('In this mode, the modern course view is shown automatically on the WooCommerce single product page (below the product summary) for products that have chapters and lessons.', 'nias-course-widget'); ?>
                        </div>
                        <div class="nias-shortcodes" style="margin-top:14px" data-nias-show-when="nias_modern_course=shortcode">
                            <div class="nias-sc-head">
                                <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span><?php echo esc_html__('Modern course view shortcodes', 'nias-course-widget'); ?></span>
                            </div>
                            <div class="nias-sc-sub"><?php echo esc_html__('The full shortcode shows the whole course. For a custom layout each part has its own shortcode and all parts stay in sync (selected lesson and shared progress). Each shortcode can take id="123".', 'nias-course-widget'); ?></div>
                            <div class="nias-sc-list">
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course]</code><span><?php echo esc_html__('Full course view', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_header]</code><span><?php echo esc_html__('Course header + progress bar + certificate button', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_player]</code><span><?php echo esc_html__('Current lesson player', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_lesson]</code><span><?php echo esc_html__('Lesson info bar (navigation and description/resources)', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_curriculum]</code><span><?php echo esc_html__('Chapters and lessons list', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_certificate]</code><span><?php echo esc_html__('Course completion certificate section', 'nias-course-widget'); ?></span></div>
                                <div class="nias-sc-item"><code dir="ltr">[nias_modern_course_button]</code><span><?php echo esc_html__('Button to enter the modern course view (independent of the display mode)', 'nias-course-widget'); ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 11h14v9H5z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Private lesson lock', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('For "private" lessons when the user has not bought the course yet, choose which parts are locked (hidden). This applies to the modern course view, the account view and the Elementor WooCommerce widget. By default everything is "locked".', 'nias-course-widget'); ?></div>
                    </div>
                    <?php
                    nias_set_toggle_row(
                        'nias_lock_private_preview',
                        __('Lock preview / lesson video', 'nias-course-widget'),
                        __('Hides the "Preview" link in the account view and widget, and the lesson video player in the modern course view, for users who have not bought the course.', 'nias-course-widget'),
                        'on'
                    );
                    nias_set_toggle_row(
                        'nias_lock_private_content',
                        __('Lock description / lesson content', 'nias-course-widget'),
                        __('Hides the lesson\'s text content (description and text lessons) for users who have not bought the course.', 'nias-course-widget'),
                        'on'
                    );
                    nias_set_toggle_row(
                        'nias_lock_private_attachments',
                        __('Lock resources and attachment (download file)', 'nias-course-widget'),
                        __('Hides the lesson\'s download file, resources and attachments for users who have not bought the course.', 'nias-course-widget'),
                        'on'
                    );
                    nias_set_toggle_row(
                        'nias_secure_media',
                        __('Personal video and file links for each user', 'nias-course-widget'),
                        __('The real address of private lesson videos and files is never sent to the browser; each user gets their own signed link (valid for 12 hours). If someone else opens the link, they see "You are not logged in" or "This link does not belong to you". Aparat/YouTube videos and HLS/DASH streams are not covered by this option.', 'nias-course-widget'),
                        'off',
                        __('Security', 'nias-course-widget')
                    );
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- Video player: settings live on their own page -->
                <div class="nias-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px 18px">
                    <div>
                        <div class="nias-row-title"><?php echo esc_html__('Video player and watch tracking', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('Look, controls, speeds, watermark, seek prevention, lesson completion percentage and HLS/DASH settings with a live preview.', 'nias-course-widget'); ?></div>
                    </div>
                    <a class="nias-btn-soft" style="text-decoration:none;display:inline-flex" href="<?php echo esc_url(admin_url('admin.php?page=nias-course-player')); ?>"><?php echo esc_html__('Player settings', 'nias-course-widget'); ?></a>
                </div>
                <?php nias_set_save_button('nias_save_main_settings'); ?>
            </form>

            <!-- Help / tutorials -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="m10 8 6 4-6 4V8Z" fill="#3858e9"/><rect x="2" y="4" width="20" height="16" rx="3" stroke="#3858e9" stroke-width="1.8"/></svg>', __('How to use the plugin', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nias-vid-note" style="display:flex;flex-wrap:wrap;align-items:center;justify-content: space-between;gap:14px;padding:16px 18px;background:#f1f4f9;border:1px solid #e2e6ee;border-radius:12px">
                        <span style="font-size:15px;font-weight:700;color:#33415c;line-height:2;text-align:center"><?php echo esc_html__('Follow the course builder tutorials on Nias Aparat and Nias YouTube', 'nias-course-widget'); ?></span>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <a href="https://www.aparat.com/playlist/26525943/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 16px;border-radius:10px;background:#ed145b;color:#fff;font-size:14px;font-weight:700;text-decoration:none">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4.2a5.8 5.8 0 1 1 0 11.6 5.8 5.8 0 0 1 0-11.6zm0 2.3a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z"/></svg>
                                <?php echo esc_html__('Nias Aparat', 'nias-course-widget'); ?>
                            </a>
                            <a href="https://www.youtube.com/watch?v=WyBlPnjvVv4&list=PLl88gtSh81bU57NKRpZqoAZaFJCok7-mx" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 16px;border-radius:10px;background:#ff0000;color:#fff;font-size:14px;font-weight:700;text-decoration:none">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>
                                <?php echo esc_html__('Nias YouTube', 'nias-course-widget'); ?>
                            </a>
                        </div>
                    </div>

                    <div class="nias-tg">
                        <div class="nias-tg-text"><?php echo esc_html__('If you have a problem or question, contact us on Telegram.', 'nias-course-widget'); ?></div>
                        <a href="https://t.me/niasir" target="_blank" rel="noopener" class="nias-tg-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="#3858e9"><path d="M23.9 4.3 20.5 20c-.25 1.1-.92 1.38-1.86.86l-5.14-3.79-2.48 2.39c-.27.27-.5.5-1.03.5l.37-5.23 9.5-8.59c.42-.37-.09-.57-.65-.21L6.97 12.8l-5.06-1.58c-1.1-.34-1.12-1.1.23-1.63L21.92 1.97c.92-.34 1.72.21 1.42 1.63Z"/></svg>
                            T.me/niasir
                        </a>
                    </div>

                    <div class="nias-banners">
                        <a href="https://nias.ir" target="_blank" rel="noopener" class="nias-banner nias-banner-blue">
                            <div>
                                <div class="nias-banner-t"><?php echo esc_html__('Nias web design tutorials', 'nias-course-widget'); ?></div>
                                <div class="nias-banner-s">nias.ir</div>
                            </div>
                            <span class="nias-banner-cta"><?php echo esc_html__('Log in', 'nias-course-widget'); ?></span>
                        </a>
                        <a href="https://proelement.ir" target="_blank" rel="noopener" class="nias-banner nias-banner-red">
                            <div>
                                <div class="nias-banner-t" style="color:#401f24"><?php echo esc_html__('ProElement — download theme', 'nias-course-widget'); ?></div>
                                <div class="nias-banner-s" style="color:#9a7b7e">proelement.ir</div>
                            </div>
                            <span class="nias-banner-cta nias-banner-cta-red"><?php echo esc_html__('Log in', 'nias-course-widget'); ?></span>
                        </a>
                    </div>
                </div>
            <?php nias_set_card_close(); ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('Thank you for building your site with <span style="color:#3858e9;font-weight:600">WordPress</span>.', 'nias-course-widget')); ?></div>
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

    $page_options = array('' => __('Select page', 'nias-course-widget'));
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
            $category_options[$category->term_id] = $category->name . (count($cat_taxes) > 1 && $category->taxonomy !== 'product_cat' ? ' ' . __('(course)', 'nias-course-widget') : '');
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
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="5" stroke="#3858e9" stroke-width="1.8"/><path d="m8.5 13-1.5 8 5-2.5 5 2.5-1.5-8" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Show certificate', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-title"><?php echo esc_html__('Certificate display mode', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('Choose how the certificate is shown for products.', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('certificate_display_type', array(
                            'all'      => __('All products', 'nias-course-widget'),
                            'selected' => __('Selected product', 'nias-course-widget'),
                            'category' => __('From category', 'nias-course-widget'),
                            'none'     => __('None', 'nias-course-widget'),
                        ), 'none');
                        nias_set_picker_field('certificate_selected_products', __('Selected products', 'nias-course-widget'), 'product', 'data-nias-show-when="certificate_display_type=selected"');
                        nias_set_multiselect_field('certificate_selected_categories', __('Selected categories', 'nias-course-widget'), $category_options, 'data-nias-show-when="certificate_display_type=category"');
                        ?>
                    </div>
                    <div class="nias-row-block nias-row-sep">
                        <?php nias_set_select_field('certificate_display_page', __('Certificate display page', 'nias-course-widget'), __('Choose the page where the certificate should be shown.', 'nias-course-widget'), $page_options); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Design mode card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Certificate design mode', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('In "Classic" mode the certificate is built with the previous design and the images and texts below on this page. In "Advanced designer" mode it is built from a template you designed yourself with drag and drop in the "Certificate designer".', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_segmented('certificate_design_mode', array(
                            'classic'  => __('Classic (current design)', 'nias-course-widget'),
                            'designer' => __('Advanced designer (canvas)', 'nias-course-widget'),
                        ), 'classic');
                        ?>
                    </div>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_design_mode=designer">
                        <?php
                        if ($template_options) {
                            nias_set_select_field('certificate_default_template', __('Default template', 'nias-course-widget'), __('For each course you can choose a separate template on the product edit page ("Course certificate" box).', 'nias-course-widget'), array('' => __('— Select template —', 'nias-course-widget')) + $template_options);
                        } else {
                            echo '<div class="nias-row-desc">' . esc_html__('You have not built a template yet. Until you do, certificates are issued with the classic design.', 'nias-course-widget') . '</div>';
                        }
                        ?>
                        <a class="nias-btn-soft" style="display:inline-flex;margin-top:12px;text-decoration:none" href="<?php echo esc_url(admin_url('admin.php?page=nias-course-cert-designer')); ?>"><?php echo esc_html__('Open certificate designer', 'nias-course-widget'); ?></a>
                    </div>
                    <?php nias_set_toggle_row('certificate_freeze_design', __('Keep the design of issued certificates', 'nias-course-widget'), __('Each certificate is built with the design it had when issued; editing or replacing the template only affects new certificates (like a paper certificate that does not change after printing). For a specific certificate you can apply the current template in "Issued certificates". Certificates issued before version 1.5.1 still follow the current template.', 'nias-course-widget'), 'on', '', 'data-nias-show-when="certificate_design_mode=designer"'); ?>
                <?php nias_set_card_close(); ?>

                <!-- Conditions card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3 8-8" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Certificate requirements', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('These requirements apply to all courses, and custom requirements can be set on each product\'s edit page ("Course certificate" box). Students see in their certificate list which requirement is not met yet. Certificates already issued are not revoked when requirements become stricter.', 'nias-course-widget'); ?></div>
                        <div class="nias-row-title" style="margin:14px 0 10px"><?php echo esc_html__('Accepted order status', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_radio_cards('certificate_cond_order_status', array(
                            'completed_processing' => __('Completed or processing', 'nias-course-widget'),
                            'completed'            => __('Only completed orders', 'nias-course-widget'),
                        ), 'completed_processing');
                        ?>
                    </div>
                    <?php
                    nias_set_toggle_row('certificate_cond_quiz', __('Require passing the quiz linked to the course', 'nias-course-widget'), __('If a quiz is linked to the course, no certificate is issued until it is passed.', 'nias-course-widget'), 'on');
                    ?>
                    <div class="nias-row-block nias-row-sep">
                        <div style="display:flex;gap:16px;flex-wrap:wrap">
                            <?php
                            nias_set_number_field('certificate_cond_min_progress', __('Minimum course progress percentage', 'nias-course-widget'), __('0 means disabled. Progress is recorded from the modern course view.', 'nias-course-widget'), 0, 100);
                            nias_set_number_field('certificate_cond_wait_days', __('Minimum days after purchase', 'nias-course-widget'), __('0 means disabled.', 'nias-course-widget'), 0);
                            ?>
                        </div>
                    </div>
                    <?php
                    nias_set_toggle_row('certificate_cond_require_name', __('Require first and last name', 'nias-course-widget'), __('No certificate is issued until the student completes their first and last name; the name form is shown on the same certificates page.', 'nias-course-widget'), 'off');
                    nias_set_toggle_row('certificate_cond_approval', __('Require manual admin approval', 'nias-course-widget'), __('New certificates are recorded as "Awaiting approval" and become downloadable and verifiable after you approve them on the "Issued certificates" page.', 'nias-course-widget'), 'off');
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- My Account tab card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21a8 8 0 0 1 16 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Show certificates in the user account', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('certificate_account_endpoint', __('Add a "My certificates" tab to the user account', 'nias-course-widget'), __('A new page (endpoint) is added to the WooCommerce account menu where students see their course certificates, the requirement status and the download button. There is no need to save the permalinks again.', 'nias-course-widget'), 'off'); ?>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_account_endpoint=on">
                        <div style="display:flex;gap:16px;flex-wrap:wrap">
                            <div style="flex:1;min-width:220px"><?php nias_set_text_field('certificate_account_title', __('Menu title', 'nias-course-widget'), __('My certificates', 'nias-course-widget')); ?></div>
                            <div style="flex:1;min-width:220px"><?php nias_set_text_field('certificate_account_slug', __('Endpoint address (Latin)', 'nias-course-widget'), 'my-certificates'); ?></div>
                        </div>
                        <?php if (function_exists('nias_cert_account_url') && nias_cert_account_url()) : ?>
                            <div class="nias-row-desc" style="margin-top:10px"><?php echo esc_html__('Current address:', 'nias-course-widget'); ?> <a href="<?php echo esc_url(nias_cert_account_url()); ?>" target="_blank" dir="ltr"><?php echo esc_html(nias_cert_account_url()); ?></a></div>
                        <?php endif; ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Verification card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Certificate verification', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <?php nias_set_select_field('certificate_verify_page', __('Certificate verification page', 'nias-course-widget'), __('The page where you placed the shortcode [nias_certificate_verify]. Once selected, the QR code of new certificates points to the verification form (QR links of old certificates keep working). If left empty, the certificate display page is used, and if that is not set either, the plugin\'s built-in verification page (/?nias_verify=code); so certificate QR codes never lead to an error page.', 'nias-course-widget'), $page_options); ?>
                    </div>
                    <?php nias_set_toggle_row('certificate_verify_mask_name', __('Mask part of the name in the verification result', 'nias-course-widget'), __('For privacy, the holder\'s name is shown like "J***n S***h". With this option on, the "View certificate (PDF)" button is not shown in the verification result.', 'nias-course-widget'), 'off'); ?>
                    <div class="nias-row-block nias-row-sep">
                        <div class="nias-row-title"><?php echo esc_html__('Verification via API', 'nias-course-widget'); ?></div>
                        <div class="nias-row-desc"><?php echo esc_html__('Other systems (employers, LMS) can check a certificate\'s validity as JSON with this address. The request limit and name masking apply to it too.', 'nias-course-widget'); ?></div>
                        <code dir="ltr" style="display:inline-block;margin-top:8px"><?php echo esc_html(rest_url('nias/v1/certificates/')); ?>{CODE}</code>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- E-mail card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="m4 7 8 6 8-6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Automatic certificate email', 'nias-course-widget')); ?>
                    <div id="nias-cert-email"></div>
                    <?php if (isset($_GET['nias_test_mail'])) : ?>
                        <div class="nias-row-block"><div class="nias-sign-msg <?php echo $_GET['nias_test_mail'] === 'ok' ? 'ok' : 'bad'; ?>" style="padding:10px 14px;border-radius:10px;font-size:13px;background:<?php echo $_GET['nias_test_mail'] === 'ok' ? '#ecfbf1' : '#fff1f1'; ?>"><?php echo $_GET['nias_test_mail'] === 'ok' ? esc_html(sprintf(__('A test email was sent to %s.', 'nias-course-widget'), wp_get_current_user()->user_email)) : esc_html__('Sending the email failed; check the site\'s SMTP settings.', 'nias-course-widget'); ?></div></div>
                    <?php endif; ?>
                    <?php nias_set_toggle_row('certificate_email_on_issue', __('Send an email when a certificate is issued', 'nias-course-widget'), __('As soon as the student meets the requirements (order completed, quiz passed, course progress) or the admin approves the certificate, a congratulation email with the verification link is sent. Each certificate is emailed only once and sending happens in the background.', 'nias-course-widget'), 'off'); ?>
                    <div data-nias-show-when="certificate_email_on_issue=on">
                        <?php nias_set_toggle_row('certificate_email_attach', __('Attach the certificate PDF', 'nias-course-widget'), __('If the digital signature is active, the attached PDF is signed too.', 'nias-course-widget'), 'on'); ?>
                        <div class="nias-row-block nias-row-sep">
                            <?php nias_set_text_field('certificate_email_subject', __('Email subject', 'nias-course-widget'), nias_cert_email_default_subject()); ?>
                            <?php nias_set_textarea_field('certificate_email_body', __('Email body', 'nias-course-widget'), nias_cert_email_default_body()); ?>
                            <div class="nias-row-desc"><?php echo esc_html__('Available fields: {student_name} {course_name} {code} {date} {verify_url} {account_url} {instructor} {site_name}. The "Get certificate" and "Verification page" buttons are added to the end of the email automatically.', 'nias-course-widget'); ?></div>
                            <a class="nias-btn-soft" style="display:inline-flex;margin-top:12px;text-decoration:none" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=nias_cert_test_email'), 'nias_cert_test_email')); ?>"><?php echo esc_html__('Send me a test email (with the saved settings)', 'nias-course-widget'); ?></a>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Shortcodes card -->
                <div class="nias-shortcodes">
                    <div class="nias-sc-head">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php echo esc_html__('Shortcodes', 'nias-course-widget'); ?></span>
                    </div>
                    <div class="nias-sc-sub"><?php echo esc_html__('Use the shortcode on the page you selected.', 'nias-course-widget'); ?></div>
                    <div class="nias-sc-list">
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate]</code><span><?php echo esc_html__('Show certificate', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate_preview]</code><span><?php echo esc_html__('Preview', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_button_certificate]</code><span><?php echo esc_html__('Button leading to the certificate', 'nias-course-widget'); ?></span></div>
                        <div class="nias-sc-item"><code dir="ltr">[nias_certificate_verify]</code><span><?php echo esc_html__('Certificate verification form by code', 'nias-course-widget'); ?></span></div>
                    </div>
                    <div class="nias-sc-warn"><?php echo esc_html__('⚠ Remember to remove the preview shortcode after testing.', 'nias-course-widget'); ?></div>
                </div>

                <!-- Images card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="#3858e9" stroke-width="1.8"/><circle cx="8.5" cy="8.5" r="1.5" fill="#3858e9"/><path d="m21 15-5-5L5 21" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Certificate images', 'nias-course-widget')); ?>
                    <div class="nias-card-padx">
                        <?php
                        nias_set_image_row('certificate_watermark', __('Your logo image', 'nias-course-widget'), __('Upload the certificate\'s brand or logo (not svg).', 'nias-course-widget'));
                        nias_set_image_row('certificate_header_bg', __('Header background image', 'nias-course-widget'), __('Background of the top part of the certificate (not svg).', 'nias-course-widget'));
                        nias_set_image_row('certificate_footer_bg', __('Footer background image', 'nias-course-widget'), __('Background of the bottom part of the certificate (not svg).', 'nias-course-widget'));
                        nias_set_image_row('certificate_icon', __('Certificate emblem', 'nias-course-widget'), __('Upload the certificate\'s emblem or icon (not svg).', 'nias-course-widget'));
                        ?>
                        <div class="nias-dlsamples">
                            <div>
                                <div class="nias-dls-title"><?php echo esc_html__('Download sample certificate images', 'nias-course-widget'); ?></div>
                                <div class="nias-dls-sub"><?php echo esc_html__('Ready sample images including header, footer, watermark and icon.', 'nias-course-widget'); ?></div>
                            </div>
                            <a href="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/images/certificate.zip'); ?>" class="nias-dls-btn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php echo esc_html__('Download sample images', 'nias-course-widget'); ?>
                            </a>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Texts card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 7V5h16v2M9 19h6M12 5v14" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Certificate texts', 'nias-course-widget')); ?>
                    <div class="nias-card-pad nias-stack">
                        <?php
                        nias_set_text_field('certificate_first_title', __('Certificate first title', 'nias-course-widget'), __('Main title at the top of the certificate', 'nias-course-widget'));
                        nias_set_text_field('certificate_before_name_title', __('Title before the student name', 'nias-course-widget'), __('Text before the student name', 'nias-course-widget'));
                        nias_set_text_field('certificate_after_name_title', __('Title after the student name', 'nias-course-widget'), __('Text after the student name', 'nias-course-widget'));
                        ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Date card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M3 9h18M8 3v4M16 3v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Certificate date', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('certificate_show_date', __('Show date', 'nias-course-widget'), '', 'on'); ?>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="certificate_show_date=on">
                        <div class="nias-row-title" style="margin-bottom:12px"><?php echo esc_html__('Date source', 'nias-course-widget'); ?></div>
                        <?php
                        nias_set_radio_cards('certificate_date_source', array(
                            'purchase_date'         => __('Course purchase date', 'nias-course-widget'),
                            'manual_date'           => __('Manual date', 'nias-course-widget'),
                            'user_certificate_date' => __('Date from the user\'s nias_certificate_date field', 'nias-course-widget'),
                        ), 'purchase_date');
                        nias_set_date_field('certificate_manual_date', __('Manual date', 'nias-course-widget'), 'data-nias-show-when="certificate_date_source=manual_date"');
                        ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- Signature card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 17c4-1 4-9 7-9s2 6 5 6 3-3 6-3" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/><path d="M3 21h18" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Seal and signature', 'nias-course-widget')); ?>
                    <div class="nias-card-padx">
                        <?php
                        nias_set_image_row('certificate_seal_image', __('Your seal image', 'nias-course-widget'), __('Upload your official seal (not svg).', 'nias-course-widget'));
                        nias_set_image_row('certificate_signature_image', __('Signature image', 'nias-course-widget'), __('Upload the signature of the person issuing the certificate (not svg).', 'nias-course-widget'));
                        ?>
                        <div class="nias-signer"><?php nias_set_text_field('certificate_signer_name', __('Signer name', 'nias-course-widget'), __('Name of the person who signs the certificate', 'nias-course-widget')); ?></div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_save_button('nias_save_certificate_settings'); ?>
            </form>

            <?php if (function_exists('nias_cert_sign_settings_card')) { nias_cert_sign_settings_card(); } ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('Thank you for building your site with <span style="color:#3858e9;font-weight:600">WordPress</span>.', 'nias-course-widget')); ?></div>
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
        strpos($hook, 'nias-course-sms') === false &&
        strpos($hook, 'nias-course-modules') === false &&
        strpos($hook, 'nias-course-assignments') === false &&
        strpos($hook, 'nias-course-stats') === false &&
        strpos($hook, 'nias-course-transfer') === false &&
        strpos($hook, 'nias-course-revenue') === false &&
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
                esc_html__('Data was moved and synced successfully; please check the pages after clearing the cache', 'nias-course-widget') .
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
