<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Standalone "beautiful" audio player (پلیر صوتی).
 *
 * A self-contained waveform audio player — the same look as the modern course
 * view's audio lesson — that works in the legacy contexts (the Elementor course
 * widgets and the "دوره های من" account accordion) where there is no JS
 * controller to hook into. Each player is just a placeholder div carrying the
 * audio URL; a tiny shared script upgrades it on the client (play/pause,
 * clickable waveform, current/total time in Persian digits).
 *
 * Public API:
 *   nias_audio_player_html($src, $title = '')  → placeholder markup for one file
 *   nias_audio_upgrade_html($html)             → convert <audio>/[audio]/bare
 *                                                audio URLs inside free HTML
 *
 * @package nias-course-widget
 */

/**
 * Print the shared player assets (CSS + JS) once per request.
 */
function nias_audio_player_assets()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .nias-ap{--nias-ap-accent:#1e83f0;box-sizing:border-box;display:flex;align-items:center;gap:14px;width:100%;max-width:560px;margin:10px 0;padding:12px 14px;border-radius:16px;background:linear-gradient(135deg,#f3f7fb,#e9eef5);border:1px solid #e2e8f0;direction:rtl}
    .nias-ap *{box-sizing:border-box}
    .nias-ap-btn{width:46px;height:46px;flex:none;border:0;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;background:var(--nias-ap-accent);box-shadow:0 8px 18px -6px rgba(30,131,240,.7);transition:transform .15s}
    .nias-ap-btn:hover{transform:scale(1.07)}
    .nias-ap-btn svg{width:20px;height:20px;fill:currentColor}
    .nias-ap-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
    .nias-ap-wave{display:flex;align-items:center;gap:2px;height:34px;cursor:pointer;direction:ltr}
    .nias-ap-bar{flex:1 1 0;min-width:2px;border-radius:99px;background:rgba(30,60,90,.18);transition:background .15s,transform .15s}
    .nias-ap-bar.on{background:var(--nias-ap-accent)}
    .nias-ap-wave:hover .nias-ap-bar{transform:scaleY(1.08)}
    .nias-ap-time{font-size:12.5px;font-weight:600;color:#5b666c;font-variant-numeric:tabular-nums;direction:ltr;align-self:flex-start}
    .nias-ap-time .sep{opacity:.5;margin:0 4px}
    .nias-ap audio{display:none}
    </style>
    <script>
    (function () {
        if (window.NiasAudio) { return; }

        function fa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
        function fmt(t) {
            t = Math.max(0, Math.floor(t || 0));
            var m = Math.floor(t / 60), s = t % 60;
            return fa(m) + ':' + fa(s < 10 ? '0' + s : s);
        }
        var PLAY = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
        var PAUSE = '<svg viewBox="0 0 24 24"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>';

        function build(box) {
            if (!box || box.getAttribute('data-nias-ap-ready') === '1') { return; }
            var src = box.getAttribute('data-src');
            if (!src) { return; }
            box.setAttribute('data-nias-ap-ready', '1');

            var NB = 40, bars = '';
            for (var i = 0; i < NB; i++) {
                var r = Math.abs(Math.sin((i + 1) * 12.9898) * 43758.5453);
                r = r - Math.floor(r);
                bars += '<span class="nias-ap-bar" data-i="' + i + '" style="height:' + Math.round(24 + r * 76) + '%"></span>';
            }
            box.innerHTML =
                '<button type="button" class="nias-ap-btn" data-act="toggle" aria-label="پخش/توقف">' + PLAY + '</button>' +
                '<div class="nias-ap-main">' +
                    '<div class="nias-ap-wave" role="slider" aria-label="نوار پخش صوت">' + bars + '</div>' +
                    '<div class="nias-ap-time"><span class="cur">۰:۰۰</span><span class="sep">/</span><span class="dur">۰:۰۰</span></div>' +
                '</div>';

            var a = document.createElement('audio');
            a.preload = 'metadata';
            a.src = src;
            box.appendChild(a);

            var btn = box.querySelector('[data-act="toggle"]');
            var wave = box.querySelector('.nias-ap-wave');
            var curEl = box.querySelector('.cur');
            var durEl = box.querySelector('.dur');
            var barEls = box.querySelectorAll('.nias-ap-bar');

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
            a.addEventListener('play', function () { btn.innerHTML = PAUSE; });
            a.addEventListener('pause', function () { btn.innerHTML = PLAY; });
            a.addEventListener('loadedmetadata', function () { durEl.textContent = fmt(a.duration); paint(); });
            a.addEventListener('timeupdate', paint);
            a.addEventListener('ended', function () { btn.innerHTML = PLAY; paint(); });
            wave.addEventListener('click', function (ev) {
                var b = ev.target && ev.target.closest ? ev.target.closest('.nias-ap-bar') : null;
                if (!b || !a.duration) { return; }
                var i = parseInt(b.getAttribute('data-i'), 10);
                try { a.currentTime = (i + 0.5) / NB * a.duration; } catch (e) {}
                paint();
            });
        }

        function scan(root) {
            var list = (root || document).querySelectorAll('.nias-ap[data-src]');
            for (var i = 0; i < list.length; i++) { build(list[i]); }
        }

        window.NiasAudio = { build: build, scan: scan };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { scan(); });
        } else {
            scan();
        }
    })();
    </script>
    <?php
}

/**
 * Placeholder markup for one audio file, upgraded on the client.
 *
 * @param string $src   Audio file URL.
 * @param string $title Optional accessible title (unused visually for now).
 * @return string
 */
