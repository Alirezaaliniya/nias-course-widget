<?php

use Mpdf\Mpdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class CertificateMPDF
{
    private $mpdf;

    public function __construct()
    {
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
                __DIR__ . '/../assets/fonts/', // مسیر فونت‌های داخل پلاگین
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

            'debug'                  => false,
            'allow_output_buffering' => true,


        ]);

        // Set background image properties
        $this->mpdf->SetDefaultBodyCSS('background-image', 'url("' . NIAS_IMAGE . '/background.png")');
        $this->mpdf->SetDefaultBodyCSS('background-repeat', 'no-repeat');
        $this->mpdf->SetDefaultBodyCSS('background-position', 'center');
        $this->mpdf->SetDefaultBodyCSS('background-size', 'cover');


                // Set HTML Header
                $headerHtml = '<div style="text-align: center; width: 100%; height: 30mm;">
                <img src="' . NIAS_IMAGE . '/header.png" style="width: 100%; height: 30mm; object-fit: cover;" />
            </div>';
            $this->mpdf->SetHTMLHeader($headerHtml);
    
            // Set HTML Footer
            $footerHtml = '<div style="text-align: center; width: 100%; height: 30mm;">
                <img src="' . NIAS_IMAGE . '/footer.png" style="width: 100%; height: 30mm; object-fit: cover;" />
            </div>';
            $this->mpdf->SetHTMLFooter($footerHtml);

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
        // تشخیص زبان متن نام
        $nameClass = 'english-text';

        $html = '
        <div class="certificate-container">
            <!-- لوگو -->
            <div class="logo">
                <img src="' . NIAS_IMAGE . '/certificate.png" alt="Certificate Logo" width="180" height="180"/>
            </div>
            
            <!-- محتوای اصلی گواهی -->
            <div class="certificate-content">
                <h1 class="certificate-title-farsi">گواهی تکمیل دوره</h1>
                                <p class="intro-text-farsi">این گواهی‌نامه تأیید می‌کند که</p>
                
                <h2 class="student-name ' . $nameClass . '">' . htmlspecialchars($name) . '</h2>
                                <p class="completion-text-farsi">با موفقیت دوره زیر را تکمیل نمود:</p>
                <h3 class="course-name">' . htmlspecialchars($course) . '</h3>
                <p class="completion-date-farsi">در تاریخ ' . $this->convertToJalali($date) . '</p>
            </div>
            
            <!-- امضا و مهر -->
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="text-align: center;">
            <img src="' . NIAS_IMAGE . '/signature.png" alt="Signature" width="80" height="80" />
            <p>Signature</p>
            <p>امضا</p>
        </td>
        <td style="text-align: center;">
            <img src="' . NIAS_IMAGE . '/stamp.png" alt="Stamp" width="80" height="80" />
        </td>
    </tr>
</table>
            
