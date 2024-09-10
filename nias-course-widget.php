<?php

/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   پلاگین دوره ساز نیاس ویجت "دوره ساز نیاس" را به ویرایشگر المنتور شما اضافه میکند که میتوانید دوره مورد نظر خود را درون تمپلیت محصول بسازیدو قالب خود را به یک قالب فروش دوره و فایل تبدیل کنید | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/product/nias-course-widget/
 * Version:     1.2.0
 * Author:      Alireza aliniya
 * Author URI:  https://nias.ir/
 * Text Domain: nias-course-widget
 * License:GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 * Requires Plugins: elementor, woocommerce
 **/

if (! defined('ABSPATH')) {
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



/* ---------------------------- script for admin ---------------------------- */
function nias_admin_course_enqueue_scripts()
{
    wp_enqueue_script('nias-admin-course-js', plugin_dir_url(__FILE__) . 'admin/nias-admin-course.js', array('jquery'), null, true);

    wp_localize_script('nias-admin-course-js', 'nias_course_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'section_title' => __('عنوان فصل', 'nias-course-widget'),
        'section_subtitle' => __('زیرعنوان فصل', 'nias-course-widget'),
        'toggle_section' => __('باز/بسته', 'nias-course-widget'),
        'remove_section' => __('حذف فصل', 'nias-course-widget'),
        'upload_icon' => __('بارگذاری آیکون', 'nias-course-widget'),
        'section_label' => __('برچسب فصل', 'nias-course-widget'),
        'add_lesson' => __('اضافه کردن درس جدید', 'nias-course-widget'),
        'lesson_title' => __('عنوان درس', 'nias-course-widget'),
        'toggle_lesson' => __('باز/بسته', 'nias-course-widget'),
        'remove_lesson' => __('حذف درس', 'nias-course-widget'),
        'upload_video' => __('بارگذاری ویدیو', 'nias-course-widget'),
        'lesson_label' => __('برچسب درس', 'nias-course-widget'),
        'lesson_preview_video' => __('ویدیوی پیش‌نمایش درس', 'nias-course-widget'),
        'lesson_download' => __('فایل خصوصی درس', 'nias-course-widget'),
        'lesson_content' => __('محتوای درس', 'nias-course-widget'),
        'lesson_private' => __('درس خصوصی است؟', 'nias-course-widget'),
    ));
}
add_action('admin_enqueue_scripts', 'nias_admin_course_enqueue_scripts');





add_action('elementor/elements/categories_registered', 'nias_course_add_custom_category_widget');

//define setting panel
define('NIAS_COURSE_PANEL', plugin_dir_path(__FILE__) . 'admin');
require(NIAS_COURSE_PANEL . '/adminpannel.php');

/* ----------------------- define woocommerce product ----------------------- */
define('NIAS_WOOCOMMERCE', plugin_dir_path(__FILE__) . 'woocommerce-course');
require(NIAS_WOOCOMMERCE . '/function-course.php');

//add setting to installed plugins
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'nias_setting_link');
function nias_setting_link($links)
{
    $links[] = '<a href="' .
        admin_url('admin.php?page=nias-course-settings') .
        '">' . __('Settings') . '</a>';
    return $links;
}

function nias_course_add_custom_category_widget()
{
    \Elementor\Plugin::$instance->elements_manager->add_category(
        'nias-widget-category',
        [
            'title' => esc_html__('ویجت دوره ساز نیاس', 'nias-course-widget'), // عنوان دسته بندی
            'icon' => 'fa fa-plug', // آیکون دسته بندی (اختیاری)
        ]
    );
}

function nias_course_register_widget($widgets_manager)
{

    require_once(__DIR__ . '/widgets/nias-course.php');
    require_once(__DIR__ . '/widgets/nias-render.php');
    require_once(__DIR__ . '/widgets/nias-controls.php');
    require_once(__DIR__ . '/widgets/nias-woocommerce.php');

    $widgets_manager->register(new \Nias_Course\Nias_course_widget());
    $widgets_manager->register(new \Nias_Course\Nias_course_woocommerce());
}

add_action('elementor/widgets/register', 'nias_course_register_widget');

function change_category_nias_course()
{
    echo '
	<style>
	#elementor-panel-category-nias-widget-category .icon:before {
		background-image: url(' . plugin_dir_url(__FILE__) . 'assets/nias-logo.png);
		content: "";
		width: 35px;
		height: 35px;
		display: block;
		position: absolute;
		left: 0;
		border-radius: 10px;
		top: 0;
		background-position: center;
		background-size: contain;
	}
	.nias-course-maker{
	content:"";
		background-image: url(' . plugin_dir_url(__FILE__) . 'assets/nias-course.svg);
		content: "";
		height: 35px;
		display: block;
		background-position: center;
		background-size: contain;
		background-repeat: no-repeat;
	  }
	#elementor-panel-category-nias-widget-category {
		background-color: #2666cf!important;
	}
	#elementor-panel-category-nias-widget-category .elementor-element-wrapper{
		background-color: #252525 !important;
		border-radius: 10px!important;
        color:white!important;
	
		button{
		border: none!important;
		}
	}
        .elementor-navigator__element__element-type .nias-course-woo , .elementor-navigator__element__element-type .nias-course-maker{
        width:20px;
        }

.nias-course-woo{
	content:"";
		background-image: url(' . plugin_dir_url(__FILE__) . 'assets/nias-course-woo.svg);
		content: "";
		height: 35px;
		display: block;
		background-position: center;
		background-size: contain;
		background-repeat: no-repeat;
	  }
	
	
	</style>
	
	';
}

add_action('elementor/editor/after_enqueue_styles', 'change_category_nias_course');
