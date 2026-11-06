<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instructor panel: course creation + admin approval (نیاز به تأیید).
 *
 *   - «دورهٔ جدید»: an instructor creates a course from the panel. With
 *     WooCommerce it is a WooCommerce product (simple, virtual); without it a
 *     «دوره» (nias_course). With approval on it is created as «در انتظار
 *     بررسی» (WordPress "pending") until the admin publishes it.
 *   - Changes to a live (published) course made by an instructor while
 *     approval is on are NOT applied: they are kept in post meta
 *     _nias_pending (curriculum + details) and the site keeps showing the
 *     current version. The instructor keeps editing their pending version.
 *   - «درخواست‌های مدرسین» (admin): new courses waiting for review and
 *     pending changes with a readable summary; approve / reject (+ note).
 *
 * @package nias-course-widget
 */

define('NIAS_PENDING_META', '_nias_pending');

/** Approval required for instructor changes? */
function nias_inst_approval_on()
{
    return function_exists('nias_inst_panel_on') && nias_inst_panel_on() && get_option('_nias_inst_panel_approval') === 'on';
}

/** Can instructors create courses from the panel? */
function nias_inst_can_create($user_id = 0)
{
    return function_exists('nias_inst_panel_user') && nias_inst_panel_user($user_id) && get_option('_nias_inst_panel_create', 'on') !== 'off';
}

/** Is the course live (visitors see it)? */
function nias_course_is_live($post_id)
{
    return in_array(get_post_status($post_id), array('publish', 'private', 'future'), true);
}

/** Must this user's edit of this course wait for the admin? */
function nias_inst_needs_approval($user_id, $post_id)
{
    return nias_inst_approval_on() && !user_can($user_id, 'edit_post', $post_id) && nias_course_is_live($post_id);
}

function nias_course_pending_get($post_id)
{
    $p = get_post_meta((int) $post_id, NIAS_PENDING_META, true);
    return is_array($p) ? $p : array();
}

/** Store one part (sections | details) of an instructor's pending change. */
function nias_course_pending_set($post_id, $part, $value, $user_id)
{
    $p = nias_course_pending_get($post_id);
    $first = !$p;
    $p[$part] = $value;
    $p['by'] = (int) $user_id;
    $p['at'] = current_time('mysql');
    update_post_meta((int) $post_id, NIAS_PENDING_META, $p);
    if ($first) {
        nias_inst_notify_admin($post_id, $user_id, 'changes');
    }
}

function nias_course_pending_clear($post_id)
{
    delete_post_meta((int) $post_id, NIAS_PENDING_META);
}

/** Curriculum to show an instructor in the editor: their pending version, if any. */
function nias_course_sections_for_editor($post_id, $user_id)
{
    $p = nias_course_pending_get($post_id);
    if (isset($p['sections']) && !user_can($user_id, 'edit_post', $post_id)) {
        return $p['sections'];
    }
    return carbon_get_post_meta($post_id, 'course_sections');
}

/* -------------------------------------------------------------------------
 * Course details (title, descriptions, image, price) — create / edit
 * ---------------------------------------------------------------------- */

/** Post type a new course gets: the WooCommerce product, or «دوره» without WooCommerce. */
function nias_inst_new_course_type()
{
    return (function_exists('nias_wc_active') && nias_wc_active()) ? 'product' : NIAS_COURSE_CPT;
}

/** Current details (live, or the instructor's pending ones). */
function nias_inst_course_details($post_id, $user_id)
{
    $post = get_post($post_id);
    $d = array(
        'title'     => $post ? $post->post_title : '',
        'excerpt'   => $post ? $post->post_excerpt : '',
        'content'   => $post ? $post->post_content : '',
        'thumbnail' => $post ? (int) get_post_thumbnail_id($post_id) : 0,
        'price'     => '',
    );
    if ($post && $post->post_type === 'product' && function_exists('wc_get_product') && ($wp = wc_get_product($post_id))) {
        $d['price'] = (string) $wp->get_regular_price();
    }
    $p = nias_course_pending_get($post_id);
    if (isset($p['details']) && !user_can($user_id, 'edit_post', $post_id)) {
        $d = array_merge($d, $p['details']);
    }
    return $d;
}

