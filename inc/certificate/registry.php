<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate registry (ثبت مرکزی مدارک).
 *
 * Every issued certificate gets one row in {prefix}nias_certificates, keyed by
 * its code. The code itself still lives in the legacy user meta
 * (certificate_code_{product_id} / usercertificate_code) so older links, QR
 * codes and third-party code that read those keys keep working; the table adds
 * status (valid / pending / revoked), lookup by code alone and reporting.
 *
 * Legacy codes that predate the table are imported lazily the first time they
 * are looked up, or in bulk from the "مدارک صادرشده" admin page.
 *
 * @package nias-course-widget
 */

define('NIAS_CERT_DB_VERSION', '2');

/* -------------------------------------------------------------------------
 * Settings helper
 * ---------------------------------------------------------------------- */

/**
 * Read a certificate option, falling back to $default when never saved.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function nias_cert_setting($key, $default = '')
{
    if (!function_exists('carbon_get_theme_option')) {
        return $default;
    }
    $val = carbon_get_theme_option($key);
    if ($val === '' || $val === false || $val === null) {
        return $default;
    }
    return $val;
}

/** Whether the certificate feature is switched on. */
function nias_cert_feature_on()
{
    return nias_cert_setting('nias_course_certificate') === 'on';
}

/* -------------------------------------------------------------------------
 * Table
 * ---------------------------------------------------------------------- */

function nias_cert_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_certificates';
}

add_action('plugins_loaded', 'nias_cert_maybe_install_table');
function nias_cert_maybe_install_table()
{
    if (get_option('nias_cert_db_version') === NIAS_CERT_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = nias_cert_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        code varchar(64) NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        product_id bigint(20) unsigned NOT NULL DEFAULT 0,
        template_id bigint(20) unsigned NOT NULL DEFAULT 0,
        student_name varchar(255) NOT NULL DEFAULT '',
        course_name varchar(255) NOT NULL DEFAULT '',
        issued_at datetime NULL DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'valid',
        note text NULL,
        design_ref varchar(40) NOT NULL DEFAULT '',
        emailed_at datetime NULL DEFAULT NULL,
        updated_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY code (code),
        KEY user_product (user_id,product_id),
        KEY status (status)
    ) {$charset};");
    // Frozen designs, shared by every certificate issued from the same
    // version of a template (keyed by content hash, so 10 000 certificates
    // from one template store the design once).
    dbDelta("CREATE TABLE " . nias_cert_designs_table() . " (
        hash char(40) NOT NULL,
        template_id bigint(20) unsigned NOT NULL DEFAULT 0,
        design longtext NOT NULL,
        created_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (hash)
    ) {$charset};");
    dbDelta("CREATE TABLE " . nias_cert_log_table() . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        cert_id bigint(20) unsigned NOT NULL DEFAULT 0,
        code varchar(64) NOT NULL DEFAULT '',
        result varchar(12) NOT NULL DEFAULT '',
        source varchar(10) NOT NULL DEFAULT 'web',
        visitor char(16) NOT NULL DEFAULT '',
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        agent varchar(190) NOT NULL DEFAULT '',
        referer varchar(190) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY cert_id (cert_id),
        KEY created_at (created_at)
    ) {$charset};");
    update_option('nias_cert_db_version', NIAS_CERT_DB_VERSION);
}

function nias_cert_designs_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_cert_designs';
}

function nias_cert_log_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_cert_verify_log';
}

/* -------------------------------------------------------------------------
 * Frozen designs
 * ---------------------------------------------------------------------- */

/** Whether issued certificates keep the design they were issued with. */
function nias_cert_freeze_on()
{
    return nias_cert_setting('certificate_freeze_design', 'on') === 'on';
}

/**
 * Freeze the design a product currently issues with and return its
 * reference: 'classic', or the sha1 of the stored design JSON.
 *
 * @param int $product_id
 * @return string
 */