<table style="width: 100%; border-collapse: collapse;">
    <!-- Instructor Info -->
    <tr>
        <td style="text-align: center;">
            <p>مدیرعامل و مدرس: علیرضا علی‌نیا</p>
        </td>
    </tr>
    <!-- Verification Section -->
    <tr>
        <td style="text-align: center;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center;">
                        <img src="' . $qr_image . '" alt="QR Code" width="50" height="50" />
                        <p>کد گواهی‌نامه:</p>
                        <p>' . htmlspecialchars($certificate_code) . '</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>';



        return $html;
    }

    public function getCertificateCSS()
    {
        return '
        <style>
        @page {
            margin: 0;
            size: A4 Portrait;
        }
        
        body {
            margin: 0;
            padding: 0;
            font-family: vazir;
            direction: rtl;
            width: 100%;
        }
            body, * {
  letter-spacing: 0 !important; /* جلوگیری از فاصله افتادن بین حروف فارسی */
}
        body {
    background-image: url("' . NIAS_IMAGE . '/background.png");
    background-repeat: no-repeat;
    background-position: center;ّ
    background-size: cover;
}
    .logo{
    text-align:center;
    display: flex;
     justify-content: center;
      align-items: center;
       width: 100%;
    }

        .certificate-container {
            position: relative;
            width: 297mm;
            height: 210mm;
            overflow: hidden;
                        display: flex;
            justify-content: center;
            align-items: center;
        }
        

        
        .certificate-content {
            top: 60mm; /* کاهش فاصله از بالا */
            left: 0;
            width: 100%;
            text-align: center;
            z-index: 3;
            transform: scale(0.9); /* کوچک‌تر کردن محتوا */
        }
        
        .certificate-title {
            font-size: 24pt;
            font-weight: bold;
            color: #2c3e50;
            margin: 0 0 5mm 0;
            direction: rtl;
        }
        
        .certificate-title-farsi {
            font-family: vazir;
            font-size: 20pt;
            font-weight: bold;
            color: #34495e;
            margin: 10mm 0 10mm 0;
            direction: rtl;
             unicode-bidi: embed;
        }
        
        .intro-text, .completion-text {
            font-size: 16pt;
            color: #2c3e50;
            margin: 5mm 0;
                        direction: rtl;
             unicode-bidi: embed;
        }
        
        .intro-text-farsi, .completion-text-farsi {
            font-family: vazir;
            font-size: 14pt;
            color: #34495e;
            direction: rtl;
        }
        
        .student-name {
            font-size: 22pt;
            font-weight: bold;
            color: #e74c3c;
            margin: 8mm 0;
            text-decoration: underline;
        }
        
        .farsi-text, 
        .certificate-title-farsi, 
        .intro-text-farsi, 
        .completion-text-farsi,
        .course-description-farsi,
        .completion-date-farsi,
        .signature-text-farsi,
        .instructor-text-farsi {
            font-family: vazir;
            direction: rtl;
            text-align: center;
        }
        
        .course-description {
            font-size: 16pt;
            color: #2c3e50;
            margin: 5mm 0;
        }
        
        .course-description-farsi {
            font-family: vazir;
            font-size: 14pt;
            color: #34495e;
            margin: 3mm 0;
            direction: rtl;
        }
        
        .course-name {
            font-size: 18pt;
            font-weight: bold;
            color: #27ae60;
            margin: 8mm 0;
        }
        
        .completion-date {
            font-size: 16pt;
            color: #2c3e50;
            margin: 5mm 0;
        }
        
        .completion-date-farsi {
            font-family: vazir;
            font-size: 14pt;
            color: #34495e;
            margin: 3mm 0;
            direction: rtl;
        }
        
        .signature-section {
            z-index: 3;
        }
        
        .signature {
            text-align: center;
            margin-bottom: 10mm;
        }
        
        .signature img {
            width: 60mm;
            height: 40mm;
        }
        
        .signature-text {
            font-size: 12pt;
            color: #2c3e50;
            margin: 2mm 0;
        }
        
        .signature-text-farsi {
            font-family: vazir;
            font-size: 11pt;
            color: #34495e;
            margin: 1mm 0;
            direction: rtl;
        }
    
        
        .stamp img {
            width: 50mm;
            height: 50mm;
        }
        
        .instructor-info {
            text-align: right;
            z-index: 3;
        }
        
        .instructor-text {
            font-size: 12pt;
            font-style: italic;
            color: #2c3e50;
            margin: 2mm 0;
        }
        
        .instructor-text-farsi {
            font-family: vazir;
            font-size: 11pt;
            font-style: italic;
            color: #34495e;
            margin: 1mm 0;
            direction: rtl;
        }
        </style>';
    }


    // تبدیل تاریخ میلادی به شمسی (ساده)
    private function convertToJalali($gregorianDate)
    {
        // برای سادگی، فعلاً همان تاریخ میلادی را برمی‌گردانیم
        // شما می‌تونید از کتابخانه jDateTime استفاده کنید
        return $gregorianDate;
    }

    public function output($filename = 'certificate.pdf', $dest = 'D')
    {
        return $this->mpdf->Output($filename, $dest);
    }
}


