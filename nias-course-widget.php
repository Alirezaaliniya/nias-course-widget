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






function nias_course_script_product(){
    ?>
        <script>
        
        jQuery(document).ready(function($) {
    function initializeLessonItems() {
        $('.nias_course_lesson_item').each(function() {
            if ($(this).data('state') === 'open') {
                $(this).find('.lesson_content').show();
            }
        });
    }

    initializeLessonItems();

    $('#nias_course_lessons_wrapper').on('click', '.toggle_lesson', function(e) {
        e.preventDefault();
        const lessonItem = $(this).closest('.nias_course_lesson_item');
        lessonItem.find('.lesson_content').slideToggle();
    });

    $('#nias_course_lessons_wrapper').on('click', '.nias_course_remove_lesson', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_lesson_item').remove();
    });

    $('#nias_course_add_lesson').click(function(e) {
        e.preventDefault();
        const index = $('#nias_course_lessons_wrapper .nias_course_lesson_item').length;
        const newLesson = `
            <div class="nias_course_lesson_item" data-index="${index}" data-state="closed">
                <div class="lesson_header">
                    <label>عنوان فصل</label>
                    <input type="text" name="nias_course_lessons_list[lesson_title][]" value="" />

                    <label>زیرعنوان</label>
                    <input type="text" name="nias_course_lessons_list[lesson_subtitle][]" value="" />

                    <a href="#" class="toggle_lesson">باز/بسته</a>
                    <a href="#" class="nias_course_remove_lesson">حذف فصل</a>
                </div>
                <div class="lesson_content" style="display: none;">
                    <label>آیکون</label>
                    <input type="text" name="nias_course_lessons_list[lesson_icon][]" value="" />

                    <label>برچسب</label>
                    <input type="text" name="nias_course_lessons_list[lesson_label][]" value="" />

                    <label>ویدیوی پیش‌نمایش</label>
                    <input type="text" name="nias_course_lessons_list[lesson_preview_video][]" value="" />

                    <label>فایل خصوصی درس</label>
                    <input type="text" name="nias_course_lessons_list[lesson_download][]" value="" />

                    <label>محتوای درس</label>
                    <textarea name="nias_course_lessons_list[lesson_content][]"></textarea>

                    <label>درس خصوصی است؟</label>
                    <input type="checkbox" name="nias_course_lessons_list[lesson_private][${index}]" value="yes" />
                </div>
            </div>`;
        $('#nias_course_lessons_wrapper').append(newLesson);
    });
});


</script>
<style>
#nias_course_meta_box {
    margin-top: 20px;
}

.nias_course_lesson_item {
    margin-bottom: 20px;
    border: 1px solid #ddd;
    padding: 10px;
    background: #f9f9f9;
}

.lesson_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.lesson_header label {
    margin-right: 10px;
}

.lesson_content {
    padding-top: 10px;
}

.lesson_content label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.lesson_content input,
.lesson_content textarea {
    width: 100%;
    margin-bottom: 10px;
    padding: 5px;
}

.toggle_lesson, .nias_course_remove_lesson {
    color: #0073aa;
    text-decoration: none;
    cursor: pointer;
    margin-left: 10px;
}

.toggle_lesson:hover, .nias_course_remove_lesson:hover {
    color: #005177;
}


</style>
    
    
    <?php
}

add_action('admin_footer' , 'nias_course_script_product');





add_action('add_meta_boxes', 'nias_course_add_custom_meta_box');
function nias_course_add_custom_meta_box() {
    add_meta_box(
        'nias_course_meta_box_id',
        __('تنظیمات دوره', 'nias-course-widget'),
        'nias_course_render_meta_box',
        'product',  // نوع پست هدف: محصولات
        'normal',
        'high'
    );
}

