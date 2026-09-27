<?php

use Mpdf\Mpdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// Get certificate settings from Carbon Fields
function get_certificate_settings() {
    return [
        'header_bg' => carbon_get_theme_option('certificate_header_bg'),
        'footer_bg' => carbon_get_theme_option('certificate_footer_bg'),
        'certificate_watermark' => carbon_get_theme_option('certificate_watermark'),
        'certificate_icon' => carbon_get_theme_option('certificate_icon'),
        'first_title' => carbon_get_theme_option('certificate_first_title') ?: 'گواهی تکمیل دوره',
        'before_name_title' => carbon_get_theme_option('certificate_before_name_title') ?: 'این گواهی تأیید میکند که',
        'after_name_title' => carbon_get_theme_option('certificate_after_name_title') ?: 'با موفقیت دوره زیر را تکمیل نمود:',
        'show_date' => carbon_get_theme_option('certificate_show_date') !== 'off',
        'seal_image' => carbon_get_theme_option('certificate_seal_image'),
        'signature_image' => carbon_get_theme_option('certificate_signature_image'),
        'signer_name' => carbon_get_theme_option('certificate_signer_name') ?: 'مدیرعامل و مدرس: علیرضا علی‌نیا'
    ];
}

/**
 * Whether a product is eligible for a certificate, honoring the
 * "نحوه نمایش مدرک" setting (certificate_display_type + selected products/cats).
 *
 * Mirrors nias_modern_course_cert_applies() so the [nias_certificate] list and
 * the modern course view agree on which products get a certificate.
 *
 * @param int $product_id
 * @return bool
 */
function nias_certificate_product_eligible($product_id)
{
    if (carbon_get_theme_option('nias_course_certificate') !== 'on') {
        return false;
    }
    $type = carbon_get_theme_option('certificate_display_type');

    if ($type === 'all') {
        return true;
    }
    if ($type === 'selected') {
        $selected = array_map('strval', (array) carbon_get_theme_option('certificate_selected_products'));
        return in_array((string) $product_id, $selected, true);
    }
    if ($type === 'category') {
        $cats  = array_map('strval', (array) carbon_get_theme_option('certificate_selected_categories'));
        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        if (is_wp_error($terms)) {
            return false;
        }
        foreach ((array) $terms as $term_id) {
            if (in_array((string) $term_id, $cats, true)) {
                return true;
            }
        }
        return false;
    }
    return false; // 'none' or unset
}

/**
 * Resolve the date printed on a certificate, honoring the "منبع تاریخ" setting
 * (certificate_date_source): purchase date, a manual date, or a per-user date
 * stored in user meta.
 *
 * @param int    $user_id
 * @param int    $product_id
 * @param string $order_date fallback (the purchase/completion date, Y-m-d)
 * @return string
 */
function nias_certificate_resolve_date($user_id, $product_id, $order_date)
{
    $source = carbon_get_theme_option('certificate_date_source');

    if ($source === 'manual_date') {
        $manual = carbon_get_theme_option('certificate_manual_date');
        if (!empty($manual)) {
            return $manual;
        }
    } elseif ($source === 'user_certificate_date') {
        // Settings label references "nias_certificate_date"; keep backward
        // compatibility with the older "usercertificate_date" meta key.
        $udate = get_user_meta($user_id, 'nias_certificate_date', true);
        if (empty($udate)) {
            $udate = get_user_meta($user_id, 'usercertificate_date', true);
        }
        if (!empty($udate)) {
            return $udate;
        }
    }

    return $order_date; // 'purchase_date' (default) or empty/unset settings
}

/**
 * Convert a Gregorian date (Y, M, D) to Jalali (Persian) date parts.
 * Standard algorithm, no external dependency.
 *
 * @param int $gy
 * @param int $gm
 * @param int $gd
 * @return array{0:int,1:int,2:int} [jy, jm, jd]
 */
