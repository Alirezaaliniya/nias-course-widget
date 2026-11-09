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
        add_role(NIAS_INSTRUCTOR_ROLE, __('Instructor', 'nias-course-widget'), array('read' => true));
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
        'post_type'      => nias_course_post_types(),
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
        __('Instructors', 'nias-course-widget'),
        __('Instructors', 'nias-course-widget'),
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
        $notice = array('err', __('No valid user was selected.', 'nias-course-widget'));
        return;
    }

    if ($action === 'add') {
        $user->add_role(NIAS_INSTRUCTOR_ROLE);
        $notice = array('ok', sprintf(__('"%s" was added to the instructors list.', 'nias-course-widget'), $user->display_name));
    } elseif ($action === 'remove') {
        $user->remove_role(NIAS_INSTRUCTOR_ROLE);
        $notice = array('ok', sprintf(__('"%s" was removed from the instructors list.', 'nias-course-widget'), $user->display_name));
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
    // "Show instructor on the course page" toggle.
    $show_saved = false;
    if (isset($_POST['nias_save_instructor_show']) && check_admin_referer('nias_instructor_show', 'nias_inst_show_nonce')) {
        update_option('_nias_inst_auto_show', (isset($_POST['nias_inst_auto_show']) && $_POST['nias_inst_auto_show'] === 'on') ? 'on' : 'off');
        $show_saved = true;
    }
    $dash_page = (int) carbon_get_theme_option('instructors_dashboard_page');
    $page_options = array('' => __('— Not shown —', 'nias-course-widget'));
    foreach (get_pages(array('post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC')) as $page) {
        $page_options[$page->ID] = $page->post_title;
    }

    $instructors = nias_get_instructors();
    $inst_ids    = array_map('intval', wp_list_pluck($instructors, 'ID'));
    // Candidates are found by searching (a site can have many thousands of users).
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
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
                    <div class="nias-alert-title"><?php echo esc_html__('Manage instructors', 'nias-course-widget'); ?></div>
                    <div class="nias-alert-desc"><?php echo esc_html__('On this page you can change WordPress users to the "Instructor" role, remove instructors and see each instructor\'s courses. To assign an instructor to a product, use that product\'s "Edit lessons and chapters" page.', 'nias-course-widget'); ?></div>
                </div>
            </div>

            <!-- Dashboard page selection ("انتخاب برگه") -->
            <?php nias_set_saved_banner($dash_saved); ?>
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M8 21h8M12 17v4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Instructor dashboard page', 'nias-course-widget')); ?>
                <form method="post">
                    <?php wp_nonce_field('nias_instructor_dashboard', 'nias_inst_dash_nonce'); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php echo esc_html__('Choose a page to show the "Instructor dashboard" on. After logging in, each instructor sees their own dashboard with real data (courses, students, WooCommerce income, certificates and reviews). If you want to choose the location yourself, use the shortcode below.', 'nias-course-widget'); ?></div>
                        <?php nias_set_select_field('instructors_dashboard_page', __('Dashboard display page', 'nias-course-widget'), '', $page_options); ?>
                    </div>
                    <div class="nias-shortcodes" style="margin-top:6px">
                        <div class="nias-sc-head">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span><?php echo esc_html__('Instructor dashboard shortcode', 'nias-course-widget'); ?></span>
                        </div>
                        <div class="nias-sc-list">
                            <div class="nias-sc-item"><code dir="ltr">[nias_instructor_dashboard]</code><span><?php echo esc_html__('Shows the instructor dashboard for the logged-in user', 'nias-course-widget'); ?></span></div>
                        </div>
                        <?php if ($dash_page) : ?>
                            <div class="nias-sc-sub" style="margin-top:10px">
                                <?php echo esc_html__('Current page:', 'nias-course-widget'); ?>
                                <a href="<?php echo esc_url(get_permalink($dash_page)); ?>" target="_blank" rel="noopener" style="color:#3858e9;font-weight:700"><?php echo esc_html(get_the_title($dash_page)); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php nias_set_save_button('nias_save_instructor_dashboard'); ?>
                </form>
            <?php nias_set_card_close(); ?>

            <!-- Instructor name on the course page -->
            <?php nias_set_saved_banner($show_saved); ?>
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21a8 8 0 0 1 16 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Show the instructor on the course page', 'nias-course-widget')); ?>
                <form method="post">
                    <?php wp_nonce_field('nias_instructor_show', 'nias_inst_show_nonce'); ?>
                    <?php nias_set_toggle_row(
                        'nias_inst_auto_show',
                        __('Show the instructor name automatically', 'nias-course-widget'),
                        __('The name (and photo) of each course\'s instructor is shown below the WooCommerce product page title and at the top of the "Course" page. If you built the product page with Elementor or want another place, leave this off and use the shortcode below.', 'nias-course-widget'),
                        'off'
                    ); ?>
                    <div class="nias-shortcodes" style="margin-top:6px">
                        <div class="nias-sc-head">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="m8 16-4-4 4-4m8 0 4 4-4 4M14 4l-4 16" stroke="#c98a16" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span><?php echo esc_html__('Instructor name shortcode', 'nias-course-widget'); ?></span>
                        </div>
                        <div class="nias-sc-list">
                            <div class="nias-sc-item"><code dir="ltr">[nias_course_instructors]</code><span><?php echo esc_html__('Name and photo of this course\'s instructor(s) — in an Elementor single product template with the "Shortcode" widget', 'nias-course-widget'); ?></span></div>
                            <div class="nias-sc-item"><code dir="ltr">[nias_course_instructors layout="card"]</code><span><?php echo esc_html__('Instructor card with biography (from the user profile\'s "Biographical info")', 'nias-course-widget'); ?></span></div>
                            <div class="nias-sc-item"><code dir="ltr">[nias_course_instructors avatar="no" label=""]</code><span><?php echo esc_html__('Instructor name only, without photo or label', 'nias-course-widget'); ?></span></div>
                            <div class="nias-sc-item"><code dir="ltr">[nias_course_instructors id="123"]</code><span><?php echo esc_html__('Instructor of a specific course (on any page)', 'nias-course-widget'); ?></span></div>
                        </div>
                    </div>
                    <?php nias_set_save_button('nias_save_instructor_show'); ?>
                </form>
            <?php nias_set_card_close(); ?>

            <!-- Instructor panel -->
            <?php if (function_exists('nias_inst_panel_settings_card')) { nias_inst_panel_settings_card(); } ?>

            <!-- Add instructor -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M22 11h-6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Add new instructor', 'nias-course-widget')); ?>
                <div class="nias-card-pad">
                    <div class="nias-fdesc" style="margin-bottom:12px"><?php echo esc_html__('Choose a WordPress user to add the "Instructor" role to.', 'nias-course-widget'); ?></div>
                        <form method="post" class="nias-inst-add" onsubmit="var h=this.querySelector('input[name=nias_inst_user]');if(!h||!h.value){alert('<?php echo esc_js(__('First search for and select a user.', 'nias-course-widget')); ?>');return false;}">
                            <?php wp_nonce_field('nias_instructors_manage', 'nias_inst_nonce'); ?>
                            <input type="hidden" name="nias_inst_action" value="add">
                            <div class="nias-inst-grow" id="nias-inst-pick"></div>
                            <script>document.addEventListener('DOMContentLoaded',function(){ if(window.NiasPicker){ window.NiasPicker.attach(document.getElementById('nias-inst-pick'),{source:'user',name:'nias_inst_user',emptyLabel:<?php echo wp_json_encode(__('Search name, email or username…', 'nias-course-widget')); ?>,placeholder:<?php echo wp_json_encode(__('Name, email or username…', 'nias-course-widget')); ?>,params:{exclude:<?php echo wp_json_encode(implode(',', $inst_ids)); ?>}}); } });</script>
                            <button type="submit" class="nias-btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php echo esc_html__('Add to instructors', 'nias-course-widget'); ?>
                            </button>
                        </form>
                </div>
            <?php nias_set_card_close(); ?>

            <!-- Instructor list -->
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', sprintf(__('Instructors list (%s)', 'nias-course-widget'), nias_fa_digits(count($instructors)))); ?>
                <div class="nias-card-pad">
                    <?php if (empty($instructors)) : ?>
                        <div class="nias-inst-empty"><?php echo esc_html__('No instructor has been added yet.', 'nias-course-widget'); ?></div>
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
                                            <span class="nias-inst-count-chip"><?php echo esc_html(sprintf(__('%s courses', 'nias-course-widget'), nias_fa_digits(count($courses)))); ?></span>
                                            <?php if (!empty($courses)) : ?>
                                                <?php foreach ($courses as $course) : ?>
                                                    <a href="<?php echo esc_url(get_edit_post_link($course->ID)); ?>" class="nias-inst-course"><?php echo esc_html($course->post_title ? $course->post_title : __('(no title)', 'nias-course-widget')); ?></a>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <span class="nias-inst-nocourse"><?php echo esc_html__('Not assigned to any course yet.', 'nias-course-widget'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" class="nias-inst-rmform" onsubmit="return confirm('<?php echo esc_js(__('Remove this instructor from the list?', 'nias-course-widget')); ?>');">
                                        <?php wp_nonce_field('nias_instructors_manage', 'nias_inst_nonce'); ?>
                                        <input type="hidden" name="nias_inst_action" value="remove">
                                        <input type="hidden" name="nias_inst_user" value="<?php echo esc_attr($inst->ID); ?>">
                                        <button type="submit" class="nias-btn-danger nias-inst-rm">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <?php echo esc_html__('Remove', 'nias-course-widget'); ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php nias_set_card_close(); ?>

            <div class="nias-foot"><?php echo wp_kses_post(__('Thank you for building your site with <span style="color:#3858e9;font-weight:600">WordPress</span>.', 'nias-course-widget')); ?></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>

        <?php nias_instructors_page_styles(); ?>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Instructor name on the course page: [nias_course_instructors] + auto display
 * ---------------------------------------------------------------------- */

/**
 * HTML for a course's instructors.
 *
 * @param int   $post_id course (product or «دوره»)
 * @param array $a       avatar yes|no, size, label, link none|author, sep, layout inline|card
 * @return string '' when the course has no instructor
 */
function nias_course_instructors_html($post_id, $a = array())
{
    $a = wp_parse_args($a, array('avatar' => 'yes', 'size' => 36, 'label' => '', 'link' => 'none', 'sep' => '، ', 'layout' => 'inline'));
    $users = array();
    foreach (nias_product_instructors((int) $post_id) as $uid) {
        $u = get_userdata($uid);
        if ($u) {
            $users[] = $u;
        }
    }
    if (!$users) {
        return '';
    }
    $label = $a['label'] === null || $a['label'] === 'default'
        ? (count($users) > 1 ? __('Instructors:', 'nias-course-widget') : __('Instructor:', 'nias-course-widget'))
        : $a['label'];
    $size = max(16, min(160, (int) $a['size']));
    $name = function ($u) use ($a) {
        $n = esc_html($u->display_name);
        return $a['link'] === 'author' ? '<a href="' . esc_url(get_author_posts_url($u->ID)) . '">' . $n . '</a>' : $n;
    };

    static $css = false;
    $style = '';
    if (!$css) {
        $css = true;
        $style = '<style>.nias-ci{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:8px 0 14px;font-size:14px;direction:rtl}'
            . '.nias-ci-l{color:#6b7280}.nias-ci-u{display:inline-flex;align-items:center;gap:7px;font-weight:700;color:#1f2937}.nias-ci-u img{border-radius:50%;display:block}'
            . '.nias-ci-cards{display:grid;gap:12px;margin:12px 0;direction:rtl}.nias-ci-card{display:flex;gap:14px;align-items:flex-start;border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff}'
            . '.nias-ci-card img{border-radius:50%;flex:none}.nias-ci-card b{display:block;font-size:15px;margin-bottom:4px;color:#111827}.nias-ci-card p{margin:0;font-size:13px;line-height:1.9;color:#4b5563}'
            . '.nias-ci-card small{display:block;color:#6b7280;margin-bottom:2px}</style>';
    }

    if ($a['layout'] === 'card') {
        $h = '<div class="nias-ci-cards">';
        foreach ($users as $u) {
            $bio = get_user_meta($u->ID, 'description', true);
            $h .= '<div class="nias-ci-card">' . ($a['avatar'] !== 'no' ? get_avatar($u->ID, max($size, 56)) : '')
                . '<div>' . ($label !== '' ? '<small>' . esc_html(rtrim($label, ':')) . '</small>' : '') . '<b>' . $name($u) . '</b>'
                . ($bio ? '<p>' . nl2br(esc_html($bio)) . '</p>' : '') . '</div></div>';
        }
        return $style . $h . '</div>';
    }
    $parts = array();
    foreach ($users as $u) {
        $parts[] = '<span class="nias-ci-u">' . ($a['avatar'] !== 'no' ? get_avatar($u->ID, $size) : '') . $name($u) . '</span>';
    }
    return $style . '<div class="nias-ci">' . ($label !== '' ? '<span class="nias-ci-l">' . esc_html($label) . '</span>' : '') . implode('<span class="nias-ci-sep">' . esc_html($a['sep']) . '</span>', $parts) . '</div>';
}

add_shortcode('nias_course_instructors', 'nias_course_instructors_shortcode');
function nias_course_instructors_shortcode($atts)
{
    if (!nias_instructors_enabled()) {
        return '';
    }
    $atts = shortcode_atts(array('id' => 0, 'avatar' => 'yes', 'size' => 36, 'label' => 'default', 'link' => 'none', 'sep' => '، ', 'layout' => 'inline'), $atts, 'nias_course_instructors');
    $pid = (int) $atts['id'];
    if (!$pid) {
        $pid = (int) get_the_ID();
        if (!nias_is_course_post($pid)) {
            $pid = (int) get_queried_object_id();
        }
    }
    if (!$pid || !nias_is_course_post($pid)) {
        return '';
    }
    return nias_course_instructors_html($pid, $atts);
}

/** Automatic display (setting «نمایش خودکار نام مدرس»). */
function nias_inst_auto_show_on()
{
    return nias_instructors_enabled() && get_option('_nias_inst_auto_show') === 'on';
}
// WooCommerce single product: under the title (title = 5, rating = 10).
add_action('woocommerce_single_product_summary', function () {
    if (nias_inst_auto_show_on()) {
        echo nias_course_instructors_html(get_the_ID(), array('label' => 'default')); // phpcs:ignore -- escaped inside
    }
}, 7);
// Elementor templates: under the Product Title / Post Title widget (once per page).
add_filter('elementor/widget/render_content', function ($content, $widget) {
    static $done = false;
    if ($done || !nias_inst_auto_show_on() || !in_array($widget->get_name(), array('woocommerce-product-title', 'theme-post-title'), true)) {
        return $content;
    }
    $pid = (int) get_the_ID();
    if (!nias_is_course_post($pid) || !is_singular()) {
        return $content;
    }
    $done = true;
    return $content . nias_course_instructors_html($pid, array('label' => 'default'));
}, 10, 2);
// «دوره» page: above the content.
add_filter('the_content', function ($content) {
    if (!nias_inst_auto_show_on() || !defined('NIAS_COURSE_CPT') || !is_singular(NIAS_COURSE_CPT) || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    return nias_course_instructors_html(get_the_ID(), array('label' => 'default')) . $content;
}, 15);

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
