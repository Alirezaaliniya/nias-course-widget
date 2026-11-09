<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «پیش‌نیاز و مسیر یادگیری» (prerequisites + learning paths).
 *
 * Prerequisites (per course, «پیش‌نیاز دوره» box on the edit screen):
 *   - one or more courses that must be finished first: «تکمیل» = 100%
 *     progress, «مدرک» = certificate issued;
 *   - «قفل محتوا»: the course can be bought, its private lessons open once the
 *     prerequisites are done; «جلوگیری از خرید»: WooCommerce refuses to add it
 *     to the cart before that.
 *   - Admins and the course's instructors are never blocked.
 *
 * Learning paths (post type «مسیر یادگیری»): an ordered list of courses,
 *   shown with [nias_path id="…"] (and on the path's own page): each step's
 *   state, overall progress and a «ترتیبی» option that makes every course
 *   require the one before it.
 *
 * @package nias-course-widget
 */

define('NIAS_PATH_CPT', 'nias_path');

function nias_paths_enabled()
{
    return (bool) apply_filters('nias_paths_enabled', function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_paths_enabled') === 'on');
}

/* -------------------------------------------------------------------------
 * Prerequisites
 * ---------------------------------------------------------------------- */

/** Rules of a course: {ids[], need: complete|certificate, mode: content|buy}. */
function nias_prereq_rules($post_id)
{
    $ids  = array_values(array_filter(array_map('intval', (array) get_post_meta($post_id, '_nias_prereq', true))));
    $need = get_post_meta($post_id, '_nias_prereq_need', true) === 'certificate' ? 'certificate' : 'complete';
    $mode = get_post_meta($post_id, '_nias_prereq_mode', true) === 'buy' ? 'buy' : 'content';
    // Sequential paths add "the previous course of the path".
    foreach (nias_paths_of_course($post_id) as $path_id) {
        if (get_post_meta($path_id, '_nias_path_seq', true) === 'on') {
            $list = nias_path_courses($path_id);
            $i    = array_search((int) $post_id, $list, true);
            if ($i !== false && $i > 0 && !in_array($list[$i - 1], $ids, true)) {
                $ids[] = $list[$i - 1];
            }
        }
    }
    return array('ids' => $ids, 'need' => $need, 'mode' => $mode);
}

/** Has the user finished this course (for a prerequisite)? */
function nias_prereq_done($user_id, $course_id, $need = 'complete')
{
    if ($need === 'certificate' && function_exists('nias_cert_get_for')) {
        return (bool) nias_cert_get_for($user_id, $course_id);
    }
    if (function_exists('nias_cert_user_progress') && nias_cert_user_progress($user_id, $course_id) >= 100) {
        return true;
    }
    return function_exists('nias_cert_get_for') && nias_cert_get_for($user_id, $course_id); // a certificate also counts
}

/** Prerequisite courses the user has not finished yet (ids). */
function nias_prereq_missing($user_id, $post_id)
{
    if (!nias_paths_enabled()) {
        return array();
    }
    if ($user_id && (user_can($user_id, 'manage_options') || (function_exists('nias_can_edit_course') && nias_can_edit_course($user_id, $post_id)))) {
        return array();
    }
    $r   = nias_prereq_rules($post_id);
    $out = array();
    foreach ($r['ids'] as $cid) {
        if ($cid !== (int) $post_id && get_post_status($cid) && (!$user_id || !nias_prereq_done($user_id, $cid, $r['need']))) {
            $out[] = $cid;
        }
    }
    return $out;
}

// Content mode: the course's private parts stay closed until the prerequisites are done.
add_filter('nias_course_access_active', function ($ok, $user_id, $post_id) {
    if (!$ok || !nias_paths_enabled()) {
        return $ok;
    }
    $r = nias_prereq_rules($post_id);
    return $r['ids'] ? !nias_prereq_missing($user_id, $post_id) : $ok;
}, 20, 3);

// Free «دوره» too (its access check is the login filter).
add_filter('nias_free_course_access', function ($ok, $user_id, $post_id) {
    return $ok && !(nias_paths_enabled() && nias_prereq_missing($user_id, $post_id));
}, 20, 3);

// Buy mode: WooCommerce refuses the course before the prerequisites.
add_filter('woocommerce_add_to_cart_validation', function ($ok, $product_id) {
    if (!$ok || !nias_paths_enabled()) {
        return $ok;
    }
    $r = nias_prereq_rules($product_id);
    if ($r['mode'] !== 'buy' || !$r['ids']) {
        return $ok;
    }
    $missing = nias_prereq_missing(get_current_user_id(), $product_id);
    if ($missing) {
        wc_add_notice(sprintf(__('To buy this course you must first finish %s.', 'nias-course-widget'), '«' . implode('»، «', array_map('get_the_title', $missing)) . '»'), 'error');
        return false;
    }
    return $ok;
}, 20, 2);

/** For the course view: [{title, url}] still missing (null = none). */
add_filter('nias_modern_prereq', function ($v, $post_id, $user_id) {
    $m = nias_prereq_missing($user_id, $post_id);
    if (!$m) {
        return null;
    }
    $r = nias_prereq_rules($post_id);
    return array('need' => $r['need'], 'courses' => array_map(function ($id) {
        return array('title' => get_the_title($id), 'url' => function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($id) : get_permalink($id));
    }, $m));
}, 10, 3);

// Notice on the product page.
add_action('woocommerce_single_product_summary', function () {
    if (!nias_paths_enabled()) {
        return;
    }
    $pid = get_the_ID();
    $m   = nias_prereq_missing(get_current_user_id(), $pid);
    if (!$m) {
        return;
    }
    $r = nias_prereq_rules($pid);
    echo '<div class="woocommerce-info" style="margin:12px 0">' . esc_html(sprintf(
        $r['mode'] === 'buy' ? __('Prerequisite for buying this course: %s', 'nias-course-widget') : __('Prerequisite for this course: %s (this course\'s lessons open after that).', 'nias-course-widget'),
        '«' . implode('»، «', array_map('get_the_title', $m)) . '»'
    )) . '</div>';
}, 25);

/* Edit screen box ---------------------------------------------------------- */

add_action('add_meta_boxes', function ($post_type) {
    if (nias_paths_enabled() && function_exists('nias_is_course_type') && nias_is_course_type($post_type)) {
        add_meta_box('nias-prereq', __('Course prerequisite (Nias Course Builder)', 'nias-course-widget'), 'nias_prereq_box', $post_type, 'side');
    }
});

function nias_prereq_box($post)
{
    wp_nonce_field('nias_prereq', 'nias_prereq_nonce');
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    $ids   = array_values(array_filter(array_map('intval', (array) get_post_meta($post->ID, '_nias_prereq', true))));
    $items = array();
    foreach ($ids as $id) {
        $items[] = function_exists('nias_picker_product_item') ? nias_picker_product_item($id) : array('id' => $id, 'label' => get_the_title($id));
    }
    $need = get_post_meta($post->ID, '_nias_prereq_need', true) === 'certificate' ? 'certificate' : 'complete';
    $mode = get_post_meta($post->ID, '_nias_prereq_mode', true) === 'buy' ? 'buy' : 'content';
    ?>
    <p style="margin-top:0"><b><?php echo esc_html__('Courses that must be finished first', 'nias-course-widget'); ?></b></p>
    <div data-nias-prereq-pick></div>
    <input type="hidden" name="nias_prereq" value="<?php echo esc_attr(implode(',', $ids)); ?>">
    <p><label><b><?php echo esc_html__('Finished means', 'nias-course-widget'); ?></b><br>
        <select name="nias_prereq_need"><option value="complete" <?php selected($need, 'complete'); ?>><?php echo esc_html__('100% progress', 'nias-course-widget'); ?></option><option value="certificate" <?php selected($need, 'certificate'); ?>><?php echo esc_html__('Getting the certificate', 'nias-course-widget'); ?></option></select></label></p>
    <p><label><b><?php echo esc_html__('Before that', 'nias-course-widget'); ?></b><br>
        <select name="nias_prereq_mode"><option value="content" <?php selected($mode, 'content'); ?>><?php echo esc_html__('Buying allowed, lessons locked', 'nias-course-widget'); ?></option><option value="buy" <?php selected($mode, 'buy'); ?>><?php echo esc_html__('Buying not possible either', 'nias-course-widget'); ?></option></select></label></p>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var h = document.querySelector('[data-nias-prereq-pick]'), inp = document.querySelector('[name=nias_prereq]');
        if (!h || !window.NiasPicker) return;
        window.NiasPicker.attach(h, { source: 'product', multiple: true, placeholder: 'جستجوی دوره…', value: <?php echo wp_json_encode($items); ?>, params: { exclude: '<?php echo (int) $post->ID; ?>' }, onChange: function (v) { inp.value = v.map(function (x) { return x.id; }).join(','); } });
    });
    </script>
    <?php
}

