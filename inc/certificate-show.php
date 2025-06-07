<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    return;
}

require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logos\LogoInterface;


class Certificate extends FPDF
{
    // تنظیمات هدر
    function Header()
    {
        // افزودن تصویر پس‌زمینه به صورت کاور برای هدر
        $this->Image(__DIR__ . '/image/header.png', 0, 0, $this->GetPageWidth(), 30); // عرض صفحه و ارتفاع هدر
    }

    // تنظیمات فوتر
    function Footer()
    {
        // موقعیت فوتر
        $this->SetY(-15);
        // افزودن تصویر پس‌زمینه به صورت کاور برای فوتر
        $this->Image(__DIR__ . '/image/footer.png', 0, $this->GetPageHeight() - 30, $this->GetPageWidth(), 30); // عرض صفحه و ارتفاع فوتر
    }

    function createCertificate($name, $course, $date)
    {
        // افزودن صفحه
        $this->AddPage();

        // افزودن تصویر پس‌زمینه گواهی
        $this->Image(__DIR__ . '/image/background.png', 0, 0, $this->GetPageWidth(), $this->GetPageHeight());

        // افزودن تصویر گواهی
        //  $this->Image(__DIR__ . '/image/certificate.png', 10, 10, 50);

        // افزودن لوگو بالای عنوان
        $logoWidth = 50; // عرض لوگو
        $logoHeight = 50; // ارتفاع لوگو
        $logoX = ($this->GetPageWidth() - $logoWidth) / 2; // لوگو در وسط
        $logoY = 20; // فاصله از بالای صفحه
        $this->Image(__DIR__ . '/image/certificate.png', $logoX, $logoY, $logoWidth, $logoHeight);


        // موقعیت عنوان زیر لوگو
        $this->SetXY(0, 70); // فاصله از بالای صفحه
        $this->SetFont('Arial', 'B', 24);
        $this->Cell(0, 20, 'Certificate of Completion', 0, 1, 'C');

        // افزودن محتوای گواهی
        $this->SetFont('Arial', '', 16);
        $this->Ln(10); // فاصله عمودی
        $this->Cell(0, 10, 'This is to certify that', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(0, 20, $name, 0, 1, 'C');
        $this->SetFont('Arial', '', 16);
        $this->Cell(0, 10, 'has successfully completed the', 0, 1, 'C');
        $this->Cell(0, 10, 'Nias advanced web development course (63 hours)', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 20, $course, 0, 1, 'C');
        $this->SetFont('Arial', '', 16);
        $this->Cell(0, 10, 'on ' . $date, 0, 1, 'C');

        // افزودن امضا
        $signatureWidth = 70; // عرض امضا
        $signatureHeight = 70; // ارتفاع امضا
        $signatureX = $this->GetPageWidth() - $signatureWidth - 30; // فاصله از سمت راست
        $signatureY = $this->GetPageHeight() - $signatureHeight - 60; // فاصله از پایین صفحه
        $this->Image(__DIR__ . '/image/signature.png', $signatureX, $signatureY, $signatureWidth, $signatureHeight);

        // افزودن مهر
        $stampWidth = 60; // عرض امضا
        $stampHeight = 60; // ارتفاع امضا
        $stampX = $this->GetPageWidth() - $stampWidth - 145; // فاصله از سمت راست
        $stampY = $this->GetPageHeight() - $stampHeight - 50; // فاصله از پایین صفحه
        $this->Image(__DIR__ . '/image/stamp.png', $stampX, $stampY, $stampWidth, $stampHeight);

        // افزودن خط امضا
        $this->Ln(20);
        $this->SetFont('Arial', 'I', 12);
        $this->Cell(0, 10, ':Signature', 0, 1, 'R');

        // افزودن متن زیر امضا
        $this->Ln(10);
        $this->SetFont('Arial', 'I', 12);
        $this->Cell(0, 10, 'CEO and Instructor: Alireza Aliniya', 0, 1, 'R');
    }
}
class CertificateWithQRCode extends Certificate
{
    function createCertificate($name, $course, $date, $certificateUrl = '')
    {
        // اجرای متد اصلی ایجاد گواهینامه
        parent::createCertificate($name, $course, $date);

        // بررسی اینکه آیا URL معتبر است
        if (!empty($certificateUrl)) {
            // تولید QR Code
            $qrCode = QrCode::create($certificateUrl)
                ->setSize(200)
                ->setMargin(10);

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            // ذخیره QR Code در یک فایل موقت
            $qrCodePath = __DIR__ . '/temp_qrcode.png';
            file_put_contents($qrCodePath, $result->getString());

            // افزودن QR Code در گوشه سمت چپ پایین صفحه
            $qrCodeWidth = 40;
            $qrCodeHeight = 40;
            $qrCodeX = $this->GetPageWidth() - 50 - 10; // Assuming $stampWidth is 60 as used earlier
            $qrCodeY = $this->GetPageHeight() - $qrCodeHeight - 15;
            $this->Image($qrCodePath, $qrCodeX, $qrCodeY, $qrCodeWidth, $qrCodeHeight);

            // حذف فایل موقت
            unlink($qrCodePath);
        }
    }
}

function get_user_id_from_current_url() {
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                   . "://" . $_SERVER['HTTP_HOST'] 
                   . $_SERVER['REQUEST_URI'];
    
    $parsed_url = parse_url($current_url);

    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_params);
        if (isset($query_params['user_id'])) {
            return intval($query_params['user_id']);
        }
    }

    return null;
}

function showCertificate() {
    @ini_set('display_errors', 0);
    error_reporting(0);

    if (ob_get_level()) {
        ob_end_clean();
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    $user_id = get_user_id_from_current_url();

    if ($user_id === null) {
        wp_die('No user ID provided');
    }

    $user = get_userdata($user_id);

    if (!$user) {
        wp_die('User not found');
    }

    // دریافت نام و نام خانوادگی
    $first_name = get_user_meta($user_id, 'first_name', true);
    $last_name = get_user_meta($user_id, 'last_name', true);
    
    // ترکیب نام و نام خانوادگی
    $name = trim("$first_name $last_name");
    if (empty($name)) {
        $name = 'Guest';
    }

    $course = 'WordPress + Elementor + Coding';
    $date = date('F j, Y');

    $certificate_code = get_user_meta($user_id, 'usercertificate_code', true);

    if (empty($certificate_code)) {
        wp_die('No certificate code found');
    }

    // Get the configured certificate page ID
    $certificate_page_id = carbon_get_theme_option('certificate_page');
    $verification_url = $certificate_page_id ? get_permalink($certificate_page_id) : home_url('/verify-certificate');

    $verification_link = add_query_arg(
        array(
            'code' => $certificate_code,
            'user_id' => $user_id
        ), 
        $verification_url
    );

    $certificate = new CertificateWithQRCode();
    
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate.pdf"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $certificate->createCertificate($name, $course, $date, $verification_link);
    $certificate->Output('I', 'certificate.pdf');
    exit();
}

function trigger_certificate_download() {
    if (!is_admin() && isset($_GET['user_id'])) {
        showCertificate();
    }
}
add_action('init', 'trigger_certificate_download');


// Alternative method if the above doesn't work
function alternative_certificate_download() {
    if (is_user_logged_in() && isset($_GET['download_certificate'])) {
        // Disable all WordPress output
        define('DONOTCACHEPAGE', true);
        define('DONOTMINIFY', true);
        remove_all_actions('wp_head');
        remove_all_actions('wp_footer');

        showCertificate();
        die();
    }
}
add_action('template_redirect', 'alternative_certificate_download', 1);


/**
 * Generate certificate code when a user buys product with ID 1819
 */
function generate_certificate_code_on_product_purchase($order_id) {
    // Get the order
    $order = wc_get_order($order_id);
    
    // Check if order is valid
    if (!$order) {
        return;
    }
    
    // Check if order is completed
    if ($order->get_status() !== 'completed') {
        return;
    }
    
    // Get customer ID
    $user_id = $order->get_user_id();
    
    // Check if user exists
    if (!$user_id) {
        return;
    }
    
    // Check if order contains product ID 1819
    $contains_target_product = false;
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        if ($product_id == 1819) {
            $contains_target_product = true;
            break;
        }
    }
    
    // If the order doesn't contain the target product, exit
    if (!$contains_target_product) {
        return;
    }
    
    // Check if certificate code already exists for this user
    $existing_code = get_user_meta($user_id, 'usercertificate_code', true);
    
    // If a code already exists, don't create a new one
    if (!empty($existing_code)) {
        return;
    }
    
    // Generate a unique certificate code
    $certificate_code = 'NIAS-' . strtoupper(substr(md5($user_id . time() . rand(1000, 9999)), 0, 12));
    
    // Save certificate code to user meta
    update_user_meta($user_id, 'usercertificate_code', $certificate_code);
    
    // Optionally store the purchase date
    update_user_meta($user_id, 'certificate_issue_date', current_time('mysql'));
    
    // Optionally log this action
    error_log("Certificate code {$certificate_code} generated for user {$user_id} after purchasing product 1819");
}

// Hook the function to WooCommerce order status changes
add_action('woocommerce_order_status_completed', 'generate_certificate_code_on_product_purchase');








