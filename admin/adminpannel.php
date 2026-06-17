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
        'nias_course_certificate'     => 'radio',
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
    <div class="wrap nias-course-settings">
        <h1><?php echo esc_html__('تنظیمات دوره ساز نیاس', 'nias-course-widget'); ?></h1>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('تنظیمات ذخیره شد.', 'nias-course-widget'); ?></p></div>
        <?php endif; ?>

        <div class="nias-course-migrate">
            <h2><?php echo esc_html__('انتقال دیتا ها به دوره ساز جدید', 'nias-course-widget'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('migrate_courses_nonce', '_wpnonce', true, true); ?>
                <p><?php echo esc_html__('چنانچه از ویجت ووکامرس دوره ساز استفاده میکردید و مشکلاتی را در آپدیت جدید مشاهده میکنید جهت انتقال داده ها به ویرایشگر جدید کلیک کنید توجه کنید حتماً از سایت بک اپ تهیه کنید :', 'nias-course-widget'); ?></p>
                <input type="submit" name="migrate_courses" class="button button-primary" value="<?php echo esc_attr__('شروع انتقال و همگام سازی', 'nias-course-widget'); ?>">
            </form>
        </div>

        <form method="post">
            <?php wp_nonce_field('nias_main_settings', 'nias_main_nonce'); ?>
            <table class="form-table" role="presentation">
                <?php
                nias_render_radio(
                    'nias_two_way_verification',
                    __('فعالسازی حالت دو جانبه بررسی خرید دوره ها', 'nias-course-widget'),
                    array('off' => __('غیرفعال', 'nias-course-widget'), 'on' => __('فعال', 'nias-course-widget')),
                    'off',
                    __('توجه این حالت تنها در صورتی استفاده شود که دوره های خریداری شده برای کاربران شما باز نمیشود', 'nias-course-widget'),
                    'nias-toggle-switch'
                );

                nias_render_radio(
                    'nias_course_account_display',
                    __('فعالسازی نمایش دوره در حساب کاربری', 'nias-course-widget'),
                    array('off' => __('غیرفعال', 'nias-course-widget'), 'on' => __('فعال', 'nias-course-widget')),
                    'off',
                    __('این گزینه فقط در صورتی که حساب کاربری شما یک حساب کاربری استاندارد باشد عمل خواهد کرد همچنین حتماً باید از ویجت ووکامرسی استفاده کرده باشید.<br>اگه تغییری حاصل نشد یکبار روی ذخیره تغییرات در صفحه <a href="/wp-admin/options-permalink.php">پیوندهای یکتا</a> کلیک کنید', 'nias-course-widget'),
                    'nias-toggle-switch'
                );

                nias_render_radio(
                    'nias_course_certificate',
                    __('فعالسازی مدرک دوره با قابلیت استعلام', 'nias-course-widget'),
                    array('off' => __('غیرفعال', 'nias-course-widget'), 'on' => __('فعال', 'nias-course-widget')),
                    'off',
                    __('با فعال کردن این گزینه، امکان صدور و استعلام مدرک دوره فعال خواهد شد (پس از ذخیره، زیرمنوی «تنظیمات مدرک دوره» نمایش داده می‌شود)', 'nias-course-widget'),
                    'nias-toggle-switch'
                );
                ?>
            </table>
            <?php submit_button(__('ذخیره تغییرات', 'nias-course-widget'), 'primary', 'nias_save_main_settings'); ?>
        </form>

        <div class="nias-course-help">
            <h2><?php echo esc_html__('آموزش استفاده از پلاگین را از اینجا ببینید', 'nias-course-widget'); ?></h2>
            <div style="max-width: 100%; display: flex; gap: 20px;">
                <div style="flex: 1; max-width: 363px;">
                    <h3>پارت 1</h3>
                    <div class="h_iframe-aparat_embed_frame">
                        <span style="display: block;padding-top: 57%"></span>
                        <iframe src="https://www.aparat.com/video/video/embed/videohash/b90c8sh/vt/frame?titleShow=true&recom=self" allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe>
                    </div>
                </div>
                <div style="flex: 1; max-width: 363px;">
                    <h3>پارت 2</h3>
                    <div class="h_iframe-aparat_embed_frame">
                        <span style="display: block;padding-top: 57%"></span>
                        <iframe src="https://www.aparat.com/video/video/embed/videohash/lcd5qbk/vt/frame" allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe>
                    </div>
                </div>
            </div>
            <p><?php echo esc_html__('لطفاً در صورت وجود مشکل یا سوال از طریق تلگرام با بنده در ارتباط باشید', 'nias-course-widget'); ?></p>
            <a href="https://T.me/niasir">T.me/niasir</a>

            <div style="display: flex; gap: 20px; margin: 20px 0; align-items: center;justify-content: space-between;">
                <a href="https://nias.ir" target="_blank" rel="noopener">
                    <img src="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/images/nias.webp'); ?>" alt="Nias" style="height: auto;">
                </a>
                <a href="https://proelement.ir" target="_blank" rel="noopener">
                    <img src="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/images/proelement.webp'); ?>" alt="Pro Element" style="height: auto;">
                </a>
            </div>
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
    <div class="wrap nias-course-settings">
        <h1><?php echo esc_html__('تنظیمات مدرک دوره', 'nias-course-widget'); ?></h1>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('تنظیمات ذخیره شد.', 'nias-course-widget'); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('nias_certificate_settings', 'nias_certificate_nonce'); ?>
            <table class="form-table" role="presentation">
                <?php
                nias_render_radio(
                    'certificate_display_type',
                    __('نحوه نمایش مدرک', 'nias-course-widget'),
                    array(
                        'all'      => __('همه محصولات', 'nias-course-widget'),
                        'selected' => __('محصول انتخابی', 'nias-course-widget'),
                        'category' => __('محصول از دسته بندی', 'nias-course-widget'),
                        'none'     => __('هیچکدام', 'nias-course-widget'),
                    ),
                    'none',
                    __('نحوه نمایش مدرک برای محصولات را انتخاب کنید', 'nias-course-widget')
                );

                nias_render_multiselect(
                    'certificate_selected_products',
                    __('محصولات انتخابی', 'nias-course-widget'),
                    $product_options,
                    __('محصولاتی که می‌خواهید مدرک برای آنها نمایش داده شود را انتخاب کنید', 'nias-course-widget'),
                    'data-nias-show-when="certificate_display_type=selected"'
                );

                nias_render_select(
                    'certificate_display_page',
                    __('صفحه نمایش مدرک', 'nias-course-widget'),
                    $page_options,
                    __('صفحه‌ای که می‌خواهید مدرک در آن نمایش داده شود را انتخاب کنید', 'nias-course-widget')
                );
                ?>
                <tr><td colspan="2">
                    <div style="background: #fff3cd; padding: 10px; border: 1px solid #ffeeba; border-radius: 4px;">
                        <strong>شورت‌کدها:</strong><br><br>
                        <span style="color: #856404;">از شورت کد در صفحه ای که انتخاب کردی استفاده کن </span><br><br>
                        <code>[nias_certificate]</code> برای نمایش مدرک<br><br>
                        <code>[nias_certificate_preview]</code> برای پیش‌نمایش<br><br>
                        <code>[nias_button_certificate]</code> دکمه هدایت کاربر به دریافت مدرک<br><br>
                        <span style="color: #856404;">حواست باشه بعد از تست، شورت‌کد پیش‌نمایش رو برداری </span>
                    </div>
                </td></tr>
                <?php
                nias_render_multiselect(
                    'certificate_selected_categories',
                    __('دسته‌بندی‌های انتخابی', 'nias-course-widget'),
                    $category_options,
                    __('دسته‌بندی‌هایی که می‌خواهید مدرک برای محصولات آنها نمایش داده شود را انتخاب کنید', 'nias-course-widget'),
                    'data-nias-show-when="certificate_display_type=category"'
                );

                nias_render_image('certificate_watermark', __('تصویر لوگوی شما', 'nias-course-widget'), __('تصویر مارک یا لوگوی مدرک را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                nias_render_image('certificate_header_bg', __('تصویر پس زمینه هدر', 'nias-course-widget'), __('تصویر پس زمینه بخش بالای مدرک را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                nias_render_image('certificate_footer_bg', __('تصویر پس زمینه فوتر', 'nias-course-widget'), __('تصویر پس زمینه بخش پایین مدرک را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                nias_render_image('certificate_icon', __('نماد سرتیفیکت', 'nias-course-widget'), __('نماد یا آیکون مدرک را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                ?>
                <tr><td colspan="2">
                    <div style="background: #e5f6ff; padding: 15px; border: 1px solid #b8e6ff; border-radius: 4px; margin: 10px 0;">
                        <h3 style="margin-top: 0;">دانلود نمونه تصاویر مدرک</h3>
                        <p>برای دانلود نمونه تصاویر آماده مدرک (شامل هدر، فوتر، واترمارک و آیکون) روی لینک زیر کلیک کنید:</p>
                        <p><a href="<?php echo esc_url(plugin_dir_url(__DIR__) . 'assets/images/certificate.zip'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                            دانلود نمونه تصاویر مدرک
                        </a></p>
                    </div>
                </td></tr>
                <?php
                nias_render_text('certificate_first_title', __('تایتل اول مدرک', 'nias-course-widget'), __('عنوان اصلی که در بالای مدرک نمایش داده می‌شود', 'nias-course-widget'));
                nias_render_text('certificate_before_name_title', __('تایتل قبل از نام دانشجو', 'nias-course-widget'), __('متنی که قبل از نام دانشجو نمایش داده می‌شود', 'nias-course-widget'));
                nias_render_text('certificate_after_name_title', __('تایتل بعد از نام دانشجو', 'nias-course-widget'), __('متنی که بعد از نام دانشجو نمایش داده می‌شود', 'nias-course-widget'));

                nias_render_radio(
                    'certificate_show_date',
                    __('نمایش تاریخ', 'nias-course-widget'),
                    array('on' => __('فعال', 'nias-course-widget'), 'off' => __('غیرفعال', 'nias-course-widget')),
                    'on',
                    ''
                );

                nias_render_radio(
                    'certificate_date_source',
                    __('منبع تاریخ', 'nias-course-widget'),
                    array(
                        'purchase_date'         => __('تاریخ خرید دوره', 'nias-course-widget'),
                        'manual_date'           => __('تاریخ دستی', 'nias-course-widget'),
                        'user_certificate_date' => __('تاریخ از فیلد nias_certificate_date کاربر', 'nias-course-widget'),
                    ),
                    'purchase_date',
                    '',
                    '',
                    'data-nias-show-when="certificate_show_date=on"'
                );

                nias_render_date(
                    'certificate_manual_date',
                    __('تاریخ دستی', 'nias-course-widget'),
                    '',
                    'data-nias-show-when="certificate_date_source=manual_date"'
                );

                nias_render_image('certificate_seal_image', __('تصویر مهر شما', 'nias-course-widget'), __('تصویر مهر رسمی خود را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                nias_render_image('certificate_signature_image', __('تصویر امضا', 'nias-course-widget'), __('تصویر امضای مسئول صدور مدرک را آپلود کنید. توجه کنید تصویر انتخاب شده از نوع svg نباشد!', 'nias-course-widget'));
                nias_render_text('certificate_signer_name', __('نام امضا کننده', 'nias-course-widget'), __('نام شخص امضا کننده مدرک', 'nias-course-widget'));
                ?>
            </table>
            <?php submit_button(__('ذخیره تغییرات', 'nias-course-widget'), 'primary', 'nias_save_certificate_settings'); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Admin assets (media uploader, conditional logic, styles)
 * ---------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', 'nias_course_settings_assets');
function nias_course_settings_assets($hook)
{
    if (strpos($hook, 'nias-course-settings') === false && strpos($hook, 'nias-course-certificate') === false) {
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
        // Media uploader for image fields.
        $(document).on('click', '.nias-image-upload', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.nias-image-field');
            var frame = wp.media({ title: 'انتخاب تصویر', multiple: false });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $wrap.find('.nias-image-url').val(attachment.url);
                $wrap.find('.nias-image-preview').html('<img src="' + attachment.url + '" style="max-width:120px;height:auto;margin-top:8px;display:block;">');
            });
            frame.open();
        });
        $(document).on('click', '.nias-image-clear', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.nias-image-field');
            $wrap.find('.nias-image-url').val('');
            $wrap.find('.nias-image-preview').empty();
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
        $(document).on('change', 'input[type=radio], select', evaluateConditions);
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
    <link href='https://fonts.googleapis.com/css?family=Vazirmatn' rel='stylesheet'>
    <style>
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
