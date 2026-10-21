<?php

/**
 * Nias Course - Carbon Fields compatibility / replacement data layer.
 *
 * Carbon Fields was removed to lighten the plugin, but the data already stored
 * by Carbon Fields must keep working untouched. This file re-implements the
 * three helper functions the plugin relied on (carbon_get_theme_option,
 * carbon_get_post_meta, carbon_set_post_meta) while reading and writing data in
 * the EXACT same storage format Carbon Fields v3 used, so existing sites do not
 * lose any data.
 *
 * Carbon Fields key schema (see vendor Key_Toolset.php):
 *   _[root]|[parent:field:names]|[group:indexes]|[value_index]|[property]
 * Example: _course_sections|section_icon:icon_url|0:0|0|value
 *
 * @package nias-course-widget
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Schema definitions
 * ---------------------------------------------------------------------- */

/**
 * Schema for the "course_sections" complex post meta field.
 *
 * Each entry: 'type' => 'value' (a stored leaf value) or 'complex' (a nested
 * repeater with its own 'fields'). HTML-only fields are not stored and are
 * therefore omitted from the schema.
 *
 * @return array
 */
function nias_course_sections_schema()
{
    $icon_fields = array(
        'icon_type'   => array('type' => 'value'),
        'icon_upload' => array('type' => 'value'),
        'icon_url'    => array('type' => 'value'),
    );

    return array(
        'section_title'    => array('type' => 'value'),
        'section_subtitle' => array('type' => 'value'),
        'section_icon'     => array('type' => 'complex', 'fields' => $icon_fields),
        'lessons'          => array('type' => 'complex', 'fields' => array(
            'lesson_title'         => array('type' => 'value'),
            'lesson_icon'          => array('type' => 'complex', 'fields' => $icon_fields),
            'lesson_label'         => array('type' => 'value'),
            'lesson_preview_video' => array('type' => 'complex', 'fields' => array(
                'video_type'   => array('type' => 'value'),
                'video_upload' => array('type' => 'value'),
                'video_url'    => array('type' => 'value'),
                'video_embed'  => array('type' => 'value'),
            )),
            'lesson_download'      => array('type' => 'complex', 'fields' => array(
                'file_type'   => array('type' => 'value'),
                'file_upload' => array('type' => 'value'),
                'file_url'    => array('type' => 'value'),
            )),
            'lesson_private'       => array('type' => 'value'),
            'lesson_duration'      => array('type' => 'value'),
            'lesson_content'       => array('type' => 'value'),
        )),
    );
}

/**
 * Theme option field names that store multiple values (Carbon multiselect).
 *
 * @return array
 */
function nias_cf_multivalue_theme_options()
{
    return array(
        'certificate_selected_products',
        'certificate_selected_categories',
    );
}

/* -------------------------------------------------------------------------
 * Key generation (ports of Carbon_Fields\Toolset\Key_Toolset)
 * ---------------------------------------------------------------------- */

/**
 * Build a full Carbon storage key for a single value.
 *
 * @param bool         $is_simple_root
 * @param array        $full_hierarchy       field names from root to this field
 * @param array        $full_hierarchy_index parent group indexes
 * @param int          $value_group_index
 * @param string       $property
 * @return string
 */
function nias_cf_storage_key($is_simple_root, $full_hierarchy, $full_hierarchy_index, $value_group_index, $property)
{
    // Sanitize hierarchy index: one entry per ancestor (count - 1).
    $full_hierarchy_index = array_slice($full_hierarchy_index, 0, max(0, count($full_hierarchy) - 1));
    $full_hierarchy_index = array_map('intval', $full_hierarchy_index);

    if ($is_simple_root && $property === 'value') {
        return '_' . $full_hierarchy[0];
    }

    $parents = $full_hierarchy;
    $first   = array_shift($parents);

    $prefix = '_' . $first . '|'
        . implode(':', $parents) . '|'
        . implode(':', $full_hierarchy_index) . '|';

    return $prefix . intval($value_group_index) . '|' . $property;
}

/**
 * Parse a Carbon storage key into its segments (port of parse_storage_key).
 *
 * @param string $key
 * @return array
 */
