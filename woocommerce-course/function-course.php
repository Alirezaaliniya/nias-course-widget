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