function nias_gregorian_to_jalali($gy, $gm, $gd)
{
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
    $days  = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4)) - ((int) (($gy2 + 99) / 100))
        + ((int) (($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int) ($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int) ($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int) ($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int) (($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return array($jy, $jm, $jd);
}

class CertificateMPDF
{
    private $mpdf;
    private $settings;

    public function __construct()
    {
        // دریافت تنظیمات
        $this->settings = get_certificate_settings();

        // تنظیمات mPDF برای پشتیبانی از فارسی
        $this->mpdf = new Mpdf([
            'mode' => 'utf-8-s',
            'format' => 'A4-P',
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'default_font' => 'vazir',
            'fontDir' => [
                __DIR__ . '/../assets/fonts/',
            ],
            'fontdata' => [
                'vazir' => [
                    'R' => 'Vazir.ttf',
                    'B' => 'Vazir-Bold.ttf',
                    'L' => 'Vazir.ttf',
                    'M' => 'Vazir-Medium.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font_size' => 16,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'directionality' => 'rtl',
            'debug' => false,
            'allow_output_buffering' => true,
        ]);

        // Set background image properties
        $this->mpdf->SetDefaultBodyCSS('background-image', 'url("' . NIAS_IMAGE . '/background.png")');
        $this->mpdf->SetDefaultBodyCSS('background-repeat', 'no-repeat');
        $this->mpdf->SetDefaultBodyCSS('background-position', 'center');
        $this->mpdf->SetDefaultBodyCSS('background-size', 'cover');

        // Only set header if header image exists
        if (!empty($this->settings['header_bg'])) {
            $headerHtml = '<div style="text-align: center; width: 100%; height: 30mm;">
                <img src="' . $this->settings['header_bg'] . '" style="width: 100%; height: 30mm; object-fit: cover;" />
            </div>';
            $this->mpdf->SetHTMLHeader($headerHtml);
        }

        // Only set footer if footer image exists
        if (!empty($this->settings['footer_bg'])) {
            $footerHtml = '<div style="text-align: center; width: 100%; height: 30mm;">
                <img src="' . $this->settings['footer_bg'] . '" style="width: 100%; height: 30mm; object-fit: cover;" />
            </div>';
            $this->mpdf->SetHTMLFooter($footerHtml);
        }
    }

    public function createCertificate($name, $course, $date, $certificate_code = '', $qr_image = '')
    {
        // شروع محتوای HTML
        $html = $this->generateCertificateHTML($name, $course, $date, $certificate_code, $qr_image);

        // تنظیم CSS برای استایل‌دهی
        $css = $this->getCertificateCSS();

        // اضافه کردن CSS به mPDF
        $this->mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

        // اضافه کردن HTML به mPDF
        $this->mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

        return $this->mpdf;
    }


    public function generateCertificateHTML($name, $course, $date, $certificate_code = '', $qr_image = '')
    {
        $nameClass = 'english-text';

        $html = '<div class="nias-course-pdf">
<table style="width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; text-align: center; direction: rtl;">';

        // Only show certificate icon if available
        if (!empty($this->settings['certificate_icon'])) {
            $html .= '<tr>
                <td style="padding: 20px;">
                    <img src="' . $this->settings['certificate_icon'] . '" alt="Certificate Logo" style="width: 150px; height: 150px;"/>
                </td>
            </tr>';
        }

        // <!-- محتوای اصلی گواهی -->
        $html .= '<tr>
            <td style="padding: 20px;">
                <h1 style="font-size: 28px; color: #333; margin: 10px 0;">' . $this->settings['first_title'] . '</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 20px;">
                <p style="font-size: 16px; color: #555; margin: 10px 0;">' . $this->settings['before_name_title'] . '</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 20px;">
                <h2 style="font-size: 24px; color: #000; margin: 10px 0; font-weight: bold;" class="' . $nameClass . '">' . htmlspecialchars($name) . '</h2>
            </td>
        </tr>
        <tr>
            <td style="padding: 20px;">
                <p style="font-size: 16px; color: #555; margin: 10px 0;">' . $this->settings['after_name_title'] . '</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 20px;">
                <h3 style="font-size: 20px; color: #333; margin: 10px 0;">' . htmlspecialchars($course) . '</h3>
            </td>
        </tr>';

        // Add date section if enabled
        if ($this->settings['show_date']) {
            $html .= '<tr>
                <td style="padding: 20px;">
                    <p style="font-size: 16px; color: #555; margin: 10px 0;">در تاریخ ' . $this->convertToJalali($date) . '</p>
                </td>
            </tr>';
        }

        $html .= '</table>
            
<!-- امضا و مهر -->
<table style="width: 100%; border-collapse: collapse;"><tr>';
        
        if (!empty($this->settings['certificate_watermark'])) {
            $html .= '<td style="text-align: center; width: 33%;">
                <img src="' . $this->settings['certificate_watermark'] . '" alt="Watermark" width="100" height="100" style="opacity: 0.7;" />
            </td>';
        }
        
        if (!empty($this->settings['signature_image'])) {
            $html .= '<td style="text-align: center;">
                <img src="' . $this->settings['signature_image'] . '" alt="Signature" width="120" height="120" />
                <p style="font-size: 13px;">' . $this->settings['signer_name'] . '</p>
            </td>';
        }
        
        if (!empty($this->settings['seal_image'])) {
            $html .= '<td style="text-align: center;">
                <img src="' . $this->settings['seal_image'] . '" alt="Stamp" width="120" height="120" />
            </td>';
        }
        
        $html .= '</tr></table>';

        // Only show QR code if available
        if (!empty($qr_image)) {
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-top:20px;">
                <tr>
                    <td style="text-align: center;">
                        <img src="' . $qr_image . '" alt="QR Code" width="85" height="85" />
                        <p style="font-size: 14px;">کد گواهی‌نامه</p>
                        <p>' . htmlspecialchars($certificate_code) . '</p>
                    </td>
                </tr>
            </table>';
        }

        $html .= '</div>';
        return $html;
    }

    public function getCertificateCSS()
    {
        return '
        <style>

        body {
    background-image: url("' . NIAS_IMAGE . '/background.png");
    background-repeat: no-repeat;
    background-position: center;
    background-size: cover;
}
        </style>';
    }


    // تبدیل تاریخ میلادی به شمسی
    private function convertToJalali($gregorianDate)
    {
        if (empty($gregorianDate)) {
            return $gregorianDate;
        }
        $ts = strtotime($gregorianDate);
        if ($ts === false) {
            return $gregorianDate; // unparseable (e.g. an already-formatted manual date)
        }
        $gy = (int) date('Y', $ts);
        // A year below 1700 is almost certainly an already-Jalali date entered
        // manually — leave it untouched instead of double-converting.
        if ($gy < 1700) {
            return $gregorianDate;
        }
        if (!function_exists('nias_gregorian_to_jalali')) {
            return $gregorianDate;
        }
        list($jy, $jm, $jd) = nias_gregorian_to_jalali($gy, (int) date('n', $ts), (int) date('j', $ts));
        $months = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
        $month_name = isset($months[$jm - 1]) ? $months[$jm - 1] : $jm;
        return $jd . ' ' . $month_name . ' ' . $jy;
    }

    public function output($filename = 'certificate.pdf', $dest = 'D')
    {
        return $this->mpdf->Output($filename, $dest);
    }
}


/**
 * Footer JS/CSS for the [nias_certificate] list (download progress + states).
 */
function nias_certificate_list_assets()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    wp_enqueue_script('jquery');
    add_action('wp_footer', function () {
?>
        <script>
            jQuery(document).ready(function($) {
                $('.certificate-form').on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var progressContainer = form.siblings('.progress-container');
                    var progressBar = progressContainer.find('.progress-bar');
                    var progressText = progressContainer.find('.progress-text');

                    // Show progress container and hide button
                    form.find('button').hide();
                    progressContainer.show();

                    // Simulate progress
                    var progress = 0;
                    var interval = setInterval(function() {
                        progress += 10;
                        if (progress <= 90) {
                            progressBar.css('width', progress + '%');
                            progressText.text('در حال ساخت گواهی... ' + progress + '%');
                        }
                    }, 500);

                    // Submit form data
                    $.ajax({
                        url: form.attr('action'),
                        type: 'POST',
                        data: form.serialize(),
                        xhrFields: {
                            responseType: 'blob'
                        },
                        success: function(response) {
                            clearInterval(interval);
                            progressBar.css('width', '100%');
                            progressText.text('دانلود در حال انجام...');

                            // Force download
                            var blob = new Blob([response], {
                                type: 'application/pdf'
                            });
                            var link = document.createElement('a');
                            link.href = window.URL.createObjectURL(blob);
                            link.download = 'certificate.pdf';
                            link.click();

                            // Reset form after short delay
                            setTimeout(function() {
                                progressContainer.hide();
                                form.find('button').show();
                                progressBar.css('width', '0%');
                            }, 1000);
                        },
                        error: function() {
                            clearInterval(interval);
                            progressText.text('خطا در دانلود!');
                            setTimeout(function() {
                                progressContainer.hide();
                                form.find('button').show();
                                progressBar.css('width', '0%');
                            }, 2000);
                        }
                    });
                });
            });
        </script>
        <style>
            .certificate-download-container{
                font-family: initial;
            }
            .progress-container {
                margin-top: 10px;
                background: #f0f0f0;
                border-radius: 4px;
                padding: 3px;
                width: 100%;
            }

            .progress-bar {
                height: 20px;
                background: #4CAF50;
                width: 0%;
                border-radius: 2px;
                transition: width 0.3s ease-in-out;
            }

            .progress-text {
                text-align: center;
                font-size: 12px;
                margin-top: 5px;
                color: #666;
            }

            .certificate-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }

            .certificate-table th,
            .certificate-table td {
                padding: 10px;
                border: 1px solid #ddd;
                text-align: center;
            }

            .certificate-table th {
                background: #f5f5f5;
            }

            .button {
                background: #4CAF50;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.3s;
            }

            .button:hover {
                background: #45a049;
            }

            .certificate-error {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border: 1px solid #f5c6cb;
                border-radius: 5px;
                margin: 10px 0;
            }

            .certificate-success {
                background: #d4edda;
                color: #155724;
                padding: 15px;
                border: 1px solid #c3e6cb;
                border-radius: 5px;
                margin: 10px 0;
            }

            .nias-cert-state {
                display: inline-block;
                font-size: 13px;
                font-weight: 600;
                padding: 4px 10px;
                border-radius: 99px;
            }
            .nias-cert-state.pending { background: #fff3cd; color: #856404; }
            .nias-cert-state.revoked { background: #f8d7da; color: #721c24; }
            .nias-cert-state.locked { background: #eef0f3; color: #555; }

            .nias-cert-checks {
                list-style: none;
                margin: 8px 0 0;
                padding: 0;
                text-align: right;
                font-size: 13px;
                line-height: 1.9;
            }
            .nias-cert-checks li { display: flex; gap: 6px; align-items: baseline; }
            .nias-cert-checks .ok { color: #1e7e34; }
            .nias-cert-checks .no { color: #b02a37; }
            .nias-cert-checks small { display: block; color: #777; font-size: 12px; }

            .nias-cert-nameform {
                background: #fff;
                border: 1px solid #ffeaa7;
                border-radius: 8px;
                padding: 14px;
                margin: 12px 0;
            }
            .nias-cert-nameform-t { font-weight: 700; margin-bottom: 10px; }
            .nias-cert-nameform-row { display: flex; gap: 8px; flex-wrap: wrap; }
            .nias-cert-nameform-row input[type=text] { flex: 1; min-width: 140px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; }
        </style>
<?php
    });
}

// Modified shortcode function
function nias_certificate_shortcode($atts)
{
    // Initialize verification variable
    $nias_course_certificate_verify = false;
    $verification_message = '';

    nias_certificate_list_assets();

    // بررسی پارامتر code در URL
    $code_param = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
    $user_id_param = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // اگر پارامتر code موجود است، بررسی اعتبار آن
    if (!empty($code_param) && $user_id_param > 0) {

        // دریافت کد گواهی ذخیره شده برای کاربر
        $stored_certificate_code_1 = get_user_meta($user_id_param, 'usercertificate_code', true);

        // دریافت تمام متاهای کاربر که با پیشوند certificate_code_ شروع می‌شوند
        $user_meta = get_user_meta($user_id_param);
        $stored_certificate_code_2 = '';

        foreach ($user_meta as $meta_key => $meta_value) {
            if (strpos($meta_key, 'certificate_code_') === 0) {
                if ($meta_value[0] === $code_param) {
                    $stored_certificate_code_2 = $meta_value[0];
                    break;
                }
            }
        }

        // A revoked certificate no longer verifies.
        $registry_row = ($stored_certificate_code_1 === $code_param || $stored_certificate_code_2 === $code_param) && function_exists('nias_cert_get_by_code')
            ? nias_cert_get_by_code($code_param)
            : null;
        $is_revoked = $registry_row && $registry_row['status'] === 'revoked';

        // بررسی تطابق با هر کدام از کدها
        if (($stored_certificate_code_1 === $code_param || $stored_certificate_code_2 === $code_param) && !$is_revoked) {
            // کد معتبر است - تنظیم پیام موفقیت
            $nias_course_certificate_verify = true;
            $verification_message = '<div class="certificate-success">
                        <h3>✅ گواهی‌ معتبر است</h3>
                        <p><strong>شناسه کاربر:</strong> ' . esc_html($user_id_param) . '</p>
                        <p>این گواهی معتبر و قابل اعتماد است.</p>
                    </div>';
        } else {
            $nias_course_certificate_verify = false;

            // کد نامعتبر است - تنظیم پیام خطا
            $verification_message = $is_revoked
                ? '<div class="certificate-error">
                        <h3>❌ این گواهی باطل شده است</h3>
                        <p>اعتبار این گواهی توسط صادرکننده لغو شده است.</p>
                    </div>'
                : '<div class="certificate-error">
                        <h3>❌ گواهی نامعتبر است</h3>
                        <p>کدی که وارد کرده‌اید در سیستم یافت نشد.</p>
                    </div>';
        }
    }

    // ادامه کد قبلی برای نمایش گواهی‌نامه‌ها
    $user_id = !empty($user_id_param) ? $user_id_param : (isset($_GET['user_id']) ? intval($_GET['user_id']) : 0);

    // اگر user_id صفر است، سعی کن از کاربر فعلی استفاده کنی
    if ($user_id === 0 && is_user_logged_in()) {
        $user_id = get_current_user_id();
    }

    // اگر هنوز user_id صفر است، خطا نشان بده
    if ($user_id === 0) {
        return '<p class="certificate-error">شناسه کاربر مشخص نشده است.</p>';
    }

    // بررسی وجود کاربر
    $user_data = get_userdata($user_id);
    if (!$user_data) {
        return '<p class="certificate-error">کاربر با این شناسه یافت نشد.</p>';
    }

    // Permission: a visitor may see a user's certificate list only for their own
    // account, or when they arrived with a valid verification code for that user.
    // This keeps the normal "view my certificates" flow working without a ?code=
    // link, while still protecting other users' lists.
    $is_self  = is_user_logged_in() && get_current_user_id() === $user_id;
    $can_view = $is_self || $nias_course_certificate_verify;
    if (!$can_view) {
        $wrap_open = '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px;">';
        if (!empty($code_param)) {
            return $wrap_open . $verification_message . '</div>'; // invalid-code message
        }
        if (is_user_logged_in()) {
            return $wrap_open . '<div class="certificate-error"><h3>دسترسی مجاز نیست</h3><p>اجازهٔ مشاهدهٔ مدارک این کاربر را ندارید.</p></div></div>';
        }
        return $wrap_open . '<div class="certificate-error"><h3>برای مشاهده مدارک</h3><p>برای دیدن مدارک خود ابتدا وارد حساب کاربری شوید.</p></div></div>';
    }

    // All purchased certificate courses with their state. Visitors arriving
    // through a verification link only see issued certificates.
    $courses = nias_cert_user_courses($user_id);
    if (!$is_self) {
        $courses = array_filter($courses, function ($c) {
            return $c['status'] === 'ready';
        });
    }

    if (empty($courses)) {
        return '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px;">
                    <h2>مدارک دوره</h2>
                    <div class="no-certificate-message" style="text-align: center; padding: 30px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; color: #856404;">
                        <h3>هیچ مدرکی یافت نشد</h3>
                        <p>' . esc_html($user_data->display_name) . ' عزیز، هنوز هیچ دوره‌ای که شامل مدرک باشد را تکمیل نکرده‌اید یا دوره‌های شما شامل مدرک نمیشود.</p>
                        <p><strong>شناسه کاربر:</strong> ' . esc_html($user_id) . '</p>
                        <p><em>اگر فکر میکنید این اشتباه است، لطفاً با پشتیبانی تماس بگیرید.</em></p>
                    </div>
                </div>';
    }

    // Name form when the "require name" rule blocks at least one course.
    $name_form = '';
    if ($is_self) {
        foreach ($courses as $c) {
            foreach ($c['checks'] as $chk) {
                if ($chk['key'] === 'name' && !$chk['ok']) {
                    $name_form = nias_cert_name_form_html($user_id);
                    break 2;
                }
            }
        }
        if (isset($_GET['nias_cert_name']) && $_GET['nias_cert_name'] === 'saved') {
            $name_form = '<div class="certificate-success">نام شما ذخیره شد و روی مدرک درج می‌شود.</div>' . $name_form;
        }
    }

    $download_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('generate_certificate_nonce');
        $output = '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px; z-index: 99;position:relative;">
        ' . $verification_message . '
        <h2>مدارک دوره‌ها</h2>
        <p><strong>کاربر:</strong> ' . esc_html($user_data->display_name) . ' (ID: ' . esc_html($user_id) . ')</p>
        ' . $name_form . '
        <table class="certificate-table">
            <thead>
                <tr>
                    <th>نام دوره</th>
                    <th>تاریخ تکمیل</th>
                    <th>کد گواهی</th>
                    <th>دانلود گواهی</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($courses as $product_id => $course_data) {
            $output .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($course_data['name']),
                esc_html($course_data['date']),
                $course_data['status'] === 'locked' ? '—' : esc_html($course_data['certificate_code']),
                nias_certificate_action_cell($user_id, $product_id, $course_data, $download_url, $nonce)
            );
        }

        $output .= '
            </tbody>
        </table>
    </div>';

        return $output;
}

/**
 * Last column of the certificate list: download form, or the reason the
 * certificate is not available yet.
 */
function nias_certificate_action_cell($user_id, $product_id, $course_data, $download_url, $nonce)
{
    switch ($course_data['status']) {
        case 'ready':
            return sprintf(
                '<form method="post" action="%s" class="certificate-form">
                    <input type="hidden" name="action" value="generate_certificate">
                    <input type="hidden" name="user_id" value="%s">
                    <input type="hidden" name="product_id" value="%s">
                    <input type="hidden" name="certificate_code" value="%s">
                    <input type="hidden" name="nonce" value="%s">
                    <button type="submit" class="button">دانلود گواهی</button>
                </form>
                <div class="progress-container" style="display: none;">
                    <div class="progress-bar"></div>
                    <div class="progress-text">در حال ساخت گواهی...</div>
                </div>',
                esc_url($download_url),
                esc_attr($user_id),
                esc_attr($product_id),
                esc_attr($course_data['certificate_code']),
                esc_attr($nonce)
            );

        case 'pending':
            return '<span class="nias-cert-state pending">در انتظار تأیید مدیر</span>';

        case 'revoked':
            return '<span class="nias-cert-state revoked">باطل‌شده</span>';

        default:
            $html = '<span class="nias-cert-state locked">شرایط دریافت هنوز کامل نشده</span><ul class="nias-cert-checks">';
            foreach ($course_data['checks'] as $chk) {
                $html .= '<li class="' . ($chk['ok'] ? 'ok' : 'no') . '"><span>' . ($chk['ok'] ? '✔' : '✖') . '</span><span>' . esc_html($chk['label'])
                    . ($chk['hint'] !== '' ? '<small>' . esc_html($chk['hint']) . '</small>' : '') . '</span></li>';
            }
            return $html . '</ul>';
    }
}

// Add AJAX handler for PDF generation
function handle_certificate_generation()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'generate_certificate_nonce')) {
        wp_die('Invalid request / درخواست نامعتبر');
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $certificate_code = isset($_POST['certificate_code']) ? sanitize_text_field(wp_unslash($_POST['certificate_code'])) : '';

    if (empty($user_id) || empty($certificate_code)) {
        wp_die('Required fields are missing / فیلدهای ضروری خالی هستند');
    }

    // دریافت اطلاعات کاربر از دیتابیس
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        wp_die('User not found / کاربر یافت نشد');
    }

    // Forms rendered before 1.4 do not send product_id: find the course the
    // code belongs to.
    if (!$product_id) {
        global $wpdb;
        $meta_key = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s AND meta_value = %s LIMIT 1",
            $user_id,
            $wpdb->esc_like('certificate_code_') . '%',
            $certificate_code
        ));
        $product_id = $meta_key ? (int) substr($meta_key, 17) : 0;
    }

    // The code must belong to this user and course — everything printed on the
    // certificate is derived server-side, never taken from the request.
    if (!$product_id || get_user_meta($user_id, 'certificate_code_' . $product_id, true) !== $certificate_code) {
        wp_die('Invalid certificate code / کد گواهی نامعتبر است');
    }

    $courses = nias_cert_user_courses($user_id);
    if (!isset($courses[$product_id]) || $courses[$product_id]['status'] !== 'ready') {
        wp_die('Certificate is not available / این گواهی در حال حاضر قابل دریافت نیست');
    }

    $data = nias_cert_build_data($user_id, $product_id, $certificate_code, $courses[$product_id]['date']);
    if (empty($data['student_name'])) {
        wp_die('User name not found / نام کاربر یافت نشد');
    }

    try {
        nias_cert_output_pdf($data, nias_cert_template_for($product_id), 'D');
    } catch (\Throwable $e) {
        wp_die('Error generating certificate: ' . esc_html($e->getMessage()) . ' / خطا در تولید گواهی');
    }

    exit;
}

add_action('wp_ajax_generate_certificate', 'handle_certificate_generation');
add_action('wp_ajax_nopriv_generate_certificate', 'handle_certificate_generation');
add_shortcode('nias_certificate', 'nias_certificate_shortcode');

/**
 * Every purchased course that offers a certificate, with its state.
 *
 * status: ready (downloadable) | pending (awaiting admin approval) |
 *         revoked | locked (conditions not met — see 'checks').
 *
 * @param int $user_id
 * @return array<int,array> product_id => name, date, order_id, certificate_code, product_id, status, checks
 */
function nias_cert_user_courses($user_id)
{
    static $cache = array();
    $user_id = (int) $user_id;
    if (!$user_id) {
        return array();
    }
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    $bought = array();
    try {
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status'      => array('completed', 'processing'),
            'limit'       => -1,
        ));
    } catch (Exception $e) {
        return array();
    }

    foreach ((array) $orders as $order) {
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            // Get product ID from order item
            $product_id = $item['product_id'];
            if (!empty($item['variation_id'])) {
                $product_id = $item['variation_id'];
            }
            if (!$product_id || !wc_get_product($product_id)) {
                continue;
            }
            // Whether this product is eligible for a certificate, honoring the
            // "نحوه نمایش مدرک" setting (همه / محصول انتخابی / دسته‌بندی / هیچکدام).
            if (!nias_certificate_product_eligible($product_id)) {
                continue;
            }

            $status = $order->get_status();
            $date   = $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d') : $order->get_date_created()->date('Y-m-d');
            $prev   = isset($bought[$product_id]) ? $bought[$product_id] : null;
            // Prefer a completed order, then the earliest one.
            if (!$prev
                || ($status === 'completed' && $prev['order_status'] !== 'completed')
                || ($status === $prev['order_status'] && $date < $prev['order_date'])) {
                $bought[$product_id] = array(
                    'name'         => wc_get_product($product_id)->get_name(),
                    'order_id'     => $order->get_id(),
                    'order_status' => $status,
                    'order_date'   => $date,
                );
            }
        }
    }

    $out = array();
    foreach ($bought as $product_id => $b) {
        $eval = nias_cert_evaluate($user_id, $product_id, array('order_status' => $b['order_status'], 'order_date' => $b['order_date']));
        $code = (string) get_user_meta($user_id, 'certificate_code_' . $product_id, true);
        $row  = nias_cert_get_for($user_id, $product_id);

        // Once issued, a certificate stays issued (unless revoked) even if the
        // admin tightens the rules later. A legacy code counts as issued. The
        // order itself must still be in a counting status.
        $issued     = ($row && $row['status'] !== 'pending') || (!$row && $code !== '');
        $order_ok   = in_array($b['order_status'], array('completed', 'processing'), true);
        $grant      = $eval['ok'] || ($issued && $order_ok && (!$row || $row['status'] === 'valid'));

        $state = 'locked';
        if ($grant) {
            if ($code === '') {
                $code = generate_certificate_code($user_id, $product_id);
                update_user_meta($user_id, 'certificate_code_' . $product_id, $code);
            }
            $row   = nias_cert_sync_registry($user_id, $product_id, $code, $b, $eval['rules'], $row);
            $state = $row ? ($row['status'] === 'valid' ? 'ready' : $row['status']) : 'ready';
        } elseif ($row && $row['status'] === 'revoked') {
            $state = 'revoked';
        } elseif ($row && $row['status'] === 'pending') {
            $state = 'pending';
        }

        $out[$product_id] = array(
            'name'             => $b['name'],
            'date'             => nias_certificate_resolve_date($user_id, $product_id, $b['order_date']),
            'order_id'         => $b['order_id'],
            'certificate_code' => $code,
            'product_id'       => $product_id,
            'status'           => $state,
            'checks'           => $eval['checks'],
        );
    }

    $cache[$user_id] = apply_filters('nias_certificate_user_courses', $out, $user_id);
    return $cache[$user_id];
}

