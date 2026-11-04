<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verification log (گزارش استعلام‌ها).
 *
 * Every public lookup (form, QR, PDF view, REST) is recorded with its
 * result. Privacy: the IP address itself is never stored — only a keyed
 * hash, enough to count distinct visitors and spot code guessing. Entries
 * older than a year are pruned daily (filter nias_certificate_log_days).
 * Lookups by administrators are not logged.
 *
 * @package nias-course-widget
 */

/** Record one lookup. */
function nias_cert_log_verification($code, $state, $row, $source)
{
    if (current_user_can('manage_options') || !apply_filters('nias_certificate_log_enabled', true)) {
        return;
    }
    global $wpdb;
    $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $ref = wp_get_raw_referer();
    $ref = $ref ? (string) wp_parse_url($ref, PHP_URL_HOST) : '';
    $wpdb->insert(nias_cert_log_table(), array(
        'cert_id'    => $row ? (int) $row['id'] : 0,
        'code'       => substr(nias_cert_normalize_code($code), 0, 64),
        'result'     => substr($state, 0, 12),
        'source'     => substr($source, 0, 10),
        'visitor'    => substr(hash_hmac('sha256', nias_cert_client_ip(), wp_salt('nonce')), 0, 16),
        'user_id'    => get_current_user_id(),
        'agent'      => mb_substr($ua, 0, 190, 'UTF-8'),
        'referer'    => mb_substr($ref, 0, 190, 'UTF-8'),
        'created_at' => current_time('mysql'),
    ));
}

/* -------------------------------------------------------------------------
 * Retention
 * ---------------------------------------------------------------------- */

add_action('init', 'nias_cert_log_schedule');
function nias_cert_log_schedule()
{
    if (!wp_next_scheduled('nias_cert_log_prune')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'nias_cert_log_prune');
    }
}

add_action('nias_cert_log_prune', 'nias_cert_log_prune');
function nias_cert_log_prune()
{
    global $wpdb;
    $days = max(7, (int) apply_filters('nias_certificate_log_days', 365));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . nias_cert_log_table() . ' WHERE created_at < %s LIMIT 5000',
        gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS)
    ));
}

/* -------------------------------------------------------------------------
 * Helpers for the admin screens
 * ---------------------------------------------------------------------- */

/**
 * Lookup counts for a set of certificates.
 *
 * @param int[] $ids
 * @return array<int,int>
 */
function nias_cert_log_counts($ids)
{
    $ids = array_filter(array_map('intval', (array) $ids));
    if (!$ids) {
        return array();
    }
    global $wpdb;
    $rows = $wpdb->get_results('SELECT cert_id, COUNT(*) n FROM ' . nias_cert_log_table() . ' WHERE cert_id IN (' . implode(',', $ids) . ') GROUP BY cert_id'); // ids are ints
    $out  = array();
    foreach ((array) $rows as $r) {
        $out[(int) $r->cert_id] = (int) $r->n;
    }
    return $out;
}

/** "Chrome · موبایل" from a user agent. */
function nias_cert_log_device($ua)
{
    if ($ua === '') {
        return '—';
    }
    $browser = 'مرورگر';
    foreach (array('Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung', 'Firefox' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome' => 'Chrome', 'Safari' => 'Safari') as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $browser = $name;
            break;
        }
    }
    if (preg_match('/bot|crawl|spider|curl|wget|python|http/i', $ua)) {
        return 'ربات / برنامه';
    }
    $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'موبایل' : 'دسکتاپ';
    return $browser . ' · ' . $device;
}

function nias_cert_log_result_label($r)
{
    $map = array(
        'valid'    => array('ok', __('معتبر', 'nias-course-widget')),
        'pending'  => array('warn', __('در انتظار', 'nias-course-widget')),
        'revoked'  => array('bad', __('باطل‌شده', 'nias-course-widget')),
        'notfound' => array('bad', __('یافت نشد', 'nias-course-widget')),
    );
    return isset($map[$r]) ? $map[$r] : array('', $r);
}

function nias_cert_log_source_label($s)
{
    $map = array('web' => __('صفحهٔ استعلام', 'nias-course-widget'), 'api' => 'API', 'pdf' => __('مشاهدهٔ PDF', 'nias-course-widget'));
    return isset($map[$s]) ? $map[$s] : $s;
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_cert_register_log_page', 14);
function nias_cert_register_log_page()
{
    if (!current_user_can('manage_options') || !nias_cert_feature_on()) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('گزارش استعلام مدارک', 'nias-course-widget'),
        __('گزارش استعلام‌ها', 'nias-course-widget'),
        'manage_options',
        'nias-course-cert-verifications',
        'nias_cert_render_log_page'
    );
}