/** Apply details to a course (admin approval, or a direct save). */
function nias_inst_apply_details($post_id, $d, $with_price)
{
    $upd = array('ID' => (int) $post_id);
    foreach (array('title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content') as $k => $f) {
        if (isset($d[$k])) {
            $upd[$f] = $d[$k];
        }
    }
    wp_update_post(wp_slash($upd));
    if (isset($d['thumbnail'])) {
        $d['thumbnail'] ? set_post_thumbnail($post_id, (int) $d['thumbnail']) : delete_post_thumbnail($post_id);
    }
    if ($with_price && isset($d['price']) && get_post_type($post_id) === 'product' && function_exists('wc_get_product') && ($wp = wc_get_product($post_id))) {
        $wp->set_regular_price($d['price'] === '' ? '' : wc_format_decimal($d['price']));
        $wp->save();
    }
}

add_action('admin_post_nias_inst_course_save', 'nias_inst_course_save');
function nias_inst_course_save()
{
    $uid  = get_current_user_id();
    $pid  = isset($_POST['course']) ? (int) $_POST['course'] : 0;
    $back = wp_validate_redirect(isset($_POST['back']) ? esc_url_raw(wp_unslash($_POST['back'])) : '', home_url('/'));
    if (!$uid || !wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'nias_inst_course_' . $pid)) {
        wp_die(esc_html__('درخواست منقضی شده است.', 'nias-course-widget'), '', array('response' => 403, 'back_link' => true));
    }
    if ($pid ? !nias_can_edit_course($uid, $pid) : !nias_inst_can_create($uid)) {
        wp_die(esc_html__('دسترسی لازم را ندارید.', 'nias-course-widget'), '', array('response' => 403, 'back_link' => true));
    }
    $title = sanitize_text_field(wp_unslash(isset($_POST['title']) ? $_POST['title'] : ''));
    if ($title === '') {
        wp_safe_redirect(add_query_arg('nip_notice', 'title', $back));
        exit;
    }
    $thumb = isset($_POST['thumbnail']) ? (int) $_POST['thumbnail'] : 0;
    if ($thumb && (get_post_type($thumb) !== 'attachment' || (!user_can($uid, 'edit_others_posts') && (int) get_post_field('post_author', $thumb) !== $uid))) {
        $thumb = 0; // only their own uploads
    }
    $d = array(
        'title'     => $title,
        'excerpt'   => sanitize_textarea_field(wp_unslash(isset($_POST['excerpt']) ? $_POST['excerpt'] : '')),
        'content'   => function_exists('nias_course_kses_limited') ? nias_course_kses_limited(wp_unslash(isset($_POST['content']) ? $_POST['content'] : '')) : wp_kses_post(wp_unslash(isset($_POST['content']) ? $_POST['content'] : '')),
        'thumbnail' => $thumb,
    );
    $price = isset($_POST['price']) ? trim(preg_replace('~[^\d.]~', '', strtr(wp_unslash($_POST['price']), array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => '')))) : null;

    if (!$pid) {
        // New course: product with WooCommerce, «دوره» without.
        $status = nias_inst_approval_on() ? 'pending' : 'publish';
        $type   = nias_inst_new_course_type();
        if ($type === 'product' && class_exists('WC_Product_Simple')) {
            $p = new WC_Product_Simple();
            $p->set_name($d['title']);
            $p->set_status($status);
            $p->set_short_description($d['excerpt']);
            $p->set_description($d['content']);
            $p->set_virtual(true);
            $p->set_catalog_visibility('visible');
            if ($price !== null && $price !== '' && nias_inst_approval_on()) {
                $p->set_regular_price(wc_format_decimal($price)); // reviewed by the admin before publishing
            }
            if ($thumb) {
                $p->set_image_id($thumb);
            }
            $pid = $p->save();
            wp_update_post(array('ID' => $pid, 'post_author' => $uid));
        } else {
            $pid = wp_insert_post(wp_slash(array('post_type' => $type, 'post_status' => $status, 'post_title' => $d['title'], 'post_excerpt' => $d['excerpt'], 'post_content' => $d['content'], 'post_author' => $uid)));
            if ($thumb && $pid) {
                set_post_thumbnail($pid, $thumb);
            }
        }
        if (!$pid || is_wp_error($pid)) {
            wp_safe_redirect(add_query_arg('nip_notice', 'error', $back));
            exit;
        }
        nias_set_product_instructors($pid, array($uid));
        if ($status === 'pending') {
            nias_inst_notify_admin($pid, $uid, 'new');
        }
        wp_safe_redirect(add_query_arg(array('nias_panel' => 'curriculum', 'course' => $pid, 'nip_notice' => $status === 'pending' ? 'created_pending' : 'created'), remove_query_arg(array('nias_panel', 'course', 'nip_notice'), $back)));
        exit;
    }

    if ($price !== null) {
        $d['price'] = $price;
    }
    if (nias_inst_needs_approval($uid, $pid)) {
        nias_course_pending_set($pid, 'details', $d, $uid);
        wp_safe_redirect(add_query_arg('nip_notice', 'pending', $back));
        exit;
    }
    // Not live yet (e.g. a pending new course) or approval off: apply now.
    // Price changes of a live product always need the admin.
    nias_inst_apply_details($pid, $d, !nias_course_is_live($pid) && nias_inst_approval_on());
    wp_safe_redirect(add_query_arg('nip_notice', 'saved', $back));
    exit;
}

