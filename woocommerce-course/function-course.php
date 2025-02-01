<?php
use Carbon_Fields\Container\Container;
use Carbon_Fields\Field\Field;
// Add custom CSS for Carbon Fields styling
add_action('admin_head', 'custom_carbon_fields_styles');
function custom_carbon_fields_styles() {
    $logo_url = plugin_dir_url(__DIR__) . 'admin/nias-course.png';

    ?>
    <style>

       .nias-course-product-option .cf-container__fields:before {
            content: '';
            background-image: url('<?php echo $logo_url; ?>');
            background-size: contain;
            background-repeat: no-repeat;
            width: 70px;
    height: 70px;
    display: block;
        }
        .nias-course-product-option ul.cf-complex__tabs-list.ui-sortable li {
    border-radius:8px;
    background-color:#e2e9ff;
    color:#5573ff;
    transition:0.3s;
    padding: 12px;
    
}
.nias-course-product-option ul.cf-complex__tabs-list.ui-sortable .cf-complex__tabs-item--current {
    border-radius:8px;
    background-color:#5573ff;
    color:#ffffff;
    border:1px solid!important;
    
}
.nias-course-product-option .cf-complex__tabs--tabbed-vertical ul.cf-complex__tabs-list.ui-sortable{
    display:flex;
    flex-direction:column;
    gap:5px;
}

.nias-course-product-option .cf-complex--tabbed-vertical>.cf-field__body{
    gap:5px
}

.nias-course-product-option .cf-complex--tabbed-vertical button.button.cf-complex__inserter-button:not(.cf-complex__tabs--tabbed-horizontal button) {
    margin-top:10px;
    color:#5573ff!important;
    border:none;
    background-color:#f1f1f1;
    border-radius:8px;
}
.cf-complex__tabs--tabbed-horizontal button{
    color:#5573ff!important;
    border:none!important;
    background-color:#f1f1f1!important;
    border-radius:8px!important; 
    margin-right: 10px!important;
}

.nias-course-product-option .cf-text__input{
    border:1px solid #dcdcdc!important;
    border-radius:8px!important;
    padding: 8px 10px!important;
}

.nias-course-product-option h1, .nias-course-product-option h2, .nias-course-product-option h3, .nias-course-product-option h4, .nias-course-product-option h5, .nias-course-product-option h6 {
    font-family: vazirmatn!important;
    font-weight: 600;
}
.nias-course-product-option {
    font-family: vazirmatn!important;
}

    </style>
    <?php
}
// Register Course Fields
add_action('carbon_fields_register_fields', 'create_course_metabox');
function create_course_metabox() {
    Container::make('post_meta', __(' تنظیمات دوره ساز نیاس', 'nias-course-widget'))
        ->where('post_type', '=', 'product')
        ->set_classes('nias-course-product-option')
        ->set_priority('high')
        ->add_fields([
            Field::make('complex', 'course_sections', __('فصل‌ها', 'nias-course-widget'))
                ->set_layout('tabbed-vertical')
                ->add_fields([
                    Field::make('text', 'section_title', __('عنوان فصل', 'nias-course-widget'))
                        ->set_required(true),
                    Field::make('text', 'section_subtitle', __('زیرعنوان', 'nias-course-widget')),
                    Field::make('complex', 'section_icon', __('آیکون', 'nias-course-widget'))
                        ->add_fields([
                            Field::make('select', 'icon_type', __('نوع آیکون', 'nias-course-widget'))
                                ->set_options([
                                    'upload' => __('آپلود فایل', 'nias-course-widget'),
                                    'url' => __('لینک مستقیم', 'nias-course-widget')
                                ]),
                            Field::make('image', 'icon_upload', __('آپلود آیکون', 'nias-course-widget'))
                                ->set_value_type('url')
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'upload']
                                ]),
                            Field::make('text', 'icon_url', __('لینک آیکون', 'nias-course-widget'))
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'url']
                                ])
                        ])
                        ->set_max(1),
                    Field::make('complex', 'lessons', __('درس‌ها', 'nias-course-widget'))
                        ->set_layout('tabbed-horizontal')
                        ->add_fields([
                            Field::make('text', 'lesson_title', __('عنوان درس', 'nias-course-widget'))
                                ->set_required(true),
                            Field::make('complex', 'lesson_icon', __('آیکون', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'icon_type', __('نوع آیکون', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('image', 'icon_upload', __('آپلود آیکون', 'nias-course-widget'))
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'icon_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'icon_url', __('لینک آیکون', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'icon_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('text', 'lesson_label', __('برچسب', 'nias-course-widget')),
                            Field::make('complex', 'lesson_preview_video', __('ویدیوی پیش‌نمایش', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'video_type', __('نوع ویدیو', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('file', 'video_upload', __('آپلود ویدیو', 'nias-course-widget'))
                                        ->set_type(['video'])
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'video_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'video_url', __('لینک ویدیو', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'video_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('complex', 'lesson_download', __('فایل خصوصی درس', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'file_type', __('نوع فایل', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('file', 'file_upload', __('آپلود فایل', 'nias-course-widget'))
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'file_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'file_url', __('لینک فایل', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'file_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('checkbox', 'lesson_private', __('درس خصوصی است؟', 'nias-course-widget')),
                            Field::make('rich_text', 'lesson_content', __('محتوای درس', 'nias-course-widget'))
                        ])
                        ->set_header_template('<%- lesson_title %>'),
                ])
                ->set_header_template('<%- section_title %>'),
        ]);
}










// Add a new container for frontend editing
add_action('carbon_fields_register_fields', 'create_frontend_course_editor');
function create_frontend_course_editor() {
    // Check user permissions
    if (!is_user_logged_in()) {
        return;
    }

    $allowed_roles = array('administrator', 'shop_manager', 'vendor');
    $user = wp_get_current_user();
    $has_permission = array_intersect($allowed_roles, $user->roles);

    if (empty($has_permission)) {
        return;
    }

    Container::make('post_meta', __('ویرایش دوره', 'nias-course-widget'))
        ->where('post_type', '=', 'product')
        ->set_context('frontend') // Set context to frontend
        ->set_classes('nias-course-product-option frontend-editor')
        ->add_fields([
            Field::make('complex', 'course_sections', __('فصل‌ها', 'nias-course-widget'))
                ->set_layout('tabbed-vertical')
                ->add_fields([
                    Field::make('text', 'section_title', __('عنوان فصل', 'nias-course-widget'))
                        ->set_required(true),
                    Field::make('text', 'section_subtitle', __('زیرعنوان', 'nias-course-widget')),
                    Field::make('complex', 'section_icon', __('آیکون', 'nias-course-widget'))
                        ->add_fields([
                            Field::make('select', 'icon_type', __('نوع آیکون', 'nias-course-widget'))
                                ->set_options([
                                    'upload' => __('آپلود فایل', 'nias-course-widget'),
                                    'url' => __('لینک مستقیم', 'nias-course-widget')
                                ]),
                            Field::make('image', 'icon_upload', __('آپلود آیکون', 'nias-course-widget'))
                                ->set_value_type('url')
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'upload']
                                ]),
                            Field::make('text', 'icon_url', __('لینک آیکون', 'nias-course-widget'))
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'url']
                                ])
                        ])
                        ->set_max(1),
                    Field::make('complex', 'lessons', __('درس‌ها', 'nias-course-widget'))
                        ->set_layout('tabbed-horizontal')
                        ->add_fields([
                            Field::make('text', 'lesson_title', __('عنوان درس', 'nias-course-widget'))
                                ->set_required(true),
                            Field::make('text', 'lesson_label', __('برچسب', 'nias-course-widget')),
                            Field::make('checkbox', 'lesson_private', __('درس خصوصی است؟', 'nias-course-widget')),
                            Field::make('rich_text', 'lesson_content', __('محتوای درس', 'nias-course-widget'))
                        ])
                        ->set_header_template('<%- lesson_title %>')
                ])
                ->set_header_template('<%- section_title %>')
        ]);
}









