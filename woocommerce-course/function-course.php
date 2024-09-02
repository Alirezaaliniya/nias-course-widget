<?php


function nias_course_script_product(){
    
    ?>
        <script>
        
 

</script>
<style>
div#nias_course_meta_box_id {
    border: none;
    border-radius: 15px;
    padding: 20px;
}

#nias_course_meta_box_id .postbox-header {
    background: #005bff17;
    border-radius:10px;
    padding:10px 20px;
    h2 , span{
    color: #0067ff!important;
        }
}



div#nias_course_meta_box {
    h3 {
        color:blue;
    }
    input{
        border:none;
        border-radius:10px
    }
}

div#nias_course_sections_wrapper {
    padding: 15px;
    /* background-color: #ffffff00; */
    border: 1px solid #f5f5f5;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.nias_course_section_item {
    padding: 10px;
    background-color: #f8f8f8;
    border-radius: 10px;
    /* margin-bottom: 15px; */
    /* margin: 10px 0; */
    /* display: flex; */
    /* flex-direction: column; */
    /* gap: 20px; */
}

.section_header {
    display: flex;
    flex-direction: row;
    justify-content: space-around;
    align-items: center;
}

.lessons_wrapper {
    margin: 15px 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.nias_course_lesson_item {
    display: flex;
    flex-direction: column;
    gap: 10px;
    justify-content: center;
}

.lesson_header {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 20px;
    background-color: #ededed;
    padding: 10px;
    border-radius: 8px;
}

.lesson_content {
    display: flex;
    flex-direction: column;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
}

.section_content {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

</style>
    
    
    <?php

}

add_action('admin_footer' , 'nias_course_script_product');





// افزودن متاباکس به صفحه محصولات
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

// نمایش متاباکس در صفحه ویرایش محصول
// نمایش متاباکس در صفحه ویرایش محصول
function nias_course_render_meta_box($post) {
    // اضافه کردن nonce برای امنیت
    wp_nonce_field('nias_course_meta_box_nonce', 'nias_course_meta_box_nonce');

    // بازیابی داده‌های ذخیره‌شده
    $sections = get_post_meta($post->ID, 'nias_course_sections_list', true) ?: [];

    ?>
    <div id="nias_course_meta_box">
        <h3><?php _e('فصل‌ها', 'nias-course-widget'); ?></h3>
        <div id="nias_course_sections_wrapper">
            <?php foreach ($sections as $index => $section) : ?>
                <div class="nias_course_section_item" data-index="<?php echo $index; ?>">
                    <div class="section_header">
                        <label><?php _e('عنوان فصل', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_sections_list[section_title][]" value="<?php echo esc_attr($section['section_title']); ?>" />

                        <label><?php _e('زیرعنوان', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_sections_list[section_subtitle][]" value="<?php echo esc_attr($section['section_subtitle']); ?>" />

                        <a href="#" class="toggle_section"><?php _e('باز/بسته', 'nias-course-widget'); ?></a>
                        <a href="#" class="nias_course_remove_section"><?php _e('حذف فصل', 'nias-course-widget'); ?></a>
                    </div>
                    <div class="section_content" style="display: none;">
                        <label><?php _e('آیکون', 'nias-course-widget'); ?></label>
                        <input type="text" id="section_icon_<?php echo $index; ?>" name="nias_course_sections_list[section_icon][]" value="<?php echo esc_url($section['section_icon']); ?>" />
                        <button class="upload_image_button" data-target="#section_icon_<?php echo $index; ?>"><?php _e('بارگذاری آیکون', 'nias-course-widget'); ?></button>

                        <label><?php _e('برچسب', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_sections_list[section_label][]" value="<?php echo esc_attr($section['section_label']); ?>" />
                    </div>
                    <div class="lessons_wrapper">
                        <?php if (!empty($section['lessons'])) : ?>
                            <?php foreach ($section['lessons'] as $lesson_index => $lesson) : ?>
                                <div class="nias_course_lesson_item" data-index="<?php echo $lesson_index; ?>">
                                    <div class="lesson_header">
                                        <label><?php _e('عنوان درس', 'nias-course-widget'); ?></label>
                                        <input type="text" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_title][]" value="<?php echo esc_attr($lesson['lesson_title']); ?>" />

                                        <a href="#" class="toggle_lesson"><?php _e('باز/بسته', 'nias-course-widget'); ?></a>
                                        <a href="#" class="nias_course_remove_lesson"><?php _e('حذف درس', 'nias-course-widget'); ?></a>
                                    </div>
                                    <div class="lesson_content" style="display: none;">
                                        <label><?php _e('آیکون', 'nias-course-widget'); ?></label>
                                        <input type="text" id="lesson_icon_<?php echo $lesson_index; ?>" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_icon][]" value="<?php echo esc_url($lesson['lesson_icon']); ?>" />
                                        <button class="upload_image_button" data-target="#lesson_icon_<?php echo $lesson_index; ?>"><?php _e('بارگذاری آیکون', 'nias-course-widget'); ?></button>

                                        <label><?php _e('برچسب', 'nias-course-widget'); ?></label>
                                        <input type="text" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_label][]" value="<?php echo esc_attr($lesson['lesson_label']); ?>" />

                                        <label><?php _e('ویدیوی پیش‌نمایش', 'nias-course-widget'); ?></label>
                                        <input type="text" id="lesson_preview_video_<?php echo $lesson_index; ?>" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_preview_video][]" value="<?php echo esc_url($lesson['lesson_preview_video']); ?>" />
                                        <button class="upload_video_button" data-target="#lesson_preview_video_<?php echo $lesson_index; ?>"><?php _e('بارگذاری ویدیو', 'nias-course-widget'); ?></button>

                                        <label><?php _e('فایل خصوصی درس', 'nias-course-widget'); ?></label>
                                        <input type="text" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_download][]" value="<?php echo esc_url($lesson['lesson_download']); ?>" />

                                        <label><?php _e('محتوای درس', 'nias-course-widget'); ?></label>
                                        <?php
                                            $editor_id = 'nias_course_lesson_content_' . $lesson_index;
                                            $content = !empty($lesson['lesson_content']) ? $lesson['lesson_content'] : '';
                                            $settings = array(
                                                'textarea_name' => 'nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_content][]',
                                                'textarea_rows' => 10,
                                                'media_buttons' => true,
                                                'tinymce' => true,
                                                'quicktags' => true,
                                            );

                                            wp_editor($content, $editor_id, $settings);
                                        ?>
                                        <label><?php _e('درس خصوصی است؟', 'nias-course-widget'); ?></label>
                                        <input type="checkbox" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_private][<?php echo $lesson_index; ?>]" value="yes" <?php checked(isset($lesson['lesson_private']) && $lesson['lesson_private'] === 'yes'); ?> />
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="#" class="nias_course_add_lesson" data-section-index="<?php echo $index; ?>"><?php _e('اضافه کردن درس جدید', 'nias-course-widget'); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="#" id="nias_course_add_section"><?php _e('اضافه کردن فصل جدید', 'nias-course-widget'); ?></a>
    </div>
    <?php
}

