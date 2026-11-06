<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modern course view (نمایش دوره مدرن).
 *
 * A redesigned front-end presentation of a product's curriculum, recreated from
 * the Claude Design handoff ("نمایش دوره"). It renders, from the very same
 * `course_sections` post meta edited in the curriculum editor:
 *   - a header with the course title/instructor, an overall-progress ring and a
 *     certificate shortcut,
 *   - a player that switches by lesson type (video file / online embed / text /
 *     downloadable file / locked private lesson),
 *   - a lesson info bar with prev / mark-complete / next and description /
 *     resources tabs,
 *   - a sticky curriculum sidebar with per-chapter progress,
 *   - a certificate section (only when the certificate feature applies).
 *
 * Per-lesson progress is tracked client-side in localStorage (per product), the
 * same way the prototype does, so no extra server storage is needed.
 *
 * Activation is controlled from the main settings ("فعالسازی نمایش دوره مدرن"):
 *   - off       : feature disabled,
 *   - auto      : printed automatically on the single-product page,
 *   - shortcode : printed manually via the [nias_modern_course] shortcode.
 *
 * All sections honor the surrounding plugin settings: instructors (مدرسین),
 * private lessons + purchase verification, and the certificate (مدرک دوره).
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Mode helper
 * ---------------------------------------------------------------------- */

/**
 * Current activation mode for the modern course view.
 *
 * @return string off|auto|shortcode
 */
