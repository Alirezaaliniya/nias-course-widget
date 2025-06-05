<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

// Add certificate settings submenu
add_action('admin_menu', 'add_certificate_settings_submenu', 20);
function add_certificate_settings_submenu() {
    add_submenu_page(
        'nias_course_settings',
        __('تنظیم مدرک', 'nias-course-widget'),
        __('تنظیم مدرک', 'nias-course-widget'),
        'manage_options',
        'nias-certificate-settings',
        'render_certificate_settings_page'
    );
}

// Register certificate fields
add_action('carbon_fields_register_fields', 'register_certificate_fields');
function register_certificate_fields() {
    Container::make('theme_options', __('تنظیمات مدرک دوره', 'nias-course-widget'))
        ->set_page_parent('nias_course_settings')
        ->add_fields([
            Field::make('image', 'certificate_signature', __('تصویر امضا', 'nias-course-widget'))
                ->set_help_text(__('تصویر امضای مسئول صدور مدرک را آپلود کنید', 'nias-course-widget'))
                ->set_value_type('url')
                ->set_width(50),

            Field::make('image', 'certificate_logo', __('تصویر لوگو', 'nias-course-widget'))
                ->set_help_text(__('لوگوی موسسه یا مرکز آموزشی را آپلود کنید', 'nias-course-widget'))
                ->set_value_type('url')
                ->set_width(50),

            Field::make('image', 'certificate_seal', __('تصویر مهر', 'nias-course-widget'))
                ->set_help_text(__('تصویر مهر موسسه را آپلود کنید', 'nias-course-widget'))
                ->set_value_type('url')
                ->set_width(50),

            Field::make('image', 'certificate_watermark', __('تصویر مارک مدرک', 'nias-course-widget'))
                ->set_help_text(__('تصویر مارک یا واترمارک مدرک را آپلود کنید', 'nias-course-widget'))
                ->set_value_type('url')
                ->set_width(50),

            Field::make('rich_text', 'certificate_description', __('توضیحات مدرک', 'nias-course-widget'))
                ->set_help_text(__('توضیحات و متن مدرک را وارد کنید. می‌توانید از متغیرهای زیر استفاده کنید:', 'nias-course-widget') . 
                    '<br>{student_name} - ' . __('نام دانشجو', 'nias-course-widget') .
                    '<br>{course_name} - ' . __('نام دوره', 'nias-course-widget') .
                    '<br>{completion_date} - ' . __('تاریخ تکمیل دوره', 'nias-course-widget') .
                    '<br>{certificate_id} - ' . __('شماره مدرک', 'nias-course-widget'))
                ->set_settings([
                    'media_buttons' => true,
                    'textarea_rows' => 10,
                    'teeny' => false,
                    'quicktags' => true
                ])
        ]);
}

// Render the certificate settings page
function render_certificate_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'nias-course-widget'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <div class="certificate-settings-container">
            <?php
            // Render Carbon Fields container
            $container = Container::make('theme_options', __('تنظیمات مدرک دوره', 'nias-course-widget'))
                ->set_page_parent('nias_course_settings');
            $container->render();
            ?>
        </div>
    </div>
    <style>
        .certificate-settings-container {
            background: #fff;
            padding: 20px;
            margin-top: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .certificate-settings-container h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
    </style>
    <?php
} 