// ذخیره‌سازی داده‌های متاباکس
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
    if (isset($_POST['nias_course_sections_list'])) {
        $sections = $_POST['nias_course_sections_list'];

        // بررسی و پاکسازی داده‌ها
        $cleaned_sections = [];
        foreach ($sections['section_title'] as $index => $title) {
            $cleaned_sections[$index] = [
                'section_title' => sanitize_text_field($title),
                'section_subtitle' => sanitize_text_field($sections['section_subtitle'][$index]),
                'section_icon' => sanitize_text_field($sections['section_icon'][$index]),
                'section_label' => sanitize_text_field($sections['section_label'][$index]),
                'lessons' => array_map(function($lesson_title, $i) use ($sections, $index) {
                    return [
                        'lesson_title' => sanitize_text_field($lesson_title),
                        'lesson_icon' => sanitize_text_field($sections['sections'][$index]['lessons']['lesson_icon'][$i]),
                        'lesson_label' => sanitize_text_field($sections['sections'][$index]['lessons']['lesson_label'][$i]),
                        'lesson_preview_video' => sanitize_text_field($sections['sections'][$index]['lessons']['lesson_preview_video'][$i]),
                        'lesson_download' => sanitize_text_field($sections['sections'][$index]['lessons']['lesson_download'][$i]),
                        'lesson_content' => wp_kses_post($sections['sections'][$index]['lessons']['lesson_content'][$i]),
                        'lesson_private' => isset($sections['sections'][$index]['lessons']['lesson_private'][$i]) ? 'yes' : 'no',
                    ];
                }, $sections['sections'][$index]['lessons']['lesson_title'], array_keys($sections['sections'][$index]['lessons']['lesson_title']))
            ];
        }

        update_post_meta($post_id, 'nias_course_sections_list', $cleaned_sections);
    } else {
        delete_post_meta($post_id, 'nias_course_sections_list');
    }
}