/** «مشخصات دوره» form (new course when $pid = 0). */
function nias_inst_panel_details_view($user_id)
{
    $pid = isset($_GET['course']) ? (int) $_GET['course'] : 0;
    if ($pid ? !nias_can_edit_course($user_id, $pid) : !nias_inst_can_create($user_id)) {
        return '<div class="nip-wrap"><div class="nip-empty">' . esc_html__('دسترسی لازم را ندارید.', 'nias-course-widget') . '</div></div>';
    }
    $d = $pid ? nias_inst_course_details($pid, $user_id) : array('title' => '', 'excerpt' => '', 'content' => '', 'thumbnail' => 0, 'price' => '');
    $is_product = $pid ? get_post_type($pid) === 'product' : nias_inst_new_course_type() === 'product';
    $show_price = $is_product && nias_inst_approval_on();
    $pending = $pid ? nias_course_pending_get($pid) : array();
    $here = $pid ? nias_inst_panel_url('course', array('course' => $pid)) : nias_inst_panel_url('course');

    $h = '<div class="nip-wrap"><div class="nip-h"><h2>' . esc_html($pid ? __('مشخصات دوره', 'nias-course-widget') : __('دورهٔ جدید', 'nias-course-widget')) . '</h2>'
        . ($pid ? '<a class="nip-btn pri" href="' . esc_url(nias_inst_panel_url('curriculum', array('course' => $pid))) . '">' . esc_html__('جلسات و فصل‌ها', 'nias-course-widget') . '</a>' : '') . '</div>';
    $h .= nias_inst_panel_notice();
    if (!$pid) {
        $h .= '<div class="nip-info">' . esc_html(nias_inst_approval_on()
            ? sprintf(__('دوره به‌صورت %s ساخته می‌شود و تا بررسی و انتشار مدیر سایت، در وضعیت «در انتظار بررسی» می‌ماند.', 'nias-course-widget'), $is_product ? __('محصول ووکامرس', 'nias-course-widget') : __('«دوره»', 'nias-course-widget'))
            : sprintf(__('دوره به‌صورت %s ساخته و منتشر می‌شود.', 'nias-course-widget'), $is_product ? __('محصول ووکامرس', 'nias-course-widget') : __('«دوره»', 'nias-course-widget'))) . '</div>';
    } elseif (isset($pending['details'])) {
        $h .= '<div class="nip-info wait">' . esc_html__('تغییرات مشخصات این دوره در انتظار تأیید مدیر است؛ تا آن زمان سایت نسخهٔ قبلی را نشان می‌دهد.', 'nias-course-widget') . '</div>';
    }
    wp_enqueue_media();
    $img = $d['thumbnail'] ? wp_get_attachment_image_url($d['thumbnail'], 'medium') : '';
    $h .= '<form class="nip-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('nias_inst_course_' . $pid, '_wpnonce', false, false)
        . '<input type="hidden" name="action" value="nias_inst_course_save"><input type="hidden" name="course" value="' . (int) $pid . '"><input type="hidden" name="back" value="' . esc_attr($here) . '">'
        . '<label>' . esc_html__('عنوان دوره', 'nias-course-widget') . ' <b>*</b><input type="text" name="title" required maxlength="200" value="' . esc_attr($d['title']) . '"></label>'
        . '<label>' . esc_html__('توضیح کوتاه', 'nias-course-widget') . '<textarea name="excerpt" rows="3">' . esc_textarea($d['excerpt']) . '</textarea></label>'
        . '<label>' . esc_html__('توضیحات کامل', 'nias-course-widget') . '<textarea name="content" rows="8">' . esc_textarea($d['content']) . '</textarea></label>'
        . '<div class="nip-img"><span>' . esc_html__('تصویر دوره', 'nias-course-widget') . '</span><div class="nip-img-box"' . ($img ? ' style="background-image:url(' . esc_url($img) . ')"' : '') . '></div>'
        . '<input type="hidden" name="thumbnail" value="' . (int) $d['thumbnail'] . '"><button type="button" class="nip-btn" data-nip-img>' . esc_html__('انتخاب تصویر', 'nias-course-widget') . '</button> <button type="button" class="nip-btn" data-nip-img-clear>' . esc_html__('حذف', 'nias-course-widget') . '</button></div>'
        . ($show_price ? '<label>' . esc_html__('قیمت (تومان)', 'nias-course-widget') . '<input type="text" name="price" inputmode="numeric" value="' . esc_attr($d['price']) . '"><small>' . esc_html__('قیمت پس از تأیید مدیر اعمال می‌شود.', 'nias-course-widget') . '</small></label>' : '')
        . '<div><button class="nip-btn pri" type="submit">' . esc_html($pid ? (nias_inst_needs_approval($user_id, $pid) ? __('ارسال برای تأیید', 'nias-course-widget') : __('ذخیره', 'nias-course-widget')) : __('ساخت دوره و رفتن به جلسات', 'nias-course-widget')) . '</button></div></form>';
    $h .= "<script>document.addEventListener('DOMContentLoaded',function(){var f=document.querySelector('.nip-form');if(!f)return;var inp=f.querySelector('[name=thumbnail]'),box=f.querySelector('.nip-img-box'),fr;f.querySelector('[data-nip-img]').addEventListener('click',function(){if(!window.wp||!wp.media){return;}fr=fr||wp.media({title:'تصویر دوره',library:{type:'image'},multiple:false});fr.off('select').on('select',function(){var a=fr.state().get('selection').first().toJSON();inp.value=a.id;box.style.backgroundImage='url('+((a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url))+')';});fr.open();});f.querySelector('[data-nip-img-clear]').addEventListener('click',function(){inp.value='0';box.style.backgroundImage='';});});</script>";
    return $h . '</div>';
}

