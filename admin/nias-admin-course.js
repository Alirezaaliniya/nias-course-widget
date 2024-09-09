jQuery(document).ready(function($) {
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
    $(document).on('click', '.upload_image_button, .upload_video_button', function(e) {
        e.preventDefault();
        openMediaUploader($(this).data('target'));
    });

    // اضافه کردن فصل جدید
    $('#nias_course_add_section').on('click', function(e) {
        e.preventDefault();

        var newIndex = $('#nias_course_sections_wrapper .nias_course_section_item').length;

        var newSection = `
            <div class="nias_course_section_item" data-index="${newIndex}">
                <div class="section_header">
                    <label>${nias_course_ajax_object.section_title}</label>
                    <input type="text" name="nias_course_sections_list[section_title][]" value="" />

                    <label>${nias_course_ajax_object.section_subtitle}</label>
                    <input type="text" name="nias_course_sections_list[section_subtitle][]" value="" />

                    <a href="#" class="toggle_section">${nias_course_ajax_object.toggle_section}</a>
                    <a href="#" class="nias_course_remove_section">${nias_course_ajax_object.remove_section}</a>
                </div>
                <div class="section_content" style="display: none;">
                    <label>${nias_course_ajax_object.upload_icon}</label>
                    <input type="text" name="nias_course_sections_list[section_icon][]" value="" />
                    <button class="upload_image_button" data-target="[name='nias_course_sections_list[section_icon][]']">${nias_course_ajax_object.upload_icon}</button>

                    <div class="lessons_wrapper"></div>
                    <a href="#" class="nias_course_add_lesson" data-section-index="${newIndex}">${nias_course_ajax_object.add_lesson}</a>
                </div>
            </div>
        `;

        $('#nias_course_sections_wrapper').append(newSection);
    });

    // اضافه کردن درس جدید
    $(document).on('click', '.nias_course_add_lesson', function(e) {
        e.preventDefault();

        var sectionIndex = $(this).data('section-index');
        var lessonCount = $(this).siblings('.lessons_wrapper').find('.nias_course_lesson_item').length;
        var newLessonIndex = lessonCount;

        var newLesson = `
            <div class="nias_course_lesson_item" data-index="${newLessonIndex}">
                <div class="lesson_header">
                    <label>${nias_course_ajax_object.lesson_title}</label>
                    <input type="text" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_title][]" value="" />

                    <a href="#" class="toggle_lesson">${nias_course_ajax_object.toggle_lesson}</a>
                    <a href="#" class="nias_course_remove_lesson">${nias_course_ajax_object.remove_lesson}</a>
                </div>
                <div class="lesson_content" style="display: none;">
                    <label>${nias_course_ajax_object.upload_icon}</label>
                    <input type="text" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_icon][]" value="" />
                    <button class="upload_image_button" data-target="[name='nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_icon][]']">${nias_course_ajax_object.upload_icon}</button>

                    <label>${nias_course_ajax_object.lesson_label}</label>
                    <input type="text" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_label][]" value="" />

                    <label>${nias_course_ajax_object.lesson_preview_video}</label>
                    <input type="text" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_preview_video][]" value="" />
                    <button class="upload_video_button" data-target="[name='nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_preview_video][]']">${nias_course_ajax_object.upload_video}</button>

                    <label>${nias_course_ajax_object.lesson_download}</label>
                    <input type="text" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_download][]" value="" />

                    <label>${nias_course_ajax_object.lesson_content}</label>
                    <textarea placeholder="لطفاً محصول را ذخیره و سپس صفحه را رفرش کنید تا ویرایشگر به درستی لود شود" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_content][]" rows="10"></textarea>
                    <label>${nias_course_ajax_object.lesson_private}</label>
                    <input type="checkbox" name="nias_course_sections_list[sections][${sectionIndex}][lessons][lesson_private][${newLessonIndex}]" value="yes" />
                </div>
            </div>
        `;

        $(this).siblings('.lessons_wrapper').append(newLesson);
    });

    // حذف فصل
    $(document).on('click', '.nias_course_remove_section', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_section_item').remove();
    });

    // حذف درس
    $(document).on('click', '.nias_course_remove_lesson', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_lesson_item').remove();
    });

    // باز/بسته کردن محتوای فصل
    $(document).on('click', '.toggle_section', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_section_item').find('.section_content').toggle();
        $(this).toggleClass("active");
    });

    // باز/بسته کردن محتوای درس
    $(document).on('click', '.toggle_lesson', function(e) {
        e.preventDefault();
        $(this).closest('.nias_course_lesson_item').find('.lesson_content').toggle();
        $(this).toggleClass("active");

    });
});