function nias_cert_freeze_design_for($product_id)
{
    $tpl    = nias_cert_template_for($product_id);
    $design = $tpl ? nias_cert_get_design($tpl) : null;
    if (!$design) {
        return 'classic';
    }
    global $wpdb;
    $json = wp_json_encode($design, JSON_UNESCAPED_UNICODE);
    $hash = sha1($json);
    $wpdb->query($wpdb->prepare(
        'INSERT IGNORE INTO ' . nias_cert_designs_table() . ' (hash, template_id, design, created_at) VALUES (%s, %d, %s, %s)',
        $hash,
        $tpl,
        $json,
        current_time('mysql')
    ));
    return $hash;
}

/**
 * What a registry row renders with: a design array, or 0 for the classic
 * layout. Rows issued before 1.5.1 (no reference) or with freezing turned
 * off follow the product's current template.
 *
 * @param array $row
 * @return array|int
 */
function nias_cert_design_for_row($row)
{
    $ref = isset($row['design_ref']) ? (string) $row['design_ref'] : '';
    if ($ref !== '' && nias_cert_freeze_on()) {
        if ($ref === 'classic') {
            return 0;
        }
        static $cache = array();
        if (!array_key_exists($ref, $cache)) {
            global $wpdb;
            $json        = $wpdb->get_var($wpdb->prepare('SELECT design FROM ' . nias_cert_designs_table() . ' WHERE hash = %s', $ref));
            $decoded     = $json ? json_decode($json, true) : null;
            $cache[$ref] = is_array($decoded) ? nias_cert_sanitize_design($decoded) : null;
        }
        if ($cache[$ref]) {
            return $cache[$ref];
        }
    }
    $tpl = nias_cert_template_for((int) $row['product_id']);
    return $tpl ? $tpl : 0;
}

/** Point a certificate at the product's current template (admin action). */
function nias_cert_refreeze($id)
{
    global $wpdb;
    $row = nias_cert_get($id);
    if (!$row) {
        return false;
    }
    $ref = nias_cert_freeze_design_for((int) $row['product_id']);
    return $wpdb->update(nias_cert_table(), array('design_ref' => $ref, 'template_id' => nias_cert_template_for((int) $row['product_id']), 'updated_at' => current_time('mysql')), array('id' => (int) $id)) !== false;
}

/* -------------------------------------------------------------------------
 * Names
 * ---------------------------------------------------------------------- */

/**
 * The name printed on a certificate: an explicit override
 * (nias_certificate_name), then first + last name, then display name / login.
 *
 * @param int $user_id
 * @return string
 */
function nias_cert_student_name($user_id)
{
    $override = trim((string) get_user_meta($user_id, 'nias_certificate_name', true));
    if ($override !== '') {
        return $override;
    }
    $full = trim(trim((string) get_user_meta($user_id, 'first_name', true)) . ' ' . trim((string) get_user_meta($user_id, 'last_name', true)));
    if ($full !== '') {
        return $full;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return '';
    }
    return $user->display_name ? $user->display_name : $user->user_login;
}

/** Whether the user has a real name on file (override or first/last). */
function nias_cert_user_has_real_name($user_id)
{
    if (trim((string) get_user_meta($user_id, 'nias_certificate_name', true)) !== '') {
        return true;
    }
    return trim((string) get_user_meta($user_id, 'first_name', true)) !== ''
        && trim((string) get_user_meta($user_id, 'last_name', true)) !== '';
}

/* -------------------------------------------------------------------------
 * Read
 * ---------------------------------------------------------------------- */

/**
 * Normalize a submitted code: Persian/Arabic digits → Latin, dash variants →
 * "-", upper-case, and drop anything that cannot be part of a code (spaces,
 * zero-width characters pasted from PDFs, etc.).
 */
function nias_cert_normalize_code($code)
{
    $code = sanitize_text_field((string) $code);
    $code = strtr($code, array(
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '–' => '-', '—' => '-', '−' => '-', 'ـ' => '-',
    ));
    $code = strtoupper(preg_replace('/[^A-Za-z0-9\-_]/', '', $code));
    return substr($code, 0, 64);
}