/** One-line notices after redirects. */
function nias_inst_panel_notice()
{
    $n = isset($_GET['nip_notice']) ? sanitize_key(wp_unslash($_GET['nip_notice'])) : '';
    $map = array(
        'saved'           => array('ok', __('ذخیره شد.', 'nias-course-widget')),
        'pending'         => array('wait', __('تغییرات برای تأیید مدیر ارسال شد؛ پس از تأیید روی دوره اعمال می‌شود.', 'nias-course-widget')),
        'created'         => array('ok', __('دوره ساخته شد. حالا فصل‌ها و جلسات را اضافه کنید.', 'nias-course-widget')),
        'created_pending' => array('wait', __('دوره ساخته شد و در انتظار بررسی مدیر است. حالا فصل‌ها و جلسات را اضافه کنید.', 'nias-course-widget')),
        'title'           => array('err', __('عنوان دوره را وارد کنید.', 'nias-course-widget')),
        'error'           => array('err', __('انجام نشد.', 'nias-course-widget')),
    );
    return isset($map[$n]) ? '<div class="nip-info ' . esc_attr($map[$n][0]) . '">' . esc_html($map[$n][1]) . '</div>' : '';
}

/* -------------------------------------------------------------------------
 * Notifications
 * ---------------------------------------------------------------------- */