function nias_course_render_meta_box($post) {
    // اضافه کردن nonce برای امنیت
    wp_nonce_field('nias_course_meta_box_nonce', 'nias_course_meta_box_nonce');

    // بازیابی داده‌های ذخیره‌شده
    $lessons = get_post_meta($post->ID, 'nias_course_lessons_list', true) ?: [];

    ?>
    <div id="nias_course_meta_box">
        <h3><?php _e('فصل‌ها', 'nias-course-widget'); ?></h3>
        <div id="nias_course_lessons_wrapper">
            <?php foreach ($lessons as $index => $lesson) : ?>
                <div class="nias_course_lesson_item" data-index="<?php echo $index; ?>">
                    <div class="lesson_header">
                        <label><?php _e('عنوان فصل', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_title][]" value="<?php echo esc_attr($lesson['lesson_title']); ?>" />

                        <label><?php _e('زیرعنوان', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_subtitle][]" value="<?php echo esc_attr($lesson['lesson_subtitle']); ?>" />

                        <a href="#" class="toggle_lesson"><?php _e('باز/بسته', 'nias-course-widget'); ?></a>
                        <a href="#" class="nias_course_remove_lesson"><?php _e('حذف فصل', 'nias-course-widget'); ?></a>
                    </div>
                    <div class="lesson_content" style="display: none;">
                        <label><?php _e('آیکون', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_icon][]" value="<?php echo esc_attr($lesson['lesson_icon']); ?>" />

                        <label><?php _e('برچسب', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_label][]" value="<?php echo esc_attr($lesson['lesson_label']); ?>" />

                        <label><?php _e('ویدیوی پیش‌نمایش', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_preview_video][]" value="<?php echo esc_attr($lesson['lesson_preview_video']); ?>" />

                        <label><?php _e('فایل خصوصی درس', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_lessons_list[lesson_download][]" value="<?php echo esc_attr($lesson['lesson_download']); ?>" />

                        <label><?php _e('محتوای درس', 'nias-course-widget'); ?></label>
                        <textarea name="nias_course_lessons_list[lesson_content][]"><?php echo esc_textarea($lesson['lesson_content']); ?></textarea>

                        <label><?php _e('درس خصوصی است؟', 'nias-course-widget'); ?></label>
                        <input type="checkbox" name="nias_course_lessons_list[lesson_private][<?php echo $index; ?>]" value="yes" <?php checked(isset($lesson['lesson_private']) && $lesson['lesson_private'] === 'yes'); ?> />
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="#" id="nias_course_add_lesson"><?php _e('اضافه کردن فصل جدید', 'nias-course-widget'); ?></a>
    </div>
    <?php
}

add_action('save_post', 'nias_course_save_meta_box');
function nias_course_save_meta_box($post_id) {
    // بررسی Nonce برای امنیت
    if (!isset($_POST['nias_course_meta_box_nonce']) || !wp_verify_nonce($_POST['nias_course_meta_box_nonce'], 'nias_course_meta_box_nonce')) {
        return;
    }

    // بررسی اینکه آیا کاربر اجازه ویرایش پست را دارد یا خیر
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // ذخیره‌سازی داده‌های متا
    if (isset($_POST['nias_course_lessons_list'])) {
        $lessons = $_POST['nias_course_lessons_list'];

        // بررسی و پاکسازی داده‌ها
        $cleaned_lessons = [];
        foreach ($lessons['lesson_title'] as $index => $title) {
            $cleaned_lessons[$index] = [
                'lesson_title' => sanitize_text_field($title),
                'lesson_subtitle' => sanitize_text_field($lessons['lesson_subtitle'][$index]),
                'lesson_icon' => sanitize_text_field($lessons['lesson_icon'][$index]),
                'lesson_label' => sanitize_text_field($lessons['lesson_label'][$index]),
                'lesson_preview_video' => sanitize_text_field($lessons['lesson_preview_video'][$index]),
                'lesson_download' => sanitize_text_field($lessons['lesson_download'][$index]),
                'lesson_content' => sanitize_textarea_field($lessons['lesson_content'][$index]),
                'lesson_private' => isset($lessons['lesson_private'][$index]) ? 'yes' : 'no',
            ];
        }

        update_post_meta($post_id, 'nias_course_lessons_list', $cleaned_lessons);
    } else {
        delete_post_meta($post_id, 'nias_course_lessons_list');
    }
}
