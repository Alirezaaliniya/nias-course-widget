<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instructor dashboard (پیشخوان مدرس).
 *
 * A self-service, front-end panel an instructor sees on a page chosen from the
 * "مدرسین" settings tab ("انتخاب برگه"). It mirrors the modern dashboard design
 * but every figure is real — built from this plugin's course data (curriculum,
 * instructors, certificate settings) and WooCommerce orders/reviews. Sections
 * that have no backing data in the plugin (live classes, Q&A inbox, grading
 * queue) are intentionally omitted; only data-backed cards are rendered.
 *
 * Access scope:
 *   - an instructor (nias_instructor role)  → their own assigned courses,
 *   - a site admin (manage_options)         → all instructor-assigned courses
 *                                             (so the page is testable/visible).
 *
 * Display:
 *   - automatically appended to the page selected in the settings, and/or
 *   - placed manually with the [nias_instructor_dashboard] shortcode.
 *
 * @package nias-course-widget
 */

/* -------------------------------------------------------------------------
 * Feature wiring
 * ---------------------------------------------------------------------- */

/** Page chosen in the instructors settings tab to host the dashboard. */
function nias_instructor_dashboard_page_id()
{
    return (int) carbon_get_theme_option('instructors_dashboard_page');
}

/** Persian month names (Jalali). */
function nias_idash_months($short = false)
{
    if ($short) {
        return array('فرو', 'ارد', 'خرد', 'تیر', 'مرد', 'شهر', 'مهر', 'آبا', 'آذر', 'دی', 'بهم', 'اسف');
    }
    return array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
}

/** A Unix timestamp as Jalali [jy, jm, jd]. */
function nias_idash_to_jalali($timestamp)
{
    $gy = (int) wp_date('Y', $timestamp);
    $gm = (int) wp_date('n', $timestamp);
    $gd = (int) wp_date('j', $timestamp);
    if (function_exists('nias_gregorian_to_jalali')) {
        return nias_gregorian_to_jalali($gy, $gm, $gd);
    }
    return array($gy, $gm, $gd);
}

/** Persian-digit money with a thousands separator, matching the design. */
function nias_idash_money($n)
{
    $formatted = number_format((float) $n, 0, '.', '٬');
    return nias_fa_digits($formatted);
}

/** Two-letter initials from a display name. */
function nias_idash_initials($name)
{
    $name  = trim((string) $name);
    if ($name === '') {
        return '؟';
    }
    $parts = preg_split('/\s+/u', $name);
    $first = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
    if (count($parts) > 1) {
        $second = function_exists('mb_substr') ? mb_substr($parts[1], 0, 1, 'UTF-8') : substr($parts[1], 0, 1);
        return $first . '.' . $second;
    }
    return $first;
}

/** Accent palette cycled across course covers / avatars. */
function nias_idash_palette($i)
{
    $palette = array('#3858e9', '#8b5cf6', '#d39a2b', '#48af3b', '#e0568a', '#0ea5b7');
    return $palette[$i % count($palette)];
}

/** rgba() soft tint of a hex color. */
function nias_idash_soft($hex, $alpha = '0.10')
{
    $h = ltrim($hex, '#');
    if (strlen($h) !== 6) {
        return 'rgba(56,88,233,' . $alpha . ')';
    }
    return 'rgba(' . hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2)) . ',' . $alpha . ')';
}

/* -------------------------------------------------------------------------
 * Scope: which products belong to the viewer
 * ---------------------------------------------------------------------- */

/**
 * Product ids the current viewer manages.
 *
 * @param int  $user_id
 * @param bool $is_admin_scope set true when the viewer is an admin (not an
 *                             instructor) and should see all instructor courses.
 * @return int[]
 */
function nias_instructor_dashboard_product_ids($user_id, $is_admin_scope)
{
    if (!$is_admin_scope) {
        return array_map(function ($p) {
            return (int) $p->ID;
        }, nias_get_instructor_courses($user_id));
    }

    // Admin scope: every product assigned to any instructor.
    $ids = get_posts(array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
        'fields'         => 'ids',
        'meta_query'     => array(
            array('key' => NIAS_INSTRUCTOR_META, 'compare' => 'EXISTS'),
        ),
    ));
    return array_map('intval', (array) $ids);
}

/* -------------------------------------------------------------------------
 * Data builder
 * ---------------------------------------------------------------------- */

/**
 * Assemble the dashboard data model for a viewer, from real plugin + WooCommerce
 * data. Cached per request (the order scan is the expensive part).
 *
 * @param int $user_id
 * @return array
 */
