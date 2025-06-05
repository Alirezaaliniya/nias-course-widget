<?php
function spotplayer_course_list_shortcode($atts) {
    // Extract shortcode attributes
    $atts = shortcode_atts(array(
        'license_key' => '',
        'course_id' => '',
        'domain' => 'https://app.spotplayer.ir'
    ), $atts);

    if (empty($atts['license_key'])) {
        return '<p>Error: License key is required.</p>';
    }

    // Start output buffering
    ob_start();
    ?>
    <div id="player" style="display:none;"></div>
    <div class="spotplayer-course-list">
        <div id="course-content"></div>
    </div>
    
    <script src="https://app.spotplayer.ir/assets/js/app-api.js"></script>
    <script type="application/javascript">
        async function initializeSpotPlayer() {
            try {
                // Use the correct domain for SpotPlayer API
                const sp = new SpotPlayer(document.getElementById('player'), 'https://app.spotplayer.ir/spotx', false);
                await sp.Open('<?php echo esc_js($atts['license_key']); ?>', '<?php echo esc_js($atts['course_id']); ?>');
                
                if (typeof sp.courses !== 'undefined' && sp.courses.length > 0) {
                    const courseContent = document.getElementById('course-content');
                    let html = '<ul class="courses-list">';
                    
                    sp.courses.forEach(course => {
                        html += `
                            <li class="course-item">
                                <h3>${course.name}</h3>
                                <ul class="sessions-list">`;
                        
                        course.items.forEach(item => {
                            html += `
                                <li class="session-item">
                                    <span class="session-name">${item.name}</span>
                                    <span class="session-duration">${formatDuration(item.duration)}</span>
                                </li>`;
                        });
                        
                        html += `
                                </ul>
                            </li>`;
                    });
                    
                    html += '</ul>';
                    courseContent.innerHTML = html;
                }
                
                // Hide player after initialization
                await sp.Hide();
            } catch(ex) {
                console.error('SpotPlayer initialization error:', ex);
                document.getElementById('course-content').innerHTML = '<p>Error loading course content. Please try again later.</p>';
            }
        }

        function formatDuration(duration) {
            var seconds = Math.floor(duration / 1000);
            var minutes = Math.floor(seconds / 60);
            var hours = Math.floor(minutes / 60);
            
            minutes = minutes % 60;
            seconds = seconds % 60;
            
            return (hours > 0 ? hours + ':' : '') + 
                   padZero(minutes) + ':' + 
                   padZero(seconds);
        }

        function padZero(num) {
            return num < 10 ? '0' + num : num;
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', initializeSpotPlayer);
    </script>
    <style>
        .spotplayer-course-list {
            margin: 20px 0;
        }
        .courses-list {
            list-style: none;
            padding: 0;
        }
        .course-item {
            margin-bottom: 20px;
        }
        .sessions-list {
            list-style: none;
            padding-left: 20px;
        }
        .session-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .session-duration {
            color: #666;
            font-size: 0.9em;
        }
    </style>
    <?php
    
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('spotplayer_course_list', 'spotplayer_course_list_shortcode');

