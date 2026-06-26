<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instructors (مدرسین) feature.
 *
 * Activated from the main plugin settings ("فعالسازی مدرسین"). When enabled it:
 *   - registers a dedicated "مدرس" (nias_instructor) WordPress role,
 *   - adds a "مدرسین" management page (under the plugin settings menu) where a
 *     site admin can promote/demote WordPress users to the instructor role and
 *     see each instructor's assigned courses,
 *   - lets each product pick its instructors from the curriculum editor.
 *
 * A product's instructors are stored as repeated post-meta rows under
 * NIAS_INSTRUCTOR_META (one user id per row) so an instructor's courses can be
 * queried with a clean meta_query.
 *
 * @package nias-course-widget
 */

if (!defined('NIAS_INSTRUCTOR_ROLE')) {
    define('NIAS_INSTRUCTOR_ROLE', 'nias_instructor');
}
if (!defined('NIAS_INSTRUCTOR_META')) {
    define('NIAS_INSTRUCTOR_META', '_nias_course_instructor');
}

/* -------------------------------------------------------------------------
 * Feature flag + role
 * ---------------------------------------------------------------------- */

/** Is the instructors feature enabled in the plugin settings? */
function nias_instructors_enabled()
{
    return carbon_get_theme_option('nias_instructors_enabled') === 'on';
}

/** Make sure the instructor role exists while the feature is on. */
add_action('init', 'nias_instructors_ensure_role');
function nias_instructors_ensure_role()
{
    if (!nias_instructors_enabled()) {
        return;
    }
    if (!get_role(NIAS_INSTRUCTOR_ROLE)) {
        add_role(NIAS_INSTRUCTOR_ROLE, __('مدرس', 'nias-course-widget'), array('read' => true));
    }
}

/* -------------------------------------------------------------------------
 * Data helpers
 * ---------------------------------------------------------------------- */

/**
 * All users that currently hold the instructor role.
 *
 * @return WP_User[]
 */
function nias_get_instructors()
{
    return get_users(array(
        'role'    => NIAS_INSTRUCTOR_ROLE,
        'orderby' => 'display_name',
        'order'   => 'ASC',
    ));
}

/** Does this user hold the instructor role? */
function nias_user_is_instructor($user_id)
{
    $user = get_userdata($user_id);
    return $user && in_array(NIAS_INSTRUCTOR_ROLE, (array) $user->roles, true);
}

/**
 * Products assigned to a given instructor.
 *
 * @param int $user_id
 * @return WP_Post[]
 */
function nias_get_instructor_courses($user_id)
{
    $user_id = intval($user_id);
    if (!$user_id) {
        return array();
    }
    return get_posts(array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(
            array('key' => NIAS_INSTRUCTOR_META, 'value' => $user_id, 'compare' => '='),
        ),
    ));
}

/**
 * Instructor user ids assigned to a product.
 *
 * @param int $product_id
 * @return int[]
 */
function nias_product_instructors($product_id)
{
    $ids = get_post_meta($product_id, NIAS_INSTRUCTOR_META, false);
    $ids = array_map('intval', (array) $ids);
    return array_values(array_unique(array_filter($ids)));
}

/**
 * Replace a product's instructors with the given list (validated to actual
 * instructor users), stored as one meta row per user id.
 *
 * @param int   $product_id
 * @param int[] $ids
 */
function nias_set_product_instructors($product_id, $ids)
{
    delete_post_meta($product_id, NIAS_INSTRUCTOR_META);
    $ids = array_values(array_unique(array_map('intval', (array) $ids)));
    foreach ($ids as $id) {
        if ($id > 0 && nias_user_is_instructor($id)) {
            add_post_meta($product_id, NIAS_INSTRUCTOR_META, $id);
        }
    }
}

/* -------------------------------------------------------------------------
 * Management page
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_instructors_register_page', 20);
function nias_instructors_register_page()
{
    if (!current_user_can('manage_options') || !nias_instructors_enabled()) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('مدرسین', 'nias-course-widget'),
        __('مدرسین', 'nias-course-widget'),
        'manage_options',
        'nias-course-instructors',
        'nias_instructors_render_page'
    );
}

/**
 * Handle add/remove submissions on the management page.
 *
 * @param array|null $notice filled with array('ok'|'err', message)
 */
