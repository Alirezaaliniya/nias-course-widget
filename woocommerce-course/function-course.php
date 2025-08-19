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
            background-image: url('<?php echo esc_url($logo_url); ?>');            background-size: contain;
            background-repeat: no-repeat;
            width: 70px;
    height: 70px;
    display: block;
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
                                    // نمایش فایل آپلود شده با HTML
                                    Field::make('html', 'file_preview_html')
                                        ->set_html('<div id="nias-file-preview"></div>')
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
                            ->set_help_text('<strong style="font-size:15px;background-color:red;color:white; padding:5px 10px;border-radius:10px;">توجه مهم:</strong> ' . __('برای درج ایفریم یوتیوب و آپارت و ... از تب بالا بخش متن را انتخاب کنید و کد را وارد کنید', 'nias-course-widget')),                        ])
                        ->set_header_template('<%- lesson_title %>'),
                ])
                ->set_header_template('<%- section_title %>'),
        ]);
}

// Add jQuery for file preview functionality
add_action('admin_footer', 'nias_course_file_preview_script');
function nias_course_file_preview_script() {
    $plugin_url = plugin_dir_url(dirname(__FILE__));
    ?>
    <script>
    const pluginUrl = '<?php echo esc_url($plugin_url); ?>';
    jQuery(document).ready(function($) {
        function updateFilePreview(fileUrl, previewContainer) {
            if (!fileUrl) {
                previewContainer.html('');
                return;
            }

            const extension = fileUrl.split('.').pop().toLowerCase();
            let previewHtml = '';

            // Image formats
            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(extension)) {
                previewHtml = `<img src="${fileUrl}" style="max-width: 200px; height: auto;">`;
            } 
            // Video formats
            else if (['mp4', 'webm', 'ogv', 'mov', 'm4v', 'avi'].includes(extension)) {
                previewHtml = `
                    <video controls style="max-width: 200px;">
                        <source src="${fileUrl}" type="video/${extension === 'mov' ? 'quicktime' : (extension === 'm4v' ? 'mp4' : extension)}">
                        Your browser does not support the video tag.
                    </video>`;
            } 
            // Audio formats
            else if (['mp3', 'wav', 'ogg', 'm4a', 'aac'].includes(extension)) {
                previewHtml = `
                    <audio controls style="max-width: 200px;">
                        <source src="${fileUrl}" type="audio/${extension === 'm4a' ? 'mp4' : extension}">
                        Your browser does not support the audio tag.
                    </audio>`;
            }
            // PDF files
            else if (['pdf'].includes(extension)) {
                previewHtml = `<img src="${pluginUrl}/admin/images/pdf-icon.png" style="width: 50px;"><br><a href="${fileUrl}" target="_blank">View PDF</a>`;
            } 
            // Other files
            else {
                previewHtml = `<a href="${fileUrl}" target="_blank">Download File</a>`;
            }

            previewContainer.html(previewHtml);
        }

        // Function to initialize file preview for a specific group
        function initializeFilePreview($group) {
            const $fileField = $group.find('.cf-file');
            const $previewContainer = $group.find('#nias-file-preview');
            
            if ($fileField.length && $previewContainer.length) {
                const $fileInput = $fileField.find('input[type="hidden"]');
                if ($fileInput.length) {
                    updateFilePreview($fileInput.val(), $previewContainer);

                    // Watch for changes using MutationObserver on the input
                    const observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                                updateFilePreview($fileInput.val(), $previewContainer);
                            }
                        });
                    });

                    observer.observe($fileInput[0], {
                        attributes: true
                    });
                }
            }
        }

        // Main container observer to watch for dynamically added groups
        const containerObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            const $node = $(node);
                            if ($node.hasClass('cf-complex__group') || $node.hasClass('cf-complex__group--grid')) {
                                initializeFilePreview($node);
                            } else {
                                // Check for groups inside the added node
                                $node.find('.cf-complex__group, .cf-complex__group--grid').each(function() {
                                    initializeFilePreview($(this));
                                });
                            }
                        }
                    });
                }
            });
        });

        // Start observing the main container for dynamic changes
        const $mainContainer = $('.cf-container__fields');
        if ($mainContainer.length) {
            containerObserver.observe($mainContainer[0], {
                childList: true,
                subtree: true
            });
        }

        // Event delegation for file actions
        $(document).on('click', '.cf-file__browse', function() {
            const $group = $(this).closest('.cf-complex__group, .cf-complex__group--grid');
            const $fileInput = $(this).closest('.cf-file__inner').find('input[type="hidden"]');
            const $previewContainer = $group.find('#nias-file-preview');
            
            if ($fileInput.length && $previewContainer.length) {
                setTimeout(function() {
                    updateFilePreview($fileInput.val(), $previewContainer);
                }, 500);
            }
        });

        $(document).on('click', '.cf-file__remove', function() {
            const $group = $(this).closest('.cf-complex__group, .cf-complex__group--grid');
            const $previewContainer = $group.find('#nias-file-preview');
            
            if ($previewContainer.length) {
                setTimeout(function() {
                    updateFilePreview('', $previewContainer);
                }, 100);
            }
        });

        // Initialize existing groups
        $('.cf-complex__group, .cf-complex__group--grid').each(function() {
            initializeFilePreview($(this));
        });

        // Handle Carbon Fields events
        $(document)
            .on('carbon_fields_complex_field_added', function(e, $group) {
                setTimeout(function() {
                    initializeFilePreview($group);
                }, 100);
            })
            .on('carbon_fields_complex_field_changes', function() {
                $('.cf-complex__group, .cf-complex__group--grid').each(function() {
                    initializeFilePreview($(this));
                });
            });
    });
    </script>
    <?php
}