// Add shortcode to display the frontend editor
add_shortcode('nias_course_editor', 'display_frontend_course_editor');function display_frontend_course_editor($atts) {
    if (!is_user_logged_in()) {
        return __('دسترسی محدود شده است.', 'nias-course-widget');
    }

    $allowed_roles = array('administrator', 'shop_manager', 'vendor');
    $user = wp_get_current_user();
    $has_permission = array_intersect($allowed_roles, $user->roles);

    if (empty($has_permission)) {
        return __('شما مجوز ویرایش این بخش را ندارید.', 'nias-course-widget');
    }

    // Get product ID based on context
    $product_id = 0;
    
    if (isset($_GET['product_id'])) {
        // Dokan vendor panel context
        $product_id = absint($_GET['product_id']);
    } else {
        // Regular product page context
        $product_id = get_the_ID();
    }

    // Verify product exists and user has permission
    if (!$product_id || !get_post($product_id)) {
        return __('محصول مورد نظر یافت نشد.', 'nias-course-widget');
    }

    // Verify product belongs to vendor if user is vendor
    if (in_array('vendor', $user->roles)) {
        $vendor_id = dokan_get_vendor_id_by_product($product_id);
        if ($vendor_id != get_current_user_id()) {
            return __('شما مجوز ویرایش این محصول را ندارید.', 'nias-course-widget');
        }
    }

    $course_sections = carbon_get_post_meta($product_id, 'course_sections');

    ob_start();
    ?>
    <div class="nias-course-frontend-editor">
        <form id="course-editor-form" class="frontend-editor">
            <?php wp_nonce_field('save_course_data', 'course_editor_nonce'); ?>
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
            
            <div class="course-sections">
                <?php 
                if (!empty($course_sections)) {
                    foreach ($course_sections as $section_index => $section) : ?>
                        <div class="section-container" data-index="<?php echo esc_attr($section_index); ?>">
                            <div class="section-header">
                                <h3>
                                    <input type="text" 
                                           name="course_sections[<?php echo esc_attr($section_index); ?>][section_title]" 
                                           value="<?php echo esc_attr($section['section_title']); ?>"
                                           placeholder="<?php esc_attr_e('عنوان فصل', 'nias-course-widget'); ?>">
                                </h3>
                                
                                <input type="text" 
                                       name="course_sections[<?php echo esc_attr($section_index); ?>][section_subtitle]" 
                                       value="<?php echo esc_attr($section['section_subtitle']); ?>"
                                       placeholder="<?php esc_attr_e('زیرعنوان', 'nias-course-widget'); ?>">
                            </div>

                            <?php if (!empty($section['lessons'])) : ?>
                                <div class="lessons-container">
                                    <?php foreach ($section['lessons'] as $lesson_index => $lesson) : ?>
                                        <div class="lesson-item" data-index="<?php echo esc_attr($lesson_index); ?>">
                                            <input type="text" 
                                                   name="course_sections[<?php echo esc_attr($section_index); ?>][lessons][<?php echo esc_attr($lesson_index); ?>][lesson_title]"
                                                   value="<?php echo esc_attr($lesson['lesson_title']); ?>"
                                                   placeholder="<?php esc_attr_e('عنوان درس', 'nias-course-widget'); ?>">
                                            
                                            <input type="text" 
                                                   name="course_sections[<?php echo esc_attr($section_index); ?>][lessons][<?php echo esc_attr($lesson_index); ?>][lesson_label]"
                                                   value="<?php echo esc_attr($lesson['lesson_label']); ?>"
                                                   placeholder="<?php esc_attr_e('برچسب درس', 'nias-course-widget'); ?>">
                                            
                                            <label>
                                                <input type="checkbox" 
                                                       name="course_sections[<?php echo esc_attr($section_index); ?>][lessons][<?php echo esc_attr($lesson_index); ?>][lesson_private]"
                                                       <?php checked(!empty($lesson['lesson_private'])); ?>>
                                                <?php _e('درس خصوصی', 'nias-course-widget'); ?>
                                            </label>
                                            
                                            <?php 
                                            wp_editor(
                                                $lesson['lesson_content'] ?? '',
                                                'lesson_content_' . $section_index . '_' . $lesson_index,
                                                array(
                                                    'textarea_name' => "course_sections[{$section_index}][lessons][{$lesson_index}][lesson_content]",
                                                    'textarea_rows' => 10,
                                                    'media_buttons' => true,
                                                    'teeny' => true
                                                )
                                            );
                                            ?>
                                            
                                            <button type="button" class="remove-lesson button-secondary">
                                                <?php _e('حذف درس', 'nias-course-widget'); ?>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <button type="button" class="add-lesson button-secondary" data-section="<?php echo esc_attr($section_index); ?>">
                                <?php _e('افزودن درس', 'nias-course-widget'); ?>
                            </button>
                            
                            <button type="button" class="remove-section button-secondary">
                                <?php _e('حذف فصل', 'nias-course-widget'); ?>
                            </button>
                        </div>
                    <?php endforeach;
                }
                ?>
            </div>
            
            <button type="button" class="add-section button-secondary">
                <?php _e('افزودن فصل جدید', 'nias-course-widget'); ?>
            </button>
            
            <button type="submit" class="save-changes button-primary">
                <?php _e('ذخیره تغییرات', 'nias-course-widget'); ?>
            </button>
        </form>
    </div>

    <script>

jQuery(document).ready(function($) {
    // Templates for new sections and lessons
    const sectionTemplate = (index) => `
        <div class="section-container" data-index="${index}">
            <div class="section-header">
                <h3>
                    <input type="text" 
                           name="course_sections[${index}][section_title]" 
                           placeholder="${wp_vars.i18n.section_title}"
                           required>
                </h3>
                <input type="text" 
                       name="course_sections[${index}][section_subtitle]" 
                       placeholder="${wp_vars.i18n.section_subtitle}">
            </div>
            <div class="lessons-container"></div>
            <button type="button" class="add-lesson button-secondary" data-section="${index}">
                ${wp_vars.i18n.add_lesson}
            </button>
            <button type="button" class="remove-section button-secondary">
                ${wp_vars.i18n.remove_section}
            </button>
        </div>
    `;

    const lessonTemplate = (sectionIndex, lessonIndex) => `
        <div class="lesson-item" data-index="${lessonIndex}">
            <input type="text" 
                   name="course_sections[${sectionIndex}][lessons][${lessonIndex}][lesson_title]"
                   placeholder="${wp_vars.i18n.lesson_title}"
                   required>
            
            <input type="text" 
                   name="course_sections[${sectionIndex}][lessons][${lessonIndex}][lesson_label]"
                   placeholder="${wp_vars.i18n.lesson_label}">
            
            <label>
                <input type="checkbox" 
                       name="course_sections[${sectionIndex}][lessons][${lessonIndex}][lesson_private]">
                ${wp_vars.i18n.private_lesson}
            </label>
            
            <div class="lesson-content-wrapper">
                <textarea 
                    id="lesson_content_${sectionIndex}_${lessonIndex}"
                    name="course_sections[${sectionIndex}][lessons][${lessonIndex}][lesson_content]"
                    class="lesson-content-editor"></textarea>
            </div>
            
            <button type="button" class="remove-lesson button-secondary">
                ${wp_vars.i18n.remove_lesson}
            </button>
        </div>
    `;

    // Initialize TinyMCE for a new lesson
    function initializeTinyMCE(sectionIndex, lessonIndex) {
        const editorId = `lesson_content_${sectionIndex}_${lessonIndex}`;
        
        // Remove if already initialized
        if (tinymce.get(editorId)) {
            tinymce.remove(`#${editorId}`);
        }

        // Initialize new editor
        wp.editor.initialize(editorId, {
            tinymce: {
                wpautop: true,
                plugins: 'lists link image media wordpress wplink',
                toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link media',
                height: 200
            },
            quicktags: true,
            mediaButtons: true
        });
    }

    // Handle form submission with proper validation
    $('#course-editor-form').on('submit', function(e) {
        e.preventDefault();
        
        // Validate required fields
        let isValid = true;
        $(this).find('input[required]').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });

        if (!isValid) {
            alert(wp_vars.i18n.fill_required);
            return;
        }

        // Update all TinyMCE editors before submit
        if (typeof tinymce !== 'undefined') {
            tinymce.triggerSave();
        }

        const formData = new FormData(this);
        formData.append('action', 'save_course_data');
        
        // Disable submit button and show loading state
        const submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true).text(wp_vars.i18n.saving);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                } else {
                    alert(response.data || wp_vars.i18n.error_saving);
                }
            },
            error: function() {
                alert(wp_vars.i18n.error_saving);
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(wp_vars.i18n.save_changes);
            }
        });
    });

    // Add new section with proper index management
    $('.add-section').on('click', function() {
        const sectionIndex = $('.section-container').length;
        const newSection = $(sectionTemplate(sectionIndex));
        $('.course-sections').append(newSection);
    });

    // Add new lesson with proper index management
    $(document).on('click', '.add-lesson', function() {
        const sectionContainer = $(this).closest('.section-container');
        const sectionIndex = sectionContainer.data('index');
        const lessonIndex = sectionContainer.find('.lesson-item').length;
        
        const newLesson = $(lessonTemplate(sectionIndex, lessonIndex));
        sectionContainer.find('.lessons-container').append(newLesson);
        
        // Initialize TinyMCE for the new lesson
        initializeTinyMCE(sectionIndex, lessonIndex);
    });

    // Remove section with confirmation
    $(document).on('click', '.remove-section', function() {
        if (confirm(wp_vars.i18n.confirm_remove_section)) {
            const section = $(this).closest('.section-container');
            
            // Remove all TinyMCE instances in this section
            section.find('.lesson-content-editor').each(function() {
                const editorId = $(this).attr('id');
                if (tinymce.get(editorId)) {
                    tinymce.remove(`#${editorId}`);
                }
            });
            
            section.remove();
            reindexSections();
        }
    });

    // Remove lesson with confirmation
    $(document).on('click', '.remove-lesson', function() {
        if (confirm(wp_vars.i18n.confirm_remove_lesson)) {
            const lesson = $(this).closest('.lesson-item');
            const editorId = lesson.find('.lesson-content-editor').attr('id');
            
            // Remove TinyMCE instance
            if (tinymce.get(editorId)) {
                tinymce.remove(`#${editorId}`);
            }
            
            lesson.remove();
            reindexLessons(lesson.closest('.section-container'));
        }
    });

    // Reindex sections after removal
    function reindexSections() {
        $('.section-container').each(function(newIndex) {
            const section = $(this);
            section.attr('data-index', newIndex);
            
            // Update section fields
            section.find('input, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/course_sections\[\d+\]/, `course_sections[${newIndex}]`));
                }
            });
            
            // Update lesson add button
            section.find('.add-lesson').attr('data-section', newIndex);
            
            // Reindex lessons within this section
            reindexLessons(section);
        });
    }

    // Reindex lessons within a section
    function reindexLessons(section) {
        const sectionIndex = section.data('index');
        section.find('.lesson-item').each(function(newIndex) {
            const lesson = $(this);
            lesson.attr('data-index', newIndex);
            
            // Update all lesson fields
            lesson.find('input, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(
                        /course_sections\[\d+\]\[lessons\]\[\d+\]/,
                        `course_sections[${sectionIndex}][lessons][${newIndex}]`
                    ));
                }
            });
            
            // Update editor ID if exists
            const editor = lesson.find('.lesson-content-editor');
            if (editor.length) {
                const oldId = editor.attr('id');
                const newId = `lesson_content_${sectionIndex}_${newIndex}`;
                
                if (oldId !== newId) {
                    // Remove old TinyMCE instance
                    if (tinymce.get(oldId)) {
                        tinymce.remove(`#${oldId}`);
                    }
                    
                    editor.attr('id', newId);
                    
                    // Reinitialize TinyMCE
                    initializeTinyMCE(sectionIndex, newIndex);
                }
            }
        });
    }
});


    </script>

    <style>
    .frontend-editor {
        max-width: 1200px;
        margin: 2rem auto;
        padding: 2rem;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .section-container {
        background: #f8f9fa;
        padding: 1.5rem;
        margin-bottom: 2rem;
        border-radius: 8px;
    }

    .lesson-item {
        background: #fff;
        padding: 1rem;
        margin: 1rem 0;
        border-radius: 4px;
    }

    .frontend-editor input[type="text"] {
        width: 100%;
        padding: 0.75rem;
        margin-bottom: 1rem;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .button-secondary {
        margin: 0.5rem;
    }

    .save-changes {
        margin-top: 2rem;
        padding: 1rem 2rem;
    }
    </style>
    <?php
    return ob_get_clean();
}

// Add AJAX handler
add_action('wp_ajax_save_course_data', 'save_course_data');
function save_course_data() {
    if (!check_ajax_referer('save_course_data', 'course_editor_nonce', false)) {
        wp_send_json_error(__('خطا در تایید امنیتی', 'nias-course-widget'));
    }

    $product_id = intval($_POST['product_id']);
    $course_sections = $_POST['course_sections'] ?? [];

    // Save using Carbon Fields
    carbon_set_post_meta($product_id, 'course_sections', $course_sections);
    
    wp_send_json_success(__('تغییرات با موفقیت ذخیره شد.', 'nias-course-widget'));
}


// Add this inside your display_frontend_course_editor function, before the JavaScript
wp_localize_script('jquery', 'wp_vars', array(
    'i18n' => array(
        'section_title' => __('عنوان فصل', 'nias-course-widget'),
        'section_subtitle' => __('زیرعنوان', 'nias-course-widget'),
        'lesson_title' => __('عنوان درس', 'nias-course-widget'),
        'lesson_label' => __('برچسب', 'nias-course-widget'),
        'private_lesson' => __('درس خصوصی', 'nias-course-widget'),
        'add_lesson' => __('افزودن درس', 'nias-course-widget'),
        'remove_lesson' => __('حذف درس', 'nias-course-widget'),
        'add_section' => __('افزودن فصل جدید', 'nias-course-widget'),
        'remove_section' => __('حذف فصل', 'nias-course-widget'),
        'save_changes' => __('ذخیره تغییرات', 'nias-course-widget'),
        'saving' => __('در حال ذخیره...', 'nias-course-widget'),
        'fill_required' => __('لطفا همه فیلدهای الزامی را پر کنید.', 'nias-course-widget'),
        'confirm_remove_section' => __('آیا از حذف این فصل اطمینان دارید؟', 'nias-course-widget'),
        'confirm_remove_lesson' => __('آیا از حذف این درس اطمینان دارید؟', 'nias-course-widget'),
        'error_saving' => __('خطا در ذخیره تغییرات', 'nias-course-widget'),
    )
));

