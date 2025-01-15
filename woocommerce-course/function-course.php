<?php

// افزودن متاباکس به صفحه محصولات
add_action('add_meta_boxes', 'nias_course_add_custom_meta_box');
function nias_course_add_custom_meta_box()
{
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
function nias_course_render_meta_box($post)
{
    // اضافه کردن nonce برای امنیت
    wp_nonce_field('nias_course_meta_box_nonce', 'nias_course_meta_box_nonce');

    // بازیابی داده‌های ذخیره‌شده
    $sections = get_post_meta($post->ID, 'nias_course_sections_list', true) ?: [];

?>
    <div id="nias_course_meta_box">
    <input type="hidden" name="nias_course_meta_box_submitted" value="1">
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
                        <?php
                        /*
                        <label><?php _e('برچسب', 'nias-course-widget'); ?></label>
                        <input type="text" name="nias_course_sections_list[section_label][]" value="<?php echo esc_attr($section['section_label']); ?>" />
                    */
                        ?>
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


                                        <label><?php _e('درس خصوصی است؟', 'nias-course-widget'); ?></label>
                                        <input type="checkbox" name="nias_course_sections_list[sections][<?php echo $index; ?>][lessons][lesson_private][<?php echo $lesson_index; ?>]" value="yes" <?php checked(isset($lesson['lesson_private']) && $lesson['lesson_private'] === 'yes'); ?> />
                                        <label><?php _e('محتوای درس', 'nias-course-widget'); ?></label>
                                        <?php
                                        $content = isset($lesson['lesson_content']) ? $lesson['lesson_content'] : '';
                                        wp_editor($content, 'lesson_content_' . $index . '_' . $lesson_index, array(
                                            'textarea_name' => "nias_course_sections_list[sections][$index][lessons][lesson_content_$lesson_index]",
                                            'textarea_rows' => 5,
                                            'media_buttons' => true,
                                        ));
                                        ?>
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
    <style>
        body *:not(#wpadminbar *,i){
  font-family: 'Vazirmatn'!important;
}

        div#nias_course_meta_box_id {
            border: none;
            border-radius: 15px;
            padding: 20px;
        }

        .toggle_section,
        .toggle_lesson {
            background-image: url(<?php echo plugin_dir_url(__File__) . 'arow.svg'; ?>);
            font-size: 0;
            width: 30px;
            height: 30px;
            background-position: center;
            background-size: contain;
            background-repeat: no-repeat;
            transform: rotate(0);
            transition: all 0.3s;
            box-shadow: none !important;
            outline: none !important;
        }

        a.toggle_section.active,
        .toggle_lesson.active {
            transform: rotate(-90deg);
        }

        a.nias_course_remove_section,
        .nias_course_remove_lesson {
            background-image: url(<?php echo plugin_dir_url(__File__) . 'remove.svg'; ?>);
            font-size: 0;
            width: 30px;
            height: 30px;
            background-position: center;
            background-size: contain;
            background-repeat: no-repeat;

        }

        a.nias_course_add_lesson,
        a#nias_course_add_section {
            padding: 10px 15px;
            background: #3858e9;
            color: white;
            text-decoration: none;
            border-radius: 10px;
        }

        a.nias_course_add_lesson:after,
        a#nias_course_add_section:after {
            content: "+";
            margin-right: 10px;
        }

        #nias_course_meta_box_id .postbox-header {
            background: #005bff17;
            border-radius: 10px;
            padding: 10px 20px;

            h2,
            span {
                color: #0067ff !important;
            }
        }



        div#nias_course_meta_box {
            h3 {
                color: blue;
            }

            input {
                border: none;
                border-radius: 10px
            }

            button {
                background: #003dff17;
                color: #3858e9;
                border-radius: 10px;
                padding: 10px;
                border: none !important;
                outline: none !important;
            }
        }

        div#nias_course_sections_wrapper {
            padding: 15px;
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


function nias_course_save_meta_box($post_id)
{
    // حذف nonce و بررسی‌های امنیتی برای ساده‌سازی کد

    // بررسی کنید آیا فرم متاباکس ارسال شده است یا خیر
    if (!isset($_POST['nias_course_meta_box_submitted'])) {
        return;
    }

    if (isset($_POST['nias_course_sections_list'])) {
        $sections = $_POST['nias_course_sections_list'];
        $cleaned_sections = [];

        foreach ($sections['section_title'] as $index => $title) {
            $cleaned_sections[$index] = [
                'section_title' => sanitize_text_field($title),
                'section_subtitle' => sanitize_text_field($sections['section_subtitle'][$index]),
                'section_icon' => esc_url_raw($sections['section_icon'][$index]),
                // 'section_label' => sanitize_text_field($sections['section_label'][$index]),
                'lessons' => []
            ];

            if (isset($sections['sections'][$index]['lessons'])) {
                foreach ($sections['sections'][$index]['lessons']['lesson_title'] as $lesson_index => $lesson_title) {
                    $cleaned_sections[$index]['lessons'][$lesson_index] = [
                        'lesson_title' => sanitize_text_field($lesson_title),
                        'lesson_icon' => esc_url_raw($sections['sections'][$index]['lessons']['lesson_icon'][$lesson_index]),
                        'lesson_label' => sanitize_text_field($sections['sections'][$index]['lessons']['lesson_label'][$lesson_index]),
                        'lesson_preview_video' => esc_url_raw($sections['sections'][$index]['lessons']['lesson_preview_video'][$lesson_index]),
                        'lesson_download' => esc_url_raw($sections['sections'][$index]['lessons']['lesson_download'][$lesson_index]),
                        'lesson_content' => $_POST['nias_course_sections_list']['sections'][$index]['lessons']["lesson_content_$lesson_index"],
                        'lesson_private' => isset($sections['sections'][$index]['lessons']['lesson_private'][$lesson_index]) ? 'yes' : 'no',
                    ];
                }
            }
        }

        update_post_meta($post_id, 'nias_course_sections_list', $cleaned_sections);
    } else {
       delete_post_meta($post_id, 'nias_course_sections_list');
    }
}
add_action('save_post', 'nias_course_save_meta_box');

