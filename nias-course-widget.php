<?php
/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   پلاگین دوره ساز نیاس ویجت "دوره ساز نیاس" را به ویرایشگر المنتور شما اضافه میکند که میتوانید دوره مورد نظر خود را درون تمپلیت محصول بسازیدو قالب خود را به یک قالب فروش دوره و فایل تبدیل کنید | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/product/nias-course-widget/
 * Version:     1.1.31
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

/* ---------------------------- script for admin ---------------------------- */
function nias_admin_course_enqueue_scripts() {
    wp_enqueue_script('nias-admin-course-js', plugin_dir_url(__FILE__) . 'admin/nias-admin-course.js', array('jquery'), null, true);

    wp_localize_script('nias-admin-course-js', 'nias_course_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'lesson_title' => __('عنوان فصل', 'nias-course-widget'),
        'lesson_subtitle' => __('زیرعنوان', 'nias-course-widget'),
        'lesson_icon' => __('آیکون', 'nias-course-widget'),
        'lesson_label' => __('برچسب', 'nias-course-widget'),
        'lesson_preview_video' => __('ویدیوی پیش‌نمایش', 'nias-course-widget'),
        'lesson_download' => __('فایل خصوصی درس', 'nias-course-widget'),
        'lesson_content' => __('محتوای درس', 'nias-course-widget'),
        'lesson_private' => __('درس خصوصی است؟', 'nias-course-widget'),
        'toggle_lesson' => __('باز/بسته', 'nias-course-widget'),
        'remove_lesson' => __('حذف فصل', 'nias-course-widget')
    ));
}
add_action('admin_enqueue_scripts', 'nias_admin_course_enqueue_scripts');





add_action( 'elementor/elements/categories_registered', 'nias_course_add_custom_category_widget' );

//define setting panel
define('NIAS_COURSE_PANEL',plugin_dir_path(__FILE__).'admin');
require(NIAS_COURSE_PANEL.'/adminpannel.php');

//add setting to installed plugins
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'nias_setting_link');
function nias_setting_link( $links ) {
	$links[] = '<a href="' .
		admin_url( 'admin.php?page=nias-course-settings' ) .
		'">' . __('Settings') . '</a>';
	return $links;
}

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





