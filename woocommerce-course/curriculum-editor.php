<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Full-page curriculum (chapters + lessons) editor.
 *
 * Replaces the old inline metabox builder. A "ویرایش جلسات و فصل‌ها" button is
 * added next to the "Add product" button on the product edit screen; clicking
 * it opens this dedicated page (recreated from the handoff design) where the
 * current product's chapters and lessons are managed and saved over AJAX into
 * the existing "course_sections" post meta.
 *
 * @package nias-course-widget
 */

/*
 * Chrome extension links for exporting the SpotPlayer session list.
 * EDIT THESE: put the extension download link and the usage-tutorial link here.
 * (Leaving them as '#' simply renders the buttons as disabled placeholders.)
 */
if (!defined('NIAS_SPOT_EXT_DOWNLOAD_URL')) {
    define('NIAS_SPOT_EXT_DOWNLOAD_URL', 'https://github.com/Alirezaaliniya/nias-spot-exporter');
}
if (!defined('NIAS_SPOT_EXT_TUTORIAL_URL')) {
    define('NIAS_SPOT_EXT_TUTORIAL_URL', '#');
}

/* -------------------------------------------------------------------------
 * Data conversion: stored course_sections  <->  flat JS model
 * ---------------------------------------------------------------------- */

/**
 * Convert a stored media group ([0] element) to the flat JS shape.
 *
 * @param array  $group icon/video/file group ([0] element) or empty
 * @param string $type  icon|video|file
 * @return array{type:string,upload:string,url:string}
 */
function nias_curriculum_media_to_js($group, $type)
{
    $keys  = nias_media_group_keys($type);
    $group = is_array($group) ? $group : array();
    $out = array(
        'type'   => isset($group[$keys[0]]) && $group[$keys[0]] !== '' ? $group[$keys[0]] : 'url',
        'upload' => isset($group[$keys[1]]) ? $group[$keys[1]] : '',
        'url'    => isset($group[$keys[2]]) ? $group[$keys[2]] : '',
    );
    if (isset($keys[3])) {
        $out['embed'] = isset($group[$keys[3]]) ? $group[$keys[3]] : '';
    }
    return $out;
}

/**
 * Convert the flat JS media shape back into the metabox POST shape used by
 * nias_course_sanitize_media_group().
 *
 * @param array  $media {type,upload,url}
 * @param string $type  icon|video|file
 * @return array
 */
function nias_curriculum_media_to_group($media, $type)
{
    $keys  = nias_media_group_keys($type);
    $media = is_array($media) ? $media : array();
    $out = array(
        $keys[0] => isset($media['type']) ? $media['type'] : 'url',
        $keys[1] => isset($media['upload']) ? $media['upload'] : '',
        $keys[2] => isset($media['url']) ? $media['url'] : '',
    );
    if (isset($keys[3])) {
        $out[$keys[3]] = isset($media['embed']) ? $media['embed'] : '';
    }
    return $out;
}

/**
 * Build the flat JS model from the stored (Carbon-format) sections.
 *
 * @param array $sections
 * @return array
 */
function nias_curriculum_sections_to_js($sections)
{
    $out = array();
    foreach ((array) $sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $lessons = array();
        if (!empty($section['lessons']) && is_array($section['lessons'])) {
            foreach ($section['lessons'] as $lesson) {
                $lessons[] = array(
                    'title'   => isset($lesson['lesson_title']) ? $lesson['lesson_title'] : '',
                    'label'   => isset($lesson['lesson_label']) ? $lesson['lesson_label'] : '',
                    'icon'    => nias_curriculum_media_to_js(isset($lesson['lesson_icon'][0]) ? $lesson['lesson_icon'][0] : array(), 'icon'),
                    'video'   => nias_curriculum_media_to_js(isset($lesson['lesson_preview_video'][0]) ? $lesson['lesson_preview_video'][0] : array(), 'video'),
                    'file'    => nias_curriculum_media_to_js(isset($lesson['lesson_download'][0]) ? $lesson['lesson_download'][0] : array(), 'file'),
                    'private' => !empty($lesson['lesson_private']),
                    'duration' => isset($lesson['lesson_duration']) ? $lesson['lesson_duration'] : '',
                    'content' => isset($lesson['lesson_content']) ? $lesson['lesson_content'] : '',
                );
            }
        }
        $out[] = array(
            'title'    => isset($section['section_title']) ? $section['section_title'] : '',
            'subtitle' => isset($section['section_subtitle']) ? $section['section_subtitle'] : '',
            'icon'     => nias_curriculum_media_to_js(isset($section['section_icon'][0]) ? $section['section_icon'][0] : array(), 'icon'),
            'lessons'  => $lessons,
        );
    }
    return $out;
}

/**
 * Convert the JS model posted from the editor into the metabox POST shape that
 * nias_course_sanitize_sections() expects.
 *
 * @param array $chapters
 * @return array
 */
function nias_curriculum_js_to_input($chapters)
{
    $sections = array();
    foreach ((array) $chapters as $chapter) {
        if (!is_array($chapter)) {
            continue;
        }
        $section = array(
            'section_title'    => isset($chapter['title']) ? $chapter['title'] : '',
            'section_subtitle' => isset($chapter['subtitle']) ? $chapter['subtitle'] : '',
            'section_icon'     => nias_curriculum_media_to_group(isset($chapter['icon']) ? $chapter['icon'] : array(), 'icon'),
            'lessons'          => array(),
        );
        if (!empty($chapter['lessons']) && is_array($chapter['lessons'])) {
            foreach ($chapter['lessons'] as $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $section['lessons'][] = array(
                    'lesson_title'         => isset($lesson['title']) ? $lesson['title'] : '',
                    'lesson_label'         => isset($lesson['label']) ? $lesson['label'] : '',
                    'lesson_icon'          => nias_curriculum_media_to_group(isset($lesson['icon']) ? $lesson['icon'] : array(), 'icon'),
                    'lesson_preview_video' => nias_curriculum_media_to_group(isset($lesson['video']) ? $lesson['video'] : array(), 'video'),
                    'lesson_download'      => nias_curriculum_media_to_group(isset($lesson['file']) ? $lesson['file'] : array(), 'file'),
                    'lesson_private'       => !empty($lesson['private']) ? 'yes' : '',
                    'lesson_duration'      => isset($lesson['duration']) ? $lesson['duration'] : '',
                    'lesson_content'       => isset($lesson['content']) ? $lesson['content'] : '',
                );
            }
        }
        $sections[] = $section;
    }
    return $sections;
}

/* -------------------------------------------------------------------------
 * Hidden admin page registration
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_curriculum_register_page');
function nias_curriculum_register_page()
{
    $hook = add_submenu_page(
        '', // hidden (no menu parent)
        __('ویرایش جلسات و فصل‌ها', 'nias-course-widget'),
        __('ویرایش جلسات و فصل‌ها', 'nias-course-widget'),
        'edit_products',
        'nias-course-curriculum',
        'nias_curriculum_render_page'
    );

    // Hidden pages (empty parent) don't get a page title resolved, which makes
    // $GLOBALS['title'] null and triggers a strip_tags() deprecation in
    // admin-header.php. Set it explicitly on this page's load.
    if ($hook) {
        add_action('load-' . $hook, 'nias_curriculum_set_admin_title');
    }
}

function nias_curriculum_set_admin_title()
{
    $GLOBALS['title'] = __('ویرایش جلسات و فصل‌ها', 'nias-course-widget');
}

/* -------------------------------------------------------------------------
 * "ویرایش جلسات و فصل‌ها" button on the product edit screen header
 * ---------------------------------------------------------------------- */