function nias_inst_notify_admin($post_id, $user_id, $kind)
{
    if (!apply_filters('nias_inst_approval_email', true, $post_id, $kind)) {
        return;
    }
    $u = get_userdata($user_id);
    $title = get_the_title($post_id);
    $subject = $kind === 'new' ? sprintf(__('دورهٔ جدید «%s» در انتظار بررسی', 'nias-course-widget'), $title) : sprintf(__('تغییرات دورهٔ «%s» در انتظار تأیید', 'nias-course-widget'), $title);
    wp_mail(get_option('admin_email'), $subject, sprintf(__("%1\$s %2\$s.\n\nبررسی: %3\$s", 'nias-course-widget'),
        $u ? $u->display_name : __('یک مدرس', 'nias-course-widget'),
        $kind === 'new' ? sprintf(__('دورهٔ «%s» را ساخت', 'nias-course-widget'), $title) : sprintf(__('دورهٔ «%s» را ویرایش کرد', 'nias-course-widget'), $title),
        admin_url('admin.php?page=nias-course-approvals')));
}

function nias_inst_notify_instructor($user_id, $post_id, $approved, $note)
{
    $u = get_userdata($user_id);
    if (!$u || !$u->user_email || !apply_filters('nias_inst_approval_email', true, $post_id, $approved ? 'approved' : 'rejected')) {
        return;
    }
    $title = get_the_title($post_id);
    wp_mail($u->user_email,
        $approved ? sprintf(__('تغییرات دورهٔ «%s» تأیید شد', 'nias-course-widget'), $title) : sprintf(__('تغییرات دورهٔ «%s» تأیید نشد', 'nias-course-widget'), $title),
        sprintf(__("%1\$s عزیز،\n\n%2\$s%3\$s\n\n%4\$s", 'nias-course-widget'), $u->display_name,
            $approved ? sprintf(__('مدیر سایت تغییرات شما در دورهٔ «%s» را تأیید کرد و روی سایت اعمال شد.', 'nias-course-widget'), $title) : sprintf(__('مدیر سایت تغییرات شما در دورهٔ «%s» را تأیید نکرد.', 'nias-course-widget'), $title),
            $note !== '' ? "\n\n" . __('یادداشت مدیر:', 'nias-course-widget') . "\n" . $note : '', get_bloginfo('name')));
}

/* -------------------------------------------------------------------------
 * Admin review: «درخواست‌های مدرسین»
 * ---------------------------------------------------------------------- */

/** New instructor courses waiting for review. */
function nias_inst_pending_new_ids()
{
    return array_map('intval', get_posts(array(
        'post_type' => nias_course_post_types(), 'post_status' => 'pending', 'posts_per_page' => 100, 'fields' => 'ids',
        'meta_query' => array(array('key' => NIAS_INSTRUCTOR_META, 'compare' => 'EXISTS')), 'no_found_rows' => true,
    )));
}

/** Live courses with pending instructor changes. */
function nias_inst_pending_change_ids()
{
    return array_map('intval', get_posts(array(
        'post_type' => nias_course_post_types(), 'post_status' => array('publish', 'private', 'future', 'draft', 'pending'), 'posts_per_page' => 100, 'fields' => 'ids',
        'meta_query' => array(array('key' => NIAS_PENDING_META, 'compare' => 'EXISTS')), 'no_found_rows' => true,
    )));
}

function nias_inst_requests_count()
{
    return count(nias_inst_pending_new_ids()) + count(nias_inst_pending_change_ids());
}

/**
 * Human summary of a pending curriculum against the live one.
 *
 * @return string[]
 */