/**
 * Make sure the registry has an up-to-date row for an issued certificate.
 * Writes only when something changed, so listing pages stay read-only.
 *
 * @return array|null
 */
function nias_cert_sync_registry($user_id, $product_id, $code, $bought, $rules, $row)
{
    $name   = nias_cert_student_name($user_id);
    $course = $bought['name'];
    if (!$row) {
        return nias_cert_upsert($user_id, $product_id, array(
            'code'         => $code,
            'student_name' => $name,
            'course_name'  => $course,
            'issued_at'    => $bought['order_date'] . ' 00:00:00',
            'template_id'  => nias_cert_template_for($product_id),
            'status'       => $rules['approval'] === 'on' ? 'pending' : 'valid',
        ));
    }
    if ($row['code'] !== nias_cert_normalize_code($code) || $row['student_name'] !== $name || $row['course_name'] !== $course) {
        return nias_cert_upsert($user_id, $product_id, array('code' => $code, 'student_name' => $name, 'course_name' => $course));
    }
    return $row;
}

// تابع بهبود یافته برای دریافت دوره‌های خریداری شده
/**
 * Courses whose certificate is ready to download (pre-1.4 contract: only
 * eligible courses, keyed by product id, with name/date/order_id/code).
 *
 * @param int $user_id
 * @return array
 */
