<?php
namespace Nias_Course;

// nias-course.php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Nias course widget.
 *
 * render course widget in product page.
 *
 * @since 1.0.0
 */


include_once NIAS_FUNCTIONS;

// Define the function to display spot license





require_once( __DIR__ . '/nias-render.php' );
require_once( __DIR__ . '/nias-controls.php' );
class Nias_course_widget extends \Elementor\Widget_Base {

	public function __construct($data = [], $args = null) {
        parent::__construct($data, $args);
  
        wp_register_script('nscourse-js', plugin_dir_url(__DIR__) . 'assets/niascourse.js', array('jquery'), false);
        wp_enqueue_style('nscourse-css', plugin_dir_url(__DIR__) . 'assets/niascourse.css');

     }

	public function get_name() {
		return 'niaslessons';
	 }
  
	 public function get_title() {
		return esc_html__( 'دوره ساز نیاس', 'nias-course-widget' );
	 }
  
	 public function get_icon() {
		  return 'nias-course-maker';
	 }
  
	 public function get_categories() {
		return [ 'nias-widget-category' ];
	}
	
	public function get_script_depends() {
		return [ 'nscourse-js' ];
	}

	public function get_style_depends() {
		return [ 'nscourse-css' ];
	}
    // ارث‌بری از کلاس Nias_course_render
    use Nias_course_render;

    // ارث‌بری از کلاس Nias_course_controls
    use Nias_course_controls;

}




  
  