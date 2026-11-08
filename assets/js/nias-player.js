/**
 * NiasPlayer — the plugin's own video player (no UI library).
 *
 *   var p = NiasPlayer.create(container, {
 *       src:        'https://…/lesson.mp4' | '…/master.m3u8' | '…/manifest.mpd',
 *       sources:    [{ label:'1080p', height:1080, src }, { label:'720p', … }], // other qualities of a file (optional)
 *       poster:     '',
 *       startAt:    0,            // resume position (seconds)
 *       watch:      { n, m },     // previously watched map (see Tracker)
 *       watermark:  '',           // overrides the configured watermark text
 *       downloadUrl:'',           // offered when the browser cannot play the file
 *       next:       { title, go },// end screen: "next lesson" (auto-advance optional)
 *       onProgress: function (info) {}, // { time, duration, watchedPct, watch }
 *       onEnded:    function (info) {}
 *   });
 *   p.play(); p.pause(); p.seek(t); p.getWatch(); p.flush(); p.destroy();
 *
 * Site-wide behaviour comes from window.NIAS_PLAYER_CONFIG (the «پلیر ویدیو»
 * settings page); per-player options win where they overlap.
 *
 * Formats: every container the browser decodes natively (mp4, m4v, mov,
 * webm, ogv/ogg, mkv, 3gp …), HLS (.m3u8) through hls.js and MPEG-DASH (.mpd)
 * through dash.js — both stream engines are bundled next to this file and
 * loaded only when such a stream is played. Formats no browser can decode
 * (avi, wmv, flv, …) get a clear message and a download button.
 *
 * Watch tracking: the video is divided into ~1-second buckets; a bucket counts
 * as watched only when it is actually played (not skipped by seeking, not
 * paused, at most 2× speed). The watched percentage is therefore the real
 * share of the video the student has seen, and the map is drawn under the
 * timeline so they can see which parts they missed.
 */
