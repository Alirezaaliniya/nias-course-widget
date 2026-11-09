<?php

/**
 * Plugin Name: Nias course | دوره ساز نیاس
 * Description:   دوره ساز نیاس برای فروش و برگزاری دوره های آموزشی؛ با ووکامرس یا بدون آن، و با المنتور (ویجت های دوره ساز) یا بدون آن | این پلاگین بصورت رایگان منتشر شده و رایگان هم خواهد ماند❤️
 * Plugin URI:  https://nias.ir/product/nias-course-widget/
 * Version:     1.7.0
 * Author:      Alireza aliniya
 * Author URI:  https://nias.ir/
 * Text Domain: nias-course-widget
 * License:GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html 
 **/

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
define('NIAS_COURSE_VERSION', '1.7.0');

define('NIAS_FUNCTIONS' , plugin_dir_path(__FILE__).'inc/functions.php');
define('NIAS_COURSE', plugin_dir_path(__FILE__) );
define('NIAS_COURSE_PANEL', plugin_dir_path(__FILE__) . 'admin');
define('NIAS_WOOCOMMERCE', plugin_dir_path(__FILE__) . 'woocommerce-course');
define('NIAS_COURSE_INC', plugin_dir_path(__FILE__) . 'inc');

define('NIAS_IMAGE', plugin_dir_url(__FILE__) . 'assets/images/');
define('NIASADMIN_URL', plugin_dir_url(__FILE__) . 'admin');
define('NIAS_FONTS_URL', plugin_dir_url(__FILE__) . 'assets/fonts/');

/**
 * Inline @font-face CSS for the bundled Vazir font, so plugin admin screens use
 * the local font files instead of the Google Fonts CDN. The faces are declared
 * under the "Vazirmatn" family name the existing styles already reference.
 *
 * @return string
 */
function nias_course_font_face_css()
{
    $b = NIAS_FONTS_URL;
    return "@font-face{font-family:'Vazirmatn';font-style:normal;font-weight:1 450;font-display:swap;src:url('{$b}Vazir.ttf') format('truetype')}"
        . "@font-face{font-family:'Vazirmatn';font-style:normal;font-weight:451 550;font-display:swap;src:url('{$b}Vazir-Medium.ttf') format('truetype')}"
        . "@font-face{font-family:'Vazirmatn';font-style:normal;font-weight:551 900;font-display:swap;src:url('{$b}Vazir-Bold.ttf') format('truetype')}";
}

//  Composer
require plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Carbon-compatible settings/meta data layer (replaces Carbon Fields).
require(NIAS_COURSE_INC . '/nias-meta.php');
require(NIAS_COURSE_INC . '/purchases.php');
require(NIAS_COURSE_INC . '/course-type.php'); // «دوره» post type + free mode (no WooCommerce)

