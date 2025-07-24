<?php
// شورت کد برای نمایش گواهینامه
function nias_button_certificate_shortcode($atts) {
    // دریافت پارامترهای شورت کد
    $atts = shortcode_atts(array(
        'user_id' => get_current_user_id(), // آیدی کاربر (پیش فرض: کاربر فعلی)
        'show_if_enabled' => true, // فقط در صورت فعال بودن تنظیمات نمایش داده شود
    ), $atts);

    // بررسی اینکه آیا نمایش گواهینامه فعال است
    if ($atts['show_if_enabled'] && carbon_get_theme_option('nias_course_certificate') !== 'on') {
        return ''; // اگر غیرفعال باشد، چیزی نمایش نده
    }

    // بررسی وجود کاربر
    if (!$atts['user_id']) {
        return '<p>کاربر یافت نشد.</p>';
    }

    // شروع output buffering برای گرفتن خروجی
    ob_start();
    
    // تولید دکمه تایید گواهینامه
    if (function_exists('generate_certificate_verification_button')) {
        $verification_button = generate_certificate_verification_button($atts['user_id']);
        echo $verification_button;
    } else {
        echo '<p>تابع تولید گواهینامه یافت نشد.</p>';
    }
    
    // دریافت محتوا و تمیز کردن buffer
    $content = ob_get_clean();
    
    return $content;
}

// ثبت شورت کد
add_shortcode('nias_button_certificate', 'nias_button_certificate_shortcode');












