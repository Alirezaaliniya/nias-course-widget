<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «سهم درآمد مدرس» (instructor revenue share + payouts). WooCommerce only.
 *
 *   - Instructor share %: site default, overridable per instructor (profile)
 *     and per course (product edit screen). Several instructors on a course
 *     split it equally.
 *   - A completed order writes one ledger row per instructor and course line
 *     ({prefix}nias_earnings), available after a hold period (refund window,
 *     default 7 days). Refunded / cancelled orders reverse their rows (a row
 *     already paid out gets a negative adjustment).
 *   - Instructors (panel → «درآمد»): available / on hold / paid, history,
 *     bank details and «درخواست تسویه» (above a minimum). Admins
 *     («درآمد مدرسین» tab): balances and requests → «پرداخت شد» (with a
 *     reference) or «رد».
 *
 * @package nias-course-widget
 */

define('NIAS_REV_DB_VERSION', '1');

function nias_rev_enabled()
{
    return (bool) apply_filters('nias_rev_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_rev_enabled') === 'on' && function_exists('nias_wc_active') && nias_wc_active() && get_option('nias_rev_db_version') === NIAS_REV_DB_VERSION);
}

function nias_rev_opt($k, $def)
{
    $v = function_exists('carbon_get_theme_option') ? carbon_get_theme_option('nias_rev_' . $k) : '';
    return ($v === '' || $v === null) ? $def : $v;
}

function nias_rev_tables()
{
    global $wpdb;
    return array($wpdb->prefix . 'nias_earnings', $wpdb->prefix . 'nias_payouts');
}

add_action('plugins_loaded', function () {
    if (get_option('nias_rev_db_version') === NIAS_REV_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    list($e, $p) = nias_rev_tables();
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$e} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        order_id bigint(20) unsigned NOT NULL,
        item_id bigint(20) unsigned NOT NULL DEFAULT 0,
        course_id bigint(20) unsigned NOT NULL,
        gross decimal(18,2) NOT NULL DEFAULT 0,
        rate decimal(5,2) NOT NULL DEFAULT 0,
        amount decimal(18,2) NOT NULL DEFAULT 0,
        status varchar(12) NOT NULL DEFAULT 'pending',
        payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        available_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY line (order_id,item_id,user_id,status),
        KEY user_status (user_id,status,available_at),
        KEY course (course_id,created)
    ) {$c};");
    dbDelta("CREATE TABLE {$p} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        amount decimal(18,2) NOT NULL DEFAULT 0,
        status varchar(12) NOT NULL DEFAULT 'requested',
        bank varchar(190) NOT NULL DEFAULT '',
        note varchar(255) NOT NULL DEFAULT '',
        created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        processed datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (id),
        KEY status (status,created),
        KEY user_id (user_id,created)
    ) {$c};");
    update_option('nias_rev_db_version', NIAS_REV_DB_VERSION, false);
}, 23);

/* -------------------------------------------------------------------------
 * Rates + ledger
 * ---------------------------------------------------------------------- */

/** Instructor share (%) for a course and instructor. */
function nias_rev_rate($course_id, $user_id)
{
    foreach (array(get_post_meta($course_id, '_nias_rev_rate', true), get_user_meta($user_id, '_nias_rev_rate', true), nias_rev_opt('rate', 70)) as $v) {
        if ($v !== '' && $v !== null && is_numeric($v)) {
            return max(0, min(100, (float) $v));
        }
    }
    return 70.0;
}

