<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate canvas designer (طراح مدرک).
 *
 * Templates are stored as a private CPT (nias_cert_tpl) holding a JSON design
 * in post meta. The admin page boots a vanilla-JS canvas app
 * (admin/cert-designer.js) that edits that JSON; everything is saved over AJAX.
 *
 * @package nias-course-widget
 */

define('NIAS_CERT_TPL_CPT', 'nias_cert_tpl');
define('NIAS_CERT_TPL_META', '_nias_cert_design');

add_action('init', 'nias_cert_register_tpl_cpt');
function nias_cert_register_tpl_cpt()
{
    register_post_type(NIAS_CERT_TPL_CPT, array(
        'label'           => __('Certificate templates', 'nias-course-widget'),
        'public'          => false,
        'show_ui'         => false,
        'show_in_rest'    => false,
        'rewrite'         => false,
        'query_var'       => false,
        'supports'        => array('title'),
        'capability_type' => 'post',
    ));
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Sanitize a design coming from the client.
 *
 * @param mixed $raw
 * @return array
 */
function nias_cert_sanitize_design($raw)
{
    $raw  = is_array($raw) ? $raw : array();
    $page = isset($raw['page']) && is_array($raw['page']) ? $raw['page'] : array();

    $sizes = nias_cert_page_sizes();
    $out   = array(
        'version'  => 1,
        'page'     => array(
            'size'        => (isset($page['size']) && isset($sizes[$page['size']])) ? $page['size'] : 'A4',
            'orientation' => (isset($page['orientation']) && $page['orientation'] === 'P') ? 'P' : 'L',
            'bgColor'     => sanitize_hex_color(isset($page['bgColor']) ? $page['bgColor'] : '') ?: '#ffffff',
            'bgImage'     => esc_url_raw(isset($page['bgImage']) ? $page['bgImage'] : ''),
        ),
        'elements' => array(),
    );

    $types = array('text', 'image', 'qr', 'barcode', 'rect', 'ellipse', 'line');
    $clamp = function ($v, $min, $max, $def) {
        if (!is_numeric($v)) {
            return $def;
        }
        return round(max($min, min($max, (float) $v)), 2);
    };
    $els = isset($raw['elements']) && is_array($raw['elements']) ? array_slice($raw['elements'], 0, 200) : array();
    foreach ($els as $i => $el) {
        if (!is_array($el) || !isset($el['type']) || !in_array($el['type'], $types, true)) {
            continue;
        }
        $clean = array(
            'id'      => preg_replace('/[^a-zA-Z0-9_\-]/', '', isset($el['id']) ? (string) $el['id'] : '') ?: 'e' . $i,
            'type'    => $el['type'],
            'name'    => sanitize_text_field(isset($el['name']) ? $el['name'] : ''),
            'x'       => $clamp(isset($el['x']) ? $el['x'] : 0, -100, 600, 0),
            'y'       => $clamp(isset($el['y']) ? $el['y'] : 0, -100, 600, 0),
            'w'       => $clamp(isset($el['w']) ? $el['w'] : 50, 0.2, 600, 50),
            'h'       => $clamp(isset($el['h']) ? $el['h'] : 10, 0.2, 600, 10),
            'opacity' => $clamp(isset($el['opacity']) ? $el['opacity'] : 1, 0, 1, 1),
            'locked'  => !empty($el['locked']),
            'hidden'  => !empty($el['hidden']),
        );
        switch ($el['type']) {
            case 'text':
                $clean['text']       = sanitize_textarea_field(isset($el['text']) ? $el['text'] : '');
                $clean['fontSize']   = $clamp(isset($el['fontSize']) ? $el['fontSize'] : 14, 4, 200, 14);
                $clean['lineHeight'] = $clamp(isset($el['lineHeight']) ? $el['lineHeight'] : 1.4, 0.8, 3, 1.4);
                $clean['bold']       = !empty($el['bold']);
                $clean['fit']        = !isset($el['fit']) || !empty($el['fit']);
                $clean['color']      = sanitize_hex_color(isset($el['color']) ? $el['color'] : '') ?: '#000000';
                $clean['bg']         = sanitize_hex_color(isset($el['bg']) ? $el['bg'] : '') ?: '';
                $clean['align']      = (isset($el['align']) && in_array($el['align'], array('left', 'center', 'right', 'justify'), true)) ? $el['align'] : 'center';
                $clean['dir']        = (isset($el['dir']) && $el['dir'] === 'ltr') ? 'ltr' : 'rtl';
                break;
            case 'image':
                $clean['src'] = esc_url_raw(isset($el['src']) ? $el['src'] : '');
                break;
            case 'qr':
                $clean['content'] = (isset($el['content']) && $el['content'] === 'custom') ? 'custom' : 'verify';
                $clean['text']    = sanitize_textarea_field(isset($el['text']) ? $el['text'] : '');
                $clean['color']   = sanitize_hex_color(isset($el['color']) ? $el['color'] : '') ?: '#000000';
                // Templates saved before 1.5 have no bg: keep them white.
                $clean['bg']      = !isset($el['bg']) ? '#ffffff' : (sanitize_hex_color($el['bg']) ?: '');
                break;
            case 'barcode':
                $clean['text']     = sanitize_text_field(isset($el['text']) ? $el['text'] : '{code}');
                $clean['color']    = sanitize_hex_color(isset($el['color']) ? $el['color'] : '') ?: '#000000';
                $clean['bg']       = sanitize_hex_color(isset($el['bg']) ? $el['bg'] : '') ?: '';
                $clean['showText'] = !empty($el['showText']);
                break;
            case 'rect':
            case 'ellipse':
            case 'line':
                $clean['bg']          = sanitize_hex_color(isset($el['bg']) ? $el['bg'] : '') ?: '';
                $clean['borderColor'] = sanitize_hex_color(isset($el['borderColor']) ? $el['borderColor'] : '') ?: '';
                $clean['borderWidth'] = $clamp(isset($el['borderWidth']) ? $el['borderWidth'] : 0, 0, 20, 0);
                $clean['radius']      = $clamp(isset($el['radius']) ? $el['radius'] : 0, 0, 100, 0);
                break;
        }
        $out['elements'][] = $clean;
    }
    return $out;
}

/**
 * Stored design of a template, or null.
 *
 * @param int $id
 * @return array|null
 */
function nias_cert_get_design($id)
{
    $post = get_post((int) $id);
    if (!$post || $post->post_type !== NIAS_CERT_TPL_CPT || $post->post_status === 'trash') {
        return null;
    }
    $json = get_post_meta($post->ID, NIAS_CERT_TPL_META, true);
    $data = is_string($json) ? json_decode($json, true) : $json;
    return is_array($data) ? nias_cert_sanitize_design($data) : null;
}

function nias_cert_store_design($id, $design)
{
    // Stored as JSON (slashed, since update_post_meta unslashes).
    update_post_meta((int) $id, NIAS_CERT_TPL_META, wp_slash(wp_json_encode(nias_cert_sanitize_design($design), JSON_UNESCAPED_UNICODE)));
}

/** @return array<int,string> id => title */
function nias_cert_templates_list()
{
    $out = array();
    foreach (get_posts(array('post_type' => NIAS_CERT_TPL_CPT, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC')) as $p) {
        $out[$p->ID] = $p->post_title !== '' ? $p->post_title : ('#' . $p->ID);
    }
    return $out;
}

/** @return array{id:int,title:string,design:array}|null */
function nias_cert_template_payload($id)
{
    $design = nias_cert_get_design($id);
    if (!$design) {
        return null;
    }
    return array('id' => (int) $id, 'title' => get_the_title($id), 'design' => $design);
}

function nias_cert_create_template($title, $design)
{
    $id = wp_insert_post(array(
        'post_type'   => NIAS_CERT_TPL_CPT,
        'post_status' => 'publish',
        'post_title'  => sanitize_text_field($title),
    ));
    if (is_wp_error($id) || !$id) {
        return 0;
    }
    nias_cert_store_design($id, $design);
    return (int) $id;
}

/* -------------------------------------------------------------------------
 * Presets
 * ---------------------------------------------------------------------- */

/** Preset names (key => label). */
function nias_cert_preset_labels()
{
    return array(
        'classic' => __('Classic (from your current images and texts)', 'nias-course-widget'),
        'modern'  => __('Modern with a color band', 'nias-course-widget'),
        'minimal' => __('Minimal', 'nias-course-widget'),
        'formal'  => __('Formal with QR and barcode', 'nias-course-widget'),
        'blank'   => __('Blank page', 'nias-course-widget'),
    );
}

/**
 * Build a preset design (A4 landscape, 297×210 mm).
 *
 * @param string $key
 * @return array
 */
function nias_cert_preset($key)
{
    $n = 0;
    $t = function ($text, $x, $y, $w, $h, $size, $extra = array()) use (&$n) {
        $n++;
        return array_merge(array('id' => 'e' . $n, 'type' => 'text', 'name' => '', 'text' => $text, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fontSize' => $size, 'bold' => false, 'color' => '#1f2a30', 'align' => 'center', 'lineHeight' => 1.4, 'fit' => true, 'opacity' => 1), $extra);
    };
    $box = function ($type, $x, $y, $w, $h, $extra = array()) use (&$n) {
        $n++;
        return array_merge(array('id' => 'e' . $n, 'type' => $type, 'name' => '', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'opacity' => 1), $extra);
    };
    $page = array('size' => 'A4', 'orientation' => 'L', 'bgColor' => '#ffffff', 'bgImage' => '');
    $el   = array();

    switch ($key) {
        case 'classic':
            $page['bgColor'] = '#fffdf6';
            $el[] = $box('rect', 8, 8, 281, 194, array('name' => 'قاب بیرونی', 'borderColor' => '#c9a84a', 'borderWidth' => 1.2, 'bg' => ''));
            $el[] = $box('rect', 12, 12, 273, 186, array('name' => 'قاب داخلی', 'borderColor' => '#e7d9a8', 'borderWidth' => 0.4, 'bg' => ''));
            $icon = nias_cert_setting('certificate_icon', '') ?: nias_cert_setting('certificate_watermark', '');
            if ($icon) {
                $el[] = $box('image', 133.5, 18, 30, 30, array('name' => 'نماد', 'src' => $icon));
            }
            $el[] = $t(nias_cert_setting('certificate_first_title', 'گواهی پایان دوره'), 40, 52, 217, 16, 30, array('bold' => true, 'name' => 'عنوان'));
            $el[] = $t(nias_cert_setting('certificate_before_name_title', 'این گواهی تأیید می‌کند که'), 40, 74, 217, 9, 14, array('color' => '#6b7280'));
            $el[] = $t('{student_name}', 40, 86, 217, 16, 28, array('bold' => true, 'color' => '#a57f1f', 'name' => 'نام دانشجو'));
            $el[] = $t(nias_cert_setting('certificate_after_name_title', 'با موفقیت دوره زیر را تکمیل نمود:'), 40, 106, 217, 9, 14, array('color' => '#6b7280'));
            $el[] = $t('{course_name}', 40, 118, 217, 14, 20, array('bold' => true, 'name' => 'نام دوره'));
            $el[] = $t('تاریخ صدور: {date}', 103.5, 172, 90, 8, 11, array('color' => '#4b5563', 'name' => 'تاریخ'));
            $el[] = $box('qr', 24, 150, 28, 28, array('name' => 'QR استعلام'));
            $el[] = $t('{code}', 14, 179, 48, 6, 8, array('color' => '#6b7280', 'name' => 'کد مدرک'));
            $sign = nias_cert_setting('certificate_signature_image', '');
            if ($sign) {
                $el[] = $box('image', 222, 146, 48, 24, array('name' => 'امضا', 'src' => $sign));
            }
            $seal = nias_cert_setting('certificate_seal_image', '');
            if ($seal) {
                $el[] = $box('image', 186, 146, 30, 30, array('name' => 'مهر', 'src' => $seal, 'opacity' => 0.9));
            }
            $el[] = $box('line', 218, 172, 56, 0.4, array('name' => 'خط امضا', 'bg' => '#9ca3af'));
            $el[] = $t('{signer_name}', 213, 174, 66, 7, 10, array('color' => '#374151', 'name' => 'امضاکننده'));
            break;

        case 'modern':
            $el[] = $box('rect', 217, 0, 80, 210, array('name' => 'نوار رنگی', 'bg' => '#1e3a8a'));
            $el[] = $box('rect', 211, 0, 3, 210, array('name' => 'نوار باریک', 'bg' => '#f59e0b'));
            $logo = nias_cert_setting('certificate_watermark', '') ?: nias_cert_setting('certificate_icon', '');
            if ($logo) {
                $el[] = $box('image', 237, 22, 40, 40, array('name' => 'لوگو', 'src' => $logo));
            }
            $el[] = $t('{site_name}', 222, 68, 70, 10, 13, array('bold' => true, 'color' => '#ffffff'));
            $el[] = $box('rect', 236.5, 137, 41, 41, array('name' => 'پس‌زمینه QR', 'bg' => '#ffffff', 'radius' => 2));
            $el[] = $box('qr', 239.5, 140, 35, 35, array('name' => 'QR استعلام'));
            $el[] = $t('{code}', 222, 181, 70, 6, 9, array('color' => '#dbeafe'));
            $el[] = $t('CERTIFICATE OF COMPLETION', 20, 26, 180, 8, 11, array('color' => '#f59e0b', 'bold' => true, 'align' => 'right'));
            $el[] = $t('گواهی پایان دوره', 20, 36, 180, 18, 34, array('bold' => true, 'color' => '#1e3a8a', 'align' => 'right', 'name' => 'عنوان'));
            $el[] = $t('این گواهی با افتخار اعطا می‌شود به', 20, 70, 180, 9, 14, array('color' => '#6b7280', 'align' => 'right'));
            $el[] = $t('{student_name}', 20, 82, 180, 18, 32, array('bold' => true, 'color' => '#111827', 'align' => 'right', 'name' => 'نام دانشجو'));
            $el[] = $box('line', 100, 103, 100, 0.8, array('name' => 'خط زیر نام', 'bg' => '#f59e0b'));
            $el[] = $t('بابت گذراندن موفقیت‌آمیز «{course_name}»', 20, 110, 180, 20, 15, array('color' => '#374151', 'align' => 'right', 'name' => 'نام دوره'));
            $el[] = $t("تاریخ صدور\n{date}", 140, 160, 60, 16, 11, array('color' => '#374151', 'align' => 'right'));
            $el[] = $t("مدرس\n{instructor}", 70, 160, 60, 16, 11, array('color' => '#374151', 'align' => 'right'));
            break;

        case 'minimal':
            $el[] = $box('line', 30, 30, 237, 0.3, array('bg' => '#111827'));
            $el[] = $t('{site_name}', 30, 18, 237, 8, 11, array('color' => '#6b7280'));
            $el[] = $t('گواهی پایان دوره', 30, 50, 237, 14, 26, array('bold' => true));
            $el[] = $t('{student_name}', 30, 82, 237, 16, 30, array('bold' => true, 'name' => 'نام دانشجو'));
            $el[] = $t('«{course_name}» را با موفقیت به پایان رسانده است.', 30, 106, 237, 12, 15, array('color' => '#374151'));
            $el[] = $box('line', 30, 178, 237, 0.3, array('bg' => '#111827'));
            $el[] = $t('{date}', 200, 182, 67, 7, 10, array('align' => 'right', 'color' => '#374151'));
            $el[] = $t('{code}', 30, 182, 90, 7, 10, array('align' => 'left', 'color' => '#374151'));
            $el[] = $box('qr', 138.5, 150, 20, 20);
            break;

        case 'formal':
            $page['bgColor'] = '#ffffff';
            $el[] = $box('rect', 6, 6, 285, 198, array('name' => 'قاب بیرونی', 'bg' => '#0f2f57'));
            $el[] = $box('rect', 9, 9, 279, 192, array('name' => 'زمینه', 'bg' => '#fffefa', 'borderColor' => '#b8913a', 'borderWidth' => 0.8));
            $el[] = $box('rect', 13, 13, 271, 184, array('name' => 'قاب داخلی', 'borderColor' => '#e4d3a3', 'borderWidth' => 0.3, 'bg' => ''));
            foreach (array(array(10, 10), array(277, 10), array(10, 190), array(277, 190)) as $i => $c) {
                $el[] = $box('ellipse', $c[0], $c[1], 10, 10, array('name' => 'گوشه ' . ($i + 1), 'bg' => '#b8913a'));
            }
            $logo = nias_cert_setting('certificate_watermark', '') ?: nias_cert_setting('certificate_icon', '');
            if ($logo) {
                $el[] = $box('image', 136, 18, 25, 25, array('name' => 'لوگو', 'src' => $logo));
            }
            $el[] = $t('{site_name}', 40, 45, 217, 8, 12, array('color' => '#b8913a', 'bold' => true));
            $el[] = $t('گواهی‌نامهٔ پایان دوره', 40, 55, 217, 16, 30, array('bold' => true, 'color' => '#0f2f57', 'name' => 'عنوان'));
            $el[] = $t('Certificate of Completion', 40, 71, 217, 7, 11, array('color' => '#8a94a6', 'dir' => 'ltr'));
            $el[] = $t('بدین‌وسیله گواهی می‌شود', 40, 84, 217, 8, 13, array('color' => '#4b5563'));
            $el[] = $t('{student_name}', 40, 93, 217, 16, 28, array('bold' => true, 'color' => '#111827', 'name' => 'نام دانشجو'));
            $el[] = $box('line', 98.5, 110, 100, 0.4, array('name' => 'خط زیر نام', 'bg' => '#b8913a'));
            $el[] = $t('«{course_name}» را با موفقیت به پایان رسانده است.', 40, 114, 217, 14, 15, array('color' => '#374151', 'name' => 'نام دوره'));
            $el[] = $t("تاریخ صدور\n{date}", 205, 150, 65, 15, 11, array('color' => '#374151'));
            $el[] = $box('line', 213, 178, 50, 0.3, array('name' => 'خط امضا', 'bg' => '#9ca3af'));
            $el[] = $t('{signer_name}', 205, 180, 65, 7, 10, array('color' => '#374151', 'name' => 'امضاکننده'));
            $sign = nias_cert_setting('certificate_signature_image', '');
            if ($sign) {
                $el[] = $box('image', 218, 160, 40, 18, array('name' => 'امضا', 'src' => $sign));
            }
            $el[] = $box('qr', 27, 150, 30, 30, array('name' => 'QR استعلام'));
            $el[] = $t('استعلام با اسکن QR', 22, 181, 40, 5, 7, array('color' => '#6b7280'));
            $el[] = $box('barcode', 101, 166, 95, 17, array('name' => 'بارکد', 'text' => '{code}', 'color' => '#111827', 'showText' => true));
            $el[] = $t('اعتبار این مدرک از طریق {site_url} قابل استعلام است.', 70, 186, 157, 5, 7.5, array('color' => '#8a94a6'));
            break;

        case 'blank':
        default:
            break;
    }

    return nias_cert_sanitize_design(array('page' => $page, 'elements' => $el));
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_cert_register_designer_page', 12);
function nias_cert_register_designer_page()
{
    if (!current_user_can('manage_options') || !nias_cert_feature_on()) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('Certificate designer', 'nias-course-widget'),
        __('Certificate designer', 'nias-course-widget'),
        'manage_options',
        'nias-course-cert-designer',
        'nias_cert_render_designer_page'
    );
}

add_action('admin_enqueue_scripts', 'nias_cert_designer_assets');
function nias_cert_designer_assets($hook)
{
    if (strpos($hook, 'nias-course-cert-designer') === false) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_style('nias-cert-designer', NIASADMIN_URL . '/cert-designer.css', array(), NIAS_COURSE_VERSION);
    wp_enqueue_script('nias-cert-designer', NIASADMIN_URL . '/cert-designer.js', array(), NIAS_COURSE_VERSION, true);
}

function nias_cert_render_designer_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $templates = array();
    foreach (nias_cert_templates_list() as $id => $title) {
        $p = nias_cert_template_payload($id);
        if ($p) {
            $templates[] = $p;
        }
    }
    $boot = array(
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('nias_cert_designer'),
        'previewUrl'      => add_query_arg(array('action' => 'nias_cert_preview', '_wpnonce' => wp_create_nonce('nias_cert_preview')), admin_url('admin-post.php')),
        'settingsUrl'     => admin_url('admin.php?page=nias-course-certificate'),
        'templates'       => $templates,
        'defaultTemplate' => (int) nias_cert_setting('certificate_default_template', 0),
        'mode'            => nias_cert_setting('certificate_design_mode', 'classic'),
        'tokens'          => nias_cert_token_labels(),
        'sample'          => nias_cert_sample_data(),
        'presets'         => nias_cert_preset_labels(),
        'pageSizes'       => nias_cert_page_sizes(),
        'fontUrl'         => NIAS_FONTS_URL,
        // Real (transparent) QR of the sample link, used as a mask on the
        // canvas so colour changes preview exactly.
        'sampleQr'        => nias_cert_qr_data_uri(nias_cert_sample_data()['verify_url'], '#000000', ''),
    );
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('designer'); ?>
        <div id="nias-cert-designer" class="ncd-app"><div class="ncd-boot"><?php echo esc_html__('Loading the certificate designer…', 'nias-course-widget'); ?></div></div>
    </div>
    <script>window.NIAS_CERT_DESIGNER = <?php echo wp_json_encode($boot); ?>;</script>
    <?php
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function nias_cert_designer_guard()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have the required permission.', 'nias-course-widget')));
    }
    if (!check_ajax_referer('nias_cert_designer', 'nonce', false)) {
        wp_send_json_error(array('message' => __('Invalid request; reload the page.', 'nias-course-widget')));
    }
}

/** Template id from the request, validated. */
function nias_cert_designer_request_id()
{
    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $post = $id ? get_post($id) : null;
    if (!$post || $post->post_type !== NIAS_CERT_TPL_CPT) {
        wp_send_json_error(array('message' => __('Template not found.', 'nias-course-widget')));
    }
    return $id;
}

add_action('wp_ajax_nias_cert_tpl_create', 'nias_cert_ajax_tpl_create');
function nias_cert_ajax_tpl_create()
{
    nias_cert_designer_guard();
    $preset = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : 'blank';
    $labels = nias_cert_preset_labels();
    if (!isset($labels[$preset])) {
        $preset = 'blank';
    }
    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    if ($title === '') {
        $title = __('Certificate template', 'nias-course-widget') . ' ' . (count(nias_cert_templates_list()) + 1);
    }
    // Import: a design exported from this or another site (sanitized like any save).
    $design = nias_cert_preset($preset);
    if (!empty($_POST['design'])) {
        $imported = json_decode(wp_unslash($_POST['design']), true);
        if (!is_array($imported) || !isset($imported['elements'])) {
            wp_send_json_error(array('message' => __('Invalid template file.', 'nias-course-widget')));
        }
        $design = $imported;
    }
    $id = nias_cert_create_template($title, $design);
    if (!$id) {
        wp_send_json_error(array('message' => __('Creating the template failed.', 'nias-course-widget')));
    }
    // The first template becomes the default automatically.
    if (!(int) nias_cert_setting('certificate_default_template', 0)) {
        update_option('_certificate_default_template', (string) $id);
    }
    wp_send_json_success(array(
        'template'        => nias_cert_template_payload($id),
        'defaultTemplate' => (int) nias_cert_setting('certificate_default_template', 0),
    ));
}

add_action('wp_ajax_nias_cert_tpl_save', 'nias_cert_ajax_tpl_save');
function nias_cert_ajax_tpl_save()
{
    nias_cert_designer_guard();
    $id     = nias_cert_designer_request_id();
    $design = isset($_POST['design']) ? json_decode(wp_unslash($_POST['design']), true) : null;
    if (!is_array($design)) {
        wp_send_json_error(array('message' => __('Invalid template data.', 'nias-course-widget')));
    }
    if (isset($_POST['title'])) {
        $title = sanitize_text_field(wp_unslash($_POST['title']));
        if ($title !== '') {
            wp_update_post(array('ID' => $id, 'post_title' => $title));
        }
    }
    nias_cert_store_design($id, $design);
    wp_send_json_success(array('message' => __('Template saved.', 'nias-course-widget'), 'template' => nias_cert_template_payload($id)));
}

add_action('wp_ajax_nias_cert_tpl_delete', 'nias_cert_ajax_tpl_delete');
function nias_cert_ajax_tpl_delete()
{
    nias_cert_designer_guard();
    $id = nias_cert_designer_request_id();
    wp_delete_post($id, true);
    if ((int) nias_cert_setting('certificate_default_template', 0) === $id) {
        update_option('_certificate_default_template', '');
    }
    wp_send_json_success(array('defaultTemplate' => (int) nias_cert_setting('certificate_default_template', 0)));
}

add_action('wp_ajax_nias_cert_tpl_duplicate', 'nias_cert_ajax_tpl_duplicate');
function nias_cert_ajax_tpl_duplicate()
{
    nias_cert_designer_guard();
    $id  = nias_cert_designer_request_id();
    $new = nias_cert_create_template(get_the_title($id) . __(' (copy)', 'nias-course-widget'), nias_cert_get_design($id));
    if (!$new) {
        wp_send_json_error(array('message' => __('Duplicating failed.', 'nias-course-widget')));
    }
    wp_send_json_success(array('template' => nias_cert_template_payload($new)));
}

add_action('wp_ajax_nias_cert_tpl_default', 'nias_cert_ajax_tpl_default');
function nias_cert_ajax_tpl_default()
{
    nias_cert_designer_guard();
    $id = nias_cert_designer_request_id();
    update_option('_certificate_default_template', (string) $id);
    wp_send_json_success(array('defaultTemplate' => $id));
}

add_action('wp_ajax_nias_cert_set_mode', 'nias_cert_ajax_set_mode');
function nias_cert_ajax_set_mode()
{
    nias_cert_designer_guard();
    $mode = (isset($_POST['mode']) && $_POST['mode'] === 'designer') ? 'designer' : 'classic';
    update_option('_certificate_design_mode', $mode);
    wp_send_json_success(array('mode' => $mode));
}

/* -------------------------------------------------------------------------
 * PDF preview (sample data)
 * ---------------------------------------------------------------------- */

add_action('admin_post_nias_cert_preview', 'nias_cert_handle_preview');
function nias_cert_handle_preview()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have the required permission.', 'nias-course-widget'));
    }
    check_admin_referer('nias_cert_preview');

    // Unsaved design posted from the editor wins over the stored one.
    $design = null;
    if (!empty($_POST['design'])) {
        $design = nias_cert_sanitize_design(json_decode(wp_unslash($_POST['design']), true));
    } elseif (!empty($_GET['tpl'])) {
        $design = nias_cert_get_design((int) $_GET['tpl']);
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    try {
        $data = nias_cert_sample_data();
        if ($design) {
            nias_cert_render_design($design, $data)->Output('certificate-preview.pdf', 'I');
        } else {
            nias_cert_output_pdf($data, 0, 'I'); // classic layout
        }
    } catch (\Throwable $e) {
        wp_die(esc_html__('Error building the preview: ', 'nias-course-widget') . esc_html($e->getMessage()));
    }
    exit;
}
