<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «امتیاز و نظر خریداران» (verified student reviews).
 *
 *   - Only students who have the course (bought / free and enrolled) and
 *     reached a minimum progress (default 30%) can rate 1–5 stars and write a
 *     review — one per student and course (they can edit it).
 *   - Stored as WordPress comments of type "review" with meta "rating" and
 *     "verified" — the same shape WooCommerce uses, so a product's average and
 *     its review schema keep working. «دوره» posts get their own average
 *     (_nias_rating_avg / _nias_rating_count) and Course schema.
 *   - Optional moderation (new reviews wait for approval in «دیدگاه‌ها»).
 *     The course's instructors and admins can answer a review.
 *   - Shown under the course view (average, distribution, reviews) and as a
 *     chip on «دوره‌های من» cards.
 *
 * @package nias-course-widget
 */

function nias_reviews_enabled()
{
    return (bool) apply_filters('nias_reviews_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_reviews_enabled') === 'on');
}

function nias_reviews_min_progress()
{
    $v = function_exists('carbon_get_theme_option') ? carbon_get_theme_option('nias_reviews_min_progress') : '';
    return $v === '' || $v === null ? 30 : max(0, min(100, (int) $v));
}

/* -------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------- */

/** Average and counts of a course: {avg, count, dist[5..1]} (cached in post meta). */
function nias_reviews_summary($post_id, $fresh = false)
{
    $post_id = (int) $post_id;
    $cached  = get_post_meta($post_id, '_nias_rating_summary', true);
    if (!$fresh && is_array($cached) && isset($cached['avg'])) {
        return $cached;
    }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT CAST(m.meta_value AS UNSIGNED) r, COUNT(*) n FROM {$wpdb->comments} c JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating'
         WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_type = 'review' AND c.comment_parent = 0 GROUP BY r",
        $post_id
    ));
    $dist = array(5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0);
    $sum  = 0;
    $cnt  = 0;
    foreach ($rows as $r) {
        $k = max(1, min(5, (int) $r->r));
        $dist[$k] += (int) $r->n;
        $sum += $k * (int) $r->n;
        $cnt += (int) $r->n;
    }
    $out = array('avg' => $cnt ? round($sum / $cnt, 1) : 0, 'count' => $cnt, 'dist' => $dist);
    update_post_meta($post_id, '_nias_rating_summary', $out);
    update_post_meta($post_id, '_nias_rating_avg', $out['avg']);
    update_post_meta($post_id, '_nias_rating_count', $cnt);
    return $out;
}

/** Recount when a review changes (WooCommerce updates its own product meta too). */
function nias_reviews_touch($comment_id)
{
    $c = get_comment($comment_id);
    if ($c && $c->comment_type === 'review' && nias_is_course_post((int) $c->comment_post_ID)) {
        nias_reviews_summary((int) $c->comment_post_ID, true);
    }
}
add_action('wp_set_comment_status', 'nias_reviews_touch');
add_action('edit_comment', 'nias_reviews_touch');
add_action('deleted_comment', function ($id, $c) {
    if ($c && $c->comment_type === 'review') {
        nias_reviews_summary((int) $c->comment_post_ID, true);
    }
}, 10, 2);

/** The user's own review of a course (top-level). */
function nias_reviews_mine($user_id, $post_id)
{
    $c = get_comments(array('post_id' => (int) $post_id, 'user_id' => (int) $user_id, 'type' => 'review', 'parent' => 0, 'number' => 1, 'status' => 'all'));
    return $c ? $c[0] : null;
}

