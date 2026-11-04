/**
 * Nias certificate canvas designer (طراح مدرک).
 *
 * A dependency-free editor for certificate templates. The design is a JSON
 * model in millimetres (see inc/certificate/designer.php); the canvas renders
 * it at `scale` px/mm so what you see matches the mPDF output.
 *
 * Features: multi-select (Shift/Ctrl-click, marquee), group move with smart
 * guides, align & distribute, inline text editing (double-click), copy/paste
 * across templates, context menu, drag-to-reorder layers, undo/redo, local
 * draft recovery, JSON import/export, QR / Code128 barcode elements.
 */
(function () {
    'use strict';

    var BOOT = window.NIAS_CERT_DESIGNER;
    var root = document.getElementById('nias-cert-designer');
    if (!BOOT || !root) { return; }

    var PT_TO_MM = 0.352778;
    var SNAP_PX = 6;
    var HISTORY_MAX = 100;
    var CLIP_KEY = 'nias_cert_clipboard';
    var DRAFT_KEY = 'nias_cert_draft_';

    /* ------------------------------------------------------------------
     * Small helpers
     * ---------------------------------------------------------------- */

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) { return; }
                if (k === 'class') { el.className = v; }
                else if (k === 'html') { el.innerHTML = v; }
                else if (k === 'text') { el.textContent = v; }
                else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2), v); }
                else if (k === 'style' && typeof v === 'object') { Object.assign(el.style, v); }
                else { el.setAttribute(k, v === true ? '' : v); }
            });
        }
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) { return; }
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return el;
    }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function round(n, d) { var p = Math.pow(10, d == null ? 1 : d); return Math.round(n * p) / p; }
    function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
    function uid() { return 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
    function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function store(key, val) { try { if (val === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, JSON.stringify(val)); } } catch (e) { /* private mode / quota */ } }
    function load(key) { try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : null; } catch (e) { return null; } }

    function post(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', BOOT.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(BOOT.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    throw new Error((res && res.data && res.data.message) || 'خطای نامشخص');
                }
                return res.data;
            });
    }

    /* ------------------------------------------------------------------
     * Icons
     * ---------------------------------------------------------------- */

    var ICON = {
        text: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></svg>',
        token: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 2-2 3 1 1 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 2 2 3-1 1-2 1.5-2 3v2c0 2-1 3-3 3"/></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
        qr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>',
        barcode: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 5v14M7 5v14M10 5v14M14 5v14M16 5v14M20 5v14"/></svg>',
        rect: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/></svg>',
        ellipse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="12" rx="9" ry="7"/></svg>',
        line: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 12h16"/></svg>',
        undo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/></svg>',
        redo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h4"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        minus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg>',
        fit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
        grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
        eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>',
        eyeOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/></svg>',
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        unlock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>',
        copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>',
        paste: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1"/></svg>',
        up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>',
        down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
        star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z"/></svg>',
        save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>',
        pdf: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"/><path d="M14 3v6h6"/></svg>',
        more: '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
        download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>',
        upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V5m0 0-4 4m4-4 4 4M5 20h14"/></svg>',
        alignR: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M10 12h10M6 18h14"/></svg>',
        alignC: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 12h10M5 18h14"/></svg>',
        alignL: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h10M4 18h14"/></svg>',
        alignJ: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        // Object alignment (geometry).
        oLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 3v18"/><rect x="7" y="6" width="12" height="4" rx="1"/><rect x="7" y="14" width="7" height="4" rx="1"/></svg>',
        oHCenter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 3v18"/><rect x="5" y="6" width="14" height="4" rx="1"/><rect x="8" y="14" width="8" height="4" rx="1"/></svg>',
        oRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M20 3v18"/><rect x="5" y="6" width="12" height="4" rx="1"/><rect x="10" y="14" width="7" height="4" rx="1"/></svg>',
        oTop: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 4h18"/><rect x="6" y="7" width="4" height="12" rx="1"/><rect x="14" y="7" width="4" height="7" rx="1"/></svg>',
        oVCenter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 12h18"/><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="8" width="4" height="8" rx="1"/></svg>',
        oBottom: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 20h18"/><rect x="6" y="5" width="4" height="12" rx="1"/><rect x="14" y="10" width="4" height="7" rx="1"/></svg>',
        distH: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 4v16M21 4v16"/><rect x="9" y="7" width="6" height="10" rx="1"/></svg>',
        distV: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 3h16M4 21h16"/><rect x="7" y="9" width="10" height="6" rx="1"/></svg>'
    };
    function ic(name) { return '<span class="ncd-ic">' + ICON[name] + '</span>'; }

    /* ------------------------------------------------------------------
     * Code 128-B (same encoder as nias_cert_code128_widths() in PHP)
     * ---------------------------------------------------------------- */

    var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

    function barcodeAscii(t) { return String(t || '').replace(/[^\x20-\x7E]/g, ''); }
    function code128Widths(text) {
        text = barcodeAscii(text);
        if (!text) { return []; }
        var codes = [104], sum = 104, i;
        for (i = 0; i < text.length; i++) { var v = text.charCodeAt(i) - 32; codes.push(v); sum += v * (i + 1); }
        codes.push(sum % 103); codes.push(106);
        var w = [0, 10];
        codes.forEach(function (c) { C128[c].split('').forEach(function (d) { w.push(+d); }); });
        w.push(10);
        return w;
    }
    function barcodeSvg(text, color) {
        var w = code128Widths(text);
        if (!w.length) { return ''; }
        var total = w.reduce(function (a, b) { return a + b; }, 0), x = 0, rects = '';
        w.forEach(function (n, i) {
            if (i % 2 === 0 && n > 0) { rects += '<rect x="' + x + '" y="0" width="' + n + '" height="10"/>'; }
            x += n;
        });
        return '<svg viewBox="0 0 ' + total + ' 10" preserveAspectRatio="none" width="100%" height="100%"><g fill="' + (color || '#000') + '" shape-rendering="crispEdges">' + rects + '</g></svg>';
    }

    /* ------------------------------------------------------------------
     * State
     * ---------------------------------------------------------------- */

    var S = {
        templates: (BOOT.templates || []).slice(),
        defaultId: BOOT.defaultTemplate || 0,
        mode: BOOT.mode || 'classic',
        currentId: 0,
        title: '',
        design: null,
        savedJson: '',
        selection: [],
        editing: null,     // id of the text element being edited inline
        history: [],
        hIndex: -1,
        zoom: 0,           // 0 = fit
        scale: 3,
        grid: false,
        sample: true,
        saving: false,
        pasteCount: 0
    };

    function findTpl(id) { return S.templates.filter(function (t) { return t.id === id; })[0] || null; }
    function pageDims() {
        var p = S.design.page, sz = BOOT.pageSizes[p.size] || BOOT.pageSizes.A4;
        return p.orientation === 'P' ? [sz[0], sz[1]] : [sz[1], sz[0]];
    }
    function getEl(id) { return S.design ? S.design.elements.filter(function (e) { return e.id === id; })[0] || null : null; }
    function isSel(id) { return S.selection.indexOf(id) > -1; }
    function selEls() { return S.design ? S.design.elements.filter(function (e) { return isSel(e.id); }) : []; }
    function sel() { return S.selection.length === 1 ? getEl(S.selection[0]) : null; }
    function isDirty() { return S.design && JSON.stringify({ t: S.title, d: S.design }) !== S.savedJson; }
    function markSaved() { S.savedJson = JSON.stringify({ t: S.title, d: S.design }); }

    function bbox(els) {
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        els.forEach(function (e) { x1 = Math.min(x1, e.x); y1 = Math.min(y1, e.y); x2 = Math.max(x2, e.x + e.w); y2 = Math.max(y2, e.y + e.h); });
        return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    }

    /* ------------------------------------------------------------------
     * History + drafts
     * ---------------------------------------------------------------- */

    function resetHistory() { S.history = [JSON.stringify(S.design)]; S.hIndex = 0; }
    function commit() {
        var json = JSON.stringify(S.design);
        if (json === S.history[S.hIndex]) { refreshChrome(); return; }
        S.history = S.history.slice(0, S.hIndex + 1);
        S.history.push(json);
        if (S.history.length > HISTORY_MAX) { S.history.shift(); }
        S.hIndex = S.history.length - 1;
        saveDraft();
        refreshChrome();
    }
    function restoreHistory() {
        S.design = JSON.parse(S.history[S.hIndex]);
        S.selection = S.selection.filter(function (id) { return !!getEl(id); });
        saveDraft();
        renderAll();
    }
    function undo() { if (S.hIndex > 0) { S.hIndex--; restoreHistory(); } }
    function redo() { if (S.hIndex < S.history.length - 1) { S.hIndex++; restoreHistory(); } }

    var draftTimer;
    function saveDraft() {
        if (!S.currentId) { return; }
        clearTimeout(draftTimer);
        draftTimer = setTimeout(function () {
            if (isDirty()) { store(DRAFT_KEY + S.currentId, { at: Date.now(), title: S.title, design: S.design }); }
            else { store(DRAFT_KEY + S.currentId, null); }
        }, 400);
    }
    function clearDraft() { clearTimeout(draftTimer); if (S.currentId) { store(DRAFT_KEY + S.currentId, null); } }

    /* ------------------------------------------------------------------
     * Layout skeleton
     * ---------------------------------------------------------------- */

    var R = {}; // references to chrome nodes

    function buildShell() {
        root.innerHTML = '';
        R.toast = h('div', { class: 'ncd-toast', role: 'status' });

        // Top bar.
        R.tplSelect = h('select', { class: 'ncd-select', 'aria-label': 'قالب', onchange: function () { switchTemplate(parseInt(this.value, 10)); } });
        R.title = h('input', { class: 'ncd-input ncd-title', type: 'text', placeholder: 'نام قالب', 'aria-label': 'نام قالب', oninput: function () { S.title = this.value; saveDraft(); refreshChrome(); } });
        R.btnNew = h('button', { type: 'button', class: 'ncd-btn', html: ic('plus') + '<span>قالب جدید</span>', onclick: function (e) { e.stopPropagation(); toggleMenu(R.presetMenu); } });
        R.presetMenu = h('div', { class: 'ncd-menu' });
        Object.keys(BOOT.presets).forEach(function (k) {
            R.presetMenu.appendChild(h('button', { type: 'button', class: 'ncd-menu-item', text: BOOT.presets[k], onclick: function () { closeMenus(); createTemplate(k); } }));
        });
        R.presetMenu.appendChild(h('div', { class: 'ncd-menu-sep' }));
        R.presetMenu.appendChild(h('button', { type: 'button', class: 'ncd-menu-item', html: '<span>درون‌ریزی از فایل (JSON)…</span>' + ic('upload'), onclick: function () { closeMenus(); importTemplate(); } }));

        R.btnMore = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'عملیات قالب', html: ic('more'), onclick: function (e) { e.stopPropagation(); toggleMenu(R.moreMenu); } });
        R.moreMenu = h('div', { class: 'ncd-menu' }, [
            h('button', { type: 'button', class: 'ncd-menu-item', html: '<span>تکثیر قالب</span>' + ic('copy'), onclick: function () { closeMenus(); duplicateTemplate(); } }),
            h('button', { type: 'button', class: 'ncd-menu-item', html: '<span>برون‌بری (دانلود JSON)</span>' + ic('download'), onclick: function () { closeMenus(); exportTemplate(); } }),
            h('div', { class: 'ncd-menu-sep' }),
            h('button', { type: 'button', class: 'ncd-menu-item ncd-danger', html: '<span>حذف قالب</span>' + ic('trash'), onclick: function () { closeMenus(); deleteTemplate(); } })
        ]);
        R.btnDefault = h('button', { type: 'button', class: 'ncd-btn', html: ic('star') + '<span>پیش‌فرض</span>', onclick: setDefault });
        R.btnUndo = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'واگرد (Ctrl+Z)', html: ic('undo'), onclick: undo });
        R.btnRedo = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'انجام دوباره (Ctrl+Y)', html: ic('redo'), onclick: redo });
        R.zoomLabel = h('button', { type: 'button', class: 'ncd-zoom-label', title: 'اندازهٔ مناسب (Ctrl+0)', onclick: function () { S.zoom = 0; renderStage(); } });
        R.btnGrid = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'شبکه و چسبندگی به شبکه', html: ic('grid'), onclick: function () { S.grid = !S.grid; renderStage(); refreshChrome(); } });
        R.btnSample = h('button', { type: 'button', class: 'ncd-btn', html: '<span>دادهٔ نمونه</span>', title: 'نمایش فیلدهای پویا با دادهٔ نمونه یا به‌صورت {کد}', onclick: function () { S.sample = !S.sample; renderStage(); refreshChrome(); } });
        R.btnPreview = h('button', { type: 'button', class: 'ncd-btn', html: ic('pdf') + '<span>پیش‌نمایش PDF</span>', onclick: previewPdf });
        R.btnSave = h('button', { type: 'button', class: 'ncd-btn ncd-primary', html: ic('save') + '<span>ذخیره</span>', onclick: save });

        var top = h('div', { class: 'ncd-top' }, [
            h('div', { class: 'ncd-top-group' }, [
                R.tplSelect, R.title,
                h('div', { class: 'ncd-menu-wrap' }, [R.btnNew, R.presetMenu]),
                h('div', { class: 'ncd-menu-wrap' }, [R.btnMore, R.moreMenu]),
                R.btnDefault
            ]),
            h('div', { class: 'ncd-top-group' }, [
                R.btnUndo, R.btnRedo, h('span', { class: 'ncd-sep' }),
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'کوچک‌نمایی', html: ic('minus'), onclick: function () { zoomBy(-0.5); } }),
                R.zoomLabel,
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'بزرگ‌نمایی (Ctrl + چرخ ماوس)', html: ic('plus'), onclick: function () { zoomBy(0.5); } }),
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'اندازهٔ مناسب', html: ic('fit'), onclick: function () { S.zoom = 0; renderStage(); } }),
                R.btnGrid, R.btnSample, h('span', { class: 'ncd-sep' }), R.btnPreview, R.btnSave
            ])
        ]);

        R.modeBar = h('div', { class: 'ncd-modebar' });
        R.draftBar = h('div', { class: 'ncd-modebar warn', style: { display: 'none' } });

        // Tools (add elements + layers).
        R.tokenMenu = h('div', { class: 'ncd-menu ncd-menu-tokens' });
        Object.keys(BOOT.tokens).forEach(function (k) {
            R.tokenMenu.appendChild(h('button', { type: 'button', class: 'ncd-menu-item', onclick: function () { closeMenus(); addElement('text', { text: '{' + k + '}', name: BOOT.tokens[k], bold: k === 'student_name' || k === 'course_name', fontSize: k === 'student_name' ? 26 : (k === 'course_name' ? 18 : 12) }); } }, [
                h('span', { text: BOOT.tokens[k] }), h('code', { text: '{' + k + '}' })
            ]));
        });
        var addBtn = function (icon, label, fn, title) { return h('button', { type: 'button', class: 'ncd-tool', title: title || label, html: ic(icon) + '<span>' + label + '</span>', onclick: fn }); };
        var tokenBtn = addBtn('token', 'فیلد پویا', function (e) { e.stopPropagation(); toggleMenu(R.tokenMenu); }, 'نام دانشجو، دوره، تاریخ، کد و …');

        R.layers = h('div', { class: 'ncd-layers' });
        var tools = h('aside', { class: 'ncd-tools' }, [
            h('div', { class: 'ncd-panel-title', text: 'افزودن المان' }),
            h('div', { class: 'ncd-toolgrid' }, [
                addBtn('text', 'متن', function () { addElement('text'); }),
                h('div', { class: 'ncd-menu-wrap' }, [tokenBtn, R.tokenMenu]),
                addBtn('image', 'تصویر', function () { pickMedia(function (att) { addImage(att); }); }),
                addBtn('qr', 'QR استعلام', function () { addElement('qr'); }),
                addBtn('barcode', 'بارکد', function () { addElement('barcode'); }, 'بارکد خطی Code128 از کد مدرک'),
                addBtn('rect', 'کادر', function () { addElement('rect'); }),
                addBtn('ellipse', 'دایره', function () { addElement('ellipse'); }),
                addBtn('line', 'خط', function () { addElement('line'); })
            ]),
            h('div', { class: 'ncd-panel-title', text: 'لایه‌ها' }),
            R.layers,
            h('details', { class: 'ncd-hint' }, [
                h('summary', { text: 'کلیدهای میانبر' }),
                h('div', { html:
                    '<b>Shift/Ctrl + کلیک</b> انتخاب چندتایی · <b>کشیدن روی فضای خالی</b> انتخاب گروهی<br>' +
                    '<b>دوبار کلیک</b> ویرایش متن روی بوم · <b>کلیک راست</b> منوی سریع<br>' +
                    '<b>جهت‌ها</b> ۱ میلی‌متر (Shift: ۱۰، Alt: ۰٫۱)<br>' +
                    '<b>Ctrl+C / X / V</b> کپی، برش، چسباندن (بین قالب‌ها هم) · <b>Ctrl+D</b> تکثیر<br>' +
                    '<b>Ctrl+A</b> انتخاب همه · <b>Delete</b> حذف · <b>Ctrl+] / [</b> ترتیب لایه<br>' +
                    '<b>Ctrl+Z / Y</b> واگرد · <b>Ctrl+S</b> ذخیره · <b>Ctrl+چرخ ماوس</b> زوم · <b>Ctrl+0</b> اندازهٔ مناسب<br>' +
                    '<b>Alt</b> هنگام کشیدن: بدون چسبندگی · <b>Shift</b> هنگام تغییر اندازه: حفظ/رهاکردن نسبت' })
            ])
        ]);

        // Stage.
        R.page = h('div', { class: 'ncd-page' });
        R.pageWrap = h('div', { class: 'ncd-page-wrap' }, [R.page]);
        R.stage = h('div', { class: 'ncd-stage', onpointerdown: onStagePointerDown, oncontextmenu: onStageContext }, [R.pageWrap]);
        R.stage.addEventListener('wheel', onWheel, { passive: false });

        // Properties.
        R.props = h('aside', { class: 'ncd-props' });

        R.body = h('div', { class: 'ncd-body' }, [tools, h('div', { class: 'ncd-center' }, [R.stage]), R.props]);
        R.empty = h('div', { class: 'ncd-empty' });
        R.ctx = h('div', { class: 'ncd-menu ncd-ctx', role: 'menu' });
        R.file = h('input', { type: 'file', accept: '.json,application/json', style: { display: 'none' }, onchange: onImportFile });

        root.appendChild(top);
        root.appendChild(R.modeBar);
        root.appendChild(R.draftBar);
        root.appendChild(R.body);
        root.appendChild(R.empty);
        root.appendChild(R.toast);
        root.appendChild(R.file);
        document.body.appendChild(R.ctx);

        document.addEventListener('click', closeMenus);
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', function () { if (S.zoom === 0) { renderStage(); } });
        window.addEventListener('beforeunload', function (e) { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });
    }

    function toggleMenu(menu) { var open = menu.classList.contains('open'); closeMenus(); if (!open) { menu.classList.add('open'); } }
    function closeMenus() {
        root.querySelectorAll('.ncd-menu.open').forEach(function (m) { m.classList.remove('open'); });
        if (R.ctx) { R.ctx.classList.remove('open'); }
    }

    var toastTimer;
    function toast(msg, type) {
        R.toast.textContent = msg;
        R.toast.className = 'ncd-toast show ' + (type || '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { R.toast.className = 'ncd-toast'; }, 2600);
    }

    /* ------------------------------------------------------------------
     * Chrome refresh (top bar, mode bar, empty state)
     * ---------------------------------------------------------------- */

    function refreshChrome() {
        var has = !!S.design;
        R.body.style.display = has ? '' : 'none';
        R.empty.style.display = has ? 'none' : '';
        [R.title, R.btnMore, R.btnDefault, R.btnUndo, R.btnRedo, R.btnPreview, R.btnSave].forEach(function (b) { b.disabled = !has; });

        R.tplSelect.innerHTML = '';
        if (!S.templates.length) {
            R.tplSelect.appendChild(h('option', { value: '0', text: '— قالبی وجود ندارد —' }));
        }
        S.templates.forEach(function (t) {
            R.tplSelect.appendChild(h('option', { value: String(t.id), text: (t.id === S.defaultId ? '★ ' : '') + t.title, selected: t.id === S.currentId }));
        });
        R.tplSelect.disabled = !S.templates.length;

        if (has) {
            if (R.title !== document.activeElement) { R.title.value = S.title; }
            R.btnUndo.disabled = S.hIndex <= 0;
            R.btnRedo.disabled = S.hIndex >= S.history.length - 1;
            var isDef = S.currentId === S.defaultId;
            R.btnDefault.classList.toggle('on', isDef);
            R.btnDefault.querySelector('span:last-child').textContent = isDef ? 'قالب پیش‌فرض' : 'تنظیم به‌عنوان پیش‌فرض';
            R.btnSave.classList.toggle('dirty', isDirty());
            R.btnSave.querySelector('span:last-child').textContent = S.saving ? 'در حال ذخیره…' : (isDirty() ? 'ذخیره *' : 'ذخیره شد');
        }
        R.btnGrid.classList.toggle('on', S.grid);
        R.btnSample.classList.toggle('on', S.sample);
        R.zoomLabel.textContent = fa(Math.round(S.scale / 3.7795 * 100)) + '٪';

        // Mode notice.
        R.modeBar.innerHTML = '';
        if (S.mode !== 'designer') {
            R.modeBar.className = 'ncd-modebar warn';
            R.modeBar.appendChild(h('span', { text: 'حالت فعلی مدرک «کلاسیک» است؛ قالب‌های این صفحه فقط وقتی استفاده می‌شوند که حالت «طراح پیشرفته» فعال باشد.' }));
            R.modeBar.appendChild(h('button', { type: 'button', class: 'ncd-btn ncd-primary', text: 'فعال‌سازی طراح پیشرفته', onclick: function () { setMode('designer'); } }));
        } else {
            R.modeBar.className = 'ncd-modebar ok';
            R.modeBar.appendChild(h('span', { text: S.defaultId ? 'طراح پیشرفته فعال است. مدارک با قالب پیش‌فرض (★) یا قالب انتخاب‌شده در صفحهٔ هر دوره صادر می‌شوند.' : 'طراح پیشرفته فعال است اما قالب پیش‌فرضی انتخاب نشده؛ تا انتخاب قالب، مدارک با طرح کلاسیک صادر می‌شوند.' }));
            R.modeBar.appendChild(h('button', { type: 'button', class: 'ncd-btn', text: 'بازگشت به حالت کلاسیک', onclick: function () { setMode('classic'); } }));
        }

        // Empty state.
        if (!has) {
            R.empty.innerHTML = '';
            R.empty.appendChild(h('div', { class: 'ncd-empty-t', text: 'اولین قالب مدرک خود را بسازید' }));
            R.empty.appendChild(h('div', { class: 'ncd-empty-s', text: 'یکی از طرح‌های آماده را انتخاب کنید و سپس آن را روی بوم ویرایش کنید.' }));
            var grid = h('div', { class: 'ncd-empty-grid' });
            Object.keys(BOOT.presets).forEach(function (k) {
                grid.appendChild(h('button', { type: 'button', class: 'ncd-preset-card ncd-preset-' + k, onclick: function () { createTemplate(k); } }, [
                    h('span', { class: 'ncd-preset-thumb' }), h('span', { class: 'ncd-preset-label', text: BOOT.presets[k] })
                ]));
            });
            R.empty.appendChild(grid);
            R.empty.appendChild(h('button', { type: 'button', class: 'ncd-btn', style: { marginTop: '16px' }, html: ic('upload') + '<span>درون‌ریزی قالب از فایل</span>', onclick: importTemplate }));
        }
    }

    /* ------------------------------------------------------------------
     * Stage rendering
     * ---------------------------------------------------------------- */

    function computeScale() {
        var d = pageDims();
        if (S.zoom > 0) { return S.zoom; }
        var avail = Math.max(300, R.stage.clientWidth - 48);
        var availH = Math.max(300, window.innerHeight - 260);
        return Math.max(1, Math.min(avail / d[0], availH / d[1]));
    }
    function zoomBy(delta) { S.zoom = clamp(round((S.zoom || S.scale) + delta, 2), 1, 12); renderStage(); }

    function onWheel(e) {
        if (!S.design || !(e.ctrlKey || e.metaKey)) { return; }
        e.preventDefault();
        zoomBy(e.deltaY < 0 ? 0.25 : -0.25);
    }

    function sampleValue(t) {
        return String(t).replace(/\{([a-z_]+)\}/g, function (m, k) { return BOOT.sample[k] != null ? BOOT.sample[k] : m; });
    }
    function displayText(t) { return S.sample ? sampleValue(t) : String(t); }

    function hexToRgba(hex, o) {
        if (!hex) { return ''; }
        var m = hex.replace('#', '');
        if (m.length === 3) { m = m[0] + m[0] + m[1] + m[1] + m[2] + m[2]; }
        var r = parseInt(m.substr(0, 2), 16), g = parseInt(m.substr(2, 2), 16), b = parseInt(m.substr(4, 2), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + (o == null ? 1 : o) + ')';
    }

    function styleNode(node, el) {
        var s = S.scale;
        node.style.left = (el.x * s) + 'px';
        node.style.top = (el.y * s) + 'px';
        node.style.width = (el.w * s) + 'px';
        node.style.height = (el.h * s) + 'px';
    }

    function fillNode(node, el) {
        var s = S.scale, o = el.opacity == null ? 1 : el.opacity, single = S.selection.length === 1;
        node.className = 'ncd-el ncd-el-' + el.type + (isSel(el.id) ? ' selected' : '') + (el.locked ? ' locked' : '') + (el.hidden ? ' is-hidden' : '') + (S.editing === el.id ? ' editing' : '');
        node.innerHTML = '';
        node.style.background = '';
        node.style.border = '';
        node.style.borderRadius = '';
        node.style.opacity = '';
        styleNode(node, el);

        if (el.type === 'text') {
            var inner = h('div', { class: 'ncd-text', dir: el.dir === 'ltr' ? 'ltr' : 'rtl' });
            inner.textContent = S.editing === el.id ? (el.text || '') : displayText(el.text || '');
            inner.style.textAlign = el.align || 'center';
            inner.style.color = hexToRgba(el.color || '#000000', o);
            inner.style.fontWeight = el.bold ? '700' : '400';
            inner.style.lineHeight = String(el.lineHeight || 1.4);
            if (el.bg) { node.style.background = hexToRgba(el.bg, o); }
            node.appendChild(inner);
            var px = (el.fontSize || 14) * PT_TO_MM * s;
            inner.style.fontSize = px + 'px';
            node.classList.toggle('fit', el.fit !== false);
            if (el.fit !== false && S.editing !== el.id) {
                // Mirror mPDF's shrink-to-fit for fixed-position blocks.
                requestAnimationFrame(function () {
                    var tries = 0;
                    while (inner.scrollHeight > node.clientHeight + 1 && tries < 40) {
                        px *= 0.95; inner.style.fontSize = px + 'px'; tries++;
                    }
                });
            }
        } else if (el.type === 'image') {
            if (el.src) {
                var img = h('img', { src: el.src, draggable: 'false', alt: '' });
                img.style.opacity = o;
                node.appendChild(img);
            } else {
                node.appendChild(h('div', { class: 'ncd-ph', html: ic('image') }));
            }
        } else if (el.type === 'qr') {
            var side = Math.min(el.w, el.h) * s;
            var qr = h('div', { class: 'ncd-qr', style: { width: side + 'px', height: side + 'px', background: el.bg ? hexToRgba(el.bg, o) : 'transparent' } });
            var ink = h('div', { class: 'ncd-qr-ink', style: { background: hexToRgba(el.color || '#000000', o) } });
            if (BOOT.sampleQr) {
                ink.style.webkitMaskImage = ink.style.maskImage = 'url("' + BOOT.sampleQr + '")';
            }
            qr.appendChild(ink);
            node.appendChild(qr);
        } else if (el.type === 'barcode') {
            var value = barcodeAscii(sampleValue(el.text || '{code}'));
            if (el.bg) { node.style.background = hexToRgba(el.bg, o); }
            var svg = barcodeSvg(value, hexToRgba(el.color || '#000000', o));
            var labelH = el.showText ? Math.min(5, el.h * 0.3) : 0;
            node.appendChild(h('div', { class: 'ncd-bars', html: svg || '<div class="ncd-ph">!</div>', style: { height: ((el.h - labelH) * s) + 'px' } }));
            if (el.showText) {
                node.appendChild(h('div', { class: 'ncd-bars-t', text: value, style: { fontSize: (Math.max(5, labelH * 1.8) * PT_TO_MM * s) + 'px', paddingTop: (0.6 * s) + 'px', color: hexToRgba(el.color || '#000000', o) } }));
            }
        } else {
            if (el.bg) { node.style.background = hexToRgba(el.bg, o); }
            if (el.borderWidth && el.borderColor) { node.style.border = Math.max(1, el.borderWidth * s) + 'px solid ' + hexToRgba(el.borderColor, o); }
            if (el.type === 'ellipse') { node.style.borderRadius = '50%'; }
            else if (el.radius) { node.style.borderRadius = (el.radius * s) + 'px'; }
            if (!el.bg && !(el.borderWidth && el.borderColor)) { node.classList.add('ncd-empty-shape'); }
        }

        if (single && isSel(el.id) && !el.locked && S.editing !== el.id) {
            ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].forEach(function (d) {
                if (el.type === 'line' && (d === 'n' || d === 's' || d.length === 2) && el.h < 2) { return; }
                node.appendChild(h('span', { class: 'ncd-handle ncd-h-' + d, 'data-handle': d }));
            });
            node.appendChild(h('span', { class: 'ncd-size-badge', text: fa(round(el.w)) + ' × ' + fa(round(el.h)) }));
        }
    }

    function renderStage() {
        if (!S.design) { return; }
        S.scale = computeScale();
        var d = pageDims(), s = S.scale, p = S.design.page;
        R.page.style.width = (d[0] * s) + 'px';
        R.page.style.height = (d[1] * s) + 'px';
        R.page.style.backgroundColor = p.bgColor || '#ffffff';
        R.page.style.backgroundImage = p.bgImage ? 'url("' + p.bgImage.replace(/"/g, '%22') + '")' : '';
        R.page.classList.toggle('grid', S.grid);
        R.page.style.setProperty('--grid', (5 * s) + 'px');
        R.page.innerHTML = '';
        S.design.elements.forEach(function (el) {
            var node = h('div', { 'data-id': el.id });
            fillNode(node, el);
            node.addEventListener('pointerdown', onElPointerDown);
            node.addEventListener('dblclick', onElDblClick);
            node.addEventListener('contextmenu', onElContext);
            R.page.appendChild(node);
        });
        R.groupBox = h('div', { class: 'ncd-groupbox' });
        R.guideV = h('div', { class: 'ncd-guide v' });
        R.guideH = h('div', { class: 'ncd-guide h' });
        R.marquee = h('div', { class: 'ncd-marquee' });
        [R.groupBox, R.guideV, R.guideH, R.marquee].forEach(function (n) { R.page.appendChild(n); });
        updateGroupBox();
        refreshChrome();
    }

    function updateGroupBox() {
        if (!R.groupBox) { return; }
        var els = selEls();
        if (els.length < 2) { R.groupBox.style.display = 'none'; return; }
        var b = bbox(els), s = S.scale;
        Object.assign(R.groupBox.style, { display: 'block', left: (b.x * s) + 'px', top: (b.y * s) + 'px', width: (b.w * s) + 'px', height: (b.h * s) + 'px' });
    }

    function nodeOf(id) { return R.page.querySelector('[data-id="' + id + '"]'); }
    function refreshEl(el) { var n = nodeOf(el.id); if (n) { fillNode(n, el); } updateGroupBox(); }

    function renderAll() {
        renderStage();
        renderLayers();
        renderProps();
    }

    /* ------------------------------------------------------------------
     * Selection
     * ---------------------------------------------------------------- */

    function setSelection(ids) {
        var next = (ids || []).filter(function (id) { return !!getEl(id); });
        if (next.join() === S.selection.join()) { return; }
        if (S.editing) { finishEditing(); }
        S.selection = next;
        renderAll();
    }
    function select(id) { setSelection(id ? [id] : []); }
    function toggleSelect(id) {
        var next = S.selection.slice(), i = next.indexOf(id);
        if (i > -1) { next.splice(i, 1); } else { next.push(id); }
        setSelection(next);
    }
    function selectAll() { setSelection(S.design.elements.filter(function (e) { return !e.hidden && !e.locked; }).map(function (e) { return e.id; })); }

    /* ------------------------------------------------------------------
     * Pointer interaction: move / resize / marquee
     * ---------------------------------------------------------------- */

    var drag = null;

    function pointerMm(e) {
        var r = R.page.getBoundingClientRect();
        return { x: (e.clientX - r.left) / S.scale, y: (e.clientY - r.top) / S.scale };
    }

    function onElPointerDown(e) {
        if (e.button !== 0) { return; }
        var node = e.currentTarget, id = node.getAttribute('data-id');
        var el = getEl(id);
        if (!el || S.editing === id) { return; }
        e.preventDefault();
        e.stopPropagation();
        closeMenus();
        if (e.shiftKey || e.ctrlKey || e.metaKey) { toggleSelect(id); return; }
        // Own double-click detection: selecting re-renders the nodes, which
        // can swallow the browser's dblclick event.
        // Only a click (no movement) counts towards a double-click; see onPointerUp.
        var last = onElPointerDown.last;
        if (last && last.id === id && Date.now() - last.t < 400 && el.type === 'text' && !el.locked) {
            onElPointerDown.last = null;
            startEditing(el);
            return;
        }
        if (!isSel(id)) { select(id); }
        var handle = e.target.getAttribute && e.target.getAttribute('data-handle');
        var movers = handle ? [el] : selEls().filter(function (x) { return !x.locked; });
        if (!movers.length) { return; }
        var origs = {};
        movers.forEach(function (m) { origs[m.id] = { x: m.x, y: m.y, w: m.w, h: m.h }; });
        drag = { kind: handle ? 'resize' : 'move', id: id, handle: handle, start: pointerMm(e), origs: origs, box: bbox(movers), moved: false };
        startCapture(e);
    }

    function onStagePointerDown(e) {
        if (e.button !== 0 || !S.design) { return; }
        if (e.target.closest && e.target.closest('.ncd-el')) { return; }
        closeMenus();
        if (S.editing) { finishEditing(); }
        var p = pointerMm(e);
        drag = { kind: 'marquee', start: p, add: e.shiftKey || e.ctrlKey || e.metaKey, base: S.selection.slice(), moved: false };
        startCapture(e);
    }

    function startCapture(e) {
        try { R.stage.setPointerCapture(e.pointerId); } catch (err) { /* synthetic pointer */ }
        R.stage.addEventListener('pointermove', onPointerMove);
        R.stage.addEventListener('pointerup', onPointerUp);
        R.stage.addEventListener('pointercancel', onPointerUp);
    }

    function snapLines(excludeIds) {
        var d = pageDims(), xs = [0, d[0] / 2, d[0]], ys = [0, d[1] / 2, d[1]];
        S.design.elements.forEach(function (o) {
            if (excludeIds.indexOf(o.id) > -1 || o.hidden) { return; }
            xs.push(o.x, o.x + o.w / 2, o.x + o.w);
            ys.push(o.y, o.y + o.h / 2, o.y + o.h);
        });
        return { xs: xs, ys: ys };
    }

    function bestSnap(points, lines, thr) {
        var best = null;
        points.forEach(function (pt) {
            lines.forEach(function (ln) {
                var dd = ln - pt;
                if (Math.abs(dd) <= thr && (!best || Math.abs(dd) < Math.abs(best.delta))) { best = { delta: dd, line: ln }; }
            });
        });
        return best;
    }
    function gridSnap(v) { return S.grid ? Math.round(v / 5) * 5 : v; }

    function showGuides(gx, gy) {
        var s = S.scale;
        R.guideV.style.display = gx == null ? 'none' : 'block';
        R.guideH.style.display = gy == null ? 'none' : 'block';
        if (gx != null) { R.guideV.style.left = (gx * s) + 'px'; }
        if (gy != null) { R.guideH.style.top = (gy * s) + 'px'; }
    }

    function onPointerMove(e) {
        if (!drag) { return; }
        var p = pointerMm(e), dx = p.x - drag.start.x, dy = p.y - drag.start.y;
        if (!drag.moved && Math.abs(dx) * S.scale < 3 && Math.abs(dy) * S.scale < 3) { return; }
        drag.moved = true;

        if (drag.kind === 'marquee') { return moveMarquee(p); }

        var ids = Object.keys(drag.origs), thr = SNAP_PX / S.scale, snap = !e.altKey;
        var lines = snap ? snapLines(ids) : null, gx = null, gy = null;

        if (drag.kind === 'move') {
            var b = drag.box, nx = b.x + dx, ny = b.y + dy;
            if (snap) {
                var sx = bestSnap([nx, nx + b.w / 2, nx + b.w], lines.xs, thr);
                var sy = bestSnap([ny, ny + b.h / 2, ny + b.h], lines.ys, thr);
                if (sx) { nx += sx.delta; gx = sx.line; } else { nx = gridSnap(nx); }
                if (sy) { ny += sy.delta; gy = sy.line; } else { ny = gridSnap(ny); }
            }
            var ox = nx - b.x, oy = ny - b.y;
            ids.forEach(function (id) {
                var m = getEl(id), o = drag.origs[id];
                m.x = round(o.x + ox, 2); m.y = round(o.y + oy, 2);
                var n = nodeOf(id); if (n) { styleNode(n, m); }
            });
            updateGroupBox();
        } else {
            var el = getEl(drag.id), o = drag.origs[drag.id], hd = drag.handle;
            var x = o.x, y = o.y, w = o.w, hh = o.h;
            var minW = 1, minH = el.type === 'line' ? 0.2 : 1;
            if (hd.indexOf('e') > -1) { w = Math.max(minW, o.w + dx); }
            if (hd.indexOf('s') > -1) { hh = Math.max(minH, o.h + dy); }
            if (hd.indexOf('w') > -1) { w = Math.max(minW, o.w - dx); x = o.x + o.w - w; }
            if (hd.indexOf('n') > -1) { hh = Math.max(minH, o.h - dy); y = o.y + o.h - hh; }
            var ratioType = el.type === 'qr' || el.type === 'image';
            var keepRatio = el.type === 'qr' || (ratioType && !e.shiftKey) || (!ratioType && e.shiftKey);
            if (keepRatio && hd.length === 2) {
                var ratio = o.w / o.h;
                if (w / hh > ratio) { w = hh * ratio; } else { hh = w / ratio; }
                if (hd.indexOf('w') > -1) { x = o.x + o.w - w; }
                if (hd.indexOf('n') > -1) { y = o.y + o.h - hh; }
            }
            if (snap && hd.length === 1) {
                if (hd === 'e') { var se = bestSnap([x + w], lines.xs, thr); if (se) { w += se.delta; gx = se.line; } }
                if (hd === 'w') { var sw = bestSnap([x], lines.xs, thr); if (sw) { x += sw.delta; w -= sw.delta; gx = sw.line; } }
                if (hd === 's') { var ss = bestSnap([y + hh], lines.ys, thr); if (ss) { hh += ss.delta; gy = ss.line; } }
                if (hd === 'n') { var sn = bestSnap([y], lines.ys, thr); if (sn) { y += sn.delta; hh -= sn.delta; gy = sn.line; } }
            }
            el.x = round(x, 2); el.y = round(y, 2); el.w = round(w, 2); el.h = round(hh, 2);
            fillNode(nodeOf(el.id), el);
        }
        showGuides(gx, gy);
        updateGeomInputs();
    }

    function moveMarquee(p) {
        var s = S.scale, a = drag.start;
        var x = Math.min(a.x, p.x), y = Math.min(a.y, p.y), w = Math.abs(p.x - a.x), hh = Math.abs(p.y - a.y);
        Object.assign(R.marquee.style, { display: 'block', left: (x * s) + 'px', top: (y * s) + 'px', width: (w * s) + 'px', height: (hh * s) + 'px' });
        drag.rect = { x: x, y: y, w: w, h: hh };
    }

    function onPointerUp() {
        R.stage.removeEventListener('pointermove', onPointerMove);
        R.stage.removeEventListener('pointerup', onPointerUp);
        R.stage.removeEventListener('pointercancel', onPointerUp);
        var d = drag;
        drag = null;
        if (!d) { return; }
        onElPointerDown.last = (d.kind === 'move' && !d.moved) ? { id: d.id, t: Date.now() } : null;
        showGuides(null, null);
        if (d.kind === 'marquee') {
            R.marquee.style.display = 'none';
            if (!d.moved || !d.rect) { if (!d.add) { select(null); } return; }
            // Fully enclosed only, so background frames are not picked up.
            var r = d.rect, hit = S.design.elements.filter(function (el) {
                return !el.hidden && !el.locked && el.x >= r.x && el.x + el.w <= r.x + r.w && el.y >= r.y && el.y + el.h <= r.y + r.h;
            }).map(function (el) { return el.id; });
            setSelection(d.add ? d.base.concat(hit.filter(function (id) { return d.base.indexOf(id) < 0; })) : hit);
            return;
        }
        if (d.moved) {
            Object.keys(d.origs).forEach(function (id) { var el = getEl(id); if (el) { refreshEl(el); } });
            commit();
            renderProps();
        }
    }

    /* ------------------------------------------------------------------
     * Inline text editing
     * ---------------------------------------------------------------- */

    function onElDblClick(e) {
        var id = e.currentTarget.getAttribute('data-id'), el = getEl(id);
        if (!el || el.locked || S.editing === id) { return; }
        if (el.type === 'text') { startEditing(el); }
        else if (el.type === 'image') { pickMedia(function (att) { el.src = att.url; refreshEl(el); commit(); renderProps(); }); }
    }

    function startEditing(el) {
        if (S.selection.join() !== el.id) { S.selection = [el.id]; renderAll(); }
        S.editing = el.id;
        var node = nodeOf(el.id);
        fillNode(node, el);
        var inner = node.querySelector('.ncd-text');
        inner.setAttribute('contenteditable', 'true');
        inner.setAttribute('spellcheck', 'false');
        inner.addEventListener('pointerdown', function (ev) { ev.stopPropagation(); });
        inner.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' || ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter')) { ev.preventDefault(); finishEditing(); }
            ev.stopPropagation();
        });
        inner.addEventListener('input', function () {
            el.text = inner.innerText.replace(/\n$/, '');
            var ta = R.props.querySelector('textarea[data-key="text"]');
            if (ta) { ta.value = el.text; }
        });
        inner.addEventListener('blur', function () { finishEditing(); });
        inner.addEventListener('paste', function (ev) {
            // Plain text only.
            ev.preventDefault();
            var t = (ev.clipboardData || window.clipboardData).getData('text');
            document.execCommand('insertText', false, t);
        });
        inner.focus();
        var range = document.createRange();
        range.selectNodeContents(inner);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(range);
    }

    function finishEditing() {
        var id = S.editing;
        if (!id) { return; }
        S.editing = null;
        var el = getEl(id);
        if (el) { refreshEl(el); renderLayers(); commit(); }
    }

    /* ------------------------------------------------------------------
     * Context menu
     * ---------------------------------------------------------------- */

    function onElContext(e) {
        e.preventDefault(); e.stopPropagation();
        var id = e.currentTarget.getAttribute('data-id');
        if (!isSel(id)) { select(id); }
        openContext(e.clientX, e.clientY, true);
    }
    function onStageContext(e) {
        if (e.target.closest && e.target.closest('.ncd-el')) { return; }
        e.preventDefault();
        openContext(e.clientX, e.clientY, false);
    }
    function openContext(x, y, onEl) {
        closeMenus();
        var els = selEls(), items = [];
        var item = function (label, icon, fn, cls, kbd) {
            return h('button', { type: 'button', class: 'ncd-menu-item ' + (cls || ''), onclick: function () { closeMenus(); fn(); } }, [
                h('span', { html: (icon ? ic(icon) : '') + '<span>' + label + '</span>', class: 'ncd-ctx-l' }), kbd ? h('kbd', { text: kbd }) : null
            ]);
        };
        if (onEl && els.length) {
            var anyLocked = els.some(function (x) { return x.locked; });
            items.push(item('کپی', 'copy', copySel, '', 'Ctrl+C'));
            items.push(item('تکثیر', 'copy', duplicateSel, '', 'Ctrl+D'));
            if (clipboard()) { items.push(item('چسباندن', 'paste', paste, '', 'Ctrl+V')); }
            items.push(h('div', { class: 'ncd-menu-sep' }));
            items.push(item('آوردن به جلو', 'up', function () { orderSel('top'); }, '', 'Ctrl+]'));
            items.push(item('بردن به عقب', 'down', function () { orderSel('bottom'); }, '', 'Ctrl+['));
            items.push(item(anyLocked ? 'باز کردن قفل' : 'قفل کردن', anyLocked ? 'unlock' : 'lock', function () { els.forEach(function (x) { x.locked = !anyLocked; }); commit(); renderAll(); }));
            items.push(h('div', { class: 'ncd-menu-sep' }));
            items.push(item('حذف', 'trash', removeSel, 'ncd-danger', 'Delete'));
        } else {
            if (clipboard()) { items.push(item('چسباندن', 'paste', paste, '', 'Ctrl+V')); }
            items.push(item('انتخاب همه', null, selectAll, '', 'Ctrl+A'));
            items.push(item('افزودن متن', 'text', function () { addElement('text'); }));
        }
        R.ctx.innerHTML = '';
        items.forEach(function (n) { R.ctx.appendChild(n); });
        R.ctx.classList.add('open');
        var w = R.ctx.offsetWidth, hh = R.ctx.offsetHeight;
        R.ctx.style.left = clamp(x - w, 8, window.innerWidth - w - 8) + 'px';
        R.ctx.style.top = clamp(y, 8, window.innerHeight - hh - 8) + 'px';
    }

    /* ------------------------------------------------------------------
     * Keyboard
     * ---------------------------------------------------------------- */

    function onKey(e) {
        if (!S.design) { return; }
        var tag = (e.target && e.target.tagName) || '';
        var typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable);
        var mod = e.ctrlKey || e.metaKey;
        var key = (e.key || '').toLowerCase();

        if (mod && key === 's') { e.preventDefault(); save(); return; }
        if (typing) { return; }
        if (mod && key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
        if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) { e.preventDefault(); redo(); return; }
        if (mod && key === 'a') { e.preventDefault(); selectAll(); return; }
        if (mod && key === 'v') { e.preventDefault(); paste(); return; }
        if (mod && key === '0') { e.preventDefault(); S.zoom = 0; renderStage(); return; }
        if (key === 'escape') { closeMenus(); select(null); return; }

        var els = selEls();
        if (!els.length) { return; }
        if (mod && key === 'c') { e.preventDefault(); copySel(); return; }
        if (mod && key === 'x') { e.preventDefault(); copySel(); removeSel(); return; }
        if (mod && key === 'd') { e.preventDefault(); duplicateSel(); return; }
        if (mod && (key === ']' || key === '[')) { e.preventDefault(); orderSel(key === ']' ? (e.shiftKey ? 'top' : 1) : (e.shiftKey ? 'bottom' : -1)); return; }
        if (key === 'delete' || key === 'backspace') { e.preventDefault(); removeSel(); return; }
        if (key === 'enter' && els.length === 1 && els[0].type === 'text') { e.preventDefault(); startEditing(els[0]); return; }
        var step = e.shiftKey ? 10 : (e.altKey ? 0.1 : 1);
        var moves = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] };
        if (moves[key]) {
            e.preventDefault();
            els.forEach(function (el) {
                if (el.locked) { return; }
                el.x = round(el.x + moves[key][0], 2);
                el.y = round(el.y + moves[key][1], 2);
                refreshEl(el);
            });
            updateGeomInputs();
            clearTimeout(onKey.t);
            onKey.t = setTimeout(commit, 350);
        }
    }

    /* ------------------------------------------------------------------
     * Element operations
     * ---------------------------------------------------------------- */

    function defaults(type) {
        var d = pageDims(), base = { id: uid(), type: type, name: '', opacity: 1, locked: false, hidden: false };
        switch (type) {
            case 'text':
                return Object.assign(base, { x: d[0] / 2 - 60, y: d[1] / 2 - 6, w: 120, h: 12, text: 'متن جدید', fontSize: 16, bold: false, color: '#1f2a30', bg: '', align: 'center', dir: 'rtl', lineHeight: 1.4, fit: true });
            case 'image':
                return Object.assign(base, { x: d[0] / 2 - 20, y: d[1] / 2 - 20, w: 40, h: 40, src: '' });
            case 'qr':
                return Object.assign(base, { x: d[0] / 2 - 15, y: d[1] / 2 - 15, w: 30, h: 30, name: 'QR استعلام', content: 'verify', text: '', color: '#000000', bg: '#ffffff' });
            case 'barcode':
                return Object.assign(base, { x: d[0] / 2 - 42.5, y: d[1] / 2 - 8, w: 85, h: 16, name: 'بارکد', text: '{code}', color: '#000000', bg: '', showText: true });
            case 'rect':
                return Object.assign(base, { x: d[0] / 2 - 40, y: d[1] / 2 - 25, w: 80, h: 50, bg: '#eef2ff', borderColor: '#3858e9', borderWidth: 0.5, radius: 2 });
            case 'ellipse':
                return Object.assign(base, { x: d[0] / 2 - 20, y: d[1] / 2 - 20, w: 40, h: 40, bg: '#fde68a', borderColor: '#c9a84a', borderWidth: 0.6, radius: 0 });
            case 'line':
                return Object.assign(base, { x: d[0] / 2 - 40, y: d[1] / 2, w: 80, h: 0.5, bg: '#1f2a30', borderColor: '', borderWidth: 0, radius: 0 });
        }
        return base;
    }

    function addElement(type, extra) {
        if (!S.design) { return null; }
        var el = Object.assign(defaults(type), extra || {});
        S.design.elements.push(el);
        S.selection = [el.id];
        commit();
        renderAll();
        return el;
    }

    function addImage(att) {
        var d = pageDims(), w = 50, hh = 50;
        if (att.width && att.height) { hh = w * att.height / att.width; }
        if (hh > d[1] * 0.6) { hh = d[1] * 0.6; w = hh * att.width / att.height; }
        addElement('image', { src: att.url, w: round(w, 2), h: round(hh, 2), x: round(d[0] / 2 - w / 2, 2), y: round(d[1] / 2 - hh / 2, 2), name: att.title || 'تصویر' });
    }

    function insertCopies(list, offset) {
        var ids = [];
        list.forEach(function (src) {
            var c = clone(src);
            c.id = uid(); c.x = round(c.x + offset, 2); c.y = round(c.y + offset, 2); c.locked = false;
            S.design.elements.push(c);
            ids.push(c.id);
        });
        S.selection = ids;
        commit(); renderAll();
    }

    function duplicateSel() { var els = selEls(); if (els.length) { insertCopies(els, 5); } }

    function removeSel() {
        var ids = S.selection.slice();
        if (!ids.length) { return; }
        S.design.elements = S.design.elements.filter(function (x) { return ids.indexOf(x.id) < 0; });
        S.selection = [];
        commit(); renderAll();
    }

    function clipboard() { var c = load(CLIP_KEY); return c && Array.isArray(c.elements) && c.elements.length ? c : null; }
    function copySel() {
        var els = selEls();
        if (!els.length) { return; }
        store(CLIP_KEY, { elements: clone(els), from: S.currentId });
        S.pasteCount = 0;
        toast(fa(els.length) + ' المان کپی شد.', 'ok');
    }
    function paste() {
        var c = clipboard();
        if (!c || !S.design) { return; }
        S.pasteCount++;
        insertCopies(c.elements, c.from === S.currentId ? 5 * S.pasteCount : 0);
    }

    function orderSel(dir) {
        var arr = S.design.elements, els = selEls();
        if (!els.length) { return; }
        if (dir === 'top' || dir === 'bottom') {
            var rest = arr.filter(function (x) { return !isSel(x.id); });
            S.design.elements = dir === 'top' ? rest.concat(els) : els.concat(rest);
        } else {
            var list = dir > 0 ? els.slice().reverse() : els;
            list.forEach(function (el) {
                var i = arr.indexOf(el), j = clamp(i + dir, 0, arr.length - 1);
                if (i !== j && !isSel(arr[j].id)) { arr.splice(i, 1); arr.splice(j, 0, el); }
            });
        }
        commit(); renderAll();
    }

    function alignSel(mode) {
        var els = selEls().filter(function (x) { return !x.locked; });
        if (!els.length) { return; }
        var d = pageDims();
        var ref = selEls().length > 1 ? bbox(selEls()) : { x: 0, y: 0, w: d[0], h: d[1] };
        els.forEach(function (e) {
            if (mode === 'left') { e.x = ref.x; }
            if (mode === 'hcenter') { e.x = ref.x + (ref.w - e.w) / 2; }
            if (mode === 'right') { e.x = ref.x + ref.w - e.w; }
            if (mode === 'top') { e.y = ref.y; }
            if (mode === 'vcenter') { e.y = ref.y + (ref.h - e.h) / 2; }
            if (mode === 'bottom') { e.y = ref.y + ref.h - e.h; }
            e.x = round(e.x, 2); e.y = round(e.y, 2);
        });
        commit(); renderAll();
    }

    function distributeSel(axis) {
        var els = selEls().filter(function (x) { return !x.locked; });
        if (els.length < 3) { return; }
        var pos = axis === 'h' ? 'x' : 'y', size = axis === 'h' ? 'w' : 'h';
        els.sort(function (a, b) { return a[pos] - b[pos]; });
        var first = els[0], last = els[els.length - 1];
        var span = (last[pos] + last[size]) - first[pos];
        var used = els.reduce(function (s, e) { return s + e[size]; }, 0);
        var gap = (span - used) / (els.length - 1), cur = first[pos];
        els.forEach(function (e) { e[pos] = round(cur, 2); cur += e[size] + gap; });
        commit(); renderAll();
    }

    function pickMedia(cb) {
        if (!window.wp || !wp.media) { toast('کتابخانهٔ رسانه در دسترس نیست.', 'err'); return; }
        var frame = wp.media({ title: 'انتخاب تصویر', library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            if (/\.svg($|\?)/i.test(att.url || '')) { toast('SVG در PDF پشتیبانی نمی‌شود؛ PNG یا JPG انتخاب کنید.', 'err'); return; }
            cb(att);
        });
        frame.open();
    }

    /* ------------------------------------------------------------------
     * Layers panel (drag to reorder)
     * ---------------------------------------------------------------- */

    var TYPE_LABEL = { text: 'متن', image: 'تصویر', qr: 'QR', barcode: 'بارکد', rect: 'کادر', ellipse: 'دایره', line: 'خط' };

    function elLabel(el) {
        if (el.name) { return el.name; }
        if (el.type === 'text') { return (el.text || '').split('\n')[0].slice(0, 28) || 'متن'; }
        return TYPE_LABEL[el.type];
    }

    var layerDrag = null;
    function renderLayers() {
        R.layers.innerHTML = '';
        if (!S.design || !S.design.elements.length) {
            R.layers.appendChild(h('div', { class: 'ncd-layers-empty', text: 'هنوز المانی اضافه نشده است.' }));
            return;
        }
        S.design.elements.slice().reverse().forEach(function (el) {
            var row = h('div', {
                class: 'ncd-layer' + (isSel(el.id) ? ' active' : '') + (el.hidden ? ' is-hidden' : ''),
                draggable: 'true',
                onclick: function (e) { if (e.shiftKey || e.ctrlKey || e.metaKey) { toggleSelect(el.id); } else { select(el.id); } },
                ondragstart: function (e) { layerDrag = el.id; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', el.id); } catch (err) { /* IE */ } row.classList.add('dragging'); },
                ondragend: function () { layerDrag = null; row.classList.remove('dragging'); R.layers.querySelectorAll('.drop-before,.drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); }); },
                ondragover: function (e) {
                    if (!layerDrag || layerDrag === el.id) { return; }
                    e.preventDefault();
                    var r = row.getBoundingClientRect(), before = e.clientY < r.top + r.height / 2;
                    row.classList.toggle('drop-before', before); row.classList.toggle('drop-after', !before);
                },
                ondragleave: function () { row.classList.remove('drop-before', 'drop-after'); },
                ondrop: function (e) {
                    e.preventDefault();
                    var before = row.classList.contains('drop-before');
                    row.classList.remove('drop-before', 'drop-after');
                    moveLayerTo(layerDrag, el.id, before);
                }
            }, [
                h('span', { class: 'ncd-layer-grip', html: '⋮⋮' }),
                h('span', { class: 'ncd-layer-ic', html: ICON[el.type === 'text' && /\{/.test(el.text) ? 'token' : el.type] }),
                h('span', { class: 'ncd-layer-name', text: elLabel(el) }),
                h('button', { type: 'button', class: 'ncd-mini', title: el.hidden ? 'نمایش' : 'مخفی', html: ICON[el.hidden ? 'eyeOff' : 'eye'], onclick: function (e) { e.stopPropagation(); el.hidden = !el.hidden; commit(); renderAll(); } }),
                h('button', { type: 'button', class: 'ncd-mini', title: el.locked ? 'باز کردن قفل' : 'قفل', html: ICON[el.locked ? 'lock' : 'unlock'], onclick: function (e) { e.stopPropagation(); el.locked = !el.locked; commit(); renderAll(); } })
            ]);
            R.layers.appendChild(row);
        });
    }

    // The list shows the top layer first: "before" a row = above it = later in the array.
    function moveLayerTo(dragId, targetId, before) {
        var arr = S.design.elements, el = getEl(dragId);
        if (!el || dragId === targetId) { return; }
        arr.splice(arr.indexOf(el), 1);
        var j = arr.indexOf(getEl(targetId));
        arr.splice(before ? j + 1 : j, 0, el);
        commit(); renderAll();
    }

    /* ------------------------------------------------------------------
     * Properties panel
     * ---------------------------------------------------------------- */

    function field(label, control, cls) {
        return h('label', { class: 'ncd-field ' + (cls || '') }, [h('span', { class: 'ncd-flabel', text: label }), control]);
    }

    // Live-apply on input, commit to history on change.
    function bindNum(el, key, opts) {
        opts = opts || {};
        var input = h('input', { type: 'number', class: 'ncd-input', step: opts.step || '0.5', min: opts.min, max: opts.max, value: round(el[key], 2) });
        input.addEventListener('input', function () {
            var v = parseFloat(this.value);
            if (isNaN(v)) { return; }
            if (opts.min != null) { v = Math.max(opts.min, v); }
            if (opts.max != null) { v = Math.min(opts.max, v); }
            el[key] = v;
            refreshEl(el);
        });
        input.addEventListener('change', commit);
        input.setAttribute('data-key', key);
        return input;
    }

    function colorControl(el, key, allowEmpty, emptyLabel) {
        var wrap = h('div', { class: 'ncd-color' });
        var input = h('input', { type: 'color', value: el[key] || '#ffffff' });
        var hex = h('input', { type: 'text', class: 'ncd-input ncd-hex', dir: 'ltr', maxlength: '7', value: el[key] || '' , placeholder: allowEmpty ? (emptyLabel || 'بدون') : '#000000' });
        var none = allowEmpty ? h('button', { type: 'button', class: 'ncd-mini ncd-none' + (el[key] ? '' : ' on'), title: emptyLabel || 'بدون رنگ', text: '∅' }) : null;
        var apply = function () { if (el === S.design.page) { renderStage(); } else { refreshEl(el); } };
        input.addEventListener('input', function () { el[key] = this.value; hex.value = this.value; if (none) { none.classList.remove('on'); } apply(); });
        input.addEventListener('change', commit);
        hex.addEventListener('change', function () {
            var v = this.value.trim();
            if (/^#?[0-9a-f]{6}$/i.test(v)) { v = v[0] === '#' ? v : '#' + v; el[key] = v.toLowerCase(); input.value = el[key]; if (none) { none.classList.remove('on'); } }
            else if (v === '' && allowEmpty) { el[key] = ''; if (none) { none.classList.add('on'); } }
            else { this.value = el[key] || ''; return; }
            apply(); commit();
        });
        if (none) {
            none.addEventListener('click', function () { el[key] = ''; hex.value = ''; none.classList.add('on'); apply(); commit(); });
        }
        wrap.appendChild(input);
        wrap.appendChild(hex);
        if (none) { wrap.appendChild(none); }
        return wrap;
    }

    function tokenSelect(onPick) {
        var tokSel = h('select', { class: 'ncd-select' }, [h('option', { value: '', text: '+ درج فیلد پویا…' })]);
        Object.keys(BOOT.tokens).forEach(function (k) { tokSel.appendChild(h('option', { value: k, text: BOOT.tokens[k] })); });
        tokSel.addEventListener('change', function () { if (this.value) { onPick('{' + this.value + '}'); this.value = ''; } });
        return tokSel;
    }
    function insertAtCursor(input, tok) {
        var st = input.selectionStart == null ? input.value.length : input.selectionStart, en = input.selectionEnd == null ? st : input.selectionEnd;
        input.value = input.value.slice(0, st) + tok + input.value.slice(en);
    }

    function updateGeomInputs() {
        var el = sel();
        if (!el) { return; }
        ['x', 'y', 'w', 'h'].forEach(function (k) {
            var i = R.props.querySelector('input[data-key="' + k + '"]');
            if (i && i !== document.activeElement) { i.value = round(el[k], 2); }
        });
    }

    function alignBar() {
        var multi = selEls().length > 1;
        var b = function (mode, icon, title) { return h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: title, html: ic(icon), onclick: function () { alignSel(mode); } }); };
        var row = h('div', { class: 'ncd-actions' }, [
            b('right', 'oRight', 'تراز راست'), b('hcenter', 'oHCenter', 'وسط افقی'), b('left', 'oLeft', 'تراز چپ'),
            b('top', 'oTop', 'تراز بالا'), b('vcenter', 'oVCenter', 'وسط عمودی'), b('bottom', 'oBottom', 'تراز پایین')
        ]);
        if (selEls().length > 2) {
            row.appendChild(h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'توزیع یکنواخت افقی', html: ic('distH'), onclick: function () { distributeSel('h'); } }));
            row.appendChild(h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'توزیع یکنواخت عمودی', html: ic('distV'), onclick: function () { distributeSel('v'); } }));
        }
        return [h('div', { class: 'ncd-panel-sub', text: multi ? 'تراز نسبت به هم' : 'تراز نسبت به صفحه' }), row];
    }

    function actionsBar(els) {
        var anyLocked = els.some(function (x) { return x.locked; });
        return h('div', { class: 'ncd-actions' }, [
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'بالاترین لایه (Ctrl+Shift+])', html: ic('up') + ic('up'), onclick: function () { orderSel('top'); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'یک لایه بالاتر (Ctrl+])', html: ic('up'), onclick: function () { orderSel(1); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'یک لایه پایین‌تر (Ctrl+[)', html: ic('down'), onclick: function () { orderSel(-1); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'پایین‌ترین لایه (Ctrl+Shift+[)', html: ic('down') + ic('down'), onclick: function () { orderSel('bottom'); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'تکثیر (Ctrl+D)', html: ic('copy'), onclick: duplicateSel }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: anyLocked ? 'باز کردن قفل' : 'قفل', html: ic(anyLocked ? 'lock' : 'unlock'), onclick: function () { els.forEach(function (x) { x.locked = !anyLocked; }); commit(); renderAll(); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon ncd-danger', title: 'حذف (Delete)', html: ic('trash'), onclick: removeSel })
        ]);
    }

    function renderProps() {
        R.props.innerHTML = '';
        if (!S.design) { return; }
        var els = selEls();
        if (!els.length) { renderPageProps(); return; }
        if (els.length > 1) { renderMultiProps(els); return; }
        var el = els[0];

        R.props.appendChild(h('div', { class: 'ncd-panel-title', text: 'ویژگی‌های ' + TYPE_LABEL[el.type] }));
        var nameInput = h('input', { type: 'text', class: 'ncd-input', value: el.name || '', placeholder: elLabel(el) });
        nameInput.addEventListener('input', function () { el.name = this.value; renderLayers(); });
        nameInput.addEventListener('change', commit);
        R.props.appendChild(field('نام لایه', nameInput));

        if (el.type === 'text') {
            var ta = h('textarea', { class: 'ncd-input', rows: '3', dir: el.dir === 'ltr' ? 'ltr' : 'rtl', 'data-key': 'text' });
            ta.value = el.text || '';
            ta.addEventListener('input', function () { el.text = this.value; refreshEl(el); renderLayers(); });
            ta.addEventListener('change', commit);
            R.props.appendChild(field('متن (یا دوبار کلیک روی بوم)', ta));
            R.props.appendChild(tokenSelect(function (tok) { insertAtCursor(ta, tok); el.text = ta.value; refreshEl(el); renderLayers(); commit(); }));

            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('اندازه (pt)', bindNum(el, 'fontSize', { min: 4, max: 200, step: '1' })),
                field('فاصله خطوط', bindNum(el, 'lineHeight', { min: 0.8, max: 3, step: '0.1' }))
            ]));
            R.props.appendChild(field('رنگ متن', colorControl(el, 'color', false)));

            var aligns = h('div', { class: 'ncd-seg' });
            [['right', 'alignR', 'راست'], ['center', 'alignC', 'وسط'], ['left', 'alignL', 'چپ'], ['justify', 'alignJ', 'تراز']].forEach(function (a) {
                aligns.appendChild(h('button', { type: 'button', title: a[2], class: el.align === a[0] ? 'on' : '', html: ICON[a[1]], onclick: function () { el.align = a[0]; refreshEl(el); commit(); renderProps(); } }));
            });
            var boldBtn = h('button', { type: 'button', class: 'ncd-btn ncd-bold' + (el.bold ? ' on' : ''), text: 'B', title: 'ضخیم', onclick: function () { el.bold = !el.bold; refreshEl(el); commit(); renderProps(); } });
            var dirSeg = h('div', { class: 'ncd-seg ncd-seg-text' });
            [['rtl', 'فارسی'], ['ltr', 'English']].forEach(function (o) {
                dirSeg.appendChild(h('button', { type: 'button', title: 'جهت نوشتار', class: (el.dir || 'rtl') === o[0] ? 'on' : '', text: o[1], onclick: function () { el.dir = o[0]; refreshEl(el); commit(); renderProps(); } }));
            });
            R.props.appendChild(h('div', { class: 'ncd-row ncd-row-tight' }, [aligns, boldBtn, dirSeg]));

            var fit = h('input', { type: 'checkbox', checked: el.fit !== false });
            fit.addEventListener('change', function () { el.fit = this.checked; refreshEl(el); commit(); });
            R.props.appendChild(h('label', { class: 'ncd-check' }, [fit, h('span', { text: 'کوچک کردن خودکار متن برای جا شدن در کادر (برای نام‌های طولانی)' })]));
            R.props.appendChild(field('رنگ پس‌زمینه', colorControl(el, 'bg', true)));
        }

        if (el.type === 'image') {
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                h('button', { type: 'button', class: 'ncd-btn', html: ic('image') + '<span>تعویض تصویر</span>', onclick: function () {
                    pickMedia(function (att) { el.src = att.url; if (att.width && att.height) { el.h = round(el.w * att.height / att.width, 2); } refreshEl(el); commit(); renderProps(); });
                } }),
                h('button', { type: 'button', class: 'ncd-btn', text: 'نسبت اصلی', onclick: function () {
                    var img = new Image();
                    img.onload = function () { el.h = round(el.w * img.naturalHeight / img.naturalWidth, 2); refreshEl(el); commit(); renderProps(); };
                    img.src = el.src;
                } })
            ]));
            R.props.appendChild(h('div', { class: 'ncd-note', text: 'برای بهترین نتیجه از PNG (با پس‌زمینهٔ شفاف برای امضا و مهر) یا JPG استفاده کنید؛ SVG در PDF پشتیبانی نمی‌شود.' }));
        }

        if (el.type === 'qr') {
            var content = h('div', { class: 'ncd-seg ncd-seg-text' });
            [['verify', 'لینک استعلام مدرک'], ['custom', 'متن دلخواه']].forEach(function (o) {
                content.appendChild(h('button', { type: 'button', class: (el.content || 'verify') === o[0] ? 'on' : '', text: o[1], onclick: function () { el.content = o[0]; commit(); renderProps(); } }));
            });
            R.props.appendChild(field('محتوای QR', content));
            if (el.content === 'custom') {
                var qt = h('textarea', { class: 'ncd-input', rows: '2', dir: 'ltr', placeholder: 'https://… یا {code}' });
                qt.value = el.text || '';
                qt.addEventListener('input', function () { el.text = this.value; });
                qt.addEventListener('change', commit);
                R.props.appendChild(qt);
                R.props.appendChild(tokenSelect(function (tok) { insertAtCursor(qt, tok); el.text = qt.value; commit(); }));
            }
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('رنگ', colorControl(el, 'color', false)),
                field('پس‌زمینه', colorControl(el, 'bg', true, 'شفاف'))
            ]));
            var side = Math.min(el.w, el.h);
            R.props.appendChild(h('div', { class: 'ncd-note' + (side < 20 ? ' warn' : ''), text: (side < 20 ? '⚠ ' : '') + 'در مدرک نهایی، QR به لینک استعلام همان مدرک اشاره می‌کند. برای اسکن مطمئن حداقل ۲۰ میلی‌متر باشد و رنگ آن تیره روی زمینهٔ روشن بماند.' }));
        }

        if (el.type === 'barcode') {
            var bt = h('input', { type: 'text', class: 'ncd-input', dir: 'ltr', value: el.text || '{code}' });
            bt.addEventListener('input', function () { el.text = this.value; refreshEl(el); });
            bt.addEventListener('change', commit);
            R.props.appendChild(field('مقدار بارکد', bt));
            R.props.appendChild(tokenSelect(function (tok) { insertAtCursor(bt, tok); el.text = bt.value; refreshEl(el); commit(); }));
            var show = h('input', { type: 'checkbox', checked: !!el.showText });
            show.addEventListener('change', function () { el.showText = this.checked; refreshEl(el); commit(); });
            R.props.appendChild(h('label', { class: 'ncd-check' }, [show, h('span', { text: 'نمایش متن کد زیر بارکد' })]));
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('رنگ خطوط', colorControl(el, 'color', false)),
                field('پس‌زمینه', colorControl(el, 'bg', true, 'شفاف'))
            ]));
            var enc = barcodeAscii(sampleValue(el.text || '{code}'));
            var mod = enc ? el.w / code128Widths(enc).reduce(function (a, b) { return a + b; }, 0) : 0;
            var warn = !enc ? 'مقدار بارکد فقط می‌تواند حروف لاتین، عدد و علائم باشد.' : (mod < 0.25 ? '⚠ عرض بارکد برای این تعداد کاراکتر کم است (پهنای هر خط ' + fa(round(mod, 2)) + ' میلی‌متر)؛ برای اسکن مطمئن عرض را بیشتر کنید.' : '');
            R.props.appendChild(h('div', { class: 'ncd-note' + (warn ? ' warn' : ''), text: warn || 'بارکد استاندارد Code128 است و با هر بارکدخوان و دوربین موبایل خوانده می‌شود. فاصلهٔ سفید دو طرف بخشی از بارکد است؛ آن را حذف نکنید.' }));
        }

        if (el.type === 'rect' || el.type === 'line' || el.type === 'ellipse') {
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('رنگ زمینه', colorControl(el, 'bg', true)),
                field('رنگ حاشیه', colorControl(el, 'borderColor', true))
            ]));
            var geo = [field('ضخامت حاشیه (mm)', bindNum(el, 'borderWidth', { min: 0, max: 20, step: '0.1' }))];
            if (el.type === 'rect') { geo.push(field('گردی گوشه (mm)', bindNum(el, 'radius', { min: 0, max: 100, step: '0.5' }))); }
            R.props.appendChild(h('div', { class: 'ncd-row' }, geo));
        }

        // Geometry.
        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'موقعیت و اندازه (میلی‌متر)' }));
        R.props.appendChild(h('div', { class: 'ncd-row ncd-row-4' }, [
            field('X', bindNum(el, 'x', { step: '0.5' })),
            field('Y', bindNum(el, 'y', { step: '0.5' })),
            field('عرض', bindNum(el, 'w', { min: 0.2, step: '0.5' })),
            field('ارتفاع', bindNum(el, 'h', { min: 0.2, step: '0.5' }))
        ]));
        alignBar().forEach(function (n) { R.props.appendChild(n); });

        // Opacity.
        var op = h('input', { type: 'range', min: '0', max: '1', step: '0.05', value: el.opacity == null ? 1 : el.opacity });
        var opv = h('span', { class: 'ncd-range-v', text: fa(Math.round((el.opacity == null ? 1 : el.opacity) * 100)) + '٪' });
        op.addEventListener('input', function () { el.opacity = parseFloat(this.value); opv.textContent = fa(Math.round(el.opacity * 100)) + '٪'; refreshEl(el); });
        op.addEventListener('change', commit);
        R.props.appendChild(field('شفافیت', h('div', { class: 'ncd-range' }, [op, opv])));

        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'ترتیب و عملیات' }));
        R.props.appendChild(actionsBar([el]));
    }

    function renderMultiProps(els) {
        R.props.appendChild(h('div', { class: 'ncd-panel-title', text: fa(els.length) + ' المان انتخاب شده' }));
        alignBar().forEach(function (n) { R.props.appendChild(n); });
        var b = bbox(els);
        R.props.appendChild(h('div', { class: 'ncd-note', text: 'محدودهٔ انتخاب: ' + fa(round(b.w)) + ' × ' + fa(round(b.h)) + ' میلی‌متر. با کشیدن یا کلیدهای جهت، همه با هم جابه‌جا می‌شوند.' }));

        // Shared colour for texts.
        var texts = els.filter(function (x) { return x.type === 'text'; });
        if (texts.length) {
            var proxy = { color: texts[0].color };
            var c = colorControl(proxy, 'color', false);
            c.querySelectorAll('input').forEach(function (inp) {
                inp.addEventListener('input', function () { texts.forEach(function (t) { t.color = proxy.color; refreshEl(t); }); });
                inp.addEventListener('change', function () { texts.forEach(function (t) { t.color = proxy.color; refreshEl(t); }); commit(); });
            });
            R.props.appendChild(field('رنگ همهٔ متن‌ها', c));
        }
        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'ترتیب و عملیات' }));
        R.props.appendChild(actionsBar(els));
    }

    function renderPageProps() {
        var p = S.design.page;
        R.props.appendChild(h('div', { class: 'ncd-panel-title', text: 'تنظیمات صفحه' }));

        var size = h('select', { class: 'ncd-select' });
        Object.keys(BOOT.pageSizes).forEach(function (k) { size.appendChild(h('option', { value: k, text: k, selected: p.size === k })); });
        size.addEventListener('change', function () { p.size = this.value; commit(); renderStage(); renderProps(); });

        var orient = h('div', { class: 'ncd-seg ncd-seg-text' });
        [['L', 'افقی'], ['P', 'عمودی']].forEach(function (o) {
            orient.appendChild(h('button', { type: 'button', class: p.orientation === o[0] ? 'on' : '', text: o[1], onclick: function () { p.orientation = o[0]; commit(); renderStage(); renderProps(); } }));
        });
        R.props.appendChild(h('div', { class: 'ncd-row' }, [field('اندازه کاغذ', size), field('جهت', orient)]));
        R.props.appendChild(field('رنگ پس‌زمینه', colorControl(p, 'bgColor', false)));

        var bgRow = h('div', { class: 'ncd-bgimg' });
        if (p.bgImage) { bgRow.appendChild(h('img', { src: p.bgImage, alt: '' })); }
        bgRow.appendChild(h('button', { type: 'button', class: 'ncd-btn', html: ic('image') + '<span>' + (p.bgImage ? 'تعویض' : 'انتخاب') + ' تصویر پس‌زمینه</span>', onclick: function () {
            pickMedia(function (att) { p.bgImage = att.url; commit(); renderStage(); renderProps(); });
        } }));
        if (p.bgImage) {
            bgRow.appendChild(h('button', { type: 'button', class: 'ncd-btn ncd-danger', text: 'حذف', onclick: function () { p.bgImage = ''; commit(); renderStage(); renderProps(); } }));
        }
        R.props.appendChild(field('تصویر پس‌زمینه (تمام صفحه)', bgRow));
        var d = pageDims();
        R.props.appendChild(h('div', { class: 'ncd-note', text: 'ابعاد صفحه: ' + fa(round(d[0])) + ' × ' + fa(round(d[1])) + ' میلی‌متر. برای تصویر پس‌زمینه از تصویری با همین نسبت و حداقل ۳۰۰۰ پیکسل عرض استفاده کنید (چاپ ۳۰۰dpi).' }));

        // Health check of the design.
        var issues = designIssues();
        if (issues.length) {
            R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'بررسی طرح' }));
            issues.forEach(function (t) { R.props.appendChild(h('div', { class: 'ncd-note warn', text: '⚠ ' + t })); });
        }

        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'فیلدهای پویا' }));
        var list = h('div', { class: 'ncd-tokens' });
        Object.keys(BOOT.tokens).forEach(function (k) {
            list.appendChild(h('div', { class: 'ncd-token' }, [h('code', { text: '{' + k + '}' }), h('span', { text: BOOT.tokens[k] })]));
        });
        R.props.appendChild(list);
        R.props.appendChild(h('div', { class: 'ncd-note', text: 'این کدها را داخل هر متنی بنویسید؛ هنگام صدور مدرک با اطلاعات واقعی دانشجو جایگزین می‌شوند.' }));
    }

    /** Things that make a certificate unprofessional or unverifiable. */
    function designIssues() {
        var els = S.design.elements.filter(function (e) { return !e.hidden; }), d = pageDims(), out = [];
        var has = function (re) { return els.some(function (e) { return e.type === 'text' && re.test(e.text || ''); }); };
        if (!has(/\{student_name\}|\{first_name\}/)) { out.push('نام دانشجو روی مدرک نیست.'); }
        if (!has(/\{course_name\}/)) { out.push('نام دوره روی مدرک نیست.'); }
        var verifiable = has(/\{code\}|\{verify_url\}/) || els.some(function (e) { return (e.type === 'qr' && e.content !== 'custom') || e.type === 'barcode'; });
        if (!verifiable) { out.push('هیچ راه استعلامی (QR، بارکد یا کد مدرک) روی مدرک نیست.'); }
        var outside = els.filter(function (e) { return e.x < -0.5 || e.y < -0.5 || e.x + e.w > d[0] + 0.5 || e.y + e.h > d[1] + 0.5; });
        if (outside.length) { out.push(fa(outside.length) + ' المان از کادر صفحه بیرون زده است.'); }
        var small = els.filter(function (e) { return e.type === 'qr' && Math.min(e.w, e.h) < 15; });
        if (small.length) { out.push('QR کوچک‌تر از ۱۵ میلی‌متر ممکن است اسکن نشود.'); }
        return out;
    }

    /* ------------------------------------------------------------------
     * Templates (server)
     * ---------------------------------------------------------------- */

    function loadTemplate(t) {
        S.currentId = t ? t.id : 0;
        S.title = t ? t.title : '';
        S.design = t ? clone(t.design) : null;
        S.selection = [];
        S.editing = null;
        S.zoom = 0;
        if (S.design) { resetHistory(); markSaved(); }
        refreshChrome();
        renderAll();
        checkDraft();
    }

    function checkDraft() {
        R.draftBar.style.display = 'none';
        if (!S.currentId) { return; }
        var dr = load(DRAFT_KEY + S.currentId);
        if (!dr || !dr.design || JSON.stringify({ t: dr.title, d: dr.design }) === S.savedJson) { return; }
        var when = new Date(dr.at);
        R.draftBar.innerHTML = '';
        R.draftBar.appendChild(h('span', { text: 'نسخهٔ ذخیره‌نشده‌ای از این قالب (' + fa(when.toLocaleTimeString('fa-IR')) + ') روی این مرورگر پیدا شد.' }));
        R.draftBar.appendChild(h('div', { class: 'ncd-top-group' }, [
            h('button', { type: 'button', class: 'ncd-btn ncd-primary', text: 'بازیابی', onclick: function () {
                S.design = dr.design; S.title = dr.title || S.title; commit(); renderAll();
                R.draftBar.style.display = 'none'; toast('نسخهٔ پیش‌نویس بازیابی شد؛ برای ماندگاری ذخیره کنید.', 'ok');
            } }),
            h('button', { type: 'button', class: 'ncd-btn', text: 'نادیده گرفتن', onclick: function () { clearDraft(); R.draftBar.style.display = 'none'; } })
        ]));
        R.draftBar.style.display = '';
    }

    function confirmDiscard() { return !isDirty() || window.confirm('تغییرات ذخیره نشده از بین می‌رود. ادامه می‌دهید؟'); }

    function switchTemplate(id) {
        if (id === S.currentId) { return; }
        if (!confirmDiscard()) { refreshChrome(); return; }
        loadTemplate(findTpl(id));
    }

    function createTemplate(preset, extra) {
        if (!confirmDiscard()) { return; }
        post('nias_cert_tpl_create', Object.assign({ preset: preset }, extra || {})).then(function (data) {
            S.templates.push(data.template);
            S.defaultId = data.defaultTemplate || S.defaultId;
            loadTemplate(data.template);
            toast(extra ? 'قالب درون‌ریزی شد.' : 'قالب جدید ساخته شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
    }

    function duplicateTemplate() {
        if (!S.currentId || !confirmDiscard()) { return; }
        post('nias_cert_tpl_duplicate', { id: S.currentId }).then(function (data) {
            S.templates.push(data.template);
            loadTemplate(data.template);
            toast('قالب تکثیر شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
    }

    function deleteTemplate() {
        if (!S.currentId || !window.confirm('این قالب برای همیشه حذف شود؟ دوره‌هایی که از آن استفاده می‌کنند به قالب پیش‌فرض برمی‌گردند.')) { return; }
        var id = S.currentId;
        post('nias_cert_tpl_delete', { id: id }).then(function (data) {
            clearDraft();
            S.templates = S.templates.filter(function (t) { return t.id !== id; });
            S.defaultId = data.defaultTemplate || 0;
            markSaved(); // skip the discard prompt
            loadTemplate(S.templates[0] || null);
            toast('قالب حذف شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
    }

    function exportTemplate() {
        if (!S.design) { return; }
        var payload = { format: 'nias-certificate-template', version: 1, title: S.title, design: S.design };
        var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        var a = h('a', { href: URL.createObjectURL(blob), download: (S.title || 'certificate-template').replace(/[\\/:*?"<>|]+/g, '-') + '.json' });
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        toast('فایل قالب دانلود شد. تصاویر با آدرس اینترنتی ذخیره شده‌اند.', 'ok');
    }

    function importTemplate() { R.file.value = ''; R.file.click(); }
    function onImportFile() {
        var f = R.file.files && R.file.files[0];
        if (!f) { return; }
        var reader = new FileReader();
        reader.onload = function () {
            var data;
            try { data = JSON.parse(reader.result); } catch (e) { toast('فایل JSON معتبر نیست.', 'err'); return; }
            var design = data && data.design ? data.design : data;
            if (!design || !Array.isArray(design.elements)) { toast('این فایل قالب مدرک نیست.', 'err'); return; }
            createTemplate('blank', { design: JSON.stringify(design), title: (data.title || f.name.replace(/\.json$/i, '')) });
        };
        reader.readAsText(f);
    }

    function setDefault() {
        if (!S.currentId) { return; }
        post('nias_cert_tpl_default', { id: S.currentId }).then(function (data) {
            S.defaultId = data.defaultTemplate;
            refreshChrome();
            toast('به‌عنوان قالب پیش‌فرض تنظیم شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
    }

    function setMode(mode) {
        post('nias_cert_set_mode', { mode: mode }).then(function (data) {
            S.mode = data.mode;
            refreshChrome();
            toast(data.mode === 'designer' ? 'طراح پیشرفته فعال شد.' : 'حالت کلاسیک فعال شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
    }

    function save() {
        if (!S.design || S.saving) { return; }
        if (S.editing) { finishEditing(); }
        S.saving = true; refreshChrome();
        post('nias_cert_tpl_save', { id: S.currentId, title: S.title, design: JSON.stringify(S.design) }).then(function (data) {
            var t = findTpl(S.currentId);
            if (t) { t.title = data.template.title; t.design = data.template.design; }
            S.title = data.template.title;
            markSaved();
            clearDraft();
            R.draftBar.style.display = 'none';
            toast(data.message || 'ذخیره شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); })
          .then(function () { S.saving = false; refreshChrome(); });
    }

    function previewPdf() {
        if (!S.design) { return; }
        var form = h('form', { method: 'post', action: BOOT.previewUrl, target: '_blank', style: { display: 'none' } });
        var input = h('input', { type: 'hidden', name: 'design' });
        input.value = JSON.stringify(S.design);
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        setTimeout(function () { form.remove(); }, 1000);
    }

    /* ------------------------------------------------------------------
     * Boot
     * ---------------------------------------------------------------- */

    buildShell();
    var first = findTpl(S.defaultId) || S.templates[0] || null;
    loadTemplate(first);
    // Fonts load async; re-measure text once they are ready.
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(function () { if (S.design) { renderStage(); } }); }
})();
