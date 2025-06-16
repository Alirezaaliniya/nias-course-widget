<?php

/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   پلاگین دوره ساز نیاس ویجت "دوره ساز نیاس" را به ویرایشگر المنتور شما اضافه میکند که میتوانید دوره مورد نظر خود را درون تمپلیت محصول بسازیدو قالب خود را به یک قالب فروش دوره و فایل تبدیل کنید | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/product/nias-course-widget/
 * Version:     1.2.6
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

//define plugin version
define('NIAS_COURSE_VERSION', '1.2.6');

define('NIAS_COURSE', plugin_dir_path(__FILE__) );
//define setting panel
define('NIAS_COURSE_PANEL', plugin_dir_path(__FILE__) . 'admin');
define('NIAS_WOOCOMMERCE', plugin_dir_path(__FILE__) . 'woocommerce-course');
define('NIAS_COURSE_INC', plugin_dir_path(__FILE__) . 'INC');
define('NIAS_IMAGE', plugin_dir_url(__FILE__) . 'assets/images/');

//  Composer
require plugin_dir_path(__FILE__) . 'vendor/autoload.php';

require(NIAS_COURSE_PANEL . '/adminpannel.php');
require(NIAS_WOOCOMMERCE . '/function-course.php');
require(NIAS_WOOCOMMERCE . '/spotplayer.php');
require(__DIR__ . '/widgets/videomodal.php');

// Include certificate files only if the feature is enabled

require(NIAS_COURSE_INC . '/certificate-show.php');
require(NIAS_COURSE_INC . '/certificate-config-user.php');

require(NIAS_WOOCOMMERCE . '/my-account.php');





// Carbon Fields
add_action('after_setup_theme', 'nias_course_load_carbon_fields');
function nias_course_load_carbon_fields() {
    \Carbon_Fields\Carbon_Fields::boot();
}
/*
// separate translation file
add_filter('carbon_fields_translate_strings', 'translate_carbon_fields_strings');
function translate_carbon_fields_strings($texts) {
    $texts['There are no entries yet.'] = __('هنوز موردی ثبت نشده است.', 'nias-course-widget');
    $texts['Add Entry'] = __('افزودن مورد', 'nias-course-widget');
    $texts['Collapse All'] = __('بستن همه', 'nias-course-widget');
    
    return $texts;
}
*/

/**
 * Register List Widget.
 *
 * Include widget file and register widget class.
 *
 * @since 1.0.0
 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
 * @return void
 */








add_action('elementor/elements/categories_registered', 'nias_course_add_custom_category_widget');
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




/*call funtion to migrate when plugin install*/

// Hook for plugin activation
register_activation_hook(__FILE__, 'nias_corse_plugin_activation');

// Hook for plugin update
add_action('upgrader_process_complete', 'nias_corse_plugin_update', 10, 2);

/**
 * Plugin activation callback
 */
function nias_corse_plugin_activation() {
    // Add error logging to debug
    error_log('Plugin activation triggered');
    
    // Check if function exists before calling
    if (function_exists('migrate_course_data_to_carbon')) {
        migrate_course_data_to_carbon();
    } else {
        error_log('Migration function not found during activation');
    }
}

/**
 * Plugin update callback
 */
function nias_corse_plugin_update($upgrader_object, $options) {
    // Add error logging to debug
    error_log('Plugin update process started');
    
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        // Get the current plugin base name
        $current_plugin = plugin_basename(__FILE__);
        
        // Check if our plugin is being updated
        if (isset($options['plugins']) && in_array($current_plugin, $options['plugins'])) {
            error_log('Plugin update confirmed for: ' . $current_plugin);
            
            // Check if function exists before calling
            if (function_exists('migrate_course_data_to_carbon')) {
                migrate_course_data_to_carbon();
            } else {
                error_log('Migration function not found during update');
            }
        }
    }
}