add_action('save_post', function ($post_id) {
    if (!isset($_POST['nias_prereq_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_prereq_nonce'])), 'nias_prereq') || !current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_POST['nias_prereq'] ?? ''))))), function ($id) use ($post_id) { return $id !== (int) $post_id; }));
    update_post_meta($post_id, '_nias_prereq', array_slice($ids, 0, 10));
    update_post_meta($post_id, '_nias_prereq_need', ($_POST['nias_prereq_need'] ?? '') === 'certificate' ? 'certificate' : 'complete');
    update_post_meta($post_id, '_nias_prereq_mode', ($_POST['nias_prereq_mode'] ?? '') === 'buy' ? 'buy' : 'content');
});

/* -------------------------------------------------------------------------
 * Learning paths
 * ---------------------------------------------------------------------- */

add_action('init', function () {
    if (!nias_paths_enabled()) {
        return;
    }
    register_post_type(NIAS_PATH_CPT, array(
        'labels'       => array('name' => __('Learning paths', 'nias-course-widget'), 'singular_name' => __('Learning path', 'nias-course-widget'), 'add_new_item' => __('Add learning path', 'nias-course-widget'), 'edit_item' => __('Edit learning path', 'nias-course-widget'), 'menu_name' => __('Learning paths', 'nias-course-widget')),
        'public'       => true,
        'has_archive'  => false,
        'rewrite'      => array('slug' => 'learning-path', 'with_front' => false),
        'menu_icon'    => 'dashicons-randomize',
        'supports'     => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest' => false,
    ));
    if (get_option('nias_path_rewrite') !== 'on') {
        add_action('wp_loaded', function () { flush_rewrite_rules(false); update_option('nias_path_rewrite', 'on'); });
    }
}, 6);

