<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Indexes that keep quiz screens fast with many quizzes, courses and students.
 *
 *   1. {prefix}nias_quiz_results — one row per (user, quiz) mirroring the
 *      summary user meta (best, last, attempts, passed, pending, updated).
 *      Lists, filters, search, pagination, statistics and the grading queue
 *      all run as indexed SQL on it instead of reading every user's meta.
 *   2. Quiz → courses: post meta _nias_quiz_in_course on each quiz (one row per
 *      course whose lessons use it), refreshed whenever a curriculum is saved.
 *   3. Quiz summary post meta (_nias_quiz_qcount/_pass/_essay/_category) so
 *      lists and pickers do not decode every quiz's questions.
 *
 * Existing data is indexed in the background (small batches on admin page
 * loads) until done; user meta stays the source of truth.
 *
 * @package nias-course-widget
 */

define('NIAS_QUIZ_DB_VERSION', '1');

function nias_quiz_table()
{
    global $wpdb;
    return $wpdb->prefix . 'nias_quiz_results';
}

/** Create / upgrade the table. */
function nias_quiz_index_install()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = nias_quiz_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t} (
        user_id bigint(20) unsigned NOT NULL,
        quiz_id bigint(20) unsigned NOT NULL,
        best smallint(6) DEFAULT NULL,
        last smallint(6) DEFAULT NULL,
        attempts int(11) unsigned NOT NULL DEFAULT 0,
        passed tinyint(1) unsigned NOT NULL DEFAULT 0,
        pending int(11) unsigned NOT NULL DEFAULT 0,
        updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY  (user_id,quiz_id),
        KEY quiz_updated (quiz_id,updated),
        KEY pending_updated (pending,updated)
    ) {$charset};");
    update_option('nias_quiz_db_version', NIAS_QUIZ_DB_VERSION, false);
}

add_action('plugins_loaded', function () {
    if (get_option('nias_quiz_db_version') !== NIAS_QUIZ_DB_VERSION) {
        nias_quiz_index_install();
        update_option('nias_quiz_index_cursor', array('results' => 0, 'quizzes' => 0, 'courses' => 0), false);
    }
}, 20);

/** Is the table there (it is, after install; guards very early calls). */
function nias_quiz_index_ready()
{
    return get_option('nias_quiz_db_version') === NIAS_QUIZ_DB_VERSION;
}

/**
 * Mirror one user's summary for a quiz into the table (or remove the row).
 *
 * @param string|null $when datetime for "updated" (default: now)
 */
function nias_quiz_index_sync($user_id, $quiz_id, $when = null)
{
    global $wpdb;
    if (!nias_quiz_index_ready()) {
        return;
    }
    $user_id = (int) $user_id;
    $quiz_id = (int) $quiz_id;
    $rec = nias_quiz_user_record($user_id, $quiz_id);
    if (!$rec) {
        $wpdb->delete(nias_quiz_table(), array('user_id' => $user_id, 'quiz_id' => $quiz_id), array('%d', '%d'));
        return;
    }
    $wpdb->replace(nias_quiz_table(), array(
        'user_id'  => $user_id,
        'quiz_id'  => $quiz_id,
        'best'     => $rec['best'],
        'last'     => $rec['last'],
        'attempts' => $rec['attempts'],
        'passed'   => $rec['passed'] ? 1 : 0,
        'pending'  => $rec['pending'],
        'updated'  => $when ? $when : current_time('mysql'),
    ), array('%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s')); // (null values are written as NULL)
}

// Keep the table in step with deletions.
add_action('deleted_user', function ($user_id) {
    global $wpdb;
    if (nias_quiz_index_ready()) {
        $wpdb->delete(nias_quiz_table(), array('user_id' => (int) $user_id), array('%d'));
    }
});
add_action('before_delete_post', function ($post_id) {
    global $wpdb;
    $type = get_post_type($post_id);
    if ($type === NIAS_QUIZ_CPT && nias_quiz_index_ready()) {
        $wpdb->delete(nias_quiz_table(), array('quiz_id' => (int) $post_id), array('%d'));
    } elseif (nias_is_course_type($type)) {
        nias_quiz_index_course($post_id, array());
    }
});

/* -------------------------------------------------------------------------
 * Quiz summary meta + quiz → course index
 * ---------------------------------------------------------------------- */

/** Summary fields written next to the quiz model (called from nias_quiz_store()). */
function nias_quiz_index_summary($post_id, $model)
{
    $essay = 0;
    foreach ((array) $model['questions'] as $q) {
        if (isset($q['type']) && $q['type'] === 'essay') {
            $essay = 1;
        }
    }
    update_post_meta($post_id, '_nias_quiz_qcount', count((array) $model['questions']));
    update_post_meta($post_id, '_nias_quiz_pass', (int) $model['passScore']);
    update_post_meta($post_id, '_nias_quiz_essay', $essay);
    update_post_meta($post_id, '_nias_quiz_category', (string) $model['category']);
}

