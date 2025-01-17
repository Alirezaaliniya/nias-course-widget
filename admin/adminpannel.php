<?php
define('NIASADMIN_URL',plugin_dir_url(__DIR__).'admin');
function nias_course_admin_style(){
    wp_enqueue_style( 'nias-admin-css', NIASADMIN_URL.'/adminstyle.css');
    ?>
    <link href='https://fonts.googleapis.com/css?family=Vazirmatn' rel='stylesheet'>
    <?php
}
add_action( 'admin_head', 'nias_course_admin_style' );


// اضافه کردن منو به وردپرس
function nias_course_add_menu() {
    // اضافه کردن منو با نام "تنظیمات پلاگین"
    add_menu_page(
        __('دوره ساز نیاس', 'nias-course-widget'), // عنوان منو
        __('دوره ساز نیاس', 'nias-course-widget'), // نام منو در منوی کناری وردپرس
        'manage_options', // نوع دسترسی به منو
        'nias-course-settings', // شناسه منو
        'nias_course_render_settings_page', // نام تابع برای نمایش صفحه تنظیمات
        NIASADMIN_URL.'/nias-course.png' // آیکون منو (اختیاری)
    );
}
add_action('admin_menu', 'nias_course_add_menu');

// Add to your main plugin file
add_action('admin_notices', 'show_course_issue_notice');
add_action('wp_ajax_dismiss_course_issue_notice', 'dismiss_course_issue_notice');

function show_course_issue_notice() {
    // Check if notice was already dismissed
    if (get_option('course_issue_notice_dismissed')) {
        return;
    }
    
    ?>
    <div class="notice notice-error is-dismissible" id="course-issue-notice">
        <p>
            <?php _e('در صورت وجود مشکل در نمایش دوره ها', 'nias-course-widget'); ?> 
            <a href="<?php echo admin_url('admin.php?page=nias-course-settings'); ?>">
                <?php _e('اینجا کلیک کنید', 'nias-course-widget'); ?>
            </a>
        </p>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#course-issue-notice').on('click', '.notice-dismiss', function() {
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'dismiss_course_issue_notice'
                },
                success: function() {
                    $('#course-issue-notice').remove();
                }
            });
        });
    });
    </script>
    <?php
}

function dismiss_course_issue_notice() {
    update_option('course_issue_notice_dismissed', true);
    wp_die();
}

// نمایش صفحه تنظیمات
function nias_course_render_settings_page() {
    ?>



    <div class="wrap">
        <style>
            .nias-course-migrate {
    background-color: #ffe5e5;
    padding: 20px;
    border-radius: 15px;
    border: 1px solid red;
}
.nias-course-migrate .button{
    background:red!important;
    border:none;
    border-radius:10px
    
}
        </style>
        <h1><?php _e('تنظیمات پلاگین', 'nias-course-widget'); ?></h1>
<?php

        if (isset($_POST['migrate_courses']) && check_admin_referer('migrate_courses_nonce')) {
        migrate_course_data_to_carbon();
        echo '<div class="notice notice-success"><p>' . __('انتقال و همگام سازی اطلاعات موفق بود لطفاً صفحات را پس از پاکسازی کش بررسی کنید', 'nias-course-widget') . '</p></div>';
    }
    ?>
    <div class="nias-course-migrate">
        <h1><?php _e('انتقال دیتا ها به دوره ساز جدید', 'nias-course-widget'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('migrate_courses_nonce'); ?>
            <p><?php _e('چنانچه از ویجت ووکامرس دوره ساز استفاده میکردید و مشکلاتی را در آپدیت جدید مشاهده میکنید جهت انتقال داده ها به ویرایشگر جدید کلیک کنید توجه کنید حتماً از سایت بک اپ تهیه کنید :', 'nias-course-widget'); ?></p>
            <input type="submit" name="migrate_courses" class="button button-primary" value="<?php _e('شروع انتقال و همگام سازی', 'nias-course-widget'); ?>">
        </form>
    </div>








        <p>بزودی:تنظیمات وامکانات بیشتر...</p>
        <form method="post" action="options.php">
            <?php settings_fields('nias_course_settings_group'); ?>
            <?php do_settings_sections('nias-course-settings');
            /* ?>
       <label for="nias_check_unregister_message">پنهان سازی دوره در صورت عدم ورود کاربر</label>
            <input type="checkbox" id="nias_check_unregister_message" name="nias_check_unregister_message" value="1" <?php checked(1, get_option('nias_check_unregister_message'), true); */
            ?>
            <p>لطفاً در صورت وجود مشکل یا سوال از طریق تلگرام با بنده در ارتباط باشید</p>
            <a href="https://T.me/niasir">T.me/niasir</a>

            <h2 style="color: red;">نحوه درج ایفریم در محتوا</h2>
            <p style="color: red;">قسمتی که محتوای متنی دوره را درج میکنید یک تب دیداری و یک تب متن دارد از انجایی که ایفریم کدhtml است باید در تب متن وارد کنید</p>

            <h2>آموزش استفاده از پلاگین را از اینجا ببینید</h2>
<div style="max-width: 363px;">
    <h3>پارت 1</h3>
    <style>.h_iframe-aparat_embed_frame{position:relative;}.h_iframe-aparat_embed_frame .ratio{display:block;width:100%;height:auto;}.h_iframe-aparat_embed_frame iframe{position:absolute;top:0;left:0;width:100%;height:100%;}</style><div class="h_iframe-aparat_embed_frame"><span style="display: block;padding-top: 57%"></span><iframe src="https://www.aparat.com/video/video/embed/videohash/b90c8sh/vt/frame?titleShow=true&recom=self"  allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe></div>
        <h3>پارت 2</h3>
        <style>.h_iframe-aparat_embed_frame{position:relative;}.h_iframe-aparat_embed_frame .ratio{display:block;width:100%;height:auto;}.h_iframe-aparat_embed_frame iframe{position:absolute;top:0;left:0;width:100%;height:100%;}</style><div class="h_iframe-aparat_embed_frame"><span style="display: block;padding-top: 57%"></span><iframe src="https://www.aparat.com/video/video/embed/videohash/lcd5qbk/vt/frame"  allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe></div>
    </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <style> 
        body *:not(#wpadminbar *,i){
  font-family: 'Vazirmatn'!important;
}

    </style>


    <?php
}


function nias_course_settings_register(){
register_setting( 'nias_course_settings_group', 'nias_check_unregister_message' );
register_setting('nias_course_settings_group' , 'nias_signin_link');
}
add_action( 'admin_init', 'nias_course_settings_register');





// Migration Function
function migrate_course_data_to_carbon() {
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
                $product->ID, 'nias_course_sections_list'
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

