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

/** Is this URL a directly-playable video file (vs. an embeddable page)? */
function nias_modern_course_is_video_file($url)
{
    return (bool) preg_match('/\.(mp4|m4v|webm|ogg|ogv|mov)(\?.*)?$/i', (string) $url);
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

    global $wp_embed;
    if (!empty($wp_embed) && is_object($wp_embed)) {
        $content = $wp_embed->run_shortcode($content); // [embed] … [/embed]
        $content = $wp_embed->autoembed($content);     // bare embeddable URLs
    }
    $content = wpautop($content);
    if (function_exists('shortcode_unautop')) {
        $content = shortcode_unautop($content);
    }
    return do_shortcode($content);                      // [audio], [video], …
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
        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
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
    if (!$product || $product->post_type !== 'product') {
        return null;
    }

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

    // Build chapters/lessons for the view.
    $chapters = array();
    foreach ($chapters_src as $ci => $chap) {
        $lessons = array();
        foreach ((isset($chap['lessons']) ? $chap['lessons'] : array()) as $li => $les) {
            $video_url = nias_modern_course_media_url(isset($les['video']) ? $les['video'] : array());
            $file_url  = nias_modern_course_media_url(isset($les['file']) ? $les['file'] : array());
            $content   = nias_modern_course_render_content(isset($les['content']) ? $les['content'] : '');
            $private   = !empty($les['private']);

            $lessons[] = array(
                'id'           => 'c' . $ci . 'l' . $li,
                'title'        => isset($les['title']) ? $les['title'] : '',
                'label'        => isset($les['label']) ? $les['label'] : '',
                'duration'     => isset($les['duration']) ? $les['duration'] : '',
                'private'      => $private,
                'locked'       => ($private && !$purchased),
                'videoKind'    => $video_url ? (nias_modern_course_is_video_file($video_url) ? 'file' : 'embed') : '',
                'videoSrc'     => $video_url,
                'downloadUrl'  => $file_url,
                'downloadName' => $file_url ? nias_modern_course_file_name($file_url) : '',
                'content'      => $content,
                'hasContent'   => (trim(wp_strip_all_tags($content)) !== '' || preg_match('/<(iframe|img|video|audio|embed|source|figure)\b/i', $content)),
            );
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

    return array(
        'productId'    => $product_id,
        'courseTitle'  => $product->post_title,
        'courseSubtitle' => wp_trim_words(wp_strip_all_tags($product->post_excerpt ? $product->post_excerpt : $product->post_content), 32, '…'),
        'instructor'   => $instructor,
        'studentName'  => ($user && $user->ID) ? $user->display_name : __('کاربر مهمان', 'nias-course-widget'),
        'purchased'    => $purchased,
        'buyUrl'       => get_permalink($product_id),
        'accent'       => '#1e83f0',
        'chapters'     => $chapters,
        'certificate'  => array(
            'enabled'   => $cert_applies,
            'threshold' => 80,
            'pageUrl'   => $cert_url ? $cert_url : '',
        ),
    );
}

/* -------------------------------------------------------------------------
 * Render
 * ---------------------------------------------------------------------- */

/** Valid section parts that can be rendered on their own. */
function nias_modern_course_parts()
{
    return array('full', 'header', 'player', 'info', 'curriculum', 'certificate');
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
    .nmc-wrap{max-width:1180px;margin:0 auto;display:flex;flex-direction:column;gap:22px}
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
    .nmc-main{display:flex;flex-direction:column;gap:22px;min-width:0}
    .nmc-side{position:sticky;top:20px;display:flex;flex-direction:column;gap:22px}
    @media (max-width:900px){.nmc-layout{grid-template-columns:1fr}.nmc-side{position:static}}

    /* player */
    .nmc-player{background:#0e1518;border-radius:18px;overflow:hidden;box-shadow:0 14px 40px rgba(14,21,24,.28)}
    .nmc-stage{position:relative;width:100%;aspect-ratio:16/9;background:#0e1518}
    .nmc-stage video,.nmc-stage iframe{width:100%;height:100%;border:0;background:#0e1518}
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
    .nmc-cover-ic{width:78px;height:78px;border-radius:20px;display:flex;align-items:center;justify-content:center}
    .nmc-cover-ic.dl{background:rgba(72,175,59,.16);color:#5fd14b}
    .nmc-cover-ic.lock{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.08);color:#fff}
    .nmc-cover-t{color:#fff;font-size:19px;font-weight:700}
    .nmc-cover-m{color:#8a979e;font-size:13px;max-width:340px;line-height:1.8}
    .nmc-cover-btn{display:inline-flex;align-items:center;gap:8px;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:99px}
    .nmc-cover-btn.dl{background:#48af3b}
    .nmc-cover-btn.buy{background:#ff6060;font-size:14px;padding:11px 24px}

    /* info bar */
    .nmc-info-top{padding:20px 24px;display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .nmc-info-l{flex:1;min-width:220px}
    .nmc-info-tags{display:flex;align-items:center;gap:9px;margin-bottom:6px;flex-wrap:wrap}
    .nmc-chip{display:inline-flex;align-items:center;gap:5px;background:#f1f4f6;color:#5b666c;font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px}
    .nmc-chip.muted{background:none;color:#7b868a;padding:0}
    .nmc-info-title{margin:0;font-size:20px;font-weight:700}
    .nmc-nav{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    .nmc-btn{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:13px;font-weight:600;padding:10px 16px;border-radius:10px;cursor:pointer;border:1px solid #e1e6e9;background:#fff;color:#3a464c}
    .nmc-btn:disabled,.nmc-btn.off{opacity:.45;pointer-events:none}
    .nmc-btn.primary{border:0;background:var(--nmc-accent,#1e83f0);color:#fff;font-weight:700}
    .nmc-btn.done{border:1px solid #9bd58f;background:rgba(72,175,59,.1);color:#2f7a24;font-weight:700}
    .nmc-tabs{display:flex;gap:4px;padding:0 24px;border-bottom:1px solid #eef1f4}
    .nmc-tab{background:none;border:0;border-bottom:2px solid transparent;color:#7b868a;font-family:inherit;font-size:14px;font-weight:600;padding:14px 6px;cursor:pointer}
    .nmc-tab.active{border-bottom-color:var(--nmc-accent,#1e83f0);color:var(--nmc-accent,#1e83f0)}
    .nmc-tabbody{padding:24px}
    .nmc-desc{font-size:15px;line-height:2.05;color:#3a464c;text-align:justify}
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
    .nmc-les.sel{background:rgba(30,131,240,.05);border-right-color:var(--nmc-accent,#1e83f0)}
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
    nias_modern_course_script();
}

/**
 * The shared front-end controller (printed once).
 */
function nias_modern_course_script()
{
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
            prev: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>',
            next: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
            lock: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v9H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>',
            lockBig: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v9H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>',
            fileBig: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 3v5h5"/></svg>',
            circle: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>',
            seal: '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="6"/><path d="M12 6v6M9 9h6"/></svg>'
        };

        function NMC(data) {
            this.d = data;
            this.regions = {}; // part -> host element (header/player/info/curriculum/certificate)
            this.accent = data.accent || '#1e83f0';
            this.KEY = 'nias-mc-' + data.productId;
            this.flat = [];
            (data.chapters || []).forEach(function (ch, ci) {
                (ch.lessons || []).forEach(function (l, li) { this.flat.push(Object.assign({ ci: ci, li: li }, l)); }, this);
            }, this);

            var saved = this.load();
            this.state = {
                progress: saved.progress || {},
                times: saved.times || {},
                currentId: saved.currentId || (this.flat[0] && this.flat[0].id) || null,
                open: saved.open || { 0: true },
                tab: 'desc'
            };
            if (!this.flat.some(function (l) { return l.id === this.state.currentId; }, this)) {
                this.state.currentId = this.flat[0] ? this.flat[0].id : null;
            }
        }

        NMC.prototype.load = function () { try { return JSON.parse(localStorage.getItem(this.KEY)) || {}; } catch (e) { return {}; } };
        NMC.prototype.persist = function () {
            var s = this.state;
            try { localStorage.setItem(this.KEY, JSON.stringify({ progress: s.progress, times: s.times, currentId: s.currentId, open: s.open })); } catch (e) {}
        };

        // ---- derived ----
        NMC.prototype.cur = function () { var id = this.state.currentId; return this.flat.find(function (l) { return l.id === id; }) || this.flat[0]; };
        NMC.prototype.pctOf = function (id) { return Math.min(100, Math.round(this.state.progress[id] || 0)); };
        NMC.prototype.counted = function () { return this.flat.filter(function (l) { return !l.locked; }); };
        NMC.prototype.overall = function () { var c = this.counted(); if (!c.length) return 0; var s = c.reduce(function (a, l) { return a + this.pctOf(l.id); }.bind(this), 0); return Math.round(s / c.length); };
        NMC.prototype.threshold = function () { var t = this.d.certificate && this.d.certificate.threshold; t = parseInt(t, 10); return isNaN(t) ? 80 : t; };
        NMC.prototype.playerType = function (l) {
            if (l.locked) return 'locked';
            if (l.videoSrc) return l.videoKind === 'file' ? 'video' : 'iframe';
            if (l.downloadUrl) return 'download';
            if (l.hasContent) return 'text';
            return 'empty';
        };
        NMC.prototype.typeLabel = function (l) {
            return ({ video: 'ویدیو', iframe: 'پخش آنلاین', download: 'فایل دانلودی', text: 'درس متنی', locked: 'درس خصوصی', empty: 'درس' })[this.playerType(l)];
        };
        NMC.prototype.typeIcon = function (l) {
            return ({ video: ICON.play, iframe: ICON.iframe, download: ICON.download, text: ICON.text, locked: ICON.lock, empty: ICON.book })[this.playerType(l)];
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
            if (this.d.certificate && this.d.certificate.enabled) {
                wrap.appendChild(cert);
            }
            root.appendChild(wrap);
            this.regions = { header: header, player: player, info: info, curriculum: curriculum, certificate: cert };
        };

        // Single section into its own root.
        NMC.prototype.buildPart = function (root, part) {
            var map = {
                header: ['header', 'nmc-card nmc-header'],
                player: ['section', 'nmc-card nmc-player'],
                info: ['section', 'nmc-card'],
                curriculum: ['section', 'nmc-card nmc-cur'],
                certificate: ['section', 'nmc-card']
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
            this.renderCert();
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

            if (t === 'video') {
                var v = el('video', { controls: '', playsinline: '', preload: 'metadata', src: cur.videoSrc });
                v.addEventListener('timeupdate', function (e) { self.onVideoTime(e); });
                v.addEventListener('loadedmetadata', function (e) { self.onVideoLoaded(e); });
                v.addEventListener('ended', function () { self.onVideoEnded(); });
                this._video = v;
                stage.appendChild(v);
            } else if (t === 'iframe') {
                stage.appendChild(el('iframe', { src: cur.videoSrc, allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen', allowfullscreen: '' }));
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
            } else if (t === 'locked') {
                var lk = el('div', { 'class': 'nmc-cover lock' });
                lk.innerHTML =
                    '<div class="nmc-cover-ic lock">' + ICON.lockBig + '</div>' +
                    '<div class="nmc-cover-t">این درس خصوصی است</div>' +
                    '<div class="nmc-cover-m">برای دسترسی به این درس باید دوره را تهیه کنید.</div>' +
                    '<a class="nmc-cover-btn buy" href="' + esc(this.d.buyUrl) + '">تهیه دوره</a>';
                stage.appendChild(lk);
            } else {
                var em = el('div', { 'class': 'nmc-cover empty' });
                em.innerHTML = '<div class="nmc-cover-ic lock">' + ICON.book + '</div><div class="nmc-cover-t">محتوایی برای این درس ثبت نشده است</div>';
                stage.appendChild(em);
            }

            host.innerHTML = '';
            host.appendChild(stage);

            // restore saved position for video
            if (t === 'video') {
                var saved = this.state.times[cur.id];
                if (saved) { try { this._video.currentTime = saved; } catch (e) {} }
            } else {
                this._video = null;
            }
        };

        // ---- info bar ----
        NMC.prototype.renderInfo = function () {
            var host = this.regions.info; if (!host) return;
            var cur = this.cur(), self = this;
            var idx = this.flat.findIndex(function (l) { return l.id === cur.id; });
            var done = this.pctOf(cur.id) >= 100;
            var completeDisabled = cur.locked || this.playerType(cur) === 'empty';

            var resources = [];
            if (cur.downloadUrl) resources.push({ name: cur.downloadName, meta: cur.duration || 'فایل دانلودی', url: cur.downloadUrl });

            var tab = this.state.tab;
            var body;
            if (tab === 'desc') {
                var descHtml = (this.playerType(cur) === 'text') ? '' : (cur.content || '');
                body = descHtml ? '<div class="nmc-desc">' + descHtml + '</div>'
                                : '<div class="nmc-empty">برای این درس توضیحی ثبت نشده است.</div>';
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
                        '<button type="button" class="nmc-btn ' + (completeDisabled ? 'off' : (done ? 'done' : 'primary')) + '" data-act="complete">' + (done ? ICON.checkC : ICON.circle) + '<span>' + (done ? 'تکمیل شد' : 'علامت‌گذاری به‌عنوان دیده‌شده') + '</span></button>' +
                        '<button type="button" class="nmc-btn ' + (idx >= this.flat.length - 1 ? 'off' : '') + '" data-act="next"><span>درس بعدی</span>' + ICON.next + '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="nmc-tabs">' +
                    '<button type="button" class="nmc-tab ' + (tab === 'desc' ? 'active' : '') + '" data-act="tabdesc">توضیحات درس</button>' +
                    '<button type="button" class="nmc-tab ' + (tab === 'res' ? 'active' : '') + '" data-act="tabres">منابع و پیوست‌ها</button>' +
                '</div>' +
                '<div class="nmc-tabbody">' + body + '</div>';

            host.querySelector('[data-act="prev"]').addEventListener('click', function () { self.go(-1); });
            host.querySelector('[data-act="next"]').addEventListener('click', function () { self.go(1); });
            host.querySelector('[data-act="complete"]').addEventListener('click', function () { self.markComplete(); });
            host.querySelector('[data-act="tabdesc"]').addEventListener('click', function () { self.state.tab = 'desc'; self.renderInfo(); });
            host.querySelector('[data-act="tabres"]').addEventListener('click', function () { self.state.tab = 'res'; self.renderInfo(); });
        };

        // ---- sidebar ----
        NMC.prototype.renderSide = function () {
            var host = this.regions.curriculum; if (!host) return;
            var d = this.d, self = this;
            var totalLessons = this.flat.length;
            var head =
                '<div class="nmc-cur-head"><h3>سرفصل‌های دوره</h3>' +
                '<span class="nmc-cur-meta">' + fa(d.chapters.length) + ' فصل · ' + fa(totalLessons) + ' درس</span></div>';

            var list = '';
            d.chapters.forEach(function (ch, ci) {
                var open = !!self.state.open[ci];
                var counted = ch.lessons.filter(function (l) { return !l.locked; });
                var doneN = counted.filter(function (l) { return self.pctOf(l.id) >= 100; }).length;
                var pct = counted.length ? Math.round(counted.reduce(function (a, l) { return a + self.pctOf(l.id); }, 0) / counted.length) : 0;
                var numCls = pct >= 100 ? 'done' : (open ? 'open' : '');

                var lessonsHtml = '';
                if (open) {
                    ch.lessons.forEach(function (l) {
                        var lp = self.pctOf(l.id), sel = l.id === self.state.currentId, doneL = lp >= 100;
                        var status;
                        if (l.locked) status = '<span class="nmc-les-status"><span class="dot" style="background:#f1f4f6;color:#a9b3b8">' + ICON.lock + '</span></span>';
                        else if (doneL) status = '<span class="nmc-les-status">' + ICON.checkC + '</span>';
                        else status = '<span class="nmc-les-status" style="color:' + (lp > 0 ? self.accent : '#c2cacf') + '">' + fa(lp) + '٪</span>';
                        var pctLbl = doneL ? 'تکمیل‌شده' : (lp > 0 ? fa(lp) + '٪ مشاهده' : 'شروع نشده');
                        var pctCol = doneL ? '#48af3b' : (lp > 0 ? self.accent : '#9aa4a9');
                        lessonsHtml +=
                            '<button type="button" class="nmc-les ' + (sel ? 'sel' : '') + '" data-lesson="' + esc(l.id) + '">' +
                                '<span class="nmc-les-ic ' + (l.locked ? 'lock' : '') + '">' + self.typeIcon(l) + '</span>' +
                                '<span class="nmc-les-body">' +
                                    '<span class="nmc-les-row1"><span class="nmc-les-title">' + esc(l.title) + '</span><span class="nmc-les-badge">' + esc(l.label || self.typeLabel(l)) + '</span></span>' +
                                    '<span class="nmc-les-row2">' + (l.duration ? '<span class="nmc-les-dur">' + ICON.clock + ' ' + esc(l.duration) + '</span>' : '') + '<span class="nmc-les-pct" style="color:' + pctCol + '">' + pctLbl + '</span></span>' +
                                '</span>' + status +
                            '</button>';
                    });
                }

                list +=
                    '<div class="nmc-ch">' +
                        '<button type="button" class="nmc-ch-head ' + (open ? 'open' : '') + '" data-ch="' + ci + '">' +
                            '<span class="nmc-ch-num ' + numCls + '">' + fa(ci + 1) + '</span>' +
                            '<span class="nmc-ch-body"><span class="nmc-ch-title">' + esc(ch.title) + '</span>' +
                                '<span class="nmc-ch-prog"><span class="nmc-bar"><span style="width:' + pct + '%"></span></span>' +
                                '<span class="nmc-ch-done">' + fa(doneN) + ' از ' + fa(counted.length) + '</span></span>' +
                            '</span>' +
                            '<span class="nmc-ch-caret ' + (open ? 'open' : '') + '">' + ICON.caret + '</span>' +
                        '</button>' +
                        (open ? '<div class="nmc-ch-lessons">' + lessonsHtml + '</div>' : '') +
                    '</div>';
            });

            host.innerHTML = head + '<div class="nmc-cur-list nmc-scroll">' + list + '</div>';

            host.querySelectorAll('[data-ch]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var ci = parseInt(b.getAttribute('data-ch'), 10);
                    self.state.open[ci] = !self.state.open[ci];
                    self.persist();
                    self.renderSide();
                });
            });
            host.querySelectorAll('[data-lesson]').forEach(function (b) {
                b.addEventListener('click', function () { self.selectLesson(b.getAttribute('data-lesson')); });
            });
        };

        // ---- certificate ----
        NMC.prototype.renderCert = function () {
            if (!this.d.certificate || !this.d.certificate.enabled) return;
            var host = this.regions.certificate; if (!host) return;
            var d = this.d, overall = this.overall(), threshold = this.threshold();
            var unlocked = overall >= threshold;
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
        NMC.prototype.selectLesson = function (id) {
            var l = this.flat.find(function (x) { return x.id === id; });
            if (!l) return;
            this.state.currentId = id;
            this.state.tab = 'desc';
            this.state.open[l.ci] = true;
            this.persist();
            this.renderPlayer();
            this.renderInfo();
            this.renderSide();
            this.renderHeader();
        };
        NMC.prototype.go = function (dir) {
            var idx = this.flat.findIndex(function (l) { return l.id === this.state.currentId; }, this);
            var n = Math.max(0, Math.min(this.flat.length - 1, idx + dir));
            if (this.flat[n]) this.selectLesson(this.flat[n].id);
        };
        NMC.prototype.markComplete = function () {
            var c = this.cur();
            if (c.locked || this.playerType(c) === 'empty') return;
            this.state.progress[c.id] = 100;
            this.persist();
            this.renderHeader(); this.renderInfo(); this.renderSide(); this.renderCert();
        };
        NMC.prototype.bump = function (id, pct) {
            if (pct > this.pctOf(id)) {
                this.state.progress[id] = pct;
                this.persist();
                this.renderHeader(); this.renderSide(); this.renderCert();
            }
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
        if (get_post_type($product_id) !== 'product') {
            $qo = get_queried_object_id();
            if (get_post_type($qo) === 'product') {
                $product_id = (int) $qo;
            }
        }
    }
    return ($product_id && get_post_type($product_id) === 'product') ? $product_id : 0;
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
    if (!$product_id || get_post_type($product_id) !== 'product' || !nias_modern_course_has_curriculum($product_id)) {
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
    if (nias_modern_course_mode() === 'off') {
        return;
    }
    if (empty($_GET[NIAS_MODERN_QV])) {
        return;
    }
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    $product_id = get_queried_object_id();
    if (!$product_id || get_post_type($product_id) !== 'product') {
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
        html,body{margin:0;padding:0;background:#eef1f4}
        /* Focused view has no theme: use the system font (no web font loaded). */
        body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif}
        .nmc-sa-bar{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:14px;background:#fff;border-bottom:1px solid #e9ecef;padding:12px 18px;font-family:inherit}
        .nmc-sa-back{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:700;color:#1e83f0;text-decoration:none;background:#eef4fe;border-radius:10px;padding:9px 16px}
        .nmc-sa-back:hover{background:#e0ecfd}
        .nmc-sa-title{font-size:15px;font-weight:800;color:#1f2a30;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .nmc-sa-page{max-width:1212px;margin:0 auto}
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
