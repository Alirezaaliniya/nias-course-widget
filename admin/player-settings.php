<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nias video player settings (تنظیمات پلیر ویدیو).
 *
 * Every option is stored like the rest of the plugin's settings (option
 * "_{name}", read with carbon_get_theme_option()), so it travels with the
 * existing settings export/backup. nias_player_config() turns them into the
 * window.NIAS_PLAYER_CONFIG object that every Nias player on the site reads.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Definitions
 * ---------------------------------------------------------------------- */

/** name => array(type, default). */
function nias_player_fields()
{
    return array(
        // Appearance
        'nias_player_accent'          => array('color', '#1e83f0'),
        'nias_player_seen_color'      => array('color', '#34d399'),
        'nias_player_radius'          => array('number', 14),
        'nias_player_big_style'       => array('radio', 'circle'),
        'nias_player_ratio'           => array('radio', '16:9'),
        'nias_player_poster'          => array('image', ''),
        'nias_player_logo'            => array('image', ''),
        'nias_player_logo_pos'        => array('radio', 'tr'),
        'nias_player_logo_opacity'    => array('number', 70),
        'nias_player_logo_link'       => array('url', ''),
        // Controls
        'nias_player_ctrl_skip'       => array('radio', 'on'),
        'nias_player_skip_seconds'    => array('radio', '10'),
        'nias_player_ctrl_volume'     => array('radio', 'on'),
        'nias_player_ctrl_time'       => array('radio', 'on'),
        'nias_player_ctrl_speed'      => array('radio', 'on'),
        'nias_player_ctrl_quality'    => array('radio', 'on'),
        'nias_player_ctrl_pip'        => array('radio', 'on'),
        'nias_player_ctrl_fullscreen' => array('radio', 'on'),
        'nias_player_ctrl_seen'       => array('radio', 'on'),
        'nias_player_ctrl_keyboard'   => array('radio', 'on'),
        'nias_player_ctrl_doubletap'  => array('radio', 'on'),
        'nias_player_idle'            => array('number', 3),
        // Playback
        'nias_player_speeds'          => array('multiselect', array('0.5', '0.75', '1', '1.25', '1.5', '1.75', '2')),
        'nias_player_default_speed'   => array('select', '1'),
        'nias_player_max_speed'       => array('select', '2'),
        'nias_player_resume'          => array('radio', 'auto'),
        'nias_player_autoplay'        => array('radio', 'off'),
        'nias_player_auto_next'       => array('radio', 'on'),
        'nias_player_auto_next_delay' => array('number', 6),
        'nias_player_preload'         => array('radio', 'metadata'),
        'nias_player_remember'        => array('radio', 'on'),
        // Learning rules
        'nias_video_complete_pct'     => array('number', 90),
        'nias_player_seek_lock'       => array('radio', 'off'),
        'nias_player_pause_hidden'    => array('radio', 'off'),
        // Protection
        'nias_video_watermark'        => array('radio', 'off'),
        'nias_player_wm_text'         => array('text', '{name} — {phone}'),
        'nias_player_wm_mode'         => array('radio', 'move'),
        'nias_player_wm_opacity'      => array('number', 35),
        'nias_player_wm_size'         => array('number', 13),
        'nias_player_wm_interval'     => array('number', 9),
        'nias_player_block_context'   => array('radio', 'on'),
        'nias_player_no_download'     => array('radio', 'off'),
        // Streaming
        'nias_player_hls_start'       => array('radio', 'auto'),
        'nias_player_hls_cap'         => array('radio', 'on'),
        'nias_player_buffer'          => array('number', 30),
    );
}

/** Current value of a player option (default when never saved). */
function nias_player_opt($name)
{
    $f = nias_player_fields();
    $default = isset($f[$name]) ? $f[$name][1] : '';
    if (!function_exists('carbon_get_theme_option')) {
        return $default;
    }
    $v = carbon_get_theme_option($name);
    if ($v === '' || $v === null || $v === false || (is_array($v) && !$v)) {
        return $default;
    }
    return $v;
}

function nias_player_on($name)
{
    return nias_player_opt($name) === 'on';
}

/**
 * Watermark text for the current user, with tokens resolved:
 * {name} {first_name} {phone} {email} {username} {user_id} {date} {site}.
 */