function nias_inst_sections_diff($live, $new)
{
    $out = array();
    $live = array_values((array) $live);
    $new  = array_values((array) $new);
    $max = max(count($live), count($new));
    for ($ci = 0; $ci < $max; $ci++) {
        $a = isset($live[$ci]) ? $live[$ci] : null;
        $b = isset($new[$ci]) ? $new[$ci] : null;
        $ct = $b ? $b['section_title'] : $a['section_title'];
        if (!$a) {
            $out[] = sprintf(__('فصل جدید: «%1$s» (%2$s جلسه)', 'nias-course-widget'), $ct, nias_fa_digits(count((array) $b['lessons'])));
            continue;
        }
        if (!$b) {
            $out[] = sprintf(__('حذف فصل: «%s»', 'nias-course-widget'), $a['section_title']);
            continue;
        }
        if ($a['section_title'] !== $b['section_title']) {
            $out[] = sprintf(__('عنوان فصل: «%1$s» ← «%2$s»', 'nias-course-widget'), $a['section_title'], $b['section_title']);
        }
        $la = array_values((array) $a['lessons']);
        $lb = array_values((array) $b['lessons']);
        $lm = max(count($la), count($lb));
        for ($li = 0; $li < $lm; $li++) {
            $x = isset($la[$li]) ? $la[$li] : null;
            $y = isset($lb[$li]) ? $lb[$li] : null;
            if (!$x) {
                $out[] = sprintf(__('جلسهٔ جدید در «%1$s»: «%2$s»', 'nias-course-widget'), $ct, $y['lesson_title']);
                continue;
            }
            if (!$y) {
                $out[] = sprintf(__('حذف جلسه از «%1$s»: «%2$s»', 'nias-course-widget'), $ct, $x['lesson_title']);
                continue;
            }
            $changes = array();
            $fields = array(
                'lesson_title' => __('عنوان', 'nias-course-widget'), 'lesson_content' => __('محتوا', 'nias-course-widget'),
                'lesson_preview_video' => __('ویدیو', 'nias-course-widget'), 'lesson_download' => __('فایل', 'nias-course-widget'),
                'lesson_private' => __('خصوصی بودن', 'nias-course-widget'), 'lesson_duration' => __('مدت', 'nias-course-widget'),
                'lesson_quiz' => __('آزمون', 'nias-course-widget'), 'lesson_gate' => __('پیش‌نیاز', 'nias-course-widget'), 'lesson_label' => __('برچسب', 'nias-course-widget'),
            );
            foreach ($fields as $k => $label) {
                $vx = isset($x[$k]) ? $x[$k] : '';
                $vy = isset($y[$k]) ? $y[$k] : '';
                if (wp_json_encode($vx) !== wp_json_encode($vy) && !(empty($vx) && empty($vy))) {
                    $changes[] = $label;
                }
            }
            if ($changes) {
                $out[] = sprintf(__('جلسهٔ «%1$s»: تغییر %2$s', 'nias-course-widget'), $y['lesson_title'], implode('، ', $changes));
            }
        }
    }
    return $out;
}

add_action('admin_menu', function () {
    if (!current_user_can('manage_options') || !nias_inst_panel_on()) {
        return;
    }
    $n = nias_inst_requests_count();
    add_submenu_page('nias-course-settings', __('درخواست‌های مدرسین', 'nias-course-widget'), __('درخواست‌های مدرسین', 'nias-course-widget') . ($n ? ' <span class="awaiting-mod">' . nias_fa_digits($n) . '</span>' : ''), 'manage_options', 'nias-course-approvals', 'nias_inst_requests_page');
}, 23);

add_action('admin_post_nias_inst_review', function () {
    $pid = isset($_POST['course']) ? (int) $_POST['course'] : 0;
    $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    if (!current_user_can('manage_options') || !wp_verify_nonce(isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '', 'nias_inst_review_' . $pid)) {
        wp_die(esc_html__('درخواست منقضی شده است.', 'nias-course-widget'), '', array('response' => 403, 'back_link' => true));
    }
    $note = sanitize_textarea_field(wp_unslash(isset($_POST['note']) ? $_POST['note'] : ''));
    $p    = nias_course_pending_get($pid);
    $by   = !empty($p['by']) ? (int) $p['by'] : (int) get_post_field('post_author', $pid);
    if ($op === 'approve') {
        if (isset($p['details'])) {
            nias_inst_apply_details($pid, $p['details'], true);
        }
        if (isset($p['sections'])) {
            carbon_set_post_meta($pid, 'course_sections', $p['sections']);
            if (!defined('NIAS_CS_COMPACT') || !NIAS_CS_COMPACT) {
                do_action('nias_course_sections_saved', $pid);
            }
        }
        nias_course_pending_clear($pid);
        nias_inst_notify_instructor($by, $pid, true, $note);
    } elseif ($op === 'reject') {
        nias_course_pending_clear($pid);
        nias_inst_notify_instructor($by, $pid, false, $note);
    } elseif ($op === 'publish') {
        wp_update_post(array('ID' => $pid, 'post_status' => 'publish'));
        nias_inst_notify_instructor($by, $pid, true, $note);
    } elseif ($op === 'reject_new') {
        wp_update_post(array('ID' => $pid, 'post_status' => 'draft'));
        nias_inst_notify_instructor($by, $pid, false, $note);
    }
    wp_safe_redirect(add_query_arg('done', $op, admin_url('admin.php?page=nias-course-approvals')));
    exit;
});

