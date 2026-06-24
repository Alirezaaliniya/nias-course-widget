<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add new endpoint
add_action('init', 'nias_course_add_endpoint');
function nias_course_add_endpoint() {

    $endpoint = get_option('nias_course_endpoint', 'my-courses');
    add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
}

// Add new menu item to my account menu
add_filter('woocommerce_account_menu_items', 'nias_course_add_menu_item');
function nias_course_add_menu_item($items) {
    if (!is_user_logged_in()){
        return $items;
    }
    
    $endpoint = get_option('nias_course_endpoint', 'my-courses');
    $new_items = array();
    foreach ($items as $key => $item) {
        $new_items[$key] = $item;
        if ($key === 'dashboard') {
            $new_items[$endpoint] = 'دوره های من';
        }
    }
    return $new_items;
}

// Add content to the new endpoint
add_action('woocommerce_account_my-courses_endpoint', 'nias_course_endpoint_content');
function nias_course_endpoint_content() {
    if (!is_user_logged_in()){
        return;
    }
    
    echo '<h2>دوره های من</h2>';
    
    // دریافت کاربر فعلی
    $current_user = wp_get_current_user();
    if (!is_user_logged_in()) {
        echo '<p>لطفا برای مشاهده دوره‌های خود وارد شوید.</p>';
        return;
    }

    // حالت مدرن: نمایش دوره‌های خریداری‌شده به‌صورت کارتی (جایگزین آکاردئون).
    if (nias_modern_account_enabled()) {
        echo nias_course_render_cards(nias_course_get_purchased_course_ids($current_user->ID));
        return;
    }

    // دریافت سفارش‌های تکمیل شده کاربر
    $args = [
        'customer_id' => $current_user->ID,
        'limit'       => -1,
        'status'      => 'wc-completed',
    ];
    $orders = wc_get_orders($args);
    
    // ذخیره محصولات خریداری شده در یک آرایه
    $purchased_courses = [];
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!in_array($product_id, $purchased_courses)) {
                $purchased_courses[] = $product_id;
            }
        }
    }
    
    if (empty($purchased_courses)) {
        echo '<p>شما هنوز هیچ دوره‌ای خریداری نکرده‌اید.</p>';
        return;
    }
    
    // نمایش هر دوره و محتوای آن
    foreach ($purchased_courses as $course_id) {
        $course = get_post($course_id);
        $sections = carbon_get_post_meta($course_id, 'course_sections');
        
        if ($course && $course->post_type === 'product' && !empty($sections)) {
            echo '<div class="nias-purchased-course">';
            echo '<h3>' . esc_html($course->post_title) . '</h3>';
            
            // تنظیم post ID برای تابع show_course_account
            global $post;
            $original_post = $post;
            $post = $course;
            setup_postdata($post);
            
            // فراخوانی تابع نمایش محتوای دوره
            nias_course_display_content($course_id);
            
            // بازگردانی پست اصلی
            $post = $original_post;
            wp_reset_postdata();
            echo '</div>';
        }
    }
}

