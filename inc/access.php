<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «دسترسی زمان‌دار و تمدید» (time-limited access + renewal).
 *
 *   - Each course (WooCommerce product) can have an access period: N days or
 *     months from enrolment. A site-wide default can apply to courses without
 *     their own value. Empty / 0 = unlimited.
 *   - Per user: _nias_access_until_{course} (unix time; -1 = unlimited by the
 *     admin). Computed once from the enrolment time, and never earlier than
 *     the moment the course got its period (so old students are not cut off
 *     the day the option is switched on).
 *   - Expired: nias_user_has_course_access() says no → private lessons, files,
 *     quizzes, Q&A behave as for a non-buyer; the course page and the account
 *     cards say «دسترسی شما به پایان رسید» with a «تمدید دسترسی» button.
 *   - Renewal: buying the course again, or a separate renewal product chosen
 *     on the course (e.g. «تمدید ۳ ماهه»). A completed order extends from the
 *     later of today / the current end date. Each order is applied once.
 *   - Reminders N days before the end and on the end day: SMS events of the
 *     SMS module + optional e-mail; an hourly tick reads only due users
 *     (user meta _nias_access_next).
 *   - Admins: a user's course access on the profile screen (extend / set /
 *     unlimited). Admins and the course's instructors are never limited.
 *
 * @package nias-course-widget
 */

function nias_access_enabled()
{
    return (bool) apply_filters('nias_access_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_access_enabled') === 'on' && function_exists('nias_wc_active') && nias_wc_active());
}

/* -------------------------------------------------------------------------
 * Course settings
 * ---------------------------------------------------------------------- */

/** Access period of a course in seconds (0 = unlimited). */
function nias_access_period($post_id)
{
    $n    = (int) get_post_meta($post_id, '_nias_access_len', true);
    $unit = get_post_meta($post_id, '_nias_access_unit', true) === 'month' ? 'month' : 'day';
    if ($n <= 0 && get_post_meta($post_id, '_nias_access_len', true) === '') {
        $n    = (int) (function_exists('carbon_get_theme_option') ? carbon_get_theme_option('nias_access_default_days') : 0);
        $unit = 'day';
    }
    if ($n <= 0) {
        return 0;
    }
    return (int) apply_filters('nias_access_period', $unit === 'month' ? $n * 30 * DAY_IN_SECONDS : $n * DAY_IN_SECONDS, $post_id);
}

/** Human text of a course period ("۹۰ روز" / "۶ ماه"). */
function nias_access_period_label($post_id)
{
    $n    = (int) get_post_meta($post_id, '_nias_access_len', true);
    $unit = get_post_meta($post_id, '_nias_access_unit', true) === 'month' ? 'month' : 'day';
    if ($n <= 0) {
        $n    = (int) carbon_get_theme_option('nias_access_default_days');
        $unit = 'day';
    }
    $t = $n <= 0 ? __('Unlimited', 'nias-course-widget') : sprintf($unit === 'month' ? __('%s months', 'nias-course-widget') : __('%s days', 'nias-course-widget'), $n);
    return function_exists('nias_fa_digits') ? nias_fa_digits($t) : $t;
}

/** Renewal: product id (0 = buy the course again) and length in seconds. */
function nias_access_renewal($post_id)
{
    $prod = (int) get_post_meta($post_id, '_nias_renew_product', true);
    if ($prod && (!function_exists('wc_get_product') || !wc_get_product($prod))) {
        $prod = 0;
    }
    $days = (int) get_post_meta($post_id, '_nias_renew_days', true);
    return array('product' => $prod, 'len' => $days > 0 ? $days * DAY_IN_SECONDS : nias_access_period($post_id));
}

function nias_access_renew_url($post_id)
{
    $r = nias_access_renewal($post_id);
    $target = $r['product'] ? $r['product'] : (int) $post_id;
    if (function_exists('wc_get_cart_url')) {
        return add_query_arg('add-to-cart', $target, wc_get_cart_url());
    }
    return get_permalink($target);
}

/* -------------------------------------------------------------------------
 * End date per user
 * ---------------------------------------------------------------------- */

function nias_access_key($post_id)
{
    return '_nias_access_until_' . (int) $post_id;
}

function nias_access_exempt($user_id, $post_id)
{
    return user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce')
        || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id));
}

