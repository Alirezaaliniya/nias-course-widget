<?php
use Carbon_Fields\Container\Container;
use Carbon_Fields\Field\Field;

// Register Course Fields
add_action('carbon_fields_register_fields', 'create_course_metabox');
function create_course_metabox() {
    Container::make('post_meta', __('تنظیمات دوره', 'nias-course-widget'))
        ->where('post_type', '=', 'product')
        ->set_priority('high')
        ->add_fields([
            Field::make('complex', 'course_sections', __('فصل‌ها', 'nias-course-widget'))
                ->set_layout('tabbed-vertical')
                ->add_fields([
                    Field::make('text', 'section_title', __('عنوان فصل', 'nias-course-widget'))
                        ->set_required(true),
                    Field::make('text', 'section_subtitle', __('زیرعنوان', 'nias-course-widget')),
                    Field::make('complex', 'section_icon', __('آیکون', 'nias-course-widget'))
                        ->add_fields([
                            Field::make('select', 'icon_type', __('نوع آیکون', 'nias-course-widget'))
                                ->set_options([
                                    'upload' => __('آپلود فایل', 'nias-course-widget'),
                                    'url' => __('لینک مستقیم', 'nias-course-widget')
                                ]),
                            Field::make('image', 'icon_upload', __('آپلود آیکون', 'nias-course-widget'))
                                ->set_value_type('url')
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'upload']
                                ]),
                            Field::make('text', 'icon_url', __('لینک آیکون', 'nias-course-widget'))
                                ->set_conditional_logic([
                                    'relation' => 'AND',
                                    ['field' => 'icon_type', 'value' => 'url']
                                ])
                        ])
                        ->set_max(1),
                    Field::make('complex', 'lessons', __('درس‌ها', 'nias-course-widget'))
                        ->set_layout('tabbed-horizontal')
                        ->add_fields([
                            Field::make('text', 'lesson_title', __('عنوان درس', 'nias-course-widget'))
                                ->set_required(true),
                            Field::make('complex', 'lesson_icon', __('آیکون', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'icon_type', __('نوع آیکون', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('image', 'icon_upload', __('آپلود آیکون', 'nias-course-widget'))
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'icon_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'icon_url', __('لینک آیکون', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'icon_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('text', 'lesson_label', __('برچسب', 'nias-course-widget')),
                            Field::make('complex', 'lesson_preview_video', __('ویدیوی پیش‌نمایش', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'video_type', __('نوع ویدیو', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('file', 'video_upload', __('آپلود ویدیو', 'nias-course-widget'))
                                        ->set_type(['video'])
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'video_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'video_url', __('لینک ویدیو', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'video_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('complex', 'lesson_download', __('فایل خصوصی درس', 'nias-course-widget'))
                                ->add_fields([
                                    Field::make('select', 'file_type', __('نوع فایل', 'nias-course-widget'))
                                        ->set_options([
                                            'upload' => __('آپلود فایل', 'nias-course-widget'),
                                            'url' => __('لینک مستقیم', 'nias-course-widget')
                                        ]),
                                    Field::make('file', 'file_upload', __('آپلود فایل', 'nias-course-widget'))
                                        ->set_value_type('url')
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'file_type', 'value' => 'upload']
                                        ]),
                                    Field::make('text', 'file_url', __('لینک فایل', 'nias-course-widget'))
                                        ->set_conditional_logic([
                                            'relation' => 'AND',
                                            ['field' => 'file_type', 'value' => 'url']
                                        ])
                                ])
                                ->set_max(1),
                            Field::make('checkbox', 'lesson_private', __('درس خصوصی است؟', 'nias-course-widget')),
                            Field::make('rich_text', 'lesson_content', __('محتوای درس', 'nias-course-widget'))
                        ])
                        ->set_header_template('<%- lesson_title %>'),
                ])
                ->set_header_template('<%- section_title %>'),
        ]);
}

