jQuery(document).ready(function($) {

        // تابعی برای راه‌اندازی مدیالایبری
        function openMediaUploader(targetInput) {
            var mediaUploader;
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
    
            mediaUploader = wp.media({
                title: 'انتخاب فایل',
                button: {
                    text: 'انتخاب'
                },
                multiple: false
            });
    
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $(targetInput).val(attachment.url);
            });
    
            mediaUploader.open();
        }
    
        // دکمه‌های آپلود آیکون و ویدیو
        $('.upload_image_button').click(function(e) {
            e.preventDefault();
            openMediaUploader($(this).data('target'));
        });
    
        $('.upload_video_button').click(function(e) {
            e.preventDefault();
            openMediaUploader($(this).data('target'));
        });

    





    var lessonCount = $('#nias_course_lessons_wrapper .nias_course_lesson_item').length;

    $('#nias_course_add_lesson').on('click', function(e) {
        e.preventDefault();

        var newIndex = lessonCount++;

        var newLesson = `
            <div class="nias_course_lesson_item" data-index="${newIndex}">
                <div class="lesson_header">
                    <label>${nias_course_ajax_object.lesson_title}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_title][]" value="" />

                    <label>${nias_course_ajax_object.lesson_subtitle}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_subtitle][]" value="" />

                    <a href="#" class="toggle_lesson">${nias_course_ajax_object.toggle_lesson}</a>
                    <a href="#" class="nias_course_remove_lesson">${nias_course_ajax_object.remove_lesson}</a>
                </div>
                <div class="lesson_content" style="display: none;">
                    <label>${nias_course_ajax_object.lesson_icon}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_icon][]" value="" />

                    <label>${nias_course_ajax_object.lesson_label}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_label][]" value="" />

                    <label>${nias_course_ajax_object.lesson_preview_video}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_preview_video][]" value="" />

                    <label>${nias_course_ajax_object.lesson_download}</label>
                    <input type="text" name="nias_course_lessons_list[lesson_download][]" value="" />

                    <label>${nias_course_ajax_object.lesson_content}</label>
                    <textarea id="nias_course_lesson_content_${newIndex}" name="nias_course_lessons_list[lesson_content][]" rows="10"></textarea>
                    <label>${nias_course_ajax_object.lesson_private}</label>
                    <input type="checkbox" name="nias_course_lessons_list[lesson_private][${newIndex}]" value="yes" />
                </div>
            </div>
        `;

        $('#nias_course_lessons_wrapper').append(newLesson);

    });

    // حذف فصل
    $(document).on('click', '.nias_course_remove_lesson', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_lesson_item').remove();
    });

    // باز/بسته کردن محتوای درس
    $(document).on('click', '.toggle_lesson', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_lesson_item').find('.lesson_content').toggle();
    });
    });
    