// تابع نمایش محتوای دوره (کپی از show_course_account)
function nias_course_display_content($product_id) {
    // بارگذاری CSS و JS
    wp_register_script('nscourse-js', plugin_dir_url(__DIR__) . 'assets/niascourse.js', array('jquery'), false);
    wp_enqueue_script('nscourse-js');
    wp_enqueue_style('nscourse-css', plugin_dir_url(__DIR__) . 'assets/niascourse.css');

    // بررسی خرید دوره
    $bought_course = false;
    $current_user = wp_get_current_user();
    
    if (is_user_logged_in() && !empty($current_user->ID) && !empty($product_id)) {
        // دسترسی فقط با سفارشِ «تکمیل‌شده» داده می‌شود؛ سفارش‌های پرداخت‌شدهٔ
        // تکمیل‌نشده یا سفارشی که از حالت تکمیل‌شده خارج شود دسترسی نمی‌دهند.
        $args = [
            'customer_id' => $current_user->ID,
            'limit'       => -1,
            'status'      => 'wc-completed',
        ];
        $orders = wc_get_orders($args);
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id) {
                    $bought_course = true;
                    break 2;
                }
            }
        }
    }

    $sections = carbon_get_post_meta($product_id, 'course_sections');
    
    // دریافت تنظیمات سراسری
    $default_icon_url = carbon_get_theme_option('default_section_icon');
    $open_close_default = carbon_get_theme_option('sections_open_close_default') ?: 'yes';
    $private_content_text = carbon_get_theme_option('private_content_text') ?: 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید';

    if ($sections) { ?>
        <div id="nias_course_sections">
            <?php foreach ($sections as $index => $section) { ?>
                <div class="nias_course_section">
                    <div class="section_header toggle_section">
                        <?php
                        // مدیریت آیکون فصل
                        if (!empty($section['section_icon'])) {
                            $icon = $section['section_icon'][0];
                            $icon_url = '';
                            if ($icon['icon_type'] === 'upload' && !empty($icon['icon_upload'])) {
                                $icon_url = $icon['icon_upload'];
                            } elseif ($icon['icon_type'] === 'url' && !empty($icon['icon_url'])) {
                                $icon_url = $icon['icon_url'];
                            }
                            if ($icon_url) {
                                echo '<img width="50" height="50" src="' . esc_url($icon_url) . '">';
                            }
                        } else { ?>
                            <img width="50" height="50" src="<?php echo esc_url($default_icon_url); ?>" alt="تصویر فصل" aria-hidden="true">
                        <?php } ?>
                        <h3 class="section_title"><?php echo esc_html($section['section_title']); ?></h3>
                        <span class="section_subtitle"><?php echo esc_html($section['section_subtitle']); ?></span>
                        <i class="nsarrowicon nias-course-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </i>
                    </div>
                    <div class="section_content" style="display: <?php echo ($open_close_default == 'yes') ? 'none' : 'block'; ?>">
                        <?php if (!empty($section['lessons'])) { ?>
                            <ul class="lessons_list">
                                <?php foreach ($section['lessons'] as $lesson) { ?>
                                    <li class="lesson_item">
                                        <div class="lesson_header toggle_lesson">
                                            <div class="nias-right-head">
                                                <?php
                                                // مدیریت آیکون درس
                                                if (!empty($lesson['lesson_icon'])) {
                                                    $lesson_icon = $lesson['lesson_icon'][0];
                                                    $lesson_icon_url = '';
                                                    if ($lesson_icon['icon_type'] === 'upload' && !empty($lesson_icon['icon_upload'])) {
                                                        $lesson_icon_url = $lesson_icon['icon_upload'];
                                                    } elseif ($lesson_icon['icon_type'] === 'url' && !empty($lesson_icon['icon_url'])) {
                                                        $lesson_icon_url = $lesson_icon['icon_url'];
                                                    }
                                                    if ($lesson_icon_url) {
                                                        echo '<img src="' . esc_url($lesson_icon_url) . '" alt="' . esc_attr($lesson['lesson_title']) . '" />';
                                                    }
                                                } else { ?>
                                                    <i class="ns-icon-wrapper nias-course-icon">
                                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                        </svg>
                                                    </i>
                                                <?php } ?>
                                                <div class="nias-lesson-text">
                                                    <h4 class="lesson_title"><?php echo esc_html($lesson['lesson_title']); ?></h4>
                                                    <span class="lesson_label"><?php echo esc_html($lesson['lesson_label']); ?></span>
                                                </div>
                                            </div>
                                            <div class="nias-left-head">
                                                <?php
                                                // مدیریت ویدیوی پیش‌نمایش
                                                // پیش‌نمایش درسِ خصوصی فقط به خریداران نشان داده می‌شود
                                                // مگر اینکه قفلِ پیش‌نمایش در تنظیمات خاموش باشد.
                                                if (!empty($lesson['lesson_preview_video']) && (empty($lesson['lesson_private']) || $bought_course || !nias_course_lock_part('preview'))) {
                                                    $preview_video = $lesson['lesson_preview_video'][0];
                                                    $video_url = '';
                                                    if ($preview_video['video_type'] === 'upload' && !empty($preview_video['video_upload'])) {
                                                        $video_url = $preview_video['video_upload'];
                                                    } elseif ($preview_video['video_type'] === 'url' && !empty($preview_video['video_url'])) {
                                                        $video_url = $preview_video['video_url'];
                                                    }
                                                    if ($video_url) { ?>
                                                        <a class="nias-preview-tag" target="_blank" href="<?php echo esc_url($video_url); ?>">
                                                            <i class="nspreviewicon nias-course-icon">
                                                                <svg width="25" height="25" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                    <path d="M8 5v14l11-7z" fill="currentColor"/>
                                                                </svg>
                                                            </i>
                                                            <span class="nsspanpreviewtext">پیش نمایش</span>
                                                        </a>
                                                    <?php }
                                                }

                                                // مدیریت دانلود درس و محتوای خصوصی
                                                if ($lesson['lesson_private']) {
                                                    if ($bought_course || !nias_course_lock_part('attachments')) {
                                                        if (!empty($lesson['lesson_download'])) {
                                                            $download = $lesson['lesson_download'][0];
                                                            $download_url = '';
                                                            if ($download['file_type'] === 'upload' && !empty($download['file_upload'])) {
                                                                $download_url = $download['file_upload'];
                                                            } elseif ($download['file_type'] === 'url' && !empty($download['file_url'])) {
                                                                $download_url = $download['file_url'];
                                                            }
                                                            if ($download_url) { ?>
                                                                <a class="nsdownload-button nias-course-icon" download target="_blank" href="<?php echo esc_url($download_url); ?>">
                                                                    <i>
                                                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                                        </svg>
                                                                    </i>
                                                                </a>
                                                        <?php }
                                                        }
                                                    } else { ?>
                                                        <div class="ns-private-lesson">
                                                            <i class="ns-private-icon nias-course-icon">
                                                                <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M16.0596 9.34865C15.0136 9.10565 13.7526 8.99465 12.2496 8.99465C10.7466 8.99465 9.4856 9.10565 8.4396 9.34865V7.91665C8.4726 5.84265 10.1236 4.19765 12.1996 4.17065C13.2306 4.14265 14.1816 4.54265 14.9106 5.25365C15.6386 5.96465 16.0476 6.91565 16.0596 7.92465V9.34865ZM12.9996 17.0477C12.9996 17.4617 12.6636 17.7977 12.2496 17.7977C11.8356 17.7977 11.4996 17.4617 11.4996 17.0477V14.8267C11.4996 14.4127 11.8356 14.0767 12.2496 14.0767C12.6636 14.0767 12.9996 14.4127 12.9996 14.8267V17.0477ZM17.5596 9.86165V7.91565C17.5416 6.49665 16.9736 5.16965 15.9566 4.17965C14.9416 3.18965 13.5876 2.69865 12.1796 2.67065C9.2886 2.70765 6.9866 5.00165 6.9396 7.90565V9.86265C4.9056 10.8327 4.0896 12.6837 4.0896 15.7657C4.0896 20.7657 6.2256 22.5377 12.2496 22.5377C18.2746 22.5377 20.4106 20.7657 20.4106 15.7657C20.4106 12.6837 19.5936 10.8317 17.5596 9.86165Z" fill="#737373" />
                                                                </svg>
                                                            </i>
                                                            <span>خصوصی</span>
                                                        </div>
                                                        <?php }
                                                } else {
                                                    if (!empty($lesson['lesson_download'])) {
                                                        $download = $lesson['lesson_download'][0];
                                                        $download_url = '';
                                                        if ($download['file_type'] === 'upload' && !empty($download['file_upload'])) {
                                                            $download_url = $download['file_upload'];
                                                        } elseif ($download['file_type'] === 'url' && !empty($download['file_url'])) {
                                                            $download_url = $download['file_url'];
                                                        }
                                                        if ($download_url) { ?>
                                                            <a target="_blank" href="<?php echo esc_url($download_url); ?>">
                                                                <div class="nsdownload-button nias-course-icon">
                                                                    <i>
                                                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                                        </svg>
                                                                    </i>
                                                                </div>
                                                            </a>
                                                <?php }
                                                    }
                                                } ?>
                                                <i class="nsarrowicon nias-course-icon">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </i>
                                            </div>
                                        </div>
                                        <div class="lesson_content">
                                            <?php
                                            if ($lesson['lesson_private']) {
                                                if ($bought_course || !nias_course_lock_part('content')) {
                                                    $content = $lesson['lesson_content'];
                                                    if (preg_match('/\[embed\](.+?)\[\/embed\]/', $content, $matches)) {
                                                        // Extract URL from embed shortcode
                                                        $url = $matches[1];
                                                        echo nias_handle_video_url($url);
                                                    } elseif (filter_var($content, FILTER_VALIDATE_URL)) {
                                                        // If content is just a URL
                                                        if (strpos($content, '.mp3') !== false || strpos($content, '.wav') !== false) {
                                                            echo '<audio controls><source src="' . esc_url(trim($content)) . '" type="audio/mpeg">Your browser does not support the audio element.</audio>';
                                                        } else {
                                                            echo nias_handle_video_url($content);
                                                        }
                                                    } elseif (strpos($content, '<iframe') !== false) {
                                                        echo '<div class="iframe-wrapper">' . $content . '</div>';
                                                    } else {
                                                        echo wpautop($content);
                                                    }
                                                } else {
                                                    echo wpautop($private_content_text);
                                                }
                                            } else {
                                                $content = $lesson['lesson_content'];
                                                if (preg_match('/\[embed\](.+?)\[\/embed\]/', $content, $matches)) {
                                                    $url = $matches[1];
                                                    echo nias_handle_video_url($url);
                                                } elseif (filter_var($content, FILTER_VALIDATE_URL)) {
                                                    if (strpos($content, '.mp3') !== false || strpos($content, '.wav') !== false) {
                                                        echo '<audio controls><source src="' . esc_url(trim($content)) . '" type="audio/mpeg">Your browser does not support the audio element.</audio>';
                                                    } else {
                                                        echo nias_handle_video_url($content);
                                                    }
                                                } elseif (strpos($content, '<iframe') !== false) {
                                                    echo '<div class="iframe-wrapper">' . $content . '</div>';
                                                } else {
                                                    echo wpautop($content);
                                                }
                                            }
                                            ?>
                                        </div>
                                    </li>
                                <?php } ?>
                            </ul>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php }
}

// Add settings field to WooCommerce Advanced tab
add_filter('woocommerce_get_settings_advanced', 'nias_course_add_settings_field', 10, 2);
function nias_course_add_settings_field($settings, $current_section) {
    $settings[] = array(
        'title' => __('نقاط پایانی دوره', 'woocommerce'),
        'type'  => 'title',
        'id'    => 'nias_course_settings'
    );
    
    $settings[] = array(
        'title'    => __('نقاط پایانی دوره', 'woocommerce'),
        'desc'     => __('نقطه پایانی صفحه دوره‌های من', 'woocommerce'),
        'id'       => 'nias_course_endpoint',
        'type'     => 'text',
        'default'  => 'my-courses',
        'desc_tip' => __('این مقدار در URL حساب کاربری استفاده خواهد شد', 'woocommerce'),
    );

    $settings[] = array(
        'type' => 'sectionend',
        'id'   => 'nias_course_settings'
    );
    
    return $settings;
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, function() {
    if (function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_course_account_display') === 'on') {
        nias_course_add_endpoint();
    }
    flush_rewrite_rules();
});

// Flush rewrite rules on plugin deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

// Add this function at the top
add_filter('wp_kses_allowed_html', 'nias_course_allowed_html', 10, 2);
function nias_course_allowed_html($allowed_tags, $context) {
    if ($context === 'post') {
        $allowed_tags['iframe'] = array(
            'src'             => true,
            'width'          => true,
            'height'         => true,
            'frameborder'    => true,
            'allowfullscreen' => true,
            'allow'          => true,
            'style'          => true,
            'class'          => true,
        );
        $allowed_tags['audio'] = array(
            'autoplay' => true,
            'controls' => true,
            'loop'     => true,
            'muted'    => true,
            'preload'  => true,
            'src'      => true,
        );
        $allowed_tags['source'] = array(
            'src'  => true,
            'type' => true,
        );
    }
    return $allowed_tags;
}

function nias_handle_video_url($url) {
    $video_extensions = array('.mp4', '.webm', '.ogg');
    foreach ($video_extensions as $ext) {
        if (strpos($url, $ext) !== false) {
            return '<video width="100%" controls><source src="' . esc_url(trim($url)) . '" type="video/' . str_replace('.', '', $ext) . '">Your browser does not support the video tag.</video>';
        }
    }
    return '<div class="video-container"><iframe src="' . esc_url(trim($url)) . '" frameborder="0" allowfullscreen></iframe></div>';
}

/* -------------------------------------------------------------------------
 * Modern card view for purchased courses
 * ---------------------------------------------------------------------- */

/** Whether the modern (card) display is enabled for the account page. */
function nias_modern_account_enabled()
{
    return function_exists('carbon_get_theme_option') && carbon_get_theme_option('nias_modern_account') === 'on';
}

/**
 * Distinct product IDs purchased by a user (completed orders).
 *
 * @param int $user_id
 * @return int[]
 */
function nias_course_get_purchased_course_ids($user_id)
{
    $ids = array();
    $user_id = intval($user_id);
    if (!$user_id || !function_exists('wc_get_orders')) {
        return $ids;
    }
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'limit'       => -1,
        'status'      => 'wc-completed',
    ));
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if ($pid && !in_array($pid, $ids, true)) {
                $ids[] = (int) $pid;
            }
        }
    }
    return $ids;
}