/** Record the instructors' share of a completed order (once per line). */
add_action('woocommerce_order_status_completed', 'nias_rev_record_order', 30);
function nias_rev_record_order($order_id)
{
    global $wpdb;
    if (!nias_rev_enabled() || !function_exists('nias_product_instructors')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    list($t) = nias_rev_tables();
    $hold  = max(0, (int) nias_rev_opt('hold', 7));
    $now   = current_time('mysql');
    $avail = wp_date('Y-m-d H:i:s', time() + $hold * DAY_IN_SECONDS);
    foreach ($order->get_items() as $item_id => $item) {
        $pid  = (int) $item->get_product_id();
        $inst = nias_product_instructors($pid);
        $inst = array_values(array_filter($inst, function ($u) use ($order) { return $u !== (int) $order->get_customer_id(); }));
        if (!$pid || !$inst) {
            continue;
        }
        $gross = (float) $item->get_total(); // after discounts, before tax
        if ($gross <= 0) {
            continue;
        }
        foreach ($inst as $uid) {
            $rate = nias_rev_rate($pid, $uid);
            $amt  = round($gross * $rate / 100 / count($inst), 2);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE order_id = %d AND item_id = %d AND user_id = %d AND status <> 'reversed' AND amount > 0", $order_id, $item_id, $uid));
            if ($exists || $amt <= 0) {
                continue;
            }
            $wpdb->insert($t, array('user_id' => $uid, 'order_id' => $order_id, 'item_id' => $item_id, 'course_id' => $pid, 'gross' => $gross, 'rate' => $rate, 'amount' => $amt, 'status' => 'pending', 'created' => $now, 'available_at' => $avail));
            do_action('nias_rev_earned', $uid, $amt, $pid, $order_id);
        }
    }
}

/** Refund / cancel: reverse the order's shares. */
function nias_rev_reverse_order($order_id)
{
    global $wpdb;
    if (!nias_rev_enabled()) {
        return;
    }
    list($t) = nias_rev_tables();
    foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE order_id = %d AND amount > 0 AND status IN ('pending','requested','paid')", $order_id), ARRAY_A) as $r) {
        if ($r['status'] === 'paid') {
            // Already paid out: a negative line comes off the next payout.
            $wpdb->insert($t, array('user_id' => $r['user_id'], 'order_id' => $order_id, 'item_id' => $r['item_id'], 'course_id' => $r['course_id'], 'gross' => -$r['gross'], 'rate' => $r['rate'], 'amount' => -$r['amount'], 'status' => 'pending', 'created' => current_time('mysql'), 'available_at' => current_time('mysql')));
        } else {
            $wpdb->update($t, array('status' => 'reversed', 'payout_id' => 0), array('id' => $r['id']));
        }
    }
}
add_action('woocommerce_order_status_refunded', 'nias_rev_reverse_order');
add_action('woocommerce_order_status_cancelled', 'nias_rev_reverse_order');

/** Balances of an instructor: {available, hold, requested, paid}. */
function nias_rev_balance($user_id)
{
    global $wpdb;
    list($t) = nias_rev_tables();
    $now = current_time('mysql');
    $r   = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(CASE WHEN status = 'pending' AND available_at <= %s THEN amount ELSE 0 END) available,
                SUM(CASE WHEN status = 'pending' AND available_at > %s THEN amount ELSE 0 END) hold,
                SUM(CASE WHEN status = 'requested' THEN amount ELSE 0 END) requested,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) paid
         FROM {$t} WHERE user_id = %d", $now, $now, $user_id), ARRAY_A);
    return array_map(function ($v) { return round((float) $v, 2); }, $r ? $r : array('available' => 0, 'hold' => 0, 'requested' => 0, 'paid' => 0));
}

function nias_rev_money($n)
{
    $cur = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '';
    return nias_fa_digits(number_format((float) $n, 0, '.', '٬')) . ' ' . $cur;
}

function nias_rev_date($mysql)
{
    if (!$mysql || strpos($mysql, '0000') === 0) {
        return '—';
    }
    $ts = strtotime($mysql);
    return nias_fa_digits(function_exists('nias_cert_jalali') ? nias_cert_jalali(wp_date('Y-m-d', $ts), 'long') : wp_date('Y-m-d', $ts));
}

/* -------------------------------------------------------------------------
 * Payouts
 * ---------------------------------------------------------------------- */