function nias_path_courses($path_id)
{
    return array_values(array_filter(array_map('intval', (array) get_post_meta($path_id, '_nias_path_courses', true))));
}

/** Paths that contain a course (cached per request). */
function nias_paths_of_course($course_id)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        if (post_type_exists(NIAS_PATH_CPT)) {
            foreach (get_posts(array('post_type' => NIAS_PATH_CPT, 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids', 'no_found_rows' => true)) as $pid) {
                foreach (nias_path_courses($pid) as $cid) {
                    $map[$cid][] = $pid;
                }
            }
        }
    }
    return isset($map[(int) $course_id]) ? $map[(int) $course_id] : array();
}

add_action('add_meta_boxes_' . NIAS_PATH_CPT, function () {
    add_meta_box('nias-path-courses', __('Courses of this path (in order)', 'nias-course-widget'), function ($post) {
        wp_nonce_field('nias_path', 'nias_path_nonce');
        if (function_exists('nias_picker_enqueue')) {
            nias_picker_enqueue();
        }
        $ids   = nias_path_courses($post->ID);
        $items = array();
        foreach ($ids as $id) {
            $items[] = function_exists('nias_picker_product_item') ? nias_picker_product_item($id) : array('id' => $id, 'label' => get_the_title($id));
        }
        ?>
        <p><?php echo esc_html__('Select the courses in the order they should be taken. Show it anywhere on the site with the shortcode:', 'nias-course-widget'); ?> <code>[nias_path id="<?php echo (int) $post->ID; ?>"]</code></p>
        <div data-nias-path-pick></div>
        <input type="hidden" name="nias_path_courses" value="<?php echo esc_attr(implode(',', $ids)); ?>">
        <p><label><input type="checkbox" name="nias_path_seq" value="on" <?php checked(get_post_meta($post->ID, '_nias_path_seq', true), 'on'); ?>> <b><?php echo esc_html__('Sequential', 'nias-course-widget'); ?></b> — <?php echo esc_html__('Each course\'s lessons open after finishing the previous course of this path.', 'nias-course-widget'); ?></label></p>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var h = document.querySelector('[data-nias-path-pick]'), inp = document.querySelector('[name=nias_path_courses]');
            if (!h || !window.NiasPicker) return;
            window.NiasPicker.attach(h, { source: 'product', multiple: true, placeholder: 'جستجوی دوره…', value: <?php echo wp_json_encode($items); ?>, onChange: function (v) { inp.value = v.map(function (x) { return x.id; }).join(','); } });
        });
        </script>
        <?php
    }, NIAS_PATH_CPT, 'normal', 'high');
});

add_action('save_post_' . NIAS_PATH_CPT, function ($post_id) {
    if (!isset($_POST['nias_path_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nias_path_nonce'])), 'nias_path') || !current_user_can('edit_post', $post_id)) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', sanitize_text_field(wp_unslash($_POST['nias_path_courses'] ?? '')))))));
    update_post_meta($post_id, '_nias_path_courses', array_slice($ids, 0, 30));
    update_post_meta($post_id, '_nias_path_seq', ($_POST['nias_path_seq'] ?? '') === 'on' ? 'on' : 'off');
});

