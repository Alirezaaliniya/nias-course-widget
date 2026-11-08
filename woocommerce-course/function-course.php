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
        // The video group has an extra 4th key holding raw embed code (iframe),
        // so a preview can be a file, a link, OR a pasted embed snippet.
        'video' => array('video_type', 'video_upload', 'video_url', 'video_embed'),
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
    $clean = nias_course_kses_limited($raw);
    // Content sent back unchanged (e.g. an admin's embed script in a lesson the
    // instructor did not edit — the editor may only have re-wrapped it) keeps
    // its stored form. See nias_course_trust_existing().
    if (!empty($GLOBALS['nias_cs_trusted'])) {
        $k = nias_course_content_key($clean);
        if (isset($GLOBALS['nias_cs_trusted'][$k])) {
            return $GLOBALS['nias_cs_trusted'][$k];
        }
    }
    return $clean;
}

/** Post HTML + iframe embeds (Aparat / YouTube…), never scripts — for users without unfiltered_html. */
function nias_course_kses_limited($raw)
{
    $allowed = wp_kses_allowed_html('post');
    $allowed['iframe'] = array('src' => true, 'width' => true, 'height' => true, 'frameborder' => true, 'allow' => true, 'allowfullscreen' => true,
        'webkitallowfullscreen' => true, 'mozallowfullscreen' => true, 'title' => true, 'loading' => true, 'referrerpolicy' => true, 'style' => true, 'class' => true, 'id' => true, 'name' => true, 'scrolling' => true);
    return wp_kses((string) $raw, $allowed);
}

/** Comparison key that ignores editor re-wrapping (empty paragraphs, spacing between tags, attribute quoting). */
function nias_course_content_key($html)
{
    $h = preg_replace('~<p>(\s|&nbsp;|<br\s*/?>)*</p>~i', '', (string) $html);
    $h = preg_replace('~>\s+<~', '><', $h);
    $h = preg_replace('~\s+~u', ' ', $h);
    $h = str_replace("'", '"', $h);
    return md5(trim($h));
}

/**
 * Before saving a curriculum as a user without unfiltered_html: remember the
 * stored lesson content / embed values, so values sent back unchanged are not
 * re-filtered (that would strip an admin's embed code from every lesson).
 */
function nias_course_trust_existing($post_id)
{
    $GLOBALS['nias_cs_trusted'] = array();
    foreach ((array) carbon_get_post_meta($post_id, 'course_sections') as $s) {
        foreach ((array) (isset($s['lessons']) ? $s['lessons'] : array()) as $l) {
            if (!empty($l['lesson_content'])) {
                $GLOBALS['nias_cs_trusted'][nias_course_content_key(nias_course_kses_limited($l['lesson_content']))] = (string) $l['lesson_content'];
            }
            foreach ((array) (isset($l['lesson_preview_video']) ? $l['lesson_preview_video'] : array()) as $g) {
                if (!empty($g['video_embed'])) {
                    $GLOBALS['nias_cs_trusted'][nias_course_content_key(nias_course_kses_limited($g['video_embed']))] = (string) $g['video_embed'];
                }
            }
        }
    }
}

function nias_course_sanitize_media_group($group, $type)
{
    $keys = nias_media_group_keys($type);
    $group = is_array($group) ? $group : array();

    $tval  = isset($group[$keys[0]]) ? sanitize_text_field($group[$keys[0]]) : 'url';
    $upval = isset($group[$keys[1]]) ? esc_url_raw($group[$keys[1]]) : '';
    $urlval = isset($group[$keys[2]]) ? esc_url_raw($group[$keys[2]]) : '';

    // Optional 4th key: raw embed code (iframe/style/script). Kept verbatim for
    // trusted users, wp_kses_post() for everyone else — same policy as lesson
    // content, so pasted Aparat/YouTube embeds survive.
    $has_embed = isset($keys[3]);
    $embedval  = ($has_embed && isset($group[$keys[3]])) ? nias_course_sanitize_lesson_content($group[$keys[3]]) : '';

    if ($upval === '' && $urlval === '' && $embedval === '') {
        return array();
    }

    $out = array(
        $keys[0] => $tval,
        $keys[1] => $upval,
        $keys[2] => $urlval,
    );
    if ($has_embed) {
        $out[$keys[3]] = $embedval;
    }
    return array($out);
}

