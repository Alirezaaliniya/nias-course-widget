<?php

/**
 * SpotPlayer License integration for Nias Course Widget.
 *
 * A self-contained, improved port of the standalone "اسپات پلیر" plugin.
 * Everything lives under the nias_spot_* prefix and the _nias_spot_* option /
 * meta namespace so it never collides with the original plugin. The whole
 * feature is gated behind the "nias_spotplayer_enabled" toggle which is set on
 * the main Nias settings page; when off, none of the runtime hooks register.
 *
 * Settings are stored as individual Carbon-compatible options (_nias_spot_<key>)
 * just like the rest of the plugin, instead of the original single-array option.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =========================================================================
 * Options helpers
 * ===================================================================== */

/** Whether the SpotPlayer License feature is turned on. */
function nias_spot_enabled()
{
    return get_option('_nias_spotplayer_enabled') === 'on';
}

/** Read a SpotPlayer setting (stored as _nias_spot_<key>). */
function nias_spot_opt($key, $default = '')
{
    $value = get_option('_nias_spot_' . $key, null);
    if ($value === null || $value === false || $value === '') {
        return $default;
    }
    return $value;
}

/** Detect the active shop platform: 1 = WooCommerce, 2 = EDD, 0 = none. */
function nias_spot_woo_or_edd()
{
    return function_exists('wc_get_orders') ? 1 : (function_exists('edd_get_payments') ? 2 : 0);
}

/** hex color -> rgba() string. */
function nias_spot_hex2rgba($h, $o = 1)
{
    if (!preg_match('/^#[0-9A-F]{6}$/i', $h)) {
        $h = '#6611DD';
    }
    $h = substr($h, 1);
    $rgb = array_map('hexdec', array($h[0] . $h[1], $h[2] . $h[3], $h[4] . $h[5]));
    return 'rgba(' . implode(',', $rgb) . ',' . min($o, 1) . ')';
}

/**
 * Default / stored license-building code.
 * NOTE: $order / $user / $payment variable names are intentional — user code
 * stored in this field relies on them, identical to the original plugin.
 */
function nias_spot_license_code()
{
    $code = nias_spot_opt('code');
    if ($code) {
        return $code;
    }
    $dgts = function_exists('digits_version') ? "\$user->get('digits_phone')" : null;
    return nias_spot_woo_or_edd() === 1
        ? "[\n\t'name' => \$order->get_formatted_billing_full_name(), \n\t'watermark' => ['texts' => [['text' => " . ($dgts ?: '$order->get_billing_phone()') . "]]]\n]"
        : "[\n\t'name' => \$payment->first_name . ' ' . \$payment->last_name, \n\t'watermark' => ['texts' => [['text' => " . ($dgts ?: '$payment->email') . "]]]\n]";
}

/* =========================================================================
 * Remote API (rewritten with the WordPress HTTP API)
 * ===================================================================== */

/**
 * Perform a request against the SpotPlayer panel.
 *
 * @param string     $url
 * @param array|null $data POST body (null => GET)
 * @return array
 * @throws Exception
 */
function nias_spot_request($url, $data = null)
{
    $args = array(
        'method'    => ($data !== null) ? 'POST' : 'GET',
        'timeout'   => 60,
        'sslverify' => false,
        'headers'   => array(
            'Content-Type' => 'application/json',
            '$Level'       => '-1',
            '$API'         => nias_spot_opt('api'),
            'X-WpSpot'     => 'nias-' . (defined('NIAS_COURSE_VERSION') ? NIAS_COURSE_VERSION : '1'),
        ),
    );
    if ($data !== null) {
        $args['body'] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $rep = json_decode(wp_remote_retrieve_body($response), true);
    if (is_array($rep) && ($ex = isset($rep['ex']) ? $rep['ex'] : null)) {
        throw new Exception($ex['msg']);
    }
    return is_array($rep) ? $rep : array();
}

/** @throws Exception */
function nias_spot_request_license_get($id)
{
    return nias_spot_request('https://panel.spotplayer.ir/license/edit/' . $id . '?d=1');
}

/** @throws Exception */
function nias_spot_request_license_put($j)
{
    if (empty($j['name'])) {
        throw new Exception('نام لایسنس خالی بود.', 999);
    }
    if (empty($j['watermark']['texts'][0]['text'])) {
        throw new Exception('واترمارک لایسنس خالی بود.', 999);
    }
    return nias_spot_request('https://panel.spotplayer.ir/license/edit/', array_merge($j, array(
        'test' => nias_spot_opt('test') === 'on' ? 1 : 0,
    )));
}

/* =========================================================================
 * Admin notices (queued)
 * ===================================================================== */

function nias_spot_admin_notice($notice = '', $type = 'error', $dismissible = true)
{
    $notices   = get_option('nias_spot_notices', array());
    $notices[] = array(
        'notice'      => $notice,
        'type'        => $type,
        'dismissible' => $dismissible ? 'is-dismissible' : '',
    );
    update_option('nias_spot_notices', $notices);
}

function nias_spot_admin_notices()
{
    $notices = get_option('nias_spot_notices', array());
    foreach ($notices as $n) {
        printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>', esc_attr($n['type']), esc_attr($n['dismissible']), wp_kses_post($n['notice']));
    }
    if (!empty($notices)) {
        delete_option('nias_spot_notices');
    }
}
add_action('admin_notices', 'nias_spot_admin_notices', 10);

/* =========================================================================
 * Settings page (rendered as the "لایسنس اسپات پلیر" tab)
 * ===================================================================== */

/** Persist the SpotPlayer settings with field-aware sanitization. */
function nias_spot_save_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Text-ish fields.
    update_option('_nias_spot_api', sanitize_text_field(wp_unslash($_POST['nias_spot_api'] ?? nias_spot_opt('api'))));
    update_option('_nias_spot_domain', sanitize_text_field(wp_unslash($_POST['nias_spot_domain'] ?? '')));

    $color = sanitize_hex_color(wp_unslash($_POST['nias_spot_color'] ?? ''));
    update_option('_nias_spot_color', $color ?: '#6611DD');

    // License code is raw PHP — keep it verbatim (admins only reach this).
    update_option('_nias_spot_code', trim((string) wp_unslash($_POST['nias_spot_code'] ?? '')));

    // On/off toggles.
    foreach (array('test', 'completed', 'web', 'webonly', 'download', 'wccrs', 'wcspc') as $flag) {
        update_option('_nias_spot_' . $flag, (($_POST['nias_spot_' . $flag] ?? 'off') === 'on') ? 'on' : 'off');
    }

    // "Skip old orders" stores the timestamp it was enabled at.
    if (($_POST['nias_spot_time'] ?? 'off') === 'on') {
        $existing = (int) nias_spot_opt('time', 0);
        update_option('_nias_spot_time', $existing ?: time());
    } else {
        update_option('_nias_spot_time', 0);
    }
}

