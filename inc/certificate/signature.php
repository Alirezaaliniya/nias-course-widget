<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Digital signature of certificate PDFs (امضای دیجیتال).
 *
 * Every PDF is signed with an X.509 certificate as a *certification*
 * signature (DocMDP, P=1): Acrobat / Foxit show "Certified by …" and any
 * later change to the file — even one character — breaks the signature.
 *
 * mPDF cannot sign, so the signature is added as a standard PDF incremental
 * update: a signature dictionary + invisible signature field are appended,
 * the /ByteRange is fixed, and a detached CMS (PKCS#7) signature over those
 * bytes is written into /Contents (SubFilter adbe.pkcs7.detached).
 *
 * Identity: either a self-signed certificate generated here (tamper
 * evidence; viewers show "identity unknown" until it is trusted) or a
 * .p12/.pfx bought from a CA. The private key is stored encrypted
 * (AES-256-GCM, key derived from the site's AUTH salt) in a non-autoloaded
 * option.
 *
 * @package nias-course-widget
 */

define('NIAS_CERT_SIGN_OPTION', 'nias_cert_signing');
define('NIAS_CERT_SIG_BYTES', 16384); // room reserved for the CMS blob (incl. CA chain)

/* -------------------------------------------------------------------------
 * Key storage
 * ---------------------------------------------------------------------- */

function nias_cert_sign_supported()
{
    return function_exists('openssl_pkey_new') && function_exists('openssl_x509_read') && (function_exists('openssl_cms_sign') || function_exists('openssl_pkcs7_sign'));
}

function nias_cert_sign_secret()
{
    return hash('sha256', wp_salt('auth') . '|nias-cert-sign', true);
}

function nias_cert_sign_encrypt($plain)
{
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', nias_cert_sign_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function nias_cert_sign_decrypt($b64)
{
    $raw = base64_decode((string) $b64, true);
    if ($raw === false || strlen($raw) < 29) {
        return false;
    }
    return openssl_decrypt(substr($raw, 28), 'aes-256-gcm', nias_cert_sign_secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
}

/**
 * The signing identity, or null.
 *
 * @return array{cert:string,key:string,chain:string[],source:string}|null
 */
function nias_cert_signing_identity()
{
    static $id = false;
    if ($id !== false) {
        return $id;
    }
    $id  = null;
    $opt = get_option(NIAS_CERT_SIGN_OPTION);
    if (!is_array($opt) || empty($opt['cert']) || empty($opt['key'])) {
        return null;
    }
    $key = nias_cert_sign_decrypt($opt['key']);
    if (!$key) {
        return null; // salts changed: the key can no longer be read
    }
    $id = array(
        'cert'   => $opt['cert'],
        'key'    => $key,
        'chain'  => isset($opt['chain']) ? (array) $opt['chain'] : array(),
        'source' => isset($opt['source']) ? $opt['source'] : 'self',
    );
    return $id;
}

/** Whether new PDFs get signed. */
function nias_cert_signing_active()
{
    static $on = null;
    if ($on === null) {
        $on = get_option('_certificate_sign_pdf') === 'on' && nias_cert_sign_supported() && nias_cert_signing_identity();
        $on = (bool) apply_filters('nias_certificate_sign_pdf', $on);
    }
    return $on;
}

function nias_cert_sign_store($cert_pem, $key_pem, $chain, $source)
{
    update_option(NIAS_CERT_SIGN_OPTION, array(
        'cert'    => $cert_pem,
        'key'     => nias_cert_sign_encrypt($key_pem),
        'chain'   => array_values((array) $chain),
        'source'  => $source,
        'created' => time(),
    ), false);
}

/**
 * Human-readable facts about a PEM certificate.
 *
 * @return array|null
 */
function nias_cert_sign_cert_info($pem)
{
    $p = function_exists('openssl_x509_parse') ? openssl_x509_parse($pem) : false;
    if (!$p) {
        return null;
    }
    $name = function ($dn) {
        foreach (array('CN', 'O', 'emailAddress') as $k) {
            if (!empty($dn[$k])) {
                return is_array($dn[$k]) ? implode(', ', $dn[$k]) : $dn[$k];
            }
        }
        return '';
    };
    $fp = function_exists('openssl_x509_fingerprint') ? openssl_x509_fingerprint($pem, 'sha256') : '';
    return array(
        'subject'     => $name($p['subject']),
        'issuer'      => $name($p['issuer']),
        'self_signed' => $p['subject'] == $p['issuer'],
        'valid_from'  => (int) $p['validFrom_time_t'],
        'valid_to'    => (int) $p['validTo_time_t'],
        'expired'     => (int) $p['validTo_time_t'] < time(),
        'fingerprint' => strtoupper(implode(':', str_split((string) $fp, 2))),
    );
}

/** Minimal openssl.cnf (Windows/PHP builds often ship without one). */
function nias_cert_sign_openssl_config()
{
    $file = trailingslashit(nias_cert_temp_dir()) . 'nias-openssl.cnf';
    $cnf  = "[ req ]\ndistinguished_name = req_dn\nstring_mask = utf8only\nutf8 = yes\n[ req_dn ]\n"
        . "[ v3_sign ]\nbasicConstraints = critical, CA:FALSE\nkeyUsage = critical, digitalSignature, nonRepudiation\n"
        . "extendedKeyUsage = emailProtection, 1.3.6.1.4.1.311.10.3.12\nsubjectKeyIdentifier = hash\n";
    if (!file_exists($file) || file_get_contents($file) !== $cnf) {
        file_put_contents($file, $cnf);
    }
    return $file;
}

/**
 * Create and store a self-signed signing certificate (RSA-2048, SHA-256,
 * 10 years) in the site's name.
 *
 * @return true|WP_Error
 */
function nias_cert_sign_generate_self_signed()
{
    if (!nias_cert_sign_supported()) {
        return new WP_Error('nias_sign', __('The OpenSSL extension is not enabled on the server.', 'nias-course-widget'));
    }
    $cfg = array(
        'config'           => nias_cert_sign_openssl_config(),
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'x509_extensions'  => 'v3_sign',
    );
    $site = trim(wp_strip_all_tags(get_bloginfo('name')));
    $site = $site !== '' ? $site : wp_parse_url(home_url(), PHP_URL_HOST);
    $dn   = array(
        'commonName'       => mb_substr($site, 0, 60, 'UTF-8'),
        'organizationName' => mb_substr($site, 0, 60, 'UTF-8'),
    );
    $email = get_option('admin_email');
    if (is_email($email)) {
        $dn['emailAddress'] = $email;
    }
    $key = openssl_pkey_new($cfg);
    if (!$key) {
        return new WP_Error('nias_sign', __('Creating the key failed: ', 'nias-course-widget') . openssl_error_string());
    }
    $csr = openssl_csr_new($dn, $key, $cfg);
    $x   = $csr ? openssl_csr_sign($csr, null, $key, 3650, $cfg, random_int(1, PHP_INT_MAX)) : false;
    if (!$x || !openssl_x509_export($x, $cert_pem) || !openssl_pkey_export($key, $key_pem, null, $cfg)) {
        return new WP_Error('nias_sign', __('Creating the certificate failed: ', 'nias-course-widget') . openssl_error_string());
    }
    nias_cert_sign_store($cert_pem, $key_pem, array(), 'self');
    return true;
}

/**
 * Import a .p12 / .pfx (certificate + private key + optional CA chain).
 *
 * @return true|WP_Error
 */
function nias_cert_sign_import_p12($data, $password)
{
    $certs = array();
    if (!openssl_pkcs12_read($data, $certs, (string) $password)) {
        $err = openssl_error_string();
        $msg = __('The file or its password is incorrect.', 'nias-course-widget');
        if ($err && stripos($err, 'unsupported') !== false) {
            $msg .= ' ' . __('This file is encrypted with an old algorithm; export it again with OpenSSL 3 (openssl pkcs12 -export …).', 'nias-course-widget');
        }
        return new WP_Error('nias_sign', $msg);
    }
    if (empty($certs['cert']) || empty($certs['pkey'])) {
        return new WP_Error('nias_sign', __('The file does not contain a certificate and private key.', 'nias-course-widget'));
    }
    if (!openssl_x509_check_private_key($certs['cert'], $certs['pkey'])) {
        return new WP_Error('nias_sign', __('The private key does not match the certificate.', 'nias-course-widget'));
    }
    $info = nias_cert_sign_cert_info($certs['cert']);
    if ($info && $info['expired']) {
        return new WP_Error('nias_sign', __('This certificate has expired.', 'nias-course-widget'));
    }
    nias_cert_sign_store($certs['cert'], $certs['pkey'], isset($certs['extracerts']) ? $certs['extracerts'] : array(), 'upload');
    return true;
}

/* -------------------------------------------------------------------------
 * Minimal PDF reader (classic xref tables, as written by mPDF)
 * ---------------------------------------------------------------------- */

/** Dictionary text "<< … >>" starting at $pos, with nesting. */
function nias_pdf_dict_at($pdf, $pos)
{
    $start = strpos($pdf, '<<', $pos);
    if ($start === false) {
        return '';
    }
    $depth = 0;
    $len   = strlen($pdf);
    for ($i = $start; $i < $len - 1; $i++) {
        $two = $pdf[$i] . $pdf[$i + 1];
        if ($two === '<<') {
            $depth++;
            $i++;
        } elseif ($two === '>>') {
            $depth--;
            $i++;
            if ($depth === 0) {
                return substr($pdf, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

/**
 * Parse the cross-reference chain.
 *
 * @return array{offsets:array,trailer:string,startxref:int}|null
 */
function nias_pdf_xref($pdf)
{
    if (!preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $m)) {
        return null;
    }
    $start   = (int) end($m[1]);
    $offsets = array();
    $trailer = '';
    $pos     = $start;
    $guard   = 0;
    while ($pos !== null && $guard++ < 20) {
        if (substr($pdf, $pos, 4) !== 'xref') {
            return null; // xref streams are not used by mPDF
        }
        $tpos = strpos($pdf, 'trailer', $pos);
        if ($tpos === false) {
            return null;
        }
        $num = 0;
        foreach (preg_split('/\r\n|\r|\n/', substr($pdf, $pos + 4, $tpos - $pos - 4)) as $line) {
            $line = trim($line);
            if (preg_match('/^(\d+)\s+(\d+)$/', $line, $s)) {
                $num = (int) $s[1];
            } elseif (preg_match('/^(\d{10})\s+(\d{5})\s+([nf])$/', $line, $e)) {
                if ($e[3] === 'n' && !isset($offsets[$num])) {
                    $offsets[$num] = (int) $e[1]; // newest section wins
                }
                $num++;
            }
        }
        $dict = nias_pdf_dict_at($pdf, $tpos);
        if ($trailer === '') {
            $trailer = $dict;
        }
        $pos = preg_match('/\/Prev\s+(\d+)/', $dict, $p) ? (int) $p[1] : null;
    }
    return array('offsets' => $offsets, 'trailer' => $trailer, 'startxref' => $start);
}

/** Body (dictionary) of object $num. */
function nias_pdf_object_dict($pdf, $offsets, $num)
{
    if (!isset($offsets[$num])) {
        return '';
    }
    $pos = $offsets[$num];
    if (!preg_match('/\G\s*' . (int) $num . '\s+0\s+obj/', $pdf, $m, 0, $pos)) {
        return '';
    }
    return nias_pdf_dict_at($pdf, $pos);
}

/** PDF text string (UTF-16BE hex), safe for any language. */
function nias_pdf_text($s)
{
    return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding((string) $s, 'UTF-16BE', 'UTF-8'))) . '>';
}

/* -------------------------------------------------------------------------
 * Signing
 * ---------------------------------------------------------------------- */

/**
 * Sign a PDF (certification signature). Returns the input unchanged when
 * signing is impossible, so a certificate is never lost because of it.
 *
 * @param string $pdf
 * @param array  $data certificate data (for the reason text)
 * @return string
 */
function nias_cert_sign_pdf($pdf, $data = array())
{
    $id = nias_cert_signing_identity();
    if (!$id) {
        return $pdf;
    }
    try {
        $signed = nias_cert_sign_pdf_raw($pdf, $id, $data);
        return $signed !== '' ? $signed : $pdf;
    } catch (\Throwable $e) {
        error_log('[nias-cert] PDF signing failed: ' . $e->getMessage());
        return $pdf;
    }
}

function nias_cert_sign_pdf_raw($pdf, $id, $data)
{
    $x = nias_pdf_xref($pdf);
    if (!$x || !preg_match('/\/Size\s+(\d+)/', $x['trailer'], $sz) || !preg_match('/\/Root\s+(\d+)\s+0\s+R/', $x['trailer'], $rt)) {
        throw new \RuntimeException('unsupported PDF structure');
    }
    $size    = (int) $sz[1];
    $root    = (int) $rt[1];
    $catalog = nias_pdf_object_dict($pdf, $x['offsets'], $root);
    if ($catalog === '' || strpos($catalog, '/AcroForm') !== false || strpos($catalog, '/Perms') !== false) {
        throw new \RuntimeException('catalog not signable');
    }
    if (!preg_match('/\/Pages\s+(\d+)\s+0\s+R/', $catalog, $pm)) {
        throw new \RuntimeException('no /Pages');
    }
    $pages = nias_pdf_object_dict($pdf, $x['offsets'], (int) $pm[1]);
    if (!preg_match('/\/Kids\s*\[\s*(\d+)\s+0\s+R/', $pages, $km)) {
        throw new \RuntimeException('no page');
    }
    $page_num = (int) $km[1];
    $page     = nias_pdf_object_dict($pdf, $x['offsets'], $page_num);
    if ($page === '') {
        throw new \RuntimeException('page unreadable');
    }

    $sig = $size;
    $fld = $size + 1;

    // New catalog and page (same object numbers = incremental update).
    $new_catalog = substr($catalog, 0, -2) . ' /AcroForm << /Fields [' . $fld . ' 0 R] /SigFlags 3 >> /Perms << /DocMDP ' . $sig . ' 0 R >>'
        . (strpos($catalog, '/Version') === false ? ' /Version /1.7' : '') . ' >>';
    if (preg_match('/\/Annots\s*\[/', $page)) {
        $new_page = preg_replace('/\/Annots\s*\[/', '/Annots [' . $fld . ' 0 R ', $page, 1);
    } elseif (strpos($page, '/Annots') !== false) {
        throw new \RuntimeException('indirect /Annots');
    } else {
        $new_page = substr($page, 0, -2) . ' /Annots [' . $fld . ' 0 R] >>';
    }

    $site    = wp_strip_all_tags(get_bloginfo('name'));
    $info    = nias_cert_sign_cert_info($id['cert']);
    $hexlen  = NIAS_CERT_SIG_BYTES * 2;
    $br_ph   = '/ByteRange [0 0000000000 0000000000 0000000000]';
    $reason  = !empty($data['code']) ? sprintf(__('Certificate issuing %s', 'nias-course-widget'), $data['code']) : __('Certificate issuing', 'nias-course-widget');
    $sig_obj = '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached ' . $br_ph
        . ' /Contents <' . str_repeat('0', $hexlen) . '>'
        . ' /M (D:' . gmdate('YmdHis') . "+00'00')"
        . ' /Name ' . nias_pdf_text($info && $info['subject'] ? $info['subject'] : $site)
        . ' /Reason ' . nias_pdf_text($reason)
        . ' /Location ' . nias_pdf_text(wp_parse_url(home_url(), PHP_URL_HOST))
        . ' /ContactInfo ' . nias_pdf_text(home_url('/'))
        . ' /Reference [ << /Type /SigRef /TransformMethod /DocMDP /TransformParams << /Type /TransformParams /P 1 /V /1.2 >> >> ] >>';
    $fld_obj = '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /F 132 /Rect [0 0 0 0] /P ' . $page_num . ' 0 R /V ' . $sig . ' 0 R >>';

    $out = rtrim($pdf, "\r\n") . "\n";
    $objs = array($root => $new_catalog, $page_num => $new_page, $sig => $sig_obj, $fld => $fld_obj);
    ksort($objs);
    $offs = array();
    foreach ($objs as $n => $body) {
        $offs[$n] = strlen($out);
        $out     .= $n . " 0 obj\n" . $body . "\nendobj\n";
    }

    // xref of the update: one subsection per run of consecutive numbers.
    $xref_pos = strlen($out);
    $out     .= "xref\n";
    $nums     = array_keys($offs);
    for ($i = 0; $i < count($nums);) {
        $j = $i;
        while ($j + 1 < count($nums) && $nums[$j + 1] === $nums[$j] + 1) {
            $j++;
        }
        $out .= $nums[$i] . ' ' . ($j - $i + 1) . "\n";
        for ($k = $i; $k <= $j; $k++) {
            $out .= sprintf("%010d 00000 n \n", $offs[$nums[$k]]);
        }
        $i = $j + 1;
    }
    $trailer = '<< /Size ' . ($size + 2) . ' /Root ' . $root . ' 0 R';
    if (preg_match('/\/Info\s+(\d+\s+0\s+R)/', $x['trailer'], $im)) {
        $trailer .= ' /Info ' . $im[1];
    }
    if (preg_match('/\/ID\s*\[[^\]]*\]/', $x['trailer'], $idm)) {
        $trailer .= ' ' . $idm[0];
    }
    $trailer .= ' /Prev ' . $x['startxref'] . ' >>';
    $out     .= "trailer\n" . $trailer . "\nstartxref\n" . $xref_pos . "\n%%EOF\n";

    // Byte range = everything except the /Contents hex string (incl. < >).
    $cpos = strpos($out, '/Contents <', $offs[$sig]);
    $c0   = $cpos + 10;               // position of '<'
    $c1   = $c0 + $hexlen + 2;        // just after '>'
    $br   = sprintf('/ByteRange [0 %d %d %d]', $c0, $c1, strlen($out) - $c1);
    $br   = str_pad($br, strlen($br_ph), ' ');
    $out  = substr_replace($out, $br, strpos($out, $br_ph, $offs[$sig]), strlen($br_ph));

    $der = nias_cert_cms_sign(substr($out, 0, $c0) . substr($out, $c1), $id);
    $hex = bin2hex($der);
    if (strlen($hex) > $hexlen) {
        throw new \RuntimeException('signature larger than reserved space');
    }
    return substr_replace($out, str_pad($hex, $hexlen, '0'), $c0 + 1, $hexlen);
}

/**
 * Detached CMS signature (DER) over $content.
 *
 * @return string
 */
function nias_cert_cms_sign($content, $id)
{
    $dir   = trailingslashit(nias_cert_temp_dir());
    $tag   = wp_generate_password(12, false);
    $in    = $dir . 'sig-in-' . $tag;
    $outf  = $dir . 'sig-out-' . $tag;
    $chain = '';
    file_put_contents($in, $content);
    if ($id['chain']) {
        $chain = $dir . 'sig-chain-' . $tag;
        file_put_contents($chain, implode("\n", $id['chain']));
    }
    try {
        if (function_exists('openssl_cms_sign')) {
            $ok = openssl_cms_sign($in, $outf, $id['cert'], $id['key'], array(), OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, $chain !== '' ? $chain : null);
            if (!$ok) {
                throw new \RuntimeException('openssl_cms_sign: ' . openssl_error_string());
            }
            return (string) file_get_contents($outf);
        }
        // PHP 7.x: S/MIME output, take the base64 signature part.
        $ok = $chain !== ''
            ? openssl_pkcs7_sign($in, $outf, $id['cert'], $id['key'], array(), PKCS7_BINARY | PKCS7_DETACHED, $chain)
            : openssl_pkcs7_sign($in, $outf, $id['cert'], $id['key'], array(), PKCS7_BINARY | PKCS7_DETACHED);
        if (!$ok) {
            throw new \RuntimeException('openssl_pkcs7_sign: ' . openssl_error_string());
        }
        $smime = (string) file_get_contents($outf);
        if (!preg_match('/Content-Type: application\/(?:x-)?pkcs7-signature[^\r\n]*\r?\n(?:[^\r\n]+\r?\n)*\r?\n([A-Za-z0-9+\/=\r\n]+)/', $smime, $m)) {
            throw new \RuntimeException('no signature part');
        }
        return base64_decode(preg_replace('/\s+/', '', $m[1]));
    } finally {
        foreach (array($in, $outf, $chain) as $f) {
            if ($f !== '' && file_exists($f)) {
                @unlink($f);
            }
        }
    }
}

/* -------------------------------------------------------------------------
 * Admin: settings card + actions
 * ---------------------------------------------------------------------- */

add_action('admin_post_nias_cert_sign', 'nias_cert_handle_sign_action');
function nias_cert_handle_sign_action()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have the required permission.', 'nias-course-widget'));
    }
    check_admin_referer('nias_cert_sign');
    $do   = isset($_REQUEST['do']) ? sanitize_key(wp_unslash($_REQUEST['do'])) : '';
    $back = admin_url('admin.php?page=nias-course-certificate#nias-cert-sign');
    $res  = true;

    switch ($do) {
        case 'generate':
            $res = nias_cert_sign_generate_self_signed();
            if ($res === true) {
                update_option('_certificate_sign_pdf', 'on');
            }
            break;
        case 'upload':
            if (empty($_FILES['p12']['tmp_name']) || !is_uploaded_file($_FILES['p12']['tmp_name'])) {
                $res = new WP_Error('nias_sign', __('No file selected.', 'nias-course-widget'));
                break;
            }
            $res = nias_cert_sign_import_p12(file_get_contents($_FILES['p12']['tmp_name']), isset($_POST['p12_pass']) ? wp_unslash($_POST['p12_pass']) : ''); // phpcs:ignore -- password, not text
            if ($res === true) {
                update_option('_certificate_sign_pdf', 'on');
            }
            break;
        case 'enable':
        case 'disable':
            update_option('_certificate_sign_pdf', $do === 'enable' ? 'on' : 'off');
            break;
        case 'remove':
            delete_option(NIAS_CERT_SIGN_OPTION);
            update_option('_certificate_sign_pdf', 'off');
            break;
        case 'download':
            $id = nias_cert_signing_identity();
            if ($id) {
                nocache_headers();
                header('Content-Type: application/x-x509-ca-cert');
                header('Content-Disposition: attachment; filename="certificate-signing.cer"');
                echo $id['cert']; // phpcs:ignore -- PEM
                exit;
            }
            break;
    }
    $back = is_wp_error($res)
        ? add_query_arg(array('nias_sign_err' => rawurlencode($res->get_error_message())), $back)
        : add_query_arg('nias_sign_ok', $do, $back);
    wp_safe_redirect($back);
    exit;
}

/** Settings card (rendered outside the main settings form). */
function nias_cert_sign_settings_card()
{
    $id     = nias_cert_signing_identity();
    $info   = $id ? nias_cert_sign_cert_info($id['cert']) : null;
    $active = nias_cert_signing_active();
    $url    = function ($do) {
        return wp_nonce_url(admin_url('admin-post.php?action=nias_cert_sign&do=' . $do), 'nias_cert_sign');
    };
    $date   = function ($ts) {
        return nias_fa_digits(nias_cert_jalali(gmdate('Y-m-d', $ts), 'long'));
    };
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><rect x="9" y="10" width="6" height="5" rx="1" stroke="#3858e9" stroke-width="1.6"/><path d="M10 10V8.5a2 2 0 0 1 4 0V10" stroke="#3858e9" stroke-width="1.6"/></svg>', __('PDF digital signature', 'nias-course-widget'));
    ?>
    <div class="nias-row-block" id="nias-cert-sign">
        <?php if (isset($_GET['nias_sign_err'])) : ?>
            <div class="nias-sign-msg bad"><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['nias_sign_err']))); ?></div>
        <?php elseif (isset($_GET['nias_sign_ok'])) : ?>
            <div class="nias-sign-msg ok"><?php echo esc_html__('Done.', 'nias-course-widget'); ?></div>
        <?php endif; ?>
        <div class="nias-row-desc"><?php echo esc_html__('Every certificate PDF is signed (certified) with an X.509 certificate. Acrobat and Foxit show the issuer\'s name and declare the signature invalid at the smallest change to the file; so forging or editing a certificate can be detected. With signing active, the PDF password lock is not used because the signature itself protects the file.', 'nias-course-widget'); ?></div>

        <?php if (!nias_cert_sign_supported()) : ?>
            <div class="nias-sign-msg bad"><?php echo esc_html__('The OpenSSL extension is not enabled in this server\'s PHP; ask your host to enable it.', 'nias-course-widget'); ?></div>
        <?php elseif ($info) : ?>
            <div class="nias-sign-box">
                <div><span><?php echo esc_html__('Status', 'nias-course-widget'); ?></span><b class="<?php echo $active ? 'ok' : 'off'; ?>"><?php echo $active ? esc_html__('Active — new certificates are signed', 'nias-course-widget') : esc_html__('Inactive', 'nias-course-widget'); ?></b></div>
                <div><span><?php echo esc_html__('Signer', 'nias-course-widget'); ?></span><b><?php echo esc_html($info['subject']); ?></b></div>
                <div><span><?php echo esc_html__('Certificate issuer', 'nias-course-widget'); ?></span><b><?php echo $info['self_signed'] ? esc_html__('Self-signed (created on this site)', 'nias-course-widget') : esc_html($info['issuer']); ?></b></div>
                <div><span><?php echo esc_html__('Valid until', 'nias-course-widget'); ?></span><b class="<?php echo $info['expired'] ? 'bad' : ''; ?>"><?php echo esc_html($date($info['valid_to'])); ?></b></div>
                <div><span><?php echo esc_html__('SHA-256 fingerprint', 'nias-course-widget'); ?></span><code dir="ltr"><?php echo esc_html($info['fingerprint']); ?></code></div>
            </div>
            <?php if ($info['self_signed']) : ?>
                <div class="nias-row-desc" style="margin-top:10px"><?php echo esc_html__('A self-signed certificate reveals any change to the file, but Acrobat shows the identity as "unknown" until you mark it as "trusted". To show the green check without any setup, get a "Document Signing" certificate from a trusted authority (AATL member) and upload its .p12 file.', 'nias-course-widget'); ?></div>
            <?php endif; ?>
            <div class="nias-sign-actions">
                <?php if ($active) : ?>
                    <a class="nias-btn-soft" href="<?php echo esc_url($url('disable')); ?>"><?php echo esc_html__('Stop signing', 'nias-course-widget'); ?></a>
                <?php else : ?>
                    <a class="nias-btn-soft" href="<?php echo esc_url($url('enable')); ?>"><?php echo esc_html__('Enable signing', 'nias-course-widget'); ?></a>
                <?php endif; ?>
                <a class="nias-btn-soft" href="<?php echo esc_url($url('download')); ?>"><?php echo esc_html__('Download public certificate (.cer)', 'nias-course-widget'); ?></a>
                <a class="nias-btn-del" href="<?php echo esc_url($url('remove')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete the signing certificate and key permanently? Certificates that were already signed stay valid.', 'nias-course-widget')); ?>')"><?php echo esc_html__('Delete certificate', 'nias-course-widget'); ?></a>
            </div>
        <?php else : ?>
            <div class="nias-sign-actions">
                <a class="nias-btn-soft" href="<?php echo esc_url($url('generate')); ?>"><?php echo esc_html__('Create a self-signed certificate and enable', 'nias-course-widget'); ?></a>
            </div>
        <?php endif; ?>

        <?php if (nias_cert_sign_supported()) : ?>
            <form class="nias-sign-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="nias_cert_sign">
                <input type="hidden" name="do" value="upload">
                <?php wp_nonce_field('nias_cert_sign'); ?>
                <div class="nias-flabel"><?php echo $info ? esc_html__('Replace with a purchased certificate (.p12 / .pfx)', 'nias-course-widget') : esc_html__('Or upload a purchased certificate (.p12 / .pfx)', 'nias-course-widget'); ?></div>
                <div class="nias-sign-upload-row">
                    <input type="file" name="p12" accept=".p12,.pfx,application/x-pkcs12" required>
                    <input type="password" class="nias-input" name="p12_pass" autocomplete="new-password" placeholder="<?php echo esc_attr__('File password', 'nias-course-widget'); ?>">
                    <button type="submit" class="nias-btn-soft"><?php echo esc_html__('Upload', 'nias-course-widget'); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <style>
    .nias-sign-msg{padding:10px 14px;border-radius:10px;margin-bottom:12px;font-size:13px}
    .nias-sign-msg.ok{background:#ecfbf1;color:#146c3a}.nias-sign-msg.bad{background:#fff1f1;color:#a1161c}
    .nias-sign-box{margin-top:14px;border:1px solid #e3e7ef;border-radius:12px;overflow:hidden}
    .nias-sign-box>div{display:flex;justify-content:space-between;gap:12px;padding:9px 14px;border-top:1px solid #f0f2f6;font-size:13px;flex-wrap:wrap}
    .nias-sign-box>div:first-child{border-top:0}
    .nias-sign-box span{color:#7b8497}.nias-sign-box b.ok{color:#0f7a41}.nias-sign-box b.off{color:#7b8497}.nias-sign-box b.bad{color:#c0262d}
    .nias-sign-box code{font-size:11px;word-break:break-all;background:#f3f4f7;padding:2px 6px;border-radius:6px}
    .nias-sign-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
    .nias-sign-actions a{text-decoration:none;display:inline-flex;align-items:center}
    .nias-sign-upload{margin-top:18px;padding-top:14px;border-top:1px dashed #e3e7ef}
    .nias-sign-upload-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
    .nias-sign-upload-row .nias-input{max-width:200px}
    </style>
    <?php
    nias_set_card_close();
}
