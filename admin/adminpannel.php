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
        'nias_meta_enabled'           => 'radio',
        'nias_modern_course'          => 'radio',
    );
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
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 6.5C4 5.67 4.67 5 5.5 5H11v14H5.5A1.5 1.5 0 0 1 4 17.5v-11Z" fill="#fff" opacity=".9"/><path d="M20 6.5C20 5.67 19.33 5 18.5 5H13v14h5.5a1.5 1.5 0 0 0 1.5-1.5v-11Z" fill="#fff"/></svg>
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
                <?php endif; ?>
                <?php if ($spot_on) : ?>
                    <a href="<?php echo esc_url($spot_url); ?>" class="nias-tab <?php echo $active === 'spot' ? 'active' : ''; ?>"><?php echo esc_html__('لایسنس اسپات پلیر', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($inst_on) : ?>
                    <a href="<?php echo esc_url($inst_url); ?>" class="nias-tab <?php echo $active === 'instructors' ? 'active' : ''; ?>"><?php echo esc_html__('مدرسین', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
                <?php if ($quiz_on) : ?>
                    <a href="<?php echo esc_url($quiz_url); ?>" class="nias-tab <?php echo $active === 'quiz' ? 'active' : ''; ?>"><?php echo esc_html__('آزمون‌ساز', 'nias-course-widget'); ?> <span class="nias-tab-badge"><?php echo esc_html__('فعال', 'nias-course-widget'); ?></span></a>
                <?php endif; ?>
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
function nias_settings_ads_sidebar()
{
    $ads = array(
        array(
            'badge'  => __('پیشنهاد ویژه', 'nias-course-widget'),
            'title'  => __('بهینه‌ترین افزونه ورود و ثبت‌نام پیامکی', 'nias-course-widget'),
            'desc'   => __('ورود و عضویت سریع کاربران با پیامک؛ سبک، سازگار با ووکامرس و بهینه برای سرعت سایت.', 'nias-course-widget'),
            'cta'    => __('مشاهده افزونه', 'nias-course-widget'),
            'url'    => 'https://nias.ir/',
            'accent' => '#16a34a',
            'icon'   => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h.01M12 11h.01M16 11h.01"/></svg>',
        ),
    );

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
                <?php if (!empty($ad['desc'])) : ?><span class="nias-ad-desc"><?php echo esc_html($ad['desc']); ?></span><?php endif; ?>
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
                            </div>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

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
    $product_options = array();
    foreach (get_posts(array('post_type' => 'product', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC')) as $product) {
        $product_options[$product->ID] = $product->post_title;
    }

    $page_options = array('' => __('انتخاب صفحه', 'nias-course-widget'));
    foreach (get_pages(array('post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC')) as $page) {
        $page_options[$page->ID] = $page->post_title;
    }

    $category_options = array();
    $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    if (!is_wp_error($categories)) {
        foreach ($categories as $category) {
            $category_options[$category->term_id] = $category->name;
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
                        nias_set_multiselect_field('certificate_selected_products', __('محصولات انتخابی', 'nias-course-widget'), $product_options, 'data-nias-show-when="certificate_display_type=selected"');
                        nias_set_multiselect_field('certificate_selected_categories', __('دسته‌بندی‌های انتخابی', 'nias-course-widget'), $category_options, 'data-nias-show-when="certificate_display_type=category"');
                        ?>
                    </div>
                    <div class="nias-row-block nias-row-sep">
                        <?php nias_set_select_field('certificate_display_page', __('صفحه نمایش مدرک', 'nias-course-widget'), __('صفحه‌ای که می‌خواهید مدرک در آن نمایش داده شود را انتخاب کنید.', 'nias-course-widget'), $page_options); ?>
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
        strpos($hook, 'nias-spotplayer-license') === false &&
        strpos($hook, 'nias-course-instructors') === false &&
        strpos($hook, 'nias-course-quiz') === false &&
        strpos($hook, 'nias-course-meta') === false
    ) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style('nias-admin-css', NIASADMIN_URL . '/adminstyle.css', array(), NIAS_COURSE_VERSION);
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