function get_user_purchased_courses($user_id)
{
    if (empty($user_id)) {
        return [];
    }
    return array_filter(nias_cert_user_courses($user_id), function ($c) {
        return $c['status'] === 'ready';
    });
}

function nias_certificate_preview_shortcode($atts)
{
    // Designer mode: render the default template as HTML (CSS mm units).
    $tpl = nias_cert_template_for(0);
    if ($tpl && ($design = nias_cert_get_design($tpl))) {
        $data = nias_cert_sample_data();
        list($pw, $ph) = nias_cert_page_dims($design['page']);
        $bg = 'background-color:' . esc_attr($design['page']['bgColor']) . ';';
        if (!empty($design['page']['bgImage'])) {
            $bg .= 'background-image:url(' . esc_url($design['page']['bgImage']) . ');background-size:100% 100%;';
        }
        $inner = '';
        foreach ($design['elements'] as $el) {
            // Browsers show scrollbars for overflow:auto; mPDF uses it for shrink-to-fit.
            $inner .= str_replace('overflow:auto;', 'overflow:hidden;', nias_cert_element_html($el, $data));
        }
        $font = "@font-face{font-family:vazir;src:url('" . esc_url(NIAS_FONTS_URL . 'Vazir.ttf') . "')}@font-face{font-family:vazir;font-weight:bold;src:url('" . esc_url(NIAS_FONTS_URL . 'Vazir-Bold.ttf') . "')}";
        return '<style>' . $font . '</style><div style="overflow:auto;max-width:100%"><div style="position:relative;margin:0 auto;width:' . nias_cert_num($pw) . 'mm;height:' . nias_cert_num($ph) . 'mm;' . $bg . 'box-shadow:0 0 10px rgba(0,0,0,.1);direction:ltr;overflow:hidden">' . $inner . '</div></div>';
    }

    // Sample data for testing
    $name = "John Doe";
    $course = "Advanced Web Development";
    $date = date('Y-m-d');
    $certificate_code = "CERT-" . rand(1000, 9999);

    // Create QR code for testing
    $qr = new QrCode('https://example.com/verify/' . $certificate_code);
    $writer = new PngWriter();
    $result = $writer->write($qr);
    $qr_image = $result->getDataUri();

    // Create certificate instance
    $certificate = new CertificateMPDF();

    // Get the HTML content without generating PDF
    $html = $certificate->generateCertificateHTML($name, $course, $date, $certificate_code, $qr_image);

    // Add required styles
    $css = $certificate->getCertificateCSS();

    // Return complete HTML with styles
    return '<div style="width: 100%; max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
        <style>' . $css . '</style>
        ' . $html . '
    </div>';
}

add_shortcode('nias_certificate_preview', 'nias_certificate_preview_shortcode');