require(NIAS_WOOCOMMERCE . '/spotplayer-sync.php');
require(NIAS_COURSE_PANEL . '/adminpannel.php');
require(NIAS_COURSE_PANEL . '/player-settings.php');
require(NIAS_WOOCOMMERCE . '/spotplayer-license.php');
require(NIAS_WOOCOMMERCE . '/function-course.php');
require(NIAS_WOOCOMMERCE . '/audio-player.php');
require(NIAS_WOOCOMMERCE . '/curriculum-editor.php');
require(NIAS_WOOCOMMERCE . '/product-meta.php');
require(NIAS_WOOCOMMERCE . '/modern-course.php');
require(NIAS_WOOCOMMERCE . '/watch-tracking.php');
require(NIAS_WOOCOMMERCE . '/quiz-builder.php');
require(NIAS_WOOCOMMERCE . '/quiz-frontend.php');
require(NIAS_WOOCOMMERCE . '/quiz-course.php');
require(NIAS_WOOCOMMERCE . '/quiz-index.php');
require(NIAS_COURSE_INC . '/nias-picker.php');
require(NIAS_WOOCOMMERCE . '/quiz-results.php');
require(NIAS_WOOCOMMERCE . '/qa.php'); // پرسش و پاسخ (optional)
require(__DIR__ . '/widgets/videomodal.php');
require(NIAS_COURSE_INC . '/certificate-show.php');
require(NIAS_COURSE_INC . '/certificate-config-user.php');
require(NIAS_COURSE_INC . '/certificate/registry.php');
require(NIAS_COURSE_INC . '/certificate/conditions.php');
require(NIAS_COURSE_INC . '/certificate/renderer.php');
require(NIAS_COURSE_INC . '/certificate/designer.php');
require(NIAS_COURSE_INC . '/certificate/verify.php');
require(NIAS_COURSE_INC . '/certificate/verify-log.php');
require(NIAS_COURSE_INC . '/certificate/signature.php');
require(NIAS_COURSE_INC . '/certificate/email.php');
require(NIAS_COURSE_INC . '/certificate/admin-issued.php');
require(NIAS_COURSE_INC . '/certificate/account.php');
require(NIAS_COURSE_INC . '/instructors.php');
require(NIAS_COURSE_INC . '/instructor-dashboard.php');
require(NIAS_COURSE_INC . '/instructor-panel.php'); // پنل مدرس (optional)
require(NIAS_COURSE_INC . '/instructor-approval.php'); // course creation + «نیاز به تأیید»
require(NIAS_COURSE_INC . '/sms.php'); // پیامک رویدادها (through Nias Login only)
require(NIAS_COURSE_INC . '/secure-media.php'); // لینک اختصاصی فایل‌های درس (optional)
require(NIAS_COURSE_INC . '/protect.php'); // بستن دسترسی مستقیم به فایل‌ها (optional module)
require(NIAS_COURSE_INC . '/drip.php'); // محتوای قطره‌ای (optional module)
require(NIAS_COURSE_INC . '/devices.php'); // محدودیت دستگاه و ورود همزمان (optional module)
require(NIAS_COURSE_INC . '/access.php'); // دسترسی زمان‌دار و تمدید (optional module)
require(NIAS_COURSE_INC . '/inactive.php'); // یادآوری به دانشجوی غیرفعال (optional module)
require(NIAS_COURSE_INC . '/assignments.php'); // تکلیف با بارگذاری فایل (optional module)
require(NIAS_COURSE_INC . '/analytics.php'); // گزارش تحلیلی (optional module)
require(NIAS_COURSE_INC . '/reviews.php'); // امتیاز و نظر خریداران (optional module)
require(NIAS_COURSE_INC . '/notes.php'); // یادداشت و نشانک زمان‌دار (optional module)
require(NIAS_COURSE_INC . '/paths.php'); // پیش‌نیاز و مسیر یادگیری (optional module)
require(NIAS_COURSE_INC . '/transfer.php'); // کپی و انتقال دوره (optional module)
require(NIAS_COURSE_INC . '/revenue.php'); // سهم درآمد مدرس (optional module)
require(NIAS_COURSE_INC . '/live.php'); // کلاس آنلاین (optional module)
require(NIAS_COURSE_INC . '/gamify.php'); // امتیاز و نشان (optional module)
require(NIAS_COURSE_INC . '/modules.php'); // صفحهٔ «ماژول‌ها»
require(NIAS_WOOCOMMERCE . '/my-account.php');
require(NIAS_COURSE_INC . '/shortcodes.php');





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

    // «مدرس دوره» (instructors of the current course) — when instructors are on.
    if (function_exists('nias_instructors_enabled') && nias_instructors_enabled()) {
        require_once(__DIR__ . '/widgets/nias-instructors.php');
        $widgets_manager->register(new \Nias_Course\Nias_course_instructors());
    }
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
	}
        		#elementor-panel-category-nias-widget-category .elementor-element-wrapper button{
		border: none!important;
		}
        #elementor-panel-category-nias-widget-category *{
color:white!important;
        }
        .elementor-navigator__element__element-type .nias-course-woo , .elementor-navigator__element__element-type .nias-course-maker{
        width:20px;
        }
        #elementor-panel-category-nias-widget-category .elementor-element{
		background-color: #252525 !important;
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