/** Can this user review? '' = yes, else the reason. */
function nias_reviews_block_reason($user_id, $post_id)
{
    if (!$user_id) {
        return __('Log in to your account to leave a review.', 'nias-course-widget');
    }
    if (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id)) {
        return __('The course instructor cannot rate their own course.', 'nias-course-widget');
    }
    $has = function_exists('nias_course_user_completed') && nias_course_user_completed($user_id, $post_id);
    if (!$has && function_exists('nias_course_is_free') && nias_course_is_free($post_id)) {
        $has = function_exists('nias_course_enrolled_at') && nias_course_enrolled_at($user_id, $post_id);
    }
    if (!$has) {
        return __('Only students of this course can leave a review.', 'nias-course-widget');
    }
    $min = nias_reviews_min_progress();
    $p   = function_exists('nias_cert_user_progress') ? nias_cert_user_progress($user_id, $post_id) : 100;
    if ($p < $min) {
        return sprintf(__('You can rate and review after watching at least %s%% of the course (your progress: %s%%).', 'nias-course-widget'), nias_fa_digits($min), nias_fa_digits($p));
    }
    return '';
}

function nias_reviews_public($c, $staff_ids = array())
{
    $replies = array();
    foreach (get_comments(array('parent' => $c->comment_ID, 'status' => 'approve', 'orderby' => 'comment_date', 'order' => 'ASC', 'number' => 5)) as $r) {
        $replies[] = array('name' => $r->comment_author, 'text' => $r->comment_content, 'staff' => true, 'date' => nias_reviews_date($r->comment_date));
    }
    return array(
        'id'      => (int) $c->comment_ID,
        'name'    => $c->comment_author,
        'rating'  => (int) get_comment_meta($c->comment_ID, 'rating', true),
        'text'    => $c->comment_content,
        'date'    => nias_reviews_date($c->comment_date),
        'pending' => $c->comment_approved !== '1',
        'replies' => $replies,
    );
}

function nias_reviews_date($mysql)
{
    $ts = strtotime($mysql);
    return nias_fa_digits(function_exists('nias_cert_jalali') ? nias_cert_jalali(wp_date('Y-m-d', $ts), 'long') : wp_date('Y-m-d', $ts));
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function nias_reviews_request()
{
    $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!nias_reviews_enabled() || !$pid || !nias_is_course_post($pid) || !check_ajax_referer('nias_reviews_' . $pid, 'nonce', false)) {
        wp_send_json_error(array('message' => __('Invalid request.', 'nias-course-widget')), 403);
    }
    return $pid;
}

add_action('wp_ajax_nias_reviews_list', 'nias_reviews_ajax_list');
add_action('wp_ajax_nopriv_nias_reviews_list', 'nias_reviews_ajax_list');
function nias_reviews_ajax_list()
{
    $pid  = nias_reviews_request();
    $page = max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
    $list = get_comments(array('post_id' => $pid, 'type' => 'review', 'parent' => 0, 'status' => 'approve', 'number' => 11, 'offset' => ($page - 1) * 10, 'orderby' => 'comment_date', 'order' => 'DESC'));
    wp_send_json_success(array('items' => array_map('nias_reviews_public', array_slice($list, 0, 10)), 'more' => count($list) > 10));
}