function nias_instructors_handle_actions(&$notice)
{
    if (!isset($_POST['nias_inst_action'])) {
        return;
    }
    if (!check_admin_referer('nias_instructors_manage', 'nias_inst_nonce')) {
        return;
    }

    $action  = sanitize_text_field(wp_unslash($_POST['nias_inst_action']));
    $user_id = isset($_POST['nias_inst_user']) ? intval($_POST['nias_inst_user']) : 0;
    $user    = $user_id ? get_userdata($user_id) : null;

    if (!$user) {
        $notice = array('err', __('کاربر معتبری انتخاب نشد.', 'nias-course-widget'));
        return;
    }

    if ($action === 'add') {
        $user->add_role(NIAS_INSTRUCTOR_ROLE);
        $notice = array('ok', sprintf(__('«%s» به فهرست مدرسین اضافه شد.', 'nias-course-widget'), $user->display_name));
    } elseif ($action === 'remove') {
        $user->remove_role(NIAS_INSTRUCTOR_ROLE);
        $notice = array('ok', sprintf(__('«%s» از فهرست مدرسین حذف شد.', 'nias-course-widget'), $user->display_name));
    }
}

function nias_instructors_render_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = null;
    nias_instructors_handle_actions($notice);

    // Dashboard-page selection ("انتخاب برگه").
    $dash_saved = false;
    if (isset($_POST['nias_save_instructor_dashboard']) && check_admin_referer('nias_instructor_dashboard', 'nias_inst_dash_nonce')) {
        $page_id = isset($_POST['instructors_dashboard_page']) ? intval($_POST['instructors_dashboard_page']) : 0;
        update_option('_instructors_dashboard_page', $page_id);
        $dash_saved = true;
    }
    $dash_page = (int) carbon_get_theme_option('instructors_dashboard_page');
    $page_options = array('' => __('— بدون نمایش —', 'nias-course-widget'));
    foreach (get_pages(array('post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC')) as $page) {
        $page_options[$page->ID] = $page->post_title;
    }

    $instructors = nias_get_instructors();
    $inst_ids    = array_map('intval', wp_list_pluck($instructors, 'ID'));
    $candidates  = get_users(array(
        'exclude' => $inst_ids ? $inst_ids : array(0),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'number'  => 500,
    ));
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('instructors'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">

            <?php if ($notice) : ?>
                <?php if ($notice[0] === 'ok') : ?>
                    <div class="nias-saved"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="m20 6-11 11-5-5" stroke="#0a8a44" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php echo esc_html($notice[1]); ?></div>
                <?php else : ?>
                    <div class="nias-inst-err"><?php echo esc_html($notice[1]); ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Intro -->
            <div class="nias-alert nias-alert-info">
                <span class="nias-alert-bar"></span>
                <div class="nias-alert-ic">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nias-alert-body">
                    <div class="nias-alert-title"><?php echo esc_html__('مدیریت مدرسین', 'nias-course-widget'); ?></div>
                    <div class="nias-alert-desc"><?php echo esc_html__('در این صفحه می‌توانید کاربران وردپرس را به نقش «مدرس» تغییر دهید، مدرسین را حذف کنید و دوره‌های هر مدرس را مشاهده کنید. برای اختصاص مدرس به یک محصول، از صفحهٔ «ویرایش جلسات و فصل‌ها» همان محصول استفاده کنید.', 'nias-course-widget'); ?></div>
                </div>
            </div>

            <!-- Dashboard page selection ("انتخاب برگه") -->
            <?php nias_set_saved_banner($dash_saved); ?>
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M8 21h8M12 17v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('برگهٔ پیشخوان مدرس', 'nias-course-widget')); ?>
                <form method="post">
                    <?php wp_nonce_field('nias_instructor_dashboard', 'nias_inst_dash_nonce'); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('برگه‌ای را انتخاب کنید تا «پیشخوان مدرس» در آن نمایش داده شود. هر مدرس پس از ورود، پیشخوان خودش را با داده‌های واقعی (دوره‌ها، دانشجویان، درآمد ووکامرس، گواهی‌ها و نظرات) می‌بیند. اگر می‌خواهید محل نمایش را خودتان تعیین کنید، از شورت‌کد زیر استفاده کنید.', 'nias-course-widget'); ?></div>
                        <?php nias_set_select_field('instructors_dashboard_page', __('برگهٔ نمایش پیشخوان', 'nias-course-widget'), '', $page_options); ?>
                    </div>
                    <div class="nias-shortcodes" style="margin-top:6px">
                        <div class="nias-sc-head">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span><?php echo esc_html__('شورت‌کد پیشخوان مدرس', 'nias-course-widget'); ?></span>
                        </div>
                        <div class="nias-sc-list">
                            <div class="nias-sc-item"><code dir="ltr">[nias_instructor_dashboard]</code><span><?php echo esc_html__('نمایش پیشخوان مدرس برای کاربر واردشده', 'nias-course-widget'); ?></span></div>
                        </div>
                        <?php if ($dash_page) : ?>
                            <div class="nias-sc-sub" style="margin-top:10px">
                                <?php echo esc_html__('برگهٔ فعلی:', 'nias-course-widget'); ?>
                                <a href="<?php echo esc_url(get_permalink($dash_page)); ?>" target="_blank" rel="noopener" style="color:#3858e9;font-weight:700"><?php echo esc_html(get_the_title($dash_page)); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php nias_set_save_button('nias_save_instructor_dashboard'); ?>
                </form>
            <?php nias_set_card_close(); ?>

            <!-- Add instructor -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M22 11h-6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('افزودن مدرس جدید', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nias-fdesc" style="margin-bottom:12px"><?php echo esc_html__('یک کاربر وردپرس را انتخاب کنید تا نقش «مدرس» به او اضافه شود.', 'nias-course-widget'); ?></div>
                    <?php if (empty($candidates)) : ?>
                        <div class="nias-inst-empty"><?php echo esc_html__('کاربر دیگری برای افزودن وجود ندارد.', 'nias-course-widget'); ?></div>
                    <?php else : ?>
                        <form method="post" class="nias-inst-add">
                            <?php wp_nonce_field('nias_instructors_manage', 'nias_inst_nonce'); ?>
                            <input type="hidden" name="nias_inst_action" value="add">
                            <div class="nias-select-wrap nias-inst-grow">
                                <select class="nias-select" name="nias_inst_user" required>
                                    <option value=""><?php echo esc_html__('انتخاب کاربر…', 'nias-course-widget'); ?></option>
                                    <?php foreach ($candidates as $cand) : ?>
                                        <option value="<?php echo esc_attr($cand->ID); ?>"><?php echo esc_html($cand->display_name . ' — ' . $cand->user_email); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="nias-select-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="m6 9 6 6 6-6" stroke="#8a90a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            </div>
                            <button type="submit" class="nias-btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php echo esc_html__('افزودن به مدرسین', 'nias-course-widget'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php nias_set_card_close(); ?>

            <!-- Instructor list -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', sprintf(__('فهرست مدرسین (%s)', 'nias-course-widget'), nias_fa_digits(count($instructors)))); ?>
                <div class="nias-card-pad">
                    <?php if (empty($instructors)) : ?>
                        <div class="nias-inst-empty"><?php echo esc_html__('هنوز مدرسی اضافه نشده است.', 'nias-course-widget'); ?></div>
                    <?php else : ?>
                        <div class="nias-inst-list">
                            <?php foreach ($instructors as $inst) :
                                $courses = nias_get_instructor_courses($inst->ID); ?>
                                <div class="nias-inst-row">
                                    <div class="nias-inst-ava"><?php echo get_avatar($inst->ID, 48); ?></div>
                                    <div class="nias-inst-info">
                                        <div class="nias-inst-name"><?php echo esc_html($inst->display_name); ?></div>
                                        <div class="nias-inst-email"><?php echo esc_html($inst->user_email); ?></div>
                                        <div class="nias-inst-courses">
                                            <span class="nias-inst-count-chip"><?php echo esc_html(sprintf(__('%s دوره', 'nias-course-widget'), nias_fa_digits(count($courses)))); ?></span>
                                            <?php if (!empty($courses)) : ?>
                                                <?php foreach ($courses as $course) : ?>
                                                    <a href="<?php echo esc_url(get_edit_post_link($course->ID)); ?>" class="nias-inst-course"><?php echo esc_html($course->post_title ? $course->post_title : __('(بدون عنوان)', 'nias-course-widget')); ?></a>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <span class="nias-inst-nocourse"><?php echo esc_html__('هنوز به دوره‌ای اختصاص نیافته است.', 'nias-course-widget'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" class="nias-inst-rmform" onsubmit="return confirm('<?php echo esc_js(__('این مدرس از فهرست حذف شود؟', 'nias-course-widget')); ?>');">
                                        <?php wp_nonce_field('nias_instructors_manage', 'nias_inst_nonce'); ?>
                                        <input type="hidden" name="nias_inst_action" value="remove">
                                        <input type="hidden" name="nias_inst_user" value="<?php echo esc_attr($inst->ID); ?>">
                                        <button type="submit" class="nias-btn-danger nias-inst-rm">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <?php echo esc_html__('حذف', 'nias-course-widget'); ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php nias_set_card_close(); ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('سپاسگزاریم از اینکه سایت خود را با <span style="color:#3858e9;font-weight:600">وردپرس</span> ساخته‌اید.', 'nias-course-widget')); ?></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>

        <?php nias_instructors_page_styles(); ?>
    </div>
    <?php
}