function nias_inst_requests_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $form = function ($pid, $buttons) {
        $h = '<form class="nir-act" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('nias_inst_review_' . $pid, '_wpnonce', false, false)
            . '<input type="hidden" name="action" value="nias_inst_review"><input type="hidden" name="course" value="' . (int) $pid . '">'
            . '<textarea name="note" rows="1" placeholder="' . esc_attr__('یادداشت برای مدرس (اختیاری)…', 'nias-course-widget') . '"></textarea>';
        foreach ($buttons as $op => $b) {
            $h .= '<button class="nir-btn ' . esc_attr($b[1]) . '" name="op" value="' . esc_attr($op) . '">' . esc_html($b[0]) . '</button>';
        }
        return $h . '</form>';
    };
    $done = isset($_GET['done']) ? sanitize_key(wp_unslash($_GET['done'])) : '';
    echo '<div class="nias-settings-app" dir="rtl">';
    if (function_exists('nias_settings_topbar')) {
        nias_settings_topbar('approvals');
    }
    echo '<div class="nias-set-shell"><div class="nias-set-main">';
    echo '<style>.nir h2{font-size:20px;margin:6px 0 14px}.nir h3{font-size:16px;margin:18px 0 10px}.nir-card{background:#fff;border:1px solid #e6eaef;border-radius:14px;padding:14px 16px;margin-bottom:12px}.nir-t{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.nir-t b{font-size:15px}.nir-t span{font-size:12.5px;color:#64748b}.nir-chip{background:#fff4e2;color:#b45309;border-radius:99px;padding:2px 10px;font-size:12px;font-weight:700}.nir-diff{margin:10px 0;padding:0 18px 0 0;font-size:13.5px;line-height:2;color:#334155}.nir-act{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.nir-act textarea{flex:1;min-width:220px;border:1px solid #dbe3ef;border-radius:9px;padding:6px 10px;font:inherit}.nir-btn{height:34px;padding:0 14px;border-radius:9px;border:1px solid #dbe3ef;background:#fff;font:inherit;font-weight:700;cursor:pointer}.nir-btn.pri{background:#16a34a;border-color:#16a34a;color:#fff}.nir-btn.no{color:#d64545;border-color:#f3c9c9}.nir-empty{padding:26px;text-align:center;color:#64748b;background:#fff;border:1px dashed #d8dee6;border-radius:14px}.nir-ok{background:#ecf8ea;border:1px solid #cfeccb;color:#2f7d27;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-weight:600}.nir a{color:#3858e9}</style>';
    echo '<div class="nir"><h2>' . esc_html__('درخواست‌های مدرسین', 'nias-course-widget') . '</h2>';
    if ($done) {
        echo '<div class="nir-ok">' . esc_html__('انجام شد و به مدرس اطلاع داده شد.', 'nias-course-widget') . '</div>';
    }
    if (!nias_inst_approval_on()) {
        echo '<div class="nir-card">' . esc_html__('«نیاز به تأیید» در تنظیمات پنل مدرس خاموش است؛ تغییرات مدرسین مستقیم اعمال می‌شود.', 'nias-course-widget') . '</div>';
    }
    $new = nias_inst_pending_new_ids();
    echo '<h3>' . esc_html(sprintf(__('دوره‌های جدید در انتظار بررسی (%s)', 'nias-course-widget'), nias_fa_digits(count($new)))) . '</h3>';
    if (!$new) {
        echo '<div class="nir-empty">' . esc_html__('دورهٔ جدیدی در انتظار نیست.', 'nias-course-widget') . '</div>';
    }
    foreach ($new as $pid) {
        $a = get_userdata((int) get_post_field('post_author', $pid));
        $n = 0;
        foreach ((array) carbon_get_post_meta($pid, 'course_sections') as $s) {
            $n += count((array) (isset($s['lessons']) ? $s['lessons'] : array()));
        }
        echo '<div class="nir-card"><div class="nir-t"><b>' . esc_html(get_the_title($pid)) . '</b><span class="nir-chip">' . esc_html__('در انتظار بررسی', 'nias-course-widget') . '</span><span>'
            . esc_html(sprintf(__('%1$s · %2$s · %3$s جلسه', 'nias-course-widget'), $a ? $a->display_name : '—', nias_course_type_label($pid), nias_fa_digits($n))) . '</span>'
            . '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html__('ویرایش', 'nias-course-widget') . '</a><a href="' . esc_url(admin_url('admin.php?page=nias-course-curriculum&product=' . $pid)) . '">' . esc_html__('جلسات', 'nias-course-widget') . '</a><a href="' . esc_url(get_preview_post_link($pid)) . '" target="_blank">' . esc_html__('پیش‌نمایش', 'nias-course-widget') . '</a></div>'
            . (get_post_type($pid) === 'product' && function_exists('wc_get_product') && ($wp = wc_get_product($pid)) ? '<div class="nir-diff">' . esc_html(sprintf(__('قیمت: %s', 'nias-course-widget'), $wp->get_regular_price() !== '' ? nias_fa_digits(number_format_i18n((float) $wp->get_regular_price())) : __('تعیین نشده', 'nias-course-widget'))) . '</div>' : '')
            . $form($pid, array('publish' => array(__('انتشار دوره', 'nias-course-widget'), 'pri'), 'reject_new' => array(__('رد (پیش‌نویس)', 'nias-course-widget'), 'no'))) . '</div>';
    }
    $chg = nias_inst_pending_change_ids();
    echo '<h3>' . esc_html(sprintf(__('تغییرات در انتظار تأیید (%s)', 'nias-course-widget'), nias_fa_digits(count($chg)))) . '</h3>';
    if (!$chg) {
        echo '<div class="nir-empty">' . esc_html__('تغییری در انتظار تأیید نیست.', 'nias-course-widget') . '</div>';
    }
    foreach ($chg as $pid) {
        $p = nias_course_pending_get($pid);
        $a = get_userdata(isset($p['by']) ? (int) $p['by'] : 0);
        $items = array();
        if (isset($p['details'])) {
            $live = nias_inst_course_details($pid, get_current_user_id());
            foreach (array('title' => __('عنوان', 'nias-course-widget'), 'excerpt' => __('توضیح کوتاه', 'nias-course-widget'), 'content' => __('توضیحات', 'nias-course-widget'), 'thumbnail' => __('تصویر', 'nias-course-widget'), 'price' => __('قیمت', 'nias-course-widget')) as $k => $lbl) {
                if (isset($p['details'][$k]) && (string) $p['details'][$k] !== (string) $live[$k]) {
                    $items[] = $k === 'title' ? sprintf(__('عنوان: «%1$s» ← «%2$s»', 'nias-course-widget'), $live['title'], $p['details']['title'])
                        : ($k === 'price' ? sprintf(__('قیمت: %1$s ← %2$s', 'nias-course-widget'), $live['price'] !== '' ? nias_fa_digits($live['price']) : '—', $p['details']['price'] !== '' ? nias_fa_digits($p['details']['price']) : '—') : sprintf(__('تغییر %s', 'nias-course-widget'), $lbl));
                }
            }
        }
        if (isset($p['sections'])) {
            $items = array_merge($items, nias_inst_sections_diff(carbon_get_post_meta($pid, 'course_sections'), $p['sections']));
        }
        if (!$items) {
            $items[] = __('بدون تفاوت با نسخهٔ فعلی', 'nias-course-widget');
        }
        echo '<div class="nir-card"><div class="nir-t"><b>' . esc_html(get_the_title($pid)) . '</b><span>' . esc_html(sprintf(__('%1$s · %2$s', 'nias-course-widget'), $a ? $a->display_name : '—', function_exists('nias_qa_when') ? nias_qa_when($p['at']) : $p['at'])) . '</span>'
            . '<a href="' . esc_url(get_permalink($pid)) . '" target="_blank">' . esc_html__('نسخهٔ فعلی', 'nias-course-widget') . '</a></div>'
            . '<ul class="nir-diff">' . implode('', array_map(function ($i) {
                return '<li>' . esc_html($i) . '</li>';
            }, array_slice($items, 0, 60))) . (count($items) > 60 ? '<li>…</li>' : '') . '</ul>'
            . $form($pid, array('approve' => array(__('تأیید و اعمال', 'nias-course-widget'), 'pri'), 'reject' => array(__('رد تغییرات', 'nias-course-widget'), 'no'))) . '</div>';
    }
    echo '</div></div></div></div>';
}