function nias_cert_render_log_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $log   = nias_cert_log_table();
    $reg   = nias_cert_table();
    $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);
    $day   = gmdate('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);

    $cert_id = isset($_GET['cert']) ? (int) $_GET['cert'] : 0;
    $result  = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';
    $paged   = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per     = 30;

    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) total, SUM(result = 'valid') ok, SUM(result = 'notfound') miss, COUNT(DISTINCT visitor) visitors, COUNT(DISTINCT NULLIF(cert_id, 0)) certs FROM {$log} WHERE created_at >= %s",
        $since
    ));
    $top = $wpdb->get_results($wpdb->prepare(
        "SELECT l.cert_id, COUNT(*) n, r.student_name, r.course_name, r.code FROM {$log} l JOIN {$reg} r ON r.id = l.cert_id WHERE l.created_at >= %s GROUP BY l.cert_id, r.student_name, r.course_name, r.code ORDER BY n DESC LIMIT 5",
        $since
    ));
    // Visitors guessing codes: many misses in 24h.
    $suspects = $wpdb->get_results($wpdb->prepare(
        "SELECT visitor, COUNT(*) n, MAX(created_at) last_at, MAX(agent) agent FROM {$log} WHERE result = 'notfound' AND created_at >= %s GROUP BY visitor HAVING n >= 5 ORDER BY n DESC LIMIT 10",
        $day
    ));

    $where = array('1=1');
    $args  = array();
    if ($cert_id) {
        $where[] = 'l.cert_id = %d';
        $args[]  = $cert_id;
    }
    if (in_array($result, array('valid', 'pending', 'revoked', 'notfound'), true)) {
        $where[] = 'l.result = %s';
        $args[]  = $result;
    }
    $where_sql = implode(' AND ', $where);
    $total     = (int) ($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$log} l WHERE {$where_sql}", $args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$log} l")); // phpcs:ignore
    $rows      = $wpdb->get_results($wpdb->prepare(
        "SELECT l.*, r.student_name, r.course_name FROM {$log} l LEFT JOIN {$reg} r ON r.id = l.cert_id WHERE {$where_sql} ORDER BY l.id DESC LIMIT %d OFFSET %d", // phpcs:ignore
        array_merge($args, array($per, ($paged - 1) * $per))
    ));
    $pages = max(1, (int) ceil($total / $per));
    $base  = admin_url('admin.php?page=nias-course-cert-verifications');
    $num   = function ($n) {
        return nias_fa_digits(number_format_i18n((int) $n));
    };
    $when  = function ($dt) {
        return nias_fa_digits(nias_cert_jalali(substr($dt, 0, 10), 'numeric') . ' ' . substr($dt, 11, 5));
    };
    $focus = $cert_id ? nias_cert_get($cert_id) : null;
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('cert'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">
            <div class="nci-stats">
                <div class="nci-stat"><span class="nci-stat-n"><?php echo esc_html($num($stats->total)); ?></span><span class="nci-stat-l"><?php echo esc_html__('استعلام در ۳۰ روز اخیر', 'nias-course-widget'); ?></span></div>
                <div class="nci-stat nci-stat-valid"><span class="nci-stat-n"><?php echo esc_html($num($stats->ok)); ?></span><span class="nci-stat-l"><?php echo esc_html__('استعلام موفق', 'nias-course-widget'); ?></span></div>
                <div class="nci-stat nci-stat-revoked"><span class="nci-stat-n"><?php echo esc_html($num($stats->miss)); ?></span><span class="nci-stat-l"><?php echo esc_html__('کد نامعتبر', 'nias-course-widget'); ?></span></div>
                <div class="nci-stat"><span class="nci-stat-n"><?php echo esc_html($num($stats->visitors)); ?></span><span class="nci-stat-l"><?php echo esc_html__('بازدیدکنندهٔ یکتا', 'nias-course-widget'); ?></span></div>
            </div>

            <?php if ($suspects) : ?>
                <div class="ncl-alert">
                    <b><?php echo esc_html__('⚠ تلاش مشکوک برای حدس زدن کد (۲۴ ساعت اخیر)', 'nias-course-widget'); ?></b>
                    <?php foreach ($suspects as $s) : ?>
                        <div><?php echo esc_html(sprintf(__('بازدیدکنندهٔ %1$s: %2$s کد نامعتبر — آخرین: %3$s — %4$s', 'nias-course-widget'), substr($s->visitor, 0, 6), $num($s->n), $when($s->last_at), nias_cert_log_device((string) $s->agent))); ?></div>
                    <?php endforeach; ?>
                    <div class="nci-muted"><?php echo esc_html__('محدودیت درخواست جلوی ادامهٔ حدس زدن را می‌گیرد؛ اگر تکرار شد، IP را در فایروال میزبان مسدود کنید.', 'nias-course-widget'); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($top && !$cert_id) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('پراستعلام‌ترین مدارک (۳۰ روز)', 'nias-course-widget')); ?>
                    <div class="nias-card-pad">
                        <?php foreach ($top as $t) : ?>
                            <a class="ncl-top" href="<?php echo esc_url(add_query_arg('cert', (int) $t->cert_id, $base)); ?>">
                                <span><b><?php echo esc_html($t->student_name); ?></b> — <?php echo esc_html($t->course_name); ?> <code dir="ltr"><?php echo esc_html($t->code); ?></code></span>
                                <span class="ncl-n"><?php echo esc_html($num($t->n)); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', $focus ? sprintf(__('استعلام‌های مدرک %s', 'nias-course-widget'), $focus['code']) : __('آخرین استعلام‌ها', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nci-toolbar">
                        <div class="ncl-filters">
                            <?php foreach (array('' => __('همه', 'nias-course-widget'), 'valid' => __('معتبر', 'nias-course-widget'), 'notfound' => __('یافت نشد', 'nias-course-widget'), 'revoked' => __('باطل‌شده', 'nias-course-widget')) as $k => $lbl) : ?>
                                <a class="nci-a <?php echo $result === $k ? 'ncl-on' : ''; ?>" href="<?php echo esc_url($k === '' ? remove_query_arg(array('result', 'paged')) : add_query_arg(array('result' => $k, 'paged' => 1))); ?>"><?php echo esc_html($lbl); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($focus) : ?>
                            <a class="nci-a" href="<?php echo esc_url($base); ?>"><?php echo esc_html__('× نمایش همهٔ مدارک', 'nias-course-widget'); ?></a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$rows) : ?>
                        <div class="nci-empty"><?php echo esc_html__('هنوز استعلامی ثبت نشده است. هر بار که کسی QR مدرک را اسکن کند یا کد را در صفحهٔ استعلام وارد کند، اینجا نمایش داده می‌شود (استعلام‌های مدیران ثبت نمی‌شوند).', 'nias-course-widget'); ?></div>
                    <?php else : ?>
                        <div class="nci-table-wrap"><table class="nci-table">
                            <thead><tr>
                                <th><?php echo esc_html__('زمان', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('نتیجه', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('مدرک', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('روش', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('دستگاه', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('ارجاع از', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('بازدیدکننده', 'nias-course-widget'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r) :
                                $lbl = nias_cert_log_result_label($r->result);
                                $u   = $r->user_id ? get_userdata((int) $r->user_id) : null; ?>
                                <tr>
                                    <td><?php echo esc_html($when($r->created_at)); ?></td>
                                    <td><span class="nci-badge ncl-<?php echo esc_attr($lbl[0]); ?>"><?php echo esc_html($lbl[1]); ?></span></td>
                                    <td>
                                        <?php if ($r->cert_id) : ?>
                                            <a href="<?php echo esc_url(add_query_arg('cert', (int) $r->cert_id, $base)); ?>"><?php echo esc_html($r->student_name ? $r->student_name : '#' . $r->cert_id); ?></a>
                                            <div class="nci-muted"><?php echo esc_html($r->course_name); ?></div>
                                        <?php else : ?>
                                            <code dir="ltr"><?php echo esc_html($r->code); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(nias_cert_log_source_label($r->source)); ?></td>
                                    <td><?php echo esc_html(nias_cert_log_device((string) $r->agent)); ?></td>
                                    <td dir="ltr" style="text-align:right"><?php echo esc_html($r->referer ? $r->referer : '—'); ?></td>
                                    <td>
                                        <?php echo $u ? esc_html($u->display_name) : '<span class="nci-muted">' . esc_html__('مهمان', 'nias-course-widget') . '</span>'; ?>
                                        <div class="nci-muted" dir="ltr" style="text-align:right">#<?php echo esc_html(substr($r->visitor, 0, 6)); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <?php if ($pages > 1) : ?>
                            <div class="nci-pages">
                                <?php for ($i = 1; $i <= min($pages, 50); $i++) : ?>
                                    <a class="<?php echo $i === $paged ? 'active' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>"><?php echo esc_html(nias_fa_digits($i)); ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="nci-muted" style="margin-top:12px"><?php echo esc_html__('برای حفظ حریم خصوصی، IP بازدیدکنندگان ذخیره نمی‌شود و فقط یک شناسهٔ ناشناس (#) برای شمارش بازدیدکنندهٔ یکتا نگه داشته می‌شود. سوابق قدیمی‌تر از یک سال خودکار پاک می‌شوند.', 'nias-course-widget'); ?></div>
                </div>
            <?php nias_set_card_close(); ?>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>
    <?php nias_cert_issued_styles(); ?>
    <style>
    .ncl-alert{background:#fff8e6;border:1px solid #f6dfa1;color:#7a5a00;border-radius:14px;padding:14px 16px;margin-bottom:16px;font-size:13px;line-height:2}
    .ncl-top{display:flex;justify-content:space-between;gap:10px;padding:10px 4px;border-bottom:1px solid #f0f2f6;text-decoration:none;color:#1f2a30;font-size:13px}
    .ncl-top:last-child{border-bottom:0}
    .ncl-top code{font-size:11px;background:#f3f4f7;padding:2px 6px;border-radius:6px}
    .ncl-n{font-weight:800;color:#3858e9}
    .ncl-filters{display:flex;gap:4px;flex-wrap:wrap}
    .ncl-on{border-color:#3858e9 !important;color:#3858e9 !important;background:#eef1fd}
    .ncl-ok{background:#ecfbf1;color:#146c3a}.ncl-warn{background:#fff8e6;color:#7a5a00}.ncl-bad{background:#fff1f1;color:#a1161c}
    </style>
    <?php
}