function nias_modern_course_mode()
{
    $mode = carbon_get_theme_option('nias_modern_course');
    return in_array($mode, array('auto', 'shortcode'), true) ? $mode : 'off';
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Resolve a curriculum media group ({type,upload,url}) to a single URL,
 * preferring an uploaded file over a manual URL.
 *
 * @param array $media
 * @return string
 */
function nias_modern_course_media_url($media)
{
    if (!is_array($media)) {
        return '';
    }
    if (!empty($media['upload'])) {
        return $media['upload'];
    }
    return !empty($media['url']) ? $media['url'] : '';
}

/** Is this URL a directly-playable video file or HLS stream (vs. an embeddable page)? */
function nias_modern_course_is_video_file($url)
{
    return (bool) preg_match('/\.(mp4|m4v|webm|ogg|ogv|mov|m3u8)(\?.*)?$/i', (string) $url);
}

/**
 * Whether rendered lesson content shows anything: text, embedded media, or
 * one of our own player placeholders (which are empty <div>s until the
 * browser builds the player — the old check missed those, so a lesson whose
 * video lived in its content was treated as "no content").
 */
function nias_modern_course_has_content($html)
{
    $html = (string) $html;
    return trim(wp_strip_all_tags($html)) !== ''
        || (bool) preg_match('/<(iframe|img|video|audio|embed|source|figure)\b|class="(nias-vp-wrap|nias-ap)"/i', $html);
}

/**
 * The first video/audio player in rendered lesson content, and the content
 * without it. Used when a lesson has no dedicated video or file: its media
 * then plays in the main player (with progress tracking and resume) and the
 * rest of the content stays in the description tab — the same layout as a
 * lesson whose video was set in the video field, for mp4 and HLS alike.
 *
 * @param string $html rendered content (after nias_audio_upgrade_html)
 * @return array{src:string,kind:string,rest:string}|null
 */
function nias_modern_course_extract_media($html)
{
    if (!preg_match('~<div class="(nias-vp-wrap|nias-ap)" data-src="([^"]+)"[^>]*>\s*</div>~i', (string) $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $rest = substr_replace($html, '', $m[0][1], strlen($m[0][0]));
    $rest = preg_replace('~<p>\s*(?:&nbsp;|<br\s*/?>|\s)*</p>~i', '', $rest); // paragraph left empty by the removal
    return array(
        'src'  => html_entity_decode($m[2][0], ENT_QUOTES),
        'kind' => strtolower($m[1][0]) === 'nias-ap' ? 'audio' : 'file',
        'rest' => trim($rest),
    );
}

/** Is this URL a directly-playable audio file? */
function nias_modern_course_is_audio_file($url)
{
    return (bool) preg_match('/\.(mp3|wav|m4a|aac|oga|flac|opus|weba)(\?.*)?$/i', (string) $url);
}

/** Human file name from a URL (used as the resource/download label). */
function nias_modern_course_file_name($url)
{
    $path = parse_url((string) $url, PHP_URL_PATH);
    $base = $path ? basename($path) : '';
    $base = urldecode($base);
    return $base !== '' ? $base : __('دانلود فایل', 'nias-course-widget');
}

/**
 * Render stored lesson content the same way WordPress renders editor content,
 * so embedded media is "called" correctly: WP media shortcodes ([audio],
 * [video]), [embed]/oEmbed URLs, pasted Aparat/YouTube iframes (+ their <style>)
 * and auto-paragraphing all work — mirroring the the_content filter chain
 * (run_shortcode → autoembed → wpautop → shortcode_unautop → do_shortcode)
 * without invoking the global the_content filter (avoids third-party re-entrancy).
 *
 * @param string $content
 * @return string
 */
function nias_modern_course_render_content($content)
{
    $content = (string) $content;
    if (trim($content) === '') {
        return '';
    }

    // The modern view prints the player assets itself: mark the inline ones
    // as printed BEFORE any shortcode/embed runs — [video]/[audio]/oEmbed
    // overrides would otherwise echo <script>/<style> while the course data
    // is being built (in the middle of the page, or into AJAX/REST output).
    if (function_exists('_nias_suppress_inline_player_assets')) {
        _nias_suppress_inline_player_assets();
    }

    global $wp_embed;
    if (!empty($wp_embed) && is_object($wp_embed)) {
        $content = $wp_embed->run_shortcode($content); // [embed] … [/embed]
        $content = $wp_embed->autoembed($content);     // bare embeddable URLs
    }
    $content = wpautop($content);
    if (function_exists('shortcode_unautop')) {
        $content = shortcode_unautop($content);
    }
    $content = do_shortcode($content); // [audio], [video] → our overrides run here

    // Aparat's embed snippet is "<style>…</style><div class=h_iframe-aparat_embed_frame>".
    // Editors often strip the <style> tags and leave the rules as visible
    // text (which also overflows on phones). Drop those orphaned rules; the
    // view styles the frame itself.
    $content = preg_replace('~(?<![\w-])\.h_iframe-aparat_embed_frame[^{<]*\{[^}<]*\}~', '', $content);

    // Catch anything that slipped through (MediaElement fallback, bare URLs
    // that were auto-embedded as links, etc.)
    // Suppress the inline asset printing — modern-course loads them globally.
    if (function_exists('nias_audio_upgrade_html')) {
        // Mark assets as already-printed so nias_audio/video_player_assets()
        // don't inject <style>/<script> blocks into the JSON-serialised string.
        _nias_suppress_inline_player_assets();
        $content = nias_audio_upgrade_html($content);
    }

    return $content;
}

/**
 * Pre-mark the inline player asset flags as "already printed" so that
 * nias_audio_player_assets() / nias_video_player_assets() become no-ops.
 * Call this before nias_audio_upgrade_html() runs inside a context where
 * assets are loaded by other means (e.g. modern-course bundles them globally).
 */
function _nias_suppress_inline_player_assets()
{
    // The two asset functions use static variables; the only portable way to
    // flip them from outside is to call the functions once while output
    // buffering suppresses the actual output.
    static $done = false;
    if ($done) { return; }
    $done = true;
    // While suppressing, the player's own script printer must not mark itself
    // as printed (the modern view prints it for real later).
    $GLOBALS['nias_player_suppress'] = true;
    ob_start();
    if (function_exists('nias_audio_player_assets')) { nias_audio_player_assets(); }
    if (function_exists('nias_video_player_assets'))  { nias_video_player_assets(); }
    ob_end_clean(); // discard the CSS/JS — modern-course loads it globally
    $GLOBALS['nias_player_suppress'] = false;
}

/**
 * Whether the certificate feature applies to this product, given the
 * certificate settings (display type + selected products/categories).
 *
 * @param int $product_id
 * @return bool
 */
function nias_modern_course_cert_applies($product_id)
{
    if (carbon_get_theme_option('nias_course_certificate') !== 'on') {
        return false;
    }
    $type = carbon_get_theme_option('certificate_display_type');

    if ($type === 'all') {
        return true;
    }
    if ($type === 'selected') {
        $selected = (array) carbon_get_theme_option('certificate_selected_products');
        return in_array((string) $product_id, array_map('strval', $selected), true);
    }
    if ($type === 'category') {
        $cats  = array_map('strval', (array) carbon_get_theme_option('certificate_selected_categories'));
        $tax   = (defined('NIAS_COURSE_CPT') && get_post_type($product_id) === NIAS_COURSE_CPT) ? NIAS_COURSE_TAX : 'product_cat';
        $terms = taxonomy_exists($tax) ? wp_get_post_terms($product_id, $tax, array('fields' => 'ids')) : array();
        if (is_wp_error($terms)) {
            return false;
        }
        foreach ((array) $terms as $term_id) {
            if (in_array((string) $term_id, $cats, true)) {
                return true;
            }
        }
        return false;
    }
    return false; // 'none' or unset
}

/* -------------------------------------------------------------------------
 * Data builder
 * ---------------------------------------------------------------------- */

/**
 * Build the data model handed to the front-end component.
 *
 * @param int $product_id
 * @return array|null  null when the product has no curriculum.
 */
function nias_modern_course_build_data($product_id)
{
    $product_id = intval($product_id);
    $product    = $product_id ? get_post($product_id) : null;
    if (!$product || !nias_is_course_type($product->post_type)) {
        return null;
    }
    $free = function_exists('nias_course_is_free') && nias_course_is_free($product_id);

    $sections = carbon_get_post_meta($product_id, 'course_sections');
    $sections = is_array($sections) ? $sections : array();
    // Reuse the curriculum editor's normalizer (flat JS shape).
    $chapters_src = function_exists('nias_curriculum_sections_to_js')
        ? nias_curriculum_sections_to_js($sections)
        : array();

    if (empty($chapters_src)) {
        return null;
    }

    // Purchase / access check (gates private lessons only).
    //
    // nias_has_bought_course() lives in inc/functions.php, which the plugin only
    // loads lazily inside the Elementor widget render — so we must make sure it
    // is available here too, otherwise the check is skipped and every private
    // lesson stays locked even after purchase.
    if (!function_exists('nias_has_bought_course') && defined('NIAS_FUNCTIONS') && file_exists(NIAS_FUNCTIONS)) {
        include_once NIAS_FUNCTIONS;
    }
    $user      = wp_get_current_user();
    $purchased = false;
    if (is_user_logged_in() && $user && $user->ID && function_exists('nias_has_bought_course')) {
        $purchased = (bool) nias_has_bought_course($user->ID, $user->user_login, $product_id);
    }

    // Instructor names (when the feature is enabled).
    $instructor = '';
    if (function_exists('nias_instructors_enabled') && nias_instructors_enabled()) {
        $names = array();
        foreach (nias_product_instructors($product_id) as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $names[] = $u->display_name;
            }
        }
        $instructor = implode('، ', $names);
    }

    // Quizzes: lesson quizzes and score gates (see quiz-course.php).
    $quiz_on     = function_exists('nias_quiz_enabled') && nias_quiz_enabled() && function_exists('nias_quiz_public_model');
    $uid         = ($user && $user->ID) ? (int) $user->ID : 0;
    $seen_quiz   = array(); // quiz ids of the lessons so far (for "prev" gates)
    $lesson_refs = array(); // quiz id => lesson (gradebook)
    $quiz_cache  = array();
    $get_quiz    = function ($id) use (&$quiz_cache) {
        $id = (int) $id;
        if (!isset($quiz_cache[$id])) {
            $q = $id ? nias_quiz_get($id) : null;
            $quiz_cache[$id] = ($q && !empty($q['questions'])) ? $q : null;
        }
        return $quiz_cache[$id];
    };
    // Where each quiz lives (first lesson that carries it), known up front so a
    // gate can point to a quiz in a later lesson too.
    if ($quiz_on) {
        foreach ($chapters_src as $ci => $chap) {
            foreach ((isset($chap['lessons']) ? $chap['lessons'] : array()) as $li => $les) {
                $rq = !empty($les['quiz']) ? (int) $les['quiz'] : 0;
                if ($rq && !isset($lesson_refs[$rq]) && $get_quiz($rq)) {
                    $lesson_refs[$rq] = array('lessonId' => 'c' . $ci . 'l' . $li, 'lessonTitle' => isset($les['title']) ? $les['title'] : '', 'chapterTitle' => isset($chap['title']) ? $chap['title'] : '');
                }
            }
        }
    }

    // Build chapters/lessons for the view.
    $chapters = array();
    foreach ($chapters_src as $ci => $chap) {
        $lessons = array();
        foreach ((isset($chap['lessons']) ? $chap['lessons'] : array()) as $li => $les) {
            $private = !empty($les['private']);
            $base    = ($private && !$purchased);
            $lid     = 'c' . $ci . 'l' . $li;

            // Score gate: which quizzes must be passed (and how well) first.
            $gate = null;
            if ($quiz_on && !empty($les['gate'])) {
                $need = $les['gate'] === 'prev' ? $seen_quiz : array((int) $les['gate']);
                $reqs = array();
                $open = true;
                foreach ($need as $gq_id) {
                    $gq = $get_quiz($gq_id);
                    if (!$gq) {
                        continue; // deleted / empty quiz never blocks
                    }
                    $min = nias_quiz_gate_min($gq, isset($les['gateMin']) ? $les['gateMin'] : '');
                    $rec = nias_quiz_user_record($uid, $gq_id);
                    $ok  = $rec && $rec['best'] !== null && $rec['best'] >= $min;
                    if (!$ok) {
                        $open = false;
                    }
                    $reqs[] = array(
                        'id'       => (int) $gq_id,
                        'title'    => $gq['title'],
                        'min'      => $min,
                        'pending'  => $rec ? (int) $rec['pending'] : 0,
                        'best'     => $rec ? $rec['best'] : null,
                        'ok'       => $ok,
                        'lessonId' => isset($lesson_refs[$gq_id]) ? $lesson_refs[$gq_id]['lessonId'] : '',
                    );
                }
                if (!$open) {
                    $gate = $reqs;
                }
            }

            // The lesson's own quiz.
            $quiz_payload = null;
            $quiz_id      = $quiz_on && !empty($les['quiz']) ? (int) $les['quiz'] : 0;
            $lq           = $quiz_id ? $get_quiz($quiz_id) : null;
            if ($lq) {
                $state = nias_quiz_client_state($lq, $uid);
                $quiz_payload = array(
                    'id'        => $quiz_id,
                    'title'     => $lq['title'],
                    'passScore' => (int) $lq['passScore'],
                    'qCount'    => count($lq['questions']),
                    'record'    => $state['record'],
                );
                // The questions only go out when the lesson is open to this user.
                $lock_quiz = $gate || ($base && (!function_exists('nias_course_lock_part') || nias_course_lock_part('content')));
                if (!$lock_quiz) {
                    $quiz_payload['model']  = nias_quiz_public_model($lq, $uid);
                    $quiz_payload['config'] = nias_quiz_student_config($lq);
                }
            }

            // Per-part access: a private, unpurchased lesson hides only the parts
            // the admin chose to lock (تنظیمات «قفل درس‌های خصوصی»). Each part maps
            // to one underlying field, consistent with the account/widget views:
            //   preview→video, content→description/text, attachments→file.
            $has_lock_fn  = function_exists('nias_course_lock_part');
            $lock_video   = $base && (!$has_lock_fn || nias_course_lock_part('preview'));
            $lock_content = $base && (!$has_lock_fn || nias_course_lock_part('content'));
            $lock_attach  = $base && (!$has_lock_fn || nias_course_lock_part('attachments'));

            // Raw values (before stripping) — used to decide the player lock cover.
            $raw_video    = nias_modern_course_media_url(isset($les['video']) ? $les['video'] : array());
            // The preview can also be a pasted embed snippet (iframe/…). It shares
            // the "video" slot and its lock, but renders as raw HTML.
            $raw_embed    = (isset($les['video']['type']) && $les['video']['type'] === 'embed' && !empty($les['video']['embed']))
                ? (string) $les['video']['embed'] : '';
            $has_video    = ($raw_video !== '' || $raw_embed !== '');
            $raw_file     = nias_modern_course_media_url(isset($les['file']) ? $les['file'] : array());
            $raw_content  = nias_modern_course_render_content(isset($les['content']) ? $les['content'] : '');
            // No video field and no file: a video/audio inside the content
            // becomes the lesson's medium (it is part of the content, so it
            // follows the content lock).
            $promoted = (!$has_video && $raw_file === '') ? nias_modern_course_extract_media($raw_content) : null;
            if ($promoted) {
                $raw_content = $promoted['rest'];
            }
            $raw_has_text = nias_modern_course_has_content($raw_content);
            $text_primary = (!$has_video && !$promoted && $raw_file === '' && $raw_has_text);

            // Strip the locked parts so they never reach the browser.
            $video_url  = $lock_video   ? '' : $raw_video;
            $embed_code = $lock_video   ? '' : $raw_embed;
            $file_url   = $lock_attach  ? '' : $raw_file;
            $content    = $lock_content ? '' : $raw_content;
            if ($promoted) {
                $video_url = $lock_content ? '' : $promoted['src'];
            }

            // The player shows a lock cover when the lesson's PRIMARY medium is
            // locked (video/embed first, then a downloadable file, then text).
            // When the lesson has no medium at all but is private and withheld
            // from this user, the "private" cover still takes priority over the
            // generic "no content" message.
            if ($has_video) {
                $player_locked = $lock_video;
            } elseif ($promoted) {
                $player_locked = $lock_content;
            } elseif ($raw_file !== '') {
                $player_locked = $lock_attach;
            } else {
                // Text lesson, or a lesson with no medium at all.
                $player_locked = $lock_content;
            }

            // A gated lesson sends nothing but its title until it opens.
            if ($gate) {
                $video_url = $embed_code = $file_url = $content = '';
                $player_locked = true;
            }

            $lessons[] = array(
                'id'           => $lid,
                'gate'         => $gate,
                'quiz'         => $quiz_payload,
                'title'        => isset($les['title']) ? $les['title'] : '',
                'label'        => isset($les['label']) ? $les['label'] : '',
                'duration'     => isset($les['duration']) ? $les['duration'] : '',
                'private'      => $private,
                'locked'       => $player_locked,
                // Tab-level "withheld because private" flags, so the info bar shows
                // a clear private notice instead of an "empty" message.
                'lockedDesc'   => ($lock_content && !$text_primary && $raw_has_text),
                'lockedRes'    => ($lock_attach && $raw_file !== ''),
                'videoKind'    => $embed_code !== '' ? 'embedcode' : ($video_url ? ($promoted ? $promoted['kind'] : (nias_modern_course_is_audio_file($video_url) ? 'audio' : (nias_modern_course_is_video_file($video_url) ? 'file' : 'embed'))) : ''),
                'videoSrc'     => $video_url,
                'videoEmbed'   => $embed_code,
                'downloadUrl'  => $file_url,
                'downloadName' => $file_url ? nias_modern_course_file_name($file_url) : '',
                'content'      => $content,
                'hasContent'   => nias_modern_course_has_content($content),
            );
            if ($lq && !in_array($quiz_id, $seen_quiz, true)) {
                $seen_quiz[] = $quiz_id;
            }
        }
        $chapters[] = array(
            'title'    => isset($chap['title']) ? $chap['title'] : '',
            'subtitle' => isset($chap['subtitle']) ? $chap['subtitle'] : '',
            'lessons'  => $lessons,
        );
    }

    // Certificate wiring.
    $cert_applies = nias_modern_course_cert_applies($product_id);
    $cert_page_id = (int) carbon_get_theme_option('certificate_display_page');
    $cert_url     = $cert_page_id ? get_permalink($cert_page_id) : '';
    // Unlock threshold: the "minimum progress" certificate rule when set,
    // otherwise the historical 80%.
    $cert_threshold = 80;
    if ($cert_applies && function_exists('nias_cert_conditions_for')) {
        $rules = nias_cert_conditions_for($product_id);
        if ((int) $rules['min_progress'] > 0) {
            $cert_threshold = (int) $rules['min_progress'];
        }
    }

    return array(
        'productId'    => $product_id,
        'courseTitle'  => $product->post_title,
        'courseSubtitle' => wp_trim_words(wp_strip_all_tags($product->post_excerpt ? $product->post_excerpt : $product->post_content), 32, '…'),
        'instructor'   => $instructor,
        'studentName'  => ($user && $user->ID) ? $user->display_name : __('کاربر مهمان', 'nias-course-widget'),
        'purchased'    => $purchased,
        'buyUrl'       => function_exists('nias_course_access_url') ? nias_course_access_url($product_id) : get_permalink($product_id),
        'free'         => $free, // «دوره» without a sale: private lessons only need a login
        'accent'       => '#1e83f0',
        'chapters'     => $chapters,
        'certificate'  => array(
            'enabled'   => $cert_applies,
            'threshold' => $cert_threshold,
            'pageUrl'   => $cert_url ? $cert_url : '',
            // Certificate also needs every course quiz passed (certificate rule "quiz").
            'quizRequired' => $quiz_on && $cert_applies && function_exists('nias_cert_conditions_for')
                && nias_cert_conditions_for($product_id)['quiz'] === 'on' && nias_quiz_for_course($product_id),
        ),
        // Overall progress is mirrored to the server so the certificate's
        // "minimum progress" rule can be enforced.
        'progressSync' => ($purchased && $user && $user->ID) ? array(
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nias_course_progress'),
        ) : null,
        // Nias player: real watched maps (all devices), completion rule, watermark.
        'watch'        => ($user && $user->ID && function_exists('nias_watch_for_client')) ? (object) nias_watch_for_client($user->ID, $product_id) : (object) array(),
        'completePct'  => function_exists('nias_watch_complete_pct') ? nias_watch_complete_pct() : 90,
        // Gradebook (every quiz of the course + this user's results) and the
        // endpoint used to reload the course after a quiz opens new lessons.
        'grades'       => $quiz_on ? nias_quiz_gradebook($uid, $product_id, $lesson_refs) : array(),
        'loggedIn'     => (bool) $uid,
        'refresh'      => array(
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nias_mc_data_' . $product_id),
        ),
    );
}

/* -------------------------------------------------------------------------
 * Render
 * ---------------------------------------------------------------------- */

/** Valid section parts that can be rendered on their own. */
function nias_modern_course_parts()
{
    return array('full', 'header', 'player', 'info', 'curriculum', 'certificate', 'grades');
}

/**
 * Build (and per-request cache) the data model for a product, so rendering
 * several section parts on one page only does the heavy work once.
 *
 * @param int $product_id
 * @return array|null
 */
function nias_modern_course_get_data($product_id)
{
    static $cache = array();
    $product_id = intval($product_id);
    if (!array_key_exists($product_id, $cache)) {
        $cache[$product_id] = nias_modern_course_build_data($product_id);
    }
    return $cache[$product_id];
}

/**
 * Render the modern course view (or a single section of it) for a product.
 *
 * Every section shares one front-end controller per product, so the player,
 * curriculum, header, etc. can be placed in separate shortcodes/areas and still
 * stay in sync (same current lesson, same progress).
 *
 * @param int    $product_id
 * @param string $part full|header|player|info|curriculum|certificate
 * @return string HTML (empty string when there is nothing to show).
 */
function nias_modern_course_render($product_id, $part = 'full')
{
    if (!in_array($part, nias_modern_course_parts(), true)) {
        $part = 'full';
    }
    $data = nias_modern_course_get_data($product_id);
    if (!$data) {
        return '';
    }
    // The certificate section only renders when the feature applies.
    if ($part === 'grades' && empty($data['grades'])) {
        return '';
    }
    if ($part === 'certificate' && (empty($data['certificate']) || empty($data['certificate']['enabled']))) {
        return '';
    }

    static $seq = 0;
    $seq++;
    $uid = 'nmc-' . intval($product_id) . '-' . $seq;

    // The (large) data payload is only printed once per product per request;
    // later section parts just mount against the already-registered data.
    static $registered = array();
    $need_data = empty($registered[$product_id]);
    $registered[$product_id] = true;

    ob_start();
    nias_modern_course_assets();
    ?>
    <div class="nmc" id="<?php echo esc_attr($uid); ?>" dir="rtl"></div>
    <script>
        (function () {
            function boot() {
                if (!window.NiasModernCourse) { return; }
                <?php if ($need_data) : ?>
                window.NiasModernCourse.register(<?php echo wp_json_encode($data); ?>);
                <?php endif; ?>
                window.NiasModernCourse.mount(<?php echo (int) $product_id; ?>, <?php echo wp_json_encode($part); ?>, <?php echo wp_json_encode($uid); ?>);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Print the component CSS + JS once per request.
 */
function nias_modern_course_assets()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;

    // Always load the Nias player so nias-vp-wrap / nias-ap players inside
    // lesson content (which lands in the DOM via JS) work without a separate print.
    $base    = plugin_dir_url(NIAS_COURSE . 'nias-course-widget.php');
    $css_ver = NIAS_COURSE_VERSION;
    ?>
    <?php if (function_exists('nias_player_script_tags')) { nias_player_script_tags(); } ?>
    <?php if (function_exists('nias_quiz_enabled') && nias_quiz_enabled() && function_exists('nias_quiz_student_assets')) { nias_quiz_student_assets(); } ?>
    <style>
    .nmc-stage{position:relative}
    /* quizzes */
    .nmc-stage.nmc-stage-quiz{padding:22px 18px;background:#f6f8fa}
    .nmc-quiz .nqs{max-width:820px}
    .nmc-quiz-tab{padding:4px 0}
    .nmc-tab-quiz{display:inline-flex;align-items:center;gap:6px}
    .nmc-tab-score{font-size:11px;font-weight:800;padding:1px 7px;border-radius:99px;background:#eef1f4;color:#7b868a}
    .nmc-tab-score.ok{background:rgba(72,175,59,.14);color:#3e9a32}
    .nmc-tab-score.no{background:#fdeaea;color:#d64545}
    .nmc-les-q{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;padding:1px 7px;border-radius:99px;background:#eaf3ff;color:#1e6fd0}
    .nmc-les-q svg{width:12px;height:12px}
    .nmc-les-q.ok{background:rgba(72,175,59,.13);color:#3e9a32}
    .nmc-les-q.no{background:#fdeaea;color:#d64545}
    .nmc-les-q.wait,.nmc-gr-st.wait,.nmc-gate-st.wait{background:#eef5ff;color:#2563c9}
    .nmc-les-new{font-size:11px;font-weight:700;padding:1px 7px;border-radius:99px;background:#fff3d6;color:#b77a00}
    .nmc-gate .nmc-gate-ic{background:#f3eefe;color:#7a4fd0}
    .nmc-gate-list{display:flex;flex-direction:column;gap:10px;width:100%;max-width:560px;margin-top:6px}
    .nmc-gate-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;text-align:right;background:#fff;border:1px solid #eceff2;border-radius:14px;padding:12px 14px}
    .nmc-gate-q{flex:1;min-width:160px;display:flex;flex-direction:column;gap:2px}
    .nmc-gate-q b{font-size:14px;color:#1f2a30}
    .nmc-gate-q span{font-size:12px;color:#8a979e}
    .nmc-gate-st{font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;background:#eef1f4;color:#6b777d;display:inline-flex;align-items:center;gap:4px}
    .nmc-gate-st svg{width:13px;height:13px}
    .nmc-gate-st.ok{background:rgba(72,175,59,.13);color:#3e9a32}
    .nmc-gate-st.no{background:#fdeaea;color:#d64545}
    .nmc-gate-row .nmc-soon-btn{margin-top:0}
    .nmc-stat-q{border:0;background:#eaf3ff;color:#1e6fd0;cursor:pointer;font:inherit;border-radius:99px;padding:3px 10px}
    .nmc-grades{margin-top:22px;overflow:hidden}
    .nmc-gr-head{display:flex;align-items:center;gap:10px;padding:18px 22px;border-bottom:1px solid #eef1f4;color:var(--nmc-accent,#1e83f0)}
    .nmc-gr-head h3{margin:0;font-size:16px;font-weight:800;color:#1f2a30}
    .nmc-gr-sum{margin-right:auto;font-size:13px;color:#6b777d;font-weight:600}
    .nmc-gr-row{display:flex;align-items:center;gap:14px;padding:14px 22px;border-bottom:1px solid #f2f4f6}
    .nmc-gr-row:last-child{border-bottom:0}
    .nmc-gr-ic{width:38px;height:38px;flex:none;border-radius:11px;background:#f4f6f8;display:flex;align-items:center;justify-content:center}
    .nmc-gr-main{flex:1;min-width:0}
    .nmc-gr-t{font-size:14.5px;font-weight:700;color:#2b363c}
    .nmc-gr-w{font-size:12.5px;color:var(--nmc-accent,#1e83f0);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nmc-gr-m{font-size:12px;color:#8a979e;margin-top:3px}
    .nmc-gr-s{width:150px;flex:none;display:flex;flex-direction:column;align-items:flex-start;gap:6px}
    .nmc-gr-pct{font-size:20px;font-weight:800;line-height:1}
    .nmc-gr-bar{position:relative;width:100%;height:6px;border-radius:99px;background:#eef1f4}
    .nmc-gr-bar span{position:absolute;right:0;top:0;bottom:0;border-radius:99px;transition:width .5s}
    .nmc-gr-bar i{position:absolute;top:-3px;width:2px;height:12px;background:#2b363c;opacity:.35;border-radius:2px}
    .nmc-gr-st{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;background:#eef1f4;color:#7b868a}
    .nmc-gr-st.ok{background:rgba(72,175,59,.13);color:#3e9a32}
    .nmc-gr-st.no{background:#fdeaea;color:#d64545}
    .nmc-gr-go{flex:none;border:1px solid #dbe6f3;background:#f5f9ff;color:var(--nmc-accent,#1e83f0);font:inherit;font-size:13px;font-weight:700;padding:8px 14px;border-radius:10px;cursor:pointer}
    .nmc-gr-go:hover{background:#eaf3ff}
    .nmc-cert-quiz{display:flex;align-items:center;gap:6px;margin-top:10px;color:#d64545}
    .nmc-cert-quiz.ok{color:#3e9a32}
    .nmc-toast{position:fixed;bottom:24px;left:50%;transform:translate(-50%,20px);opacity:0;z-index:100000;display:flex;align-items:center;gap:8px;background:#1f2a30;color:#fff;padding:12px 18px;border-radius:12px;font-size:14px;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.25);transition:opacity .3s,transform .3s;direction:rtl;max-width:calc(100vw - 32px)}
    .nmc-toast.on{opacity:1;transform:translate(-50%,0)}
    .nmc-toast svg{color:#5fd14b;flex:none}
    @media (max-width:900px){.nmc-toast{bottom:84px}}
    @media (max-width:600px){.nmc-gr-row{flex-wrap:wrap;padding:14px 16px}.nmc-gr-s{width:auto;flex:1 1 100%;order:3}.nmc-gr-go{order:4;width:100%}.nmc-stage.nmc-stage-quiz{padding:14px 10px}}
    /* Nias player inside lesson content */
    .nias-vp-wrap{width:100%;margin:10px 0;border-radius:12px;overflow:hidden;background:#000}
    /* Waveform audio player used inside lesson content */
    .nias-ap{--nias-ap-accent:#1e83f0;box-sizing:border-box;display:flex;align-items:center;gap:14px;width:100%;max-width:none;margin:10px 0;padding:12px 16px;border-radius:16px;background:linear-gradient(135deg,#f3f7fb,#e9eef5);border:1px solid #e2e8f0;direction:ltr}
    .nias-ap *{box-sizing:border-box}
    .nias-ap-btn{width:46px;height:46px;flex:none;border:0;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;background:var(--nias-ap-accent);box-shadow:0 8px 18px -6px rgba(30,131,240,.7);transition:transform .15s}
    .nias-ap-btn:hover{transform:scale(1.07)}
    .nias-ap-btn svg{width:20px;height:20px;fill:currentColor}
    .nias-ap-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
    .nias-ap-wave{display:flex;align-items:center;justify-content:space-between;gap:2px;height:30px;cursor:pointer;direction:ltr;overflow:hidden}
    .nias-ap-bar{flex:0 0 3px;width:3px;min-height:4px;border-radius:99px;background:rgba(30,60,90,.18);transition:background .15s,transform .15s}
    .nias-ap-bar.on{background:var(--nias-ap-accent)}
    .nias-ap-wave:hover .nias-ap-bar{transform:scaleY(1.08)}
    .nias-ap-time{font-size:12.5px;font-weight:600;color:#5b666c;font-variant-numeric:tabular-nums;direction:ltr;display:flex;justify-content:space-between}
    .nias-ap-time .sep{display:none}
    .nias-ap audio{display:none}
    </style>
    <script>
    /* ── Nias inline media player bootstrap (used inside lesson content) ── */
    (function () {
        var PLAY  = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
        var PAUSE = '<svg viewBox="0 0 24 24"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>';

        /* ---- waveform audio ---- */
        function buildAudio(box) {
            if (!box || box.getAttribute('data-nias-ap-ready') === '1') { return; }
            var src = box.getAttribute('data-src');
            if (!src) { return; }
            box.setAttribute('data-nias-ap-ready', '1');
            var NB = Math.max(24, Math.min(160, Math.floor(((box.clientWidth || 600) - 92) / 6))), bars = '';
            for (var i = 0; i < NB; i++) {
                var r = Math.abs(Math.sin((i + 1) * 12.9898) * 43758.5453); r -= Math.floor(r);
                bars += '<span class="nias-ap-bar" data-i="' + i + '" style="height:' + Math.round(24 + r * 76) + '%"></span>';
            }
            function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
            function fmt(t) { t = Math.max(0, Math.floor(t||0)); var m = Math.floor(t/60), s = t%60; return fa(m)+':'+fa(s<10?'0'+s:s); }
            box.innerHTML =
                '<button type="button" class="nias-ap-btn" data-act="toggle" aria-label="پخش/توقف">' + PLAY + '</button>' +
                '<div class="nias-ap-main"><div class="nias-ap-wave" role="slider" aria-label="نوار پخش صوت">' + bars + '</div>' +
                '<div class="nias-ap-time"><span class="cur">۰:۰۰</span><span class="sep">/</span><span class="dur">۰:۰۰</span></div></div>';
            var a = document.createElement('audio'); a.preload = 'metadata'; a.src = src;
            box.appendChild(a);
            var btn = box.querySelector('[data-act="toggle"]');
            var curEl = box.querySelector('.cur'), durEl = box.querySelector('.dur');
            var barEls = box.querySelectorAll('.nias-ap-bar');
            function paint() {
                var d = a.duration||0, lit = d ? Math.round(a.currentTime/d*NB) : 0;
                for (var i=0;i<barEls.length;i++) { barEls[i].classList.toggle('on', i<lit); }
                curEl.textContent = fmt(a.currentTime);
            }
            btn.addEventListener('click', function () { a.paused ? a.play() : a.pause(); });
            a.addEventListener('play',           function () { btn.innerHTML = PAUSE; });
            a.addEventListener('pause',          function () { btn.innerHTML = PLAY; });
            a.addEventListener('loadedmetadata', function () { durEl.textContent = fmt(a.duration); paint(); });
            a.addEventListener('timeupdate', paint);
            a.addEventListener('ended',          function () { btn.innerHTML = PLAY; paint(); });
            box.querySelector('.nias-ap-wave').addEventListener('click', function (ev) {
                if (!a.duration) { return; }
                var r = this.getBoundingClientRect(), f = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
                try { a.currentTime = f * a.duration; } catch(e){}
                paint();
            });
        }

        /* ---- Nias player for videos inside the content ---- */
        function buildVideo(wrap) {
            if (wrap && window.NiasPlayer) { window.NiasPlayer.scan(wrap.parentNode || wrap); }
        }

        /* ---- scan a container (or whole document) ---- */
        function scanMedia(root) {
            var r = root || document;
            r.querySelectorAll('.nias-ap[data-src]').forEach(buildAudio);
            if (window.NiasPlayer) { window.NiasPlayer.scan(r); }
        }

        /* expose globally so NiasModernCourse can call it after DOM updates */
        window.NiasMedia = { scan: scanMedia, buildAudio: buildAudio, buildVideo: buildVideo };

        /* initial scan on DOMContentLoaded */
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { scanMedia(); });
        } else {
            scanMedia();
        }
    })();
    </script>
    <?php
    nias_modern_course_script();
}

/**
 * The shared front-end controller (printed once).
 */
function nias_modern_course_script()
{
    ?>
    <style>
    .nmc,.nmc *{box-sizing:border-box}
    /* Front-end: do not load any web font — inherit the theme/site font. */
    .nmc{font-family:inherit;color:#1f2a30;background:#eef1f4;padding:24px 16px 40px;border-radius:22px;line-height:1.6}
    .nmc img{max-width:100%}
    @keyframes nmc-spin{to{transform:rotate(360deg)}}
    @keyframes nmc-fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .nmc-scroll::-webkit-scrollbar{width:8px;height:8px}
    .nmc-scroll::-webkit-scrollbar-thumb{background:#cdd5da;border-radius:99px}
    .nmc-wrap{max-width:1840px;margin:0 auto;display:flex;flex-direction:column;gap:22px}
    .nmc-card{background:#fff;border:1px solid #e9ecef;border-radius:18px;box-shadow:0 6px 22px rgba(31,42,48,.05)}

    /* header */
    .nmc-header{padding:22px 24px;display:flex;align-items:center;gap:22px;flex-wrap:wrap}
    .nmc-h-info{flex:1;min-width:240px;display:flex;flex-direction:column;gap:9px}
    .nmc-tagrow{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    .nmc-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(30,131,240,.1);color:var(--nmc-accent,#1e83f0);font-size:12px;font-weight:600;padding:5px 11px;border-radius:99px}
    .nmc-instr{font-size:13px;color:#7b868a}
    .nmc-title{margin:0;font-size:26px;font-weight:800;letter-spacing:-.3px}
    .nmc-sub{margin:0;font-size:14px;color:#5b666c;line-height:1.7}
    .nmc-stats{display:flex;align-items:center;gap:18px;margin-top:4px;flex-wrap:wrap}
    .nmc-stat{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#5b666c}
    .nmc-stat.ok{color:#48af3b;font-weight:600}
    .nmc-h-side{display:flex;align-items:center;gap:18px}
    .nmc-ring{position:relative;width:118px;height:118px;flex:none}
    .nmc-ring-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
    .nmc-ring-pct{font-size:26px;font-weight:800;line-height:1}
    .nmc-ring-lbl{font-size:11px;color:#7b868a;margin-top:2px}
    .nmc-certbtn{display:inline-flex;align-items:center;gap:7px;font-family:inherit;font-size:14px;font-weight:700;padding:12px 18px;border-radius:12px;cursor:pointer;border:0}
    .nmc-certbtn.on{background:var(--nmc-accent,#1e83f0);color:#fff}
    .nmc-certbtn.off{background:#f1f4f6;color:#9aa4a9;cursor:default}

    /* layout */
    .nmc-layout{display:grid;grid-template-columns:1fr 350px;gap:22px;align-items:start}
    @media (min-width:1400px){.nmc-layout{grid-template-columns:1fr 400px}}
    .nmc-main{display:flex;flex-direction:column;gap:22px;min-width:0}
    .nmc-side{position:sticky;top:20px;display:flex;flex-direction:column;gap:22px}
    @media (max-width:900px){.nmc-layout{grid-template-columns:1fr}.nmc-side{position:static}}

    /* player */
    .nmc-player{background:#0e1518;border-radius:18px;overflow:hidden;box-shadow:0 14px 40px rgba(14,21,24,.28)}
    .nmc-stage{position:relative;width:100%;aspect-ratio:16/9;background:#0e1518}
    /* Wide screens: never taller than the window (theatre-style), controls stay visible */
    @media (min-width:901px){.nmc-stage:not(.is-audio):not(.nmc-stage-soft){max-height:calc(100vh - 110px);max-height:calc(100dvh - 110px)}}
    .nmc-stage video,.nmc-stage iframe{width:100%;height:100%;border:0;background:#0e1518}
    .nmc-embed{position:absolute;inset:0;width:100%;height:100%;overflow:hidden}
    .nmc-embed iframe{width:100%!important;height:100%!important;border:0}
    .nmc-embed .h_iframe-aparat_embed_frame,.nmc-embed .h_iframe-aparat_embed_frame span{position:static!important;padding:0!important;height:100%!important;display:block}
    .nmc-stage video{object-fit:contain}
    .nmc-text{width:100%;height:100%;overflow:auto;background:#fff;direction:rtl}
    .nmc-text-in{max-width:760px;margin:0 auto;padding:40px 40px;font-size:16px;line-height:2.05;color:#2b363c}
    .nmc-text-tag{display:flex;align-items:center;gap:10px;margin-bottom:22px;color:var(--nmc-accent,#1e83f0);font-size:13px;font-weight:600}
    .nmc-text-body{animation:nmc-fade .4s}
    .nmc-text-body p{margin:0 0 14px}
    .nmc-text-body iframe{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:12px;margin:6px 0}
    .nmc-cover{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;text-align:center;padding:30px}
    .nmc-cover.dl{background:linear-gradient(135deg,#16232a,#0e1518)}
    .nmc-cover.lock{background:linear-gradient(135deg,#1a262d,#0e1518)}
    .nmc-cover.empty{background:linear-gradient(135deg,#1a262d,#0e1518)}
    .nmc-player.nmc-player-soft{background:#fff;box-shadow:0 1px 2px rgba(14,21,24,.04),0 8px 24px rgba(14,21,24,.06);border:1px solid #edf0f3}
    .nmc-stage.nmc-stage-soft{aspect-ratio:auto;background:transparent}
    .nmc-soon{display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;padding:38px 24px;direction:rtl}
    .nmc-soon-ic{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 12%,#fff);color:var(--nmc-accent,#1e83f0)}
    .nmc-soon-ic svg{width:26px;height:26px}
    .nmc-soon-t{font-size:17px;font-weight:700;color:#1f2a30}
    .nmc-soon-m{font-size:13.5px;color:#7b8790;line-height:1.9;max-width:460px}
    .nmc-soon-btn{margin-top:6px;display:inline-flex;align-items:center;gap:8px;border:1px solid color-mix(in srgb,var(--nmc-accent,#1e83f0) 30%,#fff);background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 7%,#fff);color:var(--nmc-accent,#1e83f0);font:inherit;font-size:13.5px;font-weight:600;padding:9px 16px;border-radius:11px;cursor:pointer;max-width:100%}
    .nmc-soon-btn span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nmc-soon-btn svg{width:16px;height:16px;flex:none}
    .nmc-soon-btn:hover{background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 13%,#fff)}
    .nmc-cover-ic{width:78px;height:78px;border-radius:20px;display:flex;align-items:center;justify-content:center}
    .nmc-cover-ic.dl{background:rgba(72,175,59,.16);color:#5fd14b}
    .nmc-cover-ic.lock{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.08);color:#fff}
    .nmc-cover-t{color:#fff;font-size:19px;font-weight:700}
    .nmc-cover-m{color:#8a979e;font-size:13px;max-width:340px;line-height:1.8}
    .nmc-cover-btn{display:inline-flex;align-items:center;gap:8px;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:99px}
    .nmc-cover-btn.dl{background:#48af3b}
    .nmc-cover-btn.buy{background:#ff6060;font-size:14px;padding:11px 24px}

    /* audio player (waveform) */
    .nmc-audio{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;padding:26px 24px;background:linear-gradient(150deg,#18262e 0%,#0e1518 70%);text-align:center}
    .nmc-audio audio{display:none}
    .nmc-audio-art{width:88px;height:88px;flex:none;border-radius:24px;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,var(--nmc-accent,#1e83f0),#0e6fd6);box-shadow:0 14px 30px -10px rgba(30,131,240,.7)}
    .nmc-audio.playing .nmc-audio-art{animation:nmc-pulse 1.6s ease-in-out infinite}
    @keyframes nmc-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
    .nmc-audio-name{color:#eaf1f6;font-size:15px;font-weight:700;max-width:82%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nmc-audio-track{display:flex;align-items:center;gap:14px;width:min(640px,92%);direction:ltr}
    .nmc-audio-wave{display:flex;align-items:center;gap:3px;height:56px;flex:1;min-width:0;cursor:pointer;direction:ltr}
    .nmc-audio-t{color:#aab6bd;font-size:13px;font-weight:600;font-variant-numeric:tabular-nums;min-width:38px;flex:none}
    .nmc-audio-t.dur{text-align:right}
    .nmc-audio-skip,.nmc-audio-rate{width:44px;height:44px;flex:none;border:0;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#dfe8ee;background:rgba(255,255,255,.08);transition:background .15s;font-family:inherit}
    .nmc-audio-skip:hover,.nmc-audio-rate:hover{background:rgba(255,255,255,.16)}
    .nmc-audio-skip svg{width:22px;height:22px}
    .nmc-audio-rate{width:auto;min-width:44px;padding:0 10px;border-radius:22px;font-size:13px;font-weight:700}
    .nmc-audio-rate.on{background:var(--nmc-accent,#1e83f0);color:#fff}
    /* Audio lessons are taller than 16:9 on phones: let the stage grow. */
    .nmc-stage.is-audio{aspect-ratio:auto;min-height:320px}
    .nmc-stage.is-audio .nmc-audio{height:auto;min-height:320px}
    @media (max-width:600px){.nmc-audio{gap:14px;padding:22px 14px}.nmc-audio-art{width:64px;height:64px;border-radius:18px}.nmc-audio-track{width:100%;gap:8px}.nmc-audio-wave{height:44px;gap:2px}.nmc-audio-t{font-size:12px;min-width:32px}.nmc-audio-skip{width:40px;height:40px}}
    .nmc-audio-bar{flex:1 1 0;min-width:2px;border-radius:99px;background:rgba(255,255,255,.16);transition:background .15s,transform .15s}
    .nmc-audio-bar.on{background:var(--nmc-accent,#1e83f0)}
    .nmc-audio-wave:hover .nmc-audio-bar{transform:scaleY(1.06)}
    .nmc-audio-controls{display:flex;align-items:center;gap:16px;direction:ltr}
    .nmc-audio-btn{width:56px;height:56px;flex:none;border:0;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;background:var(--nmc-accent,#1e83f0);box-shadow:0 10px 24px -8px rgba(30,131,240,.8);transition:transform .15s}
    .nmc-audio-btn:hover{transform:scale(1.06)}
    .nmc-audio-time{color:#aab6bd;font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;direction:ltr}
    .nmc-audio-time .sep{opacity:.5;margin:0 4px}

    /* info bar */
    .nmc-info-top{padding:20px 24px;display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .nmc-info-l{flex:1;min-width:220px}
    .nmc-info-tags{display:flex;align-items:center;gap:9px;margin-bottom:6px;flex-wrap:wrap}
    .nmc-chip{display:inline-flex;align-items:center;gap:5px;background:#f1f4f6;color:#5b666c;font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px}
    .nmc-chip.muted{background:none;color:#7b868a;padding:0}
    .nmc-info-title{margin:0;font-size:20px;font-weight:700}
    /* Laid out left→right: previous on the left, next on the right. Inside
       each button the icon sits on its outer side (left of "قبلی", right of
       "بعدی"); the complete button keeps its icon on the right like RTL text. */
    .nmc-nav{display:flex;align-items:center;gap:9px;flex-wrap:wrap;direction:ltr}
    .nmc-nav [data-act="complete"]{direction:rtl}
    .nmc-btn svg{flex:none}
    @media (max-width:600px){
      .nmc-info-top{padding:16px}
      .nmc-nav{display:grid;grid-template-columns:1fr 1fr;width:100%;gap:8px}
      .nmc-nav .nmc-btn{justify-content:center;padding:11px 10px}
      .nmc-nav [data-act="complete"]{grid-column:1 / -1;order:-1}
    }
    .nmc-btn{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:13px;font-weight:600;padding:10px 16px;border-radius:10px;cursor:pointer;border:1px solid #e1e6e9;background:#fff;color:#3a464c}
    .nmc-btn:disabled,.nmc-btn.off{opacity:.45;pointer-events:none}
    .nmc-btn.primary{border:0;background:var(--nmc-accent,#1e83f0);color:#fff;font-weight:700}
    .nmc-btn.done{border:1px solid #9bd58f;background:rgba(72,175,59,.1);color:#2f7a24;font-weight:700}
    .nmc-tabs{display:flex;gap:4px;padding:0 24px;border-bottom:1px solid #eef1f4}
    .nmc-tab{background:none;border:0;border-bottom:2px solid transparent;color:#7b868a;font-family:inherit;font-size:14px;font-weight:600;padding:14px 6px;cursor:pointer}
    .nmc-tab.active{border-bottom-color:var(--nmc-accent,#1e83f0);color:var(--nmc-accent,#1e83f0)}
    .nmc-tabbody{padding:24px}
    .nmc-desc{font-size:15px;line-height:2.05;color:#3a464c;text-align:justify}
    /* Nothing in lesson content may widen the page (long URLs, wide images…) */
    .nmc-desc,.nmc-text-body{overflow-wrap:anywhere;max-width:100%}
    .nmc-desc img,.nmc-text-body img{max-width:100%;height:auto}
    .nmc-desc table,.nmc-text-body table{display:block;max-width:100%;overflow-x:auto}
    /* Aparat embeds: responsive frame, whether or not their own CSS survived */
    .h_iframe-aparat_embed_frame{position:relative;width:100%;aspect-ratio:16/9;margin:6px 0;border-radius:12px;overflow:hidden;background:#000}
    .h_iframe-aparat_embed_frame > span,.h_iframe-aparat_embed_frame .ratio{display:none!important}
    .h_iframe-aparat_embed_frame iframe{position:absolute!important;inset:0;width:100%!important;height:100%!important;margin:0!important;border:0;aspect-ratio:auto}
    .nmc-desc iframe{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:12px;margin:6px 0}
    .nmc-desc p{margin:0 0 14px}
    /* WordPress editor media inside lesson content ([video]/[audio], aparat, oEmbed) */
    .nmc-text-body video,.nmc-desc video{display:block;width:100%;max-width:100%;height:auto;border-radius:12px;margin:6px 0;background:#000}
    .nmc-text-body audio,.nmc-desc audio{display:block;width:100%;margin:10px 0}
    .nmc .wp-video,.nmc .wp-audio-shortcode,.nmc .mejs-container{width:100%!important;max-width:100%!important}
    .nmc .wp-video-shortcode{width:100%!important;height:auto!important}
    .nmc .h_iframe-aparat_embed_frame{width:100%}
    .nmc-reslist{display:flex;flex-direction:column;gap:10px}
    .nmc-res{display:flex;align-items:center;gap:14px;background:#f8fafb;border:1px solid #eef1f4;border-radius:12px;padding:13px 16px;text-decoration:none;color:inherit}
    .nmc-res-ic{width:42px;height:42px;flex:none;border-radius:11px;display:flex;align-items:center;justify-content:center}
    .nmc-res-meta{flex:1;min-width:0}
    .nmc-res-name{display:block;font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .nmc-res-sub{display:block;font-size:12px;color:#8a979e;margin-top:2px}
    .nmc-empty{text-align:center;color:#8a979e;font-size:14px;padding:24px 0}

    /* curriculum sidebar */
    .nmc-cur{overflow:hidden;display:flex;flex-direction:column;max-height:calc(100vh - 40px)}
    .nmc-cur-head{padding:18px 20px;border-bottom:1px solid #eef1f4;display:flex;align-items:center;justify-content:space-between;flex:none}
    .nmc-cur-head h3{margin:0;font-size:16px;font-weight:700}
    .nmc-side-search{position:relative;flex:none;margin:12px 18px}
    .nmc-side-search input{width:100%;height:42px;margin:0;border:1px solid #e1e6e9;border-radius:12px;padding:0 40px 0 38px;font-family:inherit;font-size:14px;color:#2b363c;background:#fafbfc;box-sizing:border-box;direction:rtl;box-shadow:none;transition:border-color .15s,background .15s,box-shadow .15s}
    .nmc-side-search input::placeholder{color:#a3adb2}
    .nmc-side-search input:focus{outline:none;border-color:var(--nmc-accent,#1e83f0);background:#fff;box-shadow:0 0 0 3px rgba(30,131,240,.12)}
    .nmc-side-search > svg{position:absolute;right:12px;top:11px;width:20px;height:20px;color:#9aa4a9;pointer-events:none}
    .nmc-side-clear{position:absolute;left:6px;top:6px;width:30px;height:30px;margin:0;padding:0;border:0;border-radius:8px;background:transparent;color:#8a979e;cursor:pointer;display:flex;align-items:center;justify-content:center}
    .nmc-side-clear[hidden]{display:none}
    .nmc-side-clear:hover{background:#eef1f4;color:#2b363c}
    .nmc-side-clear svg{width:16px;height:16px}
    .nmc-side-hits{padding:10px 18px;font-size:12.5px;font-weight:600;color:#7b868a;background:#fafbfc;border-bottom:1px solid #eef1f4}
    .nmc-side-empty{padding:36px 20px;text-align:center;color:#8a979e;font-size:14px}
    .nmc-ch-head[disabled]{cursor:default;opacity:1;color:inherit}
    .nmc-side .nmc-mark{background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 18%,#fff);color:inherit;border-radius:3px;padding:0 1px}
    .nmc-cur-meta{font-size:12px;color:#7b868a}
    .nmc-cur-list{overflow-y:auto;flex:1}
    .nmc-ch{border-bottom:1px solid #eef1f4}
    .nmc-ch-head{width:100%;display:flex;align-items:center;gap:12px;padding:16px 18px;background:#fff;border:0;cursor:pointer;font-family:inherit;text-align:right}
    .nmc-ch-head.open{background:#fafbfc}
    .nmc-ch-num{width:34px;height:34px;flex:none;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;background:#eef1f4;color:#7b868a}
    .nmc-ch-num.open{background:var(--nmc-accent,#1e83f0);color:#fff}
    .nmc-ch-num.done{background:rgba(72,175,59,.14);color:#48af3b}
    .nmc-ch-body{flex:1;min-width:0}
    .nmc-ch-title{font-size:15px;font-weight:700;color:#1f2a30}
    .nmc-ch-prog{display:flex;align-items:center;gap:10px;margin-top:9px}
    .nmc-bar{flex:1;height:6px;background:#eef1f4;border-radius:99px;overflow:hidden;max-width:260px}
    .nmc-bar > span{display:block;height:100%;background:#48af3b;border-radius:99px;transition:width .5s}
    .nmc-ch-done{font-size:12px;color:#7b868a;white-space:nowrap}
    .nmc-ch-caret{color:#a9b3b8;transition:transform .3s;display:flex}
    .nmc-ch-caret.open{transform:rotate(180deg)}
    .nmc-ch-lessons{background:#fafbfc}
    .nmc-les{width:100%;display:flex;align-items:center;gap:11px;padding:13px 18px 13px 14px;background:transparent;border:0;border-right:3px solid transparent;cursor:pointer;font-family:inherit;text-align:right}
    .nmc-les{position:relative;transition:background .2s}
    .nmc-les:not(.sel):hover{background:rgba(30,60,90,.035)}
    .nmc-les.sel{background:rgba(30,131,240,.11);background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 11%,#fff);border-right:4px solid var(--nmc-accent,#1e83f0);box-shadow:inset 0 1px 0 rgba(30,131,240,.16),inset 0 -1px 0 rgba(30,131,240,.16)}
    .nmc-les.sel .nmc-les-ic{box-shadow:0 6px 14px -6px var(--nmc-accent,#1e83f0)}
    .nmc-les-now{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#fff;background:var(--nmc-accent,#1e83f0);padding:2px 8px 2px 7px;border-radius:99px;white-space:nowrap}
    .nmc-les-now i{width:6px;height:6px;border-radius:50%;background:#fff;animation:nmcNow 1.4s ease-in-out infinite}
    @keyframes nmcNow{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.7)}}
    .nmc-ch-head.has-cur .nmc-ch-title{color:var(--nmc-accent,#1e83f0)}
    .nmc-ch-cur{display:inline-block;margin-right:8px;font-size:11px;font-weight:700;color:var(--nmc-accent,#1e83f0);background:rgba(30,131,240,.1);padding:1px 7px;border-radius:99px;vertical-align:middle}
    @media (prefers-reduced-motion:reduce){.nmc-les-now i{animation:none}}
    .nmc-les-ic{width:30px;height:30px;flex:none;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(30,131,240,.08);color:var(--nmc-accent,#1e83f0)}
    .nmc-les.sel .nmc-les-ic{background:var(--nmc-accent,#1e83f0);color:#fff}
    .nmc-les-ic.lock{background:#f1f4f6;color:#a9b3b8}
    .nmc-les-body{flex:1;min-width:0;text-align:right}
    .nmc-les-row1{display:flex;align-items:center;gap:8px}
    .nmc-les-title{font-size:14px;font-weight:500;color:#2b363c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .nmc-les.sel .nmc-les-title{font-weight:700;color:var(--nmc-accent,#1e83f0)}
    .nmc-les-badge{background:#eef1f4;color:#8a979e;font-size:11px;font-weight:500;padding:2px 7px;border-radius:5px;white-space:nowrap}
    .nmc-les-row2{display:flex;align-items:center;gap:8px;margin-top:5px}
    .nmc-les-dur{font-size:12px;color:#9aa4a9;display:inline-flex;align-items:center;gap:4px}
    .nmc-les-pct{font-size:12px;font-weight:600}
    .nmc-les-status{width:34px;flex:none;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
    .nmc-les-status .dot{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center}
    /* "Continue learning" (curriculum head + mobile sheet) */
    .nmc-continue{display:flex;align-items:center;gap:10px;width:100%;border:0;cursor:pointer;font-family:inherit;text-align:right;background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 9%,#fff);color:#1f2a30;border-radius:12px;padding:10px 12px;margin-top:10px}
    .nmc-continue .ic{width:34px;height:34px;flex:none;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--nmc-accent,#1e83f0);color:#fff}
    .nmc-continue .ic svg{width:16px;height:16px}
    .nmc-continue .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
    .nmc-continue .tx b{font-size:13px;color:var(--nmc-accent,#1e83f0)}
    .nmc-continue .tx span{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    /* Mobile bottom bar + lesson sheet (hidden on desktop) */
    .nmc-mbar,.nmc-sheet,.nmc-sheet-bg{display:none}
    @media (max-width:900px){
      body.nmc-has-mbar{padding-bottom:calc(76px + env(safe-area-inset-bottom))}
      .nmc-mbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:99985;align-items:center;gap:8px;padding:10px 10px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.97);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-top:1px solid #e6eaee;box-shadow:0 -8px 24px rgba(31,42,48,.08);direction:ltr;font-family:inherit}
      .nmc-mbar button{font-family:inherit;border:0;cursor:pointer;color:#1f2a30}
      .nmc-mbar-nav{width:48px;height:48px;flex:none;border-radius:14px;background:#f1f4f7;display:flex;align-items:center;justify-content:center}
      .nmc-mbar-nav svg{width:20px;height:20px}
      .nmc-mbar-nav:disabled{opacity:.35}
      .nmc-mbar-main{direction:rtl;flex:1;min-width:0;height:48px;border-radius:14px;background:var(--nmc-accent,#1e83f0);color:#fff!important;display:flex;align-items:center;gap:10px;padding:0 14px;text-align:right}
      .nmc-mbar-main .t{display:flex;flex-direction:column;min-width:0;flex:1;line-height:1.35}
      .nmc-mbar-main .t b{font-size:13.5px}
      .nmc-mbar-main .t span{font-size:11.5px;opacity:.9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .nmc-mbar-main svg{width:18px;height:18px;flex:none;transform:rotate(180deg)}
      .nmc-sheet-bg{display:block;position:fixed;inset:0;z-index:99990;background:rgba(10,16,20,.45);opacity:0;pointer-events:none;transition:opacity .25s}
      .nmc-sheet{display:flex;flex-direction:column;position:fixed;left:0;right:0;bottom:0;z-index:99991;height:88vh;height:88dvh;background:#fff;border-radius:22px 22px 0 0;box-shadow:0 -12px 40px rgba(10,16,20,.25);transform:translateY(105%);transition:transform .3s cubic-bezier(.2,.8,.2,1);direction:rtl;font-family:inherit;padding-bottom:env(safe-area-inset-bottom)}
      .nmc-sheet.open{transform:translateY(0)}
      .nmc-sheet-bg.open{opacity:1;pointer-events:auto}
      .nmc-sheet-head{padding:8px 16px 12px;border-bottom:1px solid #eef1f4;flex:none;touch-action:none}
      .nmc-sheet-grip{width:44px;height:5px;border-radius:5px;background:#d6dde2;margin:4px auto 10px}
      .nmc-sheet-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
      .nmc-sheet-top h3{margin:0;font-size:17px;font-weight:800;color:#1f2a30}
      .nmc-sheet-top small{display:block;font-size:12px;color:#7b868a;font-weight:500;margin-top:2px}
      .nmc-sheet-x{width:40px;height:40px;border:0;border-radius:12px;background:#f1f4f7;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#3a464c;flex:none}
      .nmc-sheet-search{position:relative;margin-top:12px}
      .nmc-sheet-search input{width:100%;height:44px;border:1px solid #e1e6e9;border-radius:12px;padding:0 40px 0 12px;font-family:inherit;font-size:14px;background:#fafbfc;box-sizing:border-box;direction:rtl}
      .nmc-sheet-search input:focus{outline:none;border-color:var(--nmc-accent,#1e83f0);background:#fff}
      .nmc-sheet-search svg{position:absolute;right:12px;top:12px;width:20px;height:20px;color:#9aa4a9}
      .nmc-sheet-chips{display:flex;gap:8px;margin-top:10px;overflow-x:auto;scrollbar-width:none}
      .nmc-sheet-chips button{flex:none;height:34px;padding:0 14px;border-radius:99px;border:1px solid #e1e6e9;background:#fff;font-family:inherit;font-size:13px;font-weight:600;color:#56636a;cursor:pointer}
      .nmc-sheet-chips button.on{background:var(--nmc-accent,#1e83f0);border-color:var(--nmc-accent,#1e83f0);color:#fff}
      .nmc-sheet-list{flex:1;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}
      .nmc-sheet-empty{padding:40px 20px;text-align:center;color:#8a979e;font-size:14px}
      .nmc-sheet .nmc-ch-head{padding:14px 16px}
      .nmc-mark{background:color-mix(in srgb,var(--nmc-accent,#1e83f0) 18%,#fff);color:inherit;border-radius:3px;padding:0 1px}
      /* Readable, thumb-friendly rows and no scroll-inside-scroll on phones */
      .nmc-les{min-height:56px;padding:12px 16px 12px 12px}
      .nmc-les-title{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.55}
      .nmc-les-row1{align-items:flex-start}
      .nmc-cur-list{overflow:visible!important;max-height:none!important}
      .nmc-wrap.nmc-has-sheet .nmc-side{display:none}
      /* Mini player while scrolling the lesson text */
      .nmc-player.nmc-mini{position:fixed;top:calc(8px + env(safe-area-inset-top));left:8px;width:min(56vw,300px);z-index:99980;border-radius:14px;box-shadow:0 12px 32px rgba(10,16,20,.35);animation:nmc-mini-in .25s ease-out}
      .nmc-mini-ctl{display:none}
      .nmc-player.nmc-mini .nmc-mini-ctl{display:flex;position:absolute;top:6px;right:6px;z-index:20;gap:6px}
      .nmc-mini-ctl button{width:30px;height:30px;border:0;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
      .nmc-mini-ctl svg{width:16px;height:16px}
      @keyframes nmc-mini-in{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
    }

    /* certificate */
    .nmc-cert-head{padding:20px 24px;border-bottom:1px solid #eef1f4;display:flex;align-items:center;gap:10px}
    .nmc-cert-head h3{margin:0;font-size:17px;font-weight:700}
    .nmc-cert-body{padding:26px 24px}
    .nmc-cert-locked{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
    .nmc-cert-lock-ic{width:54px;height:54px;flex:none;border-radius:14px;background:#f1f4f6;display:flex;align-items:center;justify-content:center;color:#a9b3b8}
    .nmc-cert-lock-info{flex:1;min-width:220px}
    .nmc-cert-lock-t{font-size:16px;font-weight:700;margin-bottom:6px}
    .nmc-cert-lock-m{font-size:13px;color:#7b868a;line-height:1.8}
    .nmc-cert-lock-bar{margin-top:12px;display:flex;align-items:center;gap:10px;max-width:420px}
    .nmc-cert-lock-bar .bar{flex:1;height:8px;background:#eef1f4;border-radius:99px;overflow:hidden}
    .nmc-cert-lock-bar .bar > span{display:block;height:100%;background:linear-gradient(90deg,var(--nmc-accent,#1e83f0),#48af3b);border-radius:99px;transition:width .6s}
    .nmc-cert-lock-bar .pct{font-size:13px;font-weight:700;color:var(--nmc-accent,#1e83f0)}
    .nmc-cert-un{display:flex;gap:26px;align-items:center;flex-wrap:wrap}
    .nmc-cert-paper-wrap{flex:1;min-width:300px}
    .nmc-cert-paper{position:relative;border:2px solid #e7d9a8;background:linear-gradient(135deg,#fffdf5,#fbf6e6);border-radius:14px;padding:26px 28px;text-align:center;overflow:hidden}
    .nmc-cert-paper .frame{position:absolute;inset:8px;border:1px solid #ecdfb4;border-radius:9px;pointer-events:none}
    .nmc-cert-paper .seal{display:flex;justify-content:center;color:#c9a84a;margin-bottom:8px}
    .nmc-cert-paper .kicker{font-size:12px;letter-spacing:2px;color:#a98f3f;font-weight:600}
    .nmc-cert-paper .to{font-size:13px;color:#8a7a4c;margin-top:16px}
    .nmc-cert-paper .name{font-size:24px;font-weight:800;color:#2b363c;margin:6px 0}
    .nmc-cert-paper .for{font-size:13px;color:#8a7a4c}
    .nmc-cert-paper .course{font-size:16px;font-weight:700;color:var(--nmc-accent,#1e83f0);margin-top:5px}
    .nmc-cert-side{flex:none;width:260px;display:flex;flex-direction:column;gap:12px}
    .nmc-cert-side .ok{font-size:16px;font-weight:700;color:#48af3b;display:flex;align-items:center;gap:8px}
    .nmc-cert-side p{margin:0;font-size:13px;color:#7b868a;line-height:1.9}
    .nmc-cert-dl{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--nmc-accent,#1e83f0);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:12px;border-radius:11px}
    .nmc-cert-verify{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:#fff;border:1px solid #d4dadd;color:#2b363c;text-decoration:none;font-weight:600;font-size:13px;padding:11px;border-radius:11px}
    </style>
    <?php
    ?>
    <script>
    (function () {
        if (window.NiasModernCourse) { return; }

        var SVGNS = 'http://www.w3.org/2000/svg';
        function el(tag, attrs, html) {
            var n = document.createElement(tag);
            if (attrs) { for (var k in attrs) { if (attrs[k] != null) n.setAttribute(k, attrs[k]); } }
            if (html != null) n.innerHTML = html;
            return n;
        }
        function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
        /** Search key: same length as the input (so match positions map back to the title),
         *  Arabic ي/ك → Persian ی/ک, Persian/Arabic digits → Latin, ZWNJ → space, lower case. */
        function skey(s) {
            return String(s == null ? '' : s).toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ۀة]/g, 'ه')
                .replace(/[‌ ]/g, ' ').replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 1776); })
                .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); });
        }
        function smatch(title, q) { q = skey(q).trim(); return q ? skey(title).indexOf(q) : 0; }

        // Scripts injected through innerHTML are inert (the browser never runs
        // them). Pasted embed codes that ship as a <script> tag — e.g. the
        // Aparat embed script — therefore do nothing, while a pasted <iframe>
        // works. Re-create each script node so it actually executes.
        function runScripts(container) {
            if (!container) { return; }
            var scripts = container.querySelectorAll('script');
            for (var i = 0; i < scripts.length; i++) {
                var old = scripts[i], s = document.createElement('script');
                for (var j = 0; j < old.attributes.length; j++) {
                    s.setAttribute(old.attributes[j].name, old.attributes[j].value);
                }
                if (old.textContent) { s.textContent = old.textContent; }
                old.parentNode.replaceChild(s, old);
            }
        }

        // --- inline icon set (stroke style, matches the design) ---
        var ICON = {
            play: '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
            text: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 10h16M4 15h11M4 20h7"/></svg>',
            iframe: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18v12H3zM8 20h8M12 16v4"/></svg>',
            download: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 11l5 5 5-5M5 21h14"/></svg>',
            clock: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            check: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>',
            checkC: '<svg width="18" height="18" viewBox="0 0 24 24" fill="#48af3b"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm-1.2 14.3l-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z"/></svg>',
            book: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2zM18 3v16"/></svg>',
            award: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M9 13.5L7 22l5-3 5 3-2-8.5"/></svg>',
            caret: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>',
            // Site convention: "previous" sits on the left pointing left,
            // "next" on the right pointing right.
            prev: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>',
            next: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
            back15: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><text x="12" y="15.5" font-size="7.5" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none" font-family="sans-serif">15</text></svg>',
            fwd15: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/><text x="12" y="15.5" font-size="7.5" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none" font-family="sans-serif">15</text></svg>',
            lock: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v9H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>',
            lockBig: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v9H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>',
            fileBig: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 3v5h5"/></svg>',
            circle: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>',
            seal: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="6"/><path d="M12 6v6M9 9h6"/></svg>',
            audio: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 10v4M8 6v12M12 3v18M16 6v12M20 10v4"/></svg>',
            audioBig: '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 10v4M8 6v12M12 3v18M16 6v12M20 10v4"/></svg>',
            playBig: '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
            pauseBig: '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>',
            quiz: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/></svg>'
        };

        function NMC(data) {
            this.d = data;
            this.regions = {}; // part -> host element (header/player/info/curriculum/certificate)
            this.accent = data.accent || '#1e83f0';
            this.KEY = 'nias-mc-' + data.productId;
            this.buildFlat();

            var saved = this.load();
            this.state = {
                progress: saved.progress || {},
                times: saved.times || {},
                watch: {},
                currentId: saved.currentId || (this.flat[0] && this.flat[0].id) || null,
                open: saved.open || { 0: true },
                tab: 'desc'
            };
            // Watched maps: this browser's ∪ the server's (other devices).
            var lw = saved.watch || {}, sw = data.watch || {}, st = this.state;
            Object.keys(lw).concat(Object.keys(sw)).forEach(function (id) {
                if (st.watch[id]) { return; }
                st.watch[id] = window.NiasPlayer ? window.NiasPlayer.mergeWatch(lw[id], sw[id]) : (lw[id] || sw[id]);
                if (!st.times[id] && sw[id] && sw[id].t) { st.times[id] = sw[id].t; }
            });
            // Progress from the watch maps too, so a new device or a cleared
            // browser shows what the server knows (not 0%).
            var donePct = parseInt(data.completePct, 10) || 90;
            Object.keys(st.watch).forEach(function (id) {
                var p = st.watch[id] && +st.watch[id].p;
                if (p > 0) {
                    var v = p >= donePct ? 100 : Math.floor(p);
                    if (!(st.progress[id] >= v)) { st.progress[id] = v; }
                }
            });
            this.applyQuizProgress();
            this._dirty = {};
            this.bindWatchFlush();
            var self0 = this;
            // Any quiz on the page (a lesson quiz or the final course quiz).
            window.addEventListener('nias:quiz-result', function (e) { self0.onQuizResult(e.detail || {}); });
            if (!this.flat.some(function (l) { return l.id === this.state.currentId; }, this)) {
                this.state.currentId = this.flat[0] ? this.flat[0].id : null;
            }
        }

        NMC.prototype.buildFlat = function () {
            this.flat = [];
            (this.d.chapters || []).forEach(function (ch, ci) {
                (ch.lessons || []).forEach(function (l, li) { this.flat.push(Object.assign({ ci: ci, li: li }, l)); }, this);
            }, this);
        };
        // A quiz-only lesson is complete once its quiz is passed.
        NMC.prototype.applyQuizProgress = function () {
            this.flat.forEach(function (l) {
                if (l.quiz && l.quiz.record && l.quiz.record.passed && this.playerType(l) === 'quiz') { this.state.progress[l.id] = 100; }
            }, this);
        };
        NMC.prototype.onQuizResult = function (det) {
            var qid = +det.quizId, r = det.result || {}, st = r.state || null, rec = st ? st.record : null;
            if (!(this.d.grades || []).some(function (g) { return +g.id === qid; })) return;
            if (rec) {
                this.flat.forEach(function (l) { if (l.quiz && +l.quiz.id === qid) { l.quiz.record = rec; } });
                (this.d.grades || []).forEach(function (g) { if (+g.id === qid) { g.record = rec; g.attemptsLeft = st.attemptsLeft; } });
            }
            this.applyQuizProgress();
            this.persist();
            // A pass can open score-gated lessons: reload the course data (the
            // server sends their content only now). The quiz result stays on screen.
            this.renderHeader(); this.renderSide(); this.renderGrades(); this.renderCert(); this.renderMobile();
            // (A gate's minimum can be below the pass score, so check after every attempt.)
            if (r.stored && this.flat.some(function (l) { return !!l.gate; })) { this.refresh(); }
        };
        NMC.prototype.refresh = function () {
            var rf = this.d.refresh, self = this;
            if (!rf || !window.fetch) return;
            var body = new URLSearchParams();
            body.append('action', 'nias_mc_data'); body.append('nonce', rf.nonce); body.append('product_id', this.d.productId);
            fetch(rf.url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.data) return;
                    var before = {};
                    self.flat.forEach(function (l) { before[l.id] = !!l.gate; });
                    self.d = res.data;
                    self.buildFlat();
                    self.applyQuizProgress();
                    var opened = self.flat.filter(function (l) { return before[l.id] && !l.gate; });
                    self._justOpened = self._justOpened || {};
                    opened.forEach(function (l) { self._justOpened[l.id] = true; });
                    // Keep the quiz result on screen; the rest follows the new data.
                    self.renderHeader(); self.renderSide(); self.renderGrades(); self.renderCert(); self.renderMobile();
                    if (opened.length) { self.toast(opened.length === 1 ? '«' + opened[0].title + '» برای شما باز شد' : fa(opened.length) + ' جلسهٔ جدید برای شما باز شد'); }
                })
                .catch(function () {});
        };
        NMC.prototype.toast = function (msg) {
            var t = el('div', { 'class': 'nmc-toast', role: 'status' }, ICON.check + '<span>' + esc(msg) + '</span>');
            document.body.appendChild(t);
            setTimeout(function () { t.classList.add('on'); }, 20);
            setTimeout(function () { t.classList.remove('on'); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400); }, 4500);
        };

        NMC.prototype.load = function () { try { return JSON.parse(localStorage.getItem(this.KEY)) || {}; } catch (e) { return {}; } };
        NMC.prototype.persist = function () {
            var s = this.state;
            try { localStorage.setItem(this.KEY, JSON.stringify({ progress: s.progress, times: s.times, watch: s.watch, currentId: s.currentId, open: s.open })); } catch (e) {}
            this.syncProgress();
        };
        // Debounced push of the overall % to the server (only when it grows).
        NMC.prototype.syncProgress = function () {
            var sync = this.d.progressSync, self = this;
            if (!sync || !window.fetch) return;
            clearTimeout(this._syncT);
            this._syncT = setTimeout(function () {
                var pct = self.overall();
                if (pct <= (self._synced || 0)) return;
                var body = new URLSearchParams();
                body.append('action', 'nias_course_progress_sync');
                body.append('nonce', sync.nonce);
                body.append('product_id', self.d.productId);
                body.append('pct', pct);
                fetch(sync.url, { method: 'POST', credentials: 'same-origin', body: body }).then(function () { self._synced = pct; }).catch(function () {});
            }, 1500);
        };

        // ---- derived ----
        NMC.prototype.cur = function () { var id = this.state.currentId; return this.flat.find(function (l) { return l.id === id; }) || this.flat[0]; };
        NMC.prototype.pctOf = function (id) { return Math.min(100, Math.round(this.state.progress[id] || 0)); };
        // Lessons that count toward overall progress: accessible and with
        // something to watch/read. Empty lessons cannot be completed, so
        // counting them made 100% (and a progress-based certificate) unreachable.
        // Score-gated lessons count too: they are part of the course, just not open yet.
        NMC.prototype.counted = function () { return this.flat.filter(function (l) { return (!l.locked || l.gate) && this.playerType(l) !== 'empty'; }, this); };
        NMC.prototype.overall = function () { var c = this.counted(); if (!c.length) return 0; var s = c.reduce(function (a, l) { return a + this.pctOf(l.id); }.bind(this), 0); return Math.round(s / c.length); };
        NMC.prototype.threshold = function () { var t = this.d.certificate && this.d.certificate.threshold; t = parseInt(t, 10); return isNaN(t) ? 80 : t; };
        NMC.prototype.playerType = function (l) {
            if (l.gate) return 'gated';
            if (l.locked) return 'locked';
            if (l.videoEmbed) return 'embedcode';
            if (l.videoSrc) return l.videoKind === 'file' ? 'video' : (l.videoKind === 'audio' ? 'audio' : 'iframe');
            if (l.downloadUrl) return 'download';
            if (l.hasContent) return 'text';
            if (l.quiz) return 'quiz';
            return 'empty';
        };
        NMC.prototype.quizRec = function (l) { return l && l.quiz ? l.quiz.record : null; };
        NMC.prototype.typeLabel = function (l) {
            return ({ video: 'ویدیو', audio: 'فایل صوتی', iframe: 'پخش آنلاین', embedcode: 'پخش آنلاین', download: 'فایل دانلودی', text: 'درس متنی', locked: 'درس خصوصی', empty: 'درس', quiz: 'آزمون', gated: 'نیازمند قبولی' })[this.playerType(l)];
        };
        NMC.prototype.typeIcon = function (l) {
            return ({ video: ICON.play, audio: ICON.audio, iframe: ICON.iframe, embedcode: ICON.iframe, download: ICON.download, text: ICON.text, locked: ICON.lock, empty: ICON.book, quiz: ICON.quiz, gated: ICON.lock })[this.playerType(l)];
        };

        // ---- mounting ----
        // Attach a section (or the full view) into a root element. Multiple parts
        // of the same instance share state and re-render together.
        NMC.prototype.attach = function (part, rootId) {
            var root = document.getElementById(rootId);
            if (!root) return;
            root.style.setProperty('--nmc-accent', this.accent);
            if (part === 'full') { this.buildFull(root); }
            else { this.buildPart(root, part); }
            if ((part === 'full' || part === 'player') && !this._mobile) { this.setupMobile(part === 'full'); }
            this.renderAll();
        };

        // Full view: header + (player + info | curriculum) + certificate.
        NMC.prototype.buildFull = function (root) {
            root.innerHTML = '';
            var wrap = el('div', { 'class': 'nmc-wrap' });
            var header = el('header', { 'class': 'nmc-card nmc-header' });
            var player = el('section', { 'class': 'nmc-card nmc-player' });
            var info = el('section', { 'class': 'nmc-card' });
            var curriculum = el('section', { 'class': 'nmc-card nmc-cur' });
            var cert = el('section', { 'class': 'nmc-card' });
            var grades = el('section', { 'class': 'nmc-card nmc-grades' });

            var layout = el('div', { 'class': 'nmc-layout' });
            var main = el('div', { 'class': 'nmc-main' });
            var aside = el('aside', { 'class': 'nmc-side' });
            main.appendChild(player);
            main.appendChild(info);
            aside.appendChild(curriculum);
            layout.appendChild(main);
            layout.appendChild(aside);

            wrap.appendChild(header);
            wrap.appendChild(layout);
            wrap.appendChild(grades);
            if (this.d.certificate && this.d.certificate.enabled) {
                wrap.appendChild(cert);
            }
            root.appendChild(wrap);
            this.regions = { header: header, player: player, info: info, curriculum: curriculum, certificate: cert, grades: grades };
        };

        // Single section into its own root.
        NMC.prototype.buildPart = function (root, part) {
            var map = {
                header: ['header', 'nmc-card nmc-header'],
                player: ['section', 'nmc-card nmc-player'],
                info: ['section', 'nmc-card'],
                curriculum: ['section', 'nmc-card nmc-cur'],
                certificate: ['section', 'nmc-card'],
                grades: ['section', 'nmc-card nmc-grades']
            };
            var def = map[part];
            if (!def) return;
            if (part === 'certificate' && !(this.d.certificate && this.d.certificate.enabled)) {
                root.style.display = 'none';
                return;
            }
            var node = el(def[0], { 'class': def[1] });
            root.innerHTML = '';
            root.appendChild(node);
            this.regions[part] = node;
        };

        NMC.prototype.renderAll = function () {
            this.renderHeader();
            this.renderPlayer();
            this.renderInfo();
            this.renderSide();
            this.renderGrades();
            this.renderCert();
            this.renderMobile();
        };

        // ---- header ----
        NMC.prototype.renderHeader = function () {
            var host = this.regions.header; if (!host) return;
            var d = this.d, overall = this.overall();
            var total = this.flat.length;
            var completed = this.counted().filter(function (l) { return this.pctOf(l.id) >= 100; }, this).length;
            var C = 326.7, off = C * (1 - overall / 100);
            var certUnlocked = overall >= this.threshold();
            var certEnabled = d.certificate && d.certificate.enabled;

            var instr = d.instructor ? '<span class="nmc-instr">مدرس: ' + esc(d.instructor) + '</span>' : '';
            var sub = d.courseSubtitle ? '<p class="nmc-sub">' + esc(d.courseSubtitle) + '</p>' : '';

            host.innerHTML =
                '<div class="nmc-h-info">' +
                    '<div class="nmc-tagrow"><span class="nmc-pill">دوره آنلاین</span>' + instr + '</div>' +
                    '<h1 class="nmc-title">' + esc(d.courseTitle) + '</h1>' + sub +
                    '<div class="nmc-stats">' +
                        '<span class="nmc-stat">' + ICON.book + ' ' + fa(total) + ' درس</span>' +
                        '<span class="nmc-stat">' + ICON.clock + ' ' + fa(d.chapters.length) + ' فصل</span>' +
                        '<span class="nmc-stat ok">' + ICON.check + ' ' + fa(completed) + ' درس تکمیل‌شده</span>' +
                        (function (g) { return g ? '<button type="button" class="nmc-stat nmc-stat-q" data-act="gradescroll">' + ICON.quiz + ' ' + g + '</button>' : ''; })(this.gradeSummary()) +
                    '</div>' +
                '</div>' +
                '<div class="nmc-h-side">' +
                    '<div class="nmc-ring">' +
                        '<svg width="118" height="118" viewBox="0 0 118 118" style="transform:rotate(-90deg)">' +
                            '<circle cx="59" cy="59" r="52" fill="none" stroke="#eef1f4" stroke-width="11"></circle>' +
                            '<circle cx="59" cy="59" r="52" fill="none" stroke="' + this.accent + '" stroke-width="11" stroke-linecap="round" stroke-dasharray="326.7" stroke-dashoffset="' + off + '" style="transition:stroke-dashoffset .6s cubic-bezier(.4,0,.2,1)"></circle>' +
                        '</svg>' +
                        '<div class="nmc-ring-c"><span class="nmc-ring-pct">' + fa(overall) + '٪</span><span class="nmc-ring-lbl">پیشرفت کل</span></div>' +
                    '</div>' +
                    (certEnabled ? '<button type="button" class="nmc-certbtn ' + (certUnlocked ? 'on' : 'off') + '" data-act="certscroll">' + ICON.award + '<span>' + (certUnlocked ? 'دریافت گواهی' : 'گواهی (قفل)') + '</span></button>' : '') +
                '</div>';

            var self = this;
            var gs = host.querySelector('[data-act="gradescroll"]');
            if (gs) gs.addEventListener('click', function () { var g = self.regions.grades; if (g && g.parentNode) { window.scrollTo({ top: g.getBoundingClientRect().top + window.pageYOffset - 20, behavior: 'smooth' }); } });
            var cb = host.querySelector('[data-act="certscroll"]');
            if (cb) cb.addEventListener('click', function () {
                var cert = self.regions.certificate;
                if (cert && cert.parentNode) {
                    window.scrollTo(0, cert.getBoundingClientRect().top + window.pageYOffset - 20);
                }
            });
        };

        // ---- player ----
        NMC.prototype.renderPlayer = function () {
            var host = this.regions.player; if (!host) return;
            var cur = this.cur(), t = this.playerType(cur), self = this;
            var stage = el('div', { 'class': 'nmc-stage' });

            // One player at a time: destroying it also flushes its watch map.
            if (this._player) { this._player.destroy(); this._player = null; }

            if (t === 'video') {
                this._video = null;
                var w = this.state.watch[cur.id], lid = cur.id;
                // Aspect ratio from the player settings (16:9 by default).
                var ratio = (window.NIAS_PLAYER_CONFIG && window.NIAS_PLAYER_CONFIG.ratio) || '16:9';
                if (ratio !== '16:9') { stage.style.aspectRatio = ratio.replace(':', '/'); }
                // End screen: the next lesson that has something to show.
                var nidx = this.flat.indexOf(cur), nextL = null;
                for (var nj = nidx + 1; nj < this.flat.length; nj++) {
                    var nt = this.playerType(this.flat[nj]);
                    if (nt !== 'empty' && nt !== 'locked') { nextL = this.flat[nj]; break; }
                }
                this._player = window.NiasPlayer.create(stage, {
                    src: cur.videoSrc,
                    accent: this.accent,
                    next: nextL ? { title: nextL.title, go: function () { self.selectLesson(nextL.id, true); } } : null,
                    autoplay: this._autoplayNext ? true : null,
                    startAt: this.state.times[cur.id] || 0,
                    watch: (w && w.m) ? { n: w.n, m: w.m } : null,
                    downloadUrl: cur.downloadUrl || '',
                    onProgress: function (info) { self.onWatch(lid, info); },
                    onEnded: function (info) { self.onWatch(lid, info); }
                });
                this._autoplayNext = false;
            } else if (t === 'iframe') {
                stage.appendChild(el('iframe', { src: cur.videoSrc, allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen', allowfullscreen: '' }));
            } else if (t === 'audio') {
                stage.className = 'nmc-stage is-audio';
                this.buildAudio(stage, cur);
            } else if (t === 'embedcode') {
                // Raw embed snippet pasted by the admin (iframe/style/script).
                var emb = el('div', { 'class': 'nmc-embed' });
                emb.innerHTML = cur.videoEmbed;
                stage.appendChild(emb);
            } else if (t === 'text') {
                var box = el('div', { 'class': 'nmc-text nmc-scroll' });
                box.innerHTML = '<div class="nmc-text-in"><div class="nmc-text-tag">' + ICON.text + '<span>درس متنی</span></div><div class="nmc-text-body">' + cur.content + '</div></div>';
                stage.appendChild(box);
            } else if (t === 'download') {
                var dl = el('div', { 'class': 'nmc-cover dl' });
                dl.innerHTML =
                    '<div class="nmc-cover-ic dl">' + ICON.fileBig + '</div>' +
                    '<div class="nmc-cover-t">' + esc(cur.downloadName) + '</div>' +
                    '<div class="nmc-cover-m">' + esc(cur.duration || 'فایل دانلودی') + '</div>' +
                    '<a class="nmc-cover-btn dl" href="' + esc(cur.downloadUrl) + '" target="_blank" rel="noopener">' + ICON.download + ' دانلود فایل</a>';
                stage.appendChild(dl);
            } else if (t === 'quiz') {
                stage.className = 'nmc-stage nmc-stage-soft nmc-stage-quiz';
                this.mountQuiz(stage, cur);
            } else if (t === 'gated') {
                stage.className = 'nmc-stage nmc-stage-soft';
                stage.appendChild(this.gateCard(cur));
            } else if (t === 'locked') {
                var lk = el('div', { 'class': 'nmc-cover lock' });
                lk.innerHTML =
                    '<div class="nmc-cover-ic lock">' + ICON.lockBig + '</div>' +
                    '<div class="nmc-cover-t">این درس خصوصی است</div>' +
                    // Free «دوره»: a login is enough; a product needs a purchase.
                    '<div class="nmc-cover-m">' + (this.d.free ? 'این دوره رایگان است؛ برای دیدن این درس وارد حساب کاربری شوید.' : 'برای دسترسی به این درس باید دوره را تهیه کنید.') + '</div>' +
                    '<a class="nmc-cover-btn buy" href="' + esc(this.d.buyUrl) + '">' + (this.d.free ? 'ورود / ثبت‌نام' : 'تهیه دوره') + '</a>';
                stage.appendChild(lk);
            } else {
                // Nothing published for this lesson yet: a compact, light card
                // (not a big black video frame) that points to the next lesson.
                stage.className = 'nmc-stage nmc-stage-soft';
                var idx = this.flat.indexOf(cur), next = null;
                for (var ni = idx + 1; ni < this.flat.length; ni++) {
                    if (this.playerType(this.flat[ni]) !== 'empty') { next = this.flat[ni]; break; }
                }
                var em = el('div', { 'class': 'nmc-soon' });
                em.innerHTML =
                    '<div class="nmc-soon-ic">' + ICON.clock + '</div>' +
                    '<div class="nmc-soon-t">محتوای این درس به‌زودی منتشر می‌شود</div>' +
                    '<div class="nmc-soon-m">«' + esc(cur.title || 'این درس') + '» هنوز آماده نشده است؛ پس از انتشار، همین‌جا در دسترس خواهد بود.</div>' +
                    (next ? '<button type="button" class="nmc-soon-btn" data-act="soon-next"><span>ادامه با «' + esc(next.title) + '»</span>' + ICON.next + '</button>' : '');
                stage.appendChild(em);
                var nb = em.querySelector('[data-act="soon-next"]');
                if (nb) { nb.addEventListener('click', function () { self.selectLesson(next.id); }); }
            }

            host.classList.toggle('nmc-player-soft', t === 'empty' || t === 'quiz' || t === 'gated');
            host.innerHTML = '';
            host.appendChild(stage);

            // Execute any embed scripts (e.g. Aparat) that came in the lesson
            // text or the pasted preview embed snippet.
            if (t === 'text' || t === 'embedcode') { runScripts(stage); }
            // Init any nias-vp-wrap / nias-ap players inside the text content.
            if (window.NiasMedia) { window.NiasMedia.scan(stage); }

            // restore saved position for the audio element (video resumes inside NiasPlayer)
            if (t === 'audio') {
                var saved = this.state.times[cur.id];
                if (saved && this._video) { try { this._video.currentTime = saved; } catch (e) {} }
            } else {
                this._video = null;
            }
        };

        // ---- quizzes ----
        NMC.prototype.mountQuiz = function (host, l) {
            var q = l.quiz;
            if (!host || !q) return;
            if (!q.model || !window.NiasQuizStudent) {
                host.appendChild(el('div', { 'class': 'nmc-empty' }, this.d.purchased ? 'آزمون این جلسه در دسترس نیست.' : (this.d.free ? 'برای شرکت در آزمون این جلسه وارد حساب کاربری شوید.' : 'آزمون این جلسه مخصوص خریداران دوره است.')));
                return;
            }
            var box = el('div', { 'class': 'nmc-quiz' });
            host.appendChild(box);
            window.NiasQuizStudent.mount(box, q.model, Object.assign({}, q.config, { accent: this.accent }));
        };
        // Cover of a lesson that opens after passing quizzes.
        NMC.prototype.gateCard = function (l) {
            var self = this, reqs = l.gate || [];
            var rows = reqs.map(function (g) {
                var st = g.ok ? '<span class="nmc-gate-st ok">' + ICON.check + ' قبول</span>'
                    : (g.pending ? '<span class="nmc-gate-st wait">' + ICON.clock + ' در انتظار تصحیح</span>'
                    : (g.best == null ? '<span class="nmc-gate-st">شرکت نکرده‌اید</span>' : '<span class="nmc-gate-st no">نمرهٔ شما ' + fa(g.best) + '٪</span>'));
                var go = (!g.ok && g.lessonId) ? '<button type="button" class="nmc-soon-btn" data-gate-go="' + esc(g.lessonId) + '"><span>رفتن به آزمون</span>' + ICON.next + '</button>' : '';
                return '<div class="nmc-gate-row"><div class="nmc-gate-q"><b>' + esc(g.title) + '</b><span>حداقل نمرهٔ لازم: ' + fa(g.min) + '٪</span></div>' + st + go + '</div>';
            }).join('');
            var box = el('div', { 'class': 'nmc-soon nmc-gate' });
            box.innerHTML =
                '<div class="nmc-soon-ic nmc-gate-ic">' + ICON.lockBig + '</div>' +
                '<div class="nmc-soon-t">این جلسه پس از قبولی در آزمون باز می‌شود</div>' +
                '<div class="nmc-soon-m">برای دسترسی به «' + esc(l.title) + '» ابتدا در ' + (reqs.length > 1 ? 'آزمون‌های' : 'آزمون') + ' زیر به نمرهٔ لازم برسید.</div>' +
                '<div class="nmc-gate-list">' + rows + '</div>' +
                (this.d.loggedIn ? '' : '<div class="nmc-soon-m">برای ثبت نمره، وارد حساب کاربری شوید.</div>');
            box.querySelectorAll('[data-gate-go]').forEach(function (b) { b.addEventListener('click', function () { self.selectLesson(b.getAttribute('data-gate-go')); }); });
            return box;
        };
        NMC.prototype.gradeSummary = function () {
            var g = this.d.grades || [];
            if (!g.length) return '';
            var taken = g.filter(function (x) { return x.record && x.record.best != null; });
            if (!taken.length) return fa(g.length) + ' آزمون';
            var avg = Math.round(taken.reduce(function (a, x) { return a + x.record.best; }, 0) / taken.length);
            return 'میانگین آزمون‌ها ' + fa(avg) + '٪';
        };
        NMC.prototype.renderGrades = function () {
            var host = this.regions.grades; if (!host) return;
            var g = this.d.grades || [], self = this;
            if (!g.length) { host.style.display = 'none'; host.innerHTML = ''; return; }
            host.style.display = '';
            var taken = g.filter(function (x) { return x.record && x.record.best != null; }), passed = g.filter(function (x) { return x.record && x.record.passed; });
            var avg = taken.length ? Math.round(taken.reduce(function (a, x) { return a + x.record.best; }, 0) / taken.length) : null;
            var rows = g.map(function (x) {
                var r = x.record, noScore = !r || r.best == null;
                var col = noScore ? '#c2cacf' : (r.passed ? '#48af3b' : '#e05656');
                var st = !r ? '<span class="nmc-gr-st">شرکت نکرده</span>'
                    : (r.passed ? '<span class="nmc-gr-st ok">قبول</span>' : (r.best == null ? '<span class="nmc-gr-st wait">در انتظار تصحیح</span>' : '<span class="nmc-gr-st no">مردود</span>'));
                var where = x.final ? 'آزمون پایانی دوره' : (x.chapterTitle ? x.chapterTitle + ' › ' : '') + x.lessonTitle;
                var meta = [];
                if (r) {
                    meta.push(fa(r.attempts) + ' بار شرکت');
                    if (r.last !== r.best) meta.push('آخرین: ' + fa(r.last) + '٪');
                    if (r.pending && r.best != null) meta.push('تشریحی در انتظار تصحیح');
                }
                meta.push('قبولی از ' + fa(x.passScore) + '٪');
                if (x.attemptsLeft != null) meta.push(x.attemptsLeft ? fa(x.attemptsLeft) + ' فرصت دیگر' : 'فرصت شرکت تمام شده');
                var btn = (x.lessonId || x.final) ? '<button type="button" class="nmc-gr-go" data-gr="' + esc(x.lessonId || '') + '">' + (r ? 'مشاهده' : 'شرکت در آزمون') + '</button>' : '';
                return '<div class="nmc-gr-row">' +
                    '<span class="nmc-gr-ic" style="color:' + col + '">' + ICON.quiz + '</span>' +
                    '<div class="nmc-gr-main"><div class="nmc-gr-t">' + esc(x.title) + '</div><div class="nmc-gr-w">' + esc(where) + '</div><div class="nmc-gr-m">' + esc(meta.join(' · ')) + '</div></div>' +
                    '<div class="nmc-gr-s"><div class="nmc-gr-pct" style="color:' + col + '">' + (noScore ? '—' : fa(r.best) + '٪') + '</div>' +
                        '<div class="nmc-gr-bar"><span style="width:' + (noScore ? 0 : r.best) + '%;background:' + col + '"></span><i style="right:' + x.passScore + '%"></i></div>' + st + '</div>' +
                    btn + '</div>';
            }).join('');
            host.innerHTML =
                '<div class="nmc-gr-head">' + ICON.quiz + '<h3>کارنامهٔ آزمون‌ها</h3>' +
                    '<span class="nmc-gr-sum">' + fa(passed.length) + ' از ' + fa(g.length) + ' قبول' + (avg != null ? ' · میانگین ' + fa(avg) + '٪' : '') + '</span></div>' +
                (this.d.loggedIn ? '' : '<div class="nmc-empty" style="padding:14px 20px">برای ثبت و دیدن نمرات، وارد حساب کاربری شوید.</div>') +
                '<div class="nmc-gr-list">' + rows + '</div>';
            host.querySelectorAll('[data-gr]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var lid = b.getAttribute('data-gr');
                    if (lid) {
                        var l = self.flat.find(function (x) { return x.id === lid; });
                        if (l && l.quiz && self.playerType(l) !== 'quiz') { self.state.tabNext = 'quiz'; }
                        self.selectLesson(lid);
                        return;
                    }
                    var fq = document.querySelector('.nqs-course-wrap');
                    if (fq) { window.scrollTo({ top: fq.getBoundingClientRect().top + window.pageYOffset - 20, behavior: 'smooth' }); }
                });
            });
        };

        // ---- audio player (waveform) ----
        // A custom, RTL-friendly audio player for lessons whose media is an
        // audio file. Shares the video progress handlers (onVideoTime/-Loaded/
        // -Ended) via a hidden <audio> element, so watch progress, resume and
        // completion all work exactly like video lessons.
        NMC.prototype.buildAudio = function (stage, cur) {
            var self = this, NB = 56;
            var au = el('div', { 'class': 'nmc-audio' });

            var bars = '';
            for (var bi = 0; bi < NB; bi++) {
                // Deterministic pseudo-random bar heights (stable per position).
                var r = Math.abs(Math.sin((bi + 1) * 12.9898) * 43758.5453);
                r = r - Math.floor(r);
                bars += '<span class="nmc-audio-bar" data-i="' + bi + '" style="height:' + Math.round(22 + r * 78) + '%"></span>';
            }

            au.innerHTML =
                '<div class="nmc-audio-art">' + ICON.audioBig + '</div>' +
                '<div class="nmc-audio-name">' + esc(cur.title) + '</div>' +
                // Media controls read left→right (like the video player): the
                // timeline starts at the current time and ends at the duration.
                '<div class="nmc-audio-track"><span class="nmc-audio-t cur">۰:۰۰</span>' +
                    '<div class="nmc-audio-wave" role="slider" aria-label="نوار پخش صوت">' + bars + '</div>' +
                    '<span class="nmc-audio-t dur">۰:۰۰</span></div>' +
                '<div class="nmc-audio-controls">' +
                    '<button type="button" class="nmc-audio-skip" data-act="back" aria-label="۱۵ ثانیه عقب">' + ICON.back15 + '</button>' +
                    '<button type="button" class="nmc-audio-btn" data-act="toggle" aria-label="پخش/توقف">' + ICON.playBig + '</button>' +
                    '<button type="button" class="nmc-audio-skip" data-act="fwd" aria-label="۱۵ ثانیه جلو">' + ICON.fwd15 + '</button>' +
                    '<button type="button" class="nmc-audio-rate" data-act="rate" aria-label="سرعت پخش">' + fa(1) + '×</button>' +
                '</div>';

            var a = el('audio', { src: cur.videoSrc, preload: 'metadata' });
            au.appendChild(a);
            stage.appendChild(au);
            this._video = a;

            var btn = au.querySelector('[data-act="toggle"]');
            var wave = au.querySelector('.nmc-audio-wave');
            var curEl = au.querySelector('.cur');
            var durEl = au.querySelector('.dur');
            var barEls = au.querySelectorAll('.nmc-audio-bar');

            function fmt(t) {
                t = Math.max(0, Math.floor(t || 0));
                var m = Math.floor(t / 60), s = t % 60;
                return fa(m) + ':' + fa(s < 10 ? '0' + s : s);
            }
            function paint() {
                var d = a.duration || 0, lit = d ? Math.round(a.currentTime / d * NB) : 0;
                for (var i = 0; i < barEls.length; i++) {
                    if ((i < lit) !== barEls[i].classList.contains('on')) {
                        barEls[i].classList.toggle('on', i < lit);
                    }
                }
                curEl.textContent = fmt(a.currentTime);
            }

            btn.addEventListener('click', function () { if (a.paused) { a.play(); } else { a.pause(); } });
            au.querySelector('[data-act="back"]').addEventListener('click', function () { try { a.currentTime = Math.max(0, a.currentTime - 15); } catch (e) {} paint(); });
            au.querySelector('[data-act="fwd"]').addEventListener('click', function () { if (a.duration) { try { a.currentTime = Math.min(a.duration - 0.1, a.currentTime + 15); } catch (e) {} paint(); } });
            var rates = [1, 1.25, 1.5, 1.75, 2, 0.75], rateBtn = au.querySelector('[data-act="rate"]');
            rateBtn.addEventListener('click', function () {
                var i = (rates.indexOf(a.playbackRate) + 1) % rates.length;
                a.playbackRate = rates[i];
                rateBtn.textContent = fa(rates[i]) + '×';
                rateBtn.classList.toggle('on', rates[i] !== 1);
            });
            a.addEventListener('play', function () { au.classList.add('playing'); btn.innerHTML = ICON.pauseBig; });
            a.addEventListener('pause', function () { au.classList.remove('playing'); btn.innerHTML = ICON.playBig; });
            a.addEventListener('loadedmetadata', function (e) { durEl.textContent = fmt(a.duration); self.onVideoLoaded(e); paint(); });
            a.addEventListener('timeupdate', function (e) { self.onVideoTime(e); paint(); });
            a.addEventListener('ended', function () { self.onVideoEnded(); paint(); });
            wave.addEventListener('click', function (ev) {
                if (!a.duration) { return; }
                var r = wave.getBoundingClientRect(), f = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
                try { a.currentTime = f * a.duration; } catch (e) {}
                paint();
            });
        };

        // ---- info bar ----
        NMC.prototype.renderInfo = function () {
            var host = this.regions.info; if (!host) return;
            var cur = this.cur(), self = this;
            var idx = this.flat.findIndex(function (l) { return l.id === cur.id; });
            var done = this.pctOf(cur.id) >= 100;
            var ctype = this.playerType(cur);
            // Quiz lessons complete by passing the quiz, not by a click.
            var completeDisabled = cur.locked || ctype === 'empty' || ctype === 'quiz';
            var quizTab = !!cur.quiz && ctype !== 'quiz' && ctype !== 'gated';
            if (this.state.tab === 'quiz' && !quizTab) { this.state.tab = 'desc'; }

            var resources = [];
            if (cur.downloadUrl) resources.push({ name: cur.downloadName, meta: cur.duration || 'فایل دانلودی', url: cur.downloadUrl });

            var tab = this.state.tab;
            var body;
            if (tab === 'quiz') {
                body = '<div class="nmc-quiz-tab"></div>';
            } else if (tab === 'desc') {
                if (cur.lockedDesc) {
                    // Description withheld server-side because the lesson is private
                    // and "lock content" is enabled — show a clear private notice.
                    body = '<div class="nmc-empty">' + (this.d.free ? 'توضیحات این درس خصوصی است؛ برای مشاهده وارد حساب کاربری شوید.' : 'توضیحات این درس خصوصی است؛ برای مشاهده باید دوره را تهیه کنید.') + '</div>';
                } else {
                    var descHtml = (this.playerType(cur) === 'text') ? '' : (cur.content || '');
                    body = descHtml ? '<div class="nmc-desc">' + descHtml + '</div>'
                                    : '<div class="nmc-empty">برای این درس توضیحی ثبت نشده است.</div>';
                }
            } else if (cur.lockedRes) {
                // Resources/attachments withheld server-side for a private lesson.
                body = '<div class="nmc-empty">' + (this.d.free ? 'منابع و پیوست این درس خصوصی است؛ برای دسترسی وارد حساب کاربری شوید.' : 'منابع و پیوست این درس خصوصی است؛ برای دسترسی باید دوره را تهیه کنید.') + '</div>';
            } else {
                if (resources.length) {
                    body = '<div class="nmc-reslist">' + resources.map(function (r) {
                        var isPdf = /\.pdf(\?|$)/i.test(r.url);
                        var bg = isPdf ? 'rgba(255,96,96,.12)' : 'rgba(30,131,240,.1)';
                        var col = isPdf ? '#e05656' : self.accent;
                        return '<a class="nmc-res" href="' + esc(r.url) + '" target="_blank" rel="noopener">' +
                            '<span class="nmc-res-ic" style="background:' + bg + ';color:' + col + '">' + ICON.download + '</span>' +
                            '<span class="nmc-res-meta"><span class="nmc-res-name">' + esc(r.name) + '</span><span class="nmc-res-sub">' + esc(r.meta) + '</span></span>' +
                            '<span style="color:#9aa4a9">' + ICON.download + '</span></a>';
                    }).join('') + '</div>';
                } else {
                    body = '<div class="nmc-empty">برای این درس پیوستی ثبت نشده است.</div>';
                }
            }

            host.innerHTML =
                '<div class="nmc-info-top">' +
                    '<div class="nmc-info-l">' +
                        '<div class="nmc-info-tags">' +
                            '<span class="nmc-chip">' + this.typeIcon(cur) + ' ' + esc(cur.label || this.typeLabel(cur)) + '</span>' +
                            (cur.duration ? '<span class="nmc-chip muted">' + ICON.clock + ' ' + esc(cur.duration) + '</span>' : '') +
                        '</div>' +
                        '<h2 class="nmc-info-title">' + esc(cur.title) + '</h2>' +
                    '</div>' +
                    '<div class="nmc-nav">' +
                        '<button type="button" class="nmc-btn ' + (idx <= 0 ? 'off' : '') + '" data-act="prev">' + ICON.prev + '<span>درس قبلی</span></button>' +
                        '<button type="button" class="nmc-btn ' + (done ? 'done' : (completeDisabled ? 'off' : 'primary')) + '" data-act="complete">' + (done ? ICON.checkC : ICON.circle) + '<span>' + (done ? 'تکمیل شد' : (ctype === 'quiz' ? 'با قبولی در آزمون تکمیل می‌شود' : 'علامت‌گذاری به‌عنوان دیده‌شده')) + '</span></button>' +
                        '<button type="button" class="nmc-btn ' + (idx >= this.flat.length - 1 ? 'off' : '') + '" data-act="next"><span>درس بعدی</span>' + ICON.next + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="nmc-tabs">' +
                    '<button type="button" class="nmc-tab ' + (tab === 'desc' ? 'active' : '') + '" data-act="tabdesc">توضیحات درس</button>' +
                    '<button type="button" class="nmc-tab ' + (tab === 'res' ? 'active' : '') + '" data-act="tabres">منابع و پیوست‌ها</button>' +
                    (quizTab ? '<button type="button" class="nmc-tab nmc-tab-quiz ' + (tab === 'quiz' ? 'active' : '') + '" data-act="tabquiz">' + ICON.quiz + '<span>آزمون جلسه</span>' + (function (r) { return r && r.best != null ? '<span class="nmc-tab-score ' + (r.passed ? 'ok' : 'no') + '">' + fa(r.best) + '٪</span>' : ''; })(this.quizRec(cur)) + '</button>' : '') +
                '</div>' +
                '<div class="nmc-tabbody">' + body + '</div>';

            // Run embed scripts (e.g. Aparat) pasted into the description tab.
            runScripts(host.querySelector('.nmc-tabbody'));
            // Init any nias-vp-wrap / nias-ap players inside the description tab.
            if (window.NiasMedia) { window.NiasMedia.scan(host.querySelector('.nmc-tabbody')); }

            host.querySelector('[data-act="prev"]').addEventListener('click', function () { self.go(-1); });
            host.querySelector('[data-act="next"]').addEventListener('click', function () { self.go(1); });
            host.querySelector('[data-act="complete"]').addEventListener('click', function () { self.markComplete(); });
            host.querySelector('[data-act="tabdesc"]').addEventListener('click', function () { self.state.tab = 'desc'; self.renderInfo(); });
            host.querySelector('[data-act="tabres"]').addEventListener('click', function () { self.state.tab = 'res'; self.renderInfo(); });
            var tq = host.querySelector('[data-act="tabquiz"]');
            if (tq) tq.addEventListener('click', function () { self.state.tab = 'quiz'; self.renderInfo(); });
            if (tab === 'quiz') { this.mountQuiz(host.querySelector('.nmc-quiz-tab'), cur); }
        };

        // ---- sidebar ----
        // One lesson row, shared by the side list and the mobile sheet.
        NMC.prototype.lessonRow = function (l, query) {
            var self = this, lp = this.pctOf(l.id), sel = l.id === this.state.currentId, doneL = lp >= 100;
            var status;
            if (l.locked) status = '<span class="nmc-les-status"><span class="dot" style="background:#f1f4f6;color:#a9b3b8">' + ICON.lock + '</span></span>';
            else if (doneL) status = '<span class="nmc-les-status">' + ICON.checkC + '</span>';
            else status = '<span class="nmc-les-status" style="color:' + (lp > 0 ? this.accent : '#c2cacf') + '">' + fa(lp) + '٪</span>';
            var lt = this.playerType(l), qr = this.quizRec(l);
            var pctLbl = lt === 'gated' ? 'قفل تا قبولی در آزمون' : (l.locked ? 'خصوصی' : (doneL ? 'تکمیل‌شده' : (lp > 0 ? fa(lp) + '٪ مشاهده' : (lt === 'empty' ? 'به‌زودی' : (lt === 'quiz' ? (qr ? (qr.best == null ? 'منتظر نمرهٔ مدرس' : 'هنوز قبول نشده') : 'شرکت نکرده‌اید') : 'شروع نشده')))));
            if (lt === 'gated') { status = '<span class="nmc-les-status"><span class="dot" style="background:#f3eefe;color:#7a4fd0">' + ICON.lock + '</span></span>'; }
            var qWait = qr && qr.best == null;
            var quizChip = (l.quiz && lt !== 'gated') ? '<span class="nmc-les-q ' + (qr ? (qr.passed ? 'ok' : (qWait ? 'wait' : 'no')) : '') + '">' + ICON.quiz + '<span>' + (qr ? (qWait ? 'در انتظار تصحیح' : fa(qr.best) + '٪') : 'آزمون') + '</span></span>' : '';
            var pctCol = doneL ? '#48af3b' : (lp > 0 ? this.accent : '#9aa4a9');
            var title = esc(l.title);
            var qn = query ? skey(query).trim().length : 0;
            if (qn) {
                var i = smatch(l.title, query);
                if (i > -1) { title = esc(l.title.slice(0, i)) + '<mark class="nmc-mark">' + esc(l.title.slice(i, i + qn)) + '</mark>' + esc(l.title.slice(i + qn)); }
            }
            return '<button type="button" class="nmc-les ' + (sel ? 'sel' : '') + '" data-lesson="' + esc(l.id) + '"' + (sel ? ' aria-current="true"' : '') + '>' +
                '<span class="nmc-les-ic ' + (l.locked ? 'lock' : '') + '">' + self.typeIcon(l) + '</span>' +
                '<span class="nmc-les-body">' +
                    '<span class="nmc-les-row1"><span class="nmc-les-title">' + title + '</span><span class="nmc-les-badge">' + esc(l.label || self.typeLabel(l)) + '</span></span>' +
                    '<span class="nmc-les-row2">' + (sel ? '<span class="nmc-les-now"><i></i>در حال مشاهده</span>' : '') + (l.duration ? '<span class="nmc-les-dur">' + ICON.clock + ' ' + esc(l.duration) + '</span>' : '') + '<span class="nmc-les-pct" style="color:' + (lt === 'gated' ? '#7a4fd0' : pctCol) + '">' + pctLbl + '</span>' + quizChip + (self._justOpened && self._justOpened[l.id] ? '<span class="nmc-les-new">تازه باز شد</span>' : '') + '</span>' +
                '</span>' + status +
            '</button>';
        };
        /** First lesson that is accessible, has content and is not finished. */
        /** First unfinished lesson other than the one already open (it has content and is accessible). */
        NMC.prototype.nextUnfinished = function () {
            var self = this, curId = this.state.currentId;
            return this.counted().filter(function (l) { return l.id !== curId && self.pctOf(l.id) < 100; })[0] || null;
        };
        NMC.prototype.continueHtml = function () {
            var n = this.nextUnfinished();
            if (!n) return '';
            var started = this.pctOf(n.id) > 0;
            return '<button type="button" class="nmc-continue" data-continue="' + esc(n.id) + '"><span class="ic">' + ICON.play + '</span>' +
                '<span class="tx"><b>' + (started ? 'ادامهٔ یادگیری' : 'شروع درس بعدی') + '</b><span>' + esc(n.title) + '</span></span></button>';
        };

        NMC.prototype.renderSide = function () {
            var host = this.regions.curriculum; if (!host) return;
            var d = this.d, self = this, q = this._sideQ || '';
            var hadFocus = !!(document.activeElement && document.activeElement.closest && document.activeElement.closest('.nmc-side-search'));
            var head =
                '<div class="nmc-cur-head"><h3>سرفصل‌های دوره</h3>' +
                '<span class="nmc-cur-meta">' + fa(d.chapters.length) + ' فصل · ' + fa(this.flat.length) + ' درس</span></div>' +
                '<div class="nmc-side-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
                    '<input type="text" placeholder="جستجوی جلسه…" aria-label="جستجوی جلسه" autocomplete="off" enterkeyhint="search" value="' + esc(q) + '">' +
                    '<button type="button" class="nmc-side-clear" aria-label="پاک کردن جستجو"' + (q ? '' : ' hidden') + '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>' +
                '</div>' +
                '<div class="nmc-side-cont"></div>';
            host.innerHTML = head + '<div class="nmc-cur-list nmc-scroll"></div>';

            var input = host.querySelector('.nmc-side-search input'), clear = host.querySelector('.nmc-side-clear');
            var apply = function () {
                self._sideQ = input.value;
                clear.hidden = !input.value;
                self.renderSideList(true);
            };
            input.addEventListener('input', apply);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && input.value) { ev.preventDefault(); input.value = ''; apply(); }
                else if (ev.key === 'Enter') {
                    // Enter opens the first result.
                    ev.preventDefault();
                    var first = host.querySelector('.nmc-cur-list [data-lesson]');
                    if (first && skey(input.value).trim()) { first.click(); }
                }
            });
            clear.addEventListener('click', function () { input.value = ''; apply(); input.focus(); });
            if (hadFocus) { input.focus(); try { input.setSelectionRange(q.length, q.length); } catch (e) {} }
            this.renderSideList(false);
        };

        /** The chapter/lesson list under the search box. Typing only re-renders this part. */
        NMC.prototype.renderSideList = function (fromSearch) {
            var host = this.regions.curriculum; if (!host) return;
            var listHost = host.querySelector('.nmc-cur-list'); if (!listHost) return;
            var d = this.d, self = this, q = this._sideQ || '', searching = !!skey(q).trim();
            var restoreScroll = { x: window.pageXOffset || 0, y: window.pageYOffset || 0 };

            var cont = host.querySelector('.nmc-side-cont'), c = searching ? '' : this.continueHtml();
            cont.innerHTML = c ? '<div style="padding:0 18px 12px">' + c + '</div>' : '';

            var list = '', hits = 0;
            d.chapters.forEach(function (ch, ci) {
                var lessons = searching ? ch.lessons.filter(function (l) { return smatch(l.title, q) > -1; }) : ch.lessons;
                if (searching && !lessons.length) return;
                hits += lessons.length;
                var open = searching || !!self.state.open[ci];
                var counted = ch.lessons.filter(function (l) { return !l.locked; });
                var doneN = counted.filter(function (l) { return self.pctOf(l.id) >= 100; }).length;
                var pct = counted.length ? Math.round(counted.reduce(function (a, l) { return a + self.pctOf(l.id); }, 0) / counted.length) : 0;
                var numCls = pct >= 100 ? 'done' : (open ? 'open' : '');
                var hasCur = ch.lessons.some(function (l) { return l.id === self.state.currentId; });
                list +=
                    '<div class="nmc-ch">' +
                        '<button type="button" class="nmc-ch-head ' + (open ? 'open' : '') + (hasCur ? ' has-cur' : '') + '" data-ch="' + ci + '"' + (searching ? ' disabled' : '') + '>' +
                            '<span class="nmc-ch-num ' + numCls + '">' + fa(ci + 1) + '</span>' +
                            '<span class="nmc-ch-body"><span class="nmc-ch-title">' + esc(ch.title) + (hasCur && !open ? '<span class="nmc-ch-cur">جلسهٔ فعلی</span>' : '') + '</span>' +
                                '<span class="nmc-ch-prog"><span class="nmc-bar"><span style="width:' + pct + '%"></span></span>' +
                                '<span class="nmc-ch-done">' + (searching ? fa(lessons.length) + ' نتیجه' : fa(doneN) + ' از ' + fa(counted.length)) + '</span></span>' +
                            '</span>' +
                            (searching ? '' : '<span class="nmc-ch-caret ' + (open ? 'open' : '') + '">' + ICON.caret + '</span>') +
                        '</button>' +
                        (open ? '<div class="nmc-ch-lessons">' + lessons.map(function (l) { return self.lessonRow(l, searching ? q : ''); }).join('') + '</div>' : '') +
                    '</div>';
            });
            if (searching) {
                list = hits ? '<div class="nmc-side-hits">' + fa(hits) + ' جلسه پیدا شد</div>' + list
                            : '<div class="nmc-side-empty">جلسه‌ای با «' + esc(q.trim()) + '» پیدا نشد.</div>';
            }
            listHost.innerHTML = list;

            if (searching) { listHost.scrollTop = 0; }
            else {
                listHost.scrollTop = this._sideTop || 0;
                // Keep the lesson being watched in view (only the list scrolls, never the page).
                if (this._sideCur !== this.state.currentId || fromSearch) {
                    var selRow = listHost.querySelector('.nmc-les.sel');
                    if (selRow && listHost.scrollHeight > listHost.clientHeight + 2) {
                        var lr = listHost.getBoundingClientRect(), rr = selRow.getBoundingClientRect();
                        if (rr.top < lr.top + 8 || rr.bottom > lr.bottom - 8) {
                            listHost.scrollTop += (rr.top - lr.top) - (lr.height - rr.height) / 2;
                        }
                    }
                    this._sideCur = this.state.currentId;
                }
                this._sideTop = listHost.scrollTop;
            }
            if (window.scrollTo) { window.scrollTo(restoreScroll.x, restoreScroll.y); }
            if (!listHost._nmcScroll) {
                listHost._nmcScroll = true;
                listHost.addEventListener('scroll', function () { if (!skey(self._sideQ || '').trim()) { self._sideTop = listHost.scrollTop; } }, { passive: true });
            }

            listHost.querySelectorAll('[data-ch]').forEach(function (b) {
                b.addEventListener('click', function (ev) {
                    ev.preventDefault(); ev.stopPropagation(); b.blur();
                    var ci = parseInt(b.getAttribute('data-ch'), 10);
                    self.state.open[ci] = !self.state.open[ci];
                    self.persist();
                    self.renderSideList();
                });
            });
            listHost.querySelectorAll('[data-lesson]').forEach(function (b) {
                b.addEventListener('click', function (ev) {
                    ev.preventDefault(); ev.stopPropagation(); b.blur();
                    self.selectLesson(b.getAttribute('data-lesson'));
                });
            });
            var cb = cont.querySelector('[data-continue]');
            if (cb) { cb.addEventListener('click', function () { self.selectLesson(cb.getAttribute('data-continue')); }); }
        };

        // ---- mobile: bottom bar, lesson sheet, mini player ----
        NMC.prototype.isMobile = function () { return window.matchMedia && window.matchMedia('(max-width: 900px)').matches; };

        NMC.prototype.setupMobile = function (fullView) {
            var self = this;
            this._mobile = { query: '', filter: 'all', open: {}, isOpen: false };
            // Closing the sheet steps back in history; without this the browser
            // would restore the old scroll position and undo "scroll to player".
            try { if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; } } catch (e) {}
            var wrap = this.regions.player && this.regions.player.closest ? this.regions.player.closest('.nmc-wrap') : null;
            if (fullView && wrap) { wrap.classList.add('nmc-has-sheet'); }
            var bar = el('div', { 'class': 'nmc-mbar', role: 'navigation', 'aria-label': 'پیمایش درس‌ها' });
            bar.style.setProperty('--nmc-accent', this.accent);
            bar.innerHTML =
                '<button type="button" class="nmc-mbar-nav" data-m="prev" aria-label="درس قبلی">' + ICON.prev + '</button>' +
                '<button type="button" class="nmc-mbar-main" data-m="open" aria-haspopup="dialog"><span class="t"><b>سرفصل‌ها</b><span></span></span>' + ICON.caret + '</button>' +
                '<button type="button" class="nmc-mbar-nav" data-m="next" aria-label="درس بعدی">' + ICON.next + '</button>';
            var bg = el('div', { 'class': 'nmc-sheet-bg' });
            var sh = el('div', { 'class': 'nmc-sheet', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'سرفصل‌های دوره' });
            sh.style.setProperty('--nmc-accent', this.accent);
            sh.innerHTML =
                '<div class="nmc-sheet-head">' +
                    '<div class="nmc-sheet-grip"></div>' +
                    '<div class="nmc-sheet-top"><div><h3>سرفصل‌های دوره</h3><small></small></div>' +
                    '<button type="button" class="nmc-sheet-x" data-m="close" aria-label="بستن"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>' +
                    '<div class="nmc-sheet-cont"></div>' +
                    '<div class="nmc-sheet-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" placeholder="جستجوی درس…" aria-label="جستجوی درس" enterkeyhint="search"></div>' +
                    '<div class="nmc-sheet-chips">' +
                        '<button type="button" class="on" data-f="all">همه</button><button type="button" data-f="unseen">دیده‌نشده</button>' +
                        '<button type="button" data-f="progress">ناتمام</button><button type="button" data-f="done">تکمیل‌شده</button>' +
                    '</div>' +
                '</div>' +
                '<div class="nmc-sheet-list"></div>';
            document.body.appendChild(bg);
            document.body.appendChild(sh);
            document.body.appendChild(bar);
            document.body.classList.add('nmc-has-mbar');
            this._mbar = bar; this._sheet = sh; this._sheetBg = bg;

            bar.addEventListener('click', function (e) {
                var b = e.target.closest('[data-m]'); if (!b) return;
                var m = b.getAttribute('data-m');
                if (m === 'open') { self.openSheet(); }
                else if (m === 'prev') { self.go(-1); }
                else if (m === 'next') { self.go(1); }
            });
            bg.addEventListener('click', function () { self.closeSheet(); });
            sh.querySelector('[data-m="close"]').addEventListener('click', function () { self.closeSheet(); });
            var input = sh.querySelector('input');
            input.addEventListener('input', function () { self._mobile.query = input.value.trim(); self.renderSheet(); });
            sh.querySelectorAll('[data-f]').forEach(function (c) {
                c.addEventListener('click', function () {
                    self._mobile.filter = c.getAttribute('data-f');
                    sh.querySelectorAll('[data-f]').forEach(function (x) { x.classList.toggle('on', x === c); });
                    self.renderSheet();
                });
            });
            sh.addEventListener('click', function (e) {
                var ch = e.target.closest('[data-sch]');
                if (ch) { var ci = ch.getAttribute('data-sch'); self._mobile.open[ci] = !self._mobile.open[ci]; self.renderSheet(); return; }
                var l = e.target.closest('[data-lesson],[data-continue]');
                if (l) { var id = l.getAttribute('data-lesson') || l.getAttribute('data-continue'); self.closeSheet(); self.selectLesson(id); }
            });
            // Swipe the header down to close.
            var head = sh.querySelector('.nmc-sheet-head'), y0 = null, dy = 0;
            head.addEventListener('touchstart', function (e) { if (e.target.closest('input,button')) return; y0 = e.touches[0].clientY; dy = 0; sh.style.transition = 'none'; }, { passive: true });
            head.addEventListener('touchmove', function (e) { if (y0 === null) return; dy = Math.max(0, e.touches[0].clientY - y0); sh.style.transform = 'translateY(' + dy + 'px)'; }, { passive: true });
            head.addEventListener('touchend', function () { if (y0 === null) return; sh.style.transition = ''; sh.style.transform = ''; if (dy > 90) { self.closeSheet(); } y0 = null; });
            // Phone back button closes the sheet instead of leaving the course.
            window.addEventListener('popstate', function () { if (self._mobile.isOpen) { self.closeSheet(true); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self._mobile.isOpen) { self.closeSheet(); } });

            this.setupMiniPlayer();
            this.renderMobile();
        };

        NMC.prototype.renderMobile = function () {
            if (!this._mobile || !this._mbar) return;
            var cur = this.cur(), idx = this.flat.indexOf(cur);
            this._mbar.querySelector('.nmc-mbar-main .t span').textContent = 'درس ' + fa(idx + 1) + ' از ' + fa(this.flat.length) + ' · ' + (cur ? cur.title : '');
            this._mbar.querySelector('[data-m="prev"]').disabled = idx <= 0;
            this._mbar.querySelector('[data-m="next"]').disabled = idx >= this.flat.length - 1;
            if (this._mobile.isOpen) { this.renderSheet(); }
        };

        NMC.prototype.sheetMatch = function (l) {
            var m = this._mobile, p = this.pctOf(l.id);
            if (m.query && smatch(l.title, m.query) < 0) return false;
            if (m.filter === 'unseen') return p === 0 && !l.locked && this.playerType(l) !== 'empty';
            if (m.filter === 'progress') return p > 0 && p < 100;
            if (m.filter === 'done') return p >= 100;
            return true;
        };

        NMC.prototype.renderSheet = function () {
            var self = this, sh = this._sheet, m = this._mobile, searching = !!m.query || m.filter !== 'all';
            var done = this.counted().filter(function (l) { return self.pctOf(l.id) >= 100; }).length;
            sh.querySelector('.nmc-sheet-top small').textContent = fa(this.d.chapters.length) + ' فصل · ' + fa(this.flat.length) + ' درس · ' + fa(done) + ' تکمیل‌شده';
            sh.querySelector('.nmc-sheet-cont').innerHTML = searching ? '' : this.continueHtml();
            var html = '', any = false;
            this.d.chapters.forEach(function (ch, ci) {
                var lessons = self.flat.filter(function (l) { return l.ci === ci && self.sheetMatch(l); });
                if (!lessons.length) return;
                any = true;
                var cnt = self.flat.filter(function (l) { return l.ci === ci && !l.locked && self.playerType(l) !== 'empty'; });
                var dn = cnt.filter(function (l) { return self.pctOf(l.id) >= 100; }).length;
                var open = searching || !!m.open[ci];
                html += '<div class="nmc-ch"><button type="button" class="nmc-ch-head ' + (open ? 'open' : '') + '" data-sch="' + ci + '">' +
                    '<span class="nmc-ch-num ' + (cnt.length && dn === cnt.length ? 'done' : (open ? 'open' : '')) + '">' + fa(ci + 1) + '</span>' +
                    '<span class="nmc-ch-body"><span class="nmc-ch-title">' + esc(ch.title) + '</span>' +
                    '<span class="nmc-ch-prog"><span class="nmc-ch-done">' + fa(dn) + ' از ' + fa(cnt.length) + ' تکمیل‌شده</span></span></span>' +
                    '<span class="nmc-ch-caret ' + (open ? 'open' : '') + '">' + ICON.caret + '</span></button>' +
                    (open ? '<div class="nmc-ch-lessons">' + lessons.map(function (l) { return self.lessonRow(l, m.query); }).join('') + '</div>' : '') +
                '</div>';
            });
            sh.querySelector('.nmc-sheet-list').innerHTML = any ? html : '<div class="nmc-sheet-empty">درسی با این مشخصات پیدا نشد.</div>';
        };

        NMC.prototype.openSheet = function () {
            var m = this._mobile, cur = this.cur();
            if (!m || m.isOpen) return;
            m.isOpen = true;
            if (cur) { m.open[cur.ci] = true; } // the current chapter opens by itself
            this.renderSheet();
            this._sheet.classList.add('open');
            this._sheetBg.classList.add('open');
            document.documentElement.style.overflow = 'hidden';
            try { history.pushState({ nmcSheet: 1 }, ''); } catch (e) {}
            var list = this._sheet.querySelector('.nmc-sheet-list'), sel = list.querySelector('.nmc-les.sel');
            if (sel) {
                // Centre the current lesson in the list (measured on screen, so
                // the header height above the list does not matter).
                var lr = list.getBoundingClientRect(), sr = sel.getBoundingClientRect();
                list.scrollTop = Math.max(0, list.scrollTop + (sr.top - lr.top) - (lr.height - sr.height) / 2);
            }
        };

        NMC.prototype.closeSheet = function (fromHistory) {
            var m = this._mobile;
            if (!m || !m.isOpen) return;
            m.isOpen = false;
            this._sheet.classList.remove('open');
            this._sheetBg.classList.remove('open');
            document.documentElement.style.overflow = '';
            if (!fromHistory && history.state && history.state.nmcSheet) { try { history.back(); } catch (e) {} }
        };

        /** After choosing a lesson, make sure the player is on screen. */
        NMC.prototype.revealPlayer = function () {
            var host = this.regions.player;
            if (!host || !host.getBoundingClientRect) return;
            if (this._mini) { this.setMini(false); }
            var r = host.getBoundingClientRect(), topBar = 70;
            if (r.top < topBar || r.top > window.innerHeight * 0.5) {
                window.scrollTo({ top: Math.max(0, r.top + window.pageYOffset - topBar), behavior: 'smooth' });
            }
        };

        // Mini player: the video keeps playing in a corner while the
        // student reads the lesson text below (phones only).
        NMC.prototype.setupMiniPlayer = function () {
            var self = this, host = this.regions.player;
            if (!host || !host.parentNode) return;
            var spacer = el('div', { 'class': 'nmc-mini-spacer' });
            spacer.style.display = 'none';
            host.parentNode.insertBefore(spacer, host);
            var ctl = el('div', { 'class': 'nmc-mini-ctl' });
            ctl.innerHTML =
                '<button type="button" data-mini="up" aria-label="بازگشت به پلیر"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></button>' +
                '<button type="button" data-mini="close" aria-label="بستن"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>';
            ctl.addEventListener('click', function (e) {
                var b = e.target.closest('[data-mini]'); if (!b) return;
                e.stopPropagation();
                if (b.getAttribute('data-mini') === 'close') { if (self._player) { self._player.pause(); } self._miniOff = true; self.setMini(false); }
                else { self.revealPlayer(); }
            });
            this._miniSpacer = spacer; this._miniCtl = ctl;
            var tick = false;
            window.addEventListener('scroll', function () {
                if (tick) return; tick = true;
                requestAnimationFrame(function () { tick = false; self.checkMini(); });
            }, { passive: true });
        };
        NMC.prototype.checkMini = function () {
            var host = this.regions.player;
            if (!host || !this._miniSpacer) return;
            var ref = this._mini ? this._miniSpacer : host, r = ref.getBoundingClientRect();
            var playing = !!(this._player && this._player.playing());
            if (r.bottom > 40) { this._miniOff = false; } // back at the player: allow next time
            var want = this.isMobile() && !this._miniOff && (playing || this._mini) && r.bottom < 40;
            if (want !== !!this._mini) { this.setMini(want); }
        };
        NMC.prototype.setMini = function (on) {
            var host = this.regions.player;
            if (!host || !this._miniSpacer) return;
            if (on) {
                this._miniSpacer.style.height = host.offsetHeight + 'px';
                this._miniSpacer.style.display = 'block';
                host.classList.add('nmc-mini');
                host.appendChild(this._miniCtl);
            } else {
                this._miniSpacer.style.display = 'none';
                host.classList.remove('nmc-mini');
                if (this._miniCtl.parentNode) { this._miniCtl.parentNode.removeChild(this._miniCtl); }
            }
            this._mini = !!on;
        };

        // ---- certificate ----
        NMC.prototype.renderCert = function () {
            if (!this.d.certificate || !this.d.certificate.enabled) return;
            var host = this.regions.certificate; if (!host) return;
            var d = this.d, overall = this.overall(), threshold = this.threshold();
            // Certificate rule "pass the course quizzes" (when on).
            var qNeed = d.certificate.quizRequired ? (d.grades || []) : [];
            var qPassed = qNeed.filter(function (g) { return g.record && g.record.passed; }).length;
            var quizOk = qPassed >= qNeed.length;
            var unlocked = overall >= threshold && quizOk;
            var pageUrl = d.certificate.pageUrl || '#';
            var body;

            if (!unlocked) {
                body =
                    '<div class="nmc-cert-locked">' +
                        '<div class="nmc-cert-lock-ic">' + ICON.lockBig + '</div>' +
                        '<div class="nmc-cert-lock-info">' +
                            '<div class="nmc-cert-lock-t">هنوز واجد شرایط دریافت گواهی نیستید</div>' +
                            '<div class="nmc-cert-lock-m">برای صدور گواهی باید حداقل ' + fa(threshold) + '٪ دوره را تکمیل کنید. تا رسیدن به این حد ' + fa(Math.max(0, threshold - overall)) + '٪ باقی مانده است.</div>' +
                            '<div class="nmc-cert-lock-bar"><span class="bar"><span style="width:' + Math.min(100, Math.round(overall / threshold * 100)) + '%"></span></span><span class="pct">' + fa(overall) + '٪</span></div>' +
                            (qNeed.length ? '<div class="nmc-cert-lock-m nmc-cert-quiz ' + (quizOk ? 'ok' : '') + '">' + ICON.quiz + ' قبولی در آزمون‌های دوره: ' + fa(qPassed) + ' از ' + fa(qNeed.length) + (quizOk ? ' ✓' : '') + '</div>' : '') +
                        '</div>' +
                    '</div>';
            } else {
                body =
                    '<div class="nmc-cert-un">' +
                        '<div class="nmc-cert-paper-wrap"><div class="nmc-cert-paper"><div class="frame"></div>' +
                            '<div class="seal">' + ICON.seal + '</div>' +
                            '<div class="kicker">گواهی پایان دوره</div>' +
                            '<div class="to">این گواهی به</div>' +
                            '<div class="name">' + esc(d.studentName) + '</div>' +
                            '<div class="for">بابت تکمیل موفقیت‌آمیز دوره</div>' +
                            '<div class="course">' + esc(d.courseTitle) + '</div>' +
                        '</div></div>' +
                        '<div class="nmc-cert-side">' +
                            '<div class="ok">' + ICON.check + ' تبریک! دوره را کامل کردید</div>' +
                            '<p>گواهی شما آماده دریافت است. می‌توانید نسخهٔ کامل را از صفحهٔ گواهی دریافت یا استعلام کنید.</p>' +
                            '<a class="nmc-cert-dl" href="' + esc(pageUrl) + '">' + ICON.download + ' دریافت گواهی</a>' +
                            '<a class="nmc-cert-verify" href="' + esc(pageUrl) + '">استعلام آنلاین گواهی</a>' +
                        '</div>' +
                    '</div>';
            }

            host.innerHTML =
                '<div class="nmc-cert-head">' + ICON.award + '<h3>گواهی پایان دوره</h3></div>' +
                '<div class="nmc-cert-body">' + body + '</div>';
        };

        // ---- actions ----
        NMC.prototype.selectLesson = function (id, autoplay) {
            var l = this.flat.find(function (x) { return x.id === id; });
            if (!l) return;
            this._autoplayNext = !!autoplay; // coming from the "next lesson" end screen
            this.state.currentId = id;
            this.state.tab = this.state.tabNext || 'desc';
            this.state.tabNext = null;
            this.state.open[l.ci] = true;
            this.persist();
            this.renderPlayer();
            this.renderInfo();
            this.renderSide();
            this.renderHeader();
            this.renderMobile();
            this.revealPlayer();
        };
        NMC.prototype.go = function (dir) {
            var idx = this.flat.findIndex(function (l) { return l.id === this.state.currentId; }, this);
            var n = Math.max(0, Math.min(this.flat.length - 1, idx + dir));
            if (this.flat[n]) this.selectLesson(this.flat[n].id);
        };
        NMC.prototype.markComplete = function () {
            var c = this.cur();
            var ct = this.playerType(c);
            if (c.locked || ct === 'empty' || ct === 'quiz') return;
            this.state.progress[c.id] = 100;
            this.persist();
            this.renderHeader(); this.renderInfo(); this.renderSide(); this.renderCert();
        };
        NMC.prototype.bump = function (id, pct) {
            if (pct > this.pctOf(id)) {
                this.state.progress[id] = pct;
                this.persist();
                this.renderHeader(); this.renderSide(); this.renderCert(); this.renderMobile();
            }
        };
        // ---- Nias player: real watched share → lesson progress ----
        NMC.prototype.onWatch = function (id, info) {
            if (!info || !info.duration) return;
            this.state.times[id] = info.time;
            if (info.watch) {
                this.state.watch[id] = { n: info.watch.n, m: info.watch.m, p: info.watchedPct, d: Math.round(info.duration) };
                this._dirty[id] = 1;
            }
            // A lesson is complete once the student has really watched
            // completePct% of it — reaching the end by seeking is not enough.
            var done = parseInt(this.d.completePct, 10) || 90;
            var pct = info.watchedPct >= done ? 100 : Math.floor(info.watchedPct);
            if (pct > this.pctOf(id)) { this.bump(id, pct); } else { this.saveLocal(); }
            this.queueWatchSync();
        };
        NMC.prototype.saveLocal = function () {
            clearTimeout(this._saveT);
            var self = this;
            this._saveT = setTimeout(function () {
                var s = self.state;
                try { localStorage.setItem(self.KEY, JSON.stringify({ progress: s.progress, times: s.times, watch: s.watch, currentId: s.currentId, open: s.open })); } catch (e) {}
            }, 800);
        };
        NMC.prototype.watchPayload = function () {
            var ids = Object.keys(this._dirty), st = this.state;
            if (!ids.length || !this.d.progressSync) return null;
            var items = ids.map(function (id) {
                var w = st.watch[id];
                return w ? { id: id, n: w.n, m: w.m, t: Math.round((st.times[id] || 0) * 10) / 10, d: w.d || 0 } : null;
            }).filter(Boolean);
            this._dirty = {};
            var body = new FormData();
            body.append('action', 'nias_watch_sync');
            body.append('nonce', this.d.progressSync.nonce);
            body.append('product_id', this.d.productId);
            body.append('items', JSON.stringify(items));
            return body;
        };
        // Batched, throttled upload of changed watch maps: at most one request
        // every 5 s. (A debounce would never fire while a video keeps playing,
        // because the player reports progress every 2 s.)
        NMC.prototype.queueWatchSync = function () {
            if (!this.d.progressSync || !window.fetch || this._watchT) return;
            var self = this;
            this._watchT = setTimeout(function () {
                self._watchT = null;
                var body = self.watchPayload();
                if (!body) return;
                fetch(self.d.progressSync.url, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        // Server copy includes other devices' viewing.
                        if (res && res.success && res.data && res.data.watch) {
                            Object.keys(res.data.watch).forEach(function (id) {
                                self.state.watch[id] = window.NiasPlayer.mergeWatch(self.state.watch[id], res.data.watch[id]);
                            });
                        }
                    }).catch(function () {});
            }, 5000);
        };
        // Leaving the page: flush the live player and send with sendBeacon.
        NMC.prototype.bindWatchFlush = function () {
            var self = this;
            var flush = function () {
                if (self._player) { self._player.flush(); }
                clearTimeout(self._watchT);
                self._watchT = null;
                var body = self.watchPayload();
                if (body && navigator.sendBeacon) { navigator.sendBeacon(self.d.progressSync.url, body); }
                var s = self.state;
                try { localStorage.setItem(self.KEY, JSON.stringify({ progress: s.progress, times: s.times, watch: s.watch, currentId: s.currentId, open: s.open })); } catch (e) {}
            };
            window.addEventListener('pagehide', flush);
            document.addEventListener('visibilitychange', function () { if (document.hidden) { flush(); } });
        };
        NMC.prototype.onVideoTime = function (e) {
            var v = e.target; if (!v.duration) return;
            var c = this.cur(); this.state.times[c.id] = v.currentTime;
            this.bump(c.id, Math.round(v.currentTime / v.duration * 100));
        };
        NMC.prototype.onVideoLoaded = function (e) {
            var c = this.cur(), t = this.state.times[c.id];
            if (t && e.target.duration && t < e.target.duration - 1) { try { e.target.currentTime = t; } catch (err) {} }
        };
        NMC.prototype.onVideoEnded = function () {
            var c = this.cur(); this.state.progress[c.id] = 100; this.persist();
            this.renderHeader(); this.renderInfo(); this.renderSide(); this.renderCert();
        };

        // Manager: one controller per product; section parts share it. Mounts
        // that arrive before their product's data is registered are queued and
        // flushed on register, so shortcode/DOM order does not matter.
        window.NiasModernCourse = {
            _data: {},
            _inst: {},
            _pending: {},
            register: function (data) {
                if (!data || data.productId == null) { return; }
                this._data[data.productId] = data;
                var q = this._pending[data.productId];
                if (q && q.length) {
                    this._pending[data.productId] = [];
                    for (var i = 0; i < q.length; i++) { this.mount(data.productId, q[i][0], q[i][1]); }
                }
            },
            mount: function (productId, part, rootId) {
                if (!this._data[productId]) {
                    (this._pending[productId] = this._pending[productId] || []).push([part, rootId]);
                    return;
                }
                var inst = this._inst[productId];
                if (!inst) {
                    try { inst = this._inst[productId] = new NMC(this._data[productId]); }
                    catch (e) { if (window.console) console.error('NiasModernCourse', e); return; }
                }
                try { inst.attach(part, rootId); }
                catch (e) { if (window.console) console.error('NiasModernCourse', e); }
            },
            // Backward-compatible full-view entry point.
            init: function (data, rootId) { this.register(data); this.mount(data.productId, 'full', rootId); }
        };
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Shortcodes
 *
 * One shortcode renders the whole view; each section also has its own shortcode
 * so the modern area can be split across a page. They all share one controller
 * per product, so the parts stay in sync:
 *
 *   [nias_modern_course]              full view
 *   [nias_modern_course_header]       course header + progress ring + cert button
 *   [nias_modern_course_player]       the lesson player
 *   [nias_modern_course_lesson]       lesson info bar (nav + description/resources)
 *   [nias_modern_course_curriculum]   chapters/lessons list
 *   [nias_modern_course_certificate]  certificate section
 *
 * Each accepts an optional id="123" to target a specific product.
 * ---------------------------------------------------------------------- */

/** Resolve the product id for a shortcode (explicit id, else current product). */
function nias_modern_course_resolve_pid($atts)
{
    $atts = shortcode_atts(array('id' => 0), $atts);
    $product_id = intval($atts['id']);
    if (!$product_id) {
        $product_id = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
        if (!nias_is_course_post($product_id)) {
            $qo = get_queried_object_id();
            if (nias_is_course_post($qo)) {
                $product_id = (int) $qo;
            }
        }
    }
    return ($product_id && nias_is_course_post($product_id)) ? $product_id : 0;
}

/** Shared handler: render the requested section part for the resolved product. */
function nias_modern_course_shortcode_part($atts, $part)
{
    if (nias_modern_course_mode() === 'off') {
        return '';
    }
    $product_id = nias_modern_course_resolve_pid($atts);
    return $product_id ? nias_modern_course_render($product_id, $part) : '';
}

add_shortcode('nias_modern_course', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'full');
});
add_shortcode('nias_modern_course_header', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'header');
});
add_shortcode('nias_modern_course_player', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'player');
});
add_shortcode('nias_modern_course_lesson', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'info');
});
add_shortcode('nias_modern_course_curriculum', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'curriculum');
});
add_shortcode('nias_modern_course_certificate', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'certificate');
});
// Quiz gradebook of the course (only shows when the course has quizzes).
add_shortcode('nias_modern_course_grades', function ($atts) {
    return nias_modern_course_shortcode_part($atts, 'grades');
});

/* -------------------------------------------------------------------------
 * Automatic display: a sticky pulsing button on the product page that opens a
 * focused, full-screen course view at  <product-url>?nias_modern=1
 * ---------------------------------------------------------------------- */

/** Query flag used to switch the product page into the focused course view. */
if (!defined('NIAS_MODERN_QV')) {
    define('NIAS_MODERN_QV', 'nias_modern');
}

/** Does the product have any chapters/lessons stored? (light check) */
function nias_modern_course_has_curriculum($product_id)
{
    $sections = carbon_get_post_meta($product_id, 'course_sections');
    return is_array($sections) && !empty($sections);
}

/** Print the inline "enter modern course" button style once per request. */
function nias_modern_course_button_assets()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .nmc-enter-btn{display:inline-flex;align-items:center;gap:10px;font-family:inherit;font-size:15px;font-weight:800;color:#fff;text-decoration:none;border:0;border-radius:99px;padding:13px 26px;cursor:pointer;background:linear-gradient(135deg,#1e83f0,#0e6fd6);box-shadow:0 12px 30px -8px rgba(30,131,240,.7);transition:transform .15s ease,box-shadow .15s ease}
    .nmc-enter-btn:hover{transform:translateY(-2px);color:#fff;box-shadow:0 16px 40px -8px rgba(30,131,240,.95)}
    .nmc-enter-btn svg{flex:none}
    .nmc-enter-dot{width:9px;height:9px;border-radius:50%;background:#9bff5f;box-shadow:0 0 0 0 rgba(155,255,95,.7);animation:nmc-enter-dot 1.4s ease-out infinite}
    @keyframes nmc-enter-dot{0%{box-shadow:0 0 0 0 rgba(155,255,95,.7)}70%{box-shadow:0 0 0 10px rgba(155,255,95,0)}100%{box-shadow:0 0 0 0 rgba(155,255,95,0)}}
    </style>
    <?php
}

/**
 * Shortcode: a button that opens the focused, full-screen modern course view.
 *
 *   [nias_modern_course_button]               current product
 *   [nias_modern_course_button id="123"]      a specific product
 *   [nias_modern_course_button text="..."]    custom label
 *   [nias_modern_course_button class="..."]   extra CSS class(es)
 *
 * Renders whenever the resolved product has a curriculum. The focused
 * ?nias_modern=1 view is available regardless of the global modern-course mode,
 * so this button works the same way (unlike the per-section shortcodes, which
 * are tied to «نمایش دستی با شورت‌کد»).
 */
add_shortcode('nias_modern_course_button', 'nias_modern_course_button_shortcode');
function nias_modern_course_button_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'id'    => 0,
        'text'  => '',
        'class' => '',
    ), $atts, 'nias_modern_course_button');

    $product_id = nias_modern_course_resolve_pid(array('id' => $atts['id']));
    if (!$product_id || !nias_modern_course_has_curriculum($product_id)) {
        return '';
    }

    $url   = add_query_arg(NIAS_MODERN_QV, '1', get_permalink($product_id));
    $label = ($atts['text'] !== '') ? $atts['text'] : __('ورود به محیط دوره مدرن', 'nias-course-widget');
    $class = trim('nmc-enter-btn ' . sanitize_text_field($atts['class']));

    ob_start();
    nias_modern_course_button_assets();
    ?>
    <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>" dir="rtl">
        <span class="nmc-enter-dot"></span>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        <span><?php echo esc_html($label); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

/**
 * Sticky, blinking call-to-action printed at the bottom of the product page
 * when auto mode is on. Clicking it opens the focused course view.
 */
add_action('wp_footer', 'nias_modern_course_sticky_button');
function nias_modern_course_sticky_button()
{
    if (nias_modern_course_mode() !== 'auto') {
        return;
    }
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    if (!empty($_GET[NIAS_MODERN_QV])) {
        return; // already inside the focused view
    }
    $product_id = get_queried_object_id();
    if (!$product_id || !nias_is_course_post($product_id) || !nias_modern_course_has_curriculum($product_id)) {
        return;
    }
    $url = add_query_arg(NIAS_MODERN_QV, '1', get_permalink($product_id));
    ?>
    <style>
    .nmc-fab-wrap{position:fixed;left:0;right:0;bottom:18px;z-index:99990;display:flex;justify-content:center;pointer-events:none;padding:0 14px}
    .nmc-fab{pointer-events:auto;display:inline-flex;align-items:center;gap:10px;font-family:inherit;font-size:15px;font-weight:800;color:#fff;text-decoration:none;border:0;border-radius:99px;padding:14px 26px;cursor:pointer;background:linear-gradient(135deg,#1e83f0,#0e6fd6);box-shadow:0 12px 30px -8px rgba(30,131,240,.7);animation:nmc-fab-pulse 1.8s ease-in-out infinite;transition:transform .15s ease}
    .nmc-fab:hover{transform:translateY(-2px);animation-play-state:paused;color:#fff}
    .nmc-fab svg{flex:none}
    .nmc-fab-dot{width:9px;height:9px;border-radius:50%;background:#9bff5f;box-shadow:0 0 0 0 rgba(155,255,95,.7);animation:nmc-fab-dot 1.4s ease-out infinite}
    @keyframes nmc-fab-pulse{0%,100%{box-shadow:0 12px 30px -8px rgba(30,131,240,.7)}50%{box-shadow:0 14px 40px -6px rgba(30,131,240,1)}}
    @keyframes nmc-fab-dot{0%{box-shadow:0 0 0 0 rgba(155,255,95,.7)}70%{box-shadow:0 0 0 10px rgba(155,255,95,0)}100%{box-shadow:0 0 0 0 rgba(155,255,95,0)}}
    @media (max-width:600px){.nmc-fab{width:100%;justify-content:center}}
    </style>
    <div class="nmc-fab-wrap" dir="rtl">
        <a class="nmc-fab" href="<?php echo esc_url($url); ?>">
            <span class="nmc-fab-dot"></span>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
            <span><?php echo esc_html__('ورود به محیط دوره مدرن', 'nias-course-widget'); ?></span>
        </a>
    </div>
    <?php
}

/**
 * Focused, full-screen course view. When ?nias_modern=1 is present on a product
 * page, take over the response and render only the modern course (lessons still
 * follow the private/purchase logic inside the renderer).
 */
add_action('template_redirect', 'nias_modern_course_takeover');
function nias_modern_course_takeover()
{
    // The focused full-page view is triggered by the explicit ?nias_modern=1
    // flag (emitted by the auto-mode sticky button and the account/shortcode
    // course cards), so it works regardless of the global modern-course mode.
    if (empty($_GET[NIAS_MODERN_QV])) {
        return;
    }
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    $product_id = get_queried_object_id();
    if (!$product_id || !nias_is_course_post($product_id)) {
        return;
    }

    $back = get_permalink($product_id);
    $html = nias_modern_course_render($product_id);
    if ($html === '') {
        // No curriculum — just go back to the normal product page.
        wp_safe_redirect($back);
        exit;
    }

    $title = get_the_title($product_id);
    nocache_headers();
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($title); ?></title>
    <style>
        /* Focused (standalone) view has no theme, so load Vazir for a consistent
           Persian look (the shortcode/auto in-page mode still inherits the theme font). */
        @font-face{font-family:'Vazir';src:url('<?php echo esc_url(NIAS_FONTS_URL . 'Vazir.ttf'); ?>') format('truetype');font-weight:400;font-style:normal;font-display:swap}
        @font-face{font-family:'Vazir';src:url('<?php echo esc_url(NIAS_FONTS_URL . 'Vazir-Medium.ttf'); ?>') format('truetype');font-weight:500;font-style:normal;font-display:swap}
        @font-face{font-family:'Vazir';src:url('<?php echo esc_url(NIAS_FONTS_URL . 'Vazir-Bold.ttf'); ?>') format('truetype');font-weight:700;font-style:normal;font-display:swap}
        html,body{margin:0;padding:0;background:#eef1f4}
        body{font-family:'Vazir',system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}
        .nmc-sa-bar{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:14px;background:#fff;border-bottom:1px solid #e9ecef;padding:12px 18px;font-family:inherit}
        .nmc-sa-back{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;color:#1e83f0;text-decoration:none;background:#eef4fe;border-radius:10px;padding:9px 16px}
        .nmc-sa-back:hover{background:#e0ecfd}
        .nmc-sa-title{font-size:15px;font-weight:800;color:#1f2a30;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .nmc-sa-page{max-width:1888px;margin:0 auto}
    </style>
</head>
<body dir="rtl">
    <div class="nmc-sa-bar">
        <a class="nmc-sa-back" href="<?php echo esc_url($back); ?>">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
            <span><?php echo esc_html__('بازگشت به صفحه محصول', 'nias-course-widget'); ?></span>
        </a>
        <span class="nmc-sa-title"><?php echo esc_html($title); ?></span>
    </div>
    <div class="nmc-sa-page"><?php
        echo $html; // already escaped/sanitized inside the renderer
        // Linked quiz (آزمون‌ساز) shown inside the focused modern-course view.
        if (function_exists('nias_quiz_render_for_course')) {
            echo nias_quiz_render_for_course($product_id);
        }
    ?></div>
</body>
</html>
    <?php
    exit;
}
