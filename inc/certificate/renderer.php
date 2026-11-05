<?php
if (!defined('ABSPATH')) {
    exit;
}

use Mpdf\Mpdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;

/**
 * Certificate rendering.
 *
 * Picks the renderer for a certificate — the classic fixed layout
 * (CertificateMPDF, unchanged) or a template built in the canvas designer —
 * and turns a designed template into a PDF with mPDF. Designer coordinates are
 * millimetres and font sizes points, so the PDF matches the canvas 1:1.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Page sizes
 * ---------------------------------------------------------------------- */

/** Portrait sizes in mm (width, height). */
function nias_cert_page_sizes()
{
    return array(
        'A4'     => array(210, 297),
        'A5'     => array(148, 210),
        'Letter' => array(215.9, 279.4),
    );
}

/** [width, height] in mm for a design's page. */
function nias_cert_page_dims($page)
{
    $sizes = nias_cert_page_sizes();
    $size  = isset($page['size'], $sizes[$page['size']]) ? $sizes[$page['size']] : $sizes['A4'];
    $land  = !isset($page['orientation']) || $page['orientation'] !== 'P';
    return $land ? array($size[1], $size[0]) : $size;
}

/* -------------------------------------------------------------------------
 * Dates
 * ---------------------------------------------------------------------- */

/**
 * Format a date as Jalali. Already-Jalali or unparseable values pass through.
 *
 * @param string $date
 * @param string $style long ("۵ مهر ۱۴۰۵") | numeric ("1405/07/05")
 * @return string
 */