/** Lesson gate value: '' (open), 'prev', or a quiz id. */
function nias_course_sanitize_gate($v)
{
    $v = is_scalar($v) ? trim((string) $v) : '';
    if ($v === 'prev') {
        return 'prev';
    }
    return ctype_digit($v) && (int) $v > 0 ? (string) (int) $v : '';
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
                    'lesson_duration'      => sanitize_text_field($lesson['lesson_duration'] ?? ''),
                    'lesson_content'       => nias_course_sanitize_lesson_content($lesson['lesson_content'] ?? ''),
                    // Quiz shown in this lesson, and the quiz score that unlocks it
                    // ('' = open, 'prev' = every quiz of the earlier lessons, or a quiz id).
                    'lesson_quiz'          => absint($lesson['lesson_quiz'] ?? 0) ?: '',
                    'lesson_gate'          => nias_course_sanitize_gate($lesson['lesson_gate'] ?? ''),
                    'lesson_gate_min'      => (isset($lesson['lesson_gate_min']) && $lesson['lesson_gate_min'] !== '') ? (string) max(0, min(100, (int) $lesson['lesson_gate_min'])) : '',
                );
                // «قطره‌ای»: opens on day N after enrolment (1 = that day) at HH:MM.
                $drip = function_exists('nias_drip_clean')
                    ? nias_drip_clean(isset($lesson['lesson_drip']) && $lesson['lesson_drip'] === 'yes', $lesson['lesson_drip_day'] ?? 1, $lesson['lesson_drip_time'] ?? '')
                    : array('on' => false, 'day' => 1, 'time' => '');
                $last = count($sec['lessons']) - 1;
                // «کلاس زنده» (live session).
                $live = isset($lesson['lesson_live']) && $lesson['lesson_live'] === 'yes';
                $sec['lessons'][$last]['lesson_live']       = $live ? 'yes' : '';
                $sec['lessons'][$last]['lesson_live_url']   = $live ? esc_url_raw((string) ($lesson['lesson_live_url'] ?? '')) : '';
                $sec['lessons'][$last]['lesson_live_start'] = $live && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', (string) ($lesson['lesson_live_start'] ?? '')) ? str_replace(' ', 'T', $lesson['lesson_live_start']) : '';
                $sec['lessons'][$last]['lesson_live_dur']   = $live && ($lesson['lesson_live_dur'] ?? '') !== '' ? (string) max(10, min(600, (int) $lesson['lesson_live_dur'])) : '';
                // «تکلیف» (assignment).
                $sec['lessons'][$last]['lesson_assign']       = (isset($lesson['lesson_assign']) && $lesson['lesson_assign'] === 'yes') ? 'yes' : '';
                $sec['lessons'][$last]['lesson_assign_text']  = $sec['lessons'][$last]['lesson_assign'] ? mb_substr(sanitize_textarea_field($lesson['lesson_assign_text'] ?? ''), 0, 3000) : '';
                $sec['lessons'][$last]['lesson_assign_types'] = $sec['lessons'][$last]['lesson_assign'] ? preg_replace('/[^a-z0-9,]/', '', strtolower((string) ($lesson['lesson_assign_types'] ?? ''))) : '';
                $sec['lessons'][$last]['lesson_assign_max']   = $sec['lessons'][$last]['lesson_assign'] && ($lesson['lesson_assign_max'] ?? '') !== '' ? (string) max(1, (int) $lesson['lesson_assign_max']) : '';
                $sec['lessons'][$last]['lesson_assign_pass']  = $sec['lessons'][$last]['lesson_assign'] && ($lesson['lesson_assign_pass'] ?? '') !== '' ? (string) max(0, min(100, (int) $lesson['lesson_assign_pass'])) : '';
                // Other qualities of the lesson video ("label|url" lines).
                $sec['lessons'][$last]['lesson_qualities']    = function_exists('nias_lesson_qualities_join') ? nias_lesson_qualities_join(nias_lesson_qualities_parse($lesson['lesson_qualities'] ?? '')) : '';
                $sec['lessons'][$last]['lesson_quality_main'] = mb_substr(sanitize_text_field($lesson['lesson_quality_main'] ?? ''), 0, 24);
                $sec['lessons'][$last]['lesson_drip']      = $drip['on'] ? 'yes' : '';
                $sec['lessons'][$last]['lesson_drip_day']  = $drip['on'] ? (string) $drip['day'] : '';
                $sec['lessons'][$last]['lesson_drip_time'] = $drip['on'] ? $drip['time'] : '';
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
