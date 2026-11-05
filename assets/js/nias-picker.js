/*!
 * NiasPicker — a small searchable picker for large lists (quizzes, courses,
 * users …). It never loads the whole list: it shows the first few matches and
 * asks the server again as the user types (debounced, previous request
 * aborted). Single or multiple selection; keyboard friendly; RTL.
 *
 *   NiasPicker.attach(host, {
 *     source: 'quiz' | 'product' | 'user',
 *     multiple: false,
 *     value: [{ id, label, sub }],      // current selection
 *     name: 'field_name',               // optional: keeps <input type=hidden> in sync (name[] when multiple)
 *     placeholder: 'جستجو…',
 *     emptyLabel: 'بدون انتخاب',        // single mode: label of the "clear" state
 *     params: { exclude: '1,2' },       // extra request params
 *     onChange: function (values) {}
 *   });
 *
 * Needs window.NIAS_PICK = { url, nonce } (printed by nias_picker_enqueue()).
 */
(function () {
    'use strict';
    if (window.NiasPicker) { return; }

    var CSS = '' +
        '.npk{position:relative;font-family:inherit;direction:rtl;text-align:right}' +
        '.npk-box{display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-height:42px;padding:5px 8px;border:1px solid #dfe5ec;border-radius:11px;background:#fff;cursor:text;box-sizing:border-box}' +
        '.npk.open .npk-box,.npk-box:focus-within{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}' +
        '.npk-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;background:#eef4ff;color:#1e4fb8;border-radius:8px;padding:4px 6px 4px 9px;font-size:13px;font-weight:600}' +
        '.npk-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
        '.npk-chip button{border:0;background:transparent;color:inherit;cursor:pointer;font-size:15px;line-height:1;padding:0 2px;opacity:.7}' +
        '.npk-chip button:hover{opacity:1}' +
        '.npk-single{flex:1;min-width:0;font-size:14px;color:#1f2a30;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
        '.npk-single.none{color:#94a3b8}' +
        '.npk-clear{border:0;background:#f1f4f7;color:#64748b;border-radius:7px;width:24px;height:24px;cursor:pointer;flex:none;font-size:15px;line-height:1}' +
        '.npk-in{flex:1;min-width:120px;border:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;font:inherit;font-size:14px;padding:4px 2px!important;height:auto!important;min-height:0!important;margin:0!important;color:#1f2a30}' +
        '.npk-dd{position:absolute;z-index:100050;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e3e8ee;border-radius:12px;box-shadow:0 14px 34px rgba(15,23,42,.14);max-height:300px;overflow:auto;padding:5px;display:none}' +
        '.npk.open .npk-dd{display:block}' +
        '.npk-opt{display:flex;flex-direction:column;gap:2px;padding:8px 10px;border-radius:9px;cursor:pointer}' +
        '.npk-opt b{font-size:13.5px;font-weight:600;color:#1f2a30}' +
        '.npk-opt small{font-size:12px;color:#8a97a3}' +
        '.npk-opt.act,.npk-opt:hover{background:#f1f6ff}' +
        '.npk-opt.sel b{color:#1e6fd0}' +
        '.npk-opt.sel b:after{content:" ✓"}' +
        '.npk-msg{padding:10px;font-size:12.5px;color:#8a97a3;text-align:center}' +
        '.npk-more{padding:8px 10px;font-size:12px;color:#8a97a3;border-top:1px dashed #e7ebef;margin-top:4px}' +
        '.npk:not(.multi) .npk-in{display:none}' +
        '.npk.open:not(.multi) .npk-in{display:block}' +
        '.npk.open:not(.multi) .npk-single,.npk.open:not(.multi) .npk-clear{display:none}';

    function injectCss() {
        if (document.getElementById('npk-css')) { return; }
        var s = document.createElement('style');
        s.id = 'npk-css';
        s.textContent = CSS;
        document.head.appendChild(s);
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

    function Picker(host, o) {
        this.host = host;
        this.o = o || {};
        this.values = (this.o.value || []).filter(function (v) { return v && v.id; });
        this.items = [];
        this.act = -1;
        this.req = null;
        this.t = null;
        this.cache = {};
        this.build();
    }
    Picker.prototype.build = function () {
        var self = this;
        injectCss();
        this.host.classList.add('npk');
        if (this.o.multiple) { this.host.classList.add('multi'); }
        this.host.innerHTML = '<div class="npk-box"></div><div class="npk-dd" role="listbox"></div><span class="npk-hidden"></span>';
        this.box = this.host.querySelector('.npk-box');
        this.dd = this.host.querySelector('.npk-dd');
        this.hid = this.host.querySelector('.npk-hidden');
        this.renderBox();
        this.box.addEventListener('click', function (e) {
            if (e.target.closest('button')) { return; }
            self.focus();
        });
        document.addEventListener('mousedown', function (e) { if (!self.host.contains(e.target)) { self.close(); } });
    };
    Picker.prototype.renderBox = function () {
        var self = this, o = this.o, h = '';
        if (o.multiple) {
            h = this.values.map(function (v, i) { return '<span class="npk-chip"><span title="' + esc(v.label) + '">' + esc(v.label) + '</span><button type="button" data-rm="' + i + '" aria-label="حذف">×</button></span>'; }).join('');
            h += '<input class="npk-in" type="text" autocomplete="off" placeholder="' + esc(o.placeholder || 'جستجو…') + '">';
        } else {
            var v = this.values[0];
            h = '<span class="npk-single' + (v ? '' : ' none') + '">' + esc(v ? v.label : (o.emptyLabel || 'انتخاب کنید…')) + '</span>' +
                (v && o.clearable !== false ? '<button type="button" class="npk-clear" data-clear="1" aria-label="پاک کردن">×</button>' : '') +
                '<input class="npk-in" type="text" autocomplete="off" placeholder="' + esc(o.placeholder || 'جستجو…') + '">';
        }
        this.box.innerHTML = h;
        this.input = this.box.querySelector('.npk-in');
        // hidden inputs for plain <form> posts
        if (o.name) {
            this.hid.innerHTML = o.multiple
                ? this.values.map(function (v) { return '<input type="hidden" name="' + esc(o.name) + '[]" value="' + esc(v.id) + '">'; }).join('')
                : '<input type="hidden" name="' + esc(o.name) + '" value="' + esc(this.values[0] ? this.values[0].id : '') + '">';
        }
        this.box.querySelectorAll('[data-rm]').forEach(function (b) {
            b.addEventListener('click', function () { self.values.splice(+b.getAttribute('data-rm'), 1); self.changed(); });
        });
        var cl = this.box.querySelector('[data-clear]');
        if (cl) { cl.addEventListener('click', function () { self.values = []; self.changed(); }); }
        this.input.addEventListener('focus', function () { self.open(); });
        this.input.addEventListener('input', function () { self.search(self.input.value); });
        this.input.addEventListener('keydown', function (e) { self.key(e); });
    };
    Picker.prototype.changed = function () {
        this.renderBox();
        if (this.o.onChange) { this.o.onChange(this.values.slice()); }
        if (this.o.multiple) { this.input.focus(); }
    };
    Picker.prototype.focus = function () { this.host.classList.add('open'); this.input.focus(); };
    Picker.prototype.open = function () {
        this.host.classList.add('open');
        if (!this.loaded) { this.search(this.input.value); }
    };
    Picker.prototype.close = function () {
        this.host.classList.remove('open');
        if (this.input && !this.o.multiple) { this.input.value = ''; }
    };
    Picker.prototype.search = function (term) {
        var self = this;
        term = String(term || '').trim();
        this.host.classList.add('open');
        clearTimeout(this.t);
        if (this.cache[term]) { this.show(this.cache[term], term); return; }
        this.dd.innerHTML = '<div class="npk-msg">در حال جستجو…</div>';
        this.t = setTimeout(function () { self.fetch(term); }, term ? 250 : 0);
    };
    Picker.prototype.fetch = function (term) {
        var self = this, cfg = window.NIAS_PICK || {};
        if (!cfg.url) { this.dd.innerHTML = '<div class="npk-msg">پیکربندی جستجو یافت نشد.</div>'; return; }
        if (this.req && this.req.abort) { this.req.abort(); }
        var ctrl = window.AbortController ? new AbortController() : null;
        this.req = ctrl;
        var body = new URLSearchParams();
        body.append('action', 'nias_pick');
        body.append('nonce', cfg.nonce);
        body.append('source', this.o.source);
        body.append('q', term);
        var p = this.o.params || {};
        Object.keys(p).forEach(function (k) { body.append(k, p[k]); });
        fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body, signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                self.loaded = true;
                var data = (res && res.success && res.data) ? res.data : { items: [], more: false, error: (res && res.data && res.data.message) || 'خطا در جستجو' };
                self.cache[term] = data;
                if (self.input.value.trim() === term) { self.show(data, term); }
            })
            .catch(function (e) { if (!e || e.name !== 'AbortError') { self.dd.innerHTML = '<div class="npk-msg">اتصال برقرار نشد.</div>'; } });
    };
    Picker.prototype.isSel = function (id) { return this.values.some(function (v) { return String(v.id) === String(id); }); };
    Picker.prototype.show = function (data, term) {
        var self = this;
        this.items = data.items || [];
        this.act = this.items.length ? 0 : -1;
        if (data.error) { this.dd.innerHTML = '<div class="npk-msg">' + esc(data.error) + '</div>'; return; }
        if (!this.items.length) { this.dd.innerHTML = '<div class="npk-msg">' + (term ? 'موردی با «' + esc(term) + '» پیدا نشد.' : 'موردی وجود ندارد.') + '</div>'; return; }
        this.dd.innerHTML = this.items.map(function (it, i) {
            return '<div class="npk-opt' + (i === 0 ? ' act' : '') + (self.isSel(it.id) ? ' sel' : '') + '" role="option" data-i="' + i + '"><b>' + esc(it.label) + '</b>' + (it.sub ? '<small>' + esc(it.sub) + '</small>' : '') + '</div>';
        }).join('') + (data.more ? '<div class="npk-more">نتایج بیشتری هست؛ برای یافتن دقیق‌تر، جستجو را کامل‌تر بنویسید.</div>' : '');
        this.dd.querySelectorAll('.npk-opt').forEach(function (el) {
            el.addEventListener('mousedown', function (e) { e.preventDefault(); self.pick(+el.getAttribute('data-i')); });
        });
    };
    Picker.prototype.pick = function (i) {
        var it = this.items[i];
        if (!it) { return; }
        if (this.o.multiple) {
            if (this.isSel(it.id)) { this.values = this.values.filter(function (v) { return String(v.id) !== String(it.id); }); }
            else { this.values.push(it); }
            this.input.value = '';
            this.changed();
            this.search('');
        } else {
            this.values = [it];
            this.close();
            this.changed();
            this.input.blur();
        }
    };
    Picker.prototype.key = function (e) {
        var opts = this.dd.querySelectorAll('.npk-opt');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!opts.length) { return; }
            this.act = (this.act + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
            opts.forEach(function (o, i) { o.classList.toggle('act', i === this.act); }, this);
            opts[this.act].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (this.act > -1) { this.pick(this.act); }
        } else if (e.key === 'Escape') {
            this.close();
            this.input.blur();
        } else if (e.key === 'Backspace' && this.o.multiple && !this.input.value && this.values.length) {
            this.values.pop();
            this.changed();
        }
    };
    Picker.prototype.set = function (values) { this.values = (values || []).slice(); this.renderBox(); };
    Picker.prototype.get = function () { return this.values.slice(); };

    window.NiasPicker = {
        attach: function (host, o) { return host ? new Picker(host, o) : null; }
    };
})();
