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
        // بررسی تنظیمات تأیید دو طرفه
        $two_way_verification = carbon_get_theme_option('nias_two_way_verification');
        
        if ($two_way_verification === 'on') {
            // حالت تأیید دو طرفه فعال
            $wc_bought = wc_customer_bought_product($current_user->user_login, $current_user->ID, $product_id);
            $order_bought = false;
            
            // بررسی سفارش‌های تکمیل شده
            $args = [
                'customer_id' => $current_user->ID,
                'limit'       => -1,
                'status'      => 'wc-completed',
            ];
            $orders = wc_get_orders($args);
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ($item->get_product_id() == $product_id) {
                        $order_bought = true;
                        break 2;
                    }
                }
            }
            
            $bought_course = ($wc_bought || $order_bought);            
        } else {
            // حالت عادی
            if (wc_customer_bought_product($current_user->user_login, $current_user->ID, $product_id)) {
                $bought_course = true;
            } else {
                // بررسی سفارش‌های تکمیل شده
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
                                                if (!empty($lesson['lesson_preview_video'])) {
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
                                                    if ($bought_course) {
                                                        if (!empty($lesson['lesson_download'])) {
                                                            $download = $lesson['lesson_download'][0];
                                                            $download_url = '';
                                                            if ($download['file_type'] === 'upload' && !empty($download['file_upload'])) {
                                                                $download_url = $download['file_upload'];
                                                            } elseif ($download['file_type'] === 'url' && !empty($download['file_url'])) {
                                                                $download_url = $download['file_url'];
                                                            }
                                                            if ($download_url) { ?>
                                                                <a class="nsdownload-button nias-course-icon" target="_blank" href="<?php echo esc_url($download_url); ?>">
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
                                                if ($bought_course) {
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