/** Whether a code is already used (registry or legacy user meta). */
function nias_cert_code_exists($code)
{
    global $wpdb;
    $code = nias_cert_normalize_code($code);
    if ($code === '') {
        return false;
    }
    if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . nias_cert_table() . ' WHERE code = %s LIMIT 1', $code))) {
        return true;
    }
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_value = %s AND (meta_key LIKE %s OR meta_key = 'usercertificate_code') LIMIT 1",
        $code,
        $wpdb->esc_like('certificate_code_') . '%'
    ));
}

/** $n characters from $alphabet using a CSPRNG. */
function nias_cert_random_chars($alphabet, $n)
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $n; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** @return array|null */
function nias_cert_get($id)
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . nias_cert_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $row ? $row : null;
}

/** @return array|null */
function nias_cert_get_for($user_id, $product_id)
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . nias_cert_table() . ' WHERE user_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1',
        (int) $user_id,
        (int) $product_id
    ), ARRAY_A);
    return $row ? $row : null;
}

/**
 * Find a certificate by code. Falls back to the legacy user-meta codes and
 * imports a match into the registry on the fly.
 *
 * @param string $code
 * @return array|null
 */
function nias_cert_get_by_code($code)
{
    global $wpdb;
    $code = nias_cert_normalize_code($code);
    if ($code === '') {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . nias_cert_table() . ' WHERE code = %s', $code), ARRAY_A);
    if ($row) {
        return $row;
    }

    // Legacy: per-course codes, then the old single per-user code.
    $meta = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id, meta_key FROM {$wpdb->usermeta}
         WHERE meta_value = %s AND (meta_key LIKE %s OR meta_key = 'usercertificate_code')
         ORDER BY umeta_id ASC LIMIT 1",
        $code,
        $wpdb->esc_like('certificate_code_') . '%'
    ));
    if (!$meta) {
        return null;
    }
    $user_id    = (int) $meta->user_id;
    $product_id = strpos($meta->meta_key, 'certificate_code_') === 0 ? (int) substr($meta->meta_key, 17) : 0;
    if (!get_userdata($user_id)) {
        return null;
    }
    return nias_cert_import_legacy($user_id, $product_id, $code);
}

/**
 * Create a registry row for a legacy (pre-registry) code.
 *
 * Old versions auto-assigned the single per-user code (usercertificate_code)
 * to every user listed on the Users screen, even without a purchase. Such a
 * code is only imported when the user really bought a certificate course,
 * so it can never verify as "valid" on its own.
 *
 * @return array|null
 */
function nias_cert_import_legacy($user_id, $product_id, $code)
{
    if (!$product_id && function_exists('has_user_purchased_certificate') && !has_user_purchased_certificate($user_id)) {
        return null;
    }
    $course = '';
    if ($product_id) {
        $course = get_the_title($product_id);
    } else {
        $course = (string) get_user_meta($user_id, 'usercertificate_course', true);
    }
    $issued = $product_id ? nias_cert_find_order_date($user_id, $product_id) : '';
    return nias_cert_upsert($user_id, $product_id, array(
        'code'        => $code,
        'course_name' => $course,
        'issued_at'   => $issued ? $issued . ' 00:00:00' : current_time('mysql'),
        'status'      => 'valid',
        'note'        => 'legacy',
    ));
}

/**
 * Date (Y-m-d) of the user's first completed/processing order containing a product.
 *
 * @return string
 */
function nias_cert_find_order_date($user_id, $product_id)
{
    if (!function_exists('wc_get_orders')) {
        return '';
    }
    $orders = wc_get_orders(array(
        'customer_id' => (int) $user_id,
        'status'      => array('completed', 'processing'),
        'limit'       => -1,
        'orderby'     => 'date',
        'order'       => 'ASC',
    ));
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            if ((int) $item->get_product_id() === (int) $product_id || (int) $item->get_variation_id() === (int) $product_id) {
                $d = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
                return $d ? $d->date('Y-m-d') : '';
            }
        }
    }
    return '';
}