add_action('wp_ajax_nias_reviews_submit', function () {
    $pid = nias_reviews_request();
    $uid = get_current_user_id();
    if ($why = nias_reviews_block_reason($uid, $pid)) {
        wp_send_json_error(array('message' => $why));
    }
    $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $text   = isset($_POST['text']) ? trim(sanitize_textarea_field(wp_unslash($_POST['text']))) : '';
    if ($rating < 1 || $rating > 5) {
        wp_send_json_error(array('message' => __('Choose a rating (1 to 5 stars).', 'nias-course-widget')));
    }
    if (mb_strlen($text) < 10) {
        wp_send_json_error(array('message' => __('Please write a few words about your experience with the course.', 'nias-course-widget')));
    }
    $text     = mb_substr($text, 0, 3000);
    $u        = wp_get_current_user();
    $moderate = function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_reviews_moderate') === 'on';
    $mine     = nias_reviews_mine($uid, $pid);
    if ($mine) {
        wp_update_comment(array('comment_ID' => $mine->comment_ID, 'comment_content' => $text, 'comment_approved' => $moderate ? 0 : 1));
        update_comment_meta($mine->comment_ID, 'rating', $rating);
        $id = (int) $mine->comment_ID;
    } else {
        $id = wp_insert_comment(array(
            'comment_post_ID'      => $pid,
            'comment_author'       => $u->display_name,
            'comment_author_email' => $u->user_email,
            'comment_content'      => $text,
            'comment_type'         => 'review',
            'comment_parent'       => 0,
            'user_id'              => $uid,
            'comment_approved'     => $moderate ? 0 : 1,
            'comment_author_IP'    => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'comment_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 250) : '',
        ));
        update_comment_meta($id, 'rating', $rating);
        update_comment_meta($id, 'verified', 1);
    }
    if (get_post_type($pid) === 'product' && class_exists('WC_Comments')) {
        WC_Comments::clear_transients($pid); // WooCommerce average/count/schema
    }
    nias_reviews_summary($pid, true);
    do_action('nias_review_saved', $id, $pid, $uid, $rating);
    wp_send_json_success(array('item' => nias_reviews_public(get_comment($id)), 'summary' => nias_reviews_summary($pid), 'moderated' => $moderate));
});

add_action('wp_ajax_nias_reviews_reply', function () {
    $pid = nias_reviews_request();
    $uid = get_current_user_id();
    if (!user_can($uid, 'manage_options') && !(function_exists('nias_can_edit_course') && nias_can_edit_course($uid, $pid))) {
        wp_send_json_error(array('message' => __('Only the course instructor and the admin can reply.', 'nias-course-widget')), 403);
    }
    $parent = isset($_POST['parent']) ? (int) $_POST['parent'] : 0;
    $p      = $parent ? get_comment($parent) : null;
    $text   = isset($_POST['text']) ? trim(sanitize_textarea_field(wp_unslash($_POST['text']))) : '';
    if (!$p || (int) $p->comment_post_ID !== $pid || $text === '') {
        wp_send_json_error(array('message' => __('The reply was not saved.', 'nias-course-widget')));
    }
    $u = wp_get_current_user();
    wp_insert_comment(array('comment_post_ID' => $pid, 'comment_author' => $u->display_name, 'comment_author_email' => $u->user_email, 'comment_content' => mb_substr($text, 0, 3000), 'comment_type' => 'review', 'comment_parent' => $parent, 'user_id' => $uid, 'comment_approved' => 1));
    wp_send_json_success(array('item' => nias_reviews_public(get_comment($parent))));
});

/* -------------------------------------------------------------------------
 * Course view block
 * ---------------------------------------------------------------------- */

function nias_reviews_stars($avg, $size = 16)
{
    $h = '';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $avg >= $i ? 100 : ($avg > $i - 1 ? (int) round(($avg - $i + 1) * 100) : 0);
        $h .= '<span class="nrv-star" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px"><i style="width:' . $fill . '%"></i></span>';
    }
    return '<span class="nrv-stars" aria-label="' . esc_attr(sprintf(__('%s out of 5', 'nias-course-widget'), nias_fa_digits($avg))) . '">' . $h . '</span>';
}