function nias_cf_parse_key($key)
{
    $body     = substr($key, 1); // drop leading "_"
    $segments = explode('|', $body);

    $parsed = array(
        'root'            => isset($segments[0]) ? $segments[0] : '',
        'hierarchy'       => array(),
        'hierarchy_index' => array(),
        'value_index'     => 0,
        'property'        => 'value',
    );

    if (count($segments) === 5) {
        $parsed['hierarchy'] = array_values(array_filter(explode(':', $segments[1])));

        if ($segments[2] !== '') {
            $parsed['hierarchy_index'] = array_map('intval', explode(':', $segments[2]));
        }

        $parsed['value_index'] = intval($segments[3]);
        $parsed['property']    = $segments[4];
    }

    $parsed['full_hierarchy'] = array_merge(array($parsed['root']), $parsed['hierarchy']);

    return $parsed;
}

/* -------------------------------------------------------------------------
 * Reading
 * ---------------------------------------------------------------------- */

/**
 * Fetch every stored row that belongs to a root field.
 *
 * @param string $source 'option' or 'post'
 * @param int    $id     post id (ignored for options)
 * @param string $root   root field name
 * @return array<object> rows with ->key and ->value
 */
function nias_cf_fetch_rows($source, $id, $root)
{
    global $wpdb;

    $eq   = '_' . $root;
    $like = $wpdb->esc_like('_' . $root . '|') . '%';

    if ($source === 'option') {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT option_name AS `key`, option_value AS `value`
             FROM {$wpdb->options}
             WHERE option_name = %s OR option_name LIKE %s",
            $eq,
            $like
        ));
    }

    return $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key AS `key`, meta_value AS `value`
         FROM {$wpdb->postmeta}
         WHERE post_id = %d AND (meta_key = %s OR meta_key LIKE %s)",
        intval($id),
        $eq,
        $like
    ));
}

/**
 * Convert a flat array of stored rows into a nested value tree
 * (port of Key_Value_Datastore::cascading_storage_array_to_value_tree_array).
 *
 * @param array $rows
 * @return array|null
 */
function nias_cf_build_value_tree($rows)
{
    $tree            = array();
    $found_keepalive = false;

    foreach ($rows as $row) {
        $parsed = nias_cf_parse_key($row->key);

        if ($parsed['property'] === '_empty') {
            $found_keepalive = true;
            continue;
        }

        $full_hierarchy = $parsed['full_hierarchy'];
        $depth          = count($full_hierarchy);

        $level = &$tree;
        foreach ($full_hierarchy as $i => $field_name) {
            $index = isset($parsed['hierarchy_index'][$i]) ? $parsed['hierarchy_index'][$i] : 0;

            if (!isset($level[$field_name])) {
                $level[$field_name] = array();
            }
            $level = &$level[$field_name];

            if ($i < $depth - 1) {
                if (!isset($level[$index])) {
                    $level[$index] = array();
                }
                $level = &$level[$index];
            } else {
                if (!isset($level[$parsed['value_index']])) {
                    $level[$parsed['value_index']] = array();
                }
                $level = &$level[$parsed['value_index']];
                $level[$parsed['property']] = $row->value;
            }
        }
        unset($level);
    }

    if (empty($tree) && !$found_keepalive) {
        return null;
    }

    return $tree;
}

/**
 * Format a complex value-tree node into the nested array consumers expect.
 *
 * A node is keyed by group index; each group holds a "value" group-marker plus
 * its child field data. Output is a 0-indexed list of groups, each an
 * associative array of field => formatted value.
 *
 * @param mixed $node
 * @param array $schema_fields
 * @return array
 */
function nias_cf_format_complex($node, $schema_fields)
{
    $result = array();

    if (!is_array($node)) {
        return $result;
    }

    ksort($node, SORT_NUMERIC);

    foreach ($node as $group) {
        if (!is_array($group)) {
            continue;
        }

        $formatted = array();
        foreach ($schema_fields as $field_name => $def) {
            if ($def['type'] === 'complex') {
                $formatted[$field_name] = isset($group[$field_name])
                    ? nias_cf_format_complex($group[$field_name], $def['fields'])
                    : array();
            } else {
                $formatted[$field_name] = nias_cf_extract_value($group, $field_name);
            }
        }
        $result[] = $formatted;
    }

    return $result;
}

/**
 * Extract a simple leaf value from a group node.
 *
 * @param array  $group
 * @param string $field_name
 * @return string
 */
function nias_cf_extract_value($group, $field_name)
{
    if (
        isset($group[$field_name][0]) &&
        is_array($group[$field_name][0]) &&
        array_key_exists('value', $group[$field_name][0])
    ) {
        return $group[$field_name][0]['value'];
    }
    return '';
}

/**
 * Read a complex field and return its formatted nested value.
 *
 * @param string $source 'option' or 'post'
 * @param int    $id
 * @param string $root
 * @param array  $schema
 * @return array
 */