function nias_instructor_dashboard_data($user_id)
{
    static $cache = array();
    $user_id = (int) $user_id;

    $is_instructor = function_exists('nias_user_is_instructor') && nias_user_is_instructor($user_id);
    $is_admin      = !$is_instructor && user_can($user_id, 'manage_options');
    $cache_key     = $user_id . ($is_admin ? ':admin' : ':inst');
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $user        = get_userdata($user_id);
    $product_ids = nias_instructor_dashboard_product_ids($user_id, $is_admin);
    $id_lookup   = array_fill_keys($product_ids, true);

    // Per-course accumulators.
    $courses = array();
    foreach ($product_ids as $i => $pid) {
        $post     = get_post($pid);
        if (!$post) {
            continue;
        }
        $sections = carbon_get_post_meta($pid, 'course_sections');
        $lessons  = 0;
        foreach ((array) $sections as $sec) {
            if (!empty($sec['lessons']) && is_array($sec['lessons'])) {
                $lessons += count($sec['lessons']);
            }
        }
        $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
        $color   = nias_idash_palette($i);
        $courses[$pid] = array(
            'id'           => $pid,
            'title'        => $post->post_title !== '' ? $post->post_title : __('(بدون عنوان)', 'nias-course-widget'),
            'status'       => $post->post_status,
            'lessons'      => $lessons,
            'students'     => array(),   // set of buyer keys, collapsed to a count later
            'revenue'      => 0.0,
            'rating'       => $product ? (float) $product->get_average_rating() : 0.0,
            'reviews'      => $product ? (int) $product->get_review_count() : 0,
            'editUrl'      => get_edit_post_link($pid, 'raw'),
            'viewUrl'      => function_exists('nias_course_modern_view_url') ? nias_course_modern_view_url($pid) : get_permalink($pid),
            'color'        => $color,
            'certEligible' => function_exists('nias_modern_course_cert_applies') ? nias_modern_course_cert_applies($pid) : false,
        );
    }

    // Aggregates that depend on the order scan.
    $global_students = array();              // distinct buyers across all courses
    $month_revenue   = array();              // jalali "jy-jm" => revenue
    $enrollments     = array();              // recent purchase events
    $cert_events     = array();              // recent cert-eligible purchase events
    $cert_on         = (carbon_get_theme_option('nias_course_certificate') === 'on');

    if (!empty($product_ids) && function_exists('wc_get_orders')) {
        $orders = wc_get_orders(array(
            'status' => 'wc-completed',
            'limit'  => -1,
        ));
        foreach ($orders as $order) {
            $date = $order->get_date_completed();
            if (!$date) {
                $date = $order->get_date_created();
            }
            $ts          = $date ? $date->getTimestamp() : 0;
            $buyer_id    = (int) $order->get_customer_id();
            $buyer_email = $order->get_billing_email();
            $buyer_key   = $buyer_id > 0 ? 'u' . $buyer_id : 'e' . strtolower((string) $buyer_email);
            $buyer_name  = trim($order->get_formatted_billing_full_name());
            if ($buyer_name === '' && $buyer_id > 0) {
                $bu = get_userdata($buyer_id);
                $buyer_name = $bu ? $bu->display_name : '';
            }
            if ($buyer_name === '') {
                $buyer_name = __('کاربر', 'nias-course-widget');
            }

            foreach ($order->get_items() as $item) {
                $pid = (int) $item->get_product_id();
                if (!isset($id_lookup[$pid])) {
                    continue;
                }
                $line = (float) $item->get_total();
                $courses[$pid]['revenue']        += $line;
                $courses[$pid]['students'][$buyer_key] = true;
                $global_students[$buyer_key]      = true;

                if ($ts) {
                    list($jy, $jm) = nias_idash_to_jalali($ts);
                    $mk = $jy . '-' . $jm;
                    $month_revenue[$mk] = (isset($month_revenue[$mk]) ? $month_revenue[$mk] : 0) + $line;
                }

                $event = array(
                    'pid'    => $pid,
                    'name'   => $buyer_name,
                    'course' => $courses[$pid]['title'],
                    'ts'     => $ts,
                    'userId' => $buyer_id,
                    'orderDate' => $date ? $date->date('Y-m-d') : '',
                );
                $enrollments[] = $event;
                if ($cert_on && $courses[$pid]['certEligible']) {
                    $cert_events[] = $event;
                }
            }
        }
    }

    // ---- KPI: revenue this Jalali month + trend vs previous month ----
    list($cjy, $cjm) = nias_idash_to_jalali(current_time('timestamp'));
    $series = array();
    $months_full  = nias_idash_months(false);
    $months_short = nias_idash_months(true);
    // Build the trailing 12 Jalali months ending on the current month.
    $cursor_y = $cjy;
    $cursor_m = $cjm;
    $window   = array();
    for ($i = 0; $i < 12; $i++) {
        $window[] = array($cursor_y, $cursor_m);
        $cursor_m--;
        if ($cursor_m < 1) {
            $cursor_m = 12;
            $cursor_y--;
        }
    }
    $window = array_reverse($window); // oldest → newest
    foreach ($window as $idx => $ym) {
        $mk  = $ym[0] . '-' . $ym[1];
        $val = isset($month_revenue[$mk]) ? $month_revenue[$mk] : 0;
        $series[] = array(
            'labelShort' => $months_short[$ym[1] - 1],
            'labelFull'  => $months_full[$ym[1] - 1],
            'value'      => $val,
        );
    }
    $rev_this = $series[11]['value'];
    $rev_prev = $series[10]['value'];
    $rev_trend = null;
    if ($rev_prev > 0) {
        $rev_trend = round((($rev_this - $rev_prev) / $rev_prev) * 100);
    }

    // ---- KPI: ratings (weighted by review count) ----
    $rating_weight = 0;
    $rating_sum    = 0;
    $review_total  = 0;
    foreach ($courses as $c) {
        if ($c['reviews'] > 0) {
            $rating_sum    += $c['rating'] * $c['reviews'];
            $rating_weight += $c['reviews'];
            $review_total  += $c['reviews'];
        }
    }
    $avg_rating = $rating_weight > 0 ? round($rating_sum / $rating_weight, 1) : 0;

    // Collapse per-course student sets to counts + finalize.
    $published = 0;
    $drafts    = 0;
    foreach ($courses as $pid => &$c) {
        $c['students'] = count($c['students']);
        if ($c['status'] === 'publish') {
            $published++;
        } else {
            $drafts++;
        }
    }
    unset($c);

    // ---- recent students / certificates (latest first) ----
    usort($enrollments, function ($a, $b) {
        return $b['ts'] <=> $a['ts'];
    });
    usort($cert_events, function ($a, $b) {
        return $b['ts'] <=> $a['ts'];
    });

    $recent_students = array();
    $seen_student    = array();
    foreach ($enrollments as $e) {
        $k = $e['name'] . '|' . $e['course'];
        if (isset($seen_student[$k])) {
            continue;
        }
        $seen_student[$k] = true;
        list($jy, $jm, $jd) = $e['ts'] ? nias_idash_to_jalali($e['ts']) : array(0, 1, 1);
        $recent_students[] = array(
            'name'   => $e['name'],
            'course' => $e['course'],
            'date'   => $e['ts'] ? nias_fa_digits($jd) . ' ' . $months_full[$jm - 1] : '—',
        );
        if (count($recent_students) >= 6) {
            break;
        }
    }

    $recent_certs = array();
    $seen_cert    = array();
    foreach ($cert_events as $e) {
        $k = $e['name'] . '|' . $e['course'];
        if (isset($seen_cert[$k])) {
            continue;
        }
        $seen_cert[$k] = true;
        $resolved = function_exists('nias_certificate_resolve_date')
            ? nias_certificate_resolve_date($e['userId'], $e['pid'], $e['orderDate'])
            : $e['orderDate'];
        $label = $resolved;
        $rts   = strtotime((string) $resolved);
        if ($rts) {
            list($jy, $jm, $jd) = nias_idash_to_jalali($rts);
            $label = nias_fa_digits($jd) . ' ' . $months_full[$jm - 1];
        }
        $recent_certs[] = array(
            'name'   => $e['name'],
            'course' => $e['course'],
            'date'   => $label,
        );
        if (count($recent_certs) >= 6) {
            break;
        }
    }

    // ---- recent reviews on the viewer's courses ----
    $reviews = array();
    if (!empty($product_ids)) {
        $comments = get_comments(array(
            'post__in' => $product_ids,
            'status'   => 'approve',
            'type'     => 'review',
            'number'   => 6,
            'orderby'  => 'comment_date_gmt',
            'order'    => 'DESC',
        ));
        foreach ($comments as $cm) {
            $rating = (int) get_comment_meta($cm->comment_ID, 'rating', true);
            $ts     = strtotime($cm->comment_date);
            list($jy, $jm, $jd) = $ts ? nias_idash_to_jalali($ts) : array(0, 1, 1);
            $reviews[] = array(
                'name'   => $cm->comment_author !== '' ? $cm->comment_author : __('کاربر', 'nias-course-widget'),
                'course' => get_the_title($cm->comment_post_ID),
                'text'   => wp_trim_words(wp_strip_all_tags($cm->comment_content), 30, '…'),
                'rating' => $rating,
                'date'   => $ts ? nias_fa_digits($jd) . ' ' . $months_full[$jm - 1] : '',
            );
        }
    }

    $total_revenue = 0;
    foreach ($series as $s) {
        $total_revenue += $s['value'];
    }

    // Sort courses: published first, then by revenue desc.
    $course_list = array_values($courses);
    usort($course_list, function ($a, $b) {
        if (($a['status'] === 'publish') !== ($b['status'] === 'publish')) {
            return $a['status'] === 'publish' ? -1 : 1;
        }
        return $b['revenue'] <=> $a['revenue'];
    });

    $data = array(
        'user'      => $user,
        'isAdmin'   => $is_admin,
        'kpi'       => array(
            'revenueMonth' => $rev_this,
            'revenueTrend' => $rev_trend,
            'students'     => count($global_students),
            'published'    => $published,
            'drafts'       => $drafts,
            'rating'       => $avg_rating,
            'reviewTotal'  => $review_total,
        ),
        'series'        => $series,
        'totalRevenue'  => $total_revenue,
        'courses'       => $course_list,
        'recentStudents' => $recent_students,
        'recentCerts'   => $recent_certs,
        'reviews'       => $reviews,
        'certOn'        => $cert_on,
    );

    $cache[$cache_key] = $data;
    return $data;
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

/** SVG path strings reused by the renderer. */
function nias_idash_icon($name)
{
    $icons = array(
        'revenue'  => 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h3v-4z',
        'students' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'book'     => 'M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2zM4 19.5A2.5 2.5 0 0 1 6.5 17H20',
        'list'     => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'edit'     => 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
    );
    return isset($icons[$name]) ? $icons[$name] : '';
}

/** A KPI card. */
function nias_idash_kpi_card($accent, $icon_path, $label, $value, $unit, $pill)
{
    ob_start(); ?>
    <div class="nias-idash-kpi">
        <div class="nias-idash-kpi-top">
            <div class="nias-idash-kpi-ic" style="background:<?php echo esc_attr(nias_idash_soft($accent)); ?>">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr($accent); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr($icon_path); ?>"></path></svg>
            </div>
            <?php echo $pill; ?>
        </div>
        <div class="nias-idash-kpi-label"><?php echo esc_html($label); ?></div>
        <div class="nias-idash-kpi-val"><?php echo esc_html($value); ?><?php if ($unit) : ?> <span><?php echo esc_html($unit); ?></span><?php endif; ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render the full dashboard panel for a viewer.
 *
 * @param int $user_id
 * @return string
 */
function nias_instructor_dashboard_render($user_id)
{
    $d   = nias_instructor_dashboard_data($user_id);
    $kpi = $d['kpi'];

    // Header date (today, Jalali).
    list($jy, $jm, $jd) = nias_idash_to_jalali(current_time('timestamp'));
    $months_full = nias_idash_months(false);
    $weekdays    = array('یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه');
    $today_label = $weekdays[(int) wp_date('w', current_time('timestamp'))] . '، ' . nias_fa_digits($jd) . ' ' . $months_full[$jm - 1] . ' ' . nias_fa_digits($jy);

    $name     = $d['user'] ? $d['user']->display_name : __('مدرس', 'nias-course-widget');
    $first    = preg_split('/\s+/u', trim($name));
    $first    = $first[0];

    // Revenue trend pill.
    if ($kpi['revenueTrend'] === null) {
        $rev_pill = '<span class="nias-idash-pill nias-idash-pill-mute">' . esc_html__('بدون داده ماه قبل', 'nias-course-widget') . '</span>';
    } else {
        $up   = $kpi['revenueTrend'] >= 0;
        $cls  = $up ? 'nias-idash-pill-up' : 'nias-idash-pill-down';
        $arrow = $up ? 'M7 17 17 7M9 7h8v8' : 'M7 7l10 10M15 17H7V9';
        $rev_pill = '<span class="nias-idash-pill ' . $cls . '"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="' . $arrow . '"/></svg>' . nias_fa_digits(abs($kpi['revenueTrend'])) . '٪</span>';
    }
    $students_pill  = '<span class="nias-idash-pill nias-idash-pill-mute">' . esc_html(sprintf(__('%s دوره', 'nias-course-widget'), nias_fa_digits(count($d['courses'])))) . '</span>';
    $courses_pill   = $kpi['drafts'] > 0
        ? '<span class="nias-idash-pill nias-idash-pill-warn">' . esc_html(sprintf(__('%s پیش‌نویس', 'nias-course-widget'), nias_fa_digits($kpi['drafts']))) . '</span>'
        : '<span class="nias-idash-pill nias-idash-pill-mute">' . esc_html__('بدون پیش‌نویس', 'nias-course-widget') . '</span>';
    $rating_pill    = '<span class="nias-idash-pill nias-idash-pill-mute">' . esc_html(sprintf(__('%s نظر', 'nias-course-widget'), nias_fa_digits($kpi['reviewTotal']))) . '</span>';

    ob_start();
    ?>
    <div class="nias-idash" dir="rtl">
        <?php echo nias_instructor_dashboard_styles(); ?>

        <!-- header -->
        <header class="nias-idash-head">
            <div class="nias-idash-head-id">
                <div class="nias-idash-logo">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                </div>
                <div>
                    <div class="nias-idash-title"><?php echo esc_html__('پیشخوان مدرس', 'nias-course-widget'); ?></div>
                    <div class="nias-idash-sub"><?php echo esc_html($today_label); ?></div>
                </div>
            </div>
            <div class="nias-idash-user">
                <div class="nias-idash-user-meta">
                    <div class="nias-idash-user-name"><?php echo esc_html($name); ?></div>
                    <div class="nias-idash-user-role"><?php echo esc_html($d['isAdmin'] ? __('مدیر سایت', 'nias-course-widget') : __('مدرس', 'nias-course-widget')); ?></div>
                </div>
                <div class="nias-idash-avatar"><?php echo get_avatar($user_id, 40); ?></div>
            </div>
        </header>

        <!-- greeting -->
        <div class="nias-idash-greet">
            <h2><?php echo esc_html(sprintf(__('سلام، %s 👋', 'nias-course-widget'), $first)); ?></h2>
            <p>
                <?php if ($d['isAdmin']) : ?>
                    <?php echo esc_html__('نمای کلی همهٔ دوره‌های دارای مدرس در سایت شما.', 'nias-course-widget'); ?>
                <?php else : ?>
                    <?php echo esc_html(sprintf(__('شما %s دانشجو در %s دوره دارید.', 'nias-course-widget'), nias_fa_digits($kpi['students']), nias_fa_digits(count($d['courses'])))); ?>
                <?php endif; ?>
            </p>
        </div>

        <!-- KPI row -->
        <div class="nias-idash-kpis">
            <?php
            echo nias_idash_kpi_card('#3858e9', nias_idash_icon('revenue'), __('درآمد این ماه', 'nias-course-widget'), nias_idash_money($kpi['revenueMonth']), __('تومان', 'nias-course-widget'), $rev_pill);
            echo nias_idash_kpi_card('#8b5cf6', nias_idash_icon('students'), __('دانشجویان', 'nias-course-widget'), nias_fa_digits($kpi['students']), '', $students_pill);
            echo nias_idash_kpi_card('#48af3b', nias_idash_icon('book'), __('دوره‌های منتشرشده', 'nias-course-widget'), nias_fa_digits($kpi['published']), '', $courses_pill);
            echo nias_idash_kpi_card('#d39a2b', 'M12 2l2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z', __('میانگین رضایت', 'nias-course-widget'), $kpi['rating'] > 0 ? nias_fa_digits(number_format($kpi['rating'], 1)) : '—', $kpi['rating'] > 0 ? __('از ۵', 'nias-course-widget') : '', $rating_pill);
            ?>
        </div>

        <div class="nias-idash-grid">
            <!-- ===== left column ===== -->
            <div class="nias-idash-col">
                <?php echo nias_instructor_dashboard_chart($d); ?>
                <?php echo nias_instructor_dashboard_courses($d); ?>
            </div>

            <!-- ===== right column ===== -->
            <div class="nias-idash-col">
                <?php echo nias_instructor_dashboard_students($d); ?>
                <?php if ($d['certOn'] && !empty($d['recentCerts'])) echo nias_instructor_dashboard_certs($d); ?>
                <?php if (!empty($d['reviews'])) echo nias_instructor_dashboard_reviews($d); ?>
            </div>
        </div>

        <div class="nias-idash-foot"><?php echo esc_html__('دوره ساز نیاس · پیشخوان مدرس', 'nias-course-widget'); ?></div>
    </div>
    <?php echo nias_instructor_dashboard_inline_js(); ?>
    <?php
    return ob_get_clean();
}

/** Sales-trend bar chart card (server-rendered, JS period toggle). */
function nias_instructor_dashboard_chart($d)
{
    $series = $d['series'];
    $max    = 0;
    foreach ($series as $s) {
        $max = max($max, $s['value']);
    }
    ob_start(); ?>
    <section class="nias-idash-card">
        <div class="nias-idash-card-head nias-idash-chart-head">
            <div>
                <div class="nias-idash-card-title"><?php echo esc_html__('روند فروش', 'nias-course-widget'); ?></div>
                <div class="nias-idash-chart-total">
                    <span class="nias-idash-chart-num" data-total><?php echo esc_html(nias_idash_money($d['totalRevenue'])); ?></span>
                    <span class="nias-idash-chart-cap"><?php echo esc_html__('تومان در', 'nias-course-widget'); ?> <span data-period-word>۱۲ ماه</span></span>
                </div>
            </div>
            <div class="nias-idash-seg" data-chart-toggle>
                <button type="button" class="nias-idash-seg-btn is-on" data-range="12"><?php echo esc_html__('۱۲ ماه', 'nias-course-widget'); ?></button>
                <button type="button" class="nias-idash-seg-btn" data-range="6"><?php echo esc_html__('۶ ماه', 'nias-course-widget'); ?></button>
            </div>
        </div>
        <?php if ($max <= 0) : ?>
            <div class="nias-idash-empty"><?php echo esc_html__('هنوز فروشی برای دوره‌های شما ثبت نشده است.', 'nias-course-widget'); ?></div>
        <?php else : ?>
            <div class="nias-idash-bars">
                <?php foreach ($series as $i => $s) :
                    $active = ($i === count($series) - 1);
                    $h = 16 + ($max > 0 ? ($s['value'] / $max) * 150 : 0); ?>
                    <div class="nias-idash-bar-col" data-idx="<?php echo esc_attr($i); ?>" data-val="<?php echo esc_attr($s['value']); ?>">
                        <div class="nias-idash-bar-val"<?php echo $active ? '' : ' style="visibility:hidden"'; ?>><?php echo esc_html(nias_idash_money($s['value'])); ?></div>
                        <div class="nias-idash-bar<?php echo $active ? ' is-active' : ''; ?>" style="height:<?php echo esc_attr(round($h)); ?>px" title="<?php echo esc_attr(nias_idash_money($s['value']) . ' ' . __('تومان', 'nias-course-widget')); ?>"></div>
                        <span class="nias-idash-bar-lbl"><?php echo esc_html($s['labelShort']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

/** "My courses" card with filter chips. */
function nias_instructor_dashboard_courses($d)
{
    $courses = $d['courses'];
    $counts  = array('all' => count($courses), 'publish' => 0, 'draft' => 0);
    foreach ($courses as $c) {
        if ($c['status'] === 'publish') {
            $counts['publish']++;
        } else {
            $counts['draft']++;
        }
    }
    ob_start(); ?>
    <section class="nias-idash-card">
        <div class="nias-idash-card-head">
            <div class="nias-idash-card-title"><?php echo esc_html__('دوره‌های من', 'nias-course-widget'); ?></div>
            <div class="nias-idash-chips" data-course-filter>
                <button type="button" class="nias-idash-chip is-on" data-filter="all"><?php echo esc_html__('همه', 'nias-course-widget'); ?> <span><?php echo esc_html(nias_fa_digits($counts['all'])); ?></span></button>
                <button type="button" class="nias-idash-chip" data-filter="publish"><?php echo esc_html__('منتشرشده', 'nias-course-widget'); ?> <span><?php echo esc_html(nias_fa_digits($counts['publish'])); ?></span></button>
                <button type="button" class="nias-idash-chip" data-filter="draft"><?php echo esc_html__('پیش‌نویس', 'nias-course-widget'); ?> <span><?php echo esc_html(nias_fa_digits($counts['draft'])); ?></span></button>
            </div>
        </div>

        <?php if (empty($courses)) : ?>
            <div class="nias-idash-empty"><?php echo esc_html__('هنوز دوره‌ای به شما اختصاص داده نشده است.', 'nias-course-widget'); ?></div>
        <?php else : ?>
            <div class="nias-idash-courses">
                <?php foreach ($courses as $c) :
                    $is_draft = ($c['status'] !== 'publish'); ?>
                    <div class="nias-idash-course" data-status="<?php echo esc_attr($c['status'] === 'publish' ? 'publish' : 'draft'); ?>">
                        <div class="nias-idash-course-cover" style="background:<?php echo esc_attr(nias_idash_soft($c['color'])); ?>;color:<?php echo esc_attr($c['color']); ?>">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr(nias_idash_icon('book')); ?>"></path></svg>
                        </div>
                        <div class="nias-idash-course-main">
                            <div class="nias-idash-course-titlerow">
                                <a class="nias-idash-course-title" href="<?php echo esc_url($c['viewUrl']); ?>"><?php echo esc_html($c['title']); ?></a>
                                <?php if ($is_draft) : ?>
                                    <span class="nias-idash-status nias-idash-status-draft"><?php echo esc_html__('پیش‌نویس', 'nias-course-widget'); ?></span>
                                <?php else : ?>
                                    <span class="nias-idash-status nias-idash-status-pub"><?php echo esc_html__('منتشرشده', 'nias-course-widget'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="nias-idash-course-meta">
                                <span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8"/></svg><?php echo esc_html($is_draft && $c['students'] === 0 ? '—' : nias_fa_digits($c['students'])); ?></span>
                                <span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr(nias_idash_icon('list')); ?>"></path></svg><?php echo esc_html(nias_fa_digits($c['lessons'])); ?> <?php echo esc_html__('درس', 'nias-course-widget'); ?></span>
                                <?php if ($c['reviews'] > 0) : ?>
                                    <span class="nias-idash-course-rate"><svg width="13" height="13" viewBox="0 0 24 24" fill="#d39a2b" stroke="none"><path d="M12 2l2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg><?php echo esc_html(nias_fa_digits(number_format($c['rating'], 1))); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="nias-idash-course-rev">
                            <div class="nias-idash-course-rev-num"><?php echo esc_html($c['revenue'] > 0 ? nias_idash_money($c['revenue']) : '—'); ?></div>
                            <div class="nias-idash-course-rev-cap"><?php echo esc_html__('درآمد کل', 'nias-course-widget'); ?></div>
                        </div>
                        <?php if ($c['editUrl']) : ?>
                            <a class="nias-idash-manage" href="<?php echo esc_url($c['editUrl']); ?>">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr(nias_idash_icon('edit')); ?>"></path></svg>
                                <?php echo esc_html__('مدیریت', 'nias-course-widget'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

/** Recent students card. */
function nias_instructor_dashboard_students($d)
{
    ob_start(); ?>
    <section class="nias-idash-card">
        <div class="nias-idash-card-head">
            <div class="nias-idash-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3858e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?php echo esc_attr(nias_idash_icon('students')); ?>"></path></svg>
                <span><?php echo esc_html__('دانشجویان اخیر', 'nias-course-widget'); ?></span>
            </div>
        </div>
        <?php if (empty($d['recentStudents'])) : ?>
            <div class="nias-idash-empty"><?php echo esc_html__('هنوز ثبت‌نامی ثبت نشده است.', 'nias-course-widget'); ?></div>
        <?php else : ?>
            <div class="nias-idash-rows">
                <?php foreach ($d['recentStudents'] as $i => $s) :
                    $color = nias_idash_palette($i); ?>
                    <div class="nias-idash-srow">
                        <div class="nias-idash-ava-txt" style="background:<?php echo esc_attr(nias_idash_soft($color)); ?>;color:<?php echo esc_attr($color); ?>"><?php echo esc_html(nias_idash_initials($s['name'])); ?></div>
                        <div class="nias-idash-srow-main">
                            <div class="nias-idash-srow-name"><?php echo esc_html($s['name']); ?></div>
                            <div class="nias-idash-srow-sub"><?php echo esc_html($s['course']); ?></div>
                        </div>
                        <div class="nias-idash-srow-date"><?php echo esc_html($s['date']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

/** Recent certificates card (only rendered when the cert feature is on). */
function nias_instructor_dashboard_certs($d)
{
    ob_start(); ?>
    <section class="nias-idash-card">
        <div class="nias-idash-card-head">
            <div class="nias-idash-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#48af3b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 13.5L7 22l5-3 5 3-1-8.5M18 8a6 6 0 1 1-12 0 6 6 0 0 1 12 0z"/></svg>
                <span><?php echo esc_html__('گواهینامه‌های اخیر', 'nias-course-widget'); ?></span>
            </div>
        </div>
        <div class="nias-idash-rows">
            <?php foreach ($d['recentCerts'] as $c) : ?>
                <div class="nias-idash-srow">
                    <div class="nias-idash-cert-ic">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="#48af3b" stroke="none"><path d="M12 2l2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg>
                    </div>
                    <div class="nias-idash-srow-main">
                        <div class="nias-idash-srow-name"><?php echo esc_html($c['name']); ?></div>
                        <div class="nias-idash-srow-sub"><?php echo esc_html($c['course']); ?></div>
                    </div>
                    <div class="nias-idash-srow-date"><?php echo esc_html($c['date']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/** Recent reviews card (only rendered when reviews exist). */
function nias_instructor_dashboard_reviews($d)
{
    ob_start(); ?>
    <section class="nias-idash-card">
        <div class="nias-idash-card-head">
            <div class="nias-idash-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/></svg>
                <span><?php echo esc_html__('نظرات اخیر دوره‌ها', 'nias-course-widget'); ?></span>
            </div>
        </div>
        <div class="nias-idash-reviews">
            <?php foreach ($d['reviews'] as $i => $r) :
                $color = nias_idash_palette($i); ?>
                <div class="nias-idash-review">
                    <div class="nias-idash-review-top">
                        <div class="nias-idash-ava-txt" style="background:<?php echo esc_attr(nias_idash_soft($color)); ?>;color:<?php echo esc_attr($color); ?>"><?php echo esc_html(nias_idash_initials($r['name'])); ?></div>
                        <div class="nias-idash-srow-main">
                            <div class="nias-idash-srow-name"><?php echo esc_html($r['name']); ?></div>
                            <div class="nias-idash-srow-sub"><?php echo esc_html($r['course']); ?> · <?php echo esc_html($r['date']); ?></div>
                        </div>
                        <?php if ($r['rating'] > 0) : ?>
                            <div class="nias-idash-review-rate"><svg width="13" height="13" viewBox="0 0 24 24" fill="#d39a2b" stroke="none"><path d="M12 2l2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg><?php echo esc_html(nias_fa_digits($r['rating'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['text'] !== '') : ?>
                        <div class="nias-idash-review-text"><?php echo esc_html($r['text']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/** Small progressive-enhancement JS for the chart toggle + course filter. */
function nias_instructor_dashboard_inline_js()
{
    ob_start(); ?>
    <script>
    (function () {
        document.querySelectorAll('.nias-idash').forEach(function (root) {
            if (root.dataset.niasInit) return;
            root.dataset.niasInit = '1';

            // Course filter chips.
            var fbar = root.querySelector('[data-course-filter]');
            if (fbar) {
                fbar.addEventListener('click', function (e) {
                    var btn = e.target.closest('.nias-idash-chip');
                    if (!btn) return;
                    fbar.querySelectorAll('.nias-idash-chip').forEach(function (b) { b.classList.remove('is-on'); });
                    btn.classList.add('is-on');
                    var f = btn.dataset.filter;
                    root.querySelectorAll('.nias-idash-course').forEach(function (c) {
                        c.style.display = (f === 'all' || c.dataset.status === f) ? '' : 'none';
                    });
                });
            }

            // Chart 12/6 month toggle.
            var tbar = root.querySelector('[data-chart-toggle]');
            if (tbar) {
                var fa = function (s) { var m = '۰۱۲۳۴۵۶۷۸۹'; return String(s).replace(/[0-9]/g, function (d) { return m[+d]; }); };
                var money = function (n) { return fa(Math.round(n).toLocaleString('en-US').replace(/,/g, '٬')); };
                var totalEl = root.querySelector('[data-total]');
                var wordEl = root.querySelector('[data-period-word]');
                tbar.addEventListener('click', function (e) {
                    var btn = e.target.closest('.nias-idash-seg-btn');
                    if (!btn) return;
                    tbar.querySelectorAll('.nias-idash-seg-btn').forEach(function (b) { b.classList.remove('is-on'); });
                    btn.classList.add('is-on');
                    var range = parseInt(btn.dataset.range, 10);
                    var cols = root.querySelectorAll('.nias-idash-bar-col');
                    var total = cols.length, sum = 0;
                    cols.forEach(function (c, i) {
                        var shown = (i >= total - range);
                        c.style.display = shown ? '' : 'none';
                        if (shown) sum += parseFloat(c.dataset.val || '0');
                    });
                    if (totalEl) totalEl.textContent = money(sum);
                    if (wordEl) wordEl.textContent = (range === 12 ? '۱۲ ماه' : '۶ ماه');
                });
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Front-end placement (shortcode + auto-append on the chosen page)
 * ---------------------------------------------------------------------- */

/** Gate + access notices shared by the shortcode and the auto-append. */
function nias_instructor_dashboard_output()
{
    if (!function_exists('nias_instructors_enabled') || !nias_instructors_enabled()) {
        return '';
    }

    if (!is_user_logged_in()) {
        return '<div class="nias-idash nias-idash-gate" dir="rtl">' . nias_instructor_dashboard_styles()
            . '<div class="nias-idash-gate-box">' . esc_html__('برای مشاهدهٔ پیشخوان مدرس ابتدا وارد شوید.', 'nias-course-widget') . '</div></div>';
    }

    $user_id       = get_current_user_id();
    $is_instructor = function_exists('nias_user_is_instructor') && nias_user_is_instructor($user_id);
    if (!$is_instructor && !current_user_can('manage_options')) {
        return '<div class="nias-idash nias-idash-gate" dir="rtl">' . nias_instructor_dashboard_styles()
            . '<div class="nias-idash-gate-box">' . esc_html__('این صفحه مخصوص مدرسین است.', 'nias-course-widget') . '</div></div>';
    }

    return nias_instructor_dashboard_render($user_id);
}

add_shortcode('nias_instructor_dashboard', 'nias_instructor_dashboard_shortcode');
function nias_instructor_dashboard_shortcode($atts)
{
    return nias_instructor_dashboard_output();
}

/**
 * Auto-append the dashboard to the page selected in the settings, unless the
 * shortcode is already placed on that page.
 */
add_filter('the_content', 'nias_instructor_dashboard_auto_append', 20);
function nias_instructor_dashboard_auto_append($content)
{
    if (is_admin() || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (!function_exists('nias_instructors_enabled') || !nias_instructors_enabled()) {
        return $content;
    }
    $page_id = nias_instructor_dashboard_page_id();
    if (!$page_id || get_the_ID() !== $page_id) {
        return $content;
    }
    if (has_shortcode($content, 'nias_instructor_dashboard')) {
        return $content; // author placed it manually
    }
    return $content . nias_instructor_dashboard_output();
}

/* -------------------------------------------------------------------------
 * Styles
 * ---------------------------------------------------------------------- */

/** Scoped panel styles (printed once per request). */
function nias_instructor_dashboard_styles()
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    $font = function_exists('nias_course_font_face_css') ? nias_course_font_face_css() : '';
    ob_start(); ?>
    <style>
        <?php echo $font; ?>
        .nias-idash{font-family:'Vazirmatn',system-ui,-apple-system,sans-serif;background:#eef1f4;color:#2b363c;border-radius:18px;padding:22px;direction:rtl;text-align:right;-webkit-font-smoothing:antialiased;line-height:1.7;box-sizing:border-box}
        .nias-idash *{box-sizing:border-box}
        .nias-idash svg{flex:none}
        .nias-idash a{text-decoration:none}
        .nias-idash-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid #e9ecef;border-radius:16px;padding:14px 18px;margin-bottom:18px}
        .nias-idash-head-id{display:flex;align-items:center;gap:12px}
        .nias-idash-logo{width:40px;height:40px;border-radius:12px;background:#3858e9;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(56,88,233,.32)}
        .nias-idash-title{font-size:17px;font-weight:800;color:#1f2a30}
        .nias-idash-sub{font-size:12px;color:#9aa4a9;font-weight:500;margin-top:2px}
        .nias-idash-user{display:flex;align-items:center;gap:11px}
        .nias-idash-user-meta{text-align:left}
        .nias-idash-user-name{font-size:13.5px;font-weight:700;color:#1f2a30}
        .nias-idash-user-role{font-size:11px;color:#9aa4a9}
        .nias-idash-avatar img{width:40px;height:40px;border-radius:12px;display:block}
        .nias-idash-greet h2{margin:0 0 5px;font-size:22px;font-weight:800;color:#1f2a30}
        .nias-idash-greet p{margin:0 0 18px;font-size:14px;color:#5b666c}
        .nias-idash-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
        .nias-idash-kpi{background:#fff;border:1px solid #e9ecef;border-radius:16px;box-shadow:0 6px 22px rgba(31,42,48,.05);padding:17px 18px}
        .nias-idash-kpi-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px}
        .nias-idash-kpi-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center}
        .nias-idash-pill{display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:700;padding:4px 8px;border-radius:99px}
        .nias-idash-pill-up{color:#2f7a24;background:rgba(72,175,59,.10)}
        .nias-idash-pill-down{color:#b42318;background:rgba(224,86,86,.10)}
        .nias-idash-pill-warn{color:#946f23;background:#fff8ec}
        .nias-idash-pill-mute{color:#7b868a;background:#eef1f4}
        .nias-idash-kpi-label{font-size:13px;color:#7b868a;font-weight:500;margin-bottom:4px}
        .nias-idash-kpi-val{font-size:22px;font-weight:800;color:#1f2a30}
        .nias-idash-kpi-val span{font-size:13px;font-weight:600;color:#9aa4a9}
        .nias-idash-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:16px;align-items:start}
        .nias-idash-col{display:flex;flex-direction:column;gap:16px;min-width:0}
        .nias-idash-card{background:#fff;border:1px solid #e9ecef;border-radius:18px;box-shadow:0 6px 22px rgba(31,42,48,.05);padding:20px 22px}
        .nias-idash-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
        .nias-idash-card-title{font-size:16px;font-weight:700;color:#1f2a30}
        .nias-idash-side-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;color:#1f2a30}
        .nias-idash-chart-head{align-items:flex-start}
        .nias-idash-chart-total{display:flex;align-items:baseline;gap:8px;margin-top:7px}
        .nias-idash-chart-num{font-size:23px;font-weight:800;color:#1f2a30}
        .nias-idash-chart-cap{font-size:13px;color:#9aa4a9}
        .nias-idash-seg{display:flex;gap:4px;background:#f1f4f6;padding:4px;border-radius:11px}
        .nias-idash-seg-btn{border:none;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px;background:transparent;color:#7b868a}
        .nias-idash-seg-btn.is-on{background:#fff;color:#1f2a30;box-shadow:0 1px 3px rgba(31,42,48,.08)}
        .nias-idash-bars{display:flex;align-items:flex-end;gap:8px;height:188px;padding-top:6px}
        .nias-idash-bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;justify-content:flex-end;min-width:0}
        .nias-idash-bar-val{font-size:11px;font-weight:800;color:#3858e9;white-space:nowrap}
        .nias-idash-bar{width:100%;max-width:34px;border-radius:7px 7px 4px 4px;background:rgba(56,88,233,.16)}
        .nias-idash-bar.is-active{background:#3858e9}
        .nias-idash-bar-lbl{font-size:11px;color:#9aa4a9;font-weight:500}
        .nias-idash-chips{display:flex;gap:7px;flex-wrap:wrap}
        .nias-idash-chip{border:1px solid #e1e6e9;background:#fff;color:#5b666c;font-family:inherit;font-size:12.5px;font-weight:600;padding:6px 13px;border-radius:99px;cursor:pointer}
        .nias-idash-chip.is-on{border-color:#3858e9;background:rgba(56,88,233,.08);color:#3858e9}
        .nias-idash-chip span{opacity:.7}
        .nias-idash-courses{display:flex;flex-direction:column;gap:11px}
        .nias-idash-course{display:flex;align-items:center;gap:14px;padding:14px;border:1px solid #eef1f4;border-radius:14px}
        .nias-idash-course:hover{border-color:#e1e6e9;background:#f8fafb}
        .nias-idash-course-cover{width:50px;height:50px;border-radius:13px;flex:none;display:flex;align-items:center;justify-content:center}
        .nias-idash-course-main{flex:1;min-width:0}
        .nias-idash-course-titlerow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px}
        .nias-idash-course-title{font-size:14.5px;font-weight:700;color:#1f2a30}
        .nias-idash-course-title:hover{color:#3858e9}
        .nias-idash-status{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px}
        .nias-idash-status-pub{background:rgba(72,175,59,.10);color:#2f7a24}
        .nias-idash-status-draft{background:#fff8ec;color:#946f23}
        .nias-idash-course-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px;color:#7b868a}
        .nias-idash-course-meta span{display:inline-flex;align-items:center;gap:4px}
        .nias-idash-course-rate{color:#946f23}
        .nias-idash-course-rev{text-align:left;flex:none}
        .nias-idash-course-rev-num{font-size:14px;font-weight:800;color:#1f2a30}
        .nias-idash-course-rev-cap{font-size:11px;color:#9aa4a9}
        .nias-idash-manage{flex:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:9px;border:1px solid #e1e6e9;background:#fff;color:#3858e9;font-size:12.5px;font-weight:700;cursor:pointer}
        .nias-idash-manage:hover{background:rgba(56,88,233,.07);border-color:#3858e9;color:#3858e9}
        .nias-idash-rows{display:flex;flex-direction:column;gap:2px}
        .nias-idash-srow{display:flex;align-items:center;gap:11px;padding:10px 4px;border-bottom:1px solid #eef1f4}
        .nias-idash-srow:last-child{border-bottom:none}
        .nias-idash-ava-txt{width:34px;height:34px;border-radius:99px;flex:none;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
        .nias-idash-cert-ic{width:34px;height:34px;border-radius:99px;background:rgba(72,175,59,.12);display:flex;align-items:center;justify-content:center;flex:none}
        .nias-idash-srow-main{flex:1;min-width:0}
        .nias-idash-srow-name{font-size:13px;font-weight:700;color:#1f2a30}
        .nias-idash-srow-sub{font-size:11.5px;color:#9aa4a9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .nias-idash-srow-date{font-size:11.5px;color:#7b868a;flex:none}
        .nias-idash-reviews{display:flex;flex-direction:column;gap:12px}
        .nias-idash-review{padding:13px;border-radius:12px;border:1px solid #eef1f4}
        .nias-idash-review-top{display:flex;align-items:center;gap:9px;margin-bottom:8px}
        .nias-idash-review-rate{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:#946f23;flex:none}
        .nias-idash-review-text{font-size:13px;color:#2b363c;line-height:1.75}
        .nias-idash-empty{color:#9aa6b6;font-size:13px;padding:14px 2px}
        .nias-idash-foot{text-align:center;color:#b9c1c6;font-size:11.5px;margin-top:22px}
        .nias-idash-gate{padding:30px}
        .nias-idash-gate-box{background:#fff;border:1px solid #e9ecef;border-radius:14px;padding:22px;text-align:center;font-size:14px;font-weight:600;color:#5b666c}
        @media (max-width:900px){
            .nias-idash-kpis{grid-template-columns:repeat(2,1fr)}
            .nias-idash-grid{grid-template-columns:1fr}
        }
        @media (max-width:560px){
            .nias-idash{padding:14px}
            .nias-idash-kpis{grid-template-columns:1fr}
            .nias-idash-course{flex-wrap:wrap}
            .nias-idash-course-rev,.nias-idash-manage{margin-top:4px}
        }
    </style>
    <?php
    return ob_get_clean();
}
