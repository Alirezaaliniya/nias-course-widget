<?php
/**
 * Plugin Name: Nias course
 * Description: ویجتی جهت ایجاد سایت فروش دوره با المنتور(ویجت دوره را به المنتور اضافه میکند)
 * Plugin URI:  https://d.nias.ir/
 * Version:     1.0.4
 * Author:      Alireza aliniya
 * Author URI:  https://nias.ir/
 * Text Domain: nias-course-widget
 * Elementor tested up to: 3.15.3
 * Elementor Pro tested up to: 3.15.3
 */

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
 
 function enqueue_my_files() {
    // Enqueue JavaScript file
    wp_enqueue_script( 'my-js', plugin_dir_url( __FILE__ ) . 'assets/nias.js', array( 'jquery' ), false );
    // Enqueue CSS file
    wp_enqueue_style( 'my-css', plugin_dir_url( __FILE__ ) . 'assets/nias.css');
}
add_action( 'wp_enqueue_scripts', 'enqueue_my_files' );

function register_nias_widget( $widgets_manager ) {

	require_once( __DIR__ . '/widgets/nias-course.php' );

	$widgets_manager->register( new \Nias_course_widget() );

}
add_action( 'elementor/widgets/register', 'register_nias_widget' );