/** Page-scoped styles (reuse the shared .nias-settings-app design system). */
function nias_instructors_page_styles()
{
    ?>
    <style>
        .nias-settings-app .nias-alert-info .nias-alert-bar { background:#3858e9; }
        .nias-settings-app .nias-inst-err { display:flex;align-items:center;gap:9px;background:#fdecec;border:1px solid #f5c2c2;color:#b42318;border-radius:12px;padding:13px 16px;margin-bottom:16px;font-size:13.5px;font-weight:600; }
        .nias-settings-app .nias-inst-add { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
        .nias-settings-app .nias-inst-grow { flex:1 1 320px;min-width:240px; }
        .nias-settings-app .nias-inst-empty { color:#6b7280;font-size:13.5px;padding:6px 2px; }
        .nias-settings-app .nias-inst-list { display:flex;flex-direction:column;gap:12px; }
        .nias-settings-app .nias-inst-row { display:flex;align-items:flex-start;gap:14px;padding:14px 16px;border:1px solid #e6e9ef;border-radius:14px;background:#fbfcfe; }
        .nias-settings-app .nias-inst-ava img { width:48px;height:48px;border-radius:50%;display:block; }
        .nias-settings-app .nias-inst-info { flex:1 1 auto;min-width:0; }
        .nias-settings-app .nias-inst-name { font-size:14.5px;font-weight:700;color:#1f2733; }
        .nias-settings-app .nias-inst-email { font-size:12.5px;color:#8a90a6;margin-top:2px;direction:ltr;text-align:right; }
        .nias-settings-app .nias-inst-courses { display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:9px; }
        .nias-settings-app .nias-inst-count-chip { display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:7px;background:#e7eefb;color:#3858e9;font-size:12px;font-weight:700; }
        .nias-settings-app .nias-inst-course { display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:7px;background:#fff;border:1px solid #e2e6ee;color:#475569;font-size:12px;font-weight:600;text-decoration:none; }
        .nias-settings-app .nias-inst-course:hover { border-color:#3858e9;color:#3858e9; }
        .nias-settings-app .nias-inst-nocourse { color:#9aa6b6;font-size:12px; }
        .nias-settings-app .nias-inst-rmform { flex:0 0 auto; }
        .nias-settings-app .nias-inst-rm { white-space:nowrap; }
        @media (max-width:782px){
            .nias-settings-app .nias-inst-row { flex-wrap:wrap; }
            .nias-settings-app .nias-inst-rmform { width:100%; }
        }
    </style>
    <?php
}
