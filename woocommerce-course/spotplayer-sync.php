<?php
// Ensure Carbon Fields is initialized
add_action('after_setup_theme', 'crb_load_carbon_fields');
function crb_load_carbon_fields() {
    \Carbon_Fields\Carbon_Fields::boot();
}

function nias_spotplayer_sync_handler() {
    // Increase PHP execution time limit
    set_time_limit(300); // 5 minutes
    
    // Check nonce and permissions
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Permission denied');
    }

    // Get the post ID and URL from the AJAX request
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

    if (!$post_id || !$url) {
        wp_send_json_error('Invalid parameters');
    }

    try {
        error_log('Starting Spot Player sync for post ID: ' . $post_id . ' and URL: ' . $url);
        
        // Configure wp_remote_get arguments with increased timeout
        $args = array(
            'timeout' => 60, // Increase timeout to 60 seconds
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Safari/537.36',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ),
            'httpversion' => '1.1',
            'blocking' => true
        );

        // First try with the original URL
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('WP Remote Get Error: ' . $error_message);
            throw new Exception('خطا در ارتباط با سرور: ' . $error_message);
        }

        $html = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        error_log('Response status code: ' . $status_code);
        error_log('HTML content length: ' . strlen($html));
        
        if ($status_code !== 200) {
            throw new Exception('خطا در ارتباط با سرور. کد خطا: ' . $status_code);
        }

        if (empty($html)) {
            throw new Exception('No content received from URL');
        }

        // Use DOMDocument to parse the HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        if (!empty($errors)) {
            error_log('HTML parsing errors: ' . print_r($errors, true));
        }
        
        $xpath = new DOMXPath($dom);

        // Initialize the sections array
        $sections = [];
        $current_section = null;
        $current_lessons = [];
        
        // Find all li elements
        $listItems = $xpath->query("//li");
        error_log('Found ' . $listItems->length . ' li elements');
        
        if (!$listItems || $listItems->length === 0) {
            throw new Exception('No course content found in the URL');
        }

        foreach ($listItems as $item) {
            $class = $item->getAttribute('class');
            error_log('Processing li element with class: ' . $class);
            
            if ($class === 'seg') {
                // If we have a previous section, save it
                if ($current_section !== null && !empty($current_lessons)) {
                    error_log('Saving section: ' . $current_section . ' with ' . count($current_lessons) . ' lessons');
                    $sections[] = array(
                        'section_title' => $current_section,
                        'section_subtitle' => '',
                        'section_icon' => array(),
                        'lessons' => array_map(function($lesson) {
                            return array(
                                'lesson_title' => $lesson['lesson_title'],
                                'lesson_icon' => array(),
                                'lesson_label' => '',
                                'lesson_preview_video' => array(),
                                'lesson_download' => array(),
                                'lesson_private' => true,
                                'lesson_content' => ''
                            );
                        }, $current_lessons)
                    );
                }
                
                // Get the section title from h2
                $h2 = $xpath->query(".//h2", $item)->item(0);
                $sectionTitle = $h2 ? trim($h2->textContent) : 'بدون عنوان';
                error_log('Found new section: ' . $sectionTitle);
                
                // Remove "(بزودی)" from title if present
                $sectionTitle = str_replace(['(بزودی)', '(بزودی) '], '', $sectionTitle);
                
                // Start new section
                $current_section = $sectionTitle;
                $current_lessons = [];
            } 
            elseif ($class === 'vid' && $current_section !== null) {
                // Get lesson title from a tag
                $a = $xpath->query(".//a", $item)->item(0);
                if ($a) {
                    $lessonTitle = trim($a->textContent);
                    $lessonUrl = $a->getAttribute('href');
                    error_log('Found lesson: ' . $lessonTitle . ' in section: ' . $current_section);
                    
                    $current_lessons[] = [
                        '_type' => 'lessons',
                        'section_title' => $current_section,
                        'lesson_title' => $lessonTitle,
                        'lesson_private' => true,
                        'lesson_label' => '',
                        'lesson_content' => '',
                        'lesson_preview_video' => [],
                        'lesson_download' => [],
                        'lesson_icon' => []
                    ];
                }
            }
        }
        
        // Add the last section if it exists
        if ($current_section !== null && !empty($current_lessons)) {
            error_log('Saving final section: ' . $current_section . ' with ' . count($current_lessons) . ' lessons');
            $sections[] = [
                'section_title' => $current_section,
                'section_subtitle' => '',
                'section_icon' => array(),
                'lessons' => array_map(function($lesson) {
                    return array(
                        'lesson_title' => $lesson['lesson_title'],
                        'lesson_icon' => array(),
                        'lesson_label' => '',
                        'lesson_preview_video' => array(),
                        'lesson_download' => array(),
                        'lesson_private' => true,
                        'lesson_content' => ''
                    );
                }, $current_lessons)
            ];
        }

        // Filter out empty sections or sections marked as "بزودی"
        $sections = array_filter($sections, function($section) {
            return !empty($section['lessons']) && 
                   stripos($section['section_title'], 'بزودی') === false;
        });

        // Reset array keys
        $sections = array_values($sections);

        if (empty($sections)) {
            throw new Exception('No valid sections found in the content');
        }

        error_log('Final sections array: ' . print_r($sections, true));

        // Before saving, ensure Carbon Fields is booted
        if (!class_exists('\Carbon_Fields\Carbon_Fields')) {
            throw new Exception('Carbon Fields is not loaded');
        }

        // Clear existing sections first
        carbon_set_post_meta($post_id, 'course_sections', array());
        
        // Now save the new sections
        foreach ($sections as $section) {
            $complex_value = array();
            
            // Add the section data
            $complex_value['section_title'] = $section['section_title'];
            $complex_value['section_subtitle'] = '';
            $complex_value['section_icon'] = array();
            
            // Add the lessons as a nested complex field
            $complex_value['lessons'] = array();
            foreach ($section['lessons'] as $lesson) {
                $complex_value['lessons'][] = array(
                    'lesson_title' => $lesson['lesson_title'],
                    'lesson_icon' => array(),
                    'lesson_label' => '',
                    'lesson_preview_video' => array(),
                    'lesson_download' => array(),
                    'lesson_private' => true,
                    'lesson_content' => ''
                );
            }
            
            // Add this section to the course_sections field
            $current_sections = carbon_get_post_meta($post_id, 'course_sections');
            if (!is_array($current_sections)) {
                $current_sections = array();
            }
            $current_sections[] = $complex_value;
            carbon_set_post_meta($post_id, 'course_sections', $current_sections);
        }

        // Verify the save
        $saved_data = carbon_get_post_meta($post_id, 'course_sections');
        error_log('Saved sections count: ' . count($saved_data));

        wp_send_json_success([
            'message' => 'همگام سازی با موفقیت انجام شد',
            'sections' => $sections,
            'saved_count' => count($saved_data)
        ]);

    } catch (Exception $e) {
        error_log('Spot Player Sync Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage(),
            'detail' => 'لطفاً مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید'
        ]);
    }
}

add_action('wp_ajax_nias_spotplayer_sync', 'nias_spotplayer_sync_handler');