function nias_spot_render_license_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = false;
    if (isset($_POST['nias_save_spot_settings']) && check_admin_referer('nias_spot_settings', 'nias_spot_nonce')) {
        nias_spot_save_settings();
        $saved = true;
    }

    $platform     = nias_spot_woo_or_edd();
    $current_user = wp_get_current_user();
    $is_admin     = in_array('administrator', (array) $current_user->roles, true);
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('spot'); ?>
        <div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>

            <?php if (!$platform) : ?>
                <div class="nias-alert">
                    <span class="nias-alert-bar"></span>
                    <div class="nias-alert-ic">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z" stroke="#ef4444" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="nias-alert-body">
                        <div class="nias-alert-title"><?php echo esc_html__('هیچ‌کدام از ووکامرس یا EDD فعال نیستند', 'nias-course-widget'); ?></div>
                        <div class="nias-alert-desc"><?php echo esc_html__('برای استفاده از لایسنس اسپات پلیر باید افزونه ووکامرس (یا EDD) فعال باشد.', 'nias-course-widget'); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('nias_spot_settings', 'nias_spot_nonce'); ?>

                <!-- Connection card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07L11.5 4.5M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07L12.5 19.5" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('اتصال به اسپات پلیر', 'nias-course-widget')); ?>
                    <div class="nias-card-pad nias-stack">
                        <?php if ($is_admin) : ?>
                            <div class="nias-field">
                                <label class="nias-flabel"><?php echo esc_html__('کلید API', 'nias-course-widget'); ?></label>
                                <input type="text" class="nias-input nias-sp-ltr" name="nias_spot_api" value="<?php echo esc_attr(nias_spot_opt('api')); ?>" pattern="^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$">
                                <div class="nias-fhint"><?php echo esc_html__('کلید API در داشبورد اسپات پلیر در دسترس است. توجه: تغییر کلمه عبور اسپات پلیر باعث تغییر کلید API می‌شود.', 'nias-course-widget'); ?></div>
                            </div>
                        <?php else : ?>
                            <input type="hidden" name="nias_spot_api" value="<?php echo esc_attr(nias_spot_opt('api')); ?>">
                        <?php endif; ?>

                        <div class="nias-field">
                            <label class="nias-flabel"><?php echo esc_html__('دامنه ریبرندینگ', 'nias-course-widget'); ?></label>
                            <input type="text" class="nias-input nias-sp-ltr" name="nias_spot_domain" value="<?php echo esc_attr(nias_spot_opt('domain')); ?>" pattern="^app[0-9]?(\.[a-z0-9\-]+){2,}$" placeholder="app.example.com">
                            <div class="nias-fhint"><?php echo esc_html__('تنها در صورتی که سرویس ریبرندینگ را فعال کرده‌اید، دامنه را به صورت app.example.com وارد نمایید.', 'nias-course-widget'); ?></div>
                        </div>

                        <div class="nias-field" style="max-width:200px">
                            <label class="nias-flabel"><?php echo esc_html__('رنگ اصلی', 'nias-course-widget'); ?></label>
                            <input type="color" class="nias-sp-color" name="nias_spot_color" value="<?php echo esc_attr(nias_spot_opt('color', '#6611DD')); ?>">
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- License code card -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('کد ساخت لایسنس', 'nias-course-widget')); ?>
                    <div class="nias-card-pad nias-stack">
                        <div class="nias-field">
                            <textarea class="nias-input nias-sp-code" name="nias_spot_code" rows="6" dir="ltr"><?php echo esc_textarea(nias_spot_license_code()); ?></textarea>
                            <div class="nias-fhint"><?php echo esc_html__('کدی که برای ساخت لایسنس استفاده می‌شود. برای بازگردانی مقدار پیش‌فرض این فیلد را خالی بگذارید و ذخیره کنید.', 'nias-course-widget'); ?></div>
                        </div>

                        <div class="nias-sp-preview">
                            <div class="nias-sp-preview-head"><?php echo esc_html__('خروجی کد برای آخرین سفارش ثبت‌شده:', 'nias-course-widget'); ?></div>
                            <div class="nias-sp-preview-body" dir="ltr">
                                <?php nias_spot_render_last_order_preview(); ?>
                            </div>
                        </div>

                        <?php nias_spot_render_code_help($platform); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <!-- License build settings -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="#3858e9" stroke-width="1.8"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.81 1.17V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 7 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 2.6 14H2.5a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4 8.6l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 6.6V6.5a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 8a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 21 13h.5a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="#3858e9" stroke-width="1.4"/></svg>', __('تنظیمات ساخت لایسنس', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_spot_test',
                        __('حالت تستی ایجاد لایسنس', 'nias-course-widget'),
                        __('با فعال بودن این گزینه پس از خریدها لایسنس تستی ساخته می‌شود و هر لایسنس تستی جدید جایگزین قبلی می‌شود. <b style="color:#b91c1c">حتماً پس از تست این گزینه را غیرفعال کنید.</b>', 'nias-course-widget'),
                        'off',
                        __('تست', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_spot_time',
                        __('عدم ایجاد لایسنس برای سفارش‌های قدیمی', 'nias-course-widget'),
                        __('با فعال کردن، برای سفارش‌هایی که پیش از فعال‌سازی این گزینه ثبت شده‌اند لایسنس ایجاد نمی‌شود.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_spot_completed',
                        __('ایجاد لایسنس پس از تکمیل دستی سفارش', 'nias-course-widget'),
                        __('با فعال کردن، سفارش پس از پرداخت در حالت «در حال انجام» می‌ماند و تا تایید نشدن لایسنس ساخته نمی‌شود؛ مناسب برای بررسی نام و واترمارک پیش از ساخت لایسنس.', 'nias-course-widget'),
                        'off'
                    );
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- Display settings -->
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke="#3858e9" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="#3858e9" stroke-width="1.8"/></svg>', __('تنظیمات نمایش', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_spot_web',
                        __('نمایش نسخه وب در سایت', 'nias-course-widget'),
                        __('در صورتی که نسخه وب برای لایسنس فعال باشد، پلیر تحت وب در سایت شما نمایش داده می‌شود.', 'nias-course-widget'),
                        'off'
                    );
                    nias_set_toggle_row(
                        'nias_spot_webonly',
                        __('فقط نمایش نسخه وب', 'nias-course-widget'),
                        __('فقط نسخه وب نمایش داده شده و نسخه‌های نیتیو و لیست دانلود نمایش داده نمی‌شوند.', 'nias-course-widget'),
                        'off',
                        '',
                        'data-nias-show-when="nias_spot_web=on"'
                    );
                    nias_set_toggle_row(
                        'nias_spot_download',
                        __('نمایش لیست دانلود', 'nias-course-widget'),
                        __('چون برنامه فایل‌ها را خودکار دانلود و نمایش می‌دهد، فعال کردن این گزینه پیشنهاد نمی‌شود و پشتیبانی کاربران در این حالت به عهده ناشر است.', 'nias-course-widget'),
                        'off',
                        '',
                        'data-nias-show-when="nias_spot_webonly=off"'
                    );
                    if ($platform === 1) {
                        nias_set_toggle_row(
                            'nias_spot_wccrs',
                            __('نمایش «لایسنس‌های من» در منوی کاربری ووکامرس', 'nias-course-widget'),
                            __('گزینه‌ای در منوی حساب کاربری که به صفحه شورت‌کد دوره‌ها لینک می‌شود.', 'nias-course-widget'),
                            'off'
                        );
                    }
                    if (class_exists('Studiare_Core')) {
                        nias_set_toggle_row(
                            'nias_spot_wcspc',
                            __('حذف لینک دوره‌های خریداری‌شده قالب استادیار از منوی کاربری', 'nias-course-widget'),
                            '',
                            'off'
                        );
                    }
                    ?>
                <?php nias_set_card_close(); ?>

                <!-- Shortcode card -->
                <div class="nias-shortcodes">
                    <div class="nias-sc-head">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php echo esc_html__('شورت‌کد', 'nias-course-widget'); ?></span>
                    </div>
                    <div class="nias-sc-sub"><?php echo esc_html__('این شورت‌کد کل دوره‌های لایسنس‌دار کاربر را با امکان مشاهده آنلاین و دریافت لایسنس نمایش می‌دهد.', 'nias-course-widget'); ?></div>
                    <div class="nias-sc-list">
                        <div class="nias-sc-item"><code dir="ltr">[nias_spotplayer_courses]</code><span><?php echo esc_html__('نمایش دوره‌های کاربر', 'nias-course-widget'); ?></span></div>
                    </div>
                </div>

                <?php nias_set_save_button('nias_save_spot_settings'); ?>
            </form>

            <div class="nias-foot"><?php echo wp_kses_post(__('قدرت گرفته از <span style="color:#3858e9;font-weight:600">اسپات پلیر</span> — یکپارچه با دوره ساز نیاس.', 'nias-course-widget')); ?></div>
        </div>
    </div>
    <?php
}