function nias_player_watermark_text($pattern = null)
{
    if (!is_user_logged_in()) {
        return '';
    }
    $u       = wp_get_current_user();
    $pattern = $pattern === null ? (string) nias_player_opt('nias_player_wm_text') : (string) $pattern;
    $name    = trim($u->first_name . ' ' . $u->last_name);
    $name    = $name !== '' ? $name : $u->display_name;
    $phone   = '';
    foreach (array('billing_phone', 'digits_phone', 'phone', 'mobile') as $key) {
        $phone = preg_replace('/\D+/', '', (string) get_user_meta($u->ID, $key, true));
        if ($phone !== '') {
            break;
        }
    }
    $phone_masked = strlen($phone) >= 7 ? substr($phone, 0, 4) . '***' . substr($phone, -4) : '';
    $parts        = explode('@', (string) $u->user_email);
    $email_masked = $u->user_email ? mb_substr($parts[0], 0, 2) . '***@' . (isset($parts[1]) ? $parts[1] : '') : '';
    $tokens = array(
        '{name}'       => $name,
        '{first_name}' => $u->first_name ? $u->first_name : $name,
        '{phone}'      => $phone_masked !== '' ? $phone_masked : $email_masked,
        '{email}'      => $email_masked,
        '{username}'   => $u->user_login,
        '{user_id}'    => (string) $u->ID,
        '{date}'       => function_exists('nias_cert_jalali') ? nias_cert_jalali(current_time('Y-m-d'), 'numeric') : current_time('Y/m/d'),
        '{site}'       => wp_parse_url(home_url(), PHP_URL_HOST),
    );
    $text = trim(strtr($pattern, $tokens), " —-|\t");
    return apply_filters('nias_video_watermark_text', $text, $u);
}

/**
 * Config object for window.NIAS_PLAYER_CONFIG.
 *
 * @return array
 */
function nias_player_config()
{
    $on   = 'nias_player_on';
    $o    = 'nias_player_opt';
    $num  = function ($name, $min, $max) use ($o) {
        return max($min, min($max, (float) $o($name)));
    };
    $speeds = array_values(array_filter(array_map('floatval', (array) $o('nias_player_speeds')), function ($s) {
        return $s > 0 && $s <= 2;
    }));
    $cfg = array(
        'accent'        => sanitize_hex_color($o('nias_player_accent')) ?: '#1e83f0',
        'seenColor'     => sanitize_hex_color($o('nias_player_seen_color')) ?: '#34d399',
        'radius'        => (int) $num('nias_player_radius', 0, 40),
        'bigStyle'      => in_array($o('nias_player_big_style'), array('circle', 'rounded', 'none'), true) ? $o('nias_player_big_style') : 'circle',
        'ratio'         => in_array($o('nias_player_ratio'), array('16:9', '4:3', '21:9', '1:1', '9:16'), true) ? $o('nias_player_ratio') : '16:9',
        'poster'        => esc_url_raw($o('nias_player_poster')),
        'logo'          => array(
            'src'     => esc_url_raw($o('nias_player_logo')),
            'pos'     => in_array($o('nias_player_logo_pos'), array('tr', 'tl', 'br', 'bl'), true) ? $o('nias_player_logo_pos') : 'tr',
            'opacity' => $num('nias_player_logo_opacity', 10, 100) / 100,
            'link'    => esc_url_raw($o('nias_player_logo_link')),
        ),
        'controls'      => array(
            'skip'       => $on('nias_player_ctrl_skip'),
            'volume'     => $on('nias_player_ctrl_volume'),
            'time'       => $on('nias_player_ctrl_time'),
            'speed'      => $on('nias_player_ctrl_speed'),
            'quality'    => $on('nias_player_ctrl_quality'),
            'pip'        => $on('nias_player_ctrl_pip'),
            'fullscreen' => $on('nias_player_ctrl_fullscreen'),
            'seen'       => $on('nias_player_ctrl_seen'),
            'keyboard'   => $on('nias_player_ctrl_keyboard'),
            'doubleTap'  => $on('nias_player_ctrl_doubletap'),
        ),
        'skipSeconds'   => (int) $num('nias_player_skip_seconds', 3, 60),
        'idle'          => $num('nias_player_idle', 1, 15),
        'speeds'        => $speeds ? $speeds : array(0.5, 0.75, 1, 1.25, 1.5, 1.75, 2),
        'defaultSpeed'  => (float) $num('nias_player_default_speed', 0.5, 2),
        'maxSpeed'      => (float) $num('nias_player_max_speed', 1, 2),
        'resume'        => in_array($o('nias_player_resume'), array('auto', 'ask', 'off'), true) ? $o('nias_player_resume') : 'auto',
        'autoplay'      => $on('nias_player_autoplay'),
        'autoNext'      => $on('nias_player_auto_next'),
        'autoNextDelay' => (int) $num('nias_player_auto_next_delay', 2, 30),
        'preload'       => in_array($o('nias_player_preload'), array('metadata', 'auto', 'none'), true) ? $o('nias_player_preload') : 'metadata',
        'remember'      => $on('nias_player_remember'),
        'completePct'   => (int) $num('nias_video_complete_pct', 10, 100),
        'seekLock'      => in_array($o('nias_player_seek_lock'), array('off', 'first', 'always'), true) ? $o('nias_player_seek_lock') : 'off',
        'pauseHidden'   => $on('nias_player_pause_hidden'),
        'blockContext'  => $on('nias_player_block_context') || $on('nias_player_no_download'),
        'noDownload'    => $on('nias_player_no_download'),
        'watermark'     => array(
            'text'     => $on('nias_video_watermark') ? nias_player_watermark_text() : '',
            'mode'     => $o('nias_player_wm_mode') === 'corner' ? 'corner' : 'move',
            'opacity'  => $num('nias_player_wm_opacity', 5, 100) / 100,
            'size'     => (int) $num('nias_player_wm_size', 9, 40),
            'interval' => (int) $num('nias_player_wm_interval', 3, 60),
        ),
        'hls'           => array(
            'start'  => in_array($o('nias_player_hls_start'), array('auto', 'low', 'high'), true) ? $o('nias_player_hls_start') : 'auto',
            'cap'    => $on('nias_player_hls_cap'),
            'buffer' => (int) $num('nias_player_buffer', 10, 120),
        ),
    );
    return apply_filters('nias_player_config', $cfg);
}