/* -------------------------------------------------------------------------
 * Write
 * ---------------------------------------------------------------------- */

/**
 * Insert or update the registry row for (user, product).
 *
 * On insert, $args['code'] is required. On update only the provided keys are
 * changed, and an existing status is kept unless 'status' is passed.
 *
 * @param int   $user_id
 * @param int   $product_id
 * @param array $args code, student_name, course_name, issued_at, template_id, status, note
 * @return array|null the stored row
 */
function nias_cert_upsert($user_id, $product_id, $args)
{
    global $wpdb;
    $table    = nias_cert_table();
    $existing = !empty($args['code'])
        ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code = %s", nias_cert_normalize_code($args['code'])), ARRAY_A)
        : null;
    if (!$existing) {
        $existing = nias_cert_get_for($user_id, $product_id);
    }

    $fields = array();
    foreach (array('student_name', 'course_name', 'issued_at', 'status', 'note', 'design_ref') as $k) {
        if (array_key_exists($k, $args)) {
            $fields[$k] = (string) $args[$k];
        }
    }
    if (array_key_exists('template_id', $args)) {
        $fields['template_id'] = (int) $args['template_id'];
    }
    if (!isset($fields['student_name']) && !$existing) {
        $fields['student_name'] = nias_cert_student_name($user_id);
    }
    $fields['updated_at'] = current_time('mysql');

    if ($existing) {
        // The user meta holds the authoritative code; follow it if an admin
        // changed it on the profile screen.
        if (!empty($args['code']) && nias_cert_normalize_code($args['code']) !== $existing['code']) {
            $fields['code'] = nias_cert_normalize_code($args['code']);
        }
        // Never silently resurrect a revoked certificate or re-open an
        // approved one; status changes go through nias_cert_set_status().
        unset($fields['status']);
        $wpdb->update($table, $fields, array('id' => (int) $existing['id']));
        return nias_cert_get((int) $existing['id']);
    }

    if (empty($args['code'])) {
        return null;
    }
    $fields['code']       = nias_cert_normalize_code($args['code']);
    $fields['user_id']    = (int) $user_id;
    $fields['product_id'] = (int) $product_id;
    if (empty($fields['issued_at'])) {
        $fields['issued_at'] = current_time('mysql');
    }
    if (empty($fields['status'])) {
        $fields['status'] = 'valid';
    }
    $ok = $wpdb->insert($table, $fields);
    if (!$ok) {
        return null;
    }
    $row = nias_cert_get((int) $wpdb->insert_id);
    do_action('nias_certificate_issued', $row);
    return $row;
}

/**
 * Change a certificate's status (valid / pending / revoked).
 *
 * @return bool
 */
function nias_cert_set_status($id, $status, $note = null)
{
    global $wpdb;
    if (!in_array($status, array('valid', 'pending', 'revoked'), true)) {
        return false;
    }
    $fields = array('status' => $status, 'updated_at' => current_time('mysql'));
    if ($note !== null) {
        $fields['note'] = sanitize_textarea_field($note);
    }
    $done = $wpdb->update(nias_cert_table(), $fields, array('id' => (int) $id)) !== false;
    if ($done) {
        do_action('nias_certificate_status_changed', (int) $id, $status);
    }
    return $done;
}