/**
 * End of the user's access (unix time), 0 = unlimited / not limited.
 * Computed and stored on first use.
 */
function nias_access_until($user_id, $post_id)
{
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    if (!$user_id || !$post_id) {
        return 0;
    }
    $v = get_user_meta($user_id, nias_access_key($post_id), true);
    if ($v !== '') {
        return (int) $v > 0 ? (int) $v : 0;
    }
    $len = nias_access_period($post_id);
    if (!$len || !function_exists('nias_course_user_completed') || !nias_course_user_completed($user_id, $post_id)) {
        return 0;
    }
    $en    = function_exists('nias_course_enrolled_at') ? nias_course_enrolled_at($user_id, $post_id) : time();
    $since = (int) get_post_meta($post_id, '_nias_access_since', true); // when the course got its period
    $start = max($en ? $en : time(), $since);
    nias_access_set_until($user_id, $post_id, $start + $len);
    return $start + $len;
}

function nias_access_set_until($user_id, $post_id, $until)
{
    update_user_meta($user_id, nias_access_key($post_id), (int) $until);
    $list = get_user_meta($user_id, '_nias_access_courses', true);
    $list = is_array($list) ? $list : array();
    if (!in_array((int) $post_id, $list, true)) {
        $list[] = (int) $post_id;
        update_user_meta($user_id, '_nias_access_courses', $list);
    }
    // Reminders restart for the new period.
    delete_user_meta($user_id, '_nias_access_sent_' . (int) $post_id);
    nias_access_recompute_next($user_id);
    do_action('nias_access_until_changed', (int) $user_id, (int) $post_id, (int) $until);
}

/** Is the user's access to this course over? */
function nias_access_expired($user_id, $post_id)
{
    if (!nias_access_enabled() || !$user_id || nias_access_exempt($user_id, $post_id)) {
        return false;
    }
    $until = nias_access_until($user_id, $post_id);
    return $until > 0 && $until <= time();
}

// The central access check (inc/course-type.php) asks this.
add_filter('nias_course_access_active', function ($ok, $user_id, $post_id) {
    return $ok && !nias_access_expired($user_id, $post_id);
}, 10, 3);

/** "۱۵ آبان ۱۴۰۵" */
function nias_access_date($ts)
{
    $ymd = wp_date('Y-m-d', $ts);
    $d   = function_exists('nias_cert_jalali') ? nias_cert_jalali($ymd, 'long') : $ymd;
    return function_exists('nias_fa_digits') ? nias_fa_digits($d) : $d;
}

/** State for the views: null = not limited. */
function nias_access_info($user_id, $post_id)
{
    if (!nias_access_enabled() || !$user_id || nias_access_exempt($user_id, $post_id) || !nias_course_user_completed($user_id, $post_id)) {
        return null;
    }
    $until = nias_access_until($user_id, $post_id);
    if (!$until) {
        return null;
    }
    $left = (int) ceil(($until - time()) / DAY_IN_SECONDS);
    return array(
        'until'    => $until,
        'date'     => nias_access_date($until),
        'expired'  => $until <= time(),
        'daysLeft' => max(0, $left),
        'renewUrl' => nias_access_renew_url($post_id),
        'soon'     => $until > time() && $left <= (int) apply_filters('nias_access_soon_days', 14),
    );
}

/* -------------------------------------------------------------------------
 * Orders: first purchase, re-purchase, renewal product
 * ---------------------------------------------------------------------- */

add_action('woocommerce_order_status_completed', 'nias_access_on_order', 25);
function nias_access_on_order($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order || !$order->get_customer_id() || $order->get_meta('_nias_access_applied')) {
        return;
    }
    $uid  = (int) $order->get_customer_id();
    $done = false;
    foreach ($order->get_items() as $item) {
        $pid = (int) $item->get_product_id();
        $qty = max(1, (int) $item->get_quantity());
        // A renewal product extends the course(s) it belongs to.
        $courses = get_posts(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 20, 'no_found_rows' => true, 'meta_key' => '_nias_renew_product', 'meta_value' => $pid));
        foreach ($courses as $cid) {
            nias_access_extend($uid, (int) $cid, nias_access_renewal($cid)['len'] * $qty, 'renewal', $order_id);
            $done = true;
        }
        // The course itself: first purchase sets the period, a later one renews it.
        if (nias_is_course_post($pid) && nias_access_period($pid)) {
            $had = get_user_meta($uid, nias_access_key($pid), true);
            if ($had === '') {
                $d = $order->get_date_completed();
                nias_access_set_until($uid, $pid, ($d ? $d->getTimestamp() : time()) + nias_access_period($pid));
            } else {
                nias_access_extend($uid, $pid, nias_access_period($pid), 'repurchase', $order_id);
            }
            $done = true;
        }
    }
    if ($done) {
        $order->update_meta_data('_nias_access_applied', time());
        $order->save();
    }
}