function nias_cf_read_complex($source, $id, $root, $schema)
{
    $rows = nias_cf_fetch_rows($source, $id, $root);
    $tree = nias_cf_build_value_tree($rows);

    if ($tree === null || !isset($tree[$root])) {
        return array();
    }

    return nias_cf_format_complex($tree[$root], $schema);
}

/**
 * Read a multi-value field (e.g. multiselect) and return a flat array.
 *
 * @param string $source
 * @param int    $id
 * @param string $root
 * @return array
 */
function nias_cf_read_multivalue($source, $id, $root)
{
    $rows = nias_cf_fetch_rows($source, $id, $root);
    $tree = nias_cf_build_value_tree($rows);

    if ($tree === null || !isset($tree[$root]) || !is_array($tree[$root])) {
        return array();
    }

    $node = $tree[$root];
    ksort($node, SORT_NUMERIC);

    $values = array();
    foreach ($node as $entry) {
        if (is_array($entry) && array_key_exists('value', $entry)) {
            $values[] = $entry['value'];
        }
    }
    return $values;
}

/* -------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------- */

/**
 * Recursively flatten a complex value into Carbon storage rows.
 *
 * @param array  $rows           accumulator (key => value), passed by reference
 * @param array  $full_hierarchy field names from root to this complex
 * @param array  $hierarchy_index ancestor group indexes
 * @param array  $groups         list of group arrays for this complex
 * @param array  $schema_fields
 */
function nias_cf_flatten_complex(&$rows, $full_hierarchy, $hierarchy_index, $groups, $schema_fields)
{
    $groups = is_array($groups) ? array_values($groups) : array();

    // The complex field itself (group markers) keeps the structure alive.
    if (empty($groups)) {
        $rows[nias_cf_storage_key(false, $full_hierarchy, $hierarchy_index, 0, '_empty')] = '';
    } else {
        foreach ($groups as $group_index => $group) {
            $rows[nias_cf_storage_key(false, $full_hierarchy, $hierarchy_index, $group_index, 'value')] = '_';
        }
    }

    foreach ($groups as $group_index => $group) {
        if (!is_array($group)) {
            continue;
        }
        $child_index = array_merge($hierarchy_index, array($group_index));

        foreach ($schema_fields as $field_name => $def) {
            $child_hierarchy = array_merge($full_hierarchy, array($field_name));

            if ($def['type'] === 'complex') {
                $sub_groups = isset($group[$field_name]) && is_array($group[$field_name])
                    ? $group[$field_name]
                    : array();
                nias_cf_flatten_complex($rows, $child_hierarchy, $child_index, $sub_groups, $def['fields']);
            } else {
                $value = isset($group[$field_name]) ? $group[$field_name] : '';
                if (is_bool($value)) {
                    $value = $value ? 'yes' : '';
                }
                $rows[nias_cf_storage_key(false, $child_hierarchy, $child_index, 0, 'value')] = $value;
            }
        }
    }
}

/**
 * Persist a complex field's value, replacing any previous data.
 *
 * @param int    $post_id
 * @param string $root
 * @param mixed  $value
 * @param array  $schema
 */
function nias_cf_write_post_complex($post_id, $root, $value, $schema)
{
    global $wpdb;

    $rows = array();
    nias_cf_flatten_complex($rows, array($root), array(), $value, $schema);

    // Remove all previous rows for this root field.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta}
         WHERE post_id = %d AND (meta_key = %s OR meta_key LIKE %s)",
        intval($post_id),
        '_' . $root,
        $wpdb->esc_like('_' . $root . '|') . '%'
    ));

    foreach ($rows as $key => $val) {
        add_post_meta($post_id, $key, wp_slash($val));
    }

    wp_cache_delete($post_id, 'post_meta');
}

/**
 * Persist a multi-value theme option, replacing any previous data.
 *
 * @param string $root
 * @param array  $values
 */
function nias_cf_write_option_multivalue($root, $values)
{
    global $wpdb;

    $values = is_array($values) ? array_values($values) : array();

    // Remove previous rows.
    $names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name = %s OR option_name LIKE %s",
        '_' . $root,
        $wpdb->esc_like('_' . $root . '|') . '%'
    ));
    foreach ($names as $name) {
        delete_option($name);
    }

    if (empty($values)) {
        update_option(nias_cf_storage_key(false, array($root), array(), 0, '_empty'), '');
        return;
    }

    foreach ($values as $i => $value) {
        update_option(nias_cf_storage_key(false, array($root), array(), $i, 'value'), $value);
    }
}