/**
 * Print window.NIAS_PLAYER_CONFIG once per page — call before nias-player.js.
 * The watermark is per user, so pages showing it must not be page-cached
 * for other visitors (logged-in pages normally are not).
 */
function nias_player_print_config()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<script>window.NIAS_PLAYER_CONFIG = ' . wp_json_encode(nias_player_config()) . ';</script>' . "\n";
}

/** <script> tags for the player (config + script), once per page. */
function nias_player_script_tags()
{
    static $done = false;
    if ($done || !empty($GLOBALS['nias_player_suppress'])) {
        return;
    }
    $done = true;
    nias_player_print_config();
    echo '<script src="' . esc_url(plugin_dir_url(NIAS_COURSE . 'nias-course-widget.php') . 'assets/js/nias-player.js?v=' . NIAS_COURSE_VERSION . '.' . (int) @filemtime(NIAS_COURSE . 'assets/js/nias-player.js')) . '"></script>' . "\n";
    nias_player_print_nodownload_guard();
}

/**
 * «جلوگیری از دانلود» on the whole course page: no right-click menu, drag or
 * long-press on videos/audios and inside the course view, no Ctrl+S / Ctrl+U.
 */
function nias_player_print_nodownload_guard()
{
    static $done = false;
    if ($done || !function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_player_no_download') !== 'on') {
        return;
    }
    $done = true;
    ?>
    <style>.nmc video,.nmc audio,.npl video,.nias-ap audio,.nias-vp-wrap video{-webkit-touch-callout:none;-webkit-user-drag:none;user-select:none}</style>
    <script>
    (function () {
        var inCourse = function (t) { return t && t.closest && t.closest('video,audio,.nmc,.npl,.nias-ap,.nias-vp-wrap'); };
        document.addEventListener('contextmenu', function (e) { if (inCourse(e.target)) { e.preventDefault(); } }, true);
        document.addEventListener('dragstart', function (e) { if (e.target && /^(VIDEO|AUDIO)$/.test(e.target.tagName)) { e.preventDefault(); } }, true);
        document.addEventListener('keydown', function (e) {
            var k = (e.key || '').toLowerCase();
            if ((e.ctrlKey || e.metaKey) && (k === 's' || k === 'u')) { e.preventDefault(); }
        }, true);
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_player_register_page', 11);
function nias_player_register_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('Video player settings', 'nias-course-widget'),
        __('Video player', 'nias-course-widget'),
        'manage_options',
        'nias-course-player',
        'nias_player_render_page'
    );
}

/** Field map for nias_course_save_theme_options(). */
function nias_player_save_map()
{
    $map = array();
    foreach (nias_player_fields() as $name => $def) {
        $t = $def[0];
        $map[$name] = $t === 'color' || $t === 'url' ? 'text' : $t;
    }
    return $map;
}

function nias_player_render_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = false;
    if (isset($_POST['nias_save_player_settings']) && check_admin_referer('nias_player_settings', 'nias_player_nonce')) {
        if (!empty($_POST['nias_player_reset'])) {
            foreach (nias_player_fields() as $name => $def) {
                if ($def[0] === 'multiselect' && function_exists('nias_cf_write_option_multivalue')) {
                    nias_cf_write_option_multivalue($name, array()); // empty → default
                } else {
                    delete_option('_' . $name);
                }
            }
        } else {
            // Colors and URLs need their own sanitizing.
            foreach (array('nias_player_accent', 'nias_player_seen_color') as $c) {
                if (isset($_POST[$c])) {
                    $_POST[$c] = sanitize_hex_color(wp_unslash($_POST[$c])) ?: '';
                }
            }
            if (isset($_POST['nias_player_logo_link'])) {
                $_POST['nias_player_logo_link'] = esc_url_raw(wp_unslash($_POST['nias_player_logo_link']));
            }
            nias_course_save_theme_options(nias_player_save_map());
        }
        $saved = true;
    }

    // A real video from the media library for the preview, when there is one.
    // (Not one from the protected course folder: those open only through signed links.)
    $sample = get_posts(array('post_type' => 'attachment', 'post_mime_type' => 'video', 'posts_per_page' => 1, 'post_status' => 'inherit', 'meta_query' => array(array('key' => '_wp_attached_file', 'value' => 'nias-protected/', 'compare' => 'NOT LIKE'))));
    $sample_url = $sample ? wp_get_attachment_url($sample[0]->ID) : '';
    $speeds = array('0.5' => '۰٫۵×', '0.75' => '۰٫۷۵×', '1' => __('Normal', 'nias-course-widget'), '1.25' => '۱٫۲۵×', '1.5' => '۱٫۵×', '1.75' => '۱٫۷۵×', '2' => '۲×');
    $on_off_row = function ($name, $title, $desc) {
        nias_set_toggle_row($name, $title, $desc, nias_player_fields()[$name][1]);
    };
    $color = function ($name, $label) {
        $v = nias_player_opt($name);
        echo '<div class="nias-field npl-color"><label class="nias-flabel">' . esc_html($label) . '</label><div class="npl-color-row"><input type="color" value="' . esc_attr($v) . '" data-for="' . esc_attr($name) . '"><input type="text" class="nias-input" dir="ltr" maxlength="7" name="' . esc_attr($name) . '" value="' . esc_attr($v) . '" data-nias-player="' . esc_attr($name) . '"></div></div>';
    };
    $num = function ($name, $label, $desc, $min, $max, $suffix = '') {
        $v = nias_player_opt($name);
        echo '<div class="nias-field" style="max-width:260px"><label class="nias-flabel">' . esc_html($label) . '</label>' . ($desc ? '<div class="nias-fdesc">' . esc_html($desc) . '</div>' : '')
            . '<div class="npl-num"><input type="number" class="nias-input" dir="ltr" min="' . (int) $min . '" max="' . (int) $max . '" name="' . esc_attr($name) . '" value="' . esc_attr($v) . '" data-nias-player="' . esc_attr($name) . '">' . ($suffix ? '<span>' . esc_html($suffix) . '</span>' : '') . '</div></div>';
    };
    $seg = function ($name, $title, $opts, $desc = '') {
        echo '<div class="nias-row-block nias-row-sep"><div class="nias-row-title">' . esc_html($title) . '</div>' . ($desc ? '<div class="nias-row-desc">' . esc_html($desc) . '</div>' : '');
        nias_set_segmented($name, $opts, nias_player_fields()[$name][1]);
        echo '</div>';
    };
    // Own select/multiselect: the shared helpers have no default for never-saved options.
    $select = function ($name, $label, $desc, $opts) {
        $cur = (string) nias_player_opt($name);
        echo '<div class="nias-field"><label class="nias-flabel">' . esc_html($label) . '</label>' . ($desc ? '<div class="nias-fdesc">' . esc_html($desc) . '</div>' : '') . '<div class="nias-select-wrap"><select class="nias-select" name="' . esc_attr($name) . '">';
        foreach ($opts as $v => $t) {
            echo '<option value="' . esc_attr($v) . '"' . selected($cur, (string) $v, false) . '>' . esc_html($t) . '</option>';
        }
        echo '</select></div></div>';
    };
    $multi = function ($name, $label, $desc, $opts) {
        $cur = array_map('strval', (array) nias_player_opt($name));
        echo '<div class="nias-field"><label class="nias-flabel">' . esc_html($label) . '</label>' . ($desc ? '<div class="nias-fdesc">' . esc_html($desc) . '</div>' : '') . '<div class="npl-chips">';
        foreach ($opts as $v => $t) {
            echo '<label class="npl-chip"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($v) . '"' . checked(in_array((string) $v, $cur, true), true, false) . '><span>' . esc_html($t) . '</span></label>';
        }
        echo '</div></div>';
    };
    $icon = function ($path) {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    };
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('player'); ?>
        <div class="nias-set-shell npl-shell">
        <div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>
            <form method="post" id="npl-form">
                <?php wp_nonce_field('nias_player_settings', 'nias_player_nonce'); ?>

                <?php nias_set_card_open($icon('<circle cx="13.5" cy="6.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><circle cx="17.5" cy="15.5" r="2.5"/><path d="M12 22a10 10 0 1 1 10-10c0 2-1.5 3-3 3h-2a2 2 0 0 0-1 3.7A2 2 0 0 1 12 22Z"/>'), __('Appearance', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="npl-grid">
                            <?php $color('nias_player_accent', __('Main color (buttons and progress bar)', 'nias-course-widget')); ?>
                            <?php $color('nias_player_seen_color', __('"Watched" bar color', 'nias-course-widget')); ?>
                            <?php $num('nias_player_radius', __('Corner radius', 'nias-course-widget'), '', 0, 40, 'px'); ?>
                        </div>
                    </div>
                    <?php $seg('nias_player_big_style', __('Play button in the middle of the video', 'nias-course-widget'), array('circle' => __('Circle', 'nias-course-widget'), 'rounded' => __('Rounded rectangle', 'nias-course-widget'), 'none' => __('No button', 'nias-course-widget'))); ?>
                    <?php $seg('nias_player_ratio', __('Aspect ratio', 'nias-course-widget'), array('16:9' => '16:9', '4:3' => '4:3', '21:9' => '21:9', '1:1' => '1:1', '9:16' => __('9:16 (vertical)', 'nias-course-widget')), __('For the lesson content player and the modern course view.', 'nias-course-widget')); ?>
                    <div class="nias-card-padx">
                        <?php nias_set_image_row('nias_player_poster', __('Default image before playing (poster)', 'nias-course-widget'), __('Shown when the video has no image of its own.', 'nias-course-widget')); ?>
                        <?php nias_set_image_row('nias_player_logo', __('Logo on the video', 'nias-course-widget'), __('PNG with a transparent background; also visible in full screen.', 'nias-course-widget')); ?>
                    </div>
                    <?php $seg('nias_player_logo_pos', __('Logo position', 'nias-course-widget'), array('tr' => __('Top right', 'nias-course-widget'), 'tl' => __('Top left', 'nias-course-widget'), 'br' => __('Bottom right', 'nias-course-widget'), 'bl' => __('Bottom left', 'nias-course-widget'))); ?>
                    <div class="nias-row-block nias-row-sep"><div class="npl-grid">
                        <?php $num('nias_player_logo_opacity', __('Logo opacity', 'nias-course-widget'), '', 10, 100, '٪'); ?>
                        <div class="nias-field"><label class="nias-flabel"><?php esc_html_e('Logo link (optional)', 'nias-course-widget'); ?></label><input type="url" class="nias-input" dir="ltr" name="nias_player_logo_link" value="<?php echo esc_attr(nias_player_opt('nias_player_logo_link')); ?>" placeholder="https://"></div>
                    </div></div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open($icon('<rect x="2" y="6" width="20" height="12" rx="3"/><path d="M6 12h.01M10 12h.01M14 12h4"/>'), __('Controls', 'nias-course-widget')); ?>
                    <?php
                    $on_off_row('nias_player_ctrl_skip', __('Forward and back buttons', 'nias-course-widget'), '');
                    $seg('nias_player_skip_seconds', __('Skip amount (seconds)', 'nias-course-widget'), array('5' => '۵', '10' => '۱۰', '15' => '۱۵', '30' => '۳۰'), __('For the buttons, the J/L keys and double tap on mobile.', 'nias-course-widget'));
                    $on_off_row('nias_player_ctrl_volume', __('Volume control', 'nias-course-widget'), '');
                    $on_off_row('nias_player_ctrl_time', __('Show time', 'nias-course-widget'), __('Clicking the time switches between "elapsed" and "remaining".', 'nias-course-widget'));
                    $on_off_row('nias_player_ctrl_speed', __('Playback speed selection', 'nias-course-widget'), '');
                    $on_off_row('nias_player_ctrl_quality', __('Quality selection', 'nias-course-widget'), __('Students change the video quality from the player\'s settings menu: for HLS and DASH videos with several qualities, and for MP4 files when "Other qualities" (e.g. 720p and 480p) were added in the lesson editor. Each user\'s choice is remembered.', 'nias-course-widget'));
                    $on_off_row('nias_player_ctrl_seen', __('"Watched" badge and bar', 'nias-course-widget'), __('Students see what percentage and which parts of the video they have watched.', 'nias-course-widget'));
                    $on_off_row('nias_player_ctrl_pip', __('Picture in picture (PiP)', 'nias-course-widget'), '');
                    $on_off_row('nias_player_ctrl_fullscreen', __('Full screen', 'nias-course-widget'), '');
                    $on_off_row('nias_player_ctrl_keyboard', __('Keyboard shortcuts', 'nias-course-widget'), __('Space: play/pause · ← →: 5 seconds · J/L: skip · ↑ ↓: volume · M: mute · F: full screen · 0 to 9: jump to 0% to 90%', 'nias-course-widget'));
                    $on_off_row('nias_player_ctrl_doubletap', __('Double tap to skip on mobile', 'nias-course-widget'), '');
                    ?>
                    <div class="nias-row-block nias-row-sep"><?php $num('nias_player_idle', __('Hide controls while playing after', 'nias-course-widget'), '', 1, 15, __('seconds', 'nias-course-widget')); ?></div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open($icon('<circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4V8Z"/>'), __('Playback', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <?php $multi('nias_player_speeds', __('Selectable speeds', 'nias-course-widget'), __('"Normal" is always available.', 'nias-course-widget'), $speeds); ?>
                        <div class="npl-grid">
                            <?php $select('nias_player_default_speed', __('Default speed', 'nias-course-widget'), '', $speeds); ?>
                            <?php $select('nias_player_max_speed', __('Maximum allowed speed', 'nias-course-widget'), __('For courses that should not be watched fast.', 'nias-course-widget'), array('1' => __('Normal (no speed-up)', 'nias-course-widget'), '1.25' => '۱٫۲۵×', '1.5' => '۱٫۵×', '1.75' => '۱٫۷۵×', '2' => '۲×')); ?>
                        </div>
                    </div>
                    <?php $seg('nias_player_resume', __('Resume from the last position', 'nias-course-widget'), array('auto' => __('Automatic', 'nias-course-widget'), 'ask' => __('Ask', 'nias-course-widget'), 'off' => __('Always from the start', 'nias-course-widget'))); ?>
                    <?php
                    $on_off_row('nias_player_auto_next', __('Autoplay next lesson', 'nias-course-widget'), __('At the end of the video, the next lesson starts after a (cancellable) countdown. In the modern course view.', 'nias-course-widget'));
                    ?>
                    <div class="nias-row-block nias-row-sep" data-nias-show-when="nias_player_auto_next=on"><?php $num('nias_player_auto_next_delay', __('Countdown', 'nias-course-widget'), '', 2, 30, __('seconds', 'nias-course-widget')); ?></div>
                    <?php
                    $on_off_row('nias_player_autoplay', __('Start playing automatically', 'nias-course-widget'), __('Browsers block autoplay with sound; in that case the video starts muted.', 'nias-course-widget'));
                    $on_off_row('nias_player_remember', __('Remember each user\'s volume and speed', 'nias-course-widget'), '');
                    $seg('nias_player_preload', __('Preload', 'nias-course-widget'), array('metadata' => __('Metadata only (recommended)', 'nias-course-widget'), 'auto' => __('Part of the video', 'nias-course-widget'), 'none' => __('None (lowest usage)', 'nias-course-widget')));
                    ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open($icon('<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>'), __('Learning rules', 'nias-course-widget')); ?>
                    <div class="nias-row-block">
                        <div class="nias-row-desc"><?php esc_html_e('The player records every second of video the student really watches; skipping ahead, paused time and speeds above 2x do not count. Each student\'s report is on the product edit page, in the "Video watch report" box.', 'nias-course-widget'); ?></div>
                        <?php $num('nias_video_complete_pct', __('Watch percentage to complete a lesson', 'nias-course-widget'), __('Course progress and the certificate\'s "minimum progress" requirement are based on this.', 'nias-course-widget'), 10, 100, '٪'); ?>
                    </div>
                    <?php $seg('nias_player_seek_lock', __('Prevent skipping ahead', 'nias-course-widget'), array('off' => __('Free', 'nias-course-widget'), 'first' => __('Until the lesson is completed', 'nias-course-widget'), 'always' => __('Always', 'nias-course-widget')), __('Students cannot jump to a part they have not watched yet (going back is free). In "Until the lesson is completed" mode, skipping is allowed once the completion percentage is reached.', 'nias-course-widget')); ?>
                    <?php $on_off_row('nias_player_pause_hidden', __('Pause when the tab is left', 'nias-course-widget'), __('If the student switches to another tab or app, the video pauses (except in picture-in-picture).', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open($icon('<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z"/>'), __('Video protection', 'nias-course-widget')); ?>
                    <?php $on_off_row('nias_video_watermark', __('Personal watermark for each student', 'nias-course-widget'), __('The student\'s details are shown faintly on the video so unauthorized recording and sharing can be traced.', 'nias-course-widget')); ?>
                    <div data-nias-show-when="nias_video_watermark=on">
                        <div class="nias-row-block nias-row-sep">
                            <?php nias_set_text_field('nias_player_wm_text', __('Watermark text', 'nias-course-widget'), '{name} — {phone}'); ?>
                            <div class="nias-row-desc"><?php esc_html_e('Fields: {name} full name · {first_name} · {phone} mobile (partly masked) · {email} email (masked) · {username} · {user_id} · {date} today\'s date · {site} domain', 'nias-course-widget'); ?></div>
                        </div>
                        <?php $seg('nias_player_wm_mode', __('Display mode', 'nias-course-widget'), array('move' => __('Moving (recommended)', 'nias-course-widget'), 'corner' => __('Fixed in a corner', 'nias-course-widget'))); ?>
                        <div class="nias-row-block nias-row-sep"><div class="npl-grid">
                            <?php $num('nias_player_wm_opacity', __('Opacity', 'nias-course-widget'), '', 5, 100, '٪'); ?>
                            <?php $num('nias_player_wm_size', __('Text size', 'nias-course-widget'), '', 9, 40, 'px'); ?>
                            <?php $num('nias_player_wm_interval', __('Move every', 'nias-course-widget'), '', 3, 60, __('seconds', 'nias-course-widget')); ?>
                        </div></div>
                    </div>
                    <?php $on_off_row('nias_player_no_download', __('Prevent download', 'nias-course-widget'), __('Video download buttons are removed, right click and "Save video" are blocked on the course page and player, Ctrl+S is disabled, and the video link does not play/save if opened directly in the browser. Pressing PrintScreen or the snipping tool (Win+Shift+S) and switching to another app turns the video black and pauses it. Note: no website can fully block screenshots or screen recording; the user-name watermark (on this page) is the main deterrent.', 'nias-course-widget')); ?>
                    <?php $on_off_row('nias_player_block_context', __('Disable right click on the video', 'nias-course-widget'), __('The browser\'s "Save video" options are hidden. The player\'s download button is always hidden.', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open($icon('<path d="M2 12h3l3-8 4 16 3-8h7"/>'), __('Streaming (HLS / DASH)', 'nias-course-widget')); ?>
                    <?php $seg('nias_player_hls_start', __('Starting quality', 'nias-course-widget'), array('auto' => __('Automatic', 'nias-course-widget'), 'low' => __('Lowest (fast start)', 'nias-course-widget'), 'high' => __('Highest', 'nias-course-widget'))); ?>
                    <?php $on_off_row('nias_player_hls_cap', __('Limit quality to the player size', 'nias-course-widget'), __('On a small player or mobile, 1080p is not loaded; the student uses less data.', 'nias-course-widget')); ?>
                    <div class="nias-row-block nias-row-sep"><?php $num('nias_player_buffer', __('Buffer ahead of playback', 'nias-course-widget'), __('More = smoother playback on weak connections, more data used if the video is left.', 'nias-course-widget'), 10, 120, __('seconds', 'nias-course-widget')); ?></div>
                <?php nias_set_card_close(); ?>

                <div class="npl-actions">
                    <?php nias_set_save_button('nias_save_player_settings'); ?>
                    <button type="submit" name="nias_player_reset" value="1" class="nias-btn-del" onclick="return confirm('<?php echo esc_js(__('Reset all player settings to default?', 'nias-course-widget')); ?>')"><?php esc_html_e('Reset to default', 'nias-course-widget'); ?></button>
                </div>
            </form>
        </div>

        <aside class="npl-preview">
            <div class="nias-card">
                <div class="nias-card-head"><?php echo $icon('<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>'); // phpcs:ignore ?><div class="nias-card-title"><?php esc_html_e('Live preview', 'nias-course-widget'); ?></div></div>
                <div class="nias-card-pad">
                    <div id="npl-stage" class="npl-stage"></div>
                    <div class="nias-field" style="margin-top:12px">
                        <label class="nias-flabel"><?php esc_html_e('Sample video', 'nias-course-widget'); ?></label>
                        <input type="url" id="npl-sample" class="nias-input" dir="ltr" value="<?php echo esc_attr($sample_url); ?>" placeholder="https://…/video.mp4 | master.m3u8 | manifest.mpd">
                    </div>
                    <div class="nias-fdesc"><?php esc_html_e('Changes apply here immediately; click "Save" to apply them on the site. The watermark is shown with your own details. The preview is not recorded in student progress.', 'nias-course-widget'); ?></div>
                </div>
            </div>
        </aside>
        </div>
    </div>
    <style>
    .npl-shell{align-items:flex-start}
    .npl-preview{width:420px;flex:none;position:sticky;top:46px}
    .npl-stage{border-radius:12px;overflow:hidden;background:#0e1518}
    .npl-stage:empty::before{content:"<?php echo esc_js(__('Enter a video address for the preview', 'nias-course-widget')); ?>";display:flex;align-items:center;justify-content:center;aspect-ratio:16/9;color:#8a94a6;font-size:13px}
    .npl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
    .npl-color-row{display:flex;gap:8px;align-items:center}
    .npl-color-row input[type=color]{width:44px;height:38px;border:1px solid #e3e7ef;border-radius:8px;padding:2px;background:#fff;cursor:pointer;flex:none}
    .npl-color-row .nias-input{max-width:110px;font-family:monospace}
    .npl-num{display:flex;align-items:center;gap:8px}
    .npl-num .nias-input{max-width:120px}
    .npl-num span{color:#7b8497;font-size:13px}
    .npl-chips{display:flex;flex-wrap:wrap;gap:8px}
    .npl-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e3e7ef;border-radius:10px;padding:7px 12px;cursor:pointer;font-size:13px;background:#fff}
    .npl-chip:has(input:checked){border-color:#3858e9;background:#eef1fd;color:#3858e9}
    .npl-chip input{margin:0}
    .npl-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    @media (max-width:1280px){.npl-shell{flex-direction:column}.npl-preview{width:100%;position:static}}
    </style>
    <script>
    window.NIAS_PLAYER_CONFIG = <?php echo wp_json_encode(nias_player_config()); ?>;
    </script>
    <script src="<?php echo esc_url(plugin_dir_url(NIAS_COURSE . 'nias-course-widget.php') . 'assets/js/nias-player.js?v=' . NIAS_COURSE_VERSION . '.' . (int) @filemtime(NIAS_COURSE . 'assets/js/nias-player.js')); ?>"></script>
    <script>
    (function () {
        var form = document.getElementById('npl-form'), stage = document.getElementById('npl-stage'), sample = document.getElementById('npl-sample');
        var base = window.NIAS_PLAYER_CONFIG, player = null, t = null;
        var tokens = <?php
            $u = wp_get_current_user();
            echo wp_json_encode(array(
                '{name}' => trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name,
                '{first_name}' => $u->first_name ?: $u->display_name,
                '{phone}' => '0912***4567', '{email}' => mb_substr((string) $u->user_login, 0, 2) . '***@' . wp_parse_url(home_url(), PHP_URL_HOST),
                '{username}' => $u->user_login, '{user_id}' => (string) $u->ID,
                '{date}' => function_exists('nias_cert_jalali') ? nias_cert_jalali(current_time('Y-m-d'), 'numeric') : current_time('Y/m/d'),
                '{site}' => wp_parse_url(home_url(), PHP_URL_HOST),
            ));
        ?>;
        function val(name) {
            var els = form.querySelectorAll('[name="' + name + '"],[name="' + name + '[]"]');
            if (!els.length) { return null; }
            if (els[0].type === 'radio') { var c = form.querySelector('[name="' + name + '"]:checked'); return c ? c.value : null; }
            if (els.length === 2 && els[0].type === 'hidden' && els[1].type === 'checkbox') { return els[1].checked ? 'on' : 'off'; }
            if (els[0].type === 'checkbox' && /\[\]$/.test(els[0].name)) { return Array.prototype.filter.call(els, function (o) { return o.checked; }).map(function (o) { return o.value; }); }
            return els[0].value;
        }
        function on(name) { return val(name) === 'on'; }
        function num(name, d) { var n = parseFloat(val(name)); return isNaN(n) ? d : n; }
        // Mirrors nias_player_config() so the preview matches the site.
        function config() {
            var wmText = String(val('nias_player_wm_text') || '{name} — {phone}').replace(/\{[a-z_]+\}/g, function (k) { return tokens[k] != null ? tokens[k] : k; });
            return {
                accent: val('nias_player_accent') || base.accent,
                seenColor: val('nias_player_seen_color') || base.seenColor,
                radius: num('nias_player_radius', 14),
                bigStyle: val('nias_player_big_style') || 'circle',
                ratio: val('nias_player_ratio') || '16:9',
                poster: val('nias_player_poster') || '',
                logo: { src: val('nias_player_logo') || '', pos: val('nias_player_logo_pos') || 'tr', opacity: num('nias_player_logo_opacity', 70) / 100, link: '' },
                controls: {
                    skip: on('nias_player_ctrl_skip'), volume: on('nias_player_ctrl_volume'), time: on('nias_player_ctrl_time'),
                    speed: on('nias_player_ctrl_speed'), quality: on('nias_player_ctrl_quality'), pip: on('nias_player_ctrl_pip'),
                    fullscreen: on('nias_player_ctrl_fullscreen'), seen: on('nias_player_ctrl_seen'), keyboard: on('nias_player_ctrl_keyboard'),
                    doubleTap: on('nias_player_ctrl_doubletap')
                },
                skipSeconds: num('nias_player_skip_seconds', 10),
                idle: num('nias_player_idle', 3),
                speeds: (val('nias_player_speeds') || []).map(Number),
                defaultSpeed: num('nias_player_default_speed', 1),
                maxSpeed: num('nias_player_max_speed', 2),
                resume: val('nias_player_resume') || 'auto',
                autoNext: on('nias_player_auto_next'), autoNextDelay: num('nias_player_auto_next_delay', 6),
                autoplay: false, preload: val('nias_player_preload') || 'metadata', remember: false,
                completePct: num('nias_video_complete_pct', 90),
                seekLock: val('nias_player_seek_lock') || 'off',
                pauseHidden: on('nias_player_pause_hidden'),
                blockContext: on('nias_player_block_context') || on('nias_player_no_download'),
                noDownload: on('nias_player_no_download'),
                watermark: { text: on('nias_video_watermark') ? wmText : '', mode: val('nias_player_wm_mode') || 'move', opacity: num('nias_player_wm_opacity', 35) / 100, size: num('nias_player_wm_size', 13), interval: num('nias_player_wm_interval', 9) },
                hls: { start: val('nias_player_hls_start') || 'auto', cap: on('nias_player_hls_cap'), buffer: num('nias_player_buffer', 30) }
            };
        }
        function render() {
            if (player) { player.destroy(); player = null; }
            stage.innerHTML = '';
            var src = sample.value.trim();
            if (!src || !window.NiasPlayer) { return; }
            var box = document.createElement('div');
            stage.appendChild(box);
            player = window.NiasPlayer.create(box, {
                src: src, ratio: true, config: config(), track: true, startAt: 0,
                next: { title: 'درس بعدی (نمونه)', go: function () { player.seek(0); } }
            });
        }
        function later() { clearTimeout(t); t = setTimeout(render, 350); }
        // Color pickers ↔ hex inputs.
        form.querySelectorAll('input[type=color][data-for]').forEach(function (p) {
            var txt = form.querySelector('[name="' + p.getAttribute('data-for') + '"]');
            p.addEventListener('input', function () { txt.value = p.value; later(); });
            txt.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(txt.value)) { p.value = txt.value; } later(); });
        });
        form.addEventListener('input', later);
        form.addEventListener('change', later);
        sample.addEventListener('change', render);
        // Media-library pickers set hidden inputs without firing events: watch them.
        var imgSig = '';
        setInterval(function () {
            var s = (val('nias_player_poster') || '') + '|' + (val('nias_player_logo') || '');
            if (imgSig && s !== imgSig) { render(); }
            imgSig = s;
        }, 800);
        render();
    })();
    </script>
    <?php
}