add_filter('nias_modern_course_extra', 'nias_reviews_block', 20, 2);
function nias_reviews_block($html, $post_id)
{
    if (!nias_reviews_enabled() || !nias_is_course_post($post_id)) {
        return $html;
    }
    $uid   = get_current_user_id();
    $sum   = nias_reviews_summary($post_id);
    $mine  = $uid ? nias_reviews_mine($uid, $post_id) : null;
    $why   = nias_reviews_block_reason($uid, $post_id);
    $staff = $uid && (user_can($uid, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($uid, $post_id)));
    $cfg   = array(
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('nias_reviews_' . (int) $post_id),
        'pid'   => (int) $post_id,
        'can'   => $why === '',
        'why'   => $why,
        'staff' => $staff,
        'mine'  => $mine ? nias_reviews_public($mine) : null,
    );
    ob_start();
    ?>
    <section class="nrv" dir="rtl" data-nrv='<?php echo esc_attr(wp_json_encode($cfg)); ?>'>
        <style>
        .nrv{max-width:calc(100% - 34px);margin:22px auto;padding:20px;background:#fff;border:1px solid #e6ecf5;border-radius:18px;font-family:inherit;color:#1f2733;box-sizing:border-box}
        .nrv h2{margin:0 0 14px;font-size:18px}
        .nrv-sum{display:flex;flex-wrap:wrap;gap:24px;align-items:center;margin-bottom:16px}
        .nrv-big{text-align:center;min-width:120px}
        .nrv-big b{display:block;font-size:40px;font-weight:800;line-height:1.1}
        .nrv-big span{color:#64748b;font-size:13px}
        .nrv-stars{display:inline-flex;gap:2px;direction:ltr}
        .nrv-star{position:relative;display:inline-block;background:#e2e8f0;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z'/%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z'/%3E%3C/svg%3E") center/contain no-repeat}
        .nrv-star i{position:absolute;inset:0 auto 0 0;background:#f59e0b}
        .nrv-dist{flex:1 1 260px;display:flex;flex-direction:column;gap:5px}
        .nrv-dist div{display:grid;grid-template-columns:30px 1fr 40px;gap:8px;align-items:center;font-size:12.5px;color:#64748b}
        .nrv-dist em{display:block;height:8px;border-radius:99px;background:#eef2f8;overflow:hidden}
        .nrv-dist em i{display:block;height:100%;background:#f59e0b;border-radius:99px}
        .nrv-form{border:1px dashed #c8d4ea;border-radius:14px;padding:14px;margin-bottom:16px;background:#fafcff}
        .nrv-pick{display:inline-flex;gap:4px;direction:ltr;margin:6px 0 10px}
        .nrv-pick button{border:0;background:none;cursor:pointer;padding:0;font-size:28px;line-height:1;color:#cbd5e1}
        .nrv-pick button.on{color:#f59e0b}
        .nrv-form textarea{width:100%;box-sizing:border-box;border:1px solid #dbe3ef;border-radius:10px;padding:9px 11px;font:inherit;min-height:80px}
        .nrv-form .nrv-act{display:flex;gap:10px;align-items:center;margin-top:8px}
        .nrv-form button.go{border:0;background:#1e83f0;color:#fff;border-radius:10px;padding:9px 18px;font:inherit;font-weight:700;cursor:pointer}
        .nrv-msg{font-size:13px;color:#b42318}
        .nrv-why{font-size:13px;color:#64748b;background:#f8fafc;border-radius:10px;padding:10px 12px;margin-bottom:14px}
        .nrv-it{border-top:1px solid #eef1f6;padding:14px 0}
        .nrv-it-h{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center}
        .nrv-it-h b{font-size:14px}
        .nrv-ok{font-size:11.5px;font-weight:700;color:#137a3a;background:#e7f7ec;border-radius:99px;padding:1px 9px}
        .nrv-d{font-size:12px;color:#94a3b8}
        .nrv-it p{margin:8px 0 0;line-height:2;white-space:pre-wrap}
        .nrv-rep{margin:10px 22px 0 0;padding:10px 12px;background:#f5f8ff;border-radius:12px;font-size:13.5px;line-height:1.9}
        .nrv-rep b{color:#1e4fb8}
        .nrv-rf{display:flex;gap:8px;margin-top:8px}
        .nrv-rf input{flex:1;border:1px solid #dbe3ef;border-radius:9px;padding:7px 10px;font:inherit}
        .nrv-rf button,.nrv-more{border:1px solid #dbe3ef;background:#fff;border-radius:9px;padding:7px 14px;font:inherit;cursor:pointer}
        </style>
        <h2><?php echo esc_html__('Student reviews', 'nias-course-widget'); ?></h2>
        <div class="nrv-sum">
            <div class="nrv-big"><b data-nrv-avg><?php echo esc_html($sum['count'] ? nias_fa_digits(number_format_i18n($sum['avg'], 1)) : '—'); ?></b><?php echo nias_reviews_stars($sum['avg'], 18); // phpcs:ignore -- built safely ?><br><span data-nrv-count><?php echo esc_html(sprintf(__('%s reviews', 'nias-course-widget'), nias_fa_digits($sum['count']))); ?></span></div>
            <div class="nrv-dist"><?php foreach ($sum['dist'] as $k => $n) : $w = $sum['count'] ? round($n / $sum['count'] * 100) : 0; ?><div><span><?php echo esc_html(nias_fa_digits($k)); ?> ★</span><em><i style="width:<?php echo (int) $w; ?>%"></i></em><span><?php echo esc_html(nias_fa_digits($n)); ?></span></div><?php endforeach; ?></div>
        </div>
        <div data-nrv-form></div>
        <div data-nrv-list></div>
    </section>
    <script>
    (function () {
        var root = document.currentScript && document.currentScript.previousElementSibling;
        if (!root || !root.hasAttribute('data-nrv')) { root = document.querySelector('[data-nrv]'); }
        if (!root || root._b) return; root._b = 1;
        var cfg = JSON.parse(root.getAttribute('data-nrv')), page = 1;
        var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function stars(n) { var h = ''; for (var i = 1; i <= 5; i++) { h += '<span style="color:' + (i <= n ? '#f59e0b' : '#cbd5e1') + '">★</span>'; } return '<span style="direction:ltr;display:inline-block;letter-spacing:1px">' + h + '</span>'; }
        function post(d) { var b = new URLSearchParams(); b.append('nonce', cfg.nonce); b.append('product_id', cfg.pid); Object.keys(d).forEach(function (k) { b.append(k, d[k]); }); return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }); }
        function item(it) {
            return '<div class="nrv-it" data-id="' + it.id + '"><div class="nrv-it-h"><b>' + esc(it.name) + '</b>' + stars(it.rating) + '<span class="nrv-ok">دانشجوی دوره</span><span class="nrv-d">' + esc(it.date) + '</span></div><p>' + esc(it.text) + '</p>' +
                (it.replies || []).map(function (r) { return '<div class="nrv-rep"><b>پاسخ ' + esc(r.name) + ':</b> ' + esc(r.text) + '</div>'; }).join('') +
                (cfg.staff ? '<div class="nrv-rf"><input placeholder="پاسخ مدرس…"><button type="button" data-reply>پاسخ</button></div>' : '') + '</div>';
        }
        function form() {
            var host = root.querySelector('[data-nrv-form]');
            if (!cfg.can) { host.innerHTML = (cfg.mine || cfg.staff) ? '' : '<div class="nrv-why">' + esc(cfg.why) + '</div>'; return; }
            var m = cfg.mine, r = m ? m.rating : 0;
            host.innerHTML = '<div class="nrv-form"><b>' + (m ? 'نظر شما' + (m.pending ? ' (در انتظار تأیید)' : '') + ' — ویرایش' : 'امتیاز و نظر شما دربارهٔ این دوره') + '</b><div class="nrv-pick">' + [1, 2, 3, 4, 5].map(function (i) { return '<button type="button" data-r="' + i + '" class="' + (i <= r ? 'on' : '') + '" aria-label="' + fa(i) + ' ستاره">★</button>'; }).join('') + '</div>' +
                '<textarea placeholder="این دوره چطور بود؟ به چه کسی پیشنهادش می‌کنید؟">' + esc(m ? m.text : '') + '</textarea><div class="nrv-act"><button type="button" class="go">' + (m ? 'ذخیرهٔ تغییرات' : 'ثبت نظر') + '</button><span class="nrv-msg"></span></div></div>';
            host.querySelectorAll('[data-r]').forEach(function (b) { b.addEventListener('click', function () { r = +b.getAttribute('data-r'); host.querySelectorAll('[data-r]').forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-r') <= r); }); }); });
            host.querySelector('.go').addEventListener('click', function () {
                var msg = host.querySelector('.nrv-msg'), btn = this; btn.disabled = true; msg.textContent = '';
                post({ action: 'nias_reviews_submit', rating: r, text: host.querySelector('textarea').value }).then(function (res) {
                    btn.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data && res.data.message) || 'خطا'; return; }
                    cfg.mine = res.data.item; var s = res.data.summary;
                    root.querySelector('[data-nrv-avg]').textContent = s.count ? fa(String(s.avg)) : '—';
                    root.querySelector('[data-nrv-count]').textContent = fa(s.count) + ' نظر';
                    form(); list(true);
                    var done = host.querySelector('.nrv-msg'); if (done) { done.style.color = '#137a3a'; done.textContent = res.data.moderated ? 'نظر شما ثبت شد و پس از تأیید نمایش داده می‌شود.' : 'نظر شما ثبت شد. ممنون!'; }
                });
            });
        }
        function list(reset) {
            var host = root.querySelector('[data-nrv-list]');
            if (reset) { page = 1; host.innerHTML = ''; }
            post({ action: 'nias_reviews_list', page: page }).then(function (res) {
                var items = (res && res.success && res.data.items) || [];
                var old = host.querySelector('.nrv-more'); if (old) old.remove();
                if (!items.length && page === 1) { host.innerHTML = '<div class="nrv-d" style="padding:6px 0">هنوز نظری ثبت نشده است.</div>'; return; }
                host.insertAdjacentHTML('beforeend', items.map(item).join(''));
                if (res.data.more) host.insertAdjacentHTML('beforeend', '<button type="button" class="nrv-more">نظرهای بیشتر</button>');
            });
        }
        root.addEventListener('click', function (e) {
            if (e.target.closest('.nrv-more')) { page++; list(false); return; }
            var b = e.target.closest('[data-reply]'); if (!b) return;
            var it = b.closest('.nrv-it'), inp = it.querySelector('.nrv-rf input');
            if (!inp.value.trim()) return; b.disabled = true;
            post({ action: 'nias_reviews_reply', parent: it.getAttribute('data-id'), text: inp.value }).then(function (res) { if (res && res.success) { it.outerHTML = item(res.data.item); } else { b.disabled = false; alert((res && res.data && res.data.message) || 'خطا'); } });
        });
        form(); list(true);
    })();
    </script>
    <?php
    return $html . ob_get_clean();
}

/* «دوره‌های من» card chip */
add_action('nias_course_card_meta', function ($post_id) {
    if (!nias_reviews_enabled()) {
        return;
    }
    $s = nias_reviews_summary($post_id);
    if ($s['count']) {
        echo '<span class="nias-cc-chip" style="background:#fff7e6;color:#b45309">★ ' . esc_html(nias_fa_digits(number_format_i18n($s['avg'], 1)) . ' (' . nias_fa_digits($s['count']) . ')') . '</span>';
    }
}, 5);

/* Course schema with the rating for «دوره» posts (products get WooCommerce's). */
add_action('wp_head', function () {
    if (!nias_reviews_enabled() || !defined('NIAS_COURSE_CPT') || !is_singular(NIAS_COURSE_CPT)) {
        return;
    }
    $pid = get_queried_object_id();
    $s   = nias_reviews_summary($pid);
    if (!$s['count']) {
        return;
    }
    $ld = array('@context' => 'https://schema.org', '@type' => 'Course', 'name' => get_the_title($pid), 'description' => wp_strip_all_tags(get_the_excerpt($pid)), 'provider' => array('@type' => 'Organization', 'name' => get_bloginfo('name'), 'sameAs' => home_url('/')),
        'aggregateRating' => array('@type' => 'AggregateRating', 'ratingValue' => $s['avg'], 'reviewCount' => $s['count'], 'bestRating' => 5, 'worstRating' => 1));
    echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
});