/* -------------------------------------------------------------------------
 * Compact curriculum storage (1.4.1)
 *
 * The Carbon format stores one postmeta row per field, so a large course can
 * own thousands of rows (7,000+ seen in production). WordPress loads every
 * meta row of a post whenever any of its meta is read — WooCommerce does that
 * for each product on listing pages — which made those products very slow.
 *
 * The curriculum now lives in a single row (_nias_course_sections) holding the
 * exact array the Carbon reader used to return. Legacy rows are converted
 * lazily on read and in small admin batches; a non-autoloaded backup of the
 * original rows is kept in nias_cs_backup_{post_id}.
 * ---------------------------------------------------------------------- */

define('NIAS_CS_META', '_nias_course_sections');

// The compact format is used only when this file provides the Carbon helpers;
// with the real Carbon Fields plugin active, its legacy format stays in charge.
define('NIAS_CS_COMPACT', !function_exists('carbon_get_post_meta') && !function_exists('carbon_set_post_meta'));

/**
 * Normalize a curriculum to exactly what the legacy reader returned
 * (flatten → value tree → format), so every consumer sees the same shape.
 *
 * @param mixed $value
 * @return array
 */
function nias_cs_normalize($value)
{
    $rows = array();
    nias_cf_flatten_complex($rows, array('course_sections'), array(), $value, nias_course_sections_schema());
    $objects = array();
    foreach ($rows as $key => $val) {
        // Mirror the DB round-trip of the legacy format (everything a string).
        if ($val === null) {
            $val = '';
        } elseif (is_array($val) || is_object($val)) {
            $val = maybe_serialize($val);
        } else {
            $val = (string) $val;
        }
        $objects[] = (object) array('key' => $key, 'value' => $val);
    }
    $tree = nias_cf_build_value_tree($objects);
    if ($tree === null || !isset($tree['course_sections'])) {
        return array();
    }
    return nias_cf_format_complex($tree['course_sections'], nias_course_sections_schema());
}

/** Delete a post's legacy Carbon rows for course_sections. */
function nias_cs_delete_legacy_rows($post_id)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta}
         WHERE post_id = %d AND (meta_key = %s OR meta_key LIKE %s)",
        intval($post_id),
        '_course_sections',
        $wpdb->esc_like('_course_sections|') . '%'
    ));
    wp_cache_delete(intval($post_id), 'post_meta');
}

/**
 * Store a (normalized) curriculum in the compact row.
 *
 * update_metadata() is used instead of update_post_meta() on purpose: the
 * latter silently redirects revisions to their parent post, which would let an
 * old revision overwrite the live product's curriculum.
 */
function nias_cs_store($post_id, $sections)
{
    // The meta API unslashes its input; slash so backslashes survive.
    return update_metadata('post', intval($post_id), NIAS_CS_META, wp_slash($sections));
}

/** Read the compact row of exactly this post (no revision redirect). */
function nias_cs_read_row($post_id)
{
    return get_metadata('post', intval($post_id), NIAS_CS_META, true);
}

/** Has the one-time conversion of every product finished? */
function nias_cs_migration_complete()
{
    return get_option('nias_cs_migrated') === NIAS_COURSE_VERSION;
}

/**
 * Convert one post's legacy rows to the compact row.
 *
 * The legacy rows are deleted only after the compact copy has been read back
 * and matches; the raw rows are also kept in a non-autoloaded backup option.
 *
 * @param int  $post_id
 * @param bool $ok      set to true when the legacy rows were converted and removed
 * @return array the curriculum (empty when the post had none)
 */
function nias_cs_migrate_post($post_id, &$ok = null)
{
    $ok      = false;
    $post_id = intval($post_id);
    $rows    = nias_cf_fetch_rows('post', $post_id, 'course_sections');
    if (empty($rows)) {
        $ok = true;
        return array();
    }

    $tree     = nias_cf_build_value_tree($rows);
    $sections = ($tree !== null && isset($tree['course_sections']))
        ? nias_cf_format_complex($tree['course_sections'], nias_course_sections_schema())
        : array();

    $backup_key = 'nias_cs_backup_' . $post_id;
    if (get_option($backup_key, null) === null) {
        $backup = array();
        foreach ($rows as $r) {
            $backup[$r->key] = $r->value;
        }
        add_option($backup_key, $backup, '', 'no');
    }

    nias_cs_store($post_id, $sections);
    wp_cache_delete($post_id, 'post_meta');
    $check = nias_cs_read_row($post_id);
    if (is_array($check) && $check === $sections) {
        nias_cs_delete_legacy_rows($post_id);
        $ok = true;
    } else {
        // Keep the legacy rows (still readable) and stop retrying this post.
        $skip = (array) get_option('nias_cs_skip', array());
        $skip[$post_id] = time();
        update_option('nias_cs_skip', $skip, false);
    }
    return $sections;
}

