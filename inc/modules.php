<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «ماژول‌ها» settings page: optional features, each switched on separately.
 * A module's code runs only when it is on.
 *
 * @package nias-course-widget
 */

function nias_modules_fields()
{
    return array(
        'nias_drip_enabled' => 'radio',
        'nias_drip_email'   => 'radio',
    );
}

add_action('admin_menu', function () {
    add_submenu_page('nias-course-settings', __('ماژول‌ها', 'nias-course-widget'), __('ماژول‌ها', 'nias-course-widget'), 'manage_options', 'nias-course-modules', 'nias_modules_page');
}, 30);

/** Modules planned for the next versions (shown as «به‌زودی»). */
function nias_modules_upcoming()
{
    return array(
        __('محدودیت دستگاه و ورود همزمان', 'nias-course-widget'),
        __('بستن دسترسی مستقیم به فایل‌ها', 'nias-course-widget'),
        __('دسترسی زمان‌دار و تمدید', 'nias-course-widget'),
        __('یادآوری به دانشجوی غیرفعال', 'nias-course-widget'),
        __('تکلیف با بارگذاری فایل', 'nias-course-widget'),
        __('یادداشت و نشانک زمان‌دار', 'nias-course-widget'),
        __('امتیاز و نظر خریداران', 'nias-course-widget'),
        __('گزارش تحلیلی', 'nias-course-widget'),
        __('سهم درآمد مدرس', 'nias-course-widget'),
        __('پیش‌نیاز و مسیر یادگیری', 'nias-course-widget'),
        __('کپی و انتقال دوره', 'nias-course-widget'),
        __('کلاس آنلاین', 'nias-course-widget'),
        __('امتیاز و نشان', 'nias-course-widget'),
    );
}

function nias_modules_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = false;
    if (isset($_POST['nias_save_modules']) && check_admin_referer('nias_modules', 'nias_modules_nonce')) {
        nias_course_save_theme_options(nias_modules_fields());
        $saved = true;
    }
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    $drip_on = function_exists('nias_drip_enabled') && nias_drip_enabled();
    $sms_url = admin_url('admin.php?page=nias-course-sms');
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('modules'); ?>
        <div class="nias-set-shell"><div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>
            <form method="post">
                <?php wp_nonce_field('nias_modules', 'nias_modules_nonce'); ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('محتوای قطره‌ای', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_drip_enabled',
                        __('فعال‌سازی محتوای قطره‌ای', 'nias-course-widget'),
                        __('جلسات بعد از ثبت‌نام دانشجو، به ترتیبی که مدرس تعیین می‌کند (روز و ساعت) باز می‌شوند؛ مثلاً امروز جلسهٔ اول، فردا جلسهٔ دوم. تنظیم هر جلسه در ویرایشگر جلسات (پیشخوان و پنل مدرس) با گزینهٔ «قطره‌ای» انجام می‌شود. روز ۱ همان روز ثبت‌نام است؛ ساعت خالی یعنی ۰۰:۰۰. محتوای جلسهٔ بسته اصلاً به مرورگر ارسال نمی‌شود.', 'nias-course-widget'),
                        'off',
                        __('جدید', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_drip_email',
                        __('ایمیل «جلسهٔ جدید باز شد»', 'nias-course-widget'),
                        sprintf(__('وقتی جلسه‌ای برای دانشجو باز می‌شود، ایمیل اطلاع‌رسانی با لینک دوره ارسال شود. پیامک همین رویداد در صفحهٔ <a href="%s">پیامک</a> (رویداد «باز شدن جلسهٔ قطره‌ای») تنظیم می‌شود.', 'nias-course-widget'), esc_url($sms_url)),
                        'off',
                        '',
                        'data-nias-show-when="nias_drip_enabled=on"'
                    );
                    ?>
                <?php nias_set_card_close(); ?>
                <?php nias_set_save_button('nias_save_modules'); ?>
            </form>

            <?php if ($drip_on) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('برنامهٔ قطره‌ای یک دانشجو', 'nias-course-widget')); ?>
                    <div class="nias-row"><div class="nias-row-main" style="width:100%">
                        <div class="nias-row-desc" style="margin-bottom:12px"><?php echo esc_html__('دوره و دانشجو را پیدا کنید تا زمان باز شدن جلسات او را ببینید؛ می‌توانید همهٔ جلسات را برایش باز کنید یا شروع دوره‌اش را جابه‌جا کنید. مدرسین همین ابزار را برای دوره‌های خودشان در پنل مدرس دارند.', 'nias-course-widget'); ?></div>
                        <?php echo nias_drip_tool_html(0); // phpcs:ignore -- built with escaping ?>
                    </div></div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/></svg>', __('ماژول‌های بعدی', 'nias-course-widget')); ?>
                <div class="nias-row"><div class="nias-row-main">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach (nias_modules_upcoming() as $m) : ?>
                            <span class="nias-chip" style="background:#f1f5f9;color:#64748b;padding:5px 12px"><?php echo esc_html($m); ?> · <?php echo esc_html__('به‌زودی', 'nias-course-widget'); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div></div>
            <?php nias_set_card_close(); ?>
        </div></div>
    </div>
    <?php
}
