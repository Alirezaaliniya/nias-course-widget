/**
 * Nias certificate canvas designer (طراح مدرک).
 *
 * A dependency-free editor for certificate templates. The design is a JSON
 * model in millimetres (see inc/certificate/designer.php); the canvas renders
 * it at `scale` px/mm so what you see matches the mPDF output.
 */
(function () {
    'use strict';

    var BOOT = window.NIAS_CERT_DESIGNER;
    var root = document.getElementById('nias-cert-designer');
    if (!BOOT || !root) { return; }

    var PT_TO_MM = 0.352778;
    var SNAP_PX = 6;
    var HISTORY_MAX = 80;

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
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function round(n, d) { var p = Math.pow(10, d == null ? 1 : d); return Math.round(n * p) / p; }
    function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
    function uid() { return 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
    function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

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

    var ICON = {
        text: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></svg>',
        token: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 2-2 3 1 1 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 2 2 3-1 1-2 1.5-2 3v2c0 2-1 3-3 3"/></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
        qr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>',
        rect: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/></svg>',
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
        up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>',
        down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
        star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z"/></svg>',
        save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>',
        pdf: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z"/><path d="M14 3v6h6"/></svg>',
        alignR: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M10 12h10M6 18h14"/></svg>',
        alignC: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 12h10M5 18h14"/></svg>',
        alignL: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h10M4 18h14"/></svg>',
        alignJ: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
        centerH: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 3v18"/><rect x="6" y="7" width="12" height="10" rx="1.5"/></svg>',
        centerV: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 12h18"/><rect x="7" y="6" width="10" height="12" rx="1.5"/></svg>'
    };
    function ic(name) { return '<span class="ncd-ic">' + ICON[name] + '</span>'; }

    // A static QR-looking pattern for the canvas (the PDF gets a real code).
    var QR_SVG = (function () {
        var n = 25, cells = '', seed = 7;
        function rnd() { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; }
        function finder(x, y) {
            return '<rect x="' + x + '" y="' + y + '" width="7" height="7" fill="#111"/><rect x="' + (x + 1) + '" y="' + (y + 1) + '" width="5" height="5" fill="#fff"/><rect x="' + (x + 2) + '" y="' + (y + 2) + '" width="3" height="3" fill="#111"/>';
        }
        for (var y = 0; y < n; y++) {
            for (var x = 0; x < n; x++) {
                var inF = (x < 8 && y < 8) || (x > n - 9 && y < 8) || (x < 8 && y > n - 9);
                if (!inF && rnd() > 0.52) { cells += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>'; }
            }
        }
        return '<svg viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" preserveAspectRatio="none" width="100%" height="100%"><rect width="' + n + '" height="' + n + '" fill="#fff"/><g fill="#111">' + cells + '</g>' + finder(0, 0) + finder(n - 7, 0) + finder(0, n - 7) + '</svg>';
    })();

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
        selected: null,
        history: [],
        hIndex: -1,
        zoom: 0,          // 0 = fit
        scale: 3,
        grid: false,
        sample: true,
        saving: false
    };

    function findTpl(id) { return S.templates.filter(function (t) { return t.id === id; })[0] || null; }
    function pageDims() {
        var p = S.design.page, sz = BOOT.pageSizes[p.size] || BOOT.pageSizes.A4;
        return p.orientation === 'P' ? [sz[0], sz[1]] : [sz[1], sz[0]];
    }
    function getEl(id) { return S.design ? S.design.elements.filter(function (e) { return e.id === id; })[0] || null : null; }
    function sel() { return getEl(S.selected); }
    function isDirty() { return S.design && JSON.stringify({ t: S.title, d: S.design }) !== S.savedJson; }
    function markSaved() { S.savedJson = JSON.stringify({ t: S.title, d: S.design }); }

    /* ------------------------------------------------------------------
     * History
     * ---------------------------------------------------------------- */

    function resetHistory() { S.history = [JSON.stringify(S.design)]; S.hIndex = 0; }
    function commit() {
        var json = JSON.stringify(S.design);
        if (json === S.history[S.hIndex]) { refreshChrome(); return; }
        S.history = S.history.slice(0, S.hIndex + 1);
        S.history.push(json);
        if (S.history.length > HISTORY_MAX) { S.history.shift(); }
        S.hIndex = S.history.length - 1;
        refreshChrome();
    }
    function undo() {
        if (S.hIndex <= 0) { return; }
        S.hIndex--; S.design = JSON.parse(S.history[S.hIndex]);
        if (!sel()) { S.selected = null; }
        renderAll();
    }
    function redo() {
        if (S.hIndex >= S.history.length - 1) { return; }
        S.hIndex++; S.design = JSON.parse(S.history[S.hIndex]);
        if (!sel()) { S.selected = null; }
        renderAll();
    }

    /* ------------------------------------------------------------------
     * Layout skeleton
     * ---------------------------------------------------------------- */

    var R = {}; // references to chrome nodes

    function buildShell() {
        root.innerHTML = '';
        R.toast = h('div', { class: 'ncd-toast' });

        // Top bar.
        R.tplSelect = h('select', { class: 'ncd-select', onchange: function () { switchTemplate(parseInt(this.value, 10)); } });
        R.title = h('input', { class: 'ncd-input ncd-title', type: 'text', placeholder: 'نام قالب', oninput: function () { S.title = this.value; refreshChrome(); } });
        R.btnNew = h('button', { type: 'button', class: 'ncd-btn', html: ic('plus') + '<span>قالب جدید</span>', onclick: function (e) { e.stopPropagation(); togglePresetMenu(); } });
        R.presetMenu = h('div', { class: 'ncd-menu' });
        Object.keys(BOOT.presets).forEach(function (k) {
            R.presetMenu.appendChild(h('button', { type: 'button', class: 'ncd-menu-item', text: BOOT.presets[k], onclick: function () { closeMenus(); createTemplate(k); } }));
        });
        R.btnDup = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'تکثیر قالب', html: ic('copy'), onclick: duplicateTemplate });
        R.btnDel = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon ncd-danger', title: 'حذف قالب', html: ic('trash'), onclick: deleteTemplate });
        R.btnDefault = h('button', { type: 'button', class: 'ncd-btn', html: ic('star') + '<span>پیش‌فرض</span>', onclick: setDefault });
        R.btnUndo = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'واگرد (Ctrl+Z)', html: ic('undo'), onclick: undo });
        R.btnRedo = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'انجام دوباره (Ctrl+Y)', html: ic('redo'), onclick: redo });
        R.zoomLabel = h('span', { class: 'ncd-zoom-label' });
        R.btnGrid = h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'شبکه', html: ic('grid'), onclick: function () { S.grid = !S.grid; renderStage(); refreshChrome(); } });
        R.btnSample = h('button', { type: 'button', class: 'ncd-btn', html: '<span>دادهٔ نمونه</span>', title: 'نمایش فیلدهای پویا با دادهٔ نمونه یا به‌صورت {کد}', onclick: function () { S.sample = !S.sample; renderStage(); refreshChrome(); } });
        R.btnPreview = h('button', { type: 'button', class: 'ncd-btn', html: ic('pdf') + '<span>پیش‌نمایش PDF</span>', onclick: previewPdf });
        R.btnSave = h('button', { type: 'button', class: 'ncd-btn ncd-primary', html: ic('save') + '<span>ذخیره</span>', onclick: save });

        var top = h('div', { class: 'ncd-top' }, [
            h('div', { class: 'ncd-top-group' }, [R.tplSelect, R.title, h('div', { class: 'ncd-menu-wrap' }, [R.btnNew, R.presetMenu]), R.btnDup, R.btnDel, R.btnDefault]),
            h('div', { class: 'ncd-top-group' }, [
                R.btnUndo, R.btnRedo, h('span', { class: 'ncd-sep' }),
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'کوچک‌نمایی', html: ic('minus'), onclick: function () { zoomBy(-0.5); } }),
                R.zoomLabel,
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'بزرگ‌نمایی', html: ic('plus'), onclick: function () { zoomBy(0.5); } }),
                h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'اندازهٔ مناسب', html: ic('fit'), onclick: function () { S.zoom = 0; renderStage(); } }),
                R.btnGrid, R.btnSample, h('span', { class: 'ncd-sep' }), R.btnPreview, R.btnSave
            ])
        ]);

        R.modeBar = h('div', { class: 'ncd-modebar' });

        // Tools (add elements + layers).
        R.tokenMenu = h('div', { class: 'ncd-menu ncd-menu-tokens' });
        Object.keys(BOOT.tokens).forEach(function (k) {
            R.tokenMenu.appendChild(h('button', { type: 'button', class: 'ncd-menu-item', onclick: function () { closeMenus(); addElement('text', { text: '{' + k + '}', name: BOOT.tokens[k], bold: k === 'student_name' || k === 'course_name', fontSize: k === 'student_name' ? 26 : (k === 'course_name' ? 18 : 12) }); } }, [
                h('span', { text: BOOT.tokens[k] }), h('code', { text: '{' + k + '}' })
            ]));
        });
        var addBtn = function (icon, label, fn) { return h('button', { type: 'button', class: 'ncd-tool', html: ic(icon) + '<span>' + label + '</span>', onclick: fn }); };
        var tokenBtn = addBtn('token', 'فیلد پویا', function (e) { e.stopPropagation(); toggleMenu(R.tokenMenu); });

        R.layers = h('div', { class: 'ncd-layers' });
        var tools = h('aside', { class: 'ncd-tools' }, [
            h('div', { class: 'ncd-panel-title', text: 'افزودن المان' }),
            h('div', { class: 'ncd-toolgrid' }, [
                addBtn('text', 'متن', function () { addElement('text'); }),
                h('div', { class: 'ncd-menu-wrap' }, [tokenBtn, R.tokenMenu]),
                addBtn('image', 'تصویر', function () { pickMedia(function (att) { addImage(att); }); }),
                addBtn('qr', 'QR استعلام', function () { addElement('qr'); }),
                addBtn('rect', 'کادر', function () { addElement('rect'); }),
                addBtn('line', 'خط', function () { addElement('line'); })
            ]),
            h('div', { class: 'ncd-panel-title', text: 'لایه‌ها' }),
            R.layers,
            h('div', { class: 'ncd-hint', html: 'کلیدهای میانبر: جهت‌ها (جابه‌جایی ۱ میلی‌متر، با Shift ده میلی‌متر)، Delete، Ctrl+D تکثیر، Ctrl+Z / Ctrl+Y، Ctrl+S ذخیره. نگه داشتن Alt هنگام کشیدن، چسبندگی را غیرفعال می‌کند.' })
        ]);

        // Stage.
        R.page = h('div', { class: 'ncd-page' });
        R.pageWrap = h('div', { class: 'ncd-page-wrap' }, [R.page]);
        R.stage = h('div', { class: 'ncd-stage', onpointerdown: function (e) { if (e.target === R.stage || e.target === R.pageWrap || e.target === R.page) { select(null); } } }, [R.pageWrap]);

        // Properties.
        R.props = h('aside', { class: 'ncd-props' });

        R.body = h('div', { class: 'ncd-body' }, [tools, h('div', { class: 'ncd-center' }, [R.stage]), R.props]);
        R.empty = h('div', { class: 'ncd-empty' });

        root.appendChild(top);
        root.appendChild(R.modeBar);
        root.appendChild(R.body);
        root.appendChild(R.empty);
        root.appendChild(R.toast);

        document.addEventListener('click', closeMenus);
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', function () { if (S.zoom === 0) { renderStage(); } });
        window.addEventListener('beforeunload', function (e) { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });
    }

    function toggleMenu(menu) { var open = menu.classList.contains('open'); closeMenus(); if (!open) { menu.classList.add('open'); } }
    function togglePresetMenu() { toggleMenu(R.presetMenu); }
    function closeMenus() { root.querySelectorAll('.ncd-menu.open').forEach(function (m) { m.classList.remove('open'); }); }

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
        [R.title, R.btnDup, R.btnDel, R.btnDefault, R.btnUndo, R.btnRedo, R.btnPreview, R.btnSave].forEach(function (b) { b.disabled = !has; });

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
        if (S.mode !== 'designer') {
            R.modeBar.className = 'ncd-modebar warn';
            R.modeBar.innerHTML = '';
            R.modeBar.appendChild(h('span', { text: 'حالت فعلی مدرک «کلاسیک» است؛ قالب‌های این صفحه فقط وقتی استفاده می‌شوند که حالت «طراح پیشرفته» فعال باشد.' }));
            R.modeBar.appendChild(h('button', { type: 'button', class: 'ncd-btn ncd-primary', text: 'فعال‌سازی طراح پیشرفته', onclick: function () { setMode('designer'); } }));
        } else {
            R.modeBar.className = 'ncd-modebar ok';
            R.modeBar.innerHTML = '';
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
    function zoomBy(delta) { S.zoom = clamp(round((S.zoom || S.scale) + delta, 2), 1, 10); renderStage(); }

    function displayText(t) {
        if (!S.sample) { return t; }
        return String(t).replace(/\{([a-z_]+)\}/g, function (m, k) { return BOOT.sample[k] != null ? BOOT.sample[k] : m; });
    }

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
        var s = S.scale, o = el.opacity == null ? 1 : el.opacity;
        node.className = 'ncd-el ncd-el-' + el.type + (el.id === S.selected ? ' selected' : '') + (el.locked ? ' locked' : '') + (el.hidden ? ' is-hidden' : '');
        node.innerHTML = '';
        node.style.background = '';
        node.style.border = '';
        node.style.borderRadius = '';
        node.style.opacity = '';
        styleNode(node, el);

        if (el.type === 'text') {
            var inner = h('div', { class: 'ncd-text' });
            inner.textContent = displayText(el.text || '');
            inner.style.textAlign = el.align || 'center';
            inner.style.color = hexToRgba(el.color || '#000000', o);
            inner.style.fontWeight = el.bold ? '700' : '400';
            inner.style.lineHeight = String(el.lineHeight || 1.4);
            if (el.bg) { node.style.background = hexToRgba(el.bg, o); }
            node.appendChild(inner);
            var px = (el.fontSize || 14) * PT_TO_MM * s;
            inner.style.fontSize = px + 'px';
            node.classList.toggle('fit', el.fit !== false);
            if (el.fit !== false) {
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
            node.appendChild(h('div', { class: 'ncd-qr', html: QR_SVG, style: { width: side + 'px', height: side + 'px' } }));
        } else {
            if (el.bg) { node.style.background = hexToRgba(el.bg, o); }
            if (el.borderWidth && el.borderColor) { node.style.border = Math.max(1, el.borderWidth * s) + 'px solid ' + hexToRgba(el.borderColor, o); }
            if (el.radius) { node.style.borderRadius = (el.radius * s) + 'px'; }
            if (!el.bg && !(el.borderWidth && el.borderColor)) { node.classList.add('ncd-empty-shape'); }
        }

        if (el.id === S.selected && !el.locked) {
            ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'].forEach(function (d) {
                if (el.type === 'line' && (d === 'n' || d === 's' || d.length === 2) && el.h < 2) { return; }
                node.appendChild(h('span', { class: 'ncd-handle ncd-h-' + d, 'data-handle': d }));
            });
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
        R.page.style.setProperty('--grid', (10 * s) + 'px');
        R.page.innerHTML = '';
        S.design.elements.forEach(function (el) {
            var node = h('div', { 'data-id': el.id });
            fillNode(node, el);
            node.addEventListener('pointerdown', onElPointerDown);
            node.addEventListener('dblclick', function () {
                var ta = R.props.querySelector('textarea');
                if (ta) { ta.focus(); ta.select(); }
            });
            R.page.appendChild(node);
        });
        R.guideV = h('div', { class: 'ncd-guide v' });
        R.guideH = h('div', { class: 'ncd-guide h' });
        R.page.appendChild(R.guideV);
        R.page.appendChild(R.guideH);
        refreshChrome();
    }

    function nodeOf(id) { return R.page.querySelector('[data-id="' + id + '"]'); }
    function refreshEl(el) { var n = nodeOf(el.id); if (n) { fillNode(n, el); } }

    function renderAll() {
        renderStage();
        renderLayers();
        renderProps();
    }

    /* ------------------------------------------------------------------
     * Selection & pointer interaction
     * ---------------------------------------------------------------- */

    function select(id) {
        if (S.selected === id) { return; }
        S.selected = id;
        renderStage();
        renderLayers();
        renderProps();
    }

    var drag = null;

    function pointerMm(e) {
        var r = R.page.getBoundingClientRect();
        return { x: (e.clientX - r.left) / S.scale, y: (e.clientY - r.top) / S.scale };
    }

    function onElPointerDown(e) {
        if (e.button !== 0) { return; }
        var node = e.currentTarget, id = node.getAttribute('data-id');
        var el = getEl(id);
        if (!el) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (S.selected !== id) { select(id); node = nodeOf(id); }
        if (el.locked) { return; }
        var handle = e.target.getAttribute && e.target.getAttribute('data-handle');
        var p = pointerMm(e);
        drag = { id: id, handle: handle, start: p, orig: { x: el.x, y: el.y, w: el.w, h: el.h }, moved: false };
        try { node.setPointerCapture(e.pointerId); } catch (err) { /* synthetic/inactive pointer */ }
        node.addEventListener('pointermove', onPointerMove);
        node.addEventListener('pointerup', onPointerUp);
        node.addEventListener('pointercancel', onPointerUp);
    }

    function snapLines(exceptId) {
        var d = pageDims(), xs = [0, d[0] / 2, d[0]], ys = [0, d[1] / 2, d[1]];
        S.design.elements.forEach(function (o) {
            if (o.id === exceptId || o.hidden) { return; }
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

    function showGuides(gx, gy) {
        var s = S.scale;
        R.guideV.style.display = gx == null ? 'none' : 'block';
        R.guideH.style.display = gy == null ? 'none' : 'block';
        if (gx != null) { R.guideV.style.left = (gx * s) + 'px'; }
        if (gy != null) { R.guideH.style.top = (gy * s) + 'px'; }
    }

    function onPointerMove(e) {
        if (!drag) { return; }
        var el = getEl(drag.id); if (!el) { return; }
        var p = pointerMm(e), dx = p.x - drag.start.x, dy = p.y - drag.start.y;
        if (!drag.moved && Math.abs(dx) * S.scale < 2 && Math.abs(dy) * S.scale < 2) { return; }
        drag.moved = true;
        var o = drag.orig, thr = SNAP_PX / S.scale, snap = !e.altKey, lines = snap ? snapLines(el.id) : null;
        var gx = null, gy = null;

        if (!drag.handle) {
            var nx = o.x + dx, ny = o.y + dy;
            if (snap) {
                var sx = bestSnap([nx, nx + o.w / 2, nx + o.w], lines.xs, thr);
                var sy = bestSnap([ny, ny + o.h / 2, ny + o.h], lines.ys, thr);
                if (sx) { nx += sx.delta; gx = sx.line; }
                if (sy) { ny += sy.delta; gy = sy.line; }
            }
            el.x = round(nx, 2); el.y = round(ny, 2);
        } else {
            var hd = drag.handle, x = o.x, y = o.y, w = o.w, hh = o.h;
            var minW = 1, minH = el.type === 'line' ? 0.2 : 1;
            if (hd.indexOf('e') > -1) { w = Math.max(minW, o.w + dx); }
            if (hd.indexOf('s') > -1) { hh = Math.max(minH, o.h + dy); }
            if (hd.indexOf('w') > -1) { w = Math.max(minW, o.w - dx); x = o.x + o.w - w; }
            if (hd.indexOf('n') > -1) { hh = Math.max(minH, o.h - dy); y = o.y + o.h - hh; }
            var keepRatio = el.type === 'qr' || (el.type === 'image' && !e.shiftKey) || (el.type !== 'image' && e.shiftKey);
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
        }
        showGuides(gx, gy);
        var node = nodeOf(el.id);
        if (drag.handle || el.type === 'qr') { fillNode(node, el); } else { styleNode(node, el); }
        updateGeomInputs(el);
    }

    function onPointerUp(e) {
        var node = e.currentTarget;
        node.removeEventListener('pointermove', onPointerMove);
        node.removeEventListener('pointerup', onPointerUp);
        node.removeEventListener('pointercancel', onPointerUp);
        showGuides(null, null);
        if (drag && drag.moved) {
            var el = getEl(drag.id);
            if (el) { refreshEl(el); }
            commit();
        }
        drag = null;
    }

    /* ------------------------------------------------------------------
     * Keyboard
     * ---------------------------------------------------------------- */

    function onKey(e) {
        if (!S.design) { return; }
        var tag = (e.target && e.target.tagName) || '';
        var typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable);
        var mod = e.ctrlKey || e.metaKey;
        var key = e.key.toLowerCase();

        if (mod && key === 's') { e.preventDefault(); save(); return; }
        if (typing) { return; }
        if (mod && key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
        if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) { e.preventDefault(); redo(); return; }

        var el = sel();
        if (!el) { return; }
        if (mod && key === 'd') { e.preventDefault(); duplicateEl(el); return; }
        if (key === 'delete' || key === 'backspace') { e.preventDefault(); removeEl(el); return; }
        if (key === 'escape') { select(null); return; }
        var step = e.shiftKey ? 10 : (e.altKey ? 0.1 : 1);
        var moves = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] };
        if (moves[key] && !el.locked) {
            e.preventDefault();
            el.x = round(el.x + moves[key][0], 2);
            el.y = round(el.y + moves[key][1], 2);
            refreshEl(el); updateGeomInputs(el);
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
                return Object.assign(base, { x: d[0] / 2 - 60, y: d[1] / 2 - 6, w: 120, h: 12, text: 'متن جدید', fontSize: 16, bold: false, color: '#1f2a30', bg: '', align: 'center', lineHeight: 1.4, fit: true });
            case 'image':
                return Object.assign(base, { x: d[0] / 2 - 20, y: d[1] / 2 - 20, w: 40, h: 40, src: '' });
            case 'qr':
                return Object.assign(base, { x: d[0] / 2 - 15, y: d[1] / 2 - 15, w: 30, h: 30, name: 'QR استعلام' });
            case 'rect':
                return Object.assign(base, { x: d[0] / 2 - 40, y: d[1] / 2 - 25, w: 80, h: 50, bg: '#eef2ff', borderColor: '#3858e9', borderWidth: 0.5, radius: 2 });
            case 'line':
                return Object.assign(base, { x: d[0] / 2 - 40, y: d[1] / 2, w: 80, h: 0.5, bg: '#1f2a30', borderColor: '', borderWidth: 0, radius: 0 });
        }
        return base;
    }

    function addElement(type, extra) {
        var el = Object.assign(defaults(type), extra || {});
        S.design.elements.push(el);
        S.selected = el.id;
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

    function duplicateEl(el) {
        var c = clone(el);
        c.id = uid(); c.x = round(c.x + 5, 2); c.y = round(c.y + 5, 2); c.locked = false;
        var idx = S.design.elements.indexOf(el);
        S.design.elements.splice(idx + 1, 0, c);
        S.selected = c.id;
        commit(); renderAll();
    }

    function removeEl(el) {
        S.design.elements = S.design.elements.filter(function (x) { return x !== el; });
        S.selected = null;
        commit(); renderAll();
    }

    function moveLayer(el, dir) {
        var arr = S.design.elements, i = arr.indexOf(el), j;
        if (dir === 'top') { j = arr.length - 1; } else if (dir === 'bottom') { j = 0; } else { j = clamp(i + dir, 0, arr.length - 1); }
        if (i === j) { return; }
        arr.splice(i, 1); arr.splice(j, 0, el);
        commit(); renderAll();
    }

    function pickMedia(cb) {
        if (!window.wp || !wp.media) { toast('کتابخانهٔ رسانه در دسترس نیست.', 'err'); return; }
        var frame = wp.media({ title: 'انتخاب تصویر', library: { type: 'image' }, multiple: false });
        frame.on('select', function () { cb(frame.state().get('selection').first().toJSON()); });
        frame.open();
    }

    /* ------------------------------------------------------------------
     * Layers panel
     * ---------------------------------------------------------------- */

    var TYPE_LABEL = { text: 'متن', image: 'تصویر', qr: 'QR', rect: 'کادر', line: 'خط' };

    function elLabel(el) {
        if (el.name) { return el.name; }
        if (el.type === 'text') { return (el.text || '').split('\n')[0].slice(0, 28) || 'متن'; }
        return TYPE_LABEL[el.type];
    }

    function renderLayers() {
        R.layers.innerHTML = '';
        if (!S.design || !S.design.elements.length) {
            R.layers.appendChild(h('div', { class: 'ncd-layers-empty', text: 'هنوز المانی اضافه نشده است.' }));
            return;
        }
        S.design.elements.slice().reverse().forEach(function (el) {
            var row = h('div', { class: 'ncd-layer' + (el.id === S.selected ? ' active' : '') + (el.hidden ? ' is-hidden' : ''), onclick: function () { select(el.id); } }, [
                h('span', { class: 'ncd-layer-ic', html: ICON[el.type === 'text' && /\{/.test(el.text) ? 'token' : el.type] }),
                h('span', { class: 'ncd-layer-name', text: elLabel(el) }),
                h('button', { type: 'button', class: 'ncd-mini', title: el.hidden ? 'نمایش' : 'مخفی', html: ICON[el.hidden ? 'eyeOff' : 'eye'], onclick: function (e) { e.stopPropagation(); el.hidden = !el.hidden; commit(); renderAll(); } }),
                h('button', { type: 'button', class: 'ncd-mini', title: el.locked ? 'باز کردن قفل' : 'قفل', html: ICON[el.locked ? 'lock' : 'unlock'], onclick: function (e) { e.stopPropagation(); el.locked = !el.locked; commit(); renderAll(); } })
            ]);
            R.layers.appendChild(row);
        });
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
        var input = h('input', { type: 'number', class: 'ncd-input', step: opts.step || '0.5', min: opts.min, max: opts.max, value: el[key] });
        input.addEventListener('input', function () {
            var v = parseFloat(this.value);
            if (isNaN(v)) { return; }
            if (opts.min != null) { v = Math.max(opts.min, v); }
            if (opts.max != null) { v = Math.min(opts.max, v); }
            el[key] = v;
            if (opts.square) { el.w = el.h = v; }
            refreshEl(el);
        });
        input.addEventListener('change', commit);
        input.setAttribute('data-key', key);
        return input;
    }

    function colorControl(el, key, allowEmpty) {
        var wrap = h('div', { class: 'ncd-color' });
        var input = h('input', { type: 'color', value: el[key] || '#ffffff' });
        var none = allowEmpty ? h('button', { type: 'button', class: 'ncd-mini ncd-none' + (el[key] ? '' : ' on'), title: 'بدون رنگ', text: '∅' }) : null;
        input.addEventListener('input', function () { el[key] = this.value; if (none) { none.classList.remove('on'); } refreshEl(el); if (el === S.design.page) { renderStage(); } });
        input.addEventListener('change', commit);
        if (none) {
            none.addEventListener('click', function () { el[key] = ''; none.classList.add('on'); if (el === S.design.page) { renderStage(); } else { refreshEl(el); } commit(); });
        }
        wrap.appendChild(input);
        if (none) { wrap.appendChild(none); }
        return wrap;
    }

    function updateGeomInputs(el) {
        ['x', 'y', 'w', 'h'].forEach(function (k) {
            var i = R.props.querySelector('input[data-key="' + k + '"]');
            if (i && i !== document.activeElement) { i.value = round(el[k], 2); }
        });
    }

    function renderProps() {
        R.props.innerHTML = '';
        if (!S.design) { return; }
        var el = sel();
        if (!el) { renderPageProps(); return; }

        R.props.appendChild(h('div', { class: 'ncd-panel-title', text: 'ویژگی‌های ' + TYPE_LABEL[el.type] }));
        var nameInput = h('input', { type: 'text', class: 'ncd-input', value: el.name || '', placeholder: elLabel(el) });
        nameInput.addEventListener('input', function () { el.name = this.value; renderLayers(); });
        nameInput.addEventListener('change', commit);
        R.props.appendChild(field('نام لایه', nameInput));

        if (el.type === 'text') {
            var ta = h('textarea', { class: 'ncd-input', rows: '3', dir: 'rtl' });
            ta.value = el.text || '';
            ta.addEventListener('input', function () { el.text = this.value; refreshEl(el); renderLayers(); });
            ta.addEventListener('change', commit);
            R.props.appendChild(field('متن', ta));
            var tokSel = h('select', { class: 'ncd-select' }, [h('option', { value: '', text: '+ درج فیلد پویا…' })]);
            Object.keys(BOOT.tokens).forEach(function (k) { tokSel.appendChild(h('option', { value: k, text: BOOT.tokens[k] })); });
            tokSel.addEventListener('change', function () {
                if (!this.value) { return; }
                var tok = '{' + this.value + '}', st = ta.selectionStart || ta.value.length, en = ta.selectionEnd || st;
                ta.value = ta.value.slice(0, st) + tok + ta.value.slice(en);
                el.text = ta.value; this.value = '';
                refreshEl(el); renderLayers(); commit();
            });
            R.props.appendChild(tokSel);

            var row1 = h('div', { class: 'ncd-row' }, [
                field('اندازه (pt)', bindNum(el, 'fontSize', { min: 4, max: 200, step: '1' })),
                field('فاصله خطوط', bindNum(el, 'lineHeight', { min: 0.8, max: 3, step: '0.1' })),
                field('رنگ', colorControl(el, 'color', false))
            ]);
            R.props.appendChild(row1);

            var aligns = h('div', { class: 'ncd-seg' });
            [['right', 'alignR', 'راست'], ['center', 'alignC', 'وسط'], ['left', 'alignL', 'چپ'], ['justify', 'alignJ', 'تراز']].forEach(function (a) {
                aligns.appendChild(h('button', { type: 'button', title: a[2], class: el.align === a[0] ? 'on' : '', html: ICON[a[1]], onclick: function () { el.align = a[0]; refreshEl(el); commit(); renderProps(); } }));
            });
            var boldBtn = h('button', { type: 'button', class: 'ncd-btn ncd-bold' + (el.bold ? ' on' : ''), text: 'B', title: 'ضخیم', onclick: function () { el.bold = !el.bold; refreshEl(el); commit(); renderProps(); } });
            R.props.appendChild(h('div', { class: 'ncd-row ncd-row-tight' }, [aligns, boldBtn]));

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
            R.props.appendChild(h('div', { class: 'ncd-note', text: 'برای بهترین نتیجه از PNG یا JPG استفاده کنید (SVG در PDF پشتیبانی نمی‌شود).' }));
        }

        if (el.type === 'qr') {
            R.props.appendChild(h('div', { class: 'ncd-note', text: 'این QR در مدرک نهایی به لینک استعلام همان مدرک اشاره می‌کند. برای خوانایی حداقل ۲۰ میلی‌متر باشد.' }));
        }

        if (el.type === 'rect' || el.type === 'line') {
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('رنگ زمینه', colorControl(el, 'bg', true)),
                field('رنگ حاشیه', colorControl(el, 'borderColor', true))
            ]));
            R.props.appendChild(h('div', { class: 'ncd-row' }, [
                field('ضخامت حاشیه (mm)', bindNum(el, 'borderWidth', { min: 0, max: 20, step: '0.1' })),
                field('گردی گوشه (mm)', bindNum(el, 'radius', { min: 0, max: 100, step: '0.5' }))
            ]));
        }

        // Geometry.
        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'موقعیت و اندازه (میلی‌متر)' }));
        R.props.appendChild(h('div', { class: 'ncd-row ncd-row-4' }, [
            field('X', bindNum(el, 'x', { step: '0.5' })),
            field('Y', bindNum(el, 'y', { step: '0.5' })),
            field('عرض', bindNum(el, 'w', { min: 0.2, step: '0.5' })),
            field('ارتفاع', bindNum(el, 'h', { min: 0.2, step: '0.5' }))
        ]));
        var d = pageDims();
        R.props.appendChild(h('div', { class: 'ncd-row ncd-row-tight' }, [
            h('button', { type: 'button', class: 'ncd-btn', html: ic('centerH') + '<span>وسط افقی</span>', onclick: function () { el.x = round((d[0] - el.w) / 2, 2); refreshEl(el); updateGeomInputs(el); commit(); } }),
            h('button', { type: 'button', class: 'ncd-btn', html: ic('centerV') + '<span>وسط عمودی</span>', onclick: function () { el.y = round((d[1] - el.h) / 2, 2); refreshEl(el); updateGeomInputs(el); commit(); } })
        ]));

        // Opacity.
        var op = h('input', { type: 'range', min: '0', max: '1', step: '0.05', value: el.opacity == null ? 1 : el.opacity });
        var opv = h('span', { class: 'ncd-range-v', text: fa(Math.round((el.opacity == null ? 1 : el.opacity) * 100)) + '٪' });
        op.addEventListener('input', function () { el.opacity = parseFloat(this.value); opv.textContent = fa(Math.round(el.opacity * 100)) + '٪'; refreshEl(el); });
        op.addEventListener('change', commit);
        R.props.appendChild(field('شفافیت', h('div', { class: 'ncd-range' }, [op, opv])));

        // Layer actions.
        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'ترتیب و عملیات' }));
        R.props.appendChild(h('div', { class: 'ncd-actions' }, [
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'بالاترین لایه', html: ic('up') + ic('up'), onclick: function () { moveLayer(el, 'top'); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'یک لایه بالاتر', html: ic('up'), onclick: function () { moveLayer(el, 1); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'یک لایه پایین‌تر', html: ic('down'), onclick: function () { moveLayer(el, -1); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'پایین‌ترین لایه', html: ic('down') + ic('down'), onclick: function () { moveLayer(el, 'bottom'); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: 'تکثیر (Ctrl+D)', html: ic('copy'), onclick: function () { duplicateEl(el); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon', title: el.locked ? 'باز کردن قفل' : 'قفل', html: ic(el.locked ? 'lock' : 'unlock'), onclick: function () { el.locked = !el.locked; commit(); renderAll(); } }),
            h('button', { type: 'button', class: 'ncd-btn ncd-btn-icon ncd-danger', title: 'حذف (Delete)', html: ic('trash'), onclick: function () { removeEl(el); } })
        ]));
    }

    function renderPageProps() {
        var p = S.design.page;
        R.props.appendChild(h('div', { class: 'ncd-panel-title', text: 'تنظیمات صفحه' }));

        var size = h('select', { class: 'ncd-select' });
        Object.keys(BOOT.pageSizes).forEach(function (k) { size.appendChild(h('option', { value: k, text: k, selected: p.size === k })); });
        size.addEventListener('change', function () { p.size = this.value; commit(); renderStage(); });

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
        R.props.appendChild(h('div', { class: 'ncd-note', text: 'ابعاد صفحه: ' + fa(round(d[0])) + ' × ' + fa(round(d[1])) + ' میلی‌متر. برای تصویر پس‌زمینه از تصویری با همین نسبت و حداقل ۲۰۰۰ پیکسل عرض استفاده کنید.' }));

        R.props.appendChild(h('div', { class: 'ncd-panel-sub', text: 'فیلدهای پویا' }));
        var list = h('div', { class: 'ncd-tokens' });
        Object.keys(BOOT.tokens).forEach(function (k) {
            list.appendChild(h('div', { class: 'ncd-token' }, [h('code', { text: '{' + k + '}' }), h('span', { text: BOOT.tokens[k] })]));
        });
        R.props.appendChild(list);
        R.props.appendChild(h('div', { class: 'ncd-note', text: 'این کدها را داخل هر متنی بنویسید؛ هنگام صدور مدرک با اطلاعات واقعی دانشجو جایگزین می‌شوند.' }));
    }

    /* ------------------------------------------------------------------
     * Templates (server)
     * ---------------------------------------------------------------- */

    function loadTemplate(t) {
        S.currentId = t ? t.id : 0;
        S.title = t ? t.title : '';
        S.design = t ? clone(t.design) : null;
        S.selected = null;
        S.zoom = 0;
        if (S.design) { resetHistory(); markSaved(); }
        refreshChrome();
        renderAll();
    }

    function confirmDiscard() { return !isDirty() || window.confirm('تغییرات ذخیره نشده از بین می‌رود. ادامه می‌دهید؟'); }

    function switchTemplate(id) {
        if (id === S.currentId) { return; }
        if (!confirmDiscard()) { refreshChrome(); return; }
        loadTemplate(findTpl(id));
    }

    function createTemplate(preset) {
        if (!confirmDiscard()) { return; }
        post('nias_cert_tpl_create', { preset: preset }).then(function (data) {
            S.templates.push(data.template);
            S.defaultId = data.defaultTemplate || S.defaultId;
            loadTemplate(data.template);
            toast('قالب جدید ساخته شد.', 'ok');
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
            S.templates = S.templates.filter(function (t) { return t.id !== id; });
            S.defaultId = data.defaultTemplate || 0;
            S.savedJson = JSON.stringify({ t: S.title, d: S.design }); // skip the discard prompt
            loadTemplate(S.templates[0] || null);
            toast('قالب حذف شد.', 'ok');
        }).catch(function (err) { toast(err.message, 'err'); });
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
        S.saving = true; refreshChrome();
        post('nias_cert_tpl_save', { id: S.currentId, title: S.title, design: JSON.stringify(S.design) }).then(function (data) {
            var t = findTpl(S.currentId);
            if (t) { t.title = data.template.title; t.design = data.template.design; }
            S.title = data.template.title;
            markSaved();
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
