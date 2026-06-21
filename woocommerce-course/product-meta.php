<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom product meta feature ("متا").
 *
 * Enabled with the "فعالسازی متا" toggle on the main settings page. When on:
 *  - a "متا" settings tab appears with a repeater to define meta entries
 *    (each has a name + key, and exposes a copyable key and shortcode);
 *  - the defined meta fields show up in a box right under the curriculum
 *    editor grid, where a per-product value is entered for each (every field
 *    has copy-key and copy-shortcode buttons);
 *  - [nias_meta key="..."] outputs the stored value for a product.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Whether the custom meta feature is enabled. */
function nias_meta_enabled()
{
    return carbon_get_theme_option('nias_meta_enabled') === 'on';
}

/** Sanitize a user-entered key into a safe meta-key slug (latin, lowercase). */
function nias_meta_sanitize_key($key)
{
    return sanitize_key((string) $key);
}

/**
 * Defined meta entries.
 *
 * @return array<int,array{name:string,key:string}>
 */
function nias_meta_get_definitions()
{
    $defs = get_option('nias_meta_definitions', array());
    if (!is_array($defs)) {
        return array();
    }
    $out = array();
    foreach ($defs as $def) {
        if (!is_array($def)) {
            continue;
        }
        $key = isset($def['key']) ? nias_meta_sanitize_key($def['key']) : '';
        if ($key === '') {
            continue;
        }
        $name = isset($def['name']) ? (string) $def['name'] : '';
        $out[] = array('name' => $name !== '' ? $name : $key, 'key' => $key);
    }
    return $out;
}

/** All stored meta values for a product (key => value). */
function nias_meta_get_product_values($product_id)
{
    $vals = get_post_meta(intval($product_id), '_nias_meta_values', true);
    return is_array($vals) ? $vals : array();
}

/** A single stored meta value for a product. */
function nias_meta_get_product_value($product_id, $key)
{
    $vals = nias_meta_get_product_values($product_id);
    $key  = nias_meta_sanitize_key($key);
    return isset($vals[$key]) ? $vals[$key] : '';
}

/* -------------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------------- */

/** Persist the meta definitions from the settings-page POST. */
function nias_meta_save_definitions_from_post()
{
    $names = isset($_POST['nias_meta_name']) ? (array) wp_unslash($_POST['nias_meta_name']) : array();
    $keys  = isset($_POST['nias_meta_key']) ? (array) wp_unslash($_POST['nias_meta_key']) : array();

    $count = max(count($names), count($keys));
    $defs  = array();
    $seen  = array();

    for ($i = 0; $i < $count; $i++) {
        $name = isset($names[$i]) ? sanitize_text_field($names[$i]) : '';
        $key  = isset($keys[$i]) ? nias_meta_sanitize_key($keys[$i]) : '';

        if ($key === '' && $name === '') {
            continue;
        }
        if ($key === '') {
            $key = nias_meta_sanitize_key($name);
        }
        if ($key === '') {
            continue;
        }

        // Ensure keys are unique.
        $base = $key;
        $n    = 2;
        while (in_array($key, $seen, true)) {
            $key = $base . '-' . $n;
            $n++;
        }
        $seen[] = $key;

        $defs[] = array('name' => $name !== '' ? $name : $key, 'key' => $key);
    }

    update_option('nias_meta_definitions', $defs);
}

/**
 * Persist per-product meta values (called from the curriculum AJAX save).
 *
 * @param int          $product_id
 * @param string|array $raw JSON string (or array) of key => value
 */
function nias_meta_save_product_values($product_id, $raw)
{
    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = array();
    }

    $valid_keys = wp_list_pluck(nias_meta_get_definitions(), 'key');
    $clean      = array();

    foreach ($decoded as $key => $value) {
        $key = nias_meta_sanitize_key($key);
        if ($key === '' || !in_array($key, $valid_keys, true)) {
            continue;
        }
        $clean[$key] = wp_kses_post((string) $value);
    }

    update_post_meta(intval($product_id), '_nias_meta_values', $clean);
}

/* -------------------------------------------------------------------------
 * Shortcode
 * ---------------------------------------------------------------------- */

add_shortcode('nias_meta', 'nias_meta_shortcode');
function nias_meta_shortcode($atts)
{
    $atts = shortcode_atts(array('key' => '', 'id' => 0), $atts, 'nias_meta');

    $key = nias_meta_sanitize_key($atts['key']);
    if ($key === '') {
        return '';
    }

    $id = intval($atts['id']);
    if (!$id) {
        $id = get_the_ID();
    }
    if (!$id) {
        return '';
    }

    return wp_kses_post(nias_meta_get_product_value($id, $key));
}

