<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

define('NIASADMIN_URL', plugin_dir_url(__DIR__) . 'admin');
// Add the menu


// Register Carbon Fields for settings
add_action('carbon_fields_register_fields', 'nias_course_settings_fields');
function nias_course_settings_fields()
{

        // بررسی سطح دسترسی - فقط مدیران می‌توانند به تنظیمات دسترسی داشته باشند
        if (!current_user_can('manage_options')) {
            return; // خروج از تابع اگر کاربر دسترسی لازم را ندارد
        }
    Container::make('theme_options', __('تنظیمات دوره ساز نیاس', 'nias-course-widget'))
        ->set_page_file('nias-course-settings')
        ->set_icon(NIASADMIN_URL . '/nias-course.png')
        ->add_fields([
            Field::make('html', 'nias_course_migration_section')
                ->set_html('
                    <div class="nias-course-migrate">
                        <h1>' . __('انتقال دیتا ها به دوره ساز جدید', 'nias-course-widget') . '</h1>
                        <form method="post">
                            ' . wp_nonce_field('migrate_courses_nonce', '_wpnonce', true, false) . '
                            <p>' . __('چنانچه از ویجت ووکامرس دوره ساز استفاده میکردید و مشکلاتی را در آپدیت جدید مشاهده میکنید جهت انتقال داده ها به ویرایشگر جدید کلیک کنید توجه کنید حتماً از سایت بک اپ تهیه کنید :', 'nias-course-widget') . '</p>
                            <input type="submit" name="migrate_courses" class="button button-primary" value="' . __('شروع انتقال و همگام سازی', 'nias-course-widget') . '">
                        </form>
                    </div>
                '),
                /*
            Field::make('checkbox', 'nias_check_unregister_message', __('پنهان سازی دوره در صورت عدم ورود کاربر', 'nias-course-widget')),
            Field::make('text', 'nias_signin_link', __('لینک ورود', 'nias-course-widget')),
            */
            // Add this field to the add_fields array
Field::make('radio', 'nias_two_way_verification', __('فعالسازی حالت دو جانبه بررسی خرید دوره ها', 'nias-course-widget'))
->set_options([
    'off' => __('غیرفعال', 'nias-course-widget'),
    'on' => __('فعال', 'nias-course-widget'),
])
->set_default_value('off')
->set_help_text(__('توجه این حالت تنها در صورتی استفاده شود که دوره های خریداری شده برای کاربران شما باز نمیشود', 'nias-course-widget'))
->set_classes('nias-toggle-switch'),
            Field::make('html', 'nias_course_help_section')
                ->set_html('
                    <h2>' . __('آموزش استفاده از پلاگین را از اینجا ببینید', 'nias-course-widget') . '</h2>
                    <div style="max-width: 363px;">
                        <h3>پارت 1</h3>
                        <div class="h_iframe-aparat_embed_frame">
                            <span style="display: block;padding-top: 57%"></span>
                            <iframe src="https://www.aparat.com/video/video/embed/videohash/b90c8sh/vt/frame?titleShow=true&recom=self" allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe>
                        </div>
                        <h3>پارت 2</h3>
                        <div class="h_iframe-aparat_embed_frame">
                            <span style="display: block;padding-top: 57%"></span>
                            <iframe src="https://www.aparat.com/video/video/embed/videohash/lcd5qbk/vt/frame" allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe>
                        </div>
                    </div>
                    <p>' . __('لطفاً در صورت وجود مشکل یا سوال از طریق تلگرام با بنده در ارتباط باشید', 'nias-course-widget') . '</p>
                    <a href="https://T.me/niasir">T.me/niasir</a>
                '),
            Field::make('radio', 'nias_course_modern_setting', __('فعالسازی حالت مدرن دوره ساز', 'nias-course-widget'))
                ->set_options([
                    'off' => __('غیرفعال', 'nias-course-widget'),
                    'on' => __('فعال', 'nias-course-widget'),
                ])
                ->set_default_value('off')
                ->set_help_text(__('با فعال کردن این گزینه، ظاهر مدرن دوره ساز فعال خواهد شد', 'nias-course-widget'))
                ->set_classes('nias-toggle-switch'),

        ]);
}

// Add the necessary styles
function nias_course_admin_style()
{
    
    wp_enqueue_style('nias-admin-css', NIASADMIN_URL . '/adminstyle.css');
?>
    <link href='https://fonts.googleapis.com/css?family=Vazirmatn' rel='stylesheet'>
    <style>
        [dir=rtl] .carbon-network #post-body.columns-2 #postbox-container-1.fixed, [dir=rtl] .carbon-theme-options #post-body.columns-2 #postbox-container-1.fixed {
    right: auto!important;
    left: 0!important;
    margin: 0 0 0 20px!important;
}

.nias-toggle-switch .cf-radio__list {
            display: flex;
            background: #e4e4e4;
            padding: 3px;
            border-radius: 50px;
            width: fit-content;
        }
        
        .nias-toggle-switch .cf-radio__list-item {
            margin: 0 !important;
        }
        
        .nias-toggle-switch .cf-radio__label {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .nias-toggle-switch .cf-radio__input:checked + .cf-radio__label {
            background: #2271b1;
            color: white;
        }
        
        .nias-toggle-switch .cf-radio__input {
            display: none;
        }
        .nias-course-migrate {
            background-color: #ffe5e5;
            padding: 20px;
            border-radius: 15px;
            border: 1px solid red;
        }

        .nias-course-migrate .button {
            background: red !important;
            border: none;
            border-radius: 10px;
        }

        body *:not(#wpadminbar *, i , [role=treeitem] span[aria-hidden] , .dashicons) {
            font-family: 'Vazirmatn' !important;
        }

        .h_iframe-aparat_embed_frame {
            position: relative;
        }

        .h_iframe-aparat_embed_frame .ratio {
            display: block;
            width: 100%;
            height: auto;
        }

        .h_iframe-aparat_embed_frame iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
    </style>
<?php
}
add_action('admin_head', 'nias_course_admin_style');

// Handle the migration action
add_action('admin_init', 'handle_course_migration');
function handle_course_migration()
{
    if (isset($_POST['migrate_courses']) && check_admin_referer('migrate_courses_nonce')) {
        migrate_course_data_to_carbon();
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success"><p>' .
                __('انتقال و همگام سازی اطلاعات موفق بود لطفاً صفحات را پس از پاکسازی کش بررسی کنید', 'nias-course-widget') .
                '</p></div>';
        });
    }
}