// Migration Function
function migrate_course_data_to_carbon() {
    global $wpdb;
    // Check if migration has already been done
    $products = get_posts([
        'post_type' => 'product',
        'posts_per_page' => -1,
    ]);
    
    foreach ($products as $product) {
        if (get_post_meta($product->ID, '_course_data_migrated', true) === 'yes') {
            continue; // Skip if already migrated
        }
        
        // Retrieve old data
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
            WHERE post_id = %d AND meta_key = %s",
            $product->ID, 'nias_course_sections_list'
        ));
        
        foreach ($meta_rows as $row) {
            // Unserialize the data
            $sections = maybe_unserialize($row->meta_value);
            if (!is_array($sections)) {
                error_log("Invalid data for post ID {$product->ID}");
                continue;
            }
            
            // Prepare data for Carbon Fields
            $carbon_fields_data = [];
            foreach ($sections as $section_index => $section) {
                $section_data = [
                    'section_title'    => $section['section_title'] ?? '',
                    'section_subtitle' => $section['section_subtitle'] ?? '',
                    'section_icon'     => [
                        [
                            'icon_type' => 'url',
                            'icon_url'  => $section['section_icon'] ?? '',
                        ]
                    ],
                    'lessons'          => [],
                ];
                
                if (isset($section['lessons'])) {
                    foreach ($section['lessons'] as $lesson_index => $lesson) {
                        $lesson_data = [
                            'lesson_title'    => $lesson['lesson_title'] ?? '',
                            'lesson_icon'     => [
                                [
                                    'icon_type' => 'url',
                                    'icon_url'  => $lesson['lesson_icon'] ?? '',
                                ]
                            ],
                            'lesson_label'    => $lesson['lesson_label'] ?? '',
                            'lesson_preview_video' => [
                                [
                                    'video_type' => 'url',
                                    'video_url'  => $lesson['lesson_preview_video'] ?? '',
                                ]
                            ],
                            'lesson_download' => [
                                [
                                    'file_type' => 'url',
                                    'file_url'  => $lesson['lesson_download'] ?? '',
                                ]
                            ],
                            'lesson_content'  => $lesson['lesson_content'] ?? '',
                            'lesson_private'  => $lesson['lesson_private'] ?? false,
                        ];
                        
                        // Clean up empty media fields
                        if (empty($lesson_data['lesson_icon'][0]['icon_url'])) {
                            $lesson_data['lesson_icon'] = [];
                        }
                        if (empty($lesson_data['lesson_preview_video'][0]['video_url'])) {
                            $lesson_data['lesson_preview_video'] = [];
                        }
                        if (empty($lesson_data['lesson_download'][0]['file_url'])) {
                            $lesson_data['lesson_download'] = [];
                        }
                        
                        $section_data['lessons'][] = $lesson_data;
                    }
                }
                
                // Clean up empty section icon
                if (empty($section_data['section_icon'][0]['icon_url'])) {
                    $section_data['section_icon'] = [];
                }
                
                $carbon_fields_data[] = $section_data;
            }
            
            // Save data to Carbon Fields meta key
            carbon_set_post_meta($product->ID, 'course_sections', $carbon_fields_data);
        }
        
        // Mark migration as completed
        update_post_meta($product->ID, '_course_data_migrated', 'yes');
        
        error_log("Successfully migrated course data for product ID: {$product->ID}");
    }
    
    error_log("Course data migration completed");
}
// Add Migration Button
add_action('admin_menu', 'add_migration_menu');
function add_migration_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        __('Migrate Course Data', 'nias-course-widget'),
        __('Migrate Course Data', 'nias-course-widget'),
        'manage_options',
        'migrate-course-data',
        'render_migration_page'
    );
}

function render_migration_page() {
    if (isset($_POST['migrate_courses']) && check_admin_referer('migrate_courses_nonce')) {
        migrate_course_data_to_carbon();
        echo '<div class="notice notice-success"><p>' . __('Migration completed successfully!', 'nias-course-widget') . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php _e('Migrate Course Data to Carbon Fields', 'nias-course-widget'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('migrate_courses_nonce'); ?>
            <p><?php _e('Click the button below to migrate existing course data to the new format:', 'nias-course-widget'); ?></p>
            <input type="submit" name="migrate_courses" class="button button-primary" value="<?php _e('Start Migration', 'nias-course-widget'); ?>">
        </form>
    </div>
    <?php
}