/**
 * Read a curriculum (compact row first, legacy rows as a fallback).
 *
 * @param int $post_id
 * @return array
 */
function nias_cs_get($post_id)
{
    $post_id = intval($post_id);
    if (!$post_id) {
        return array();
    }
    $data = nias_cs_read_row($post_id);
    if (is_array($data)) {
        return $data;
    }
    $skip = (array) get_option('nias_cs_skip', array());
    if (nias_cs_migration_complete() && !isset($skip[$post_id])) {
        return array();
    }
    if (isset($skip[$post_id])) {
        // Conversion failed earlier: serve the legacy rows as before.
        return nias_cf_read_complex('post', $post_id, 'course_sections', nias_course_sections_schema());
    }
    return nias_cs_migrate_post($post_id);
}

/**
 * Save a curriculum in the compact row and drop any legacy rows.
 *
 * @param int   $post_id
 * @param mixed $value
 */
function nias_cs_set($post_id, $value)
{
    $post_id = intval($post_id);
    nias_cs_store($post_id, nias_cs_normalize($value));
    nias_cs_delete_legacy_rows($post_id);
}

/**
 * Convert legacy rows in small batches on admin requests until none remain.
 * Products that are never opened on the front end are converted too, so
 * WooCommerce listing pages stop loading thousands of rows.
 */
add_action('admin_init', 'nias_cs_migrate_batch');
function nias_cs_migrate_batch()
{
    if (!NIAS_CS_COMPACT || nias_cs_migration_complete() || wp_doing_ajax()) {
        return;
    }
    global $wpdb;
    $skip = array_map('intval', array_keys((array) get_option('nias_cs_skip', array())));
    $not  = $skip ? ' AND post_id NOT IN (' . implode(',', $skip) . ')' : '';
    $ids  = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s{$not} LIMIT 5",
        $wpdb->esc_like('_course_sections|') . '%'
    ));
    if (empty($ids)) {
        update_option('nias_cs_migrated', NIAS_COURSE_VERSION);
        return;
    }
    $start = microtime(true);
    foreach ($ids as $id) {
        nias_cs_migrate_post((int) $id);
        if (microtime(true) - $start > 5) {
            break;
        }
    }
}

/**
 * Emergency restore of the original Carbon rows from the backup option.
 *
 * @param int $post_id
 * @return bool
 */
function nias_cs_restore_legacy($post_id)
{
    $post_id = intval($post_id);
    $backup  = get_option('nias_cs_backup_' . $post_id, null);
    if (!is_array($backup)) {
        return false;
    }
    nias_cs_delete_legacy_rows($post_id);
    foreach ($backup as $key => $val) {
        add_metadata('post', $post_id, $key, wp_slash($val));
    }
    delete_metadata('post', $post_id, NIAS_CS_META);
    delete_option('nias_cs_migrated');
    return true;
}

/* -------------------------------------------------------------------------
 * Public drop-in replacements for the Carbon Fields helpers
 * ---------------------------------------------------------------------- */

if (!function_exists('carbon_get_theme_option')) {
    /**
     * Read a theme option stored in the Carbon Fields format.
     *
     * @param string $name
     * @return mixed string for simple fields, array for multiselect fields
     */
    function carbon_get_theme_option($name)
    {
        if (in_array($name, nias_cf_multivalue_theme_options(), true)) {
            return nias_cf_read_multivalue('option', 0, $name);
        }
        $value = get_option('_' . $name, '');
        return $value === false ? '' : $value;
    }
}

if (!function_exists('carbon_get_post_meta')) {
    /**
     * Read post meta stored in the Carbon Fields format.
     *
     * @param int    $post_id
     * @param string $name
     * @return mixed
     */
    function carbon_get_post_meta($post_id, $name)
    {
        if ($name === 'course_sections') {
            return nias_cs_get($post_id);
        }
        return get_post_meta($post_id, '_' . $name, true);
    }
}

if (!function_exists('carbon_set_post_meta')) {
    /**
     * Write post meta in the Carbon Fields format.
     *
     * @param int    $post_id
     * @param string $name
     * @param mixed  $value
     */
    function carbon_set_post_meta($post_id, $name, $value)
    {
        if ($name === 'course_sections') {
            nias_cs_set($post_id, $value);
            return;
        }
        update_post_meta($post_id, '_' . $name, $value);
    }
}
