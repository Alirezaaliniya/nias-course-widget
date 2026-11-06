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
        'nias_dev_enabled'     => 'radio',
        'nias_dev_max'         => 'number',
        'nias_dev_mode'        => 'radio',
        'nias_dev_single'      => 'radio',
        'nias_dev_exempt_inst' => 'radio',
        'nias_dev_self'        => 'radio',
        'nias_dev_removals'    => 'number',
    );
}

add_action('admin_menu', function () {
    add_submenu_page('nias-course-settings', __('ماژول‌ها', 'nias-course-widget'), __('ماژول‌ها', 'nias-course-widget'), 'manage_options', 'nias-course-modules', 'nias_modules_page');
}, 30);

/** Modules planned for the next versions (shown as «به‌زودی»). */
function nias_modules_upcoming()
{
    return array(
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

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="6" y="2" width="12" height="20" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M11 18h2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('محدودیت دستگاه و ورود همزمان', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_dev_enabled',
                        __('فعال‌سازی محدودیت دستگاه', 'nias-course-widget'),
                        __('جلوی استفادهٔ چند نفر از یک حساب را می‌گیرد. هر مرورگر یک دستگاه حساب می‌شود و حساب فقط روی تعداد مشخصی دستگاه فعال می‌ماند. با همهٔ روش‌های ورود کار می‌کند (ورود پیامکی و گوگلِ نیاس لاگین، ووکامرس و فرم وردپرس). مدیران و مدیران فروشگاه هیچ‌وقت محدود نمی‌شوند.', 'nias-course-widget'),
                        'off',
                        __('جدید', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_dev_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <?php nias_set_number_field('nias_dev_max', __('حداکثر تعداد دستگاه', 'nias-course-widget'), __('برای هر کاربر (۱ تا ۲۰).', 'nias-course-widget'), 2, 20); ?>
                            <div class="nias-field"><label class="nias-flabel"><?php echo esc_html__('وقتی ظرفیت پر است و از دستگاه جدیدی وارد می‌شود', 'nias-course-widget'); ?></label>
                                <?php nias_set_segmented('nias_dev_mode', array('block' => __('ورود رد شود', 'nias-course-widget'), 'oldest' => __('قدیمی‌ترین دستگاه خارج شود', 'nias-course-widget')), 'block'); ?>
                                <div class="nias-fdesc" style="margin-top:6px"><?php echo esc_html__('«ورود رد شود»: کاربر پیام می‌بیند که باید از دستگاه‌های قبلی یکی را حذف کند. «قدیمی‌ترین خارج شود»: دستگاهی که مدت بیشتری استفاده نشده خودکار حذف می‌شود.', 'nias-course-widget'); ?></div>
                            </div>
                        </div>
                        <?php
                        nias_set_toggle_row('nias_dev_single', __('ورود همزمان مجاز نیست', 'nias-course-widget'), __('در هر لحظه فقط یک دستگاه فعال است: با فعالیت روی یک دستگاه، حساب روی دستگاه‌های دیگر خارج می‌شود (دستگاه‌ها در فهرست می‌مانند).', 'nias-course-widget'), 'off');
                        nias_set_toggle_row('nias_dev_exempt_inst', __('مدرسین محدود نشوند', 'nias-course-widget'), __('مدرس‌ها (که معمولاً از چند دستگاه کار می‌کنند) شامل محدودیت نمی‌شوند.', 'nias-course-widget'), 'on');
                        nias_set_toggle_row('nias_dev_self', __('حذف دستگاه توسط خود کاربر', 'nias-course-widget'), __('تب «دستگاه‌های من» در حساب کاربری (یا شورت‌کد [nias_my_devices]) که کاربر دستگاه‌هایش را می‌بیند و می‌تواند یکی را حذف کند. مدیر همیشه از صفحهٔ ویرایش کاربر (بخش «دستگاه‌های کاربر») می‌تواند دستگاه‌ها را ببیند و حذف کند.', 'nias-course-widget'), 'on');
                        ?>
                        <div class="nias-row" data-nias-show-when="nias_dev_self=on">
                            <?php nias_set_number_field('nias_dev_removals', __('حداکثر حذف در هر ۳۰ روز', 'nias-course-widget'), __('۰ = بدون محدودیت. محدود کردنش جلوی دست‌به‌دست کردن حساب با حذف و ورود پشت‌سرهم را می‌گیرد.', 'nias-course-widget'), 2, 50); ?>
                        </div>
                    </div>
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
