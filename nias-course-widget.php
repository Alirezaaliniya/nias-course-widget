<?php
/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   پلاگین دوره ساز نیاس ویجت "دوره ساز نیاس" را به ویرایشگر المنتور شما اضافه میکند که میتوانید دوره مورد نظر خود را درون تمپلیت محصول بسازیدو قالب خود را به یک قالب فروش دوره و فایل تبدیل کنید | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/product/nias-course-widget/
 * Version:     1.1.2
 * Author:      Alireza aliniya
 * Author URI:  https://nias.ir/
 * Text Domain: nias-course-widget
 * License:GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 * Requires Plugins: elementor, woocommerce
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register List Widget.
 *
 * Include widget file and register widget class.
 *
 * @since 1.0.0
 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
 * @return void
 */
 
 function nias_course_enqueue_course_asset() {
    // Check if the current page is a WooCommerce product page
        // Enqueue JavaScript file
        wp_enqueue_script( 'nscourse-js', plugin_dir_url( __FILE__ ) . 'assets/niascourse.js', array( 'jquery' ), false );
        // Enqueue CSS file
        wp_enqueue_style( 'nscourse-css', plugin_dir_url( __FILE__ ) . 'assets/niascourse.css');

}
add_action( 'wp_enqueue_scripts', 'nias_course_enqueue_course_asset' );

add_action( 'elementor/elements/categories_registered', 'nias_course_add_custom_category_widget' );

function nias_course_add_custom_category_widget() {
    \Elementor\Plugin::$instance->elements_manager->add_category(
        'nias-widget-category',
        [
            'title' => esc_html__( 'ویجت های نیاس', 'nias-course-widget' ), // عنوان دسته بندی
            'icon' => 'fa fa-plug', // آیکون دسته بندی (اختیاری)
        ]
    );
}

function nias_course_register_widget( $widgets_manager ) {

	require_once( __DIR__ . '/widgets/nias-course.php' );
    require_once( __DIR__ . '/widgets/nias-render.php' );
    require_once( __DIR__ . '/widgets/nias-controls.php' );

    $widgets_manager->register( new \Nias_Course\Nias_course_widget() );
}

add_action( 'elementor/widgets/register', 'nias_course_register_widget' );

// اضافه کردن منو به وردپرس
function nias_course_add_menu() {
    // اضافه کردن منو با نام "تنظیمات پلاگین"
    add_menu_page(
        __('تنظیمات پلاگین', 'nias-course-widget'), // عنوان منو
        __('تنظیمات پلاگین', 'nias-course-widget'), // نام منو در منوی کناری وردپرس
        'manage_options', // نوع دسترسی به منو
        'nias-course-settings', // شناسه منو
        'nias_course_render_settings_page', // نام تابع برای نمایش صفحه تنظیمات
        'dashicons-admin-generic' // آیکون منو (اختیاری)
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
        __('تنظیمات پلاگین', 'nias-course-widget'), // عنوان بخش
        'nias_course_section_description', // نام تابع برای توضیحات بخش (اختیاری)
        'nias-course-settings' // شناسه صفحه تنظیمات
    );

    // اضافه کردن چک باکس به بخش تنظیمات
    add_settings_field(
        'nias_course_enable_feature', // شناسه فیلد
        __('فعالسازی ویژگی', 'nias-course-widget'), // برچسب فیلد
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
    echo '<p>'.__('در اینجا می‌توانید ویژگی‌های پلاگین را تنظیم کنید.', 'nias-course-widget').'</p>';
}


// نمایش چک باکس
function nias_course_render_checkbox() {
    $value = get_option('nias_course_enable_feature');
    ?>
    <input type="checkbox" name="nias_course_enable_feature" value="1" <?php checked(1, $value); ?> />
    <?php
   // echo $value;
}


// تابع برای برگرداندن مقدار گزینه تنظیمات
function nias_course_is_feature_enabled() {
    return get_option('nias_course_enable_feature');
}
