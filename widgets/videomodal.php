<?php
defined('ABSPATH') || exit;

/**
 * Shared "preview" modal for the legacy course views.
 *
 * A single modal plays a lesson preview triggered by any `.nias-preview-tag` /
 * `.video-lesson-preview` element. It supports three preview kinds:
 *   - embed code : raw <iframe>/<script> snippet on the trigger's data-embed
 *   - direct file: an mp4/webm/… URL → played with Plyr
 *   - other URL  : an embeddable page (Aparat/YouTube…) → shown in an <iframe>
 *
 * Print it once per request via nias_request_preview_modal(); the Elementor
 * course widgets request it after render, and the "دوره های من" account view
 * requests it when it outputs a preview trigger.
 */

/** Ask for the preview modal to be printed in the footer (once per request). */
function nias_request_preview_modal()
{
    static $hooked = false;
    if ($hooked) {
        return;
    }
    $hooked = true;
    wp_enqueue_script('jquery');
    add_action('wp_footer', 'nias_modal_player');
    add_action('wp_footer', 'nias_modal_player_script');
}

// The Elementor course widgets request the modal after they render.
add_action('elementor/frontend/after_render', function ($element) {
    if (in_array($element->get_name(), ['niaslessonswoo', 'niaslessons'])) {
        nias_request_preview_modal();
    }
});

// Modal markup.
function nias_modal_player()
{
    ?>
    <div id="videoModal" class="nias-video-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);justify-content:center;align-items:center;z-index:99999;">
        <div class="nias-video-modal-inner" style="position:relative;width:92%;max-width:880px;">
            <button id="closeModal" type="button" style="position:relative;top:0;right:0;background:#e11d48;color:#fff;font-size:13px;border:none;cursor:pointer;font-family:inherit;border-radius:8px 8px 0 0;padding:7px 14px;">بستن</button>
            <div id="niasModalStage" class="nias-modal-stage" style="position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:0 8px 8px 8px;overflow:hidden;">
                <video id="modalVideo" class="plyr-video" controls playsinline style="width:100%;height:100%;"></video>
                <div id="niasModalEmbed" class="nias-modal-embed" style="position:absolute;inset:0;display:none;"></div>
            </div>
        </div>
    </div>
    <style>
        .nias-modal-embed iframe{width:100%!important;height:100%!important;border:0}
        .nias-modal-embed .h_iframe-aparat_embed_frame,
        .nias-modal-embed .h_iframe-aparat_embed_frame span{position:static!important;padding:0!important;height:100%!important;display:block}
    </style>
    <?php
}

// Modal behaviour (Plyr for files, iframe/embed for the rest).
function nias_modal_player_script()
{
    ?>
    <link rel="stylesheet" href="<?php echo plugin_dir_url(__DIR__) . 'assets/niasplyr.css?v=', NIAS_COURSE_VERSION; ?>" />
    <script src="<?php echo plugin_dir_url(__DIR__) . 'assets/niasplyrscript.js?v=', NIAS_COURSE_VERSION; ?>"></script>
    <script>
    jQuery(document).ready(function ($) {
        var $modal   = $('#videoModal');
        var $embed   = $('#niasModalEmbed');
        var videoEl  = document.getElementById('modalVideo');
        var player   = (typeof Plyr !== 'undefined') ? new Plyr('#modalVideo', {
            controls: ['play-large','play','rewind','fast-forward','progress','current-time','duration','mute','volume','settings','fullscreen'],
            settings: ['speed'],
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] }
        }) : null;

        function isVideoFile(url) { return /\.(mp4|m4v|webm|ogg|ogv|mov)(\?.*)?$/i.test(url); }

        // Re-create <script> nodes so embed snippets (e.g. Aparat) actually run —
        // scripts injected via innerHTML are inert.
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

        // Show/hide the Plyr player (its wrapper element, when present).
        function showVideo(show) {
            var c = (player && player.elements && player.elements.container) ? player.elements.container : videoEl;
            if (c) { c.style.display = show ? '' : 'none'; }
        }

        function openModal(opts) {
            $modal.css('display', 'flex');
            if (opts.embed) {
                if (player) { player.stop(); }
                showVideo(false);
                $embed.show()[0].innerHTML = opts.embed;
                runScripts($embed[0]);
            } else if (opts.src && isVideoFile(opts.src) && player) {
                $embed.hide()[0].innerHTML = '';
                showVideo(true);
                player.source = { type: 'video', sources: [{ src: opts.src }] };
                player.play();
            } else if (opts.src) {
                // Embeddable page (Aparat/YouTube/…): show it in an iframe.
                if (player) { player.stop(); }
                showVideo(false);
                $embed.show()[0].innerHTML = '<iframe src="' + opts.src + '" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"></iframe>';
            }
        }

        function closeModal() {
            $modal.hide();
            if (player) { player.stop(); }
            $embed.hide()[0].innerHTML = '';
            showVideo(true);
        }

        $('#closeModal').on('click', closeModal);
        $modal.on('click', function (e) { if (e.target === this) { closeModal(); } });

        // Delegated so triggers rendered at any time (including dynamic) work.
        $(document).on('click', '.nias-preview-tag, .video-lesson-preview', function (e) {
            e.preventDefault();
            var $t = $(this);
            openModal({ embed: $t.attr('data-embed') || '', src: $t.attr('data-src') || $t.attr('href') || '' });
        });
    });
    </script>
    <?php
}
