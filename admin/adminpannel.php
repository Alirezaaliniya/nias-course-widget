<?php
define('NIASADMIN_URL',plugin_dir_url(__DIR__).'admin');
function nias_course_admin_style(){
    wp_enqueue_style( 'nias-admin-css', NIASADMIN_URL.'/adminstyle.css');
}
add_action( 'admin_head', 'nias_course_admin_style' );


// اضافه کردن منو به وردپرس
function nias_course_add_menu() {
    // اضافه کردن منو با نام "تنظیمات پلاگین"
    add_menu_page(
        __('دوره ساز نیاس', 'nias-course-widget'), // عنوان منو
        __('دوره ساز نیاس', 'nias-course-widget'), // نام منو در منوی کناری وردپرس
        'manage_options', // نوع دسترسی به منو
        'nias-course-settings', // شناسه منو
        'nias_course_render_settings_page', // نام تابع برای نمایش صفحه تنظیمات
        NIASADMIN_URL.'/nias-course.png' // آیکون منو (اختیاری)
    );
}
add_action('admin_menu', 'nias_course_add_menu');

// نمایش صفحه تنظیمات
function nias_course_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('تنظیمات پلاگین', 'nias-course-widget'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('nias_course_settings_group'); ?>
            <?php do_settings_sections('nias-course-settings'); ?>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// اضافه کردن چک باکس به صفحه تنظیمات
function nias_course_add_checkbox() {
    // اضافه کردن یک بخش به صفحه تنظیمات با نام "تنظیمات پلاگین"
    add_settings_section(
        'nias_course_settings_section', // شناسه بخش
        __('تنظیمات دوره ساز نیاس', 'nias-course-widget'), // عنوان بخش
        'nias_course_section_description', // نام تابع برای توضیحات بخش (اختیاری)
        'nias-course-settings' // شناسه صفحه تنظیمات
    );

    // اضافه کردن چک باکس به بخش تنظیمات
    add_settings_field(
        'nias_course_enable_feature', // شناسه فیلد
        __('فعالسازی قابلیت محافظت لینک', 'nias-course-widget'), // برچسب فیلد
        'nias_course_render_checkbox', // نام تابع برای نمایش فیلد
        'nias-course-settings', // شناسه صفحه تنظیمات
        'nias_course_settings_section' // شناسه بخش
    );

    // ثبت فیلد
    register_setting(
        'nias_course_settings_group', // گروه تنظیمات
        'nias_course_enable_feature' // شناسه فیلد
    );
}
add_action('admin_init', 'nias_course_add_checkbox');

// توضیحات بخش
function nias_course_section_description() {
    echo '<p>'.__('هشدار:حتماً قبل از فعالسازی این گزینه از هر محصول سایت برای خودتان سفارش ثبت کنید در غیر این صورت تمپلیت محصول شما هنگام ویرایش با المنتور مشکل لود پیدا خواهد کرد', 'nias-course-widget').'</p>';
}

/*
// نمایش چک باکس
function nias_course_render_checkbox() {
    $value = get_option('nias_course_enable_feature');
    ?>
    <input type="checkbox" name="nias_course_enable_feature" value="1" <?php checked(1, $value); ?> />
    <?php
}


// تابع برای برگرداندن مقدار گزینه تنظیمات
function nias_course_is_feature_enabled() {
    return get_option('nias_course_enable_feature');
}
*/
