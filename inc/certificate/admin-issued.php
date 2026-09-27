<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "مدارک صادرشده" admin page: search, approve, revoke/restore, download and
 * import legacy codes into the registry.
 *
 * @package nias-course-widget
 */

add_action('admin_menu', 'nias_cert_register_issued_page', 13);
function nias_cert_register_issued_page()
{
    if (!current_user_can('manage_options') || !nias_cert_feature_on()) {
        return;
    }
    $pending = nias_cert_count_status('pending');
    $label   = __('مدارک صادرشده', 'nias-course-widget');
    if ($pending) {
        $label .= ' <span class="awaiting-mod">' . (int) $pending . '</span>';
    }
    add_submenu_page(
        'nias-course-settings',
        __('مدارک صادرشده', 'nias-course-widget'),
        $label,
        'manage_options',
        'nias-course-cert-issued',
        'nias_cert_render_issued_page'
    );
}

/** Row count for a status ('' = all). */
function nias_cert_count_status($status = '')
{
    global $wpdb;
    $table = nias_cert_table();
    if ($status === '') {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/* -------------------------------------------------------------------------
 * Actions
 * ---------------------------------------------------------------------- */

add_action('admin_init', 'nias_cert_handle_issued_actions');
function nias_cert_handle_issued_actions()
{
    if (!isset($_REQUEST['page']) || $_REQUEST['page'] !== 'nias-course-cert-issued' || !current_user_can('manage_options')) {
        return;
    }
    $back = remove_query_arg(array('nias_act', 'id', '_wpnonce', 'note', 'done'), wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=nias-course-cert-issued'));

    if (!empty($_POST['nias_cert_backfill'])) {
        check_admin_referer('nias_cert_backfill');
        $n = nias_cert_backfill(500);
        wp_safe_redirect(add_query_arg(array('done' => 'backfill', 'n' => $n), $back));
        exit;
    }

    if (empty($_GET['nias_act']) || empty($_GET['id'])) {
        return;
    }
    $act = sanitize_key(wp_unslash($_GET['nias_act']));
    $id  = (int) $_GET['id'];
    check_admin_referer('nias_cert_act_' . $id);

    $note = isset($_GET['note']) ? sanitize_textarea_field(wp_unslash($_GET['note'])) : null;
    switch ($act) {
        case 'approve':
        case 'restore':
            nias_cert_set_status($id, 'valid', $act === 'restore' ? '' : null);
            break;
        case 'revoke':
            nias_cert_set_status($id, 'revoked', $note !== null ? $note : '');
            break;
        case 'delete':
            global $wpdb;
            $wpdb->delete(nias_cert_table(), array('id' => $id));
            break;
        default:
            return;
    }
    wp_safe_redirect(add_query_arg('done', $act, $back));
    exit;
}

/** Admin-only PDF download of any registry entry. */
add_action('admin_post_nias_cert_admin_download', 'nias_cert_handle_admin_download');
function nias_cert_handle_admin_download()
{
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('دسترسی لازم را ندارید.', 'nias-course-widget'));
    }
    check_admin_referer('nias_cert_dl_' . $id);
    $row = nias_cert_get($id);
    if (!$row) {
        wp_die(esc_html__('مدرک یافت نشد.', 'nias-course-widget'));
    }
    $pid  = (int) $row['product_id'];
    $date = $pid ? nias_certificate_resolve_date((int) $row['user_id'], $pid, substr((string) $row['issued_at'], 0, 10)) : substr((string) $row['issued_at'], 0, 10);
    $data = nias_cert_build_data((int) $row['user_id'], $pid, $row['code'], $date);
    if ($data['course_name'] === '' && $row['course_name'] !== '') {
        $data['course_name'] = $row['course_name'];
    }
    try {
        nias_cert_output_pdf($data, nias_cert_template_for($pid), 'I');
    } catch (\Throwable $e) {
        wp_die(esc_html__('خطا در تولید مدرک: ', 'nias-course-widget') . esc_html($e->getMessage()));
    }
    exit;
}

/* -------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------- */

function nias_cert_render_issued_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $table  = nias_cert_table();
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
    $paged  = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per    = 20;

    $where = array('1=1');
    $args  = array();
    if (in_array($status, array('valid', 'pending', 'revoked'), true)) {
        $where[] = 'status = %s';
        $args[]  = $status;
    }
    if ($search !== '') {
        $like    = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(code LIKE %s OR student_name LIKE %s OR course_name LIKE %s)';
        array_push($args, $like, $like, $like);
    }
    $where_sql = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    $total     = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, $args)) : $wpdb->get_var($count_sql)); // phpcs:ignore
    $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $rows      = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($args, array($per, ($paged - 1) * $per))), ARRAY_A); // phpcs:ignore
    $pages     = max(1, (int) ceil($total / $per));

    $base   = admin_url('admin.php?page=nias-course-cert-issued');
    $counts = array(
        ''        => nias_cert_count_status(''),
        'valid'   => nias_cert_count_status('valid'),
        'pending' => nias_cert_count_status('pending'),
        'revoked' => nias_cert_count_status('revoked'),
    );
    $done = isset($_GET['done']) ? sanitize_key(wp_unslash($_GET['done'])) : '';
    $done_msgs = array(
        'approve'  => __('مدرک تأیید شد.', 'nias-course-widget'),
        'restore'  => __('مدرک دوباره معتبر شد.', 'nias-course-widget'),
        'revoke'   => __('مدرک باطل شد.', 'nias-course-widget'),
        'delete'   => __('رکورد مدرک حذف شد.', 'nias-course-widget'),
        /* translators: %d: number of certificates */
        'backfill' => sprintf(__('%d مدرک قدیمی وارد فهرست شد.', 'nias-course-widget'), isset($_GET['n']) ? (int) $_GET['n'] : 0),
    );
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('issued'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main nci">
            <?php if ($done && isset($done_msgs[$done])) : ?>
                <div class="nias-saved"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m20 6-11 11-5-5" stroke="#0a8a44" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php echo esc_html($done_msgs[$done]); ?></div>
            <?php endif; ?>

            <div class="nci-stats">
                <?php
                $labels = array('' => __('همه', 'nias-course-widget'), 'valid' => __('معتبر', 'nias-course-widget'), 'pending' => __('در انتظار تأیید', 'nias-course-widget'), 'revoked' => __('باطل‌شده', 'nias-course-widget'));
                foreach ($labels as $k => $lbl) :
                    $url = $k === '' ? remove_query_arg(array('status', 'paged'), $base) : add_query_arg('status', $k, $base); ?>
                    <a class="nci-stat nci-stat-<?php echo esc_attr($k ? $k : 'all'); ?> <?php echo $status === $k ? 'active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                        <span class="nci-stat-n"><?php echo esc_html(nias_fa_digits($counts[$k])); ?></span>
                        <span class="nci-stat-l"><?php echo esc_html($lbl); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="5" stroke="#3858e9" stroke-width="1.8"/><path d="m8.5 13-1.5 8 5-2.5 5 2.5-1.5-8" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('مدارک صادرشده', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nci-toolbar">
                        <form method="get" class="nci-search">
                            <input type="hidden" name="page" value="nias-course-cert-issued">
                            <?php if ($status) : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                            <input type="search" class="nias-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('جست‌وجوی کد، نام یا دوره…', 'nias-course-widget'); ?>">
                            <button type="submit" class="nias-btn-soft"><?php echo esc_html__('جست‌وجو', 'nias-course-widget'); ?></button>
                        </form>
                        <form method="post">
                            <?php wp_nonce_field('nias_cert_backfill'); ?>
                            <button type="submit" name="nias_cert_backfill" value="1" class="nias-btn-soft" title="<?php echo esc_attr__('کدهای مدرکی که پیش از این نسخه ساخته شده‌اند را به این فهرست اضافه می‌کند.', 'nias-course-widget'); ?>"><?php echo esc_html__('همگام‌سازی مدارک قدیمی', 'nias-course-widget'); ?></button>
                        </form>
                    </div>

                    <?php if (!$rows) : ?>
                        <div class="nci-empty"><?php echo esc_html__('مدرکی یافت نشد. مدارک پس از احراز شرایط توسط دانشجو، یا با «همگام‌سازی مدارک قدیمی» در این فهرست ثبت می‌شوند.', 'nias-course-widget'); ?></div>
                    <?php else : ?>
                        <div class="nci-table-wrap">
                        <table class="nci-table">
                            <thead><tr>
                                <th><?php echo esc_html__('دانشجو', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('دوره', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('کد مدرک', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('تاریخ', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('وضعیت', 'nias-course-widget'); ?></th>
                                <th><?php echo esc_html__('عملیات', 'nias-course-widget'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r) :
                                $id   = (int) $r['id'];
                                $act  = function ($a) use ($id, $base) {
                                    return wp_nonce_url(add_query_arg(array('nias_act' => $a, 'id' => $id), $base), 'nias_cert_act_' . $id);
                                };
                                $dl   = wp_nonce_url(admin_url('admin-post.php?action=nias_cert_admin_download&id=' . $id), 'nias_cert_dl_' . $id);
                                $user = get_userdata((int) $r['user_id']);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($r['student_name'] !== '' ? $r['student_name'] : ($user ? $user->display_name : '—')); ?></strong>
                                        <?php if ($user) : ?><div class="nci-muted"><a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html($user->user_login); ?></a></div><?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($r['course_name'] !== '' ? $r['course_name'] : '—'); ?></td>
                                    <td><code dir="ltr"><?php echo esc_html($r['code']); ?></code></td>
                                    <td><?php echo esc_html($r['issued_at'] ? nias_fa_digits(nias_cert_jalali(substr($r['issued_at'], 0, 10), 'numeric')) : '—'); ?></td>
                                    <td>
                                        <span class="nci-badge nci-badge-<?php echo esc_attr($r['status']); ?>"><?php echo esc_html(nias_cert_status_label($r['status'])); ?></span>
                                        <?php if ($r['status'] === 'revoked' && !empty($r['note']) && $r['note'] !== 'legacy') : ?><div class="nci-muted"><?php echo esc_html($r['note']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="nci-actions">
                                        <?php if ($r['status'] === 'pending') : ?>
                                            <a class="nci-a nci-a-ok" href="<?php echo esc_url($act('approve')); ?>"><?php echo esc_html__('تأیید', 'nias-course-widget'); ?></a>
                                        <?php endif; ?>
                                        <?php if ($r['status'] === 'revoked') : ?>
                                            <a class="nci-a nci-a-ok" href="<?php echo esc_url($act('restore')); ?>"><?php echo esc_html__('بازگردانی', 'nias-course-widget'); ?></a>
                                        <?php else : ?>
                                            <a class="nci-a nci-a-bad nci-revoke" href="<?php echo esc_url($act('revoke')); ?>"><?php echo esc_html__('ابطال', 'nias-course-widget'); ?></a>
                                        <?php endif; ?>
                                        <a class="nci-a" target="_blank" href="<?php echo esc_url($dl); ?>"><?php echo esc_html__('PDF', 'nias-course-widget'); ?></a>
                                        <a class="nci-a" target="_blank" href="<?php echo esc_url(nias_cert_verify_url($r['code'], (int) $r['user_id'])); ?>"><?php echo esc_html__('استعلام', 'nias-course-widget'); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php if ($pages > 1) : ?>
                            <div class="nci-pages">
                                <?php for ($i = 1; $i <= $pages; $i++) : ?>
                                    <a class="<?php echo $i === $paged ? 'active' : ''; ?>" href="<?php echo esc_url(remove_query_arg(array('done', 'n'), add_query_arg('paged', $i))); ?>"><?php echo esc_html(nias_fa_digits($i)); ?></a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php nias_set_card_close(); ?>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>
    <style>
    .nci-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
    .nci-stat{background:#fff;border:1px solid #e3e7ef;border-radius:14px;padding:14px 16px;text-decoration:none;color:#1f2a30;display:flex;flex-direction:column;gap:4px;transition:border-color .15s}
    .nci-stat:hover,.nci-stat.active{border-color:#3858e9}
    .nci-stat-n{font-size:22px;font-weight:800}
    .nci-stat-l{font-size:12.5px;color:#7b8497}
    .nci-stat-valid .nci-stat-n{color:#0f7a41}.nci-stat-pending .nci-stat-n{color:#b7791f}.nci-stat-revoked .nci-stat-n{color:#c0262d}
    .nci-toolbar{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px}
    .nci-search{display:flex;gap:8px;flex:1;max-width:460px}
    .nci-search .nias-input{flex:1}
    .nci-table-wrap{overflow-x:auto}
    .nci-table{width:100%;border-collapse:collapse;font-size:13px}
    .nci-table th{text-align:right;font-weight:700;color:#7b8497;font-size:12px;padding:10px;border-bottom:1px solid #e3e7ef;white-space:nowrap}
    .nci-table td{padding:12px 10px;border-bottom:1px solid #f0f2f6;vertical-align:top}
    .nci-table code{font-size:12px;background:#f3f4f7;padding:3px 7px;border-radius:6px}
    .nci-muted{color:#8a90a6;font-size:12px;margin-top:3px}
    .nci-badge{display:inline-block;font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap}
    .nci-badge-valid{background:#ecfbf1;color:#146c3a}.nci-badge-pending{background:#fff8e6;color:#7a5a00}.nci-badge-revoked{background:#fff1f1;color:#a1161c}
    .nci-actions{white-space:nowrap}
    .nci-a{display:inline-block;font-size:12px;font-weight:600;padding:4px 9px;border-radius:7px;border:1px solid #e3e7ef;text-decoration:none;color:#1f2a30;margin:0 0 4px 3px}
    .nci-a:hover{border-color:#3858e9;color:#3858e9}
    .nci-a-ok{color:#0f7a41;border-color:#bfe9cf}.nci-a-bad{color:#c0262d;border-color:#f5c2c4}
    .nci-empty{padding:30px;text-align:center;color:#7b8497;font-size:13px;line-height:2}
    .nci-pages{display:flex;gap:5px;flex-wrap:wrap;margin-top:14px}
    .nci-pages a{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e3e7ef;border-radius:8px;text-decoration:none;color:#1f2a30;font-size:13px}
    .nci-pages a.active{background:#3858e9;border-color:#3858e9;color:#fff}
    @media(max-width:782px){.nci-stats{grid-template-columns:repeat(2,1fr)}}
    </style>
    <script>
    document.querySelectorAll('.nci-revoke').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var note = window.prompt('<?php echo esc_js(__('دلیل ابطال (اختیاری):', 'nias-course-widget')); ?>', '');
            if (note === null) { return; }
            window.location.href = a.href + '&note=' + encodeURIComponent(note);
        });
    });
    </script>
    <?php
}