// Modified shortcode function
function nias_certificate_shortcode($atts)
{
    // Add JavaScript for handling form submission and progress bar
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
        </style>
<?php
    });

    // بررسی پارامتر code در URL
    $code_param = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
    $user_id_param = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // متغیر برای ذخیره پیام اعتبارسنجی
    $verification_message = '';

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

        // بررسی تطابق با هر کدام از کدها
        if ($stored_certificate_code_1 === $code_param || $stored_certificate_code_2 === $code_param) {
            // کد معتبر است - تنظیم پیام موفقیت
            $nias_course_certificate_verify = true;
            $verification_message = '<div class="certificate-success">
                        <h3>✅ گواهی‌نامه معتبر است</h3>
                        <p><strong>کد گواهی:</strong> ' . esc_html($code_param) . '</p>
                        <p><strong>شناسه کاربر:</strong> ' . esc_html($user_id_param) . '</p>
                        <p>این گواهی‌نامه معتبر و قابل اعتماد است.</p>
                    </div>';
        } else {
            $nias_course_certificate_verify = false;

            // کد نامعتبر است - تنظیم پیام خطا
            $verification_message = '<div class="certificate-error">
                        <h3>❌ گواهی‌نامه نامعتبر است</h3>
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

    // Get user's eligible courses
    $eligible_courses = get_user_purchased_courses($user_id);

    if (empty($eligible_courses)) {
        return '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px;">
                    <h2>گواهی‌نامه‌های دوره‌ها</h2>
                    <div class="no-certificate-message" style="text-align: center; padding: 30px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; color: #856404;">
                        <h3>هیچ گواهی‌نامه‌ای یافت نشد</h3>
                        <p>' . esc_html($user_data->display_name) . ' عزیز، هنوز هیچ دوره‌ای که شامل گواهی‌نامه باشد را تکمیل نکرده‌اید یا دوره‌های شما شامل گواهی‌نامه نمی‌شود.</p>
                        <p><strong>شناسه کاربر:</strong> ' . esc_html($user_id) . '</p>
                        <p><em>اگر فکر می‌کنید این اشتباه است، لطفاً با پشتیبانی تماس بگیرید.</em></p>
                    </div>
                </div>';
    }

    $download_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('generate_certificate_nonce');
    if ($nias_course_certificate_verify) {
        $output = '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px; z-index: 99;position:relative;">
        ' . $verification_message . '
        <h2>گواهی‌نامه‌های دوره‌ها</h2>
        <p><strong>کاربر:</strong> ' . esc_html($user_data->display_name) . ' (ID: ' . esc_html($user_id) . ')</p>
        <table class="certificate-table">
            <thead>
                <tr>
                    <th>نام دوره</th>
                    <th>تاریخ تکمیل</th>
                    <th>کد گواهی‌نامه</th>
                    <th>دانلود گواهی</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($eligible_courses as $product_id => $course_data) {
            $output .= sprintf(
                '<tr>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>
                    <form method="post" action="%s" class="certificate-form">
                        <input type="hidden" name="action" value="generate_certificate">
                        <input type="hidden" name="user_id" value="%s">
                        <input type="hidden" name="name" value="%s">
                        <input type="hidden" name="course" value="%s">
                        <input type="hidden" name="date" value="%s">
                        <input type="hidden" name="certificate_code" value="%s">
                        <input type="hidden" name="nonce" value="%s">
                        <button type="submit" class="button">دانلود گواهی‌نامه</button>
                    </form>
                    <div class="progress-container" style="display: none;">
                        <div class="progress-bar"></div>
                        <div class="progress-text">در حال ساخت گواهی...</div>
                    </div>
                </td>
            </tr>',
                esc_html($course_data['name']),
                esc_html($course_data['date']),
                esc_html($course_data['certificate_code']),
                esc_url($download_url),
                esc_attr($user_id),
                esc_attr($user_data->display_name),
                esc_attr($course_data['name']),
                esc_attr($course_data['date']),
                esc_attr($course_data['certificate_code']),
                esc_attr($nonce)
            );
        }

        $output .= '
            </tbody>
        </table>
    </div>';

        return $output;
    } else {
        return '<div class="certificate-download-container" style="max-width: 800px; margin: auto; padding: 20px; background-color: #f9f9f9; border-radius: 10px; z-index: 99;position:relative;">
        <h2>گواهی‌نامه‌های دوره‌ها</h2>
        <p><strong>کاربر:</strong> ' . esc_html($user_data->display_name) . ' (ID: ' . esc_html($user_id) . ')</p>
        <div class="certificate-error">
            <h3>❌ گواهی‌نامه نامعتبر است</h3>
            <p>کدی که وارد کرده‌اید در سیستم یافت نشد.</p>
        </div>
    </div>';
    }
}