/** Render the live preview of the eval'd code against the most recent order. */
function nias_spot_render_last_order_preview()
{
    try {
        $platform = nias_spot_woo_or_edd();
        if ($platform === 1) {
            $orders = wc_get_orders(array('limit' => 1));
            $j      = !empty($orders) ? nias_spot_woo_license_data_eval($orders[0]) : null;
        } elseif ($platform === 2) {
            $payments = edd_get_payments(array('number' => 1));
            $j        = !empty($payments) ? nias_spot_edd_license_data_eval($payments[0]) : null;
        } else {
            $j = null;
        }

        if (!$j) {
            echo '<div class="nias-sp-preview-warn">' . esc_html__('هیچ سفارش فعالی وجود ندارد. برای تست یک سفارش ایجاد کنید.', 'nias-course-widget') . '</div>';
            return;
        }

        echo '<pre>' . esc_html(json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';

        if (empty($j['name']) || empty($j['watermark']['texts'][0]['text'])) {
            $id  = $platform === 1 ? wc_get_orders(array('limit' => 1))[0]->get_id() : edd_get_payments(array('number' => 1))[0]->ID;
            $dbg = '<div class="nias-sp-preview-warn" dir="rtl"><a target="_blank" href="' . esc_url(parse_url(get_home_url(), PHP_URL_PATH) . '/niasspdeb?id=' . $id) . '">' . esc_html__('اطلاعات دیباگ', 'nias-course-widget') . '</a></div>';
            if (empty($j['name'])) {
                echo '<div class="nias-sp-preview-warn" dir="rtl">' . esc_html__('مقدار نام خالی است. از فیلد دیگری برای تعیین نام استفاده کنید.', 'nias-course-widget') . '</div>' . $dbg;
            }
            if (empty($j['watermark']['texts'][0]['text'])) {
                echo '<div class="nias-sp-preview-warn" dir="rtl">' . esc_html__('مقدار اولین واترمارک خالی است. از فیلد دیگری استفاده کنید.', 'nias-course-widget') . '</div>' . $dbg;
            }
        }
    } catch (Error $e) {
        echo '<div class="nias-sp-preview-warn">' . esc_html($e->getMessage()) . '</div>';
        echo '<div class="nias-sp-preview-warn" dir="rtl">' . esc_html__('سینتکس کد وارد شده را بررسی و اصلاح کرده و تنظیمات را ذخیره نمایید.', 'nias-course-widget') . '</div>';
    }
}

/** Help block listing the variables available inside the license code. */
function nias_spot_render_code_help($platform)
{
    ?>
    <div class="nias-sp-help">
        <?php if ($platform === 1) : ?>
            <div><?php echo esc_html__('متغیر $order ووکامرس شامل اطلاعات سفارش است (متدهای پیش‌فرض و get_meta برای متادیتا).', 'nias-course-widget'); ?></div>
            <ul dir="ltr">
                <li><b>$order</b> — <a target="_blank" href="https://woocommerce.github.io/code-reference/classes/WC-Order.html"><small>WC_Order</small></a></li>
                <li>$order-&gt;get_formatted_billing_full_name()</li>
                <li>$order-&gt;get_billing_phone()</li>
                <li>$order-&gt;get_billing_email()</li>
                <li>$order-&gt;get_meta("_meta_key")</li>
            </ul>
        <?php elseif ($platform === 2) : ?>
            <ul dir="ltr">
                <li><b>$payment</b> — <a target="_blank" href="https://docs.easydigitaldownloads.com/article/1113-eddpayment"><small>EDD_Payment</small></a></li>
                <li>$payment-&gt;first_name</li>
                <li>$payment-&gt;last_name</li>
                <li>$payment-&gt;email</li>
            </ul>
        <?php endif; ?>
        <?php if ($platform) : ?>
            <div><?php echo esc_html__('متغیر $user وردپرس شامل اطلاعات خریدار است (متد get و فیلدهای پیش‌فرض).', 'nias-course-widget'); ?></div>
            <ul dir="ltr">
                <li><b>$user</b> — <a target="_blank" href="https://developer.wordpress.org/reference/classes/wp_user/"><small>WP_User</small></a></li>
                <li>$user-&gt;user_login</li>
                <li>$user-&gt;user_firstname</li>
                <li>$user-&gt;user_lastname</li>
                <li>$user-&gt;user_email</li>
                <li>$user-&gt;get('digits_phone')</li>
            </ul>
            <div><b style="color:#b91c1c"><?php echo esc_html__('برای ردگیری واترمارک‌ها حتماً از سیستم تایید شماره (مثلاً دیجیتس) هنگام ثبت‌نام استفاده کنید.', 'nias-course-widget'); ?></b></div>
        <?php endif; ?>
    </div>
    <?php
}

/* =========================================================================
 * Shared admin order/payment box
 * ===================================================================== */

function nias_spot_admin_order_box_ui($data)
{
    $texts   = isset($data['watermark']['texts']) ? $data['watermark']['texts'] : array();
    $disable = !empty($data['_id']) ? 'disabled readonly' : '';
    ?>
    <table class="widefat" style="border:none">
        <tr>
            <td><?php echo esc_html__('شناسه:', 'nias-course-widget'); ?></td>
            <td>
                <input type="text" class="ltr" name="nias-spot-id" value="<?php echo esc_attr($data['_id'] ?? ''); ?>" <?php echo $disable; ?>/>
                <?php if (!$disable) : ?>
                    <button type="submit" name="nias-spot-retrieve" value="1"><?php echo esc_html__('دریافت اطلاعات لایسنس با شناسه', 'nias-course-widget'); ?></button>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><?php echo esc_html__('نام:', 'nias-course-widget'); ?></td>
            <td><input type="text" name="nias-spot-name" value="<?php echo esc_attr($data['name'] ?? ''); ?>" <?php echo $disable; ?>/></td>
        </tr>
        <?php for ($i = 0; $i < 3; $i++) : ?>
            <tr>
                <td><?php echo esc_html(sprintf(__('واترمارک %d:', 'nias-course-widget'), $i + 1)); ?></td>
                <td><input type="text" class="ltr" name="nias-spot-text[<?php echo $i; ?>]" value="<?php echo esc_attr($texts[$i]['text'] ?? ''); ?>" <?php echo $disable; ?>/></td>
            </tr>
        <?php endfor; ?>
        <tr>
            <td></td>
            <td>
                <?php if ($disable) : ?>
                    <button class="remove" type="submit" name="nias-spot-remove" value="1"><?php echo esc_html__('حذف اطلاعات لایسنس از وردپرس', 'nias-course-widget'); ?></button>
                <?php else : ?>
                    <button type="submit" name="nias-spot-create" value="1"><?php echo esc_html__('ایجاد لایسنس', 'nias-course-widget'); ?></button>
                    <button class="remove" type="submit" name="nias-spot-remove" value="1"><?php echo esc_html__('ریست اطلاعات', 'nias-course-widget'); ?></button>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

/* =========================================================================
 * WooCommerce — product / variation course IDs
 * ===================================================================== */

/**
 * Validate and store a course-ID string on a product/variation post.
 * Shared by the Nias metabox (simple products) and the variation field.
 *
 * @return bool whether a valid course string was stored.
 */
function nias_spot_store_course_meta($post_id, $course)
{
    $course = is_string($course) ? trim($course) : '';
    if (preg_match('/^[0-9a-f]{24}(,[0-9a-f]{24})*$/i', $course)) {
        update_post_meta($post_id, '_nias_spot_course', $course);
        // SpotPlayer courses are virtual & limited to one per order.
        update_post_meta($post_id, '_virtual', 'yes');
        update_post_meta($post_id, '_sold_individually', 'yes');
        return true;
    }
    update_post_meta($post_id, '_nias_spot_course', '');
    return false;
}

function nias_spot_woo_admin_variation_panel($i, $data)
{
    ?>
    <div id="nias-spotplayer-product"><?php woocommerce_wp_textarea_input(array(
        'id'            => "nias_spot_course$i",
        'name'          => "nias_spot_course[$i]",
        'value'         => $data['_nias_spot_course'][0] ?? '',
        'label'         => 'شناسه‌های دوره اسپات پلیر',
        'wrapper_class' => 'form-row form-row-full',
        'class'         => 'ltr',
        'desc_tip'      => true,
        'description'   => 'شناسه دوره‌های مد نظر را از پنل اسپات پلیر کپی و با جداکننده , وارد کنید.',
    )); ?></div>
    <?php
}

function nias_spot_woo_admin_variation_update(WC_Product_Variation $variation, $i)
{
    nias_spot_woo_admin_product_save($variation, $_POST['nias_spot_course'][$i] ?? '');
}

function nias_spot_woo_admin_product_save($product, $course)
{
    if (!current_user_can('administrator')) {
        return;
    }
    $course = is_string($course) ? trim($course) : '';
    if (preg_match('/^[0-9a-f]{24}(,[0-9a-f]{24})*$/i', $course)) {
        $product->update_meta_data('_nias_spot_course', $course);
        $product->set_virtual(true);
        $product->set_sold_individually(true);
    } else {
        $product->update_meta_data('_nias_spot_course', '');
    }
}

/* =========================================================================
 * WooCommerce — order license metabox
 * ===================================================================== */

function nias_spot_woo_admin_order()
{
    if (function_exists('wc_get_order') && count(nias_spot_woo_order_items(wc_get_order() ?: null))) {
        add_meta_box('nias-spot-order', 'اسپات پلیر', 'nias_spot_woo_admin_order_box', null, 'normal', 'high');
    }
}

function nias_spot_woo_admin_order_box()
{
    echo '<div id="nias-sp-order">';
    nias_spot_admin_order_box_ui(nias_spot_woo_license_data(wc_get_order()));
    echo '</div>';
}

function nias_spot_woo_admin_order_save($oid)
{
    if (!current_user_can('administrator')) {
        return;
    }
    $ord = wc_get_order($oid);
    if (!$ord || !count(nias_spot_woo_order_items($ord))) {
        return;
    }

    if (!empty($_POST['nias-spot-remove'])) {
        $ord->delete_meta_data('_nias_spot_data');
        $ord->save_meta_data();
        $ord->add_order_note('اطلاعات لایسنس اسپات پلیر حذف شد.');
        return;
    }

    $data = nias_spot_woo_license_data($ord);
    if (!empty($data['_id'])) {
        return;
    }

    if (!empty($_POST['nias-spot-retrieve'])) {
        if (!preg_match('/^[0-9a-f]{24}$/i', $id = $_POST['nias-spot-id'])) {
            nias_spot_admin_notice('شناسه لایسنس اسپات پلیر باید یک رشته هگز ۲۴ کاراکتری باشد.', 'warning');
            return;
        }
        try {
            $rep = nias_spot_request_license_get($id);
            if (!($id = $rep['_id'] ?? null)) {
                throw new Exception('909');
            }
            $ord->update_meta_data('_nias_spot_data', $rep);
            $ord->save_meta_data();
            $note = sprintf('اطلاعات لایسنس %s دریافت شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>');
            $ord->add_order_note($note);
            nias_spot_admin_notice($note . ' <a href="' . get_edit_post_link($ord->get_id()) . '">سفارش ' . $ord->get_id() . '</a>', 'info');
        } catch (Exception $ex) {
            nias_spot_admin_notice('هنگام دریافت لایسنس ' . $ex->getMessage());
        }
    } elseif (!empty($_POST['nias-spot-create'])) {
        if (($n = $_POST['nias-spot-name'] ?? '') && ($t = $_POST['nias-spot-text'] ?? '')) {
            try {
                $ord->update_meta_data('_nias_spot_data', array_merge($data, array(
                    'name'      => sanitize_text_field($n),
                    'watermark' => array('texts' => array_values(array_filter(
                        array(array('text' => $t[0] ?? ''), array('text' => $t[1] ?? ''), array('text' => $t[2] ?? '')),
                        function ($e) {
                            return strlen($e['text']) > 3;
                        }
                    ))),
                )));
                $ord->save_meta_data();
                nias_spot_woo_order_license_request($ord, true);
            } catch (Exception $ex) {
            }
        } else {
            nias_spot_admin_notice('نام و متن واترمارک اول وارد نشده بود.', 'warning');
        }
    }
}

/* =========================================================================
 * EDD — download / payment
 * ===================================================================== */

function nias_spot_edd_admin_dl($dl_id)
{
    ?>
    <div id="nias-spot-course">
        <label for="nias_spot_course"><?php echo esc_html__('شناسه دوره‌های اسپات پلیر', 'nias-course-widget'); ?></label>
        <textarea id="nias_spot_course" name="nias_spot_course"><?php echo esc_textarea(implode(',', get_post_meta($dl_id, '_nias_spot_course', true) ?: array())); ?></textarea>
        <div><?php echo esc_html__('شناسه یک یا چند دوره که با , از هم جدا شده‌اند.', 'nias-course-widget'); ?></div>
    </div>
    <?php
}

function nias_spot_edd_admin_dl_save($dl_id)
{
    update_post_meta($dl_id, '_nias_spot_course', array_filter(explode(',', $_POST['nias_spot_course'] ?? ''), function ($id) {
        return preg_match('/^[0-9a-f]{24}$/i', $id);
    }));
}

function nias_spot_edd_admin_payment_box($pid)
{
    ?>
    <div id="nias-sp-order" class="postbox">
        <h3 class="hndle"><span><?php echo esc_html__('اطلاعات اسپات پلیر', 'nias-course-widget'); ?></span></h3>
        <div class="inside edd-clearfix"><?php nias_spot_admin_order_box_ui(nias_spot_edd_license_data(edd_get_payment($pid))); ?></div>
    </div>
    <?php
}

function nias_spot_edd_admin_payment_save($pid)
{
    if (!current_user_can('administrator')) {
        return;
    }
    $pay = edd_get_payment($pid);
    if (!$pay || !count(nias_spot_edd_payment_items($pay))) {
        return;
    }
    if (!empty($_POST['nias-spot-remove'])) {
        $pay->delete_meta('_nias_spot_data');
        edd_insert_payment_note($pay->ID, 'اطلاعات لایسنس اسپات پلیر حذف شد.');
        return;
    }
    $data = nias_spot_edd_license_data($pay);
    if (!empty($data['_id'])) {
        return;
    }

    if (!empty($_POST['nias-spot-retrieve'])) {
        if (!preg_match('/^[0-9a-f]{24}$/i', $id = $_POST['nias-spot-id'])) {
            nias_spot_admin_notice('شناسه لایسنس اسپات پلیر باید یک رشته هگز ۲۴ کاراکتری باشد.', 'warning');
            return;
        }
        try {
            $rep = nias_spot_request_license_get($id);
            if (!($id = $rep['_id'] ?? null)) {
                throw new Exception('909');
            }
            $pay->update_meta('_nias_spot_data', $rep);
            $note = sprintf('اطلاعات لایسنس %s دریافت شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>');
            edd_insert_payment_note($pay->ID, $note);
            nias_spot_admin_notice($note . ' <a href="' . get_edit_post_link($pay->ID) . '">سفارش ' . $pay->ID . '</a>', 'info');
        } catch (Exception $ex) {
            nias_spot_admin_notice('هنگام دریافت لایسنس خطای ' . $ex->getMessage() . ' روی داد.');
        }
    } elseif (!empty($_POST['nias-spot-create'])) {
        if (($n = $_POST['nias-spot-name'] ?? '') && ($t = $_POST['nias-spot-text'] ?? '')) {
            try {
                $pay->update_meta('_nias_spot_data', array_merge($data, array(
                    'name'      => sanitize_text_field($n),
                    'watermark' => array('texts' => array_values(array_filter(
                        array(array('text' => $t[0] ?? ''), array('text' => $t[1] ?? ''), array('text' => $t[2] ?? '')),
                        function ($e) {
                            return strlen($e['text']) > 3;
                        }
                    ))),
                )));
                nias_spot_edd_payment_license_request($pay, true);
            } catch (Exception $ex) {
            }
        } else {
            nias_spot_admin_notice('نام و متن واترمارک اول وارد نشده بود.', 'warning');
        }
    }
}

/* =========================================================================
 * WooCommerce shop (frontend)
 * ===================================================================== */

function nias_spot_woo_shop_order(WC_Order $ord)
{
    if ($ord->get_customer_id() !== get_current_user_id()) {
        return;
    }
    if (!in_array($status = $ord->get_status(), array('processing', 'completed', 'partial-payment'), true)) {
        return;
    }
    if (!count(nias_spot_woo_order_items($ord))) {
        return;
    }

    $completed = ($status === 'completed');
    if (nias_spot_opt('completed') === 'on' && !$completed) {
        return;
    }

    try {
        nias_spot_shop_success(nias_spot_woo_order_license_request($ord));

        if ($completed) {
            return;
        }
        foreach ($ord->get_items() as $item) {
            if (($item instanceof WC_Order_Item_Product) &&
                (($product = $item->get_product()) instanceof WC_Product) &&
                !($product->is_downloadable() || $product->get_meta('_nias_spot_course'))) {
                return;
            }
        }
        $ord->update_status('completed');
    } catch (Exception $ex) {
        nias_spot_shop_failed($ex->getMessage());
    }
}

/**
 * SpotPlayer courses shortcode — courses purchased by the current user.
 */
function nias_spot_woo_shortcode()
{
    $uid = get_current_user_id();
    if (!$uid) {
        return '<script type="application/javascript">window.location.href = "' . esc_url(get_home_url()) . '"</script>';
    }

    if (isset($_GET['spo'])) {
        $ord = wc_get_order($_GET['spo']);
        if (!$ord || $ord->get_customer_id() !== $uid) {
            return '<script type="application/javascript">window.location.href = "' . esc_url(get_home_url()) . '"</script>';
        }
    }

    ob_start();

    if (isset($ord)) {
        $spot_data = $ord->get_meta('_nias_spot_data');
        $product   = wc_get_product($_GET['spp']);
        nias_spot_shop_success($spot_data, $product ? $product->get_name() : '', $_GET['spc'] ?? null);
    } else {
        ?>
        <div id="nias-sp_courses">
            <?php
            foreach (wc_get_orders(array('customer' => $uid)) as $ord) {
                $spot_data = $ord->get_meta('_nias_spot_data');
                if ($spot_data && is_array($spot_data) && isset($spot_data['_id'])) {
                    foreach (nias_spot_woo_order_items($ord, true) as $p) {
                        ?>
                        <a href="<?php echo esc_url("?spo={$ord->get_id()}&spp={$p->get_id()}&spc={$p->get_meta('_nias_spot_course')}"); ?>">
                            <?php echo $p->get_image(); ?>
                            <h2><?php echo esc_html($p->get_name()); ?></h2>
                        </a>
                        <?php
                    }
                }
            }
            ?>
        </div>
        <?php
    }

    return ob_get_clean();
}

function nias_spot_woo_shop_my_menu($links)
{
    if (class_exists('Studiare_Core') && nias_spot_opt('wcspc') === 'on') {
        unset($links['purchased-products']);
    }
    if (nias_spot_opt('wccrs') !== 'on') {
        return $links;
    }
    return array_slice($links, 0, 1, true) + array('nias-licenses' => 'لایسنس‌های من') + array_slice($links, 1, null, true);
}

function nias_spot_woo_shop_my_licenses_init()
{
    add_rewrite_endpoint('nias-licenses', EP_PAGES);
    // Flush rewrite rules only once after enabling, not on every load.
    if (get_option('nias_spot_flush_rewrite') !== 'done') {
        flush_rewrite_rules();
        update_option('nias_spot_flush_rewrite', 'done');
    }
}

function nias_spot_woo_shop_my_licenses_content()
{
    echo nias_spot_shortcode();
}

/* =========================================================================
 * EDD shop (frontend)
 * ===================================================================== */

function nias_spot_edd_shop_order(EDD_Payment $pay)
{
    if (intval(edd_get_payment_user_id($pay->ID)) !== get_current_user_id()) {
        return;
    }
    if (edd_get_payment_status($pay) !== 'complete') {
        return;
    }
    try {
        nias_spot_shop_success(nias_spot_edd_payment_license_request($pay));
    } catch (Exception $ex) {
        nias_spot_shop_failed($ex->getMessage());
    }
}

function nias_spot_edd_shortcode()
{
    if (!($uid = get_current_user_id()) || (($o = $_GET['spo'] ?? null) && (intval(edd_get_payment_customer_id($o)) !== $uid))) {
        return '<script type="application/javascript">window.location.href = "' . esc_url(get_home_url()) . '"</script>';
    }

    ob_start();
    if ($o) {
        nias_spot_shop_success(edd_get_payment($o)->get_meta('_nias_spot_data'), get_the_title($o), $_GET['spc'] ?? null);
    } else {
        ?>
        <div id="nias-sp_courses">
            <?php foreach (edd_get_payments(array('user' => $uid, 'output' => 'payments')) as $pay) :
                if (!empty($pay->get_meta('_nias_spot_data')['_id'])) :
                    foreach (nias_spot_edd_payment_items($pay, true) as $d) : ?>
                        <a href="<?php echo esc_url("?spo=$pay->ID&spp={$d['id']}&spc={$d['course']}"); ?>">
                            <?php echo get_the_post_thumbnail($d['id']); ?>
                            <h2><?php echo esc_html($d['name']); ?></h2>
                        </a>
                    <?php endforeach;
                endif;
            endforeach; ?>
        </div>
        <?php
    }
    return ob_get_clean();
}

/* =========================================================================
 * Shared shop output
 * ===================================================================== */

function nias_spot_shortcode()
{
    $p = nias_spot_woo_or_edd();
    return $p === 1 ? nias_spot_woo_shortcode() : ($p === 2 ? nias_spot_edd_shortcode() : 'ووکامرس یا EDD نصب نشده است.');
}

function nias_spot_shop_failed($err)
{
    ?>
    <div id="nias-sp_fail">
        <p><?php echo esc_html($err); ?></p>
        <button onclick="window.location.reload();"><?php echo esc_html__('تلاش مجدد', 'nias-course-widget'); ?></button>
    </div>
    <?php
}

function nias_spot_shop_success($data, $product = '', $course = null)
{
    if (!$data) {
        return;
    }

    $domain   = nias_spot_opt('domain') ?: 'app.spotplayer.ir';
    $icon_url = plugin_dir_url(__FILE__) . 'arow.svg';
    ?>
    <script type="application/javascript">
        function niasSpotCopy(txt, lbl) {
            try {
                navigator.clipboard.writeText(txt).catch(function () { niasSpotCopyLegacy(txt); });
            } catch (e) { niasSpotCopyLegacy(txt); }
            finally { alert(lbl + ' به کلیپ بورد کپی شد.'); }
        }
        function niasSpotCopyLegacy(txt) {
            const el = document.createElement('textarea');
            el.value = txt; el.style.position = 'absolute'; el.style.opacity = '0';
            document.body.appendChild(el); el.select(); document.execCommand('copy'); document.body.removeChild(el);
        }
        function niasSpotToggle(el) { el.className = el.className === 'active' ? '' : 'active'; }
        let spotplayer_players;
        let spotplayer_courses;
    </script>
    <div id="nias-sp">
        <?php if ($product) : ?><h1><?php echo esc_html($product); ?></h1><?php endif; ?>
        <div id="nias-sp-warn"><?php echo esc_html__('مطالب این دوره دارای واترمارک‌های پیدا و پنهان هستند و هرگونه کپی‌برداری و نشر آن قابل پیگیری بوده و موجب پیگرد قانونی خواهد شد.', 'nias-course-widget'); ?></div>

        <?php if (nias_spot_opt('web') === 'on') : ?>
            <div id="nias-sp-web">
                <h2><?php echo esc_html__('مشاهده در پلیر وب', 'nias-course-widget'); ?></h2>
                <p><?php echo esc_html__('پس از فعال کردن لایسنس در این مرورگر، فقط در همین دستگاه و مرورگر می‌توانید دوره را مشاهده کنید و یک دستگاه از ظرفیت لایسنس کم خواهد شد.', 'nias-course-widget'); ?></p>
                <div id="spotplayer"></div>
                <script src="https://<?php echo esc_attr($domain); ?>/assets/js/app-api.js"></script>
                <script type="application/javascript">
                    (async function () {
                        (new SpotPlayer(document.getElementById('spotplayer'), '<?php echo esc_js(parse_url(get_home_url(), PHP_URL_PATH)); ?>/niasspotx'))
                            .Open('<?php echo esc_js($data['key']); ?>', <?php echo preg_match('/^[0-9a-f]{24}$/i', (string) $course) ? "'" . esc_js($course) . "'" : 'null'; ?>);
                    })();
                </script>
            </div>
        <?php endif; ?>

        <?php if (nias_spot_opt('webonly') !== 'on') : ?>
            <div id="nias-sp-app">
                <h2><?php echo esc_html__('مشاهده در اپلیکیشن', 'nias-course-widget'); ?></h2>
                <p><?php echo esc_html__('ابتدا پلیر را با توجه به سیستم‌عامل خود دانلود و نصب کنید. سپس در صفحه ثبت دوره جدید کلید لایسنس را وارد، مکان ذخیره‌سازی را انتخاب و فرم را تایید کنید.', 'nias-course-widget'); ?></p>

                <div id="nias-sp_players">
                    <h3><b>❶</b> <?php echo esc_html__('دانلود و نصب پلیر', 'nias-course-widget'); ?></h3>
                    <div>
                        <script src="https://<?php echo esc_attr($domain); ?>/player/?f=js&l=<?php echo esc_attr($data['_id']); ?>"></script>
                        <script type="application/javascript">
                            document.write(window.spotplayer_players.map(function (p) {
                                return [
                                    '<a target="_blank" ' + (p.file ? ('href="https://<?php echo esc_js($domain); ?>' + p.file + '"') : '') + ' class="' + (p.disable ? 'disable' : '') + '">',
                                    ' <img alt="' + p.name + '" src="https://<?php echo esc_js($domain); ?>' + p.image + '">',
                                    ' <b>' + p.name + '</b>',
                                    ' <u>' + (p.file ? p.version : 'به زودی') + '</u>',
                                    '</a>'
                                ].join('');
                            }).join(''));
                        </script>
                    </div>
                </div>

                <div id="nias-sp_license">
                    <h3><b>❷</b> <?php echo esc_html__('کپی و وارد نمودن کلید در پلیر', 'nias-course-widget'); ?></h3>
                    <textarea readonly><?php echo esc_textarea($data['key']); ?></textarea>
                    <button class="nias-sp_color_back" onclick="niasSpotCopy('<?php echo esc_js($data['key']); ?>', 'کلید لایسنس')"><?php echo esc_html__('کپی کلید', 'nias-course-widget'); ?></button>
                </div>

                <?php if (nias_spot_opt('download') === 'on') :
                    $burl = 'https://' . $domain . '/' . $data['_id'] . '/' . md5(hex2bin(substr($data['key'], 24, 64))) . '/'; ?>
                    <div id="nias-sp_videos">
                        <h3><b>❸</b> <?php echo esc_html__('دانلود ویدیوها', 'nias-course-widget'); ?></h3>
                        <p><?php echo esc_html__('اگرچه پلیر فایل‌های دوره را خودکار دانلود و نمایش می‌دهد، می‌توانید فایل‌ها را از لینک‌های زیر به‌صورت مجزا دانلود کنید.', 'nias-course-widget'); ?></p>
                        <ul>
                            <script src="<?php echo esc_url($burl); ?>?f=js"></script>
                            <script type="application/javascript">
                                document.write(window.spotplayer_courses.map(function (c) {
                                    return [
                                        '<li><h4 onclick="niasSpotToggle(this.parentNode)">',
                                        '<img src="<?php echo esc_js($icon_url); ?>">' + c.name,
                                        '</h4><ul>',
                                        c.items.map(function (v) {
                                            return [
                                                '<li class="nias-sp_' + v.type + '"><a href="<?php echo esc_js($burl); ?>' + c._id + '/' + v._id + '.spot">',
                                                '<img src="<?php echo esc_js($icon_url); ?>" />' + v.name,
                                                '</a></li>'].join('');
                                        }).join(''),
                                        '</ul></li>'
                                    ].join('');
                                }).join(''));
                            </script>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* =========================================================================
 * Web player cookie route + debug route
 * ===================================================================== */

function nias_spot_url_handler()
{
    $p = str_replace(parse_url(get_home_url(), PHP_URL_PATH), '', $_SERVER['REQUEST_URI']);
    $s = substr($p, 0, 9);
    if ($s === '/niasspot') {
        nias_spot_shop_x();
    }
    if (substr($p, 0, 9) === '/niasspde') {
        nias_spot_debug();
    }
}

function nias_spot_shop_x()
{
    if (substr($_SERVER['REQUEST_URI'], 0, strlen(parse_url(get_home_url(), PHP_URL_PATH) . '/niasspotx')) !== parse_url(get_home_url(), PHP_URL_PATH) . '/niasspotx') {
        return;
    }
    $cookie = isset($_COOKIE['X']) ? $_COOKIE['X'] : '';
    if ($cookie && (microtime(true) * 1000) > hexdec(substr($cookie, 24, 12))) {
        $response = wp_remote_head('https://app.spotplayer.ir/', array(
            'headers'   => array('cookie' => 'X=' . $cookie),
            'sslverify' => false,
        ));
        if (!is_wp_error($response)) {
            $cookies = wp_remote_retrieve_cookies($response);
            foreach ($cookies as $c) {
                if ($c->name === 'X') {
                    setcookie('X', $c->value, time() + 9e9, '/', parse_url(get_home_url(), PHP_URL_HOST), true, false);
                }
            }
        }
    }
    die();
}

function nias_spot_debug()
{
    if (substr($_SERVER['REQUEST_URI'], 0, strlen(parse_url(get_home_url(), PHP_URL_PATH) . '/niasspdeb')) !== parse_url(get_home_url(), PHP_URL_PATH) . '/niasspdeb') {
        return;
    }
    current_user_can('administrator') or die('Access denied');
    header('Content-Type: application/json');

    if (nias_spot_woo_or_edd() === 1) {
        $o = wc_get_order($_GET['id']);
        header('Content-Disposition: attachment; filename=nias-debug-' . $o->get_id() . '.json');
        die(json_encode(array(
            'code' => nias_spot_license_code(),
            'user' => get_user_meta($o->get_user_id()),
            'data' => $o->get_data(),
            'meta' => $o->get_meta_data(),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    } else {
        $p = edd_get_payment($_GET['id']);
        header('Content-Disposition: attachment; filename=nias-debug-' . $p->ID . '.json');
        die(json_encode(array(
            'code' => nias_spot_license_code(),
            'user' => get_user_meta($p->user_id),
            'data' => $p,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

/* =========================================================================
 * WooCommerce data helpers
 * ===================================================================== */

/** @return WC_Product[]|string[] */
function nias_spot_woo_order_items($ord, $products = false)
{
    $r = array();
    if ($ord) {
        foreach ($ord->get_items() as $i) {
            if (($i instanceof WC_Order_Item_Product) && (($p = $i->get_product()) instanceof WC_Product) && ($c = $p->get_meta('_nias_spot_course'))) {
                $products ? array_push($r, $p) : ($r = array_merge($r, explode(',', $c)));
            }
        }
    }
    return $r;
}

/** @throws Exception */
function nias_spot_woo_order_license_request(WC_Order $ord, $admin = false)
{
    $data = nias_spot_woo_license_data($ord);
    if (!empty($data['_id'])) {
        return $data;
    }
    if (!count($courses = nias_spot_woo_order_items($ord))) {
        return null;
    }
    if (!$admin && ($ord->get_date_created()->getTimestamp() < (int) nias_spot_opt('time', 0))) {
        return null;
    }

    try {
        $rep = nias_spot_request_license_put(array_merge($data, array('course' => $courses, 'payload' => strval($ord->get_id()))));
        if (!($id = $rep['_id'] ?? null)) {
            throw new Exception('999');
        }
        $ord->update_meta_data('_nias_spot_data', $data = array_merge($data, $rep));
        $ord->save_meta_data();
        $ord->add_order_note(sprintf('لایسنس با شناسه %s برای این سفارش ایجاد شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
        return $data;
    } catch (Exception $ex) {
        $err = sprintf('خطای %s هنگام ایجاد لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>');
        $ord->add_order_note($err . (($ex->getCode() == 999) ? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/niasspdeb?id=' . $ord->get_id() . '">اطلاعات دیباگ</a>' : ''));
        nias_spot_admin_notice($err . ' <a href="' . get_edit_post_link($ord->get_id()) . '">سفارش ' . $ord->get_id() . '</a>');
        throw new Exception($err);
    }
}

function nias_spot_woo_license_data(WC_Order $ord) // $order name preserved for eval code
{
    $data = $ord->get_meta('_nias_spot_data') ?: array();
    if (in_array($ord->get_status(), array('auto-draft', 'draft'), true)) {
        return $data;
    }
    return $data ?: nias_spot_woo_license_data_eval($ord);
}

function nias_spot_woo_license_data_eval(?WC_Order $order) // $order & $user preserved for eval code
{
    if (!$order) {
        return null;
    }
    $user = $order->get_user();
    return @eval('return ' . nias_spot_license_code() . ';');
}

/* =========================================================================
 * EDD data helpers
 * ===================================================================== */

function nias_spot_edd_payment_items($pay, $downloads = false)
{
    $r = array();
    if ($pay) {
        foreach (edd_get_payment_meta_cart_details($pay->ID) as $i) {
            $c = get_post_meta($i['id'], '_nias_spot_course', true);
            if (!$downloads) {
                $r = array_merge($r, $c ?: array());
            } elseif ($i['course'] = join(',', $c ?: array())) {
                $r[] = $i;
            }
        }
    }
    return $r;
}

/** @throws Exception */
function nias_spot_edd_payment_license_request(EDD_Payment $pay, $admin = false)
{
    $data = nias_spot_edd_license_data($pay);
    if (!empty($data['_id'])) {
        return $data;
    }
    if (!count($courses = nias_spot_edd_payment_items($pay))) {
        return null;
    }
    if (!$admin && (strtotime(edd_get_payment_completed_date($pay->ID)) < (int) nias_spot_opt('time', 0))) {
        return null;
    }

    try {
        $rep = nias_spot_request_license_put(array_merge($data, array('course' => $courses, 'payload' => strval($pay->ID))));
        if (!($id = $rep['_id'] ?? null)) {
            throw new Exception('999');
        }
        $pay->update_meta('_nias_spot_data', $data = array_merge($data, $rep));
        edd_insert_payment_note($pay->ID, sprintf('لایسنس با شناسه %s برای این سفارش ایجاد شد.', '<a href="https://panel.spotplayer.ir/license/edit/' . $id . '" target="_blank">' . $id . '</a>'));
        return $data;
    } catch (Exception $ex) {
        $err = sprintf('خطای %s هنگام ایجاد لایسنس روی داد.', '<b>«' . $ex->getMessage() . '»</b>');
        edd_insert_payment_note($pay->ID, $err . (($ex->getCode() == 999) ? ' <a target="_blank" href="' . parse_url(get_home_url(), PHP_URL_PATH) . '/niasspdeb?id=' . $pay->ID . '">اطلاعات دیباگ</a>' : ''));
        nias_spot_admin_notice($err . ' <a href="' . get_edit_post_link($pay->ID) . '">سفارش ' . $pay->ID . '</a>');
        throw new Exception($err);
    }
}

function nias_spot_edd_license_data(EDD_Payment $pay)
{
    if ($data = $pay->get_meta('_nias_spot_data') ?: array()) {
        return $data;
    }
    return nias_spot_edd_license_data_eval($pay);
}

function nias_spot_edd_license_data_eval(?EDD_Payment $payment) // $payment & $user preserved for eval code
{
    if (!$payment) {
        return null;
    }
    $user = get_userdata(edd_get_payment_user_id($payment->ID));
    return @eval('return ' . nias_spot_license_code() . ';');
}

/* =========================================================================
 * Assets
 * ===================================================================== */

function nias_spot_shop_css()
{
    wp_enqueue_style('nias-spot-shop', NIASADMIN_URL . '/spotplayer-shop.css', array(), NIAS_COURSE_VERSION);
    $c = nias_spot_opt('color', '#6611DD');
    if (!preg_match('/^#[0-9A-F]{6}$/i', $c)) {
        $c = '#6611DD';
    }
    wp_add_inline_style('nias-spot-shop', "#nias-sp_license > button{background:$c} #nias-sp b{color:$c} #nias-sp_players > div{background:" . nias_spot_hex2rgba($c, 0.05) . "}");
}

function nias_spot_admin_css($hook)
{
    wp_enqueue_style('nias-spot-admin', NIASADMIN_URL . '/spotplayer-admin.css', array(), NIAS_COURSE_VERSION);
}

/* =========================================================================
 * Hook registration (only when the feature is enabled)
 * ===================================================================== */

if (nias_spot_enabled()) {
    // Routes.
    add_action('parse_request', 'nias_spot_url_handler');

    // Assets.
    add_action('wp_enqueue_scripts', 'nias_spot_shop_css');
    add_action('admin_enqueue_scripts', 'nias_spot_admin_css');

    $platform = nias_spot_woo_or_edd();

    if ($platform === 1) {
        // The simple-product course-ID field lives in the Nias "تنظیمات اسپات پلیر"
        // metabox (see woocommerce-course/function-course.php). Variable products
        // still get a per-variation field below.
        add_action('woocommerce_product_after_variable_attributes', 'nias_spot_woo_admin_variation_panel', 10, 2);
        add_action('woocommerce_admin_process_variation_object', 'nias_spot_woo_admin_variation_update', 10, 2);

        // Order metabox.
        add_action('add_meta_boxes', 'nias_spot_woo_admin_order', 0);
        add_action('woocommerce_process_shop_order_meta', 'nias_spot_woo_admin_order_save', 10, 1);

        // Shop.
        add_action('woocommerce_order_details_before_order_table', 'nias_spot_woo_shop_order');
        add_filter('woocommerce_account_menu_items', 'nias_spot_woo_shop_my_menu', 50);
        add_action('init', 'nias_spot_woo_shop_my_licenses_init');
        add_action('woocommerce_account_nias-licenses_endpoint', 'nias_spot_woo_shop_my_licenses_content');
    } elseif ($platform === 2) {
        // EDD.
        add_action('edd_price_field', 'nias_spot_edd_admin_dl', 10, 1);
        add_action('edd_save_download', 'nias_spot_edd_admin_dl_save', 10, 2);
        add_action('edd_view_order_details_main_before', 'nias_spot_edd_admin_payment_box', 10, 1);
        add_action('edd_updated_edited_purchase', 'nias_spot_edd_admin_payment_save', 10, 1);
        add_action('edd_payment_receipt_after_table', 'nias_spot_edd_shop_order', 10, 1);
    }

    // Shortcode (both platforms).
    add_shortcode('nias_spotplayer_courses', 'nias_spot_shortcode');
}