function crb_load() {
    try {
        \Carbon_Fields\Carbon_Fields::boot();
    } catch (Exception $e) {
        error_log("Failed to initialize Carbon Fields: " . $e->getMessage());
    }
}


// Migration Function
function migrate_course_data_to_carbon() {

        // بررسی سطح دسترسی - فقط مدیران می‌توانند به تنظیمات دسترسی داشته باشند
        if (!current_user_can('manage_options')) {
            return; // خروج از تابع اگر کاربر دسترسی لازم را ندارد
        } 
        
    // First verify Carbon Fields is available
    if (!function_exists('carbon_set_post_meta')) {
        error_log("Carbon Fields not properly initialized - migration cancelled");
        return false;
    }
    global $wpdb;

    // بررسی تمام حالت‌های ممکن برای پست
    $products = get_posts([
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => [
            'publish',     // منتشر شده
            'draft',       // پیش‌نویس
            'pending',     // در انتظار بررسی
            'private',     // خصوصی
            'future',      // زمان‌بندی شده
            'auto-draft',  // پیش‌نویس خودکار
            'inherit',     // برای revision ها
            'trash'        // در سطل زباله
        ]
    ]);

    
    // ثبت شروع فرآیند مهاجرت
    error_log("Starting migration process. Total products found: " . count($products));

    foreach ($products as $product) {
        try {
            // بررسی وضعیت مهاجرت
            if (get_post_meta($product->ID, '_course_data_migrated', true) === 'yes') {
                error_log("Skipping product ID {$product->ID} - Already migrated");
                continue;
            }

            error_log("Processing product ID: {$product->ID}, Status: {$product->post_status}");

            // دریافت داده‌های قدیمی
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                WHERE post_id = %d AND meta_key = %s",
                $product->ID,
                'nias_course_sections_list'
            ));

            if (empty($meta_rows)) {
                error_log("No course data found for product ID {$product->ID}");
                continue;
            }

            foreach ($meta_rows as $row) {
                // تبدیل داده‌های سریالایز شده
                $sections = maybe_unserialize($row->meta_value);

                // اعتبارسنجی داده‌ها
                if (!is_array($sections)) {
                    error_log("Invalid data format for product ID {$product->ID}");
                    continue;
                }

                // آماده‌سازی داده‌ها برای Carbon Fields
                $carbon_fields_data = [];
                foreach ($sections as $section_index => $section) {
                    $section_data = [
                        'section_title'    => sanitize_text_field($section['section_title'] ?? ''),
                        'section_subtitle' => sanitize_text_field($section['section_subtitle'] ?? ''),
                        'section_icon'     => [
                            [
                                'icon_type' => 'url',
                                'icon_url'  => esc_url_raw($section['section_icon'] ?? ''),
                            ]
                        ],
                        'lessons'          => [],
                    ];

                    if (isset($section['lessons']) && is_array($section['lessons'])) {
                        foreach ($section['lessons'] as $lesson_index => $lesson) {
                            $lesson_data = [
                                'lesson_title'    => sanitize_text_field($lesson['lesson_title'] ?? ''),
                                'lesson_icon'     => [
                                    [
                                        'icon_type' => 'url',
                                        'icon_url'  => esc_url_raw($lesson['lesson_icon'] ?? ''),
                                    ]
                                ],
                                'lesson_label'    => sanitize_text_field($lesson['lesson_label'] ?? ''),
                                'lesson_preview_video' => [
                                    [
                                        'video_type' => 'url',
                                        'video_url'  => esc_url_raw($lesson['lesson_preview_video'] ?? ''),
                                    ]
                                ],
                                'lesson_download' => [
                                    [
                                        'file_type' => 'url',
                                        'file_url'  => esc_url_raw($lesson['lesson_download'] ?? ''),
                                    ]
                                ],
                                'lesson_content'  => wp_kses_post($lesson['lesson_content'] ?? ''),
                                'lesson_private'  => (bool) ($lesson['lesson_private'] ?? false),
                            ];

                            // پاکسازی فیلدهای رسانه خالی
                            if (empty($lesson_data['lesson_icon'][0]['icon_url'])) {
                                $lesson_data['lesson_icon'] = [];
                            }
                            if (empty($lesson_data['lesson_preview_video'][0]['video_url'])) {
                                $lesson_data['lesson_preview_video'] = [];
                            }
                            if (empty($lesson_data['lesson_download'][0]['file_url'])) {
                                $lesson_data['lesson_download'] = [];
                            }

                            $section_data['lessons'][] = $lesson_data;
                        }
                    }

                    // پاکسازی آیکون بخش اگر خالی است
                    if (empty($section_data['section_icon'][0]['icon_url'])) {
                        $section_data['section_icon'] = [];
                    }

                    $carbon_fields_data[] = $section_data;
                }

                // ذخیره داده‌ها در Carbon Fields
                try {
                    carbon_set_post_meta($product->ID, 'course_sections', $carbon_fields_data);
                    update_post_meta($product->ID, '_course_data_migrated', 'yes');
                    error_log("Successfully migrated course data for product ID: {$product->ID}");
                } catch (Exception $e) {
                    error_log("Error saving Carbon Fields data for product ID {$product->ID}: " . $e->getMessage());
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log("Error processing product ID {$product->ID}: " . $e->getMessage());
            continue;
        }
    }

    error_log("Course data migration completed");

    // برگرداندن تعداد محصولات پردازش شده
    return count($products);
}
