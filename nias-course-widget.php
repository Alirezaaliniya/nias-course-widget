<?php
/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   پلاگین دوره ساز نیاس ویجت "دوره ساز نیاس" را به ویرایشگر المنتور شما اضافه میکند که میتوانید دوره مورد نظر خود را درون تمپلیت محصول بسازیدو قالب خود را به یک قالب فروش دوره و فایل تبدیل کنید | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/
 * Version:     1.1.0
 * Author:      Alireza aliniya
 * Author URI:  https://nias.ir/
 * Text Domain: nias-course-widget
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
 
 function enqueue_niascourse_asset() {
    // Check if the current page is a WooCommerce product page
    if (is_product()) {
        // Enqueue JavaScript file
        wp_enqueue_script( 'nscourse-js', plugin_dir_url( __FILE__ ) . 'assets/niascourse.js', array( 'jquery' ), false );
        // Enqueue CSS file
        wp_enqueue_style( 'nscourse-css', plugin_dir_url( __FILE__ ) . 'assets/niascourse.css');
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_niascourse_asset' );

add_action( 'elementor/elements/categories_registered', 'add_custom_category_nias_widget' );

function add_custom_category_nias_widget() {
    \Elementor\Plugin::$instance->elements_manager->add_category(
        'nias-widget-category',
        [
            'title' => __( 'ویجت های نیاس', 'nias-course-widget' ), // عنوان دسته بندی
            'icon' => 'fa fa-plug', // آیکون دسته بندی (اختیاری)
        ]
    );
}

function register_nias_widget( $widgets_manager ) {

	require_once( __DIR__ . '/widgets/nias-course.php' );
    require_once( __DIR__ . '/widgets/nias-render.php' );
    require_once( __DIR__ . '/widgets/nias-controls.php' );

    $widgets_manager->register( new \Nias_Course\Nias_course_widget() );
}

add_action( 'elementor/widgets/register', 'register_nias_widget' );