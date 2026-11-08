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
        'nias_assign_enabled'   => 'radio',
        'nias_stats_enabled'    => 'radio',
        'nias_notes_enabled'        => 'radio',
        'nias_paths_enabled'        => 'radio',
        'nias_transfer_enabled'     => 'radio',
        'nias_live_enabled'         => 'radio',
        'nias_gm_enabled'           => 'radio',
        'nias_gm_board'             => 'radio',
        'nias_gm_names'             => 'radio',
        'nias_rev_enabled'          => 'radio',
        'nias_rev_rate'             => 'text',
        'nias_rev_hold'             => 'number',
        'nias_rev_min'              => 'number',
        'nias_reviews_enabled'      => 'radio',
        'nias_reviews_min_progress' => 'number',
        'nias_reviews_moderate'     => 'radio',
        'nias_inactive_enabled' => 'radio',
        'nias_inactive_days'    => 'text',
        'nias_inactive_hours'   => 'text',
        'nias_inactive_email'   => 'radio',
        'nias_access_enabled'      => 'radio',
        'nias_access_default_days' => 'number',
        'nias_access_remind'       => 'text',
        'nias_access_email'        => 'radio',
        'nias_protect_enabled' => 'radio',
        'nias_protect_send'    => 'radio',
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

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="6" stroke="#3858e9" stroke-width="1.8"/><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('امتیاز و نشان', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_gm_enabled', __('فعال‌سازی امتیاز و نشان', 'nias-course-widget'), __('دانشجو برای تکمیل جلسه (+۱۰)، قبولی در آزمون (+۲۰)، قبولی تکلیف (+۳۰)، تمام کردن دوره (+۵۰)، نظر (+۵) و پرسش (+۲) امتیاز و نشان می‌گیرد (هر کار یک بار). زیر صفحهٔ دوره امتیاز، رتبه، نشان‌ها و جدول برترین‌های همان دوره نمایش داده می‌شود و هنگام گرفتن نشان جدید پیغام می‌آید. شورت‌کد [nias_my_badges] برای حساب کاربری. امتیازها از روز فعال‌سازی حساب می‌شوند.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                    <div data-nias-show-when="nias_gm_enabled=on">
                        <?php nias_set_toggle_row('nias_gm_board', __('جدول برترین‌ها', 'nias-course-widget'), __('۱۰ نفر اول هر دوره زیر صفحهٔ دوره.', 'nias-course-widget'), 'on'); ?>
                        <div class="nias-row"><div class="nias-field"><label class="nias-flabel"><?php echo esc_html__('نام دانشجوها در جدول', 'nias-course-widget'); ?></label><?php nias_set_segmented('nias_gm_names', array('short' => __('نام کوچک + حرف اول فامیلی', 'nias-course-widget'), 'full' => __('نام کامل', 'nias-course-widget'), 'hidden' => __('مخفی', 'nias-course-widget')), 'short'); ?></div></div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="15" height="14" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="m17 10 5-3v10l-5-3" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('کلاس آنلاین', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_live_enabled', __('فعال‌سازی کلاس آنلاین', 'nias-course-widget'), __('در ویرایشگر جلسه گزینهٔ «کلاس زنده» اضافه می‌شود: لینک کلاس (اسکای‌روم، BigBlueButton، گوگل‌میت و …)، زمان شروع و مدت. دانشجو تا قبل از کلاس شمارش معکوس و «افزودن به تقویم» می‌بیند؛ لینک ورود فقط از ۱۰ دقیقه قبل تا پایان کلاس و فقط به کسانی که به جلسه دسترسی دارند داده می‌شود. یک ساعت قبل پیامک یادآوری می‌رود. ویدیوی ضبط‌شده را بعداً در همان جلسه بگذارید تا جای کارت کلاس پخش شود.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="M2 10h20M6 15h4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('سهم درآمد مدرس', 'nias-course-widget')); ?>
                    <?php
                    $wc_ok2 = function_exists('nias_wc_active') && nias_wc_active();
                    nias_set_toggle_row('nias_rev_enabled', __('فعال‌سازی سهم درآمد مدرس', 'nias-course-widget'), $wc_ok2 ? __('با تکمیل هر سفارش، سهم مدرس (یا مدرسینِ) دوره در حسابش ثبت می‌شود و پس از دورهٔ امن قابل برداشت است؛ بازپرداخت یا لغو سفارش سهم را برمی‌گرداند. مدرس در پنل «درآمد» موجودی، فروش‌ها و درخواست تسویه را می‌بیند و مدیر در تب «درآمد مدرسین» درخواست‌ها را پرداخت یا رد می‌کند. درصد برای هر مدرس (صفحهٔ ویرایش کاربر) و هر دوره (صفحهٔ محصول) قابل تغییر است. فروش‌های قبل از فعال‌سازی حساب نمی‌شوند.', 'nias-course-widget') : __('این ماژول به ووکامرس نیاز دارد.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget'));
                    ?>
                    <div data-nias-show-when="nias_rev_enabled=on"><div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px">
                        <?php nias_set_number_field('nias_rev_rate', __('سهم پیش‌فرض مدرس (٪)', 'nias-course-widget'), __('از مبلغ فروش هر دوره (بعد از تخفیف).', 'nias-course-widget'), 70, 100); ?>
                        <?php nias_set_number_field('nias_rev_hold', __('دورهٔ امن (روز)', 'nias-course-widget'), __('سهم پس از این مدت قابل برداشت می‌شود.', 'nias-course-widget'), 7, 90); ?>
                        <?php nias_set_number_field('nias_rev_min', __('حداقل مبلغ تسویه', 'nias-course-widget'), __('به واحد پول فروشگاه.', 'nias-course-widget'), 500000); ?>
                    </div></div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="12" height="12" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="#3858e9" stroke-width="1.8"/></svg>', __('کپی و انتقال دوره', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_transfer_enabled', __('فعال‌سازی کپی و انتقال', 'nias-course-widget'), __('در فهرست دوره‌ها زیر نام هر دوره «کپی دوره» و «خروجی دوره» اضافه می‌شود. کپی: یک پیش‌نویس با همان محتوا، جلسات، تنظیمات و نسخهٔ جداگانه از آزمون‌ها (نتایج دو دوره قاطی نمی‌شود). خروجی: فایل JSON که در صفحهٔ «انتقال دوره» همین سایت یا سایت دیگر وارد می‌شود.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="6" r="2.5" stroke="#3858e9" stroke-width="1.8"/><circle cx="19" cy="18" r="2.5" stroke="#3858e9" stroke-width="1.8"/><path d="M7.5 6H15a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h7.5" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('پیش‌نیاز و مسیر یادگیری', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_paths_enabled', __('فعال‌سازی پیش‌نیاز و مسیر یادگیری', 'nias-course-widget'), __('در صفحهٔ ویرایش هر دوره جعبهٔ «پیش‌نیاز دوره» اضافه می‌شود: دوره‌هایی که باید اول تمام شوند (پیشرفت ۱۰۰٪ یا گرفتن مدرک) و این‌که قبل از آن خرید آزاد است و فقط جلسات قفل‌اند یا خرید هم ممکن نیست. منوی «مسیرهای یادگیری» هم اضافه می‌شود: چند دوره به ترتیب با پیشرفت کلی، قابل نمایش با شورت‌کد [nias_path id=""] و در صفحهٔ خود مسیر؛ گزینهٔ «ترتیبی» هر دوره را به دورهٔ قبلی وابسته می‌کند. مدیران و مدرسِ دوره هیچ‌وقت محدود نمی‌شوند.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 4h16v12H8l-4 4V4Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 9h8M8 12h5" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('یادداشت و نشانک زمان‌دار', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_notes_enabled', __('فعال‌سازی یادداشت‌ها', 'nias-course-widget'), __('در جلسه‌های ویدیویی تب «یادداشت‌ها» اضافه می‌شود: دانشجو در هر لحظه یادداشت می‌گذارد و با کلیک روی زمان یادداشت به همان لحظه برمی‌گردد. همهٔ یادداشت‌ها در «حساب کاربری › یادداشت‌های من» (یا شورت‌کد [nias_my_notes]) با جستجو، لینک به همان لحظه و دریافت فایل متنی دیده می‌شوند. یادداشت‌ها خصوصی‌اند.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('امتیاز و نظر خریداران', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_reviews_enabled', __('فعال‌سازی امتیاز و نظر', 'nias-course-widget'), __('زیر صفحهٔ دوره بخش «نظر دانشجویان» با میانگین امتیاز، توزیع ستاره‌ها و نظرها اضافه می‌شود. فقط دانشجوی خود دوره (بعد از درصد مشخصی پیشرفت) می‌تواند ۱ تا ۵ ستاره و نظر بدهد و هر نفر یک نظر دارد که می‌تواند ویرایش کند. مدرس و مدیر زیر هر نظر پاسخ می‌دهند. امتیاز روی کارت «دوره‌های من» و (برای محصول) در امتیاز و اسکیمای ووکامرس هم نمایش داده می‌شود.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                    <div data-nias-show-when="nias_reviews_enabled=on">
                        <div class="nias-row"><?php nias_set_number_field('nias_reviews_min_progress', __('حداقل پیشرفت برای ثبت نظر (٪)', 'nias-course-widget'), __('تا دانشجو این مقدار از دوره را ندیده باشد، نمی‌تواند امتیاز بدهد.', 'nias-course-widget'), 30, 100); ?></div>
                        <?php nias_set_toggle_row('nias_reviews_moderate', __('تأیید نظرها پیش از نمایش', 'nias-course-widget'), __('نظر جدید تا تأیید در «دیدگاه‌ها»ی وردپرس نمایش داده نمی‌شود.', 'nias-course-widget'), 'off'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('گزارش تحلیلی', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_stats_enabled', __('فعال‌سازی گزارش‌ها', 'nias-course-widget'), __('برای هر دوره: تعداد دانشجو، نرخ شروع و تکمیل، میانگین پیشرفت، نمودار ریزش جلسه‌به‌جلسه (بر اساس درصد واقعی تماشای ویدیو)، وضعیت آزمون‌ها و تکالیف، دانشجوهای عقب‌افتاده و فروش ۱۲ ماه. مدیر همهٔ دوره‌ها را در تب «گزارش‌ها» و مدرس دوره‌های خودش را در پنل مدرس می‌بیند. هر گزارش ۶ ساعت نگه داشته می‌شود تا سرور سنگین نشود.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v5h5M9 14l2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('تکلیف با بارگذاری فایل', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_assign_enabled', __('فعال‌سازی تکلیف', 'nias-course-widget'), __('در ویرایشگر جلسه (پیشخوان و پنل مدرس) گزینهٔ «تکلیف» اضافه می‌شود: شرح، پسوندهای مجاز، حداکثر حجم و نمرهٔ قبولی. دانشجو از تب «تکلیف» جلسه فایل می‌فرستد (در پوشهٔ محافظت‌شده) و مدرس از «تکالیف» نمره و بازخورد می‌دهد یا ارسال دوباره می‌خواهد. دانشجو با ایمیل و پیامک و مدرس با پیامک باخبر می‌شود.', 'nias-course-widget'), 'off', __('جدید', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('یادآوری به دانشجوی غیرفعال', 'nias-course-widget')); ?>
                    <?php
                    $in_on = function_exists('nias_inactive_enabled') && nias_inactive_enabled();
                    nias_set_toggle_row(
                        'nias_inactive_enabled',
                        __('فعال‌سازی یادآوری ادامهٔ دوره', 'nias-course-widget'),
                        __('اگر دانشجو چند روز به دوره سر نزند (نه دوره را باز کند، نه ویدیو ببیند، نه آزمون بدهد)، پیام «از جلسهٔ X ادامه بده» با لینک دوره برایش می‌رود؛ دانشجویی که دوره را خریده ولی شروع نکرده هم یادآوری می‌گیرد. با هر فعالیت دوباره از صفر حساب می‌شود. برای دوره‌های تمام‌شده، دسترسی منقضی، مدیران و مدرسین ارسال نمی‌شود و هر ایمیل لینک لغو دارد. دانشجوهای فعلی از روز فعال‌سازی حساب می‌شوند (موج پیام راه نمی‌افتد).', 'nias-course-widget') . ($in_on ? ' <b>' . esc_html(sprintf(__('ارسال‌شده در ۳۰ روز گذشته: %s', 'nias-course-widget'), nias_fa_digits(nias_inactive_sent_count(30)))) . '</b>' : ''),
                        'off',
                        __('جدید', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_inactive_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <div class="nias-field" style="max-width:300px"><label class="nias-flabel"><?php echo esc_html__('بعد از چند روز بی‌فعالیتی', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('مرحله‌ها با کاما؛ مثلاً 3,7,14 یعنی یک بار بعد از ۳ روز، یک بار بعد از ۷ و یک بار بعد از ۱۴ روز.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_inactive_days" value="<?php echo esc_attr((string) carbon_get_theme_option('nias_inactive_days') !== '' ? carbon_get_theme_option('nias_inactive_days') : '3,7,14'); ?>"></div>
                            <div class="nias-field" style="max-width:240px"><label class="nias-flabel"><?php echo esc_html__('ساعت مجاز ارسال', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('مثلاً 9-21؛ بیرون از این ساعت پیامی نمی‌رود.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_inactive_hours" value="<?php echo esc_attr((string) carbon_get_theme_option('nias_inactive_hours') !== '' ? carbon_get_theme_option('nias_inactive_hours') : '9-21'); ?>"></div>
                        </div>
                        <?php nias_set_toggle_row('nias_inactive_email', __('ارسال با ایمیل', 'nias-course-widget'), sprintf(__('ایمیل با دکمهٔ «ادامهٔ دوره» و لینک لغو. پیامک همین یادآوری در صفحهٔ <a href="%s">پیامک</a> (رویداد «یادآوری ادامهٔ دوره») تنظیم می‌شود.', 'nias-course-widget'), esc_url(admin_url('admin.php?page=nias-course-sms'))), 'on'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="13" r="8" stroke="#3858e9" stroke-width="1.8"/><path d="M12 9v4l3 2M9 2h6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('دسترسی زمان‌دار و تمدید', 'nias-course-widget')); ?>
                    <?php
                    $wc_ok = function_exists('nias_wc_active') && nias_wc_active();
                    nias_set_toggle_row(
                        'nias_access_enabled',
                        __('فعال‌سازی دسترسی زمان‌دار', 'nias-course-widget'),
                        $wc_ok
                            ? __('برای هر دوره (در صفحهٔ ویرایش محصول، جعبهٔ «دسترسی زمان‌دار») مدت دسترسی پس از خرید تعیین کنید؛ بعد از آن، جلسات خصوصی مثل کسی که دوره را نخریده بسته می‌شوند و دانشجو دکمهٔ «تمدید دسترسی» می‌بیند. تمدید با خرید دوبارهٔ دوره یا یک محصول تمدید جدا. دانشجوهایی که قبل از تعیین مدت خریده‌اند، از همان روز حساب می‌شوند. مدیران و مدرسِ دوره هیچ‌وقت محدود نمی‌شوند.', 'nias-course-widget')
                            : __('این ماژول برای فروش دوره است و به ووکامرس نیاز دارد.', 'nias-course-widget'),
                        'off',
                        __('جدید', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_access_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <?php nias_set_number_field('nias_access_default_days', __('مدت پیش‌فرض (روز)', 'nias-course-widget'), __('برای دوره‌هایی که مدت خودشان را ندارند. ۰ = نامحدود.', 'nias-course-widget'), 0, 3650); ?>
                            <div class="nias-field" style="max-width:300px"><label class="nias-flabel"><?php echo esc_html__('یادآوری قبل از پایان (روز)', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('با کاما جدا کنید؛ مثلاً 7,1. روز پایان هم اطلاع داده می‌شود.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_access_remind" value="<?php echo esc_attr(carbon_get_theme_option('nias_access_remind') !== '' && carbon_get_theme_option('nias_access_remind') !== null ? carbon_get_theme_option('nias_access_remind') : '7,1'); ?>"></div>
                        </div>
                        <?php nias_set_toggle_row('nias_access_email', __('ایمیل یادآوری و پایان دسترسی', 'nias-course-widget'), sprintf(__('ایمیل با دکمهٔ «تمدید دسترسی». پیامک همین رویدادها در صفحهٔ <a href="%s">پیامک</a> («یادآوری پایان دسترسی» و «پایان دسترسی») تنظیم می‌شود. مدیر از صفحهٔ ویرایش هر کاربر (بخش «دسترسی زمان‌دار دوره‌ها») می‌تواند دسترسی را تمدید، نامحدود یا تمام کند.', 'nias-course-widget'), esc_url(admin_url('admin.php?page=nias-course-sms'))), 'off'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('بستن دسترسی مستقیم به فایل‌ها', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_protect_enabled',
                        __('فعال‌سازی پوشهٔ محافظت‌شده', 'nias-course-widget'),
                        __('ویدیوها و فایل‌هایی که از ویرایشگر جلسات بارگذاری می‌شوند در پوشهٔ «nias-protected» ذخیره می‌شوند که با آدرس مستقیم باز نمی‌شود؛ فقط با لینک امضاشده (برای درس خصوصی: لینک اختصاصی همان کاربر، برای درس عمومی: لینک موقت). حتی اگر آدرس فایل لو برود، کار نمی‌کند. فایل‌های قبلی را با ابزار پایین منتقل کنید.', 'nias-course-widget'),
                        'off',
                        __('جدید', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_protect_enabled=on">
                        <div class="nias-row"><div class="nias-row-main" style="width:100%">
                            <?php echo nias_protect_settings_html(); // phpcs:ignore -- escaped inside ?>
                            <div class="nias-field" style="margin-top:10px"><label class="nias-flabel"><?php echo esc_html__('ارسال فایل', 'nias-course-widget'); ?></label>
                                <?php nias_set_segmented('nias_protect_send', array('php' => __('با PHP (همه‌جا کار می‌کند)', 'nias-course-widget'), 'xsendfile' => __('X-Sendfile (Apache)', 'nias-course-widget'), 'xaccel' => __('X-Accel-Redirect (Nginx)', 'nias-course-widget')), 'php'); ?>
                                <div class="nias-fdesc" style="margin-top:6px"><?php echo esc_html__('برای ویدیوهای حجیم و بازدید زیاد، ارسال توسط خود وب‌سرور سبک‌تر است؛ فقط وقتی ماژول mod_xsendfile روی Apache نصب است یا قانون internal بالا روی Nginx اضافه شده، آن را انتخاب کنید.', 'nias-course-widget'); ?></div>
                            </div>
                        </div></div>
                    </div>
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

            <?php if (function_exists('nias_protect_enabled') && nias_protect_enabled()) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10v5m0 0-2-2m2 2 2-2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('انتقال فایل‌های فعلی دوره‌ها به پوشهٔ محافظت‌شده', 'nias-course-widget')); ?>
                    <div class="nias-row"><div class="nias-row-main" style="width:100%"><?php echo nias_protect_tool_html(); // phpcs:ignore -- escaped inside ?></div></div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php if ($drip_on) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('برنامهٔ قطره‌ای یک دانشجو', 'nias-course-widget')); ?>
                    <div class="nias-row"><div class="nias-row-main" style="width:100%">
                        <div class="nias-row-desc" style="margin-bottom:12px"><?php echo esc_html__('دوره و دانشجو را پیدا کنید تا زمان باز شدن جلسات او را ببینید؛ می‌توانید همهٔ جلسات را برایش باز کنید یا شروع دوره‌اش را جابه‌جا کنید. مدرسین همین ابزار را برای دوره‌های خودشان در پنل مدرس دارند.', 'nias-course-widget'); ?></div>
                        <?php echo nias_drip_tool_html(0); // phpcs:ignore -- built with escaping ?>
                    </div></div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php if (nias_modules_upcoming()) : ?>
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/></svg>', __('ماژول‌های بعدی', 'nias-course-widget')); ?>
                <div class="nias-row"><div class="nias-row-main">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach (nias_modules_upcoming() as $m) : ?>
                            <span class="nias-chip" style="background:#f1f5f9;color:#64748b;padding:5px 12px"><?php echo esc_html($m); ?> · <?php echo esc_html__('به‌زودی', 'nias-course-widget'); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div></div>
            <?php nias_set_card_close(); ?>
            <?php endif; ?>
        </div></div>
    </div>
    <?php
}
