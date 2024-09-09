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


// نمایش صفحه تنظیمات
function nias_course_render_settings_page() {
    ?>



    <div class="wrap">
        
        <h1><?php _e('تنظیمات پلاگین', 'nias-course-widget'); ?></h1>
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

            <h2>آموزش استفاده از پلاگین را از اینجا ببینید</h2>
<div style="max-width: 363px;">
    <style>.h_iframe-aparat_embed_frame{position:relative;}.h_iframe-aparat_embed_frame .ratio{display:block;width:100%;height:auto;}.h_iframe-aparat_embed_frame iframe{position:absolute;top:0;left:0;width:100%;height:100%;}</style><div class="h_iframe-aparat_embed_frame"><span style="display: block;padding-top: 57%"></span><iframe src="https://www.aparat.com/video/video/embed/videohash/b90c8sh/vt/frame?titleShow=true&recom=self"  allowFullScreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe></div>
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