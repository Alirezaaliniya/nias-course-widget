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

/*
 * NOTE: The "تنظیمات اسپات پلیر" metabox used to live on the product edit
 * screen. It has been moved into the curriculum editor page (the "ابزار اسپات
 * پلیر" drawer in woocommerce-course/curriculum-editor.php), where the download
 * URL, session sync, Chrome-extension links, JSON import and the license
 * course IDs are now managed and saved together with the chapters via AJAX.
 */

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
 * Save (legacy inline curriculum inputs only)
 *
 * The curriculum, download URL and license fields are saved from the dedicated
 * editor page via AJAX. This guard only persists legacy inline section inputs
 * if they were actually submitted, so a normal product save never wipes data.
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

    if (isset($_POST['nias_course']['sections'])) {
        $sections_input = (array) wp_unslash($_POST['nias_course']['sections']);
        $sections = nias_course_sanitize_sections($sections_input);
        carbon_set_post_meta($post_id, 'course_sections', $sections);
    }
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

/*
 * The Spot Player session-sync script used to run on the product edit screen
 * (targeting the old metabox). It now lives in the curriculum editor's
 * "ابزار اسپات پلیر" drawer (woocommerce-course/curriculum-editor.php).
 */