/**
 * Refresh which quizzes a course uses in its lessons.
 *
 * @param int        $product_id
 * @param int[]|null $quiz_ids null = read the curriculum now
 */
function nias_quiz_index_course($product_id, $quiz_ids = null)
{
    $product_id = (int) $product_id;
    if ($quiz_ids === null) {
        $quiz_ids = function_exists('nias_quiz_lesson_quiz_ids') ? nias_quiz_lesson_quiz_ids($product_id, true) : array();
    }
    $quiz_ids = array_map('intval', $quiz_ids);
    $old = get_post_meta($product_id, '_nias_course_quiz_ids', true);
    $old = is_array($old) ? array_map('intval', $old) : array();
    foreach (array_diff($old, $quiz_ids) as $q) {
        delete_post_meta($q, '_nias_quiz_in_course', $product_id);
    }
    foreach (array_diff($quiz_ids, $old) as $q) {
        add_post_meta($q, '_nias_quiz_in_course', $product_id);
    }
    if ($quiz_ids) {
        update_post_meta($product_id, '_nias_course_quiz_ids', $quiz_ids);
    } else {
        delete_post_meta($product_id, '_nias_course_quiz_ids');
    }
}
add_action('nias_course_sections_saved', 'nias_quiz_index_course', 10, 1);

/** Course ids a quiz belongs to (lessons + the "linked course" setting). */
function nias_quiz_course_ids($quiz_id)
{
    $ids = array_map('intval', (array) get_post_meta($quiz_id, '_nias_quiz_in_course', false));
    $linked = (int) get_post_meta($quiz_id, NIAS_QUIZ_COURSE_META, true);
    if ($linked && !in_array($linked, $ids, true)) {
        $ids[] = $linked;
    }
    return array_values(array_filter($ids));
}

/* -------------------------------------------------------------------------
 * Background backfill (existing data)
 * ---------------------------------------------------------------------- */

/** Index one batch of existing data. Returns true when everything is done. */
function nias_quiz_index_backfill_step($batch = 200)
{
    global $wpdb;
    $cur = get_option('nias_quiz_index_cursor');
    if (!is_array($cur)) {
        return true;
    }
    $did = false;

    // 1) results (user meta → table)
    if ($cur['results'] !== 'done') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id, user_id, meta_key FROM {$wpdb->usermeta} WHERE umeta_id > %d AND meta_key LIKE %s ORDER BY umeta_id ASC LIMIT %d",
            (int) $cur['results'], $wpdb->esc_like('_nias_quiz_result_') . '%', $batch
        ));
        foreach ($rows as $r) {
            $qid = (int) substr($r->meta_key, strlen('_nias_quiz_result_'));
            $rec = nias_quiz_user_record((int) $r->user_id, $qid);
            nias_quiz_index_sync((int) $r->user_id, $qid, ($rec && $rec['date']) ? $rec['date'] . ' 00:00:00' : null);
            $cur['results'] = (int) $r->umeta_id;
        }
        if (count($rows) < $batch) {
            $cur['results'] = 'done';
        }
        $did = true;
    } elseif ($cur['quizzes'] !== 'done') {
        // 2) quiz summary meta
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            NIAS_QUIZ_CPT, (int) $cur['quizzes'], 100
        ));
        foreach ($ids as $id) {
            $q = nias_quiz_get((int) $id);
            if ($q) {
                nias_quiz_index_summary((int) $id, $q);
            }
            $cur['quizzes'] = (int) $id;
        }
        if (count($ids) < 100) {
            $cur['quizzes'] = 'done';
        }
        $did = true;
    } elseif ($cur['courses'] !== 'done') {
        // 3) quiz → course index, for products that have a curriculum
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','" . esc_sql(NIAS_COURSE_CPT) . "') AND ID > %d ORDER BY ID ASC LIMIT %d",
            (int) $cur['courses'], 50
        ));
        foreach ($ids as $id) {
            nias_quiz_index_course((int) $id);
            $cur['courses'] = (int) $id;
        }
        if (count($ids) < 50) {
            $cur['courses'] = 'done';
        }
        $did = true;
    }
    $done = $cur['results'] === 'done' && $cur['quizzes'] === 'done' && $cur['courses'] === 'done';
    if ($done) {
        delete_option('nias_quiz_index_cursor');
    } elseif ($did) {
        update_option('nias_quiz_index_cursor', $cur, false);
    }
    return $done;
}

/** Run a few batches on admin page loads until the backfill is complete. */
add_action('admin_init', function () {
    if (wp_doing_ajax() || !get_option('nias_quiz_index_cursor') || !current_user_can('manage_options')) {
        return;
    }
    $until = microtime(true) + 1.5; // never hold an admin page for long
    while (microtime(true) < $until) {
        if (nias_quiz_index_backfill_step()) {
            break;
        }
    }
});

/** Is the index complete (results lists can rely on it)? */
function nias_quiz_index_complete()
{
    return nias_quiz_index_ready() && !get_option('nias_quiz_index_cursor');
}