function nias_rev_request_payout($user_id)
{
    global $wpdb;
    list($t, $p) = nias_rev_tables();
    $bal  = nias_rev_balance($user_id);
    $min  = max(0, (float) nias_rev_opt('min', 500000));
    $bank = trim((string) get_user_meta($user_id, '_nias_rev_bank', true));
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$p} WHERE user_id = %d AND status = 'requested'", $user_id))) {
        return new WP_Error('open', __('یک درخواست تسویهٔ باز دارید؛ تا بررسی آن صبر کنید.', 'nias-course-widget'));
    }
    if ($bank === '') {
        return new WP_Error('bank', __('ابتدا شمارهٔ شبا یا کارت خود را وارد کنید.', 'nias-course-widget'));
    }
    if ($bal['available'] <= 0 || $bal['available'] < $min) {
        return new WP_Error('min', sprintf(__('حداقل مبلغ تسویه %s است.', 'nias-course-widget'), nias_rev_money($min)));
    }
    $wpdb->insert($p, array('user_id' => $user_id, 'amount' => $bal['available'], 'status' => 'requested', 'bank' => mb_substr($bank, 0, 190), 'created' => current_time('mysql')));
    $pid = (int) $wpdb->insert_id;
    $wpdb->query($wpdb->prepare("UPDATE {$t} SET status = 'requested', payout_id = %d WHERE user_id = %d AND status = 'pending' AND available_at <= %s", $pid, $user_id, current_time('mysql')));
    do_action('nias_rev_payout_requested', $pid, $user_id, $bal['available']);
    return $pid;
}

function nias_rev_process_payout($payout_id, $paid, $note = '')
{
    global $wpdb;
    list($t, $p) = nias_rev_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p} WHERE id = %d AND status = 'requested'", $payout_id), ARRAY_A);
    if (!$row) {
        return false;
    }
    $wpdb->update($p, array('status' => $paid ? 'paid' : 'rejected', 'note' => mb_substr(sanitize_text_field($note), 0, 255), 'processed' => current_time('mysql')), array('id' => $payout_id));
    if ($paid) {
        $wpdb->update($t, array('status' => 'paid'), array('payout_id' => $payout_id, 'status' => 'requested'));
    } else {
        $wpdb->query($wpdb->prepare("UPDATE {$t} SET status = 'pending', payout_id = 0 WHERE payout_id = %d AND status = 'requested'", $payout_id));
    }
    do_action('nias_rev_payout_processed', $payout_id, (int) $row['user_id'], $paid, $note);
    return true;
}

/* -------------------------------------------------------------------------
 * Instructor panel: «درآمد»
 * ---------------------------------------------------------------------- */