/** Add time: from the current end date, or from now if it already passed. */
function nias_access_extend($user_id, $post_id, $len, $why = 'admin', $order_id = 0)
{
    if ($len <= 0) {
        return;
    }
    $cur = get_user_meta($user_id, nias_access_key($post_id), true);
    if ($cur !== '' && (int) $cur < 0) {
        return; // unlimited already
    }
    $base = max(time(), (int) ($cur !== '' ? $cur : nias_access_until($user_id, $post_id)));
    nias_access_set_until($user_id, $post_id, $base + $len);
    do_action('nias_access_renewed', (int) $user_id, (int) $post_id, $base + $len, $why, (int) $order_id);
}

/* -------------------------------------------------------------------------
 * Reminders
 * ---------------------------------------------------------------------- */

/** Days before the end to remind (e.g. 7 and 1). */
function nias_access_reminder_days()
{
    $raw  = function_exists('carbon_get_theme_option') ? (string) carbon_get_theme_option('nias_access_remind') : '';
    $raw  = $raw === '' ? '7,1' : $raw;
    $days = array_unique(array_filter(array_map('intval', preg_split('/[\s,،]+/u', strtr($raw, array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9')))), function ($d) { return $d > 0 && $d <= 365; }));
    rsort($days);
    return $days;
}

/** Moments to notify for one end date: "d7" => ts, …, "end" => until. */
function nias_access_moments($until)
{
    $m = array();
    foreach (nias_access_reminder_days() as $d) {
        $m['d' . $d] = $until - $d * DAY_IN_SECONDS;
    }
    $m['end'] = $until;
    return $m;
}

function nias_access_recompute_next($user_id)
{
    $next = 0;
    $now  = time();
    foreach ((array) get_user_meta($user_id, '_nias_access_courses', true) as $pid) {
        $until = (int) get_user_meta($user_id, nias_access_key($pid), true);
        if ($until <= 0) {
            continue;
        }
        $sent = (array) get_user_meta($user_id, '_nias_access_sent_' . (int) $pid, true);
        foreach (nias_access_moments($until) as $k => $ts) {
            if (!in_array($k, $sent, true) && $ts > $now - DAY_IN_SECONDS && (!$next || $ts < $next)) {
                $next = max($ts, $now);
            }
        }
    }
    if ($next) {
        update_user_meta($user_id, '_nias_access_next', $next);
    } else {
        delete_user_meta($user_id, '_nias_access_next');
    }
}

add_action('init', function () {
    $on = nias_access_enabled();
    $at = wp_next_scheduled('nias_access_tick');
    if ($on && !$at) {
        wp_schedule_event(time() + 120, 'hourly', 'nias_access_tick');
    } elseif (!$on && $at) {
        wp_clear_scheduled_hook('nias_access_tick');
    }
}, 30);

add_action('nias_access_tick', 'nias_access_tick');
function nias_access_tick()
{
    if (!nias_access_enabled()) {
        return;
    }
    $now = time();
    $ids = get_users(array('fields' => 'ID', 'number' => 200, 'count_total' => false, 'meta_query' => array(array('key' => '_nias_access_next', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC'))));
    foreach ($ids as $uid) {
        $uid = (int) $uid;
        foreach ((array) get_user_meta($uid, '_nias_access_courses', true) as $pid) {
            $pid   = (int) $pid;
            $until = (int) get_user_meta($uid, nias_access_key($pid), true);
            if ($until <= 0 || get_post_status($pid) !== 'publish' || nias_access_exempt($uid, $pid)) {
                continue;
            }
            $sent = (array) get_user_meta($uid, '_nias_access_sent_' . $pid, true);
            $due  = array();
            foreach (nias_access_moments($until) as $k => $ts) {
                if (!in_array($k, $sent, true) && $ts <= $now) {
                    $due[] = $k;
                    $sent[] = $k;
                }
            }
            if (!$due) {
                continue;
            }
            update_user_meta($uid, '_nias_access_sent_' . $pid, $sent);
            // Only the latest moment is announced (no burst after a long pause).
            $last = end($due);
            if ($last === 'end') {
                do_action('nias_access_expired_notice', $uid, $pid, $until);
            } elseif ($until > $now) {
                do_action('nias_access_expiring_notice', $uid, $pid, $until, (int) ceil(($until - $now) / DAY_IN_SECONDS));
            }
        }
        nias_access_recompute_next($uid);
    }
}

function nias_access_mail($user_id, $post_id, $subject, $line)
{
    if (carbon_get_theme_option('nias_access_email') !== 'on') {
        return;
    }
    $u = get_userdata($user_id);
    if (!$u || !is_email($u->user_email)) {
        return;
    }
    $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:2;color:#1f2a30">'
        . '<p>' . esc_html(sprintf(__('Dear %s,', 'nias-course-widget'), $u->display_name)) . '</p><p>' . esc_html($line) . '</p>'
        . '<p><a href="' . esc_url(nias_access_renew_url($post_id)) . '" style="display:inline-block;background:#1e83f0;color:#fff;text-decoration:none;padding:8px 18px;border-radius:9px">' . esc_html__('Renew access', 'nias-course-widget') . '</a></p></div>';
    wp_mail($u->user_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
}
add_action('nias_access_expiring_notice', function ($uid, $pid, $until, $days) {
    $c = get_the_title($pid);
    nias_access_mail($uid, $pid, sprintf(__('Your access to the course "%s" is ending soon', 'nias-course-widget'), $c), nias_fa_digits(sprintf(__('Your access to the course "%1$s" ends in %2$s days (%3$s). Renew it to keep access.', 'nias-course-widget'), $c, $days, nias_access_date($until))));
}, 10, 4);
add_action('nias_access_expired_notice', function ($uid, $pid, $until) {
    $c = get_the_title($pid);
    nias_access_mail($uid, $pid, sprintf(__('Your access to the course "%s" has ended', 'nias-course-widget'), $c), sprintf(__('Your access period for the course "%s" is over. By renewing, you get access again to all lessons and your previous progress.', 'nias-course-widget'), $c));
}, 10, 3);

/* -------------------------------------------------------------------------
 * Views: course page, account cards
 * ---------------------------------------------------------------------- */

add_filter('nias_modern_course_access', function ($info, $post_id, $user_id) {
    return nias_access_info($user_id, $post_id);
}, 10, 3);

add_action('nias_course_card_meta', function ($post_id) {
    $i = nias_access_info(get_current_user_id(), $post_id);
    if (!$i) {
        return;
    }
    $style = $i['expired'] ? 'background:#fdeeee;color:#b42318' : ($i['soon'] ? 'background:#fff4e5;color:#b45309' : '');
    $text  = $i['expired'] ? sprintf(__('Expired (%s)', 'nias-course-widget'), $i['date']) : sprintf(__('Access until %s', 'nias-course-widget'), $i['date']);
    echo '<span class="nias-cc-chip" style="' . esc_attr($style) . '">' . esc_html($text) . '</span>'; // phpcs:ignore
});

/* -------------------------------------------------------------------------
 * Admin: course meta box + user profile
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes_product', function () {
    if (!nias_access_enabled()) {
        return;
    }
    add_meta_box('nias-access', __('Time-limited access (Nias Course Builder)', 'nias-course-widget'), 'nias_access_metabox', 'product', 'side', 'default');
});

function nias_access_metabox($post)
{
    wp_nonce_field('nias_access_box', 'nias_access_nonce');
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    $len  = get_post_meta($post->ID, '_nias_access_len', true);
    $unit = get_post_meta($post->ID, '_nias_access_unit', true) === 'month' ? 'month' : 'day';
    $rp   = (int) get_post_meta($post->ID, '_nias_renew_product', true);
    $rd   = get_post_meta($post->ID, '_nias_renew_days', true);
    $item = ($rp && function_exists('nias_picker_product_item')) ? nias_picker_product_item($rp) : null;
    $def  = (int) carbon_get_theme_option('nias_access_default_days');
    ?>
    <p style="margin-top:0"><label><b><?php echo esc_html__('Access period after purchase', 'nias-course-widget'); ?></b></label><br>
        <input type="number" min="0" name="nias_access_len" value="<?php echo esc_attr($len); ?>" style="width:80px" placeholder="<?php echo esc_attr($def ? $def : '∞'); ?>">
        <select name="nias_access_unit"><option value="day" <?php selected($unit, 'day'); ?>><?php echo esc_html__('Days', 'nias-course-widget'); ?></option><option value="month" <?php selected($unit, 'month'); ?>><?php echo esc_html__('Months (30 days)', 'nias-course-widget'); ?></option></select>
        <br><small><?php echo esc_html($def ? sprintf(__('Empty = site default (%s days). 0 = unlimited.', 'nias-course-widget'), $def) : __('Empty or 0 = unlimited.', 'nias-course-widget')); ?></small></p>
    <p><label><b><?php echo esc_html__('Renewal product', 'nias-course-widget'); ?></b></label>
        <span data-nias-renew-pick style="display:block;margin-top:4px"></span>
        <input type="hidden" name="nias_renew_product" value="<?php echo (int) $rp; ?>">
        <small><?php echo esc_html__('Empty = renew by buying this same course again. You can create a separate product (e.g. a cheaper "3-month renewal") and select it here.', 'nias-course-widget'); ?></small></p>
    <p><label><b><?php echo esc_html__('Length of each renewal (days)', 'nias-course-widget'); ?></b></label><br>
        <input type="number" min="0" name="nias_renew_days" value="<?php echo esc_attr($rd); ?>" style="width:80px"> <small><?php echo esc_html__('Empty = same as the course period', 'nias-course-widget'); ?></small></p>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var h = document.querySelector('[data-nias-renew-pick]'), inp = document.querySelector('[name=nias_renew_product]');
        if (!h || !window.NiasPicker) return;
        window.NiasPicker.attach(h, { source: 'product', emptyLabel: 'خرید دوبارهٔ همین دوره', placeholder: 'جستجوی محصول…', value: <?php echo wp_json_encode($item ? array($item) : array()); ?>, onChange: function (v) { inp.value = v[0] ? v[0].id : ''; } });
    });
    </script>
    <?php
}

add_action('save_post_product', function ($post_id) {
    if (!isset($_POST['nias_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_access_nonce'])), 'nias_access_box') || !current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    $old = (string) get_post_meta($post_id, '_nias_access_len', true);
    $len = isset($_POST['nias_access_len']) && $_POST['nias_access_len'] !== '' ? (string) max(0, (int) $_POST['nias_access_len']) : '';
    update_post_meta($post_id, '_nias_access_len', $len);
    update_post_meta($post_id, '_nias_access_unit', isset($_POST['nias_access_unit']) && $_POST['nias_access_unit'] === 'month' ? 'month' : 'day');
    $rp = isset($_POST['nias_renew_product']) ? (int) $_POST['nias_renew_product'] : 0;
    if ($rp && $rp !== (int) $post_id) {
        update_post_meta($post_id, '_nias_renew_product', $rp);
    } else {
        delete_post_meta($post_id, '_nias_renew_product');
    }
    $rd = isset($_POST['nias_renew_days']) && $_POST['nias_renew_days'] !== '' ? (string) max(0, (int) $_POST['nias_renew_days']) : '';
    update_post_meta($post_id, '_nias_renew_days', $rd);
    // Students enrolled before the course got a period count from today.
    if ((int) $len > 0 && (int) $old <= 0 && !get_post_meta($post_id, '_nias_access_since', true)) {
        update_post_meta($post_id, '_nias_access_since', time());
    }
});

/** When the site-wide default is switched on, it counts from that day. */
add_action('update_option__nias_access_default_days', function ($old, $new) {
    if ((int) $new > 0 && (int) $old <= 0) {
        update_option('nias_access_default_since', time(), false);
    }
}, 10, 2);
add_filter('nias_access_period', function ($len, $post_id) {
    if (get_post_meta($post_id, '_nias_access_len', true) === '' && !get_post_meta($post_id, '_nias_access_since', true) && ($s = (int) get_option('nias_access_default_since'))) {
        update_post_meta($post_id, '_nias_access_since', $s);
    }
    return $len;
}, 5, 2);

// Profile: the user's course access.
add_action('edit_user_profile', 'nias_access_profile_box', 12);
add_action('show_user_profile', 'nias_access_profile_box', 12);
function nias_access_profile_box($user)
{
    if (!nias_access_enabled() || !current_user_can('edit_users') || !function_exists('nias_course_completed_product_ids')) {
        return;
    }
    $rows = array();
    foreach (nias_course_completed_product_ids($user->ID) as $pid) {
        if (!nias_is_course_post($pid)) {
            continue;
        }
        $raw = get_user_meta($user->ID, nias_access_key($pid), true);
        $until = nias_access_until($user->ID, $pid);
        if ($raw === '' && !$until && !nias_access_period($pid)) {
            continue;
        }
        $rows[$pid] = (int) get_user_meta($user->ID, nias_access_key($pid), true);
    }
    echo '<h2 id="nias-access">' . esc_html__('Time-limited course access (Nias Course Builder)', 'nias-course-widget') . '</h2>';
    if (isset($_GET['nias_access_done'])) {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('Done.', 'nias-course-widget') . '</p></div>';
    }
    if (!$rows) {
        echo '<p>' . esc_html__('This user has no time-limited course.', 'nias-course-widget') . '</p>';
        return;
    }
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('Course', 'nias-course-widget') . '</th><th>' . esc_html__('Access ends', 'nias-course-widget') . '</th><th>' . esc_html__('Change', 'nias-course-widget') . '</th></tr></thead><tbody>';
    foreach ($rows as $pid => $until) {
        $url = function ($op, $days = 0) use ($user, $pid) {
            return wp_nonce_url(admin_url('admin-post.php?action=nias_access_admin&user=' . (int) $user->ID . '&course=' . (int) $pid . '&op=' . $op . '&days=' . (int) $days), 'nias_access_admin_' . $user->ID);
        };
        $state = $until < 0 ? __('Unlimited', 'nias-course-widget') : ($until <= time() ? sprintf(__('Expired: %s', 'nias-course-widget'), nias_access_date($until)) : nias_access_date($until));
        echo '<tr><td>' . esc_html(get_the_title($pid)) . '</td><td>' . esc_html($state) . '</td><td>'
            . '<a class="button button-small" href="' . esc_url($url('extend', 30)) . '">+۳۰ ' . esc_html__('Days', 'nias-course-widget') . '</a> '
            . '<a class="button button-small" href="' . esc_url($url('extend', 90)) . '">+۹۰ ' . esc_html__('Days', 'nias-course-widget') . '</a> '
            . '<a class="button button-small" href="' . esc_url($url('unlimited')) . '">' . esc_html__('Unlimited', 'nias-course-widget') . '</a> '
            . '<a class="button button-small" href="' . esc_url($url('end')) . '" onclick="return confirm(\'' . esc_js(__('End access right now?', 'nias-course-widget')) . '\')">' . esc_html__('End now', 'nias-course-widget') . '</a> '
            . '<a class="button button-small" href="' . esc_url($url('reset')) . '">' . esc_html__('Recalculate', 'nias-course-widget') . '</a>'
            . '</td></tr>';
    }
    echo '</tbody></table>';
}

add_action('admin_post_nias_access_admin', function () {
    $uid = isset($_GET['user']) ? (int) $_GET['user'] : 0;
    $pid = isset($_GET['course']) ? (int) $_GET['course'] : 0;
    if (!$uid || !$pid || !current_user_can('edit_user', $uid) || !check_admin_referer('nias_access_admin_' . $uid)) {
        wp_die(esc_html__('You do not have access.', 'nias-course-widget'), 403);
    }
    $op = isset($_GET['op']) ? sanitize_key(wp_unslash($_GET['op'])) : '';
    if ($op === 'extend') {
        $cur = get_user_meta($uid, nias_access_key($pid), true);
        if ($cur !== '' && (int) $cur < 0) {
            delete_user_meta($uid, nias_access_key($pid));
        }
        nias_access_extend($uid, $pid, max(1, (int) $_GET['days']) * DAY_IN_SECONDS, 'admin');
    } elseif ($op === 'unlimited') {
        nias_access_set_until($uid, $pid, -1);
    } elseif ($op === 'end') {
        nias_access_set_until($uid, $pid, time() - 1);
    } elseif ($op === 'reset') {
        delete_user_meta($uid, nias_access_key($pid));
        nias_access_until($uid, $pid);
    }
    wp_safe_redirect(add_query_arg('nias_access_done', '1', get_edit_user_link($uid)) . '#nias-access');
    exit;
});
