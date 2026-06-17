<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product course meta handling (Carbon Fields replacement).
 *
 * The chapter/lesson curriculum is edited on a dedicated full-page editor
 * (see woocommerce-course/curriculum-editor.php) instead of an inline metabox.
 * This file keeps the Spot Player metabox, the shared sanitizers used by the
 * curriculum editor's AJAX save, and the save_post guard.
 */

/* -------------------------------------------------------------------------
 * Metabox registration (Spot Player only)
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes', 'nias_course_register_metaboxes');
function nias_course_register_metaboxes()
{
    add_meta_box(
        'nias_spotplayer_box',
        __('تنظیمات اسپات پلیر', 'nias-course-widget'),
        'nias_spotplayer_metabox',
        'product',
        'normal',
        'high'
    );
}

/* -------------------------------------------------------------------------
 * Media group helper (icon / video / file with upload+url, value type url)
 * ---------------------------------------------------------------------- */

function nias_media_group_keys($type)
{
    $map = array(
        'icon'  => array('icon_type', 'icon_upload', 'icon_url'),
        'video' => array('video_type', 'video_upload', 'video_url'),
        'file'  => array('file_type', 'file_upload', 'file_url'),
    );
    return $map[$type];
}

/* -------------------------------------------------------------------------
 * Metabox callbacks
 * ---------------------------------------------------------------------- */

function nias_spotplayer_metabox($post)
{
    // This nonce now also authorises the save_post handler below.
    wp_nonce_field('nias_course_meta', 'nias_course_meta_nonce');

    $url = get_post_meta($post->ID, '_spotplayer_download_url', true);
    ?>
    <p>
        <label class="nias-field-label" for="_spotplayer_download_url"><?php echo esc_html__('لینک صفحه دانلود ویدیو اسپات پلیر', 'nias-course-widget'); ?></label>
        <input type="text" class="widefat" id="_spotplayer_download_url" name="_spotplayer_download_url" value="<?php echo esc_attr($url); ?>">
        <span class="description"><?php echo esc_html__('لینک صفحه دانلود ویدیو را از اسپات پلیر وارد کنید', 'nias-course-widget'); ?></span>
    </p>
    <p>
        <button type="button" class="button button-primary" id="sync-spotplayer"><?php echo esc_html__('همگام سازی جلسات با اسپات پلیر', 'nias-course-widget'); ?></button>
    </p>
    <div id="sync-status"></div>
    <?php
}

/* -------------------------------------------------------------------------
 * Save
 * ---------------------------------------------------------------------- */

add_action('save_post_product', 'nias_course_save_metaboxes', 10, 2);
function nias_course_save_metaboxes($post_id, $post)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['nias_course_meta_nonce']) || !wp_verify_nonce($_POST['nias_course_meta_nonce'], 'nias_course_meta')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // The curriculum is edited on the dedicated page (saved via AJAX). Only
    // persist sections here if legacy inline inputs were actually submitted,
    // so a normal product save never wipes the stored curriculum.
    if (isset($_POST['nias_course']['sections'])) {
        $sections_input = (array) wp_unslash($_POST['nias_course']['sections']);
        $sections = nias_course_sanitize_sections($sections_input);
        carbon_set_post_meta($post_id, 'course_sections', $sections);
    }

    $spot = isset($_POST['_spotplayer_download_url']) ? esc_url_raw(wp_unslash($_POST['_spotplayer_download_url'])) : '';
    update_post_meta($post_id, '_spotplayer_download_url', $spot);
}

/* -------------------------------------------------------------------------
 * Sanitizers (shared with the curriculum editor AJAX save)
 * ---------------------------------------------------------------------- */

/**
 * Sanitize lesson rich content.
 *
 * Course lessons routinely contain embed code (Aparat/YouTube iframes together
 * with their <style> block). Trusted users — those allowed to post unfiltered
 * HTML, i.e. administrators/shop managers on most installs — keep it verbatim,
 * exactly as WordPress stores post_content. Everyone else falls back to
 * wp_kses_post(). This prevents <style>/<iframe> from being stripped while their
 * inner text leaks onto the front-end.
 *
 * @param string $raw
 * @return string
 */