function nias_rev_panel_html($user_id)
{
    global $wpdb;
    list($t, $p) = nias_rev_tables();
    $msg = '';
    if (isset($_POST['nias_rev_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_rev_nonce'])), 'nias_rev_' . $user_id)) {
        if (isset($_POST['nias_rev_bank'])) {
            update_user_meta($user_id, '_nias_rev_bank', mb_substr(sanitize_text_field(wp_unslash($_POST['nias_rev_bank'])), 0, 190));
            $msg = '<div class="nip-info">' . esc_html__('اطلاعات حساب ذخیره شد.', 'nias-course-widget') . '</div>';
        }
        if (isset($_POST['nias_rev_request'])) {
            $r   = nias_rev_request_payout($user_id);
            $msg = is_wp_error($r) ? '<div class="nip-info wait">' . esc_html($r->get_error_message()) . '</div>' : '<div class="nip-info">' . esc_html__('درخواست تسویه ثبت شد.', 'nias-course-widget') . '</div>';
        }
    }
    $b    = nias_rev_balance($user_id);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id = %d AND status <> 'reversed' ORDER BY id DESC LIMIT 30", $user_id), ARRAY_A);
    $pays = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p} WHERE user_id = %d ORDER BY id DESC LIMIT 10", $user_id), ARRAY_A);
    $st   = array('pending' => __('در دورهٔ امن', 'nias-course-widget'), 'requested' => __('در درخواست تسویه', 'nias-course-widget'), 'paid' => __('پرداخت‌شده', 'nias-course-widget'));
    $pst  = array('requested' => __('در انتظار بررسی', 'nias-course-widget'), 'paid' => __('پرداخت شد', 'nias-course-widget'), 'rejected' => __('رد شد', 'nias-course-widget'));
    ob_start();
    ?>
    <div class="nrev" dir="rtl">
    <style>
    .nrev{font-size:14px}
    .nrev-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}
    .nrev-k div{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:14px}
    .nrev-k span{color:#64748b;font-size:12.5px}
    .nrev-k b{display:block;font-size:20px;margin-top:4px}
    .nrev-k .ok b{color:#137a3a}
    .nrev-c{background:#fff;border:1px solid #e6ecf5;border-radius:14px;padding:14px;margin-bottom:14px}
    .nrev-c h3{margin:0 0 10px;font-size:15px}
    .nrev-c form{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .nrev-c input[type=text]{flex:1 1 260px;border:1px solid #dbe3ef;border-radius:10px;padding:8px 12px;font:inherit;direction:ltr}
    .nrev-c button{border:0;background:#3858e9;color:#fff;border-radius:10px;padding:9px 16px;font:inherit;font-weight:700;cursor:pointer}
    .nrev-t{width:100%;border-collapse:collapse;font-size:13px}
    .nrev-t th,.nrev-t td{padding:8px;border-bottom:1px solid #eef1f6;text-align:right}
    .nrev-t th{color:#64748b;background:#f8fafc}
    .nrev-hint{color:#64748b;font-size:12.5px;margin-top:6px}
    </style>
    <?php echo $msg; // phpcs:ignore -- escaped above ?>
    <div class="nrev-k">
        <div class="ok"><span><?php echo esc_html__('قابل برداشت', 'nias-course-widget'); ?></span><b><?php echo esc_html(nias_rev_money($b['available'])); ?></b></div>
        <div><span><?php echo esc_html(sprintf(__('در دورهٔ امن (%s روز پس از فروش)', 'nias-course-widget'), nias_fa_digits(nias_rev_opt('hold', 7)))); ?></span><b><?php echo esc_html(nias_rev_money($b['hold'])); ?></b></div>
        <div><span><?php echo esc_html__('در درخواست تسویه', 'nias-course-widget'); ?></span><b><?php echo esc_html(nias_rev_money($b['requested'])); ?></b></div>
        <div><span><?php echo esc_html__('پرداخت‌شده تا امروز', 'nias-course-widget'); ?></span><b><?php echo esc_html(nias_rev_money($b['paid'])); ?></b></div>
    </div>
    <div class="nrev-c"><h3><?php echo esc_html__('تسویه', 'nias-course-widget'); ?></h3>
        <form method="post"><?php wp_nonce_field('nias_rev_' . $user_id, 'nias_rev_nonce'); ?>
            <input type="text" name="nias_rev_bank" value="<?php echo esc_attr(get_user_meta($user_id, '_nias_rev_bank', true)); ?>" placeholder="IR… یا شمارهٔ کارت (و نام صاحب حساب)">
            <button type="submit" style="background:#fff;color:#1f2733;border:1px solid #dbe3ef"><?php echo esc_html__('ذخیرهٔ حساب', 'nias-course-widget'); ?></button>
        </form>
        <form method="post" style="margin-top:10px"><?php wp_nonce_field('nias_rev_' . $user_id, 'nias_rev_nonce'); ?><?php $can_pay = $b['available'] > 0 && $b['available'] >= (float) nias_rev_opt('min', 500000); ?><button type="submit" name="nias_rev_request" value="1" <?php disabled(!$can_pay); ?> style="<?php echo $can_pay ? '' : 'opacity:.5;cursor:not-allowed'; ?>"><?php echo esc_html($can_pay ? sprintf(__('درخواست تسویهٔ %s', 'nias-course-widget'), nias_rev_money($b['available'])) : __('موجودی قابل برداشت کافی نیست', 'nias-course-widget')); ?></button></form>
        <div class="nrev-hint"><?php echo esc_html(sprintf(__('حداقل مبلغ تسویه: %s. سهم هر فروش پس از دورهٔ امن قابل برداشت می‌شود؛ اگر سفارشی بازپرداخت یا لغو شود، سهم آن کم می‌شود.', 'nias-course-widget'), nias_rev_money(nias_rev_opt('min', 500000)))); ?></div>
        <?php if ($pays) : ?>
        <table class="nrev-t" style="margin-top:12px"><thead><tr><th><?php echo esc_html__('درخواست', 'nias-course-widget'); ?></th><th><?php echo esc_html__('مبلغ', 'nias-course-widget'); ?></th><th><?php echo esc_html__('وضعیت', 'nias-course-widget'); ?></th><th><?php echo esc_html__('توضیح', 'nias-course-widget'); ?></th></tr></thead><tbody>
            <?php foreach ($pays as $r) : ?><tr><td><?php echo esc_html(nias_rev_date($r['created'])); ?></td><td><?php echo esc_html(nias_rev_money($r['amount'])); ?></td><td><?php echo esc_html($pst[$r['status']] ?? $r['status']); ?></td><td><?php echo esc_html($r['note']); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
    <div class="nrev-c"><h3><?php echo esc_html__('فروش‌ها و سهم شما', 'nias-course-widget'); ?></h3>
        <?php if ($rows) : ?>
        <table class="nrev-t"><thead><tr><th><?php echo esc_html__('تاریخ', 'nias-course-widget'); ?></th><th><?php echo esc_html__('دوره', 'nias-course-widget'); ?></th><th><?php echo esc_html__('مبلغ فروش', 'nias-course-widget'); ?></th><th><?php echo esc_html__('سهم', 'nias-course-widget'); ?></th><th><?php echo esc_html__('وضعیت', 'nias-course-widget'); ?></th></tr></thead><tbody>
            <?php foreach ($rows as $r) : ?><tr><td><?php echo esc_html(nias_rev_date($r['created'])); ?></td><td><?php echo esc_html(get_the_title((int) $r['course_id'])); ?></td><td><?php echo esc_html(nias_rev_money($r['gross'])); ?></td><td><?php echo esc_html(nias_rev_money($r['amount']) . ' (' . nias_fa_digits(rtrim(rtrim($r['rate'], '0'), '.')) . '٪)'); ?></td><td><?php echo esc_html(($r['status'] === 'pending' && $r['available_at'] <= current_time('mysql')) ? __('قابل برداشت', 'nias-course-widget') : ($st[$r['status']] ?? $r['status'])); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php else : ?><div class="nrev-hint"><?php echo esc_html__('هنوز فروشی ثبت نشده است.', 'nias-course-widget'); ?></div><?php endif; ?>
    </div>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Admin: «درآمد مدرسین»
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!nias_rev_enabled()) {
        return;
    }
    global $wpdb;
    list(, $p) = nias_rev_tables();
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} WHERE status = 'requested'");
    add_submenu_page('nias-course-settings', __('درآمد مدرسین', 'nias-course-widget'), __('درآمد مدرسین', 'nias-course-widget') . ($n ? ' <span class="awaiting-mod">' . $n . '</span>' : ''), 'manage_options', 'nias-course-revenue', 'nias_rev_admin_page');
}, 43);

function nias_rev_admin_page()
{
    global $wpdb;
    if (!current_user_can('manage_options')) {
        return;
    }
    list($t, $p) = nias_rev_tables();
    $msg = '';
    if (isset($_POST['nias_rev_do']) && check_admin_referer('nias_rev_admin', 'nias_rev_admin_nonce')) {
        nias_rev_process_payout((int) $_POST['payout'], $_POST['nias_rev_do'] === 'paid', wp_unslash($_POST['note'] ?? ''));
        $msg = '<div class="notice notice-success inline"><p>' . esc_html__('انجام شد.', 'nias-course-widget') . '</p></div>';
    }
    $req  = $wpdb->get_results("SELECT * FROM {$p} WHERE status = 'requested' ORDER BY created ASC LIMIT 50", ARRAY_A);
    $now  = current_time('mysql');
    $bals = $wpdb->get_results($wpdb->prepare("SELECT user_id, SUM(CASE WHEN status='pending' AND available_at <= %s THEN amount ELSE 0 END) available, SUM(CASE WHEN status='pending' AND available_at > %s THEN amount ELSE 0 END) hold, SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) paid, SUM(CASE WHEN status <> 'reversed' THEN gross ELSE 0 END) gross FROM {$t} GROUP BY user_id ORDER BY available DESC LIMIT 100", $now, $now), ARRAY_A);
    echo '<div class="nias-settings-app" dir="rtl">';
    nias_settings_topbar('revenue');
    echo '<div class="nias-set-shell"><div class="nias-set-main">' . $msg; // phpcs:ignore
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="M2 10h20" stroke="#3858e9" stroke-width="1.8"/></svg>', __('درخواست‌های تسویه', 'nias-course-widget'));
    echo '<div class="nias-card-pad">';
    if (!$req) {
        echo '<p>' . esc_html__('درخواست بازی نیست.', 'nias-course-widget') . '</p>';
    }
    foreach ($req as $r) {
        $u = get_userdata((int) $r['user_id']);
        echo '<form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;border-bottom:1px solid #eef1f6;padding:10px 0">';
        wp_nonce_field('nias_rev_admin', 'nias_rev_admin_nonce');
        echo '<input type="hidden" name="payout" value="' . (int) $r['id'] . '"><b>' . esc_html($u ? $u->display_name : '#' . $r['user_id']) . '</b><span>' . esc_html(nias_rev_money($r['amount'])) . '</span><code dir="ltr">' . esc_html($r['bank']) . '</code><span style="color:#64748b">' . esc_html(nias_rev_date($r['created'])) . '</span>'
            . '<input type="text" name="note" placeholder="' . esc_attr__('شمارهٔ پیگیری / دلیل رد', 'nias-course-widget') . '" style="flex:1 1 200px">'
            . '<button class="button button-primary" name="nias_rev_do" value="paid">' . esc_html__('پرداخت شد', 'nias-course-widget') . '</button><button class="button" name="nias_rev_do" value="rejected">' . esc_html__('رد', 'nias-course-widget') . '</button></form>';
    }
    echo '</div>';
    nias_set_card_close();
    nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('حساب مدرسین', 'nias-course-widget'));
    echo '<div class="nias-card-pad"><table class="widefat striped"><thead><tr><th>' . esc_html__('مدرس', 'nias-course-widget') . '</th><th>' . esc_html__('فروش (ناخالص)', 'nias-course-widget') . '</th><th>' . esc_html__('قابل برداشت', 'nias-course-widget') . '</th><th>' . esc_html__('در دورهٔ امن', 'nias-course-widget') . '</th><th>' . esc_html__('پرداخت‌شده', 'nias-course-widget') . '</th><th>' . esc_html__('درصد سهم', 'nias-course-widget') . '</th></tr></thead><tbody>';
    if (!$bals) {
        echo '<tr><td colspan="6">' . esc_html__('هنوز فروشی برای مدرسین ثبت نشده است.', 'nias-course-widget') . '</td></tr>';
    }
    foreach ($bals as $b) {
        $u = get_userdata((int) $b['user_id']);
        $own = get_user_meta((int) $b['user_id'], '_nias_rev_rate', true);
        echo '<tr><td><a href="' . esc_url(get_edit_user_link((int) $b['user_id'])) . '#nias-rev">' . esc_html($u ? $u->display_name : '#' . $b['user_id']) . '</a></td><td>' . esc_html(nias_rev_money($b['gross'])) . '</td><td>' . esc_html(nias_rev_money($b['available'])) . '</td><td>' . esc_html(nias_rev_money($b['hold'])) . '</td><td>' . esc_html(nias_rev_money($b['paid'])) . '</td><td>' . esc_html(nias_fa_digits($own !== '' ? $own : nias_rev_opt('rate', 70)) . '٪' . ($own !== '' ? '' : ' (' . __('پیش‌فرض', 'nias-course-widget') . ')')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    nias_set_card_close();
    echo '</div></div></div>';
}

/* Per-instructor rate (profile) + per-course rate (product) ---------------- */

add_action('edit_user_profile', 'nias_rev_profile_field', 14);
add_action('show_user_profile', 'nias_rev_profile_field', 14);
function nias_rev_profile_field($user)
{
    if (!nias_rev_enabled() || !current_user_can('manage_options') || !function_exists('nias_user_is_instructor') || !nias_user_is_instructor($user->ID)) {
        return;
    }
    wp_nonce_field('nias_rev_rate_' . $user->ID, 'nias_rev_rate_nonce');
    $b = nias_rev_balance($user->ID);
    echo '<h2 id="nias-rev">' . esc_html__('سهم درآمد مدرس (دوره ساز نیاس)', 'nias-course-widget') . '</h2><table class="form-table"><tr><th>' . esc_html__('درصد سهم این مدرس', 'nias-course-widget') . '</th><td><input type="number" min="0" max="100" step="0.5" name="nias_rev_rate" value="' . esc_attr(get_user_meta($user->ID, '_nias_rev_rate', true)) . '" placeholder="' . esc_attr(nias_rev_opt('rate', 70)) . '" style="width:90px"> ٪ <p class="description">' . esc_html__('خالی = درصد پیش‌فرض سایت. برای یک دورهٔ خاص می‌توانید در صفحهٔ ویرایش همان محصول درصد جدا بگذارید.', 'nias-course-widget') . '</p>'
        . '<p>' . esc_html(sprintf(__('قابل برداشت: %1$s · در دورهٔ امن: %2$s · پرداخت‌شده: %3$s', 'nias-course-widget'), nias_rev_money($b['available']), nias_rev_money($b['hold']), nias_rev_money($b['paid']))) . '</p></td></tr></table>';
}
add_action('edit_user_profile_update', 'nias_rev_profile_save');
add_action('personal_options_update', 'nias_rev_profile_save');
function nias_rev_profile_save($uid)
{
    if (!isset($_POST['nias_rev_rate_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_rev_rate_nonce'])), 'nias_rev_rate_' . $uid) || !current_user_can('manage_options')) {
        return;
    }
    $v = isset($_POST['nias_rev_rate']) ? trim((string) wp_unslash($_POST['nias_rev_rate'])) : '';
    $v === '' ? delete_user_meta($uid, '_nias_rev_rate') : update_user_meta($uid, '_nias_rev_rate', (string) max(0, min(100, (float) $v)));
}

add_action('add_meta_boxes_product', function () {
    if (nias_rev_enabled()) {
        add_meta_box('nias-rev-rate', __('سهم مدرس از فروش این دوره', 'nias-course-widget'), function ($post) {
            wp_nonce_field('nias_rev_course', 'nias_rev_course_nonce');
            echo '<p><input type="number" min="0" max="100" step="0.5" name="nias_rev_course_rate" value="' . esc_attr(get_post_meta($post->ID, '_nias_rev_rate', true)) . '" style="width:80px"> ٪</p><p class="description">' . esc_html__('خالی = درصد خود مدرس (یا پیش‌فرض سایت).', 'nias-course-widget') . '</p>';
        }, 'product', 'side');
    }
});
add_action('save_post_product', function ($pid) {
    if (!isset($_POST['nias_rev_course_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_rev_course_nonce'])), 'nias_rev_course') || !current_user_can('manage_options')) {
        return;
    }
    $v = isset($_POST['nias_rev_course_rate']) ? trim((string) wp_unslash($_POST['nias_rev_course_rate'])) : '';
    $v === '' ? delete_post_meta($pid, '_nias_rev_rate') : update_post_meta($pid, '_nias_rev_rate', (string) max(0, min(100, (float) $v)));
});