/* -------------------------------------------------------------------------
 * Settings sub-page ("متا" tab)
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'nias_meta_register_settings_page', 11);
function nias_meta_register_settings_page()
{
    if (!current_user_can('manage_options') || !nias_meta_enabled()) {
        return;
    }
    add_submenu_page(
        'nias-course-settings',
        __('تنظیمات متا', 'nias-course-widget'),
        __('متا', 'nias-course-widget'),
        'manage_options',
        'nias-course-meta',
        'nias_course_render_meta_settings'
    );
}

function nias_course_render_meta_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = false;
    if (isset($_POST['nias_save_meta_settings']) && check_admin_referer('nias_meta_settings', 'nias_meta_nonce')) {
        nias_meta_save_definitions_from_post();
        $saved = true;
    }

    $defs = nias_meta_get_definitions();
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('meta'); ?>
        <div class="nias-set-shell">
        <div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>

            <form method="post">
                <?php wp_nonce_field('nias_meta_settings', 'nias_meta_nonce'); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h10" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('متاهای سفارشی', 'nias-course-widget')); ?>
                    <div class="nias-card-pad">
                        <div class="nias-row-desc" style="margin-bottom:14px">
                            <?php echo esc_html__('برای هر متا یک نام و یک کلید (لاتین) تعریف کنید. سپس می‌توانید مقدار هر متا را در صفحهٔ «ویرایش جلسات و فصل‌ها» هر محصول وارد کرده و با شورت‌کد آن را نمایش دهید.', 'nias-course-widget'); ?>
                        </div>

                        <div id="nias-meta-rows" class="nias-meta-rows"></div>

                        <button type="button" class="nias-btn-soft" id="nias-meta-add">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                            <?php echo esc_html__('افزودن متا', 'nias-course-widget'); ?>
                        </button>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_save_button('nias_save_meta_settings'); ?>
            </form>

            <div class="nias-foot"><?php echo wp_kses_post(__('سپاسگزاریم از اینکه سایت خود را با <span style="color:#3858e9;font-weight:600">وردپرس</span> ساخته‌اید.', 'nias-course-widget')); ?></div>
        </div>
        <?php nias_settings_ads_sidebar(); ?>
        </div>
    </div>

    <style>
        .nias-meta-rows{display:flex;flex-direction:column;gap:12px;margin-bottom:14px}
        .nias-meta-row{display:grid;grid-template-columns:1fr 1fr 1.4fr auto;gap:12px;align-items:end;background:#f8fafc;border:1px solid #e6e9ef;border-radius:12px;padding:12px}
        .nias-meta-row .nias-mlabel{display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:6px}
        .nias-meta-row .nias-input{width:100%;height:40px;border:1px solid #e2e6ee;background:#fff;border-radius:9px;padding:0 12px;font-size:13.5px;color:#1f2733}
        .nias-meta-row .nias-input:focus{outline:none;border-color:#3858e9;box-shadow:0 0 0 3px rgba(56,88,233,.15)}
        .nias-meta-copy{display:flex;flex-direction:column;gap:6px}
        .nias-meta-copybox{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e6ee;border-radius:9px;padding:5px 6px 5px 10px}
        .nias-meta-copybox code{flex:1 1 auto;min-width:0;font-size:12px;color:#1f2733;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:none}
        .nias-meta-cbtn{flex:0 0 auto;display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 11px;border:none;border-radius:7px;background:#e7eefb;color:#2159d3;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:.13s}
        .nias-meta-cbtn:hover{background:#d7e3fb}
        .nias-meta-cbtn.copied{background:#dff4e7;color:#0a8a44}
        .nias-meta-del{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:1px solid #f3c9d2;border-radius:9px;background:#fff;color:#e11d48;cursor:pointer;transition:.13s}
        .nias-meta-del:hover{background:#fff1f3}
        .nias-meta-empty-hint{font-size:13px;color:#94a3b8}
        @media(max-width:900px){.nias-meta-row{grid-template-columns:1fr}}
    </style>

    <script>
    jQuery(function ($) {
        var DEFS = <?php echo wp_json_encode($defs); ?> || [];
        var L = {
            name: <?php echo wp_json_encode(__('نام متا', 'nias-course-widget')); ?>,
            namePh: <?php echo wp_json_encode(__('مثلاً: مدت دوره', 'nias-course-widget')); ?>,
            key: <?php echo wp_json_encode(__('کلید (لاتین)', 'nias-course-widget')); ?>,
            keyPh: <?php echo wp_json_encode(__('مثلاً: course_duration', 'nias-course-widget')); ?>,
            id: <?php echo wp_json_encode(__('شناسه', 'nias-course-widget')); ?>,
            sc: <?php echo wp_json_encode(__('شورت‌کد', 'nias-course-widget')); ?>,
            copy: <?php echo wp_json_encode(__('کپی', 'nias-course-widget')); ?>,
            copied: <?php echo wp_json_encode(__('کپی شد', 'nias-course-widget')); ?>,
            del: <?php echo wp_json_encode(__('حذف', 'nias-course-widget')); ?>,
            keyHint: <?php echo wp_json_encode(__('یک کلید لاتین وارد کنید', 'nias-course-widget')); ?>
        };
        var $rows = $('#nias-meta-rows');

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function slugify(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9_\-]+/g, ''); }
        function shortcodeFor(k) { return '[nias_meta key="' + k + '"]'; }

        function rowHtml(name, key) {
            return '' +
            '<div class="nias-meta-row">' +
                '<div><label class="nias-mlabel">' + esc(L.name) + '</label>' +
                    '<input type="text" name="nias_meta_name[]" class="nias-input nias-meta-name" value="' + esc(name) + '" placeholder="' + esc(L.namePh) + '"></div>' +
                '<div><label class="nias-mlabel">' + esc(L.key) + '</label>' +
                    '<input type="text" name="nias_meta_key[]" class="nias-input nias-meta-key" dir="ltr" value="' + esc(key) + '" placeholder="' + esc(L.keyPh) + '"></div>' +
                '<div class="nias-meta-copy">' +
                    '<div><label class="nias-mlabel">' + esc(L.id) + '</label>' +
                        '<div class="nias-meta-copybox"><code class="nias-meta-keytext" dir="ltr"></code>' +
                        '<button type="button" class="nias-meta-cbtn" data-copy="key">' + esc(L.copy) + '</button></div></div>' +
                    '<div><label class="nias-mlabel">' + esc(L.sc) + '</label>' +
                        '<div class="nias-meta-copybox"><code class="nias-meta-sctext" dir="ltr"></code>' +
                        '<button type="button" class="nias-meta-cbtn" data-copy="sc">' + esc(L.copy) + '</button></div></div>' +
                '</div>' +
                '<button type="button" class="nias-meta-del" title="' + esc(L.del) + '">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>' +
                '</button>' +
            '</div>';
        }

        function syncRow($row) {
            var rawKey = $row.find('.nias-meta-key').val();
            var name = $row.find('.nias-meta-name').val();
            var key = slugify(rawKey || name);
            $row.find('.nias-meta-keytext').text(key || L.keyHint);
            $row.find('.nias-meta-sctext').text(key ? shortcodeFor(key) : L.keyHint);
            $row.find('[data-copy]').each(function () {
                var type = $(this).data('copy');
                $(this).attr('data-clip', key ? (type === 'sc' ? shortcodeFor(key) : key) : '');
                $(this).prop('disabled', !key);
            });
        }

        function addRow(name, key) {
            var $row = $(rowHtml(name || '', key || ''));
            $rows.append($row);
            syncRow($row);
        }

        function copyText(text, $btn) {
            if (!text) return;
            var done = function () {
                var old = $btn.text();
                $btn.text(L.copied).addClass('copied');
                setTimeout(function () { $btn.text(old).removeClass('copied'); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
            } else {
                fallbackCopy(text); done();
            }
        }
        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }

        // Initial rows.
        if (DEFS.length) {
            DEFS.forEach(function (d) { addRow(d.name, d.key); });
        } else {
            addRow('', '');
        }

        $('#nias-meta-add').on('click', function () { addRow('', ''); });
        $rows.on('input', '.nias-meta-name, .nias-meta-key', function () { syncRow($(this).closest('.nias-meta-row')); });
        $rows.on('click', '.nias-meta-del', function () { $(this).closest('.nias-meta-row').remove(); });
        $rows.on('click', '[data-copy]', function () { copyText($(this).attr('data-clip'), $(this)); });
    });
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Curriculum editor box (rendered right under the .nc-grid area)
 * ---------------------------------------------------------------------- */