// Add AJAX handler for PDF generation
function handle_certificate_generation()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'generate_certificate_nonce')) {
        wp_die('Invalid request / درخواست نامعتبر');
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $course = isset($_POST['course']) ? sanitize_text_field($_POST['course']) : '';
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $certificate_code = isset($_POST['certificate_code']) ? sanitize_text_field($_POST['certificate_code']) : '';

    if (empty($user_id) || empty($certificate_code)) {
        wp_die('Required fields are missing / فیلدهای ضروری خالی هستند');
    }

    // دریافت اطلاعات کاربر از دیتابیس
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        wp_die('User not found / کاربر یافت نشد');
    }

    // گرفتن نام و نام خانوادگی از فیلدهای کاربر
    $first_name = get_user_meta($user_id, 'first_name', true);
    $last_name = get_user_meta($user_id, 'last_name', true);
    
    // ترکیب نام و نام خانوادگی
    $full_name = trim($first_name . ' ' . $last_name);
    
    // اگر نام و نام خانوادگی خالی بود، از نام نمایشی استفاده کن
    if (empty($full_name)) {
        $full_name = $user->display_name;
    }

    if (empty($full_name)) {
        wp_die('User name not found / نام کاربر یافت نشد');
    }

    // پاک کردن تمام buffer های خروجی
    while (ob_get_level()) {
        ob_end_clean();
    }

    try {
        // Create QR code for certificate verification
        $verification_url = add_query_arg(
            array(
                'code' => urlencode($certificate_code),
                'user_id' => $user_id
            ),
            get_certificate_display_page_url()
        );
        $qr = new QrCode($verification_url);
        $writer = new PngWriter();
        $result = $writer->write($qr);
        $qr_image_data = $result->getDataUri();

        // ایجاد گواهی با mPDF
        $certificate = new CertificateMPDF();
        $certificate->createCertificate($full_name, $course, $date, $certificate_code, $qr_image_data);

        // تنظیم header های HTTP برای دانلود PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certificate_' . sanitize_file_name($full_name) . '_' . sanitize_file_name($certificate_code) . '.pdf"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        $certificate->output('certificate.pdf', 'I');
    } catch (Exception $e) {
        wp_die('Error generating certificate: ' . $e->getMessage() . ' / خطا در تولید گواهی‌نامه');
    }

    exit;
}

add_action('wp_ajax_generate_certificate', 'handle_certificate_generation');
add_action('wp_ajax_nopriv_generate_certificate', 'handle_certificate_generation');
add_shortcode('nias_certificate', 'nias_certificate_shortcode');

// تابع بهبود یافته برای دریافت دوره‌های خریداری شده
function get_user_purchased_courses($user_id)
{
    if (empty($user_id)) {
        //error_log('get_user_purchased_courses: user_id empty');
        return [];
    }

    $eligible_products = [];

    try {
        // Get all customer orders using WooCommerce functions
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('completed', 'processing'), // اضافه کردن processing
            'limit' => -1,
        ));

        //error_log('Orders found for user ' . $user_id . ': ' . count($orders));

        if (empty($orders)) {
            // اگر سفارشی یافت نشد، بررسی کنید که آیا کاربر وجود دارد
            $user = get_userdata($user_id);
            if ($user) {
                //error_log('User exists but no orders found for user ID: ' . $user_id);
            } else {
                //error_log('User not found with ID: ' . $user_id);
            }
            return [];
        }

        foreach ($orders as $order) {
            //error_log('Processing order ID: ' . $order->get_id() . ' with status: ' . $order->get_status());

            foreach ($order->get_items() as $item) {
                if (!($item instanceof WC_Order_Item_Product)) {
                    continue;
                }

                // Get product ID from order item
                $product_id = $item['product_id'];
                if (!empty($item['variation_id'])) {
                    $product_id = $item['variation_id'];
                }

                if (!$product_id) continue;

                // Get product object
                $product = wc_get_product($product_id);
                if (!$product) continue;

                //error_log('Processing product: ' . $product->get_name() . ' (ID: ' . $product_id . ')');

                // بررسی اینکه آیا این محصول واجد شرایط گواهی‌نامه است
                // فعلاً همه محصولات را واجد شرایط در نظر می‌گیریم
                // شما می‌توانید شرایط خاص خود را اضافه کنید
                $is_certificate_eligible = true; // یا has_user_purchased_certificate($user_id);

                if ($is_certificate_eligible) {
                    // Get or generate certificate code
                    $certificate_code = get_user_meta($user_id, 'certificate_code_' . $product_id, true);
                    if (empty($certificate_code)) {
                        $certificate_code = generate_certificate_code($user_id, $product_id);
                        update_user_meta($user_id, 'certificate_code_' . $product_id, $certificate_code);
                    }

                    $eligible_products[$product_id] = [
                        'name' => $product->get_name(),
                        'date' => $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d') : $order->get_date_created()->date('Y-m-d'),
                        'order_id' => $order->get_id(),
                        'certificate_code' => $certificate_code
                    ];

                    //error_log('Added eligible product: ' . $product->get_name());
                }
            }
        }

        //error_log('Total eligible products for user ' . $user_id . ': ' . count($eligible_products));

    } catch (Exception $e) {
        //error_log('Error in get_user_purchased_courses: ' . $e->getMessage());
        return [];
    }

    return $eligible_products;
}

function nias_certificate_preview_shortcode($atts)
{
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