add_action('admin_footer', 'nias_curriculum_inject_button');
function nias_curriculum_inject_button()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
        return;
    }
    global $post;
    if (!$post || !$post->ID) {
        return;
    }
    // A brand-new product is still an "auto-draft": it has an ID but hasn't been
    // saved yet. The curriculum is stored per product-ID, and opening the editor
    // is a full-page navigation that would discard the unsaved product form, so
    // for new products we still show the button but disable it with a hint to
    // save the product first.
    $is_new = ($post->post_status === 'auto-draft');
    $url    = admin_url('admin.php?page=nias-course-curriculum&product=' . intval($post->ID));
    ?>
    <style>
        .nias-curriculum-btn.page-title-action,
        .nias-curriculum-btn.page-title-action:hover,
        .nias-curriculum-btn.page-title-action:focus {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-inline-start: 8px;
            padding: 7px 18px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.6;
            color: #fff;
            border: none;
            border-radius: 9px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            box-shadow: 0 8px 18px -8px rgba(37, 99, 235, .7);
            text-shadow: none;
            animation: niasCurPulse 2.2s ease-in-out infinite;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .nias-curriculum-btn.page-title-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px -8px rgba(37, 99, 235, .8);
            animation-play-state: paused;
        }
        .nias-curriculum-btn.is-disabled,
        .nias-curriculum-btn.is-disabled:hover,
        .nias-curriculum-btn.is-disabled:focus {
            background: linear-gradient(135deg, #94a3b8, #64748b);
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
            animation: none;
            opacity: .85;
        }
        .nias-curriculum-btn .dashicons {
            width: 18px;
            height: 18px;
            font-size: 18px;
            line-height: 1;
        }
        @keyframes niasCurPulse {
            0%, 100% { box-shadow: 0 8px 18px -8px rgba(37, 99, 235, .7); }
            50%      { box-shadow: 0 8px 26px -6px rgba(37, 99, 235, 1); }
        }
    </style>
    <script>
    jQuery(function ($) {
        var label   = <?php echo wp_json_encode(__('ویرایش جلسات و فصل‌ها', 'nias-course-widget')); ?>;
        var href    = <?php echo wp_json_encode($url); ?>;
        var isNew   = <?php echo $is_new ? 'true' : 'false'; ?>;
        var saveMsg = <?php echo wp_json_encode(__('برای افزودن فصل و درس، ابتدا محصول را ذخیره کنید.', 'nias-course-widget')); ?>;
        if ($('.nias-curriculum-btn').length) {
            return;
        }
        // The ".page-title-action" ("Add New") button only exists on the list and
        // edit-existing screens — not on post-new.php. Fall back to inserting after
        // the page heading so the button is reachable when adding a new product.
        var $action = $('.wrap .page-title-action').first();
        var $anchor = $action.length ? $action : $('.wrap h1.wp-heading-inline, #wpbody-content .wrap h1').first();
        if (!$anchor.length) {
            return;
        }
        var $btn = $('<a>', {
            'class': 'page-title-action nias-curriculum-btn' + (isNew ? ' is-disabled' : ''),
            'href': isNew ? '#' : href,
            'title': isNew ? saveMsg : label
        });
        $btn.html('<span class="dashicons dashicons-welcome-learn-more"></span>' + $('<span>').text(label).html());
        if (isNew) {
            $btn.attr('aria-disabled', 'true');
            $btn.on('click', function (e) {
                e.preventDefault();
                window.alert(saveMsg);
            });
        }
        $btn.insertAfter($anchor);
    });
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Row action on the products list (next to Edit / Quick Edit)
 * ---------------------------------------------------------------------- */

add_filter('post_row_actions', 'nias_curriculum_row_action', 10, 2);
function nias_curriculum_row_action($actions, $post)
{
    if (!$post || $post->post_type !== 'product' || !current_user_can('edit_post', $post->ID)) {
        return $actions;
    }
    $url = admin_url('admin.php?page=nias-course-curriculum&product=' . intval($post->ID));
    $actions['nias_curriculum'] = '<a href="' . esc_url($url) . '" style="color:#2563eb;font-weight:600;">'
        . esc_html__('ویرایش جلسات و فصل‌ها', 'nias-course-widget') . '</a>';
    return $actions;
}

/* -------------------------------------------------------------------------
 * AJAX save
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_nias_save_curriculum', 'nias_curriculum_ajax_save');
function nias_curriculum_ajax_save()
{
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id || !check_ajax_referer('nias_curriculum_save_' . $product_id, 'nonce', false)) {
        wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'nias-course-widget')));
    }
    if (!current_user_can('edit_post', $product_id)) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'nias-course-widget')));
    }

    $raw = isset($_POST['chapters']) ? wp_unslash($_POST['chapters']) : '';
    $chapters = json_decode($raw, true);
    if (!is_array($chapters)) {
        $chapters = array();
    }

    $sections = nias_course_sanitize_sections(nias_curriculum_js_to_input($chapters));
    carbon_set_post_meta($product_id, 'course_sections', $sections);

    // Course instructors (when the feature is enabled).
    if (function_exists('nias_instructors_enabled') && nias_instructors_enabled() && isset($_POST['instructors'])) {
        $instructor_ids = array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['instructors']))));
        nias_set_product_instructors($product_id, $instructor_ids);
    }

    // SpotPlayer fields moved here from the product metabox.
    if (isset($_POST['spot_download_url'])) {
        update_post_meta($product_id, '_spotplayer_download_url', esc_url_raw(wp_unslash($_POST['spot_download_url'])));
    }
    if (isset($_POST['spot_license_course']) && function_exists('nias_spot_store_course_meta') && nias_spot_enabled()) {
        nias_spot_store_course_meta($product_id, wp_unslash($_POST['spot_license_course']));
    }

    // Custom product meta values (when the feature is enabled).
    if (function_exists('nias_meta_enabled') && nias_meta_enabled() && isset($_POST['meta_values'])) {
        nias_meta_save_product_values($product_id, wp_unslash($_POST['meta_values']));
    }

    wp_send_json_success(array('message' => __('تغییرات ذخیره شد.', 'nias-course-widget')));
}

/* -------------------------------------------------------------------------
 * Page render
 * ---------------------------------------------------------------------- */

function nias_curriculum_render_page()
{
    if (!current_user_can('edit_products')) {
        wp_die(esc_html__('دسترسی لازم را ندارید.', 'nias-course-widget'));
    }

    $product_id = isset($_GET['product']) ? intval($_GET['product']) : 0;
    $product    = $product_id ? get_post($product_id) : null;

    if (!$product || $product->post_type !== 'product' || !current_user_can('edit_post', $product_id)) {
        echo '<div class="wrap"><h1>' . esc_html__('ویرایش جلسات و فصل‌ها', 'nias-course-widget') . '</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html__('محصول معتبری انتخاب نشده است.', 'nias-course-widget') . '</p></div></div>';
        return;
    }

    wp_enqueue_media();
    wp_enqueue_editor(); // loads the wp.editor JS API for dynamic TinyMCE init

    $sections  = carbon_get_post_meta($product_id, 'course_sections');
    $sections  = is_array($sections) ? $sections : array();
    $spot_enabled = function_exists('nias_spot_enabled') && nias_spot_enabled();

    $inst_enabled   = function_exists('nias_instructors_enabled') && nias_instructors_enabled();
    $inst_available = array();
    if ($inst_enabled) {
        foreach (nias_get_instructors() as $inst_user) {
            $inst_available[] = array(
                'id'    => $inst_user->ID,
                'name'  => $inst_user->display_name,
                'email' => $inst_user->user_email,
            );
        }
    }
    $inst_selected = $inst_enabled ? nias_product_instructors($product_id) : array();

    $boot = array(
        'chapters'       => nias_curriculum_sections_to_js($sections),
        'productId'      => $product_id,
        'productTitle'   => $product->post_title,
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('nias_curriculum_save_' . $product_id),
        'productEditUrl' => get_edit_post_link($product_id, 'raw'),
        'spot'           => array(
            'enabled'      => $spot_enabled,
            'downloadUrl'  => get_post_meta($product_id, '_spotplayer_download_url', true),
            'licenseCourse'=> $spot_enabled ? get_post_meta($product_id, '_nias_spot_course', true) : '',
            'extDownload'  => NIAS_SPOT_EXT_DOWNLOAD_URL,
            'extTutorial'  => NIAS_SPOT_EXT_TUTORIAL_URL,
        ),
        'instructors'    => array(
            'enabled'   => $inst_enabled,
            'available' => $inst_available,
            'selected'  => array_map('intval', $inst_selected),
            'manageUrl' => admin_url('admin.php?page=nias-course-instructors'),
        ),
        'i18n'           => array(
            'newChapter'  => __('فصل جدید', 'nias-course-widget'),
            'newLesson'   => __('درس جدید', 'nias-course-widget'),
            'copySuffix'  => __(' (کپی)', 'nias-course-widget'),
            'saved'       => __('تغییرات ذخیره شد.', 'nias-course-widget'),
            'saving'      => __('در حال ذخیره…', 'nias-course-widget'),
            'saveError'   => __('خطا در ذخیره تغییرات.', 'nias-course-widget'),
            'leaveWarn'   => __('تغییرات ذخیره‌نشده دارید.', 'nias-course-widget'),
            'pickImage'   => __('انتخاب تصویر', 'nias-course-widget'),
            'pickFile'    => __('انتخاب فایل', 'nias-course-widget'),
            'linkPrompt'  => __('آدرس پیوند:', 'nias-course-widget'),
        ),
    );
    ?>
    <style><?php echo nias_course_font_face_css(); ?></style>
    <?php nias_curriculum_styles(); ?>

    <div id="nias-cur-app" class="nc-app" dir="rtl">
        <div class="nc-shell">

            <!-- HEADER -->
            <div class="nc-header">
                <div class="nc-head-left">
                    <div class="nc-logo">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    </div>
                    <div>
                        <div class="nc-head-title"><?php echo esc_html__('دروس و فصل‌ها', 'nias-course-widget'); ?></div>
                        <div class="nc-head-sub"><?php echo esc_html($product->post_title); ?></div>
                    </div>
                </div>
                <div class="nc-head-right">
                    <div class="nc-search">
                        <span class="nc-search-ic">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        </span>
                        <input type="text" id="nc-search-input" placeholder="<?php echo esc_attr__('جستجوی فصل یا درس…', 'nias-course-widget'); ?>">
                    </div>
                    <button type="button" class="nc-btn nc-btn-primary" id="nc-add-chapter-top">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        <?php echo esc_html__('افزودن فصل', 'nias-course-widget'); ?>
                    </button>
                    <button type="button" class="nc-btn nc-btn-save" id="nc-save-all">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                        <?php echo esc_html__('ذخیره همه تغییرات', 'nias-course-widget'); ?>
                    </button>
                    <button type="button" class="nc-btn nc-btn-spot" id="nc-spot-open">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 6 8 6-8 6V6z"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>
                        <?php echo esc_html__('ابزار اسپات پلیر', 'nias-course-widget'); ?>
                    </button>
                    <?php if ($inst_enabled) : ?>
                    <button type="button" class="nc-btn nc-btn-inst" id="nc-inst-open">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <?php echo esc_html__('مدرسین دوره', 'nias-course-widget'); ?>
                        <span class="nc-inst-count" id="nc-inst-count"></span>
                    </button>
                    <?php endif; ?>
                    <a class="nc-btn nc-btn-ghost" id="nc-back" href="<?php echo esc_url($boot['productEditUrl']); ?>">
                        <?php echo esc_html__('بازگشت به محصول', 'nias-course-widget'); ?>
                    </a>
                </div>
            </div>

            <!-- BODY GRID -->
            <div class="nc-grid">
                <div class="nc-list-col">
                    <div class="nc-list-head">
                        <div class="nc-list-head-l">
                            <span><?php echo esc_html__('محتوای دوره', 'nias-course-widget'); ?></span>
                            <span class="nc-stat" id="nc-stat"></span>
                        </div>
                        <div class="nc-list-head-r">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
                            <?php echo esc_html__('برای جابه‌جایی، دستگیره را بکشید', 'nias-course-widget'); ?>
                        </div>
                    </div>
                    <div id="nc-list"></div>
                </div>

                <div class="nc-editor" id="nc-editor">
                    <div class="nc-ed-head" id="nc-ed-head"></div>
                    <div class="nc-ed-body">
                        <div id="nc-ed-fields"></div>
                        <div id="nc-ed-content" style="display:none">
                            <div class="nc-label"><?php echo esc_html__('محتوای درس', 'nias-course-widget'); ?></div>
                            <?php
                            wp_editor('', 'nc-content-editor', array(
                                'wpautop'       => true,
                                'media_buttons' => true,
                                'quicktags'     => true,
                                'default_editor' => 'tinymce', // start on the Visual (WYSIWYG) tab
                                'editor_height' => 230,
                                'textarea_name' => 'nc_content_editor',
                                'tinymce'       => array(
                                    'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignright,aligncenter,alignleft,link,unlink,wp_more,fullscreen,wp_adv',
                                    'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
                                    // Preserve embed code (<style>/<iframe>/<script>) instead of escaping it.
                                    'extended_valid_elements' => 'style[type|media],iframe[id|class|title|name|src|width|height|frameborder|marginwidth|marginheight|scrolling|style|align|allow|allowfullscreen|webkitallowfullscreen|mozallowfullscreen|loading|referrerpolicy|sandbox],script[type|src|charset|defer|async]',
                                    'valid_children'          => '+body[style|script],+div[style|script|iframe],+span[style]',
                                    'cleanup_on_startup'      => false,
                                    'verify_html'             => false,
                                ),
                            ));
                            ?>
                            <div class="nc-note"><span>!</span><div><?php echo esc_html__('برای درج کد امبد (آپارات/یوتیوب) که شامل iframe و استایل است، مطمئن‌ترین راه استفاده از تب «کد» است.', 'nias-course-widget'); ?></div></div>
                        </div>
                    </div>
                    <div class="nc-ed-foot" id="nc-ed-foot"></div>
                </div>
            </div>

            <?php nias_meta_render_curriculum_box($product_id); ?>
        </div>

        <div id="nc-modal-root"></div>

        <!-- SpotPlayer tools drawer -->
        <div id="nc-spot-drawer" class="nc-spot-drawer" aria-hidden="true">
            <div class="nc-spot-overlay" data-spot-close></div>
            <aside class="nc-spot-panel" role="dialog" aria-label="<?php echo esc_attr__('ابزار اسپات پلیر', 'nias-course-widget'); ?>">
                <div class="nc-spot-head">
                    <div class="nc-spot-head-t">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 6 8 6-8 6V6z"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>
                        <span><?php echo esc_html__('ابزار اسپات پلیر', 'nias-course-widget'); ?></span>
                    </div>
                    <button type="button" class="nc-spot-x" data-spot-close aria-label="<?php echo esc_attr__('بستن', 'nias-course-widget'); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="nc-spot-body">

                    <!-- A: sync from URL -->
                    <section class="nc-spot-card">
                        <div class="nc-spot-card-t">
                            <span class="nc-spot-num">۱</span>
                            <?php echo esc_html__('همگام‌سازی از لینک اسپات پلیر', 'nias-course-widget'); ?>
                        </div>
                        <label class="nc-spot-label" for="nc-spot-url"><?php echo esc_html__('لینک صفحه دانلود ویدیو اسپات پلیر', 'nias-course-widget'); ?></label>
                        <input type="text" id="nc-spot-url" class="nc-spot-input" dir="ltr" placeholder="https://…" value="<?php echo esc_attr($boot['spot']['downloadUrl']); ?>">
                        <p class="nc-spot-hint"><?php echo esc_html__('لینک صفحه دانلود ویدیو را وارد کنید؛ فصل‌ها و جلسات به‌صورت خودکار خوانده شده و جایگزین محتوای فعلی می‌شوند.', 'nias-course-widget'); ?></p>
                        <button type="button" class="nc-spot-btn nc-spot-btn-primary" id="nc-spot-sync">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                            <?php echo esc_html__('همگام‌سازی جلسات', 'nias-course-widget'); ?>
                        </button>
                    </section>

                    <!-- B: Chrome extension -->
                    <section class="nc-spot-card">
                        <div class="nc-spot-card-t">
                            <span class="nc-spot-num">۲</span>
                            <?php echo esc_html__('اکستنشن کروم خروجی لیست جلسات', 'nias-course-widget'); ?>
                        </div>
                        <p class="nc-spot-hint"><?php echo esc_html__('با اکستنشن کروم می‌توانید لیست جلسات را از اسپات پلیر هم با فرمت قابل تشخیص توسط پلاگین (JSON) و هم به‌صورت PDF خروجی بگیرید.', 'nias-course-widget'); ?></p>
                        <div class="nc-spot-row">
                            <a class="nc-spot-btn nc-spot-btn-dark <?php echo NIAS_SPOT_EXT_DOWNLOAD_URL === '#' ? 'is-disabled' : ''; ?>" id="nc-spot-ext-dl" href="<?php echo esc_url(NIAS_SPOT_EXT_DOWNLOAD_URL); ?>" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                                <?php echo esc_html__('دانلود اکستنشن', 'nias-course-widget'); ?>
                            </a>
                            <a class="nc-spot-btn nc-spot-btn-soft <?php echo NIAS_SPOT_EXT_TUTORIAL_URL === '#' ? 'is-disabled' : ''; ?>" id="nc-spot-ext-help" href="<?php echo esc_url(NIAS_SPOT_EXT_TUTORIAL_URL); ?>" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/></svg>
                                <?php echo esc_html__('آموزش کار با اکستنشن', 'nias-course-widget'); ?>
                            </a>
                        </div>
                    </section>

                    <!-- C: import JSON file -->
                    <section class="nc-spot-card">
                        <div class="nc-spot-card-t">
                            <span class="nc-spot-num">۳</span>
                            <?php echo esc_html__('وارد کردن لیست جلسات از فایل JSON', 'nias-course-widget'); ?>
                        </div>
                        <p class="nc-spot-hint"><?php echo esc_html__('فایل JSON خروجی‌گرفته‌شده از اکستنشن را انتخاب کنید تا فصل‌ها و جلسات وارد و جایگزین محتوای فعلی شوند.', 'nias-course-widget'); ?></p>
                        <label class="nc-spot-file" id="nc-spot-file-label">
                            <input type="file" id="nc-spot-file" accept=".json,application/json" hidden>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            <span id="nc-spot-file-name"><?php echo esc_html__('انتخاب فایل JSON…', 'nias-course-widget'); ?></span>
                        </label>
                        <button type="button" class="nc-spot-btn nc-spot-btn-primary" id="nc-spot-import" disabled>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                            <?php echo esc_html__('وارد کردن از فایل', 'nias-course-widget'); ?>
                        </button>
                    </section>

                    <?php if ($boot['spot']['enabled']) : ?>
                    <!-- D: license course IDs -->
                    <section class="nc-spot-card">
                        <div class="nc-spot-card-t">
                            <span class="nc-spot-num">۴</span>
                            <?php echo esc_html__('شناسه دوره‌های لایسنس', 'nias-course-widget'); ?>
                        </div>
                        <label class="nc-spot-label" for="nc-spot-license"><?php echo esc_html__('شناسه دوره‌های اسپات پلیر', 'nias-course-widget'); ?></label>
                        <textarea id="nc-spot-license" class="nc-spot-input" dir="ltr" rows="2" placeholder="aaaaaaaaaaaaaaaaaaaaaaaa,bbbbbbbbbbbbbbbbbbbbbbbb"><?php echo esc_textarea($boot['spot']['licenseCourse']); ?></textarea>
                        <p class="nc-spot-hint"><?php echo esc_html__('شناسه دوره‌ها را با جداکننده , وارد کنید. با خرید محصول، لایسنس به‌صورت خودکار ساخته می‌شود. با «ذخیره همه تغییرات» ذخیره می‌شود.', 'nias-course-widget'); ?></p>
                    </section>
                    <?php endif; ?>

                </div>
            </aside>
        </div>

        <?php if ($inst_enabled) : ?>
        <!-- Instructors drawer -->
        <div id="nc-inst-drawer" class="nc-spot-drawer nc-inst-drawer" aria-hidden="true">
            <div class="nc-spot-overlay" data-inst-close></div>
            <aside class="nc-spot-panel" role="dialog" aria-label="<?php echo esc_attr__('مدرسین دوره', 'nias-course-widget'); ?>">
                <div class="nc-spot-head">
                    <div class="nc-spot-head-t">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span><?php echo esc_html__('مدرسین دوره', 'nias-course-widget'); ?></span>
                    </div>
                    <button type="button" class="nc-spot-x" data-inst-close aria-label="<?php echo esc_attr__('بستن', 'nias-course-widget'); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="nc-spot-body">
                    <section class="nc-spot-card">
                        <div class="nc-spot-card-t">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            <?php echo esc_html__('انتخاب مدرسین این دوره', 'nias-course-widget'); ?>
                        </div>
                        <p class="nc-spot-hint"><?php echo esc_html__('مدرسینی که این دوره را تدریس می‌کنند انتخاب کنید. تغییرات با «ذخیره همه تغییرات» ذخیره می‌شوند.', 'nias-course-widget'); ?></p>

                        <?php if (empty($inst_available)) : ?>
                            <div class="nc-inst-none"><?php echo esc_html__('هنوز مدرسی ثبت نشده است.', 'nias-course-widget'); ?></div>
                            <a class="nc-spot-btn nc-spot-btn-soft" href="<?php echo esc_url($boot['instructors']['manageUrl']); ?>" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M22 11h-6"/></svg>
                                <?php echo esc_html__('افزودن مدرس در صفحهٔ مدیریت', 'nias-course-widget'); ?>
                            </a>
                        <?php else : ?>
                            <div class="nc-inst-pick" id="nc-inst-pick">
                                <?php foreach ($inst_available as $inst_opt) :
                                    $checked = in_array((int) $inst_opt['id'], array_map('intval', $inst_selected), true);
                                    $initial = function_exists('mb_substr') ? mb_substr($inst_opt['name'], 0, 1, 'UTF-8') : substr($inst_opt['name'], 0, 1); ?>
                                    <label class="nc-inst-item">
                                        <input type="checkbox" class="nc-inst-cb" value="<?php echo esc_attr($inst_opt['id']); ?>" <?php checked($checked); ?>>
                                        <span class="nc-inst-ava"><?php echo esc_html($initial); ?></span>
                                        <span class="nc-inst-meta">
                                            <span class="nc-inst-name"><?php echo esc_html($inst_opt['name']); ?></span>
                                            <span class="nc-inst-email"><?php echo esc_html($inst_opt['email']); ?></span>
                                        </span>
                                        <span class="nc-inst-tick"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <a class="nc-inst-managelink" href="<?php echo esc_url($boot['instructors']['manageUrl']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('مدیریت مدرسین', 'nias-course-widget'); ?> ↗</a>
                        <?php endif; ?>
                    </section>
                </div>
            </aside>
        </div>
        <?php endif; ?>

        <div id="nc-toast" class="nc-toast"></div>
    </div>

    <script>
        window.NIAS_CUR = <?php echo wp_json_encode($boot); ?>;
    </script>
    <?php
    nias_curriculum_script();
}

/* -------------------------------------------------------------------------
 * Styles
 * ---------------------------------------------------------------------- */

function nias_curriculum_styles()
{
    ?>
    <style>
    #nias-cur-app, #nias-cur-app *{box-sizing:border-box}
    #nias-cur-app{
        --acc:#2563eb;--deep:#2159d3;--soft:#e9effd;--soft2:#dce6fc;--bord:#a8c1f7;--ring:rgba(37,99,235,.18);
        font-family:'Vazirmatn',sans-serif;color:#1f2733;margin:0;min-height:calc(100vh - 32px);
        padding:24px 22px 60px;background:radial-gradient(1200px 600px at 80% -10%, #e8effb 0%, rgba(232,239,251,0) 55%), #eef2f7;
        -webkit-font-smoothing:antialiased;
    }
    #nias-cur-app input,#nias-cur-app textarea,#nias-cur-app button{font-family:inherit}
    #nias-cur-app input:focus,#nias-cur-app textarea:focus{outline:none}
    #nias-cur-app [contenteditable]:focus{outline:none}
    #nias-cur-app ::placeholder{color:#9aa6b6}
    @keyframes nc-pop{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
    @keyframes nc-fadein{from{opacity:0}to{opacity:1}}
    @keyframes nc-slidein{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
    .nc-shell{max-width:1340px;margin:0 auto}

    /* header */
    .nc-header{display:flex;align-items:center;justify-content:space-between;gap:18px;background:#fff;border:1px solid #e6e9ef;border-radius:18px;padding:16px 20px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -16px rgba(16,24,40,.18);flex-wrap:wrap}
    .nc-head-left{display:flex;align-items:center;gap:14px}
    .nc-logo{width:46px;height:46px;border-radius:13px;background:linear-gradient(140deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px -6px rgba(37,99,235,.6)}
    .nc-head-title{font-size:20px;font-weight:800;letter-spacing:-.2px}
    .nc-head-sub{font-size:13px;color:#64748b;margin-top:3px;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-head-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .nc-search{position:relative;display:flex;align-items:center}
    .nc-search-ic{position:absolute;right:12px;display:flex;color:#9aa6b6;pointer-events:none}
    .nc-search input{width:240px;height:42px;border:1px solid #e2e6ee;background:#f7f9fc;border-radius:11px;padding:0 38px 0 14px;font-size:14px;color:#1f2733;transition:.15s}
    .nc-search input:focus{border-color:var(--acc);background:#fff;box-shadow:0 0 0 3px var(--ring)}

    .nc-btn{display:inline-flex;align-items:center;gap:7px;height:42px;padding:0 16px;border:none;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;transition:.15s;text-decoration:none}
    .nc-btn-primary{background:var(--acc);color:#fff;box-shadow:0 8px 18px -8px var(--acc)}
    .nc-btn-primary:hover{background:var(--deep);transform:translateY(-1px)}
    .nc-btn-save{background:#0ea05a;color:#fff;box-shadow:0 8px 18px -8px #0ea05a}
    .nc-btn-save:hover{background:#0b8b4d;transform:translateY(-1px)}
    .nc-btn-save.is-busy{opacity:.85;pointer-events:none}
    .nc-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:nc-spin .7s linear infinite;display:inline-block;flex:0 0 auto}
    @keyframes nc-spin{to{transform:rotate(360deg)}}
    .nc-btn-ghost{background:#fff;color:#475569;border:1px solid #e2e6ee}
    .nc-btn-ghost:hover{background:#f1f4f9}

    /* grid */
    .nc-grid{display:flex;align-items:flex-start;gap:20px;margin-top:20px;flex-wrap:wrap}
    .nc-list-col{flex:1 1 560px;min-width:320px;display:flex;flex-direction:column;gap:12px}
    .nc-list-head{display:flex;align-items:center;justify-content:space-between;padding:2px 4px 2px 2px}
    .nc-list-head-l{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#64748b}
    .nc-stat{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:7px;background:#e7eefb;color:var(--acc);font-size:12px;font-weight:700}
    .nc-list-head-r{font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:6px}
    #nc-list{display:flex;flex-direction:column;gap:11px}

    /* chapter card */
    .nc-chap{background:#fff;border:1px solid #e6e9ef;border-radius:15px;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:border-color .15s,box-shadow .15s;overflow:hidden}
    .nc-chap.sel{border-color:var(--bord);box-shadow:0 1px 2px rgba(16,24,40,.04),0 10px 26px -18px var(--acc)}
    .nc-chap.drop{outline:2px dashed var(--acc);outline-offset:1px}
    .nc-chap.dragging,.nc-lesson.dragging{opacity:.4}
    .nc-chap.dropbefore{box-shadow:inset 0 3px 0 0 var(--acc),0 1px 2px rgba(16,24,40,.04)}
    .nc-chap.dropafter{box-shadow:inset 0 -3px 0 0 var(--acc),0 1px 2px rgba(16,24,40,.04)}
    .nc-lesson.dropbefore{box-shadow:inset 0 3px 0 0 var(--acc)}
    .nc-lesson.dropafter{box-shadow:inset 0 -3px 0 0 var(--acc)}
    .nc-grip,.nc-grip-sm{user-select:none}
    .nc-chap-head{display:flex;align-items:center;gap:8px;padding:12px}
    .nc-chap.sel .nc-chap-head{background:var(--soft)}
    .nc-grip{display:flex;align-items:center;color:#aeb8c6;cursor:grab;padding:4px 2px;flex:0 0 auto}
    .nc-grip:hover{color:#64748b}
    .nc-grip-sm{display:flex;align-items:center;color:#bcc5d2;cursor:grab;flex:0 0 auto}
    .nc-grip-sm:hover{color:#64748b}
    .nc-chev{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border:none;background:#f1f4f9;color:#64748b;border-radius:8px;cursor:pointer;flex:0 0 auto;padding:0}
    .nc-chap.sel .nc-chev{background:rgba(37,99,235,.10);color:var(--deep)}
    .nc-chev svg{transition:transform .2s}
    .nc-chev.open svg{transform:rotate(90deg)}
    .nc-chap-meta{flex:1 1 auto;min-width:0;cursor:pointer;display:flex;flex-direction:column;gap:2px;padding:2px}
    .nc-chap-title{font-size:14.5px;font-weight:700;color:#1f2733;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-chap.sel .nc-chap-title{color:var(--deep)}
    .nc-chap-sub{font-size:12px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-badge{display:inline-flex;align-items:center;gap:5px;flex:0 0 auto;height:24px;padding:0 9px;border-radius:7px;background:#eef1f6;color:#64748b;font-size:12px;font-weight:700}
    .nc-icon-btn{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border:none;background:transparent;color:#aeb8c6;border-radius:8px;cursor:pointer;padding:0;transition:.13s}
    .nc-icon-btn:hover{background:var(--soft);color:var(--deep)}
    .nc-icon-btn.sm{width:28px;height:28px;color:#bcc5d2}
    .nc-icon-btn.danger:hover{background:#fff1f3;color:#e11d48}

    /* lessons */
    .nc-lessons{padding:6px 12px 12px;border-top:1px solid #eef1f6;display:flex;flex-direction:column;gap:6px;animation:nc-slidein .18s ease}
    .nc-lesson{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:11px;cursor:pointer;background:#f8fafc;border:1px solid #eef1f6;transition:background .12s,border-color .12s}
    .nc-lesson.sel{background:var(--soft);border-color:var(--bord)}
    .nc-lesson.drop{border-color:var(--acc)}
    .nc-lesson-ico{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;flex:0 0 auto;background:#eef2f9;color:#94a3b8}
    .nc-lesson.sel .nc-lesson-ico{background:#fff;color:var(--deep)}
    .nc-lesson-title{flex:0 1 auto;min-width:0;font-size:13.5px;font-weight:600;color:#33415c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-lesson.sel .nc-lesson-title{color:var(--deep)}
    .nc-private-badge{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto;height:21px;padding:0 8px;border-radius:6px;background:#fef3e8;color:#c2702a;font-size:11px;font-weight:700}
    .nc-lesson-label{flex:0 0 auto;color:#9aa6b6;font-size:12px;font-weight:600;margin-inline-start:auto;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-lesson-actions{display:flex;align-items:center;gap:2px;flex:0 0 auto;margin-inline-start:auto}
    .nc-lesson-label + .nc-lesson-actions{margin-inline-start:6px}
    .nc-add-lesson{display:inline-flex;align-items:center;justify-content:center;gap:7px;width:100%;height:40px;margin-top:2px;border:1.5px dashed #d3dbe6;background:#fbfcfe;color:#64748b;border-radius:11px;font-size:13px;font-weight:700;cursor:pointer;transition:.15s}
    .nc-add-lesson:hover{border-color:var(--acc);color:var(--deep);background:var(--soft)}
    .nc-add-chapter{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;height:52px;border:1.5px dashed var(--bord);background:var(--soft);color:var(--deep);border-radius:14px;font-size:14.5px;font-weight:700;cursor:pointer;transition:.15s}
    .nc-add-chapter:hover{background:var(--soft2);border-color:var(--acc)}

    .nc-empty{background:#fff;border:1px dashed #d7dde7;border-radius:16px;padding:46px 20px;text-align:center;color:#94a3b8}
    .nc-empty-ic{width:54px;height:54px;border-radius:14px;background:#f1f4f9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .nc-empty b{display:block;font-size:15px;font-weight:700;color:#475569}

    /* editor */
    .nc-editor{flex:0 0 452px;width:452px;max-width:100%;position:sticky;top:20px;align-self:flex-start;background:#fff;border:1px solid #e6e9ef;border-radius:18px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 14px 40px -22px rgba(16,24,40,.28);max-height:calc(100vh - 60px);display:flex;flex-direction:column;overflow:hidden}
    .nc-ed-head{padding:16px 18px;border-bottom:1px solid #eef1f6;display:flex;align-items:center;gap:11px;flex:0 0 auto}
    .nc-ed-badge{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex:0 0 auto;background:var(--soft2);color:var(--deep)}
    .nc-ed-crumb{font-size:12px;color:#94a3b8;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
    .nc-ed-title{font-size:15px;font-weight:800;margin-top:1px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
    .nc-ed-body{overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:18px}
    #nc-ed-fields{display:flex;flex-direction:column;gap:18px}
    #nc-ed-content{display:flex;flex-direction:column}
    #nc-ed-content .nc-label{margin-bottom:8px}
    .nc-label{font-size:13px;font-weight:700;color:#475569;margin-bottom:8px}
    .nc-label .req{color:#e11d48}
    .nc-input{width:100%;height:44px;border:1px solid #e2e6ee;background:#f7f9fc;border-radius:11px;padding:0 14px;font-size:14px;color:#1f2733;transition:.15s}
    .nc-input:focus{border-color:var(--acc);background:#fff;box-shadow:0 0 0 3px var(--ring)}

    .nc-drop{display:flex;align-items:center;gap:12px;padding:12px;border:1.5px dashed #d7dde7;border-radius:13px;background:#fbfcfe;cursor:pointer;transition:.15s}
    .nc-drop:hover{border-color:var(--acc);background:var(--soft)}
    .nc-drop-ic{width:40px;height:40px;border-radius:10px;background:#eef2f9;display:flex;align-items:center;justify-content:center;color:#9aa6b6;flex:0 0 auto;overflow:hidden}
    .nc-drop-ic img{width:100%;height:100%;object-fit:cover}
    .nc-drop-text{flex:1 1 auto;min-width:0}
    .nc-drop-t{font-size:13px;font-weight:600;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-drop-s{font-size:12px;color:#9aa6b6}
    .nc-chip{display:inline-flex;align-items:center;height:30px;padding:0 13px;border-radius:9px;background:#fff;border:1px solid #e2e6ee;color:#475569;font-size:12.5px;font-weight:700;flex:0 0 auto}
    .nc-media-url{display:flex;gap:8px;margin-top:8px}
    .nc-media-url input{flex:1 1 auto;height:38px;border:1px solid #e2e6ee;background:#f7f9fc;border-radius:9px;padding:0 12px;font-size:13px;color:#1f2733}
    .nc-media-url input:focus{border-color:var(--acc);background:#fff}
    .nc-media-embed{margin-top:8px}
    .nc-media-embed-lbl{font-size:12px;color:#64748b;margin-bottom:5px}
    .nc-media-embed textarea{width:100%;min-height:72px;border:1px solid #e2e6ee;background:#f7f9fc;border-radius:9px;padding:9px 12px;font-size:12.5px;color:#1f2733;font-family:monospace;resize:vertical}
    .nc-media-embed textarea:focus{border-color:var(--acc);background:#fff}
    .nc-media-clear{flex:0 0 auto;height:38px;padding:0 12px;border:1px solid #f4d4dc;background:#fff;color:#e11d48;border-radius:9px;font-size:12.5px;font-weight:700;cursor:pointer}

    .nc-private{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 14px;border:1px solid #eef1f6;background:#f7f9fc;border-radius:13px;cursor:pointer;transition:.15s}
    .nc-private.on{border-color:var(--bord);background:var(--soft)}
    .nc-private-l{display:flex;align-items:center;gap:10px}
    .nc-lock{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;flex:0 0 auto;background:#eef2f9;color:#94a3b8}
    .nc-private.on .nc-lock{background:#fff;color:var(--deep)}
    .nc-private-t{font-size:13.5px;font-weight:700;color:#1f2733}
    .nc-private-s{font-size:12px;color:#94a3b8}
    .nc-switch{position:relative;width:46px;height:26px;border-radius:14px;flex:0 0 auto;background:#cbd5e1;transition:background .2s}
    .nc-private.on .nc-switch{background:var(--acc)}
    .nc-knob{position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:right .2s}
    .nc-private.on .nc-knob{right:23px}

    .nc-wp-editor{width:100%}
    .nc-note{display:flex;align-items:center;gap:8px;margin-top:10px;padding:10px 12px;background:#fff5ef;border:1px solid #ffe1cf;border-radius:11px}
    .nc-note span{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:7px;background:#ff8a3c;color:#fff;flex:0 0 auto;font-size:13px;font-weight:800}
    .nc-note div{font-size:12px;color:#9a5a2c;line-height:1.7}
    .nc-ed-body .wp-editor-wrap{border:1px solid #e2e6ee;border-radius:12px;overflow:hidden}
    .nc-ed-body .wp-editor-tools{padding-top:8px;background:#f7f9fc}
    .nc-ed-body .wp-editor-container textarea.wp-editor-area{border:none}

    .nc-statbox{display:flex;align-items:center;gap:10px;padding:13px 14px;background:#f7f9fc;border:1px solid #eef1f6;border-radius:12px;font-size:13px;color:#64748b}
    .nc-statbox .nc-statbox-ic{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9px;background:var(--soft);color:var(--deep);flex:0 0 auto}
    .nc-statbox b{color:#1f2733}

    .nc-ed-foot{padding:13px 18px;border-top:1px solid #eef1f6;display:flex;align-items:center;gap:10px;flex:0 0 auto;background:#fbfcfe}
    .nc-save{display:inline-flex;align-items:center;justify-content:center;gap:7px;flex:1 1 auto;height:44px;border:none;border-radius:12px;background:var(--acc);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 8px 18px -8px var(--acc);transition:.15s}
    .nc-save:hover{background:var(--deep);transform:translateY(-1px)}
    .nc-save.is-busy{opacity:.85;pointer-events:none}
    .nc-ghost{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:1px solid #e2e6ee;background:#fff;color:#475569;border-radius:12px;cursor:pointer;flex:0 0 auto;transition:.13s}
    .nc-ghost:hover{background:#f1f4f9}
    .nc-ghost.danger{border-color:#f4d4dc;color:#e11d48}
    .nc-ghost.danger:hover{background:#fff1f3}

    /* modal */
    .nc-overlay{position:fixed;inset:0;background:rgba(17,24,39,.5);display:flex;align-items:center;justify-content:center;z-index:99999;animation:nc-fadein .15s ease;padding:20px}
    .nc-modal{width:400px;max-width:100%;background:#fff;border-radius:18px;box-shadow:0 24px 60px -12px rgba(16,24,40,.4);padding:24px;animation:nc-pop .2s ease}
    .nc-modal-head{display:flex;align-items:center;gap:13px}
    .nc-modal-ic{width:46px;height:46px;border-radius:13px;background:#fff1f3;display:flex;align-items:center;justify-content:center;color:#e11d48;flex:0 0 auto}
    .nc-modal-title{font-size:16px;font-weight:800;color:#1f2733}
    .nc-modal-sub{font-size:13px;color:#64748b;margin-top:2px}
    .nc-modal-body{margin-top:16px;font-size:14px;color:#475569;line-height:1.9}
    .nc-modal-body b{color:#1f2733}
    .nc-modal-actions{display:flex;gap:10px;margin-top:22px}
    .nc-modal-confirm{flex:1 1 auto;height:44px;border:none;background:#e11d48;color:#fff;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 8px 18px -8px rgba(225,29,72,.6)}
    .nc-modal-confirm:hover{background:#be123c}
    .nc-modal-cancel{flex:0 0 auto;height:44px;padding:0 20px;border:1px solid #e2e6ee;background:#fff;color:#475569;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer}
    .nc-modal-cancel:hover{background:#f1f4f9}

    /* toast */
    .nc-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);background:#1f2733;color:#fff;padding:12px 22px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:0 16px 40px -12px rgba(16,24,40,.5);opacity:0;pointer-events:none;transition:.25s;z-index:100000}
    .nc-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
    .nc-toast.err{background:#b91c1c}

    /* SpotPlayer tools button + drawer */
    .nc-btn-spot{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 8px 18px -8px rgba(109,40,217,.7)}
    .nc-btn-spot:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6)}
    .nc-spot-drawer{position:fixed;inset:0;z-index:100001;display:none}
    .nc-spot-drawer.open{display:block}
    .nc-spot-overlay{position:absolute;inset:0;background:rgba(15,23,42,.55);opacity:0;transition:opacity .25s}
    .nc-spot-drawer.open .nc-spot-overlay{opacity:1}
    .nc-spot-panel{position:absolute;top:0;bottom:0;left:0;width:420px;max-width:92vw;background:#f6f7fb;box-shadow:0 0 60px -10px rgba(16,24,40,.5);display:flex;flex-direction:column;transform:translateX(-100%);transition:transform .28s cubic-bezier(.4,0,.2,1)}
    .nc-spot-drawer.open .nc-spot-panel{transform:translateX(0)}
    .nc-spot-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff}
    .nc-spot-head-t{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800}
    .nc-spot-x{width:34px;height:34px;border:none;background:rgba(255,255,255,.18);color:#fff;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center}
    .nc-spot-x:hover{background:rgba(255,255,255,.3)}
    .nc-spot-body{padding:18px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px}
    .nc-spot-card{background:#fff;border:1px solid #eceef4;border-radius:16px;padding:16px 18px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
    .nc-spot-card-t{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:800;color:#1f2733;margin-bottom:12px}
    .nc-spot-num{width:24px;height:24px;flex:0 0 24px;border-radius:8px;background:#ede9fe;color:#6d28d9;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center}
    .nc-spot-label{display:block;font-size:12.5px;font-weight:700;color:#475569;margin-bottom:6px}
    .nc-spot-input{width:100%;box-sizing:border-box;border:1px solid #dce0ea;border-radius:10px;padding:10px 12px;font-size:13px;font-family:inherit;background:#fbfcfe;outline:none;transition:.15s}
    .nc-spot-input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12);background:#fff}
    textarea.nc-spot-input{resize:vertical;line-height:1.7}
    .nc-spot-hint{font-size:11.5px;color:#7b819a;line-height:1.9;margin:8px 0 12px}
    .nc-spot-row{display:flex;gap:10px;flex-wrap:wrap}
    .nc-spot-row>.nc-spot-btn{flex:1 1 0;min-width:140px}
    .nc-spot-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;transition:.15s;width:100%;box-sizing:border-box}
    .nc-spot-btn-primary{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 8px 16px -8px rgba(109,40,217,.6)}
    .nc-spot-btn-primary:hover{filter:brightness(1.05)}
    .nc-spot-btn-primary:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
    .nc-spot-btn-dark{background:#1f2733;color:#fff}
    .nc-spot-btn-dark:hover{background:#0f172a}
    .nc-spot-btn-soft{background:#ede9fe;color:#6d28d9}
    .nc-spot-btn-soft:hover{background:#ddd6fe}
    .nc-spot-btn.is-disabled{opacity:.45;pointer-events:none}
    .nc-spot-btn.is-busy{opacity:.7;pointer-events:none}
    .nc-spot-file{display:flex;align-items:center;gap:9px;border:1.5px dashed #c8cdda;border-radius:11px;padding:13px 14px;font-size:12.5px;color:#64748b;cursor:pointer;background:#fbfcfe;margin-bottom:12px;transition:.15s}
    .nc-spot-file:hover{border-color:#7c3aed;color:#6d28d9;background:#faf8ff}
    .nc-spot-file #nc-spot-file-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* Instructors button + drawer */
    .nc-btn-inst{background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;box-shadow:0 8px 18px -8px rgba(67,56,202,.7)}
    .nc-btn-inst:hover{background:linear-gradient(135deg,#4338ca,#3730a3)}
    .nc-inst-count{display:none;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:rgba(255,255,255,.25);color:#fff;font-size:12px;font-weight:800}
    .nc-inst-count.has{display:inline-flex}
    .nc-inst-drawer .nc-spot-panel{right:0;left:auto;transform:translateX(100%)}
    .nc-inst-drawer.open .nc-spot-panel{transform:translateX(0)}
    .nc-inst-drawer .nc-spot-head{background:linear-gradient(135deg,#4f46e5,#4338ca)}
    .nc-inst-pick{display:flex;flex-direction:column;gap:9px;margin-top:4px}
    .nc-inst-item{display:flex;align-items:center;gap:11px;padding:11px 12px;border:1px solid #e6e9ef;border-radius:13px;background:#fbfcfe;cursor:pointer;transition:.15s}
    .nc-inst-item:hover{border-color:#c7d0f7;background:#f5f7ff}
    .nc-inst-item input{position:absolute;opacity:0;width:0;height:0}
    .nc-inst-item:has(input:checked){border-color:#4f46e5;background:#eef0fe}
    .nc-inst-ava{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;flex:0 0 auto;background:#e0e3fb;color:#4338ca;font-size:15px;font-weight:800}
    .nc-inst-item:has(input:checked) .nc-inst-ava{background:#4f46e5;color:#fff}
    .nc-inst-meta{flex:1 1 auto;min-width:0;display:flex;flex-direction:column}
    .nc-inst-name{font-size:13.5px;font-weight:700;color:#1f2733;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-inst-email{font-size:11.5px;color:#8a90a6;direction:ltr;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nc-inst-tick{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;flex:0 0 auto;border:2px solid #cdd3e6;color:transparent;transition:.15s}
    .nc-inst-item:has(input:checked) .nc-inst-tick{background:#4f46e5;border-color:#4f46e5;color:#fff}
    .nc-inst-none{padding:14px;border:1px dashed #d7dde7;border-radius:12px;background:#fbfcfe;color:#94a3b8;font-size:13px;text-align:center;margin-bottom:12px}
    .nc-inst-managelink{display:inline-block;margin-top:14px;color:#4f46e5;font-size:12.5px;font-weight:700;text-decoration:none}
    .nc-inst-managelink:hover{text-decoration:underline}
    </style>
    <?php
}

/* -------------------------------------------------------------------------
 * App script
 * ---------------------------------------------------------------------- */

function nias_curriculum_script()
{
    ?>
    <script>
    (function () {
        var DATA = window.NIAS_CUR || {};
        var I18N = DATA.i18n || {};
        var counter = 1;
        function uid(p) { return p + (counter++); }
        function faNum(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function mkMedia(m) { m = m || {}; return { type: m.type || 'url', upload: m.upload || '', url: m.url || '', embed: m.embed || '' }; }
        function mediaUrl(m) { return m && m.type === 'upload' ? (m.upload || '') : (m && m.url || ''); }

        /* ---- icons ---- */
        var IC = {
            grip: '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>',
            gripSm: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>',
            chev: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>',
            book: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            bookBig: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            play: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>',
            playBig: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>',
            lockSm: '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            lock: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            copy: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
            trash: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>',
            plus: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
            img: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L7 20"/></svg>',
            video: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
            file: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>'
        };

        /* ---- state ---- */
        var state = {
            chapters: (DATA.chapters || []).map(function (c) {
                return {
                    id: uid('c'), title: c.title || '', subtitle: c.subtitle || '',
                    icon: mkMedia(c.icon), expanded: false,
                    lessons: (c.lessons || []).map(function (l) {
                        return {
                            id: uid('l'), title: l.title || '', label: l.label || '',
                            icon: mkMedia(l.icon), video: mkMedia(l.video), file: mkMedia(l.file),
                            private: !!l.private, duration: l.duration || '', content: l.content || ''
                        };
                    })
                };
            }),
            selected: null, search: '', confirm: null, dirty: false
        };
        if (state.chapters.length) {
            var c0 = state.chapters[0]; c0.expanded = true;
            state.selected = c0.lessons.length
                ? { type: 'lesson', chapterId: c0.id, lessonId: c0.lessons[0].id }
                : { type: 'chapter', chapterId: c0.id, lessonId: null };
        } else {
            state.selected = { type: 'chapter', chapterId: null, lessonId: null };
        }
        var dragInfo = null;

        /* ---- dom refs ---- */
        var listEl = document.getElementById('nc-list');
        var editorEl = document.getElementById('nc-editor');
        var headEl = document.getElementById('nc-ed-head');
        var fieldsEl = document.getElementById('nc-ed-fields');
        var contentEl = document.getElementById('nc-ed-content');
        var footEl = document.getElementById('nc-ed-foot');
        var statEl = document.getElementById('nc-stat');
        var modalRoot = document.getElementById('nc-modal-root');
        var toastEl = document.getElementById('nc-toast');

        /* ---- getters ---- */
        function getChapter(id) { for (var i = 0; i < state.chapters.length; i++) if (state.chapters[i].id === id) return state.chapters[i]; return null; }
        function getLesson(ch, id) { if (!ch) return null; for (var i = 0; i < ch.lessons.length; i++) if (ch.lessons[i].id === id) return ch.lessons[i]; return null; }
        function selChapter() { return getChapter(state.selected.chapterId); }
        function selLesson() { var ch = selChapter(); return ch ? getLesson(ch, state.selected.lessonId) : null; }
        function markDirty() { state.dirty = true; }

        /* ---- stat ---- */
        function renderStat() {
            var totL = 0;
            state.chapters.forEach(function (c) { totL += c.lessons.length; });
            statEl.textContent = faNum(state.chapters.length) + ' فصل · ' + faNum(totL) + ' درس';
        }

        /* ---- list ---- */
        function renderList() {
            var q = state.search.trim();
            var rows = [];
            state.chapters.forEach(function (ch) {
                var lessons = ch.lessons, expanded = ch.expanded, show = true;
                if (q) {
                    var cm = ch.title.indexOf(q) >= 0;
                    var lm = ch.lessons.filter(function (l) { return l.title.indexOf(q) >= 0; });
                    if (!cm) { lessons = lm; }
                    show = cm || lm.length > 0;
                    expanded = true;
                }
                if (!show) return;
                rows.push(chapterHtml(ch, lessons, expanded));
            });

            if (!rows.length) {
                if (q) {
                    listEl.innerHTML = '<div class="nc-empty"><div class="nc-empty-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#aeb8c6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></div><b>نتیجه‌ای یافت نشد</b><div style="font-size:13px;margin-top:5px">برای عبارت «' + esc(q) + '» فصل یا درسی پیدا نشد.</div></div>';
                } else {
                    listEl.innerHTML = '<div class="nc-empty"><div class="nc-empty-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#aeb8c6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div><b>هنوز فصلی اضافه نشده</b><div style="font-size:13px;margin-top:5px">برای شروع، یک فصل جدید اضافه کنید.</div></div>'
                        + '<button type="button" class="nc-add-chapter" data-add-chapter style="margin-top:11px">' + IC.plus + ' افزودن فصل جدید</button>';
                }
                renderStat();
                return;
            }

            rows.push('<button type="button" class="nc-add-chapter" data-add-chapter>' + IC.plus + ' افزودن فصل جدید</button>');
            listEl.innerHTML = rows.join('');
            renderStat();
        }

        function chapterHtml(ch, lessons, expanded) {
            var sel = state.selected.type === 'chapter' && state.selected.chapterId === ch.id;
            var h = '';
            h += '<div class="nc-chap' + (sel ? ' sel' : '') + '" data-cid="' + ch.id + '">';
            h += '<div class="nc-chap-head">';
            h += '<span class="nc-grip" draggable="true" data-drag="chapter" data-cid="' + ch.id + '" title="جابه‌جایی فصل">' + IC.grip + '</span>';
            h += '<button type="button" class="nc-chev' + (expanded ? ' open' : '') + '" data-toggle="' + ch.id + '" title="باز/بستن">' + IC.chev + '</button>';
            h += '<div class="nc-chap-meta" data-select-chap="' + ch.id + '">';
            h += '<span class="nc-chap-title" data-title-c="' + ch.id + '">' + esc(ch.title || 'بدون عنوان') + '</span>';
            if (ch.subtitle) h += '<span class="nc-chap-sub">' + esc(ch.subtitle) + '</span>';
            h += '</div>';
            h += '<span class="nc-badge">' + IC.book + ' ' + faNum(ch.lessons.length) + ' درس</span>';
            h += '<div style="display:flex;align-items:center;gap:2px;flex:0 0 auto">';
            h += '<button type="button" class="nc-icon-btn" data-copy-chap="' + ch.id + '" title="کپی فصل">' + IC.copy + '</button>';
            h += '<button type="button" class="nc-icon-btn danger" data-del-chap="' + ch.id + '" title="حذف فصل">' + IC.trash + '</button>';
            h += '</div></div>';

            if (expanded) {
                h += '<div class="nc-lessons">';
                lessons.forEach(function (ls) {
                    var lsel = state.selected.type === 'lesson' && state.selected.lessonId === ls.id;
                    h += '<div class="nc-lesson' + (lsel ? ' sel' : '') + '" data-cid="' + ch.id + '" data-lid="' + ls.id + '" data-select-lesson>';
                    h += '<span class="nc-grip-sm" draggable="true" data-drag="lesson" data-cid="' + ch.id + '" data-lid="' + ls.id + '" title="جابه‌جایی درس">' + IC.gripSm + '</span>';
                    h += '<span class="nc-lesson-ico">' + IC.play + '</span>';
                    h += '<span class="nc-lesson-title" data-title-l="' + ls.id + '">' + esc(ls.title || 'بدون عنوان') + '</span>';
                    if (ls.private) h += '<span class="nc-private-badge">' + IC.lockSm + ' خصوصی</span>';
                    if (ls.label) h += '<span class="nc-lesson-label" data-label-l="' + ls.id + '">' + esc(ls.label) + '</span>';
                    h += '<div class="nc-lesson-actions">';
                    h += '<button type="button" class="nc-icon-btn sm" data-copy-lesson="' + ls.id + '" data-cid="' + ch.id + '" title="کپی درس">' + IC.copy + '</button>';
                    h += '<button type="button" class="nc-icon-btn sm danger" data-del-lesson="' + ls.id + '" data-cid="' + ch.id + '" title="حذف درس">' + IC.trash + '</button>';
                    h += '</div></div>';
                });
                h += '<button type="button" class="nc-add-lesson" data-add-lesson="' + ch.id + '">' + IC.plus + ' افزودن درس به این فصل</button>';
                h += '</div>';
            }
            h += '</div>';
            return h;
        }

        /* ---- media control ---- */
        function mediaHtml(scope, key, media, ic, hint, pickLabel, allowEmbed) {
            var url = mediaUrl(media);
            var fname = url ? url.split('/').pop().split('?')[0] : 'چیزی وارد نشده';
            var hasEmbed = allowEmbed && media && media.type === 'embed' && media.embed;
            var mainLabel = url ? fname : (hasEmbed ? 'کد امبد ثبت شده' : 'چیزی وارد نشده');
            var thumb = (url && /\.(png|jpe?g|gif|webp|svg|bmp)$/i.test(url)) ? '<img src="' + esc(url) + '">' : ic;
            var h = '<div class="nc-media" data-scope="' + scope + '" data-key="' + key + '">';
            h += '<div class="nc-drop" data-media-pick="' + key + '" data-scope="' + scope + '">';
            h += '<div class="nc-drop-ic">' + thumb + '</div>';
            h += '<div class="nc-drop-text"><div class="nc-drop-t">' + esc(mainLabel) + '</div><div class="nc-drop-s">' + esc(hint) + '</div></div>';
            h += '<span class="nc-chip">' + esc(pickLabel) + '</span></div>';
            h += '<div class="nc-media-url">';
            h += '<input type="text" data-media-url="' + key + '" data-scope="' + scope + '" placeholder="یا لینک مستقیم را وارد کنید" value="' + esc(media.type === 'upload' ? '' : (media.url || '')) + '">';
            if (url) h += '<button type="button" class="nc-media-clear" data-media-clear="' + key + '" data-scope="' + scope + '">حذف</button>';
            h += '</div>';
            if (allowEmbed) {
                // Optional third input: paste a full embed snippet (iframe/style/…).
                h += '<div class="nc-media-embed">';
                h += '<div class="nc-media-embed-lbl">یا کد امبد (iframe) را اینجا بچسبانید</div>';
                h += '<textarea data-media-embed="' + key + '" data-scope="' + scope + '" rows="3" dir="ltr" placeholder="&lt;iframe src=&quot;…&quot;&gt;&lt;/iframe&gt;">' + esc(media.embed || '') + '</textarea>';
                h += '</div>';
            }
            h += '</div>';
            return h;
        }

        /* ---- WordPress (TinyMCE) content editor (single persistent instance) ---- */
        var EDITOR_ID = 'nc-content-editor';
        var editorLessonId = null;

        function lessonById(id) {
            for (var i = 0; i < state.chapters.length; i++) { var l = getLesson(state.chapters[i], id); if (l) return l; }
            return null;
        }
        function activeTinymce() {
            if (!window.tinymce) return null;
            var ed = window.tinymce.get(EDITOR_ID);
            return (ed && !ed.isHidden()) ? ed : null;
        }
        function getEditorContent() {
            var ed = activeTinymce();
            if (ed) return ed.getContent();
            var ta = document.getElementById(EDITOR_ID);
            return ta ? ta.value : '';
        }
        function setEditorContent(html) {
            html = html || '';
            var ed = window.tinymce ? window.tinymce.get(EDITOR_ID) : null;
            if (ed) { ed.setContent(html); }
            var ta = document.getElementById(EDITOR_ID);
            if (ta) ta.value = html; // keeps the Text tab + any not-yet-ready editor in sync
        }
        function flushEditor() {
            if (!editorLessonId) return;
            var ls = lessonById(editorLessonId);
            if (ls) ls.content = getEditorContent();
        }

        /* ---- editor ---- */
        function renderEditor() {
            flushEditor();
            var sel = state.selected;
            if (!sel || !selChapter()) {
                editorLessonId = null;
                headEl.innerHTML = '';
                fieldsEl.innerHTML = '<div class="nc-empty" style="border:none"><b>چیزی برای ویرایش نیست</b><div style="font-size:13px;margin-top:5px">یک فصل اضافه کنید تا شروع کنید.</div></div>';
                contentEl.style.display = 'none';
                footEl.innerHTML = '';
                return;
            }
            if (sel.type === 'chapter') { renderChapterEditor(); }
            else { renderLessonEditor(); }
        }

        function footInner() {
            return '<button type="button" class="nc-save" data-save title="ذخیره"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg> ذخیره تغییرات</button>'
                + '<button type="button" class="nc-ghost" data-duplicate title="تکثیر">' + IC.copy + '</button>'
                + '<button type="button" class="nc-ghost danger" data-del-current title="حذف">' + IC.trash + '</button>';
        }

        function renderChapterEditor() {
            editorLessonId = null;
            contentEl.style.display = 'none';
            var ch = selChapter();
            headEl.innerHTML = '<div class="nc-ed-badge">' + IC.bookBig + '</div><div style="min-width:0;flex:1 1 auto"><div class="nc-ed-crumb">ویرایش فصل</div><div class="nc-ed-title" data-head-title>' + esc(ch.title || 'بدون عنوان') + '</div></div>';
            var h = '';
            h += '<div><div class="nc-label">عنوان فصل <span class="req">*</span></div><input type="text" class="nc-input" data-field="chapTitle" value="' + esc(ch.title) + '" placeholder="مثلاً: مقدمه و توضیحات دوره"></div>';
            h += '<div><div class="nc-label">زیرعنوان</div><input type="text" class="nc-input" data-field="chapSub" value="' + esc(ch.subtitle) + '" placeholder="یک توضیح کوتاه دربارهٔ این فصل"></div>';
            h += '<div><div class="nc-label">آیکون فصل</div>' + mediaHtml('chapter', 'icon', ch.icon, IC.img, 'یک تصویر انتخاب کنید یا لینک بدهید', 'انتخاب') + '</div>';
            h += '<div class="nc-statbox"><span class="nc-statbox-ic">' + IC.book + '</span><div>این فصل شامل <b>' + faNum(ch.lessons.length) + ' درس</b> است.</div></div>';
            fieldsEl.innerHTML = h;
            footEl.innerHTML = footInner();
        }

        function renderLessonEditor() {
            var ch = selChapter();
            var ls = selLesson();
            if (!ls) { renderChapterEditor(); return; }
            headEl.innerHTML = '<div class="nc-ed-badge">' + IC.playBig + '</div><div style="min-width:0;flex:1 1 auto"><div class="nc-ed-crumb">' + esc(ch.title || '') + ' ‹ ویرایش درس</div><div class="nc-ed-title" data-head-title>' + esc(ls.title || 'بدون عنوان') + '</div></div>';
            var h = '';
            h += '<div><div class="nc-label">عنوان درس <span class="req">*</span></div><input type="text" class="nc-input" data-field="lessonTitle" value="' + esc(ls.title) + '" placeholder="مثلاً: مقدمه"></div>';
            h += '<div><div class="nc-label">برچسب درس</div><input type="text" class="nc-input" data-field="lessonLabel" value="' + esc(ls.label) + '" placeholder="مثلاً: رایگان"></div>';
            h += '<div><div class="nc-label">مدت زمان درس</div><input type="text" class="nc-input" data-field="lessonDuration" value="' + esc(ls.duration || '') + '" placeholder="مثلاً: ۱۲:۳۵ یا ۱۵ دقیقه"></div>';
            h += '<div><div class="nc-label">آیکون درس</div>' + mediaHtml('lesson', 'icon', ls.icon, IC.img, 'یک تصویر انتخاب کنید یا لینک بدهید', 'انتخاب') + '</div>';
            h += '<div><div class="nc-label">ویدیوی پیش‌نمایش</div>' + mediaHtml('lesson', 'video', ls.video, IC.video, 'لینک، فایل یا کد امبد ویدیو را اضافه کنید', 'افزودن', true) + '</div>';
            h += '<div><div class="nc-label">فایل خصوصی درس</div>' + mediaHtml('lesson', 'file', ls.file, IC.file, 'PDF، تمرین یا فایل پیوست', 'افزودن') + '</div>';
            h += '<div class="nc-private' + (ls.private ? ' on' : '') + '" data-private-toggle><div class="nc-private-l"><span class="nc-lock">' + IC.lock + '</span><div><div class="nc-private-t">درس خصوصی است؟</div><div class="nc-private-s">فقط برای کاربران خریدار نمایش داده می‌شود</div></div></div><span class="nc-switch"><span class="nc-knob"></span></span></div>';
            fieldsEl.innerHTML = h;
            contentEl.style.display = '';
            setEditorContent(ls.content || '');
            editorLessonId = ls.id;
            footEl.innerHTML = footInner();
        }

        /* keep TinyMCE content + dirty flag in sync with the persistent editor */
        function bindTinymce(ed) {
            ed.on('keyup ExecCommand Undo Redo', function () { if (editorLessonId) markDirty(); });
            // Once TinyMCE is ready, re-apply the current selection so content
            // and visibility are correct even if it initialized while hidden.
            ed.on('init', function () { renderEditor(); });
        }
        if (window.tinymce) {
            var existingEd = window.tinymce.get(EDITOR_ID);
            if (existingEd) bindTinymce(existingEd);
            window.tinymce.on('AddEditor', function (e) { if (e.editor && e.editor.id === EDITOR_ID) bindTinymce(e.editor); });
        }

        function renderAll() { renderList(); renderEditor(); }

        /* ---- mutations ---- */
        function addChapter() {
            var ch = { id: uid('c'), title: I18N.newChapter || 'فصل جدید', subtitle: '', icon: mkMedia(), expanded: true, lessons: [] };
            state.chapters.push(ch);
            state.selected = { type: 'chapter', chapterId: ch.id, lessonId: null };
            markDirty(); renderAll();
        }
        function addLesson(cid) {
            var ch = getChapter(cid); if (!ch) return;
            var ls = { id: uid('l'), title: I18N.newLesson || 'درس جدید', label: '', icon: mkMedia(), video: mkMedia(), file: mkMedia(), private: false, duration: '', content: '' };
            ch.expanded = true; ch.lessons.push(ls);
            state.selected = { type: 'lesson', chapterId: cid, lessonId: ls.id };
            markDirty(); renderAll();
        }
        function cloneMedia(m) { return { type: m.type, upload: m.upload, url: m.url, embed: m.embed || '' }; }
        function duplicateChapter(cid) {
            var idx = -1; for (var i = 0; i < state.chapters.length; i++) if (state.chapters[i].id === cid) { idx = i; break; }
            if (idx < 0) return;
            var src = state.chapters[idx];
            var copy = {
                id: uid('c'), title: src.title + (I18N.copySuffix || ' (کپی)'), subtitle: src.subtitle,
                icon: cloneMedia(src.icon), expanded: true,
                lessons: src.lessons.map(function (l) {
                    return { id: uid('l'), title: l.title, label: l.label, icon: cloneMedia(l.icon), video: cloneMedia(l.video), file: cloneMedia(l.file), private: l.private, duration: l.duration || '', content: l.content };
                })
            };
            state.chapters.splice(idx + 1, 0, copy);
            state.selected = { type: 'chapter', chapterId: copy.id, lessonId: null };
            markDirty(); renderAll();
        }
        function duplicateLesson(cid, lid) {
            var ch = getChapter(cid); if (!ch) return;
            var idx = -1; for (var i = 0; i < ch.lessons.length; i++) if (ch.lessons[i].id === lid) { idx = i; break; }
            if (idx < 0) return;
            var src = ch.lessons[idx];
            var copy = { id: uid('l'), title: src.title + (I18N.copySuffix || ' (کپی)'), label: src.label, icon: cloneMedia(src.icon), video: cloneMedia(src.video), file: cloneMedia(src.file), private: src.private, duration: src.duration || '', content: src.content };
            ch.lessons.splice(idx + 1, 0, copy);
            state.selected = { type: 'lesson', chapterId: cid, lessonId: copy.id };
            markDirty(); renderAll();
        }
        function deleteChapter(cid) {
            state.chapters = state.chapters.filter(function (c) { return c.id !== cid; });
            fixSelection(); markDirty(); renderAll();
        }
        function deleteLesson(cid, lid) {
            var ch = getChapter(cid); if (ch) ch.lessons = ch.lessons.filter(function (l) { return l.id !== lid; });
            fixSelection(); markDirty(); renderAll();
        }
        function fixSelection() {
            var s = state.selected, valid = false;
            if (s.type === 'chapter') { valid = !!getChapter(s.chapterId); }
            else { var ch = getChapter(s.chapterId); valid = ch && !!getLesson(ch, s.lessonId); }
            if (valid) return;
            var first = state.chapters[0];
            if (!first) { state.selected = { type: 'chapter', chapterId: null, lessonId: null }; return; }
            state.selected = first.lessons.length
                ? { type: 'lesson', chapterId: first.id, lessonId: first.lessons[0].id }
                : { type: 'chapter', chapterId: first.id, lessonId: null };
        }
        function indexOfId(arr, id) { for (var i = 0; i < arr.length; i++) if (arr[i].id === id) return i; return -1; }
        function reorder(arr, fromId, toId, after) {
            if (fromId === toId) return false;
            var fi = indexOfId(arr, fromId); if (fi < 0) return false;
            var item = arr.splice(fi, 1)[0];
            var ti = indexOfId(arr, toId);
            if (ti < 0) { arr.splice(fi, 0, item); return false; }
            arr.splice(after ? ti + 1 : ti, 0, item);
            return true;
        }
        function moveChapterTo(fromId, toId, after) {
            if (reorder(state.chapters, fromId, toId, after)) { markDirty(); renderList(); }
        }
        function moveLessonTo(cid, fromId, toId, after) {
            var ch = getChapter(cid); if (!ch) return;
            if (reorder(ch.lessons, fromId, toId, after)) { markDirty(); renderList(); }
        }

        /* ---- confirm modal ---- */
        function requestDelete(kind, cid, lid) {
            var name = '', extra = '';
            if (kind === 'chapter') { var ch = getChapter(cid); if (!ch) return; name = ch.title; extra = ch.lessons.length ? 'تمام درس‌های داخل آن نیز حذف می‌شوند.' : ''; }
            else { var c = getChapter(cid); var ls = c && getLesson(c, lid); if (!ls) return; name = ls.title; }
            state.confirm = { kind: kind, cid: cid, lid: lid, name: name, extra: extra };
            renderModal();
        }
        function renderModal() {
            if (!state.confirm) { modalRoot.innerHTML = ''; return; }
            var c = state.confirm;
            var kindLabel = c.kind === 'chapter' ? 'فصل' : 'درس';
            var h = '<div class="nc-overlay" data-modal-cancel><div class="nc-modal" data-modal-stop>';
            h += '<div class="nc-modal-head"><div class="nc-modal-ic">' + IC.trash + '</div><div><div class="nc-modal-title">حذف ' + kindLabel + '</div><div class="nc-modal-sub">این عمل قابل بازگشت نیست.</div></div></div>';
            h += '<div class="nc-modal-body">آیا از حذف <b>«' + esc(c.name || 'بدون عنوان') + '»</b> مطمئن هستید؟ ' + esc(c.extra) + '</div>';
            h += '<div class="nc-modal-actions"><button type="button" class="nc-modal-confirm" data-modal-confirm>بله، حذف کن</button><button type="button" class="nc-modal-cancel" data-modal-cancel>انصراف</button></div>';
            h += '</div></div>';
            modalRoot.innerHTML = h;
        }
        function closeModal() { state.confirm = null; renderModal(); }
        function confirmDelete() {
            var c = state.confirm; if (!c) return;
            if (c.kind === 'chapter') deleteChapter(c.cid); else deleteLesson(c.cid, c.lid);
            state.confirm = null; renderModal();
        }

        /* ---- toast ---- */
        var toastTimer = null;
        function toast(msg, isErr) {
            toastEl.textContent = msg;
            toastEl.className = 'nc-toast show' + (isErr ? ' err' : '');
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toastEl.className = 'nc-toast' + (isErr ? ' err' : ''); }, 2600);
        }

        /* ---- media picker (wp.media) ---- */
        var frames = {};
        function pickMedia(scope, key) {
            var ch = selChapter(); if (!ch) return;
            var target = scope === 'chapter' ? ch : selLesson();
            if (!target || !target[key]) return;
            var isImage = (key === 'icon');
            var frame = wp.media({ title: isImage ? (I18N.pickImage || 'انتخاب تصویر') : (I18N.pickFile || 'انتخاب فایل'), multiple: false });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                target[key] = { type: 'upload', upload: att.url, url: '' };
                markDirty(); renderEditor();
            });
            frame.open();
        }

        /* ---- save ---- */
        function serialize() {
            return state.chapters.map(function (c) {
                return {
                    title: c.title, subtitle: c.subtitle, icon: c.icon,
                    lessons: c.lessons.map(function (l) {
                        return { title: l.title, label: l.label, icon: l.icon, video: l.video, file: l.file, private: l.private, duration: l.duration || '', content: l.content };
                    })
                };
            });
        }
        function setBtnBusy(btn, busy) {
            if (!btn) return;
            if (busy) {
                if (btn.getAttribute('data-orig') === null || btn.getAttribute('data-orig') === undefined) {
                    btn.setAttribute('data-orig', btn.innerHTML);
                }
                btn.classList.add('is-busy');
                btn.innerHTML = '<span class="nc-spinner"></span> ' + (I18N.saving || 'در حال ذخیره…');
            } else {
                btn.classList.remove('is-busy');
                var orig = btn.getAttribute('data-orig');
                if (orig !== null && orig !== undefined) { btn.innerHTML = orig; btn.removeAttribute('data-orig'); }
            }
        }
        function busyButtons() {
            // header save + the currently rendered editor-footer save (if any).
            return [document.getElementById('nc-save-all'), footEl.querySelector('[data-save]')];
        }
        var saving = false;
        function saveAll() {
            if (saving) return;
            flushEditor(); // pull current TinyMCE content into state
            saving = true;
            var btns = busyButtons();
            btns.forEach(function (b) { setBtnBusy(b, true); });
            function done() { saving = false; busyButtons().forEach(function (b) { setBtnBusy(b, false); }); }
            var body = new URLSearchParams();
            body.append('action', 'nias_save_curriculum');
            body.append('product_id', DATA.productId);
            body.append('nonce', DATA.nonce);
            body.append('chapters', JSON.stringify(serialize()));
            var spUrlEl = document.getElementById('nc-spot-url');
            var spLicEl = document.getElementById('nc-spot-license');
            if (spUrlEl) { body.append('spot_download_url', spUrlEl.value); }
            if (spLicEl) { body.append('spot_license_course', spLicEl.value); }
            if (DATA.instructors && DATA.instructors.enabled) {
                var instCbs = document.querySelectorAll('.nc-inst-cb');
                var instIds = [];
                for (var ci = 0; ci < instCbs.length; ci++) { if (instCbs[ci].checked) instIds.push(instCbs[ci].value); }
                body.append('instructors', instIds.join(','));
            }
            var metaInputs = document.querySelectorAll('[data-nias-meta-key]');
            if (metaInputs.length) {
                var metaVals = {};
                for (var mi = 0; mi < metaInputs.length; mi++) {
                    metaVals[metaInputs[mi].getAttribute('data-nias-meta-key')] = metaInputs[mi].value;
                }
                body.append('meta_values', JSON.stringify(metaVals));
            }
            fetch(DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    done();
                    if (res && res.success) { state.dirty = false; toast((res.data && res.data.message) || I18N.saved || 'ذخیره شد'); }
                    else { toast((res && res.data && res.data.message) || I18N.saveError || 'خطا', true); }
                })
                .catch(function () { done(); toast(I18N.saveError || 'خطا', true); });
        }

        /* ---- events: list ---- */
        listEl.addEventListener('click', function (e) {
            var t = e.target, m;
            if ((m = t.closest('[data-copy-lesson]'))) { duplicateLesson(m.getAttribute('data-cid'), m.getAttribute('data-copy-lesson')); return; }
            if ((m = t.closest('[data-del-lesson]'))) { requestDelete('lesson', m.getAttribute('data-cid'), m.getAttribute('data-del-lesson')); return; }
            if ((m = t.closest('[data-copy-chap]'))) { duplicateChapter(m.getAttribute('data-copy-chap')); return; }
            if ((m = t.closest('[data-del-chap]'))) { requestDelete('chapter', m.getAttribute('data-del-chap')); return; }
            if ((m = t.closest('[data-add-lesson]'))) { addLesson(m.getAttribute('data-add-lesson')); return; }
            if ((m = t.closest('[data-add-chapter]'))) { addChapter(); return; }
            if ((m = t.closest('[data-toggle]'))) { var ch = getChapter(m.getAttribute('data-toggle')); if (ch) { ch.expanded = !ch.expanded; renderList(); } return; }
            if ((m = t.closest('[data-select-lesson]'))) {
                var cid = m.getAttribute('data-cid'), lid = m.getAttribute('data-lid');
                var c = getChapter(cid); if (c && !c.expanded) c.expanded = true;
                state.selected = { type: 'lesson', chapterId: cid, lessonId: lid }; renderAll(); return;
            }
            if ((m = t.closest('[data-select-chap]'))) { state.selected = { type: 'chapter', chapterId: m.getAttribute('data-select-chap'), lessonId: null }; renderAll(); return; }
        });

        /* drag & drop (delegated; events bubble). Drop position is decided by
           the pointer relative to the target's vertical midpoint, with a clear
           before/after insertion indicator. */
        var dropTarget = null;
        function clearDropMarks() {
            var nodes = listEl.querySelectorAll('.dropbefore, .dropafter');
            for (var i = 0; i < nodes.length; i++) nodes[i].classList.remove('dropbefore', 'dropafter');
        }
        function dndTargetFor(e) {
            if (dragInfo.type === 'chapter') { return e.target.closest('.nc-chap'); }
            var row = e.target.closest('.nc-lesson');
            return (row && row.getAttribute('data-cid') === dragInfo.cid) ? row : null;
        }
        listEl.addEventListener('dragstart', function (e) {
            var g = e.target.closest('[data-drag]'); if (!g) return;
            var type = g.getAttribute('data-drag');
            if (type === 'chapter') { dragInfo = { type: 'chapter', cid: g.getAttribute('data-cid') }; }
            else { dragInfo = { type: 'lesson', cid: g.getAttribute('data-cid'), lid: g.getAttribute('data-lid') }; }
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ' '); } catch (_) {}
            var card = type === 'chapter' ? g.closest('.nc-chap') : g.closest('.nc-lesson');
            if (card) { setTimeout(function () { card.classList.add('dragging'); }, 0); }
        });
        listEl.addEventListener('dragover', function (e) {
            if (!dragInfo) return;
            var target = dndTargetFor(e);
            if (!target) { dropTarget = null; clearDropMarks(); return; }
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (_) {}
            var rect = target.getBoundingClientRect();
            var after = (e.clientY - rect.top) > rect.height / 2;
            // ignore hovering over the dragged element itself.
            var selfId = dragInfo.type === 'chapter' ? target.getAttribute('data-cid') : target.getAttribute('data-lid');
            if ((dragInfo.type === 'chapter' && selfId === dragInfo.cid) || (dragInfo.type === 'lesson' && selfId === dragInfo.lid)) {
                dropTarget = null; clearDropMarks(); return;
            }
            clearDropMarks();
            target.classList.add(after ? 'dropafter' : 'dropbefore');
            dropTarget = { id: selfId, after: after };
        });
        listEl.addEventListener('drop', function (e) {
            if (dragInfo && dropTarget) {
                e.preventDefault();
                if (dragInfo.type === 'chapter') { moveChapterTo(dragInfo.cid, dropTarget.id, dropTarget.after); }
                else { moveLessonTo(dragInfo.cid, dragInfo.lid, dropTarget.id, dropTarget.after); }
            }
            cleanupDrag();
        });
        listEl.addEventListener('dragend', cleanupDrag);
        function cleanupDrag() {
            dragInfo = null; dropTarget = null; clearDropMarks();
            var dn = listEl.querySelectorAll('.dragging');
            for (var i = 0; i < dn.length; i++) dn[i].classList.remove('dragging');
        }

        /* ---- events: editor ---- */
        editorEl.addEventListener('click', function (e) {
            var t = e.target, m;
            if ((m = t.closest('[data-media-pick]'))) { pickMedia(m.getAttribute('data-scope'), m.getAttribute('data-media-pick')); return; }
            if ((m = t.closest('[data-media-clear]'))) {
                var scope = m.getAttribute('data-scope'), key = m.getAttribute('data-media-clear');
                var tgt = scope === 'chapter' ? selChapter() : selLesson();
                if (tgt) { tgt[key] = mkMedia(); markDirty(); renderEditor(); }
                return;
            }
            if (t.closest('[data-private-toggle]')) { var ls = selLesson(); if (ls) { ls.private = !ls.private; markDirty(); renderEditor(); renderList(); } return; }
            if (t.closest('[data-save]')) { saveAll(); return; }
            if (t.closest('[data-duplicate]')) {
                if (state.selected.type === 'chapter') duplicateChapter(state.selected.chapterId);
                else duplicateLesson(state.selected.chapterId, state.selected.lessonId);
                return;
            }
            if (t.closest('[data-del-current]')) {
                if (state.selected.type === 'chapter') requestDelete('chapter', state.selected.chapterId);
                else requestDelete('lesson', state.selected.chapterId, state.selected.lessonId);
                return;
            }
        });

        editorEl.addEventListener('input', function (e) {
            var t = e.target;
            if (t.id === EDITOR_ID) { markDirty(); return; } // typing in the editor "Text" tab
            var field = t.getAttribute('data-field');
            if (field) {
                var val = t.value;
                if (field === 'chapTitle') { var ch = selChapter(); if (ch) { ch.title = val; updateText('[data-title-c="' + ch.id + '"]', val || 'بدون عنوان'); updateText('[data-head-title]', val || 'بدون عنوان', editorEl); } }
                else if (field === 'chapSub') { var ch2 = selChapter(); if (ch2) ch2.subtitle = val; }
                else if (field === 'lessonTitle') { var ls = selLesson(); if (ls) { ls.title = val; updateText('[data-title-l="' + ls.id + '"]', val || 'بدون عنوان'); updateText('[data-head-title]', val || 'بدون عنوان', editorEl); } }
                else if (field === 'lessonLabel') { var ls2 = selLesson(); if (ls2) ls2.label = val; }
                else if (field === 'lessonDuration') { var ls3 = selLesson(); if (ls3) ls3.duration = val; }
                markDirty();
                return;
            }
            var mkey = t.getAttribute('data-media-url');
            if (mkey) {
                var scope = t.getAttribute('data-scope');
                var tgt = scope === 'chapter' ? selChapter() : selLesson();
                if (tgt && tgt[mkey]) { tgt[mkey] = { type: 'url', upload: '', url: t.value, embed: '' }; markDirty(); }
                return;
            }
            var ekey = t.getAttribute('data-media-embed');
            if (ekey) {
                var escope = t.getAttribute('data-scope');
                var etgt = escope === 'chapter' ? selChapter() : selLesson();
                if (etgt && etgt[ekey]) { etgt[ekey] = { type: 'embed', upload: '', url: '', embed: t.value }; markDirty(); }
                return;
            }
        });

        function updateText(sel, text, ctx) {
            var node = (ctx || listEl).querySelector(sel);
            if (node) node.textContent = text;
        }

        /* ---- modal events ---- */
        modalRoot.addEventListener('click', function (e) {
            if (e.target.closest('[data-modal-confirm]')) { confirmDelete(); return; }
            if (e.target.closest('[data-modal-stop]') && !e.target.closest('[data-modal-cancel]')) { return; }
            if (e.target.closest('[data-modal-cancel]') || e.target.classList.contains('nc-overlay')) { closeModal(); return; }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.confirm) closeModal(); });

        /* ---- header events ---- */
        document.getElementById('nc-add-chapter-top').addEventListener('click', addChapter);
        document.getElementById('nc-save-all').addEventListener('click', saveAll);
        var searchInput = document.getElementById('nc-search-input');
        searchInput.addEventListener('input', function () { state.search = this.value; renderList(); });

        /* ---- leave guard ---- */
        window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = I18N.leaveWarn || ''; return e.returnValue; } });
        document.getElementById('nc-back').addEventListener('click', function (e) {
            if (state.dirty && !window.confirm((I18N.leaveWarn || '') + ' ادامه می‌دهید؟')) { e.preventDefault(); }
        });

        /* ---- ctrl/cmd+s ---- */
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); saveAll(); }
        });

        /* =====================================================================
         * SpotPlayer tools drawer
         * ================================================================= */
        (function () {
            var SPOT = DATA.spot || {};
            var drawer = document.getElementById('nc-spot-drawer');
            if (!drawer) { return; }
            var openBtn = document.getElementById('nc-spot-open');

            function openDrawer() { drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); }
            function closeDrawer() { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); }
            if (openBtn) { openBtn.addEventListener('click', openDrawer); }
            drawer.addEventListener('click', function (e) { if (e.target.closest('[data-spot-close]')) { closeDrawer(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open')) { closeDrawer(); } });

            /* ---- build editor chapters from an arbitrary imported list ---- */
            function normTitle(o, keys) {
                for (var i = 0; i < keys.length; i++) { if (o && o[keys[i]] != null && String(o[keys[i]]).trim() !== '') { return String(o[keys[i]]).trim(); } }
                return '';
            }
            function toChapters(raw) {
                // Accept: top-level array, {sections:[…]}, {chapters:[…]}, {data:[…]}.
                var list = raw;
                if (!Array.isArray(list)) {
                    list = (raw && (raw.sections || raw.chapters || raw.data)) || [];
                }
                if (!Array.isArray(list)) { return []; }
                var out = [];
                list.forEach(function (ch) {
                    if (ch == null) { return; }
                    var title = normTitle(ch, ['title', 'section_title', 'name', 'chapter']);
                    var rawLessons = ch.lessons || ch.items || ch.videos || ch.sessions || [];
                    if (!Array.isArray(rawLessons)) { rawLessons = []; }
                    // a bare array of strings = lessons of an untitled chapter
                    var lessons = rawLessons.map(function (ls) {
                        var lt = (typeof ls === 'string') ? ls : normTitle(ls, ['title', 'lesson_title', 'name', 'session']);
                        var ld = (ls && typeof ls === 'object') ? (ls.duration || ls.time || ls.length || ls.lesson_duration || '') : '';
                        return {
                            id: uid('l'), title: lt, label: '',
                            icon: mkMedia(), video: mkMedia(), file: mkMedia(),
                            private: true, duration: String(ld || ''), content: ''
                        };
                    }).filter(function (l) { return l.title !== ''; });
                    if (title === '' && lessons.length === 0) { return; }
                    out.push({ id: uid('c'), title: title || 'بدون عنوان', subtitle: '', icon: mkMedia(), expanded: false, lessons: lessons });
                });
                return out;
            }
            function applyChapters(chapters, label) {
                if (!chapters.length) { toast('موردی برای وارد کردن یافت نشد.', true); return; }
                state.chapters = chapters;
                if (state.chapters.length) {
                    state.chapters[0].expanded = true;
                    var c0 = state.chapters[0];
                    state.selected = c0.lessons.length
                        ? { type: 'lesson', chapterId: c0.id, lessonId: c0.lessons[0].id }
                        : { type: 'chapter', chapterId: c0.id, lessonId: null };
                } else {
                    state.selected = { type: 'chapter', chapterId: null, lessonId: null };
                }
                markDirty();
                renderAll();
                closeDrawer();
                var totL = 0; state.chapters.forEach(function (c) { totL += c.lessons.length; });
                toast((label || 'وارد شد') + '：' + faNum(state.chapters.length) + ' فصل · ' + faNum(totL) + ' درس. برای ذخیره، «ذخیره همه تغییرات» را بزنید.');
            }
            function confirmReplace() {
                var hasContent = state.chapters.length > 0;
                return !hasContent || window.confirm('محتوای فعلی فصل‌ها و جلسات با لیست جدید جایگزین می‌شود. ادامه می‌دهید؟');
            }

            /* ---- A: sync from URL ---- */
            var syncBtn = document.getElementById('nc-spot-sync');
            if (syncBtn) {
                syncBtn.addEventListener('click', function () {
                    var urlEl = document.getElementById('nc-spot-url');
                    var url = urlEl ? urlEl.value.trim() : '';
                    if (!url) { toast('ابتدا لینک اسپات پلیر را وارد کنید.', true); return; }
                    if (!confirmReplace()) { return; }
                    setBtnBusy(syncBtn, true);
                    var body = new URLSearchParams();
                    body.append('action', 'nias_spotplayer_sync');
                    body.append('post_id', DATA.productId);
                    body.append('url', url);
                    fetch(DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            setBtnBusy(syncBtn, false);
                            if (res && res.success) {
                                // The endpoint already wrote course_sections; reload to show it.
                                state.dirty = false;
                                toast((res.data && res.data.message) || 'همگام‌سازی انجام شد');
                                setTimeout(function () { window.location.reload(); }, 700);
                            } else {
                                toast((res && res.data && res.data.message) || 'خطا در همگام‌سازی', true);
                            }
                        })
                        .catch(function () { setBtnBusy(syncBtn, false); toast('خطا در ارتباط با سرور', true); });
                });
            }

            /* ---- C: import JSON file ---- */
            var fileInput = document.getElementById('nc-spot-file');
            var fileName = document.getElementById('nc-spot-file-name');
            var importBtn = document.getElementById('nc-spot-import');
            var pickedFile = null;
            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    pickedFile = this.files && this.files[0] ? this.files[0] : null;
                    if (fileName) { fileName.textContent = pickedFile ? pickedFile.name : 'انتخاب فایل JSON…'; }
                    if (importBtn) { importBtn.disabled = !pickedFile; }
                });
            }
            if (importBtn) {
                importBtn.addEventListener('click', function () {
                    if (!pickedFile) { return; }
                    if (!confirmReplace()) { return; }
                    var reader = new FileReader();
                    reader.onload = function () {
                        var data;
                        try { data = JSON.parse(reader.result); }
                        catch (err) { toast('فایل JSON معتبر نیست.', true); return; }
                        applyChapters(toChapters(data), 'وارد شد از فایل');
                    };
                    reader.onerror = function () { toast('خطا در خواندن فایل.', true); };
                    reader.readAsText(pickedFile);
                });
            }
        })();

        /* =====================================================================
         * Instructors drawer
         * ================================================================= */
        (function () {
            if (!DATA.instructors || !DATA.instructors.enabled) { return; }
            var drawer = document.getElementById('nc-inst-drawer');
            var openBtn = document.getElementById('nc-inst-open');
            var countEl = document.getElementById('nc-inst-count');

            function updateCount() {
                var cbs = document.querySelectorAll('.nc-inst-cb');
                var n = 0;
                for (var i = 0; i < cbs.length; i++) { if (cbs[i].checked) n++; }
                if (countEl) {
                    if (n > 0) { countEl.textContent = faNum(n); countEl.classList.add('has'); }
                    else { countEl.textContent = ''; countEl.classList.remove('has'); }
                }
            }
            function openDrawer() { if (drawer) { drawer.classList.add('open'); drawer.setAttribute('aria-hidden', 'false'); } }
            function closeDrawer() { if (drawer) { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); } }

            if (openBtn) { openBtn.addEventListener('click', openDrawer); }
            if (drawer) {
                drawer.addEventListener('click', function (e) { if (e.target.closest('[data-inst-close]')) { closeDrawer(); } });
                drawer.addEventListener('change', function (e) {
                    if (e.target && e.target.classList.contains('nc-inst-cb')) { updateCount(); markDirty(); }
                });
            }
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer && drawer.classList.contains('open')) { closeDrawer(); } });

            updateCount();
        })();

        renderAll();
    })();
    </script>
    <?php
}