/** Human label for a status. */
function nias_cert_status_label($status)
{
    $map = array(
        'valid'   => __('معتبر', 'nias-course-widget'),
        'pending' => __('در انتظار تأیید', 'nias-course-widget'),
        'revoked' => __('باطل‌شده', 'nias-course-widget'),
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

/* -------------------------------------------------------------------------
 * Bulk import of legacy codes
 * ---------------------------------------------------------------------- */

/**
 * Import legacy codes that are not in the registry yet.
 *
 * @param int $limit max rows per run
 * @return int number of imported certificates
 */
function nias_cert_backfill($limit = 300)
{
    global $wpdb;
    $table = nias_cert_table();
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT um.user_id, um.meta_key, um.meta_value FROM {$wpdb->usermeta} um
         LEFT JOIN {$table} c ON c.code = um.meta_value
         WHERE (um.meta_key LIKE %s OR um.meta_key = 'usercertificate_code')
           AND um.meta_value <> '' AND c.id IS NULL
         LIMIT %d",
        $wpdb->esc_like('certificate_code_') . '%',
        (int) $limit
    ));
    $count = 0;
    foreach ((array) $rows as $r) {
        $product_id = strpos($r->meta_key, 'certificate_code_') === 0 ? (int) substr($r->meta_key, 17) : 0;
        if (nias_cert_import_legacy((int) $r->user_id, $product_id, $r->meta_value)) {
            $count++;
        }
    }
    return $count;
}

/* -------------------------------------------------------------------------
 * URLs
 * ---------------------------------------------------------------------- */

/**
 * The URL printed in a certificate's QR code.
 *
 * Always code-only (?cert_code=CODE): the user id is no longer exposed, so
 * the link cannot be used to enumerate accounts. It points to the
 * verification page when one is configured, otherwise to the certificate
 * display page — both [nias_certificate_verify] and [nias_certificate]
 * answer ?cert_code=. Legacy ?code=&user_id= links printed on older
 * certificates keep working.
 *
 * @param string $code
 * @param int    $user_id unused, kept for backward compatibility
 * @return string
 */
function nias_cert_verify_url($code, $user_id = 0)
{
    $code = rawurlencode(nias_cert_normalize_code($code));
    $base = nias_cert_verify_base_page();
    // No page configured: the plugin's own built-in verification page, so a
    // printed QR code never leads to a 404.
    $url = $base ? add_query_arg('cert_code', $code, $base) : add_query_arg(NIAS_CERT_VERIFY_QV, $code, home_url('/'));
    return apply_filters('nias_certificate_verify_url', $url, $code, $user_id);
}

define('NIAS_CERT_VERIFY_QV', 'nias_verify');

/** Permalink of the configured verification page (or display page), or ''. */
function nias_cert_verify_base_page()
{
    foreach (array('certificate_verify_page', 'certificate_display_page') as $key) {
        $id = (int) nias_cert_setting($key, 0);
        if ($id && get_post_status($id) === 'publish') {
            return get_permalink($id);
        }
    }
    return '';
}

/**
 * Everything needed to render the PDF of a registry row.
 *
 * @param array $row
 * @return array nias_cert_build_data() output
 */
function nias_cert_row_data($row)
{
    $pid  = (int) $row['product_id'];
    $date = substr((string) $row['issued_at'], 0, 10);
    if ($pid) {
        $date = nias_certificate_resolve_date((int) $row['user_id'], $pid, $date);
    }
    $data = nias_cert_build_data((int) $row['user_id'], $pid, $row['code'], $date);
    if ($data['course_name'] === '' && $row['course_name'] !== '') {
        $data['course_name'] = $row['course_name'];
    }
    // The name recorded at issuance wins, so a later profile edit cannot
    // change what an already-issued certificate says.
    if (!empty($row['student_name'])) {
        $data['student_name'] = $row['student_name'];
    }
    return $data;
}

/* -------------------------------------------------------------------------
 * Cleanup when a user is deleted
 * ---------------------------------------------------------------------- */

add_action('deleted_user', 'nias_cert_on_user_deleted');
function nias_cert_on_user_deleted($user_id)
{
    global $wpdb;
    $wpdb->update(
        nias_cert_table(),
        array('status' => 'revoked', 'note' => 'user deleted', 'updated_at' => current_time('mysql')),
        array('user_id' => (int) $user_id)
    );
}