/** URL of a course's display page (the focused modern course view). */
function nias_course_modern_view_url($product_id)
{
    $qv = defined('NIAS_MODERN_QV') ? NIAS_MODERN_QV : 'nias_modern';
    return add_query_arg($qv, '1', get_permalink(intval($product_id)));
}

/**
 * Render purchased courses as a responsive card grid. Each card links to that
 * course's display page. Only products that actually hold curriculum sections
 * are shown.
 *
 * @param int[] $course_ids
 * @return string
 */
function nias_course_render_cards($course_ids)
{
    $course_ids = array_filter(array_map('intval', (array) $course_ids));

    $cards = array();
    foreach ($course_ids as $cid) {
        $course = get_post($cid);
        if (!$course || $course->post_type !== 'product') {
            continue;
        }
        $sections = carbon_get_post_meta($cid, 'course_sections');
        if (empty($sections) || !is_array($sections)) {
            continue;
        }
        $cards[] = array('id' => $cid, 'post' => $course, 'sections' => $sections);
    }

    if (empty($cards)) {
        return '<p class="nias-cc-empty">' . esc_html__('شما هنوز هیچ دوره‌ای خریداری نکرده‌اید.', 'nias-course-widget') . '</p>';
    }

    ob_start();
    ?>
    <div class="nias-course-cards" dir="rtl">
        <?php foreach ($cards as $c) :
            $cid   = $c['id'];
            $title = $c['post']->post_title;
            $url   = nias_course_modern_view_url($cid);
            $thumb = get_the_post_thumbnail_url($cid, 'large');
            if (!$thumb && function_exists('wc_placeholder_img_src')) {
                $thumb = wc_placeholder_img_src('large');
            }
            $sec_count = count($c['sections']);
            $les_count = 0;
            foreach ($c['sections'] as $s) {
                if (!empty($s['lessons']) && is_array($s['lessons'])) {
                    $les_count += count($s['lessons']);
                }
            }
            ?>
            <a class="nias-course-card" href="<?php echo esc_url($url); ?>">
                <div class="nias-cc-thumb">
                    <?php if ($thumb) : ?><img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy"><?php endif; ?>
                    <span class="nias-cc-play">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 4 20 12 6 20 6 4" fill="#fff" stroke="none"/></svg>
                    </span>
                </div>
                <div class="nias-cc-body">
                    <h3 class="nias-cc-title"><?php echo esc_html($title); ?></h3>
                    <div class="nias-cc-meta">
                        <span class="nias-cc-chip">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <?php echo esc_html(number_format_i18n($sec_count) . ' ' . __('سرفصل', 'nias-course-widget')); ?>
                        </span>
                        <span class="nias-cc-chip">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>
                            <?php echo esc_html(number_format_i18n($les_count) . ' ' . __('جلسه', 'nias-course-widget')); ?>
                        </span>
                    </div>
                    <span class="nias-cc-btn">
                        <?php echo esc_html__('ورود به دوره', 'nias-course-widget'); ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <style>
        .nias-course-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px;margin:14px 0}
        .nias-course-cards *{box-sizing:border-box}
        .nias-course-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e6e9ef;border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 1px 2px rgba(16,24,40,.04),0 10px 26px -20px rgba(16,24,40,.4);transition:transform .16s ease,box-shadow .16s ease}
        .nias-course-card:hover{transform:translateY(-4px);box-shadow:0 1px 2px rgba(16,24,40,.04),0 18px 36px -18px rgba(30,131,240,.55);border-color:#bcd6fb}
        .nias-cc-thumb{position:relative;aspect-ratio:16/9;background:#eef2f7;overflow:hidden}
        .nias-cc-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s ease}
        .nias-course-card:hover .nias-cc-thumb img{transform:scale(1.05)}
        .nias-cc-play{position:absolute;inset:0;margin:auto;width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(30,131,240,.92);box-shadow:0 8px 20px -6px rgba(30,131,240,.8);opacity:0;transition:opacity .16s ease}
        .nias-course-card:hover .nias-cc-play{opacity:1}
        .nias-cc-body{display:flex;flex-direction:column;gap:12px;padding:16px 16px 18px}
        .nias-cc-title{margin:0;font-size:16px;font-weight:800;line-height:1.7;color:#1f2a30;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .nias-cc-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto}
        .nias-cc-chip{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 10px;border-radius:8px;background:#eef4fe;color:#1e6fd6;font-size:12.5px;font-weight:700}
        .nias-cc-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:42px;border-radius:11px;background:linear-gradient(135deg,#1e83f0,#0e6fd6);color:#fff;font-size:14px;font-weight:800;margin-top:4px}
        .nias-course-card:hover .nias-cc-btn{background:linear-gradient(135deg,#1873d6,#0a62c0)}
        .nias-cc-empty{padding:16px;background:#f1f4f9;border-radius:12px;color:#475569;font-weight:600}
    </style>
    <?php
    return ob_get_clean();
}

/** Shortcode: purchased-course cards for the current user. */
add_shortcode('nias_purchased_courses', 'nias_purchased_courses_shortcode');
function nias_purchased_courses_shortcode($atts)
{
    if (!is_user_logged_in()) {
        return '<p class="nias-cc-empty">' . esc_html__('برای مشاهده دوره‌های خریداری‌شده وارد شوید.', 'nias-course-widget') . '</p>';
    }
    return nias_course_render_cards(nias_course_get_purchased_course_ids(get_current_user_id()));
}

// Hide 'my-courses' menu item if not enabled
add_filter('woocommerce_account_menu_items', 'nias_hide_my_courses_menu_item');
function nias_hide_my_courses_menu_item($items) {
    if (!is_user_logged_in()){
        return $items;
    }
    
    if (!function_exists('carbon_get_theme_option') || carbon_get_theme_option('nias_course_account_display') !== 'on') {
        // اگر گزینه فعال نبود، منوی دوره‌های من را با CSS مخفی کن
        add_action('wp_footer', function() {
            echo '<style>.woocommerce-MyAccount-navigation-link--my-courses { display: none !important; }</style>';
        });
        return $items;
    }
    
    $endpoint = get_option('nias_course_endpoint', 'my-courses');
    $new_items = array();
    foreach ($items as $key => $item) {
        $new_items[$key] = $item;
        if ($key === 'dashboard') {
            $new_items[$endpoint] = 'دوره های من';
        }
    }
    return $new_items;
}