function nias_cert_jalali($date, $style = 'long')
{
    $ts = $date ? strtotime($date) : false;
    if ($ts === false || (int) date('Y', $ts) < 1700) {
        return (string) $date;
    }
    list($jy, $jm, $jd) = nias_gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    if ($style === 'numeric') {
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
    $months = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    return $jd . ' ' . $months[$jm - 1] . ' ' . $jy;
}

/* -------------------------------------------------------------------------
 * Dynamic data
 * ---------------------------------------------------------------------- */

/** Tokens available in designer text elements (token => label). */
function nias_cert_token_labels()
{
    return apply_filters('nias_certificate_token_labels', array(
        'student_name'   => __('نام کامل دانشجو', 'nias-course-widget'),
        'first_name'     => __('نام', 'nias-course-widget'),
        'last_name'      => __('نام خانوادگی', 'nias-course-widget'),
        'course_name'    => __('نام دوره', 'nias-course-widget'),
        'date'           => __('تاریخ شمسی (۵ مهر ۱۴۰۵)', 'nias-course-widget'),
        'date_numeric'   => __('تاریخ شمسی عددی', 'nias-course-widget'),
        'date_gregorian' => __('تاریخ میلادی', 'nias-course-widget'),
        'code'           => __('کد مدرک', 'nias-course-widget'),
        'verify_url'     => __('لینک استعلام', 'nias-course-widget'),
        'instructor'     => __('نام مدرس', 'nias-course-widget'),
        'quiz_score'     => __('نمرهٔ آزمون (درصد)', 'nias-course-widget'),
        'signer_name'    => __('نام امضاکننده', 'nias-course-widget'),
        'site_name'      => __('نام سایت', 'nias-course-widget'),
        'site_url'       => __('آدرس سایت', 'nias-course-widget'),
    ));
}

/**
 * Collect everything a certificate can print for (user, product).
 *
 * @param int    $user_id
 * @param int    $product_id
 * @param string $code
 * @param string $date resolved certificate date (Y-m-d, or free text)
 * @return array
 */
function nias_cert_build_data($user_id, $product_id, $code, $date)
{
    $instructor = '';
    if ($product_id && function_exists('nias_instructors_enabled') && nias_instructors_enabled() && function_exists('nias_product_instructors')) {
        $names = array();
        foreach (nias_product_instructors(nias_cert_parent_product_id($product_id)) as $iid) {
            $u = get_userdata($iid);
            if ($u) {
                $names[] = $u->display_name;
            }
        }
        $instructor = implode('، ', $names);
    }

    $score = '';
    if ($product_id && function_exists('nias_quiz_course_average')) {
        // Several quizzes (lesson quizzes + final exam): their average best score.
        $score = (string) nias_quiz_course_average($user_id, $product_id);
    } elseif ($product_id && function_exists('nias_quiz_for_course')) {
        foreach (nias_quiz_for_course($product_id) as $qid) {
            $rec = get_user_meta($user_id, nias_quiz_result_meta_key($qid), true);
            if (is_array($rec) && isset($rec['pct'])) {
                $score = (string) (int) $rec['pct'];
                break;
            }
        }
    }

    $ts = $date ? strtotime($date) : false;
    $data = array(
        'user_id'        => (int) $user_id,
        'product_id'     => (int) $product_id,
        'student_name'   => nias_cert_student_name($user_id),
        'first_name'     => (string) get_user_meta($user_id, 'first_name', true),
        'last_name'      => (string) get_user_meta($user_id, 'last_name', true),
        'course_name'    => $product_id ? get_the_title($product_id) : '',
        'date_raw'       => (string) $date,
        'date'           => nias_cert_jalali($date, 'long'),
        'date_numeric'   => nias_cert_jalali($date, 'numeric'),
        'date_gregorian' => ($ts && (int) date('Y', $ts) >= 1700) ? date('Y/m/d', $ts) : (string) $date,
        'code'           => (string) $code,
        'verify_url'     => $code ? nias_cert_verify_url($code, $user_id) : home_url('/'),
        'instructor'     => $instructor,
        'quiz_score'     => $score,
        'signer_name'    => (string) nias_cert_setting('certificate_signer_name', ''),
        'site_name'      => get_bloginfo('name'),
        'site_url'       => preg_replace('#^https?://#', '', untrailingslashit(home_url())),
    );
    return apply_filters('nias_certificate_data', $data, $user_id, $product_id);
}

/** Sample data for previews. */
function nias_cert_sample_data()
{
    $code = 'CERT-DEMO-7K2Q9XWM';
    return array(
        'user_id'        => 0,
        'product_id'     => 0,
        'student_name'   => 'علیرضا محمدی',
        'first_name'     => 'علیرضا',
        'last_name'      => 'محمدی',
        'course_name'    => 'دورهٔ جامع طراحی سایت با وردپرس',
        'date_raw'       => current_time('Y-m-d'),
        'date'           => nias_cert_jalali(current_time('Y-m-d'), 'long'),
        'date_numeric'   => nias_cert_jalali(current_time('Y-m-d'), 'numeric'),
        'date_gregorian' => current_time('Y/m/d'),
        'code'           => $code,
        'verify_url'     => nias_cert_verify_url($code, 0),
        'instructor'     => 'استاد نمونه',
        'quiz_score'     => '95',
        'signer_name'    => (string) nias_cert_setting('certificate_signer_name', 'مدیر آموزش'),
        'site_name'      => get_bloginfo('name'),
        'site_url'       => preg_replace('#^https?://#', '', untrailingslashit(home_url())),
    );
}

/** Replace {token} placeholders. */
function nias_cert_apply_tokens($text, $data)
{
    return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($data) {
        return isset($data[$m[1]]) ? (string) $data[$m[1]] : $m[0];
    }, (string) $text);
}

/* -------------------------------------------------------------------------
 * Template selection
 * ---------------------------------------------------------------------- */

/**
 * Designer template to use for a product, or 0 for the classic layout.
 *
 * @param int $product_id
 * @return int
 */
function nias_cert_template_for($product_id)
{
    if (nias_cert_setting('certificate_design_mode', 'classic') !== 'designer') {
        return 0;
    }
    $tpl = 0;
    $pid = nias_cert_parent_product_id($product_id);
    if ($pid) {
        $tpl = (int) get_post_meta($pid, '_nias_cert_template', true);
    }
    if (!$tpl) {
        $tpl = (int) nias_cert_setting('certificate_default_template', 0);
    }
    return ($tpl && function_exists('nias_cert_get_design') && nias_cert_get_design($tpl)) ? $tpl : 0;
}

/* -------------------------------------------------------------------------
 * mPDF
 * ---------------------------------------------------------------------- */

/** Shared mPDF config (Vazir font, zero margins). */
function nias_cert_mpdf_config($format)
{
    return array(
        'mode'              => 'utf-8',
        'format'            => $format,
        'margin_left'       => 0,
        'margin_right'      => 0,
        'margin_top'        => 0,
        'margin_bottom'     => 0,
        'margin_header'     => 0,
        'margin_footer'     => 0,
        'default_font'      => 'vazir',
        'fontDir'           => array(NIAS_COURSE . 'assets/fonts/'),
        'fontdata'          => array(
            'vazir' => array(
                'R'          => 'Vazir.ttf',
                'B'          => 'Vazir-Bold.ttf',
                'useOTL'     => 0xFF,
                'useKashida' => 75,
            ),
        ),
        'autoScriptToLang'  => true,
        'autoLangToFont'    => false,
        'directionality'    => 'rtl',
        'tempDir'           => nias_cert_temp_dir(),
    );
}

/** A writable temp dir for mPDF (uploads/nias-cert-tmp, falls back to system tmp). */
function nias_cert_temp_dir()
{
    $up = wp_upload_dir(null, false);
    if (empty($up['error'])) {
        $dir = trailingslashit($up['basedir']) . 'nias-cert-tmp';
        if (wp_mkdir_p($dir) && wp_is_writable($dir)) {
            if (!file_exists($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', '<?php // Silence');
            }
            return $dir;
        }
    }
    return get_temp_dir();
}

/**
 * Use a local file path for media from this site, so mPDF does not need an
 * HTTP round-trip (which fails on many hosts).
 */
function nias_cert_local_src($url)
{
    $url = (string) $url;
    if ($url === '' || strpos($url, 'data:') === 0) {
        return $url;
    }
    $up = wp_upload_dir(null, false);
    $map = array(
        set_url_scheme($up['baseurl'], 'http')  => $up['basedir'],
        set_url_scheme($up['baseurl'], 'https') => $up['basedir'],
        set_url_scheme(untrailingslashit(plugins_url()), 'http')  => WP_PLUGIN_DIR,
        set_url_scheme(untrailingslashit(plugins_url()), 'https') => WP_PLUGIN_DIR,
    );
    foreach ($map as $base => $dir) {
        if (strpos($url, $base) === 0) {
            $path = $dir . substr($url, strlen($base));
            $path = strtok($path, '?');
            if (file_exists($path)) {
                return $path;
            }
        }
    }
    return $url;
}

/**
 * QR code PNG data URI.
 *
 * Error correction "Medium" (15%) survives print wear and small logos/stains
 * better than the library default "Low".
 *
 * @param string $text
 * @param string $fg  foreground hex
 * @param string $bg  background hex, '' = transparent
 * @return string
 */
function nias_cert_qr_data_uri($text, $fg = '#000000', $bg = '#ffffff')
{
    static $cache = array();
    $key = md5($text . '|' . $fg . '|' . $bg);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $qr = new QrCode((string) $text);
    $qr->setSize(600);
    $qr->setMargin(0);
    $qr->setEncoding(new Encoding('UTF-8'));
    $qr->setErrorCorrectionLevel(new ErrorCorrectionLevelMedium());
    $f = nias_cert_hex_rgb($fg ? $fg : '#000000');
    $qr->setForegroundColor(new Color($f[0], $f[1], $f[2]));
    if ($bg === '') {
        $qr->setBackgroundColor(new Color(255, 255, 255, 127));
    } else {
        $b = nias_cert_hex_rgb($bg);
        $qr->setBackgroundColor(new Color($b[0], $b[1], $b[2]));
    }
    $writer = new PngWriter();
    return $cache[$key] = $writer->write($qr)->getDataUri();
}

/** "#rrggbb" → [r, g, b]. */
function nias_cert_hex_rgb($hex)
{
    $hex = ltrim((string) sanitize_hex_color((string) $hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return array(0, 0, 0);
    }
    return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

/* -------------------------------------------------------------------------
 * Barcode (Code 128, subset B)
 * ---------------------------------------------------------------------- */

/**
 * Module widths of a Code 128-B barcode, alternating bar/space and starting
 * with a bar, including the 10-module quiet zones on both sides (returned as
 * the first and last "space" entries; the first entry is a 0-width bar).
 *
 * Characters outside printable ASCII cannot be encoded and are dropped.
 *
 * @param string $text
 * @return int[] empty when nothing is encodable
 */
function nias_cert_code128_widths($text)
{
    static $p = array(
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    );
    $text = preg_replace('/[^\x20-\x7E]/', '', (string) $text);
    if ($text === '') {
        return array();
    }
    $codes = array(104); // Start B
    $sum   = 104;
    $len   = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $v       = ord($text[$i]) - 32;
        $codes[] = $v;
        $sum    += $v * ($i + 1);
    }
    $codes[] = $sum % 103;
    $codes[] = 106; // Stop

    $w = array(0, 10); // zero bar + left quiet zone
    foreach ($codes as $c) {
        foreach (str_split($p[$c]) as $d) {
            $w[] = (int) $d;
        }
    }
    $w[] = 10; // right quiet zone (follows the stop's final bar)
    return $w;
}

/**
 * Write a barcode as an SVG file (mPDF draws SVG as vectors, so bars stay
 * razor-sharp at any print resolution) and return its path.
 *
 * @return string '' when nothing is encodable
 */
function nias_cert_barcode_svg_file($text, $w_mm, $h_mm, $color)
{
    $svg = nias_cert_barcode_svg($text, $w_mm, $h_mm, $color);
    if ($svg === '') {
        return '';
    }
    $file = trailingslashit(nias_cert_temp_dir()) . 'bc-' . md5($svg) . '.svg';
    if (!file_exists($file)) {
        file_put_contents($file, $svg);
    }
    return $file;
}

/** Barcode SVG markup sized in mm ('' when nothing is encodable). */
function nias_cert_barcode_svg($text, $w_mm, $h_mm, $color)
{
    $widths = nias_cert_code128_widths($text);
    if (!$widths) {
        return '';
    }
    $total = array_sum($widths);
    $mw    = $w_mm / $total;
    $x     = 0.0;
    $rects = '';
    foreach ($widths as $i => $n) {
        if ($i % 2 === 0 && $n > 0) {
            $rects .= '<rect x="' . nias_cert_num4($x) . '" y="0" width="' . nias_cert_num4($n * $mw) . '" height="' . nias_cert_num4($h_mm) . '"/>';
        }
        $x += $n * $mw;
    }
    $fill = sanitize_hex_color($color) ? sanitize_hex_color($color) : '#000000';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . nias_cert_num4($w_mm) . 'mm" height="' . nias_cert_num4($h_mm) . 'mm" viewBox="0 0 ' . nias_cert_num4($w_mm) . ' ' . nias_cert_num4($h_mm) . '"><g fill="' . $fill . '" shape-rendering="crispEdges">' . $rects . '</g></svg>';
}

/** Float with up to 4 decimals. */
function nias_cert_num4($n)
{
    return rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
}

/** "#rrggbb" + opacity → CSS rgba(). */
function nias_cert_rgba($hex, $opacity = 1)
{
    $hex = sanitize_hex_color((string) $hex);
    if (!$hex) {
        return '';
    }
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $o = max(0, min(1, (float) $opacity));
    if ($o >= 1) {
        return '#' . $hex;
    }
    return sprintf('rgba(%d,%d,%d,%s)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), rtrim(rtrim(number_format($o, 2, '.', ''), '0'), '.'));
}

/** Format a float for CSS (mm). */
function nias_cert_num($n)
{
    return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}

/**
 * HTML for one designed element (absolutely positioned, mm units).
 *
 * @param array $el
 * @param array $data
 * @return string
 */
function nias_cert_element_html($el, $data)
{
    if (!empty($el['hidden'])) {
        return '';
    }
    $x = nias_cert_num($el['x']);
    $y = nias_cert_num($el['y']);
    $w = nias_cert_num($el['w']);
    $h = nias_cert_num($el['h']);
    $opacity = isset($el['opacity']) ? (float) $el['opacity'] : 1;

    $box = 'position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $w . 'mm;height:' . $h . 'mm;';

    switch ($el['type']) {
        case 'image':
            if (empty($el['src'])) {
                return '';
            }
            return '<div style="' . $box . 'overflow:hidden;"><img src="' . esc_attr(nias_cert_local_src($el['src'])) . '" style="width:' . $w . 'mm;height:' . $h . 'mm;' . ($opacity < 1 ? 'opacity:' . nias_cert_num($opacity) . ';' : '') . '"></div>';

        case 'qr':
            $content = (isset($el['content']) && $el['content'] === 'custom')
                ? trim(nias_cert_apply_tokens(isset($el['text']) ? $el['text'] : '', $data))
                : (!empty($data['verify_url']) ? $data['verify_url'] : home_url('/'));
            if ($content === '') {
                return '';
            }
            $uri  = nias_cert_qr_data_uri($content, isset($el['color']) ? $el['color'] : '#000000', isset($el['bg']) ? (string) $el['bg'] : '#ffffff');
            $side = nias_cert_num(min((float) $el['w'], (float) $el['h']));
            return '<div style="' . $box . '"><img src="' . $uri . '" style="width:' . $side . 'mm;height:' . $side . 'mm;"></div>';

        case 'barcode':
            $value = trim(nias_cert_apply_tokens(isset($el['text']) && $el['text'] !== '' ? $el['text'] : '{code}', $data));
            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
            $color = sanitize_hex_color(isset($el['color']) ? $el['color'] : '') ? $el['color'] : '#000000';
            $label = !empty($el['showText']);
            $lh    = $label ? min(5, (float) $el['h'] * 0.3) : 0; // mm reserved for the human-readable line
            $bar_h = max(1, (float) $el['h'] - $lh);
            if (!empty($data['_html'])) {
                // Browser preview: inline SVG instead of a server file path.
                $svg = nias_cert_barcode_svg($value, (float) $el['w'], $bar_h, $color);
                if ($svg === '') {
                    return '';
                }
                $img = '<div style="width:' . $w . 'mm;height:' . nias_cert_num($bar_h) . 'mm;line-height:0">' . $svg . '</div>';
            } else {
                $file = nias_cert_barcode_svg_file($value, (float) $el['w'], $bar_h, $color);
                if ($file === '') {
                    return '';
                }
                $img = '<img src="' . esc_attr($file) . '" style="width:' . $w . 'mm;height:' . nias_cert_num($bar_h) . 'mm;">';
            }
            $css = $box;
            $bg  = nias_cert_rgba(isset($el['bg']) ? $el['bg'] : '', $opacity);
            if ($bg) {
                $css .= 'background-color:' . $bg . ';';
            }
            $html = '<div style="' . $css . '">' . $img;
            if ($label) {
                $html .= '<div style="direction:ltr;text-align:center;font-family:vazir;font-size:' . nias_cert_num(max(5, $lh * 1.8)) . 'pt;line-height:1;padding-top:0.6mm;color:' . $color . ';letter-spacing:0.3mm;">' . esc_html($value) . '</div>';
            }
            return $html . '</div>';

        case 'rect':
        case 'line':
        case 'ellipse':
            $css = $box;
            $bg  = nias_cert_rgba(isset($el['bg']) ? $el['bg'] : '', $opacity);
            if ($bg) {
                $css .= 'background-color:' . $bg . ';';
            }
            if (!empty($el['borderWidth']) && !empty($el['borderColor'])) {
                $css .= 'border:' . nias_cert_num($el['borderWidth']) . 'mm solid ' . nias_cert_rgba($el['borderColor'], $opacity) . ';';
            }
            if ($el['type'] === 'ellipse') {
                $css .= 'border-radius:' . nias_cert_num($el['w'] / 2) . 'mm / ' . nias_cert_num($el['h'] / 2) . 'mm;';
            } elseif (!empty($el['radius'])) {
                $css .= 'border-radius:' . nias_cert_num($el['radius']) . 'mm;';
            }
            return '<div style="' . $css . '"></div>';

        case 'text':
        default:
            $text = nias_cert_apply_tokens(isset($el['text']) ? $el['text'] : '', $data);
            $text = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            $align = isset($el['align']) && in_array($el['align'], array('left', 'center', 'right', 'justify'), true) ? $el['align'] : 'center';
            $color = nias_cert_rgba(isset($el['color']) ? $el['color'] : '#000000', $opacity);
            $size  = isset($el['fontSize']) ? max(4, (float) $el['fontSize']) : 14;
            $lh    = isset($el['lineHeight']) ? max(0.8, (float) $el['lineHeight']) : 1.4;
            $dir   = (isset($el['dir']) && $el['dir'] === 'ltr') ? 'ltr' : 'rtl';
            $font  = 'direction:' . $dir . ';text-align:' . $align . ';font-family:vazir;font-size:' . nias_cert_num($size) . 'pt;line-height:' . nias_cert_num($lh) . ';color:' . ($color ? $color : '#000') . ';';
            $font .= !empty($el['bold']) ? 'font-weight:bold;' : '';
            // mPDF: overflow:auto on a fixed-position block shrinks text to fit.
            $css   = $box . ((!isset($el['fit']) || $el['fit']) ? 'overflow:auto;' : 'overflow:visible;');
            $bg = nias_cert_rgba(isset($el['bg']) ? $el['bg'] : '', $opacity);
            if ($bg) {
                $css .= 'background-color:' . $bg . ';';
            }
            // mPDF ignores `direction` on the positioned box itself; an inner
            // block is needed for correct RTL punctuation (":", «») placement.
            return '<div style="' . $css . '"><div style="' . $font . '">' . $text . '</div></div>';
    }
}

/**
 * Render a designed template to an mPDF instance.
 *
 * @param array $design sanitized design (see nias_cert_sanitize_design)
 * @param array $data   nias_cert_build_data() output
 * @return Mpdf
 */
function nias_cert_render_design($design, $data)
{
    $page = isset($design['page']) ? $design['page'] : array();
    list($pw, $ph) = nias_cert_page_dims($page);

    $mpdf = new Mpdf(nias_cert_mpdf_config(array($pw, $ph)));
    $mpdf->SetTitle($data['course_name'] ? $data['course_name'] : 'Certificate');
    $mpdf->SetAuthor(get_bloginfo('name'));
    $mpdf->SetCreator(get_bloginfo('name'));
    if (!empty($data['code'])) {
        // Code + verification link in the file properties, so the PDF
        // itself says where it can be checked.
        $mpdf->SetSubject('Certificate ' . $data['code']);
        $mpdf->SetKeywords($data['code'] . ' ' . $data['verify_url']);
    }
    // Opens and prints freely, but cannot be edited or have its text copied
    // in viewers that honor PDF permissions (Acrobat, browsers, …).
    // A digitally signed (certified) PDF is protected by the signature
    // itself; encryption would also break the incremental signing.
    if (!empty($data['code']) && !nias_cert_signing_active() && apply_filters('nias_certificate_pdf_protect', true, $data)) {
        $mpdf->SetProtection(array('print', 'print-highres'), '', wp_generate_password(32, true, true));
    }

    $page_css = 'margin:0;';
    $bg_color = sanitize_hex_color(isset($page['bgColor']) ? $page['bgColor'] : '');
    if ($bg_color) {
        $page_css .= 'background-color:' . $bg_color . ';';
    }
    if (!empty($page['bgImage'])) {
        $page_css .= 'background-image:url("' . esc_attr(nias_cert_local_src($page['bgImage'])) . '");background-image-resize:6;background-repeat:no-repeat;';
    }

    $html  = '<html><head><style>@page{' . $page_css . '} body{font-family:vazir;}</style></head><body>';
    foreach ((array) $design['elements'] as $el) {
        $html .= nias_cert_element_html($el, $data);
    }
    $html .= '</body></html>';

    $mpdf->WriteHTML($html);
    return $mpdf;
}

/* -------------------------------------------------------------------------
 * Output
 * ---------------------------------------------------------------------- */

/**
 * Build a certificate PDF and return its bytes, digitally signed when
 * signing is set up.
 *
 * @param array     $data     nias_cert_build_data() output
 * @param int|array $template designer template id, a design array (frozen
 *                            snapshot), or 0 for the classic layout
 * @return string
 */
function nias_cert_pdf_bytes($data, $template)
{
    $design = is_array($template) ? $template : ($template ? nias_cert_get_design($template) : null);
    if ($design) {
        $bytes = nias_cert_render_design($design, $data)->Output('', 'S');
    } else {
        $qr   = $data['code'] ? nias_cert_qr_data_uri($data['verify_url']) : '';
        $cert = new CertificateMPDF();
        $cert->createCertificate($data['student_name'], $data['course_name'], $data['date_raw'], $data['code'], $qr);
        $bytes = $cert->output('', 'S');
    }
    if (!empty($data['code']) && nias_cert_signing_active()) {
        $bytes = nias_cert_sign_pdf($bytes, $data);
    }
    return $bytes;
}

/** File name of a certificate PDF. */
function nias_cert_pdf_filename($data)
{
    return 'certificate_' . sanitize_file_name($data['student_name']) . '_' . sanitize_file_name($data['code']) . '.pdf';
}

/**
 * Build and send a certificate PDF using the right renderer.
 *
 * @param array     $data     nias_cert_build_data() output
 * @param int|array $template see nias_cert_pdf_bytes()
 * @param string    $dest     I inline, D download, S return string
 * @return string|void
 */
function nias_cert_output_pdf($data, $template, $dest = 'I')
{
    $bytes = nias_cert_pdf_bytes($data, $template);
    if ($dest === 'S') {
        return $bytes;
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    $filename = nias_cert_pdf_filename($data);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . ($dest === 'D' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes; // phpcs:ignore -- binary PDF
}