/** The path view. */
function nias_path_html($path_id)
{
    $ids = nias_path_courses($path_id);
    if (!$ids) {
        return '';
    }
    $uid   = get_current_user_id();
    $done  = 0;
    $steps = '';
    $n     = 0;
    foreach ($ids as $i => $cid) {
        if (!get_post_status($cid)) {
            continue;
        }
        $n++;
        $has  = $uid && function_exists('nias_user_has_course_access') && nias_user_has_course_access($uid, $cid);
        $pct  = $uid && function_exists('nias_cert_user_progress') ? nias_cert_user_progress($uid, $cid) : 0;
        $fin  = $uid && nias_prereq_done($uid, $cid);
        $miss = nias_prereq_missing($uid, $cid);
        $done += $fin ? 1 : 0;
        $state = $fin ? array('done', __('Finished', 'nias-course-widget')) : ($miss ? array('lock', __('After the previous course', 'nias-course-widget')) : ($has ? array('now', sprintf(__('In progress · %s%%', 'nias-course-widget'), nias_fa_digits($pct))) : array('open', __('Ready to start', 'nias-course-widget'))));
        $url   = $has && function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($cid) : get_permalink($cid);
        $img   = get_the_post_thumbnail_url($cid, 'medium');
        $steps .= '<li class="npt-s ' . esc_attr($state[0]) . '"><span class="npt-n">' . esc_html(nias_fa_digits($n)) . '</span>'
            . ($img ? '<img src="' . esc_url($img) . '" alt="" loading="lazy">' : '')
            . '<div class="npt-b"><a href="' . esc_url($url) . '">' . esc_html(get_the_title($cid)) . '</a><span class="npt-st">' . esc_html($state[1]) . '</span>'
            . ($has && !$fin ? '<span class="npt-bar"><i style="width:' . (int) $pct . '%"></i></span>' : '') . '</div></li>';
    }
    $total = max(1, $n);
    ob_start();
    ?>
    <div class="npt" dir="rtl">
        <style>
        .npt{border:1px solid #e6ecf5;border-radius:18px;padding:18px;background:#fff;font-family:inherit}
        .npt-h{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;margin-bottom:14px}
        .npt-h b{font-size:17px}
        .npt-p{flex:1 1 200px;max-width:320px}
        .npt-p span{font-size:12.5px;color:#64748b}
        .npt-p em,.npt-bar{display:block;height:8px;border-radius:99px;background:#eef2f8;overflow:hidden;margin-top:4px}
        .npt-p em i,.npt-bar i{display:block;height:100%;background:#16a34a;border-radius:99px}
        .npt ol{list-style:none;margin:0;padding:0;position:relative}
        .npt-s{display:flex;align-items:center;gap:12px;padding:10px 0;position:relative}
        .npt-s:not(:last-child)::after{content:"";position:absolute;right:15px;top:44px;bottom:-10px;width:2px;background:#e2e8f0}
        .npt-n{flex:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:#eef2f8;color:#475569;z-index:1}
        .npt-s.done .npt-n{background:#16a34a;color:#fff}.npt-s.now .npt-n{background:#3858e9;color:#fff}.npt-s.lock{opacity:.6}
        .npt-s img{width:64px;height:44px;object-fit:cover;border-radius:8px;flex:none}
        .npt-b{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
        .npt-b a{font-weight:800;text-decoration:none;color:#1f2733}
        .npt-st{font-size:12.5px;color:#64748b}
        .npt-s.done .npt-st{color:#137a3a}.npt-s.now .npt-st{color:#1e4fb8}
        .npt-bar{max-width:220px}
        .npt-bar i{background:#3858e9}
        </style>
        <div class="npt-h"><b><?php echo esc_html(get_the_title($path_id)); ?></b>
            <div class="npt-p"><span><?php echo esc_html(sprintf(__('%1$s of %2$s courses finished', 'nias-course-widget'), nias_fa_digits($done), nias_fa_digits($n))); ?></span><em><i style="width:<?php echo (int) round($done / $total * 100); ?>%"></i></em></div>
        </div>
        <ol><?php echo $steps; // phpcs:ignore -- escaped above ?></ol>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('nias_path', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'nias_path');
    return nias_paths_enabled() && get_post_type((int) $atts['id']) === NIAS_PATH_CPT ? nias_path_html((int) $atts['id']) : '';
});

// The path's own page shows the steps after its description.
add_filter('the_content', function ($c) {
    if (nias_paths_enabled() && is_singular(NIAS_PATH_CPT) && in_the_loop() && is_main_query()) {
        $c .= nias_path_html(get_the_ID());
    }
    return $c;
}, 20);
