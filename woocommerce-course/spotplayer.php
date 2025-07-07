<?php
function spotplayer_simple_outline_shortcode($atts) {
    $atts = shortcode_atts(array(
        'api_key' => '',
        'endpoint' => 'https://panel.spotplayer.ir/course/',
        'level' => '-1'
    ), $atts);

    if (empty($atts['api_key'])) {
        return '<p>کلید API وارد نشده است.</p>';
    }

    $response = wp_remote_get($atts['endpoint'], array(
        'headers' => array(
            '$API' => $atts['api_key'],
            '$LEVEL' => $atts['level']
        ),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return '<p>خطا در اتصال به سرور: ' . esc_html($response->get_error_message()) . '</p>';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!$data || !isset($data['contents'])) {
        return '<p>اطلاعات نامعتبر است.</p>';
    }

    $output = '<div class="spotplayer-course-outline">';

    // لیست فصل‌ها
    $segments = array_filter($data['contents'], function($item) {
        return isset($item['type']) && $item['type'] === 'seg';
    });

    if (!empty($segments)) {
        $output .= '<h3>📚 فصل‌ها:</h3><ul>';
        foreach ($segments as $seg) {
            $output .= '<li>' . esc_html($seg['name']) . '</li>';
        }
        $output .= '</ul>';
    }

    // لیست درس‌ها
    $lessons = array_filter($data['contents'], function($item) {
        return isset($item['type']) && $item['type'] === 'vid';
    });

    if (!empty($lessons)) {
        $output .= '<h3>🎥 دروس:</h3><ul>';
        foreach ($lessons as $lesson) {
            $output .= '<li>' . esc_html($lesson['name']) . '</li>';
        }
        $output .= '</ul>';
    }

    $output .= '</div>';

    return $output;
}
add_shortcode('spotplayer_simple_outline', 'spotplayer_simple_outline_shortcode');