function nias_course_sanitize_lesson_content($raw)
{
    $raw = (string) $raw;
    if (current_user_can('unfiltered_html')) {
        return $raw;
    }
    return wp_kses_post($raw);
}

function nias_course_sanitize_media_group($group, $type)
{
    $keys = nias_media_group_keys($type);
    $group = is_array($group) ? $group : array();

    $tval  = isset($group[$keys[0]]) ? sanitize_text_field($group[$keys[0]]) : 'url';
    $upval = isset($group[$keys[1]]) ? esc_url_raw($group[$keys[1]]) : '';
    $urlval = isset($group[$keys[2]]) ? esc_url_raw($group[$keys[2]]) : '';

    if ($upval === '' && $urlval === '') {
        return array();
    }

    return array(array(
        $keys[0] => $tval,
        $keys[1] => $upval,
        $keys[2] => $urlval,
    ));
}

function nias_course_sanitize_sections($raw)
{
    $out = array();
    foreach ($raw as $section) {
        if (!is_array($section)) {
            continue;
        }

        $sec = array(
            'section_title'    => sanitize_text_field($section['section_title'] ?? ''),
            'section_subtitle' => sanitize_text_field($section['section_subtitle'] ?? ''),
            'section_icon'     => nias_course_sanitize_media_group($section['section_icon'] ?? array(), 'icon'),
            'lessons'          => array(),
        );

        if (!empty($section['lessons']) && is_array($section['lessons'])) {
            foreach ($section['lessons'] as $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $sec['lessons'][] = array(
                    'lesson_title'         => sanitize_text_field($lesson['lesson_title'] ?? ''),
                    'lesson_icon'          => nias_course_sanitize_media_group($lesson['lesson_icon'] ?? array(), 'icon'),
                    'lesson_label'         => sanitize_text_field($lesson['lesson_label'] ?? ''),
                    'lesson_preview_video' => nias_course_sanitize_media_group($lesson['lesson_preview_video'] ?? array(), 'video'),
                    'lesson_download'      => nias_course_sanitize_media_group($lesson['lesson_download'] ?? array(), 'file'),
                    'lesson_private'       => (isset($lesson['lesson_private']) && $lesson['lesson_private'] === 'yes'),
                    'lesson_content'       => nias_course_sanitize_lesson_content($lesson['lesson_content'] ?? ''),
                );
            }
        }

        // Skip completely empty sections.
        if ($sec['section_title'] === '' && empty($sec['lessons']) && empty($sec['section_icon'])) {
            continue;
        }

        $out[] = $sec;
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Admin assets + Spot Player sync script (product edit screen)
 * ---------------------------------------------------------------------- */

add_action('admin_footer', 'nias_course_builder_script');
function nias_course_builder_script()
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'product') {
        return;
    }
    ?>
    <script>
    jQuery(function ($) {
        // Spot Player sync.
        $('#sync-spotplayer').on('click', function () {
            var button = $(this);
            var statusDiv = $('#sync-status');
            var downloadUrl = $('#_spotplayer_download_url').val();

            if (!downloadUrl) {
                statusDiv.html('<p style="color: red;">لطفا ابتدا لینک دانلود را وارد کنید</p>');
                return;
            }

            button.prop('disabled', true);
            statusDiv.html('<p>در حال همگام سازی...</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'nias_spotplayer_sync',
                    post_id: $('#post_ID').val(),
                    url: downloadUrl
                },
                success: function (response) {
                    if (response.success) {
                        statusDiv.html('<p style="color: green;">' + response.data.message + '</p>');
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : response.data;
                        statusDiv.html('<p style="color: red;">خطا: ' + msg + '</p>');
                        button.prop('disabled', false);
                    }
                },
                error: function () {
                    statusDiv.html('<p style="color: red;">خطا در ارتباط با سرور</p>');
                    button.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
}