function nias_audio_player_html($src, $title = '')
{
    $src = trim((string) $src);
    if ($src === '') {
        return '';
    }
    nias_audio_player_assets();
    return '<div class="nias-ap" data-src="' . esc_url($src) . '"'
        . ($title !== '' ? ' data-title="' . esc_attr($title) . '"' : '')
        . '></div>';
}

/**
 * Print the Plyr video player assets (CSS + JS) once per request.
 */
function nias_video_player_assets()
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    $css_url = plugin_dir_url(NIAS_COURSE . 'nias-course-widget.php') . 'assets/niasplyr.css?v=' . NIAS_COURSE_VERSION;
    $js_url  = plugin_dir_url(NIAS_COURSE . 'nias-course-widget.php') . 'assets/niasplyrscript.js?v=' . NIAS_COURSE_VERSION;
    ?>
    <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>" />
    <script src="<?php echo esc_url($js_url); ?>"></script>
    <script>
    (function () {
        if (window._NiasVideoInit) { return; }
        window._NiasVideoInit = true;
        function initPlayers() {
            document.querySelectorAll('.nias-vp-wrap[data-src]:not([data-plyr-ready])').forEach(function (wrap) {
                wrap.setAttribute('data-plyr-ready', '1');
                var src = wrap.getAttribute('data-src');
                var vid = document.createElement('video');
                vid.setAttribute('playsinline', '');
                vid.setAttribute('controls', '');
                vid.style.width = '100%';
                vid.style.borderRadius = '12px';
                var source = document.createElement('source');
                source.src = src;
                vid.appendChild(source);
                wrap.appendChild(vid);
                if (typeof Plyr !== 'undefined') {
                    new Plyr(vid, { controls: ['play-large','play','progress','current-time','mute','volume','fullscreen'] });
                }
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPlayers);
        } else {
            initPlayers();
        }
    })();
    </script>
    <style>
    .nias-vp-wrap { width: 100%; max-width: 720px; margin: 10px 0; border-radius: 12px; overflow: hidden; background: #000; }
    .nias-vp-wrap .plyr { border-radius: 12px; }
    </style>
    <?php
}

/**
 * Placeholder markup for one video file, upgraded on the client with Plyr.
 *
 * @param string $src   Video file URL.
 * @return string
 */
function nias_video_player_html($src)
{
    $src = trim((string) $src);
    if ($src === '') {
        return '';
    }
    nias_video_player_assets();
    return '<div class="nias-vp-wrap" data-src="' . esc_url($src) . '"></div>';
}

/**
 * Convert audio references inside free-form HTML into beautiful players:
 *   - [audio src="…"] (and mp3/wav/… variants) shortcodes,
 *   - <audio …><source src="…"></audio> blocks,
 *   - a bare audio URL standing on its own.
 *   - a bare video URL (mp4/webm/…) standing on its own → Plyr video player.
 *
 * Everything else is returned untouched.
 *
 * @param string $html
 * @return string
 */
function nias_audio_upgrade_html($html)
{
    $html = (string) $html;
    if ($html === '') {
        return $html;
    }
    // If both player types are already present, skip re-processing
    $has_audio = stripos($html, 'nias-ap') !== false;
    $has_video = stripos($html, 'nias-vp-wrap') !== false;
    if ($has_audio && $has_video) {
        return $html; // already fully upgraded
    }

    // 1) [audio src="…"] shortcodes.
    $html = preg_replace_callback('/\[audio\b([^\]]*)\]/i', function ($m) {
        if (preg_match('/(?:src|mp3|m4a|wav|ogg|oga|aac|flac)\s*=\s*["\']([^"\']+)["\']/i', $m[1], $u)) {
            return nias_audio_player_html($u[1]);
        }
        return $m[0];
    }, $html);

    // 2) <audio …>…</audio> blocks — use the element's src or first <source>.
    $html = preg_replace_callback('/<audio\b[^>]*>.*?<\/audio>/is', function ($m) {
        if (preg_match('/<audio\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $u)
            || preg_match('/<source\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $u)) {
            return nias_audio_player_html($u[1]);
        }
        return $m[0];
    }, $html);

    // 3) A bare audio URL on its own line / cell.
    $html = preg_replace_callback('~(^|>|\n)(\s*)(https?://[^\s<"\']+\.(?:mp3|wav|m4a|aac|oga|flac|opus))(\s*)(?=<|\n|$)~i', function ($m) {
        return $m[1] . $m[2] . nias_audio_player_html($m[3]) . $m[4];
    }, $html);

    // 4) A bare video URL on its own line / cell → Plyr video player.
    $html = preg_replace_callback('~(^|>|\n)(\s*)(https?://[^\s<"\']+\.(?:mp4|m4v|webm|ogv|mov))(\?[^\s<"\']*)?(\s*)(?=<|\n|$)~i', function ($m) {
        $url = $m[3] . (isset($m[4]) ? $m[4] : '');
        return $m[1] . $m[2] . nias_video_player_html($url) . $m[5];
    }, $html);

    // 5) A video URL wrapped in an anchor tag: <a href="...mp4">…</a>
    $html = preg_replace_callback('~<a\b[^>]*\bhref\s*=\s*["\']([^"\']+\.(?:mp4|m4v|webm|ogv|mov)(?:\?[^"\']*)?)["\'][^>]*>.*?</a>~is', function ($m) {
        return nias_video_player_html($m[1]);
    }, $html);

    return $html;
}