/**
 * Render the per-product meta box shown under the curriculum grid.
 *
 * @param int $product_id
 */
function nias_meta_render_curriculum_box($product_id)
{
    if (!nias_meta_enabled()) {
        return;
    }

    $defs   = nias_meta_get_definitions();
    $values = nias_meta_get_product_values($product_id);
    ?>
    <div class="nc-meta-box" dir="rtl">
        <div class="nc-meta-head">
            <span class="nc-meta-ic">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            </span>
            <span class="nc-meta-title"><?php echo esc_html__('متاهای سفارشی محصول', 'nias-course-widget'); ?></span>
        </div>

        <?php if (empty($defs)) : ?>
            <div class="nc-meta-empty">
                <?php echo esc_html__('هنوز متایی تعریف نشده است.', 'nias-course-widget'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=nias-course-meta')); ?>" target="_blank" rel="noopener"><?php echo esc_html__('تعریف متا', 'nias-course-widget'); ?> ↗</a>
            </div>
        <?php else : ?>
            <div class="nc-meta-grid">
                <?php foreach ($defs as $def) :
                    $key = $def['key'];
                    $val = isset($values[$key]) ? $values[$key] : '';
                    $sc  = '[nias_meta key="' . $key . '"]'; ?>
                    <div class="nc-meta-field">
                        <label class="nc-meta-label"><?php echo esc_html($def['name']); ?></label>
                        <input type="text" class="nc-meta-input" data-nias-meta-key="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                        <div class="nc-meta-tools">
                            <code dir="ltr"><?php echo esc_html($key); ?></code>
                            <button type="button" class="nc-meta-cbtn" data-clip="<?php echo esc_attr($key); ?>"><?php echo esc_html__('کپی شناسه', 'nias-course-widget'); ?></button>
                            <button type="button" class="nc-meta-cbtn" data-clip="<?php echo esc_attr($sc); ?>"><?php echo esc_html__('کپی شورت‌کد', 'nias-course-widget'); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .nc-meta-box{background:#fff;border:1px solid #e6e9ef;border-radius:18px;padding:18px 20px;margin-top:20px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -16px rgba(16,24,40,.18);font-family:'Vazirmatn',sans-serif}
        .nc-meta-head{display:flex;align-items:center;gap:9px;margin-bottom:14px}
        .nc-meta-ic{display:inline-flex;width:34px;height:34px;border-radius:10px;align-items:center;justify-content:center;background:#e7eefb;color:#2159d3}
        .nc-meta-title{font-size:15.5px;font-weight:800;color:#1f2733}
        .nc-meta-empty{font-size:13.5px;color:#94a3b8}
        .nc-meta-empty a{color:#2563eb;font-weight:700;text-decoration:none;margin-inline-start:6px}
        .nc-meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
        .nc-meta-field{background:#f8fafc;border:1px solid #eef1f6;border-radius:12px;padding:12px}
        .nc-meta-label{display:block;font-size:13px;font-weight:700;color:#33415c;margin-bottom:7px}
        .nc-meta-input{width:100%;height:40px;border:1px solid #e2e6ee;background:#fff;border-radius:9px;padding:0 12px;font-size:13.5px;color:#1f2733;font-family:inherit}
        .nc-meta-input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
        .nc-meta-tools{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:9px}
        .nc-meta-tools code{font-size:12px;color:#64748b;background:#eef2f9;border-radius:6px;padding:3px 8px;direction:ltr}
        .nc-meta-cbtn{display:inline-flex;align-items:center;height:28px;padding:0 11px;border:none;border-radius:7px;background:#e7eefb;color:#2159d3;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:.13s}
        .nc-meta-cbtn:hover{background:#d7e3fb}
        .nc-meta-cbtn.copied{background:#dff4e7;color:#0a8a44}
    </style>

    <script>
    (function () {
        var box = document.querySelector('.nc-meta-box');
        if (!box) return;
        var COPIED = <?php echo wp_json_encode(__('کپی شد', 'nias-course-widget')); ?>;
        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }
        box.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-clip]');
            if (!btn) return;
            var text = btn.getAttribute('data-clip');
            if (!text) return;
            var flash = function () {
                var old = btn.textContent;
                btn.textContent = COPIED; btn.classList.add('copied');
                setTimeout(function () { btn.textContent = old; btn.classList.remove('copied'); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(flash, function () { fallbackCopy(text); flash(); });
            } else {
                fallbackCopy(text); flash();
            }
        });
    })();
    </script>
    <?php
}