(function () {
    'use strict';
    if (window.NiasPlayer) { return; }

    var me = document.currentScript && document.currentScript.src;
    var BASE = me ? me.replace(/[^\/]*(\?.*)?$/, '') : '';
    var LIBS = {
        hls: { url: BASE + 'hls.min.js?v=1.7.4', global: 'Hls' },
        dash: { url: BASE + 'dash.mediaplayer.min.js?v=5.3.0', global: 'dashjs' }
    };
    var ALL_SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
    var MAX_COUNTED_RATE = 2;
    var MAX_BUCKETS = 4000;
    var PREFS_KEY = 'nias_player_prefs';

    /** Defaults = the settings page defaults. */
    var DEFAULTS = {
        accent: '#1e83f0',
        seenColor: '#34d399',
        radius: 0,
        bigStyle: 'circle',          // circle | rounded | none
        ratio: '16:9',
        poster: '',
        logo: { src: '', pos: 'tr', opacity: 0.7, link: '' },
        controls: { skip: true, volume: true, time: true, speed: true, quality: true, pip: true, fullscreen: true, seen: true, keyboard: true, doubleTap: true },
        skipSeconds: 10,
        speeds: ALL_SPEEDS.slice(),
        defaultSpeed: 1,
        maxSpeed: 2,
        idle: 2.6,                   // seconds before controls hide
        autoplay: false,
        resume: 'auto',              // auto | ask | off
        autoNext: true,
        autoNextDelay: 6,
        preload: 'metadata',         // metadata | auto | none
        remember: true,              // volume / speed / remaining-time per browser
        pauseHidden: false,          // pause when the tab is hidden
        seekLock: 'off',             // off | first | always
        completePct: 90,
        blockContext: true,
        watermark: { text: '', opacity: 0.35, size: 13, interval: 9, mode: 'move' },
        hls: { start: 'auto', cap: true, buffer: 30 }
    };

    /* ------------------------------------------------------------------
     * Utils
     * ---------------------------------------------------------------- */

    function fa(s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
    function fmt(t) {
        t = Math.max(0, Math.floor(t || 0));
        var h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
        var mm = h ? (m < 10 ? '0' + m : m) : m;
        return fa((h ? h + ':' : '') + mm + ':' + (s < 10 ? '0' + s : s));
    }
    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html != null) { n.innerHTML = html; }
        return n;
    }
    function isObj(x) { return x && typeof x === 'object' && !Array.isArray(x); }
    function merge(base) {
        var out = {};
        Array.prototype.slice.call(arguments).forEach(function (src) {
            if (!isObj(src)) { return; }
            Object.keys(src).forEach(function (k) {
                var v = src[k];
                if (v === undefined || v === null) { return; }
                out[k] = (isObj(v) && isObj(out[k])) ? merge(out[k], v) : (Array.isArray(v) ? v.slice() : v);
            });
        });
        return out;
    }
    function readPrefs() { try { return JSON.parse(localStorage.getItem(PREFS_KEY)) || {}; } catch (e) { return {}; } }
    function writePrefs(set) {
        var p = readPrefs();
        Object.keys(set).forEach(function (k) { p[k] = set[k]; });
        try { localStorage.setItem(PREFS_KEY, JSON.stringify(p)); } catch (e) { /* private mode */ }
    }
    function isTouch() { return window.matchMedia && window.matchMedia('(pointer: coarse)').matches; }

    var libPromises = {};
    function loadLib(name) {
        var lib = LIBS[name];
        if (window[lib.global]) { return Promise.resolve(window[lib.global]); }
        if (libPromises[name]) { return libPromises[name]; }
        libPromises[name] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = lib.url;
            s.async = true;
            s.onload = function () { window[lib.global] ? resolve(window[lib.global]) : reject(new Error(name)); };
            s.onerror = function () { delete libPromises[name]; reject(new Error(name)); };
            document.head.appendChild(s);
        });
        return libPromises[name];
    }

    /* ------------------------------------------------------------------
     * Formats & engines
     * ---------------------------------------------------------------- */

    var UNSUPPORTED = /^(avi|wmv|flv|f4v|asf|rm|rmvb|vob|mpg|mpeg|divx|xvid|swf)$/;

    /** 'hls' | 'dash' | 'native' | 'unsupported' */
    function detect(src) {
        var s = String(src || '').toLowerCase();
        var path = s.split(/[?#]/)[0];
        var ext = (path.match(/\.([a-z0-9]{2,5})$/) || [])[1] || '';
        if (ext === 'm3u8' || /[?&](format|type)=m3u8/.test(s)) { return 'hls'; }
        if (ext === 'mpd' || /[?&](format|type)=mpd/.test(s)) { return 'dash'; }
        if (UNSUPPORTED.test(ext)) { return 'unsupported'; }
        return 'native'; // mp4/webm/mov/mkv/… and extension-less signed URLs
    }

    /**
     * Start playback of src on video. Resolves to an engine:
     * { kind, levels:[{height,bitrate}], level(i|-1), current(), destroy() }
     */
    function attachEngine(video, src, hcfg) {
        var kind = detect(src);
        var none = { kind: kind, levels: [], level: function () {}, current: function () { return -1; }, destroy: function () {} };
        var native = !!video.canPlayType('application/vnd.apple.mpegurl');
        var mse = !!(window.MediaSource || window.ManagedMediaSource);
        hcfg = hcfg || DEFAULTS.hls;

        if (kind === 'unsupported') { return Promise.reject({ code: 'format' }); }
        if (kind === 'native' || (kind === 'hls' && !mse && native)) {
            video.src = src;
            return Promise.resolve(none);
        }
        if (kind === 'hls') {
            return loadLib('hls').then(function (Hls) {
                if (!Hls.isSupported()) {
                    if (native) { video.src = src; return none; }
                    throw { code: 'browser' };
                }
                return new Promise(function (resolve, reject) {
                    var buf = clamp(+hcfg.buffer || 30, 10, 120);
                    var hls = new Hls({ capLevelToPlayerSize: hcfg.cap !== false, startLevel: -1, maxBufferLength: buf, maxMaxBufferLength: buf * 2 });
                    var eng = {
                        kind: 'hls', levels: [],
                        level: function (i) { hls.currentLevel = i; },
                        current: function () { return hls.autoLevelEnabled ? -1 : hls.currentLevel; },
                        destroy: function () { hls.destroy(); }
                    };
                    var settled = false, retries = 0;
                    hls.on(Hls.Events.MANIFEST_PARSED, function () {
                        eng.levels = hls.levels.map(function (l) { return { height: l.height || 0, bitrate: l.bitrate || 0 }; });
                        if (eng.levels.length > 1 && hcfg.start !== 'auto') {
                            // Start quality: lowest = fastest start, highest = best picture.
                            var order = eng.levels.map(function (l, i) { return i; }).sort(function (a, b) { return eng.levels[a].bitrate - eng.levels[b].bitrate; });
                            hls.startLevel = hcfg.start === 'low' ? order[0] : order[order.length - 1];
                        }
                        settled = true;
                        resolve(eng);
                    });
                    hls.on(Hls.Events.ERROR, function (e, d) {
                        if (!d.fatal) { return; }
                        // hls.js retried the manifest already; startLoad() would not reload it.
                        var manifest = /^manifest/i.test(String(d.details || ''));
                        if (d.type === Hls.ErrorTypes.NETWORK_ERROR && !manifest && retries++ < 3) { hls.startLoad(); return; }
                        if (d.type === Hls.ErrorTypes.MEDIA_ERROR && retries++ < 3) { hls.recoverMediaError(); return; }
                        hls.destroy();
                        if (!settled) { reject({ code: 'network' }); } else { video.dispatchEvent(new CustomEvent('npl-fatal')); }
                    });
                    hls.loadSource(src);
                    hls.attachMedia(video);
                });
            }, function (err) {
                if (err && err.code) { throw err; }
                if (native) { video.src = src; return none; }
                throw { code: 'lib' };
            });
        }
        // DASH
        return loadLib('dash').then(function (dashjs) {
            return new Promise(function (resolve, reject) {
                var p = dashjs.MediaPlayer().create();
                var eng = {
                    kind: 'dash', levels: [], _cur: -1,
                    level: function (i) {
                        eng._cur = i;
                        p.updateSettings({ streaming: { abr: { autoSwitchBitrate: { video: i < 0 } } } });
                        if (i >= 0 && eng._reps && eng._reps[i]) { p.setRepresentationForTypeById('video', eng._reps[i].id); }
                    },
                    current: function () {
                        var s = p.getSettings();
                        return s.streaming.abr.autoSwitchBitrate.video ? -1 : eng._cur;
                    },
                    destroy: function () { try { p.reset(); } catch (e) { /* already reset */ } }
                };
                p.updateSettings({ streaming: { buffer: { bufferTimeAtTopQuality: clamp(+hcfg.buffer || 30, 10, 120) } } });
                p.on(dashjs.MediaPlayer.events.STREAM_INITIALIZED, function () {
                    var reps = (p.getRepresentationsByType && p.getRepresentationsByType('video')) || [];
                    eng._reps = reps;
                    eng.levels = reps.map(function (r) { return { height: r.height || 0, bitrate: r.bandwidth || 0 }; });
                    resolve(eng);
                });
                p.on(dashjs.MediaPlayer.events.ERROR, function () { reject({ code: 'network' }); });
                p.initialize(video, src, false);
            });
        }, function () { throw { code: 'lib' }; });
    }

    /* ------------------------------------------------------------------
     * Watch tracker
     * ---------------------------------------------------------------- */

    function b64encode(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
        return btoa(s);
    }
    function b64decode(str) {
        try {
            var s = atob(str), out = new Uint8Array(s.length);
            for (var i = 0; i < s.length; i++) { out[i] = s.charCodeAt(i); }
            return out;
        } catch (e) { return null; }
    }

    /**
     * n buckets over the duration; bit i = bucket i watched.
     * Serialised as { n: buckets, m: base64(bitmap) }.
     */
    function Tracker(duration, saved) {
        this.d = duration;
        this.n = clamp(Math.ceil(duration), 1, MAX_BUCKETS);
        this.bits = new Uint8Array(Math.ceil(this.n / 8));
        if (saved && +saved.n === this.n && saved.m) {
            var b = b64decode(saved.m);
            if (b && b.length === this.bits.length) { this.bits = b; }
        }
        this.count = 0;
        for (var i = 0; i < this.n; i++) { if (this.has(i)) { this.count++; } }
    }
    Tracker.prototype.has = function (i) { return (this.bits[i >> 3] >> (i & 7)) & 1; };
    Tracker.prototype.idx = function (t) { return clamp(Math.floor(t / this.d * this.n), 0, this.n - 1); };
    /** Mark everything played between from and to; returns true when new buckets were added. */
    Tracker.prototype.mark = function (from, to) {
        var a = this.idx(from), b = this.idx(to), added = false;
        for (var i = a; i <= b; i++) {
            if (!this.has(i)) { this.bits[i >> 3] |= 1 << (i & 7); this.count++; added = true; }
        }
        return added;
    };
    Tracker.prototype.pct = function () { return Math.round(this.count / this.n * 1000) / 10; };
    /** Seconds watched without a gap from the very start (for the seek lock). */
    Tracker.prototype.prefix = function () {
        var i = 0;
        while (i < this.n && this.has(i)) { i++; }
        return i / this.n * this.d;
    };
    /** Watched runs as [startFraction, endFraction] for the timeline. */
    Tracker.prototype.ranges = function () {
        var out = [], start = -1;
        for (var i = 0; i <= this.n; i++) {
            var on = i < this.n && this.has(i);
            if (on && start < 0) { start = i; }
            if (!on && start >= 0) { out.push([start / this.n, i / this.n]); start = -1; }
        }
        return out;
    };
    Tracker.prototype.toJSON = function () { return { n: this.n, m: b64encode(this.bits) }; };

    /* ------------------------------------------------------------------
     * Icons
     * ---------------------------------------------------------------- */

    function skipIcon(dir, secs) {
        var arc = dir < 0 ? '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>' : '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + arc +
            '<text x="12" y="15.5" font-size="' + (secs > 9 ? 7.5 : 8.5) + '" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none" font-family="sans-serif">' + secs + '</text></svg>';
    }
    var I = {
        play: '<svg viewBox="0 0 24 24"><path d="M8 5.5v13a1 1 0 0 0 1.5.9l10.4-6.5a1 1 0 0 0 0-1.8L9.5 4.6A1 1 0 0 0 8 5.5Z"/></svg>',
        pause: '<svg viewBox="0 0 24 24"><rect x="6" y="5" width="4.2" height="14" rx="1.2"/><rect x="13.8" y="5" width="4.2" height="14" rx="1.2"/></svg>',
        replay: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
        next: '<svg viewBox="0 0 24 24"><path d="M6 5.5v13a1 1 0 0 0 1.5.9l9-6.5a1 1 0 0 0 0-1.8l-9-6.5A1 1 0 0 0 6 5.5Z"/><rect x="17" y="5" width="2.6" height="14" rx="1"/></svg>',
        vol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4V5Z" fill="currentColor"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>',
        volLow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4V5Z" fill="currentColor"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/></svg>',
        mute: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4V5Z" fill="currentColor"/><path d="m16 9 6 6M22 9l-6 6"/></svg>',
        gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
        pip: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><rect x="12" y="11" width="7" height="6" rx="1" fill="currentColor"/></svg>',
        full: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
        exitFull: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>',
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
        check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg>',
        warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>'
    };

    /* ------------------------------------------------------------------
     * Styles (injected once)
     * ---------------------------------------------------------------- */

    var CSS = [
        '.npl{--npl-accent:#1e83f0;--npl-seen:#34d399;position:relative;width:100%;height:100%;min-height:160px;background:#000;color:#fff;overflow:hidden;font-family:inherit;user-select:none;-webkit-user-select:none;outline:none;direction:ltr;line-height:1.4;-webkit-tap-highlight-color:transparent}',
        '.npl *{box-sizing:border-box}',
        '.npl.npl-ratio{height:auto}',
        '.npl video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000;display:block}',
        '.npl button{font:inherit;color:inherit;background:none;border:0;padding:0;margin:0;cursor:pointer;line-height:0}',
        '.npl svg{width:100%;height:100%;fill:currentColor}',
        '.npl svg[fill="none"]{fill:none}',
        /* Centred with a transform: the generic ".npl button{margin:0}" reset would cancel negative margins. */
        '.npl .npl-big{position:absolute;left:50%;top:50%;width:74px;height:74px;margin:0;transform:translate(-50%,-50%);border-radius:50%;background:var(--npl-accent)!important;box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex!important;align-items:center;justify-content:center;transition:transform .15s,opacity .2s;z-index:4}',
        '.npl.npl-big-rounded .npl-big{border-radius:20px;width:84px;height:62px}',
        '.npl-big-none .npl-big{display:none!important}',
        /* The triangle's centroid is already at the centre of its 24px box: no extra offset. */
        '.npl-big svg{width:32px;height:32px;display:block}',
        '.npl .npl-big:hover{transform:translate(-50%,-50%) scale(1.07)}',
        '.npl.is-playing .npl-big,.npl.is-error .npl-big,.npl.is-waiting .npl-big,.npl.is-ended .npl-big{opacity:0;pointer-events:none}',
        '.npl-spin{position:absolute;left:50%;top:50%;width:48px;height:48px;margin:-24px 0 0 -24px;border-radius:50%;border:3px solid rgba(255,255,255,.25);border-top-color:#fff;animation:npl-rot .8s linear infinite;display:none;z-index:3;pointer-events:none}',
        '.npl.is-waiting .npl-spin{display:block}',
        '@keyframes npl-rot{to{transform:rotate(360deg)}}',
        '.npl-bar{position:absolute;left:0;right:0;bottom:0;padding:26px 14px 10px;background:linear-gradient(transparent,rgba(0,0,0,.78));transition:opacity .25s,transform .25s;z-index:5}',
        '.npl.is-idle.is-playing .npl-bar{opacity:0;transform:translateY(8px);pointer-events:none}',
        '.npl.is-idle.is-playing{cursor:none}',
        '.npl-track{position:relative;height:22px;cursor:pointer;touch-action:none}',
        '.npl-rail{position:absolute;left:0;right:0;top:7px;height:4px;border-radius:4px;background:rgba(255,255,255,.22);transition:height .12s,top .12s}',
        '.npl-track:hover .npl-rail,.npl.is-scrub .npl-rail{top:6px;height:6px}',
        '.npl-buf,.npl-played,.npl-lockz{position:absolute;left:0;top:0;bottom:0;border-radius:4px}',
        '.npl-buf{background:rgba(255,255,255,.32);width:0}',
        '.npl-lockz{left:auto;right:0;background:repeating-linear-gradient(135deg,rgba(255,255,255,.12) 0 4px,transparent 4px 8px);display:none}',
        '.npl.has-lock .npl-lockz{display:block}',
        /* watched map: its own strip under the rail, so parts watched before the playhead stay visible */
        '.npl-seen{position:absolute;left:0;right:0;top:100%;margin-top:3px;height:3px;pointer-events:none}',
        '.npl-seen i{position:absolute;top:0;bottom:0;background:var(--npl-seen);border-radius:2px;opacity:.9}',
        '.npl-played{background:var(--npl-accent);width:0;opacity:.95}',
        '.npl-thumb{position:absolute;top:50%;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4);transform:scale(0);transition:transform .12s;left:0}',
        '.npl-track:hover .npl-thumb,.npl.is-scrub .npl-thumb{transform:scale(1)}',
        '.npl-tip{position:absolute;bottom:24px;transform:translateX(-50%);background:rgba(15,20,25,.92);padding:4px 8px;border-radius:7px;font-size:12px;white-space:nowrap;opacity:0;transition:opacity .12s;pointer-events:none;direction:rtl}',
        '.npl-track:hover .npl-tip,.npl.is-scrub .npl-tip{opacity:1}',
        '.npl-tip b{font-weight:400;opacity:.75;margin-right:6px}',
        '.npl-row{display:flex;align-items:center;gap:6px;margin-top:4px}',
        '.npl-btn{width:36px;height:36px;padding:7px!important;border-radius:9px;flex:none;opacity:.92;transition:background .15s,opacity .15s}',
        '.npl-btn:hover{background:rgba(255,255,255,.14)!important;opacity:1}',
        '.npl-btn:focus-visible,.npl-big:focus-visible,.npl-time:focus-visible{outline:2px solid #fff;outline-offset:2px}',
        '.npl-vol{display:flex;align-items:center}',
        '.npl-volr{width:0;opacity:0;transition:width .2s,opacity .2s;accent-color:#fff;height:4px;margin:0;cursor:pointer}',
        '.npl-vol:hover .npl-volr,.npl-vol:focus-within .npl-volr{width:76px;opacity:1;margin:0 6px}',
        '.npl-time{font-size:13px!important;line-height:1.4!important;font-variant-numeric:tabular-nums;white-space:nowrap;opacity:.95;padding:0 4px!important;direction:ltr;unicode-bidi:isolate;border-radius:6px}',
        '.npl-time span{unicode-bidi:isolate}',
        '.npl-sp{flex:1}',
        '.npl-seenb{display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:4px 9px;border-radius:99px;background:rgba(52,211,153,.16);color:#a7f3d0;white-space:nowrap;direction:rtl}',
        '.npl-seenb svg{width:14px;height:14px;flex:none}',
        '.npl-rate{font-size:11px;font-weight:700;position:absolute;right:3px;bottom:3px;background:var(--npl-accent);border-radius:5px;padding:1px 3px;line-height:1.2;display:none}',
        '.npl-gearw{position:relative}',
        '.npl-gearw.has-rate .npl-rate{display:block}',
        '.npl-menu{position:absolute;right:12px;bottom:64px;min-width:210px;max-height:calc(100% - 80px);overflow:auto;background:rgba(18,24,30,.96);border-radius:12px;padding:6px;display:none;z-index:8;direction:rtl;box-shadow:0 12px 32px rgba(0,0,0,.45)}',
        '.npl-menu.open{display:block}',
        '.npl-mi{display:flex!important;width:100%;align-items:center;justify-content:space-between;gap:16px;padding:9px 12px!important;border-radius:8px;font-size:13.5px!important;line-height:1.4!important;text-align:right}',
        '.npl-mi:hover{background:rgba(255,255,255,.1)!important}',
        '.npl-mi .v{opacity:.65;display:inline-flex;align-items:center;gap:4px}',
        '.npl-mi .v svg,.npl-mi .ck svg{width:15px;height:15px}',
        '.npl-mi .ck{width:15px;height:15px;color:var(--npl-accent)}',
        '.npl-mh{display:flex!important;align-items:center;gap:6px;padding:8px 10px!important;font-size:13px!important;line-height:1.4!important;opacity:.75;width:100%;border-bottom:1px solid rgba(255,255,255,.1)!important;margin-bottom:4px!important}',
        '.npl-mh svg{width:16px;height:16px;transform:scaleX(-1)}',
        '.npl-toast{position:absolute;left:50%;top:18px;transform:translateX(-50%);background:rgba(15,20,25,.88);padding:7px 14px;border-radius:10px;font-size:13px;z-index:6;opacity:0;transition:opacity .2s;pointer-events:none;direction:rtl;white-space:nowrap;max-width:90%;overflow:hidden;text-overflow:ellipsis}',
        '.npl-toast.show{opacity:1}',
        '.npl-tap{position:absolute;top:0;bottom:0;width:38%;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:2;opacity:0;transition:opacity .25s}',
        '.npl-tap.l{left:0;border-radius:0 50% 50% 0;background:radial-gradient(circle at left,rgba(255,255,255,.18),transparent 70%)}',
        '.npl-tap.r{right:0;border-radius:50% 0 0 50%;background:radial-gradient(circle at right,rgba(255,255,255,.18),transparent 70%)}',
        '.npl-tap.show{opacity:1}',
        '.npl-tap span{font-size:13px;background:rgba(0,0,0,.45);padding:6px 12px;border-radius:99px;direction:rtl}',
        '.npl-resume{position:absolute;left:14px;bottom:76px;z-index:6;display:none;align-items:center;gap:8px;background:rgba(15,20,25,.92);padding:8px 8px 8px 14px;border-radius:12px;font-size:13px;direction:rtl}',
        '.npl-resume.show{display:flex}',
        '.npl-resume button{background:rgba(255,255,255,.14)!important;padding:6px 12px!important;border-radius:8px;font-size:12.5px!important;line-height:1.4!important}',
        '.npl-resume button.pri{background:var(--npl-accent)!important}',
        '.npl-end{position:absolute;inset:0;z-index:7;display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:rgba(8,12,16,.82);direction:rtl;text-align:center;padding:20px}',
        '.npl.is-ended .npl-end{display:flex}',
        '.npl-end .lbl{font-size:13px;opacity:.7}',
        '.npl-end .tt{font-size:18px;font-weight:700;max-width:520px}',
        '.npl-end .acts{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}',
        '.npl-end button{display:inline-flex!important;align-items:center;gap:8px;padding:10px 18px!important;border-radius:11px;font-size:14px!important;line-height:1.4!important;background:rgba(255,255,255,.14)!important}',
        '.npl-end button.pri{background:var(--npl-accent)!important}',
        '.npl-end button svg{width:18px;height:18px}',
        '.npl-ring{width:58px;height:58px;position:relative}',
        '.npl-ring svg{transform:rotate(-90deg)}',
        '.npl-ring b{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:18px}',
        '.npl-err{position:absolute;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:24px;text-align:center;background:linear-gradient(135deg,#1a262d,#0e1518);z-index:7;direction:rtl}',
        '.npl.is-error .npl-err{display:flex}',
        '.npl-shield{position:absolute;inset:0;z-index:40;display:none;align-items:center;justify-content:center;background:#000;color:#fff;font-size:14px;cursor:pointer;text-align:center;padding:16px}',
        '.npl.is-shielded .npl-shield{display:flex}',
        '.npl-err .ic{width:46px;height:46px;color:#fbbf24}',
        '.npl-err .t{font-size:16px;font-weight:700}',
        '.npl-err .m{font-size:13px;opacity:.75;max-width:420px;line-height:1.9}',
        '.npl-err a{display:inline-block;background:var(--npl-accent);color:#fff!important;text-decoration:none;padding:9px 18px;border-radius:10px;font-size:13.5px}',
        '.npl-wm{position:absolute;z-index:3;pointer-events:none;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.6);white-space:nowrap;transition:left 1.2s ease,top 1.2s ease;direction:rtl}',
        '.npl-wm.corner{left:auto!important;top:auto!important;right:14px;bottom:70px;transition:none}',
        '.npl-logo{position:absolute;z-index:4;max-width:18%;line-height:0;transition:opacity .25s}',
        '.npl-logo img{max-width:100%;max-height:44px;display:block}',
        '.npl-logo.tr{top:14px;right:14px}.npl-logo.tl{top:14px;left:14px}.npl-logo.br{bottom:72px;right:14px}.npl-logo.bl{bottom:72px;left:14px}',
        '.npl:fullscreen,.npl:-webkit-full-screen{width:100%;height:100%;aspect-ratio:auto!important;border-radius:0!important}',
        /* Compact layouts follow the player's own width (narrow columns, sidebars), not the window's. */
        '.npl{container-type:inline-size}',
        '@container (max-width:600px){.npl-time{font-size:12px!important}.npl-btn{width:32px;height:32px;padding:6px!important}.npl-row{gap:3px}.npl-seenb .lbl{display:none}.npl .npl-big{width:62px;height:62px}.npl .npl-big svg{width:26px;height:26px}.npl-hide-sm{display:none!important}.npl-bar{padding:22px 8px 6px}}',
        '@container (max-width:400px){.npl-seenb,.npl-vol .npl-volr{display:none!important}.npl-time .dur,.npl-time .sl{display:none}.npl-logo img{max-height:28px}}',
        '@media (max-width:560px){.npl-hide-sm{display:none!important}}'
    ].join('\n');

    function injectCss() {
        if (document.getElementById('nias-player-css')) { return; }
        var s = el('style');
        s.id = 'nias-player-css';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    /* ------------------------------------------------------------------
     * Player
     * ---------------------------------------------------------------- */

    var ERRORS = {
        format: ['این فرمت ویدیو در مرورگر قابل پخش نیست', 'فرمت‌هایی مثل AVI، WMV و FLV در هیچ مرورگری پخش نمی‌شوند. لطفاً فایل را دانلود کنید؛ مدیر سایت می‌تواند ویدیو را به MP4 یا HLS تبدیل کند.'],
        decode: ['مرورگر شما این ویدیو را پخش نمی‌کند', 'کدک این فایل در مرورگر شما پشتیبانی نمی‌شود. مرورگر دیگری (Chrome یا Edge) را امتحان کنید یا فایل را دانلود کنید.'],
        missing: ['فایل ویدیو در دسترس نیست', 'آدرس این ویدیو پیدا نشد یا دسترسی به آن محدود است. لطفاً به پشتیبانی سایت اطلاع دهید.'],
        unplayable: ['پخش این ویدیو ممکن نشد', 'ممکن است فایل در دسترس نباشد یا فرمت آن در مرورگر شما پشتیبانی نشود. صفحه را دوباره بارگذاری کنید یا مرورگر دیگری را امتحان کنید.'],
        network: ['بارگذاری ویدیو ممکن نشد', 'اتصال اینترنت را بررسی کنید یا صفحه را دوباره بارگذاری کنید.'],
        browser: ['مرورگر شما از پخش این ویدیو پشتیبانی نمی‌کند', 'لطفاً مرورگر خود را به‌روز کنید.'],
        lib: ['بارگذاری پخش‌کننده ناموفق بود', 'صفحه را دوباره بارگذاری کنید.']
    };

    function Player(container, opts) {
        injectCss();
        this.o = opts || {};
        var c = merge(DEFAULTS, window.NIAS_PLAYER_CONFIG || {}, this.o.config || {});
        if (this.o.accent) { c.accent = this.o.accent; }
        if (typeof this.o.watermark === 'string' && this.o.watermark !== '') { c.watermark.text = this.o.watermark; }
        if (this.o.autoplay != null) { c.autoplay = !!this.o.autoplay; }
        c.maxSpeed = clamp(+c.maxSpeed || 2, 1, 2);
        c.speeds = (c.speeds || ALL_SPEEDS).map(Number).filter(function (s) { return s > 0 && s <= c.maxSpeed; });
        if (c.speeds.indexOf(1) < 0) { c.speeds.push(1); }
        c.speeds.sort(function (a, b) { return a - b; });
        c.skipSeconds = clamp(+c.skipSeconds || 10, 3, 60);
        this.cfg = c;
        this.c = container;
        this.prefs = c.remember ? readPrefs() : {};
        this.tracker = null;
        this.lastT = 0;
        this.maxReached = 0;
        this.seeking = false;
        this.engine = null;
        this.destroyed = false;
        this.remaining = !!this.prefs.remaining;
        this._timers = [];
        this._listeners = [];
        this.build();
        this.bind();
        this.load();
    }

    Player.prototype.listen = function (target, ev, fn, opt) {
        target.addEventListener(ev, fn, opt);
        this._listeners.push([target, ev, fn, opt]);
    };
    Player.prototype.pref = function (set) { if (this.cfg.remember) { writePrefs(set); } };

    Player.prototype.build = function () {
        var c = this.cfg, k = c.controls, s = c.skipSeconds;
        var r = el('div', 'npl npl-big-' + (c.bigStyle || 'circle'));
        r.tabIndex = 0;
        r.setAttribute('role', 'region');
        r.setAttribute('aria-label', 'پخش‌کنندهٔ ویدیو');
        r.style.setProperty('--npl-accent', c.accent);
        r.style.setProperty('--npl-seen', c.seenColor);
        if (+c.radius > 0) { r.style.borderRadius = (+c.radius) + 'px'; }
        if (this.o.ratio) {
            r.classList.add('npl-ratio');
            r.style.aspectRatio = String(c.ratio || '16:9').replace(':', '/');
        }

        var v = el('video');
        v.setAttribute('playsinline', '');
        v.setAttribute('webkit-playsinline', '');
        v.setAttribute('preload', /^(none|metadata|auto)$/.test(c.preload) ? c.preload : 'metadata');
        v.setAttribute('controlsList', 'nodownload noremoteplayback');
        if (c.noDownload) {
            // «جلوگیری از دانلود»: no save/drag/long-press menu on the video.
            v.setAttribute('disableRemotePlayback', '');
            v.setAttribute('draggable', 'false');
            v.style.webkitTouchCallout = 'none';
        }
        var poster = this.o.poster || c.poster;
        if (poster) { v.poster = poster; }
        var p = this.prefs;
        v.volume = typeof p.vol === 'number' ? clamp(p.vol, 0, 1) : 1;
        v.muted = !!p.muted;
        r.appendChild(v);

        var html =
            '<div class="npl-spin"></div>' +
            '<button type="button" class="npl-big" aria-label="پخش">' + I.play + '</button>' +
            '<div class="npl-tap l"><span>' + fa(s) + ' ثانیه عقب</span></div><div class="npl-tap r"><span>' + fa(s) + ' ثانیه جلو</span></div>' +
            '<div class="npl-toast" role="status"></div>' +
            '<div class="npl-resume"><span class="txt"></span><span class="acts"></span></div>' +
            '<div class="npl-end"></div>' +
            '<div class="npl-err"><div class="ic">' + I.warn + '</div><div class="t"></div><div class="m"></div></div>' +
            '<div class="npl-bar">' +
                '<div class="npl-track" role="slider" aria-label="نوار پخش" tabindex="-1">' +
                    '<div class="npl-rail"><div class="npl-buf"></div><div class="npl-lockz"></div><div class="npl-played"></div>' + (k.seen ? '<div class="npl-seen"></div>' : '') + '</div>' +
                    '<div class="npl-thumb"></div><div class="npl-tip"></div>' +
                '</div>' +
                '<div class="npl-row">' +
                    '<button type="button" class="npl-btn" data-a="play" aria-label="پخش">' + I.play + '</button>' +
                    (k.skip ? '<button type="button" class="npl-btn npl-hide-sm" data-a="back" aria-label="' + fa(s) + ' ثانیه عقب">' + skipIcon(-1, s) + '</button>' +
                              '<button type="button" class="npl-btn npl-hide-sm" data-a="fwd" aria-label="' + fa(s) + ' ثانیه جلو">' + skipIcon(1, s) + '</button>' : '') +
                    (k.volume ? '<div class="npl-vol"><button type="button" class="npl-btn" data-a="mute" aria-label="صدا"></button><input class="npl-volr" type="range" min="0" max="1" step="0.05" aria-label="میزان صدا"></div>' : '') +
                    (k.time ? '<button type="button" class="npl-time" data-a="time" title="نمایش زمان باقی‌مانده / سپری‌شده"><span class="cur">۰:۰۰</span><span class="sl"> / </span><span class="dur">۰:۰۰</span></button>' : '') +
                    '<span class="npl-sp"></span>' +
                    (k.seen ? '<span class="npl-seenb" title="سهمی از ویدیو که واقعاً تماشا کرده‌اید">' + I.eye + '<span><span class="lbl">دیده‌شده </span><b>۰٪</b></span></span>' : '') +
                    ((k.speed || k.quality) ? '<span class="npl-gearw"><button type="button" class="npl-btn" data-a="menu" aria-label="تنظیمات" aria-haspopup="true">' + I.gear + '</button><span class="npl-rate"></span></span>' : '') +
                    (k.pip ? '<button type="button" class="npl-btn npl-hide-sm" data-a="pip" aria-label="تصویر در تصویر">' + I.pip + '</button>' : '') +
                    (k.fullscreen ? '<button type="button" class="npl-btn" data-a="full" aria-label="تمام‌صفحه">' + I.full + '</button>' : '') +
                '</div>' +
            '</div>' +
            '<div class="npl-menu" role="menu"></div>';
        r.insertAdjacentHTML('beforeend', html);

        var wm = c.watermark || {};
        if (wm.text) {
            this.wm = el('div', 'npl-wm' + (wm.mode === 'corner' ? ' corner' : ''));
            this.wm.textContent = wm.text;
            this.wm.style.opacity = clamp(+wm.opacity || 0.35, 0.05, 1);
            this.wm.style.fontSize = clamp(+wm.size || 13, 9, 40) + 'px';
            r.appendChild(this.wm);
        }
        if (c.logo && c.logo.src) {
            var lg = el(c.logo.link ? 'a' : 'span', 'npl-logo ' + (/^(tr|tl|br|bl)$/.test(c.logo.pos) ? c.logo.pos : 'tr'));
            if (c.logo.link) { lg.href = c.logo.link; lg.target = '_blank'; lg.rel = 'noopener'; }
            var im = el('img');
            im.src = c.logo.src;
            im.alt = '';
            lg.style.opacity = clamp(+c.logo.opacity || 0.7, 0.1, 1);
            lg.appendChild(im);
            r.appendChild(lg);
        }

        var q = function (sel) { return r.querySelector(sel); };
        this.r = r;
        this.v = v;
        this.ui = {
            big: q('.npl-big'), play: q('[data-a="play"]'), mute: q('[data-a="mute"]'), volr: q('.npl-volr'),
            time: q('.npl-time'), cur: q('.npl-time .cur'), dur: q('.npl-time .dur'), track: q('.npl-track'), buf: q('.npl-buf'),
            seen: q('.npl-seen'), lockz: q('.npl-lockz'), played: q('.npl-played'), thumb: q('.npl-thumb'), tip: q('.npl-tip'),
            seenb: q('.npl-seenb b'), menu: q('.npl-menu'), gearw: q('.npl-gearw'),
            rate: q('.npl-rate'), pip: q('[data-a="pip"]'), full: q('[data-a="full"]'), toast: q('.npl-toast'),
            resume: q('.npl-resume'), end: q('.npl-end'), err: q('.npl-err'), tapL: q('.npl-tap.l'), tapR: q('.npl-tap.r')
        };
        if (this.ui.pip && !(document.pictureInPictureEnabled && v.requestPictureInPicture)) { this.ui.pip.style.display = 'none'; }
        if (this.o.track === false && this.ui.seenb) { this.ui.seenb.closest('.npl-seenb').style.display = 'none'; }
        if (this.ui.volr) { this.ui.volr.value = v.muted ? 0 : v.volume; }
        this.paintVolume();
        this.c.innerHTML = '';
        this.c.appendChild(r);
    };

    /* ---- loading ---- */

    /**
     * Several files of the same video (1080p / 720p / 480p): which one to
     * start with — the viewer's last choice, else the HLS start setting.
     */
    Player.prototype.pickSource = function () {
        var s = this.o.sources, c = this.cfg;
        if (!s || s.length < 2 || !c.controls.quality) { return -1; }
        var want = +this.prefs.qh || 0, best = -1;
        if (want) {
            s.forEach(function (x, i) { if ((+x.height || 0) === want) { best = i; } });
            if (best > -1) { return best; }
        }
        var start = (c.hls && c.hls.start) || 'auto';
        if (start === 'auto') { return 0; }
        var order = s.map(function (x, i) { return i; }).sort(function (a, b) { return (+s[a].height || 0) - (+s[b].height || 0); });
        return start === 'low' ? order[0] : order[order.length - 1];
    };
    /** Engine over several files: switching keeps the time, speed and play state. */
    Player.prototype.sourcesEngine = function (idx) {
        var self = this, v = this.v, s = this.o.sources;
        return {
            kind: 'sources', _cur: idx,
            levels: s.map(function (x) { return { height: +x.height || 0, bitrate: 0, label: x.label || '' }; }),
            level: function (i) {
                if (i < 0 || i === this._cur || !s[i]) { return; }
                var t = v.currentTime, was = !v.paused, rate = v.playbackRate;
                this._cur = i;
                self.r.classList.add('is-waiting');
                var back = function () {
                    v.removeEventListener('loadedmetadata', back);
                    try { v.currentTime = t; } catch (e) { /* not seekable yet */ }
                    v.playbackRate = rate;
                    if (was) { var p = v.play(); if (p && p.catch) { p.catch(function () {}); } }
                };
                v.addEventListener('loadedmetadata', back);
                v.src = s[i].src;
                v.load();
                if (self.cfg.remember) { writePrefs({ qh: +s[i].height || 0 }); self.prefs.qh = +s[i].height || 0; }
            },
            current: function () { return this._cur; },
            destroy: function () {}
        };
    };

    Player.prototype.load = function () {
        var self = this, v = this.v, c = this.cfg;
        this.r.classList.add('is-waiting');
        var si = this.pickSource();
        attachEngine(v, si > -1 ? this.o.sources[si].src : this.o.src, c.hls).then(function (eng) {
            if (self.destroyed) { eng.destroy(); return; }
            if (si > -1 && eng.kind === 'native') { eng = self.sourcesEngine(si); }
            self.engine = eng;
            var rate = (c.remember && c.speeds.indexOf(self.prefs.rate) > -1) ? self.prefs.rate : +c.defaultSpeed;
            if (c.speeds.indexOf(rate) < 0) { rate = 1; }
            v.playbackRate = rate;
            v.defaultPlaybackRate = rate;
            self.paintRate();
            if (eng.kind !== 'native') { self.r.classList.remove('is-waiting'); }
            if (c.autoplay) { self.play(true); }
        }, function (err) {
            self.fail(err && err.code ? err.code : 'network');
        });
    };

    /**
     * «جلوگیری از دانلود»: make capture harder. A screenshot key or a capture
     * tool (which takes the focus away) blanks the picture and pauses; Ctrl+S
     * is blocked. Screen capture cannot be fully prevented by a web page.
     */
    Player.prototype.guardCapture = function () {
        var self = this, v = this.v, r = this.r;
        var L = function (t, e, f, o) { self.listen(t, e, f, o); };
        var shield = el('div', 'npl-shield');
        shield.innerHTML = '<span>' + 'برای ادامه، روی ویدیو کلیک کنید' + '</span>';
        r.appendChild(shield);
        var hide = function (pause) {
            r.classList.add('is-shielded');
            if (pause && !v.paused) { self._shieldPaused = true; v.pause(); }
        };
        var show = function () { r.classList.remove('is-shielded'); };
        L(shield, 'click', function (e) { e.stopPropagation(); show(); if (self._shieldPaused) { self._shieldPaused = false; self.play(); } });
        L(window, 'blur', function () { if (!v.paused || r.classList.contains('is-playing')) { hide(true); } });
        L(document, 'visibilitychange', function () { if (document.hidden) { hide(true); } });
        L(document, 'keydown', function (e) {
            var k = (e.key || '').toLowerCase();
            if (k === 'printscreen' || (e.metaKey && e.shiftKey && (k === '3' || k === '4' || k === '5' || k === 's'))) { hide(true); }
            if ((e.ctrlKey || e.metaKey) && (k === 's' || k === 'u')) { e.preventDefault(); }
        }, true);
        L(document, 'keyup', function (e) {
            if ((e.key || '').toLowerCase() === 'printscreen') {
                hide(true);
                try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText('').catch(function () { /* page not focused */ }); } } catch (x) { /* not allowed */ }
            }
        }, true);
    };

    Player.prototype.fail = function (code) {
        var e = ERRORS[code] || ERRORS.network;
        this.r.classList.remove('is-waiting', 'is-playing');
        this.r.classList.add('is-error');
        this.ui.err.querySelector('.t').textContent = e[0];
        this.ui.err.querySelector('.m').textContent = e[1];
        var dl = this.cfg.noDownload ? '' : (this.o.downloadUrl || ((code === 'format' || code === 'decode') ? this.o.src : ''));
        if (dl && !this.ui.err.querySelector('a')) {
            var a = el('a');
            a.href = dl;
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = 'دانلود فایل ویدیو';
            this.ui.err.appendChild(a);
        }
        if (this.o.onError) { this.o.onError(code); }
    };

    /* ---- events ---- */

    Player.prototype.bind = function () {
        var self = this, v = this.v, r = this.r, ui = this.ui, c = this.cfg;
        var L = function (t, e, f, o) { self.listen(t, e, f, o); };

        L(v, 'loadedmetadata', function () { self.onMeta(); });
        L(v, 'durationchange', function () { if (!self.tracker) { self.onMeta(); } });
        L(v, 'timeupdate', function () { self.onTime(); });
        L(v, 'progress', function () { self.paintBuffer(); });
        L(v, 'play', function () { r.classList.remove('is-ended'); self.cancelNext(); self.paintPlay(); self.poke(); });
        L(v, 'pause', function () { self.paintPlay(); self.flush(); r.classList.remove('is-idle'); });
        L(v, 'playing', function () { r.classList.remove('is-waiting'); });
        L(v, 'waiting', function () { r.classList.add('is-waiting'); });
        L(v, 'canplay', function () { r.classList.remove('is-waiting'); });
        L(v, 'seeking', function () {
            self.seeking = true;
            // Native controls (iOS full screen, PiP) can seek too: hold the lock.
            var lim = self.seekLimit();
            if (v.currentTime > lim + 1) { v.currentTime = lim; self.toast('برای جلو رفتن، ابتدا این بخش را تماشا کنید'); }
        });
        L(v, 'seeked', function () { self.seeking = false; self.lastT = v.currentTime; self.paintTime(); });
        L(v, 'ratechange', function () {
            if (v.playbackRate > c.maxSpeed + 0.01) { v.playbackRate = c.maxSpeed; self.toast('حداکثر سرعت مجاز ' + self.rateLabel(c.maxSpeed) + ' است'); return; }
            self.pref({ rate: v.playbackRate });
            self.paintRate();
        });
        L(v, 'volumechange', function () { self.pref({ vol: v.volume, muted: v.muted }); self.paintVolume(); });
        L(v, 'ended', function () { self.onTime(); self.flush(); self.paintPlay(); self.showEnd(); if (self.o.onEnded) { self.o.onEnded(self.info()); } });
        L(v, 'error', function () {
            var code = v.error ? v.error.code : 0;
            if (code === 3) { self.fail('decode'); return; } // MEDIA_ERR_DECODE
            if (code !== 4) { self.fail('network'); return; }
            // MEDIA_ERR_SRC_NOT_SUPPORTED is also what a 404 looks like: ask the server.
            if (!window.fetch) { self.fail('unplayable'); return; }
            fetch(self.o.src, { method: 'HEAD', credentials: 'same-origin' }).then(function (res) {
                self.fail(res.ok ? 'decode' : 'missing');
            }, function () { self.fail('unplayable'); });
        });
        L(v, 'npl-fatal', function () { self.fail('network'); });
        if (c.blockContext) { L(r, 'contextmenu', function (e) { e.preventDefault(); }); }
        if (c.noDownload) { this.guardCapture(); }

        // Buttons.
        L(r, 'click', function (e) {
            var b = e.target.closest('[data-a]');
            if (!b || !r.contains(b)) { return; }
            var a = b.getAttribute('data-a');
            e.stopPropagation();
            if (a === 'play') { self.toggle(); }
            else if (a === 'back') { self.skip(-c.skipSeconds); }
            else if (a === 'fwd') { self.skip(c.skipSeconds); }
            else if (a === 'mute') { self.toggleMute(); }
            else if (a === 'menu') { self.toggleMenu(); }
            else if (a === 'pip') { self.togglePip(); }
            else if (a === 'full') { self.toggleFull(); }
            else if (a === 'time') { self.remaining = !self.remaining; self.pref({ remaining: self.remaining }); self.paintTime(); }
            else if (a === 'resume') { ui.resume.classList.remove('show'); self.seek(self._resumeAt || 0); self.play(); }
            else if (a === 'restart') { ui.resume.classList.remove('show'); self.seek(0); self.play(); }
            else if (a === 'replay') { r.classList.remove('is-ended'); self.cancelNext(); self.seek(0); self.play(); }
            else if (a === 'next') { self.goNext(); }
            else if (a === 'stay') { self.cancelNext(); }
        });
        L(ui.big, 'click', function (e) { e.stopPropagation(); self.play(); });
        if (ui.volr) { L(ui.volr, 'input', function () { v.volume = +this.value; v.muted = +this.value === 0; }); }

        // Surface: click = play/pause (mouse), tap = show controls, double tap on the sides = ±skip.
        var lastTap = 0, tapTimer = null;
        L(r, 'pointerup', function (e) {
            if (e.target !== v && !e.target.classList.contains('npl-tap') && e.target !== r) { return; }
            if (ui.menu.classList.contains('open')) { self.closeMenu(); return; }
            var now = Date.now(), rect = r.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width;
            if (e.pointerType === 'mouse') {
                if (now - lastTap < 280) { clearTimeout(tapTimer); if (c.controls.fullscreen) { self.toggleFull(); } lastTap = 0; return; }
                lastTap = now;
                tapTimer = setTimeout(function () { self.toggle(); }, 200);
                return;
            }
            if (c.controls.doubleTap && now - lastTap < 300 && (x < 0.38 || x > 0.62)) {
                clearTimeout(tapTimer);
                self.skip(x < 0.38 ? -c.skipSeconds : c.skipSeconds, true);
                lastTap = now; // keep chaining fast taps
                return;
            }
            lastTap = now;
            tapTimer = setTimeout(function () {
                if (r.classList.contains('is-idle') || !self.playing()) { r.classList.remove('is-idle'); self.poke(); }
                else { r.classList.add('is-idle'); }
            }, 300);
        });

        // Auto-hide controls.
        L(r, 'pointermove', function (e) { if (e.pointerType === 'mouse') { self.poke(); } });
        L(r, 'mouseleave', function () { if (self.playing()) { r.classList.add('is-idle'); } });
        L(r, 'focusin', function () { self.poke(); });

        // Timeline scrubbing.
        var scrubbing = false;
        var frac = function (e) { var b = ui.track.getBoundingClientRect(); return clamp((e.clientX - b.left) / b.width, 0, 1); };
        L(ui.track, 'pointerdown', function (e) {
            if (!self.dur()) { return; }
            scrubbing = true;
            r.classList.add('is-scrub');
            try { ui.track.setPointerCapture(e.pointerId); } catch (err) { /* synthetic */ }
            self.preview(frac(e));
            self.seek(frac(e) * self.dur());
            e.preventDefault();
        });
        L(ui.track, 'pointermove', function (e) {
            if (!self.dur()) { return; }
            self.preview(frac(e));
            if (scrubbing) { self.seek(frac(e) * self.dur(), true); }
        });
        var endScrub = function () { if (scrubbing) { scrubbing = false; r.classList.remove('is-scrub'); } };
        L(ui.track, 'pointerup', endScrub);
        L(ui.track, 'pointercancel', endScrub);

        // Keyboard (when the player has focus).
        if (c.controls.keyboard) { L(r, 'keydown', function (e) { self.onKey(e); }); }
        L(document, 'fullscreenchange', function () { self.paintFull(); });
        L(document, 'webkitfullscreenchange', function () { self.paintFull(); });
        L(document, 'click', function (e) { if (!r.contains(e.target)) { self.closeMenu(); } });
        // Save the watch map when the tab is hidden or closed; optionally pause.
        L(document, 'visibilitychange', function () {
            if (!document.hidden) { return; }
            self.flush();
            if (c.pauseHidden && self.playing() && document.pictureInPictureElement !== v) { self.pause(); self._pausedHidden = true; }
        });
        L(window, 'pagehide', function () { self.flush(); });

        if (this.wm && (c.watermark.mode || 'move') === 'move') {
            this.moveWatermark();
            this._timers.push(setInterval(function () { self.moveWatermark(); }, clamp(+c.watermark.interval || 9, 3, 60) * 1000));
        }
    };

    Player.prototype.onKey = function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) { return; }
        var k = e.key, v = this.v, c = this.cfg, handled = true;
        if (k === ' ' || k === 'k' || k === 'K') { this.toggle(); }
        else if (k === 'ArrowLeft') { this.skip(-5); }
        else if (k === 'ArrowRight') { this.skip(5); }
        else if (k === 'j' || k === 'J') { this.skip(-c.skipSeconds); }
        else if (k === 'l' || k === 'L') { this.skip(c.skipSeconds); }
        else if (k === 'ArrowUp') { v.muted = false; v.volume = clamp(v.volume + 0.1, 0, 1); this.toast('صدا ' + fa(Math.round(v.volume * 100)) + '٪'); }
        else if (k === 'ArrowDown') { v.volume = clamp(v.volume - 0.1, 0, 1); this.toast('صدا ' + fa(Math.round(v.volume * 100)) + '٪'); }
        else if (k === 'm' || k === 'M') { this.toggleMute(); }
        else if ((k === 'f' || k === 'F') && c.controls.fullscreen) { this.toggleFull(); }
        else if ((k === '>' || k === '<') && c.controls.speed) {
            var sp = c.speeds, i = sp.indexOf(v.playbackRate);
            i = clamp((i < 0 ? sp.indexOf(1) : i) + (k === '>' ? 1 : -1), 0, sp.length - 1);
            v.playbackRate = sp[i];
            this.toast('سرعت ' + this.rateLabel(sp[i]));
        }
        else if (/^[0-9]$/.test(k) && this.dur()) { this.seek(this.dur() * (+k) / 10); }
        else if (k === 'Escape') { this.closeMenu(); handled = false; }
        else { handled = false; }
        if (handled) { e.preventDefault(); this.poke(); }
    };

    /* ---- metadata, time & tracking ---- */

    Player.prototype.dur = function () { var d = this.v.duration; return isFinite(d) && d > 0 ? d : 0; };
    Player.prototype.playing = function () { return !this.v.paused && !this.v.ended; };

    Player.prototype.onMeta = function () {
        var d = this.dur(), c = this.cfg;
        if (!d || this.tracker) { return; }
        if (this.o.track !== false) {
            this.tracker = new Tracker(d, this.o.watch);
            this.maxReached = this.tracker.prefix();
        }
        if (this.ui.dur) { this.ui.dur.textContent = fmt(d); }
        var at = Math.min(+this.o.startAt || 0, this.seekLimit());
        if (c.resume !== 'off' && at > 5 && at < d - 5) {
            this._resumeAt = at;
            var ui = this.ui;
            ui.resume.querySelector('.txt').textContent = (c.resume === 'ask' ? 'آخرین بار تا ' : 'ادامه از ') + fmt(at);
            ui.resume.querySelector('.acts').innerHTML = c.resume === 'ask'
                ? '<button type="button" class="pri" data-a="resume">ادامه</button><button type="button" data-a="restart">از ابتدا</button>'
                : '<button type="button" data-a="restart">از ابتدا</button>';
            if (c.resume === 'auto') { this.seek(at, true); this.lastT = at; }
            ui.resume.classList.add('show');
            this._timers.push(setTimeout(function () { ui.resume.classList.remove('show'); }, c.resume === 'ask' ? 15000 : 7000));
        }
        this.paintSeen();
        this.paintTime();
        if (this.o.onReady) { this.o.onReady(this.info()); }
    };

    Player.prototype.onTime = function () {
        var v = this.v, t = v.currentTime;
        if (this.tracker && !this.seeking && !v.paused) {
            var dt = t - this.lastT;
            // Count only continuous playback: a jump bigger than ~2.5 s of
            // media (at ≤ 2× speed) is a seek, not watching.
            var maxStep = 2.5 * Math.min(MAX_COUNTED_RATE, Math.max(1, v.playbackRate));
            if (dt > 0 && dt <= maxStep && v.playbackRate <= MAX_COUNTED_RATE) {
                if (this.tracker.mark(this.lastT, t)) { this.seenDirty = true; }
                if (t > this.maxReached) { this.maxReached = t; }
            }
        }
        this.lastT = t;
        this.paintTime();
        var now = Date.now();
        if (this.seenDirty && now - (this._seenAt || 0) > 1000) { this._seenAt = now; this.seenDirty = false; this.paintSeen(); }
        if (now - (this._emitAt || 0) > 2000) { this._emitAt = now; this.emit(); }
    };

    /**
     * Furthest point the student may seek to (seek lock). "first": until the
     * lesson counts as complete; "always": never past what they watched.
     */
    Player.prototype.seekLimit = function () {
        var c = this.cfg, d = this.dur();
        if (!d || c.seekLock === 'off' || !this.tracker) { return Infinity; }
        if (c.seekLock === 'first' && this.tracker.pct() >= (+c.completePct || 90)) { return Infinity; }
        return Math.min(d, Math.max(this.maxReached, this.tracker.prefix()) + 1.5);
    };

    Player.prototype.info = function () {
        return {
            time: this.v.currentTime || 0,
            duration: this.dur(),
            watchedPct: this.tracker ? this.tracker.pct() : 0,
            watch: this.tracker ? this.tracker.toJSON() : null
        };
    };
    Player.prototype.emit = function () { if (this.o.onProgress && this.dur()) { this.o.onProgress(this.info()); } };
    Player.prototype.flush = function () { this.paintSeen(); this.emit(); };
    Player.prototype.getWatch = function () { return this.info(); };

    /* ---- end screen ---- */

    Player.prototype.showEnd = function () {
        var nx = this.o.next, ui = this.ui, self = this, c = this.cfg;
        if (!nx || !nx.title || typeof nx.go !== 'function') {
            ui.end.innerHTML = '<div class="acts"><button type="button" class="pri" data-a="replay">' + I.replay + '<span>پخش دوباره</span></button></div>';
            this.r.classList.add('is-ended');
            return;
        }
        var auto = c.autoNext && !this.o.noAutoNext, secs = clamp(+c.autoNextDelay || 6, 2, 30);
        ui.end.innerHTML =
            (auto ? '<div class="npl-ring"><svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="17" fill="none" stroke="rgba(255,255,255,.18)" stroke-width="3"/><circle class="arc" cx="20" cy="20" r="17" fill="none" stroke="var(--npl-accent)" stroke-width="3" stroke-dasharray="106.8" stroke-dashoffset="0" stroke-linecap="round"/></svg><b>' + fa(secs) + '</b></div>' : '') +
            '<div class="lbl">درس بعدی</div><div class="tt"></div>' +
            '<div class="acts"><button type="button" class="pri" data-a="next">' + I.next + '<span>پخش درس بعدی</span></button>' +
            (auto ? '<button type="button" data-a="stay"><span>لغو</span></button>' : '') +
            '<button type="button" data-a="replay">' + I.replay + '<span>پخش دوباره</span></button></div>';
        ui.end.querySelector('.tt').textContent = nx.title;
        this.r.classList.add('is-ended');
        if (!auto) { return; }
        var left = secs, arc = ui.end.querySelector('.arc'), num = ui.end.querySelector('.npl-ring b');
        this._nextT = setInterval(function () {
            left -= 0.1;
            arc.setAttribute('stroke-dashoffset', String(106.8 * (1 - left / secs)));
            num.textContent = fa(Math.max(0, Math.ceil(left)));
            if (left <= 0) { self.goNext(); }
        }, 100);
    };
    Player.prototype.cancelNext = function () {
        if (this._nextT) {
            clearInterval(this._nextT);
            this._nextT = null;
            var ring = this.ui.end.querySelector('.npl-ring'), stay = this.ui.end.querySelector('[data-a="stay"]');
            if (ring) { ring.remove(); }
            if (stay) { stay.remove(); }
        }
    };
    Player.prototype.goNext = function () {
        this.cancelNext();
        if (this.o.next && typeof this.o.next.go === 'function') { this.o.next.go(); }
    };

    /* ---- painting ---- */

    Player.prototype.paintTime = function () {
        var d = this.dur(), t = this.v.currentTime || 0, f = d ? t / d : 0;
        if (this.ui.cur) { this.ui.cur.textContent = this.remaining && d ? '-' + fmt(d - t) : fmt(t); }
        this.ui.played.style.width = (f * 100) + '%';
        this.ui.thumb.style.left = (f * 100) + '%';
        this.ui.track.setAttribute('aria-valuetext', fmt(t) + ' از ' + fmt(d));
        var lim = this.seekLimit();
        if (d && lim < d) {
            this.r.classList.add('has-lock');
            this.ui.lockz.style.width = ((1 - lim / d) * 100) + '%';
        } else {
            this.r.classList.remove('has-lock');
        }
    };
    Player.prototype.paintBuffer = function () {
        var v = this.v, d = this.dur();
        if (!d || !v.buffered.length) { return; }
        var t = v.currentTime, end = 0;
        for (var i = 0; i < v.buffered.length; i++) {
            if (v.buffered.start(i) <= t + 0.5) { end = Math.max(end, v.buffered.end(i)); }
        }
        this.ui.buf.style.width = clamp(end / d * 100, 0, 100) + '%';
    };
    Player.prototype.paintSeen = function () {
        if (!this.tracker) { return; }
        if (this.ui.seen) {
            var html = '';
            this.tracker.ranges().forEach(function (g) {
                html += '<i style="left:' + (g[0] * 100) + '%;width:' + ((g[1] - g[0]) * 100) + '%"></i>';
            });
            this.ui.seen.innerHTML = html;
        }
        if (this.ui.seenb) {
            var pct = this.tracker.pct();
            this.ui.seenb.textContent = fa(pct >= 99.5 ? 100 : Math.floor(pct)) + '٪';
        }
    };
    Player.prototype.paintPlay = function () {
        var p = this.playing();
        this.r.classList.toggle('is-playing', p);
        this.ui.play.innerHTML = p ? I.pause : I.play;
        this.ui.play.setAttribute('aria-label', p ? 'توقف' : 'پخش');
    };
    Player.prototype.paintVolume = function () {
        if (!this.ui.mute) { return; }
        var v = this.v, level = v.muted ? 0 : v.volume;
        this.ui.mute.innerHTML = level === 0 ? I.mute : (level < 0.5 ? I.volLow : I.vol);
        if (document.activeElement !== this.ui.volr) { this.ui.volr.value = level; }
    };
    Player.prototype.paintRate = function () {
        if (!this.ui.gearw) { return; }
        var r = this.v.playbackRate;
        this.ui.gearw.classList.toggle('has-rate', r !== 1);
        this.ui.rate.textContent = fa(r) + '×';
    };
    Player.prototype.paintFull = function () {
        if (!this.ui.full) { return; }
        var on = this.isFull();
        this.ui.full.innerHTML = on ? I.exitFull : I.full;
        this.ui.full.setAttribute('aria-label', on ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه');
    };
    Player.prototype.preview = function (f) {
        var d = this.dur(), ui = this.ui, html = fmt(f * d), t = f * d;
        if (t > this.seekLimit()) { html += '<b>' + 'قفل — هنوز تماشا نشده' + '</b>'; }
        else if (this.tracker && this.tracker.has(this.tracker.idx(t))) { html += '<b>دیده‌شده</b>'; }
        ui.tip.innerHTML = html;
        var w = ui.track.getBoundingClientRect().width, tw = ui.tip.offsetWidth / 2;
        ui.tip.style.left = clamp(f * w, tw, w - tw) + 'px';
    };
    Player.prototype.toast = function (msg) {
        var t = this.ui.toast;
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(this._toastT);
        this._toastT = setTimeout(function () { t.classList.remove('show'); }, 1400);
    };
    Player.prototype.poke = function () {
        var r = this.r, self = this;
        r.classList.remove('is-idle');
        clearTimeout(this._idleT);
        this._idleT = setTimeout(function () {
            if (self.playing() && !self.ui.menu.classList.contains('open') && !r.classList.contains('is-scrub')) { r.classList.add('is-idle'); }
        }, clamp(+this.cfg.idle || 2.6, 1, 15) * 1000);
    };
    Player.prototype.moveWatermark = function () {
        if (!this.wm) { return; }
        this.wm.style.left = (6 + Math.random() * 60) + '%';
        this.wm.style.top = (8 + Math.random() * 62) + '%';
    };

    /* ---- actions ---- */

    Player.prototype.play = function (auto) {
        var self = this, pr = this.v.play();
        this.ui.resume.classList.remove('show');
        if (pr && pr.catch) {
            pr.catch(function () {
                // Autoplay with sound blocked: retry muted.
                if (auto && !self.v.muted) { self.v.muted = true; self.v.play().catch(function () {}); }
            });
        }
    };
    Player.prototype.pause = function () { this.v.pause(); };
    Player.prototype.toggle = function () { if (this.playing()) { this.pause(); } else { this.play(); } };
    Player.prototype.seek = function (t, quiet) {
        var d = this.dur();
        if (!d) { return; }
        var lim = this.seekLimit();
        if (t > lim) {
            t = lim;
            if (!quiet) { this.toast('برای جلو رفتن، ابتدا این بخش را تماشا کنید'); }
        }
        this.v.currentTime = clamp(t, 0, Math.max(0, d - 0.05));
        this.lastT = this.v.currentTime;
        this.paintTime();
        if (!quiet) { this.poke(); }
    };
    Player.prototype.skip = function (s, ripple) {
        this.seek((this.v.currentTime || 0) + s);
        if (ripple) {
            var n = s < 0 ? this.ui.tapL : this.ui.tapR;
            n.classList.add('show');
            clearTimeout(n._t);
            n._t = setTimeout(function () { n.classList.remove('show'); }, 450);
        } else {
            this.toast((s < 0 ? '' : '+') + fa(s) + ' ثانیه');
        }
    };
    Player.prototype.toggleMute = function () {
        var v = this.v;
        if (v.muted || v.volume === 0) { v.muted = false; if (v.volume === 0) { v.volume = 0.6; } } else { v.muted = true; }
    };
    Player.prototype.isFull = function () {
        return (document.fullscreenElement || document.webkitFullscreenElement) === this.r;
    };
    Player.prototype.toggleFull = function () {
        var r = this.r, v = this.v;
        if (this.isFull()) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else if (r.requestFullscreen || r.webkitRequestFullscreen) {
            var p = (r.requestFullscreen || r.webkitRequestFullscreen).call(r);
            if (p && p.then && screen.orientation && screen.orientation.lock && isTouch()) {
                p.then(function () { screen.orientation.lock('landscape').catch(function () {}); });
            }
        } else if (v.webkitEnterFullscreen) {
            v.webkitEnterFullscreen(); // iPhone
        }
    };
    Player.prototype.togglePip = function () {
        if (document.pictureInPictureElement) { document.exitPictureInPicture(); }
        else if (this.v.requestPictureInPicture) { this.v.requestPictureInPicture().catch(function () {}); }
    };

    /* ---- settings menu ---- */

    Player.prototype.rateLabel = function (r) { return r === 1 ? 'عادی' : fa(r) + '×'; };
    Player.prototype.qualityLabel = function (i) {
        if (i < 0) { return 'خودکار'; }
        var l = this.engine.levels[i];
        if (l.label) { return fa(l.label); }
        return l.height ? fa(l.height) + 'p' : fa(Math.round(l.bitrate / 1000)) + ' kbps';
    };
    Player.prototype.toggleMenu = function () {
        if (this.ui.menu.classList.contains('open')) { this.closeMenu(); } else { this.openMenu('main'); }
    };
    Player.prototype.closeMenu = function () { this.ui.menu.classList.remove('open'); };
    Player.prototype.openMenu = function (page) {
        var self = this, m = this.ui.menu, v = this.v, eng = this.engine, c = this.cfg;
        var hasQ = c.controls.quality && eng && eng.levels.length > 1;
        var item = function (label, value, fn, checked) {
            var b = el('button', 'npl-mi', (checked != null ? '<span class="ck">' + (checked ? I.check : '') + '</span>' : '') + '<span class="l"></span><span class="v"></span>');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.querySelector('.l').textContent = label;
            if (value) { b.querySelector('.v').innerHTML = value; }
            b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
            m.appendChild(b);
        };
        var head = function (title) {
            var b = el('button', 'npl-mh', I.chev + '<span></span>');
            b.type = 'button';
            b.querySelector('span').textContent = title;
            b.addEventListener('click', function (e) { e.stopPropagation(); self.openMenu('main'); });
            m.appendChild(b);
        };
        // Only one section: open it directly.
        if (page === 'main' && !hasQ && c.controls.speed) { page = 'speed'; }
        if (page === 'main' && hasQ && !c.controls.speed) { page = 'quality'; }
        m.innerHTML = '';
        if (page === 'main') {
            item('سرعت پخش', this.rateLabel(v.playbackRate) + I.chev, function () { self.openMenu('speed'); });
            var cur = eng.current();
            item('کیفیت', (cur < 0 ? 'خودکار' : this.qualityLabel(cur)) + I.chev, function () { self.openMenu('quality'); });
        } else if (page === 'speed') {
            if (hasQ) { head('سرعت پخش'); }
            c.speeds.forEach(function (s) {
                item(self.rateLabel(s), '', function () { v.playbackRate = s; self.closeMenu(); }, v.playbackRate === s);
            });
        } else if (page === 'quality') {
            if (c.controls.speed) { head('کیفیت'); }
            var curL = eng.current();
            // Separate files have no "auto": the viewer picks one.
            if (eng.kind !== 'sources') { item('خودکار', '', function () { eng.level(-1); self.closeMenu(); self.toast('کیفیت: خودکار'); }, curL < 0); }
            eng.levels.map(function (l, i) { return { i: i, h: l.height, b: l.bitrate }; })
                .sort(function (a, b) { return (b.h - a.h) || (b.b - a.b); })
                .forEach(function (l) {
                    item(self.qualityLabel(l.i), l.h >= 720 ? 'HD' : '', function () { eng.level(l.i); self.closeMenu(); self.toast('کیفیت: ' + self.qualityLabel(l.i)); }, curL === l.i);
                });
        }
        m.classList.add('open');
        this.poke();
    };

    /* ---- teardown ---- */

    Player.prototype.destroy = function () {
        if (this.destroyed) { return; }
        this.flush();
        this.cancelNext();
        this.destroyed = true;
        this._timers.forEach(function (t) { clearTimeout(t); clearInterval(t); });
        clearTimeout(this._idleT);
        this._listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
        if (this.engine) { this.engine.destroy(); }
        try { this.v.pause(); this.v.removeAttribute('src'); this.v.load(); } catch (e) { /* detached */ }
        if (this.r.parentNode) { this.r.parentNode.removeChild(this.r); }
    };

    /* ------------------------------------------------------------------
     * Public API
     * ---------------------------------------------------------------- */

    window.NiasPlayer = {
        create: function (container, opts) { return new Player(container, opts); },
        detect: detect,
        Tracker: Tracker,
        defaults: DEFAULTS,
        /** Union of two watch maps { n, m } (same bucket count); otherwise the one that has data. */
        mergeWatch: function (a, b) {
            if (!a || !a.m) { return b || null; }
            if (!b || !b.m || +a.n !== +b.n) { return a; }
            var x = b64decode(a.m), y = b64decode(b.m);
            if (!x || !y || x.length !== y.length) { return a; }
            var cnt = 0;
            for (var i = 0; i < x.length; i++) {
                x[i] |= y[i];
                for (var k = x[i]; k; k &= k - 1) { cnt++; }
            }
            return { n: +a.n, m: b64encode(x), p: Math.round(cnt / a.n * 1000) / 10, t: a.t || b.t || 0 };
        },
        /** Upgrade every .nias-vp-wrap[data-src] / [data-nias-player] placeholder inside root. */
        scan: function (root) {
            (root || document).querySelectorAll('.nias-vp-wrap[data-src]:not([data-npl]),[data-nias-player]:not([data-npl])').forEach(function (n) {
                n.setAttribute('data-npl', '1');
                var box = el('div');
                n.appendChild(box);
                new Player(box, { src: n.getAttribute('data-src') || n.getAttribute('data-nias-player'), ratio: true, track: false, poster: n.getAttribute('data-poster') || '' });
            });
        }
    };
})();
