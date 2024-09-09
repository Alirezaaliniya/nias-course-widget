<?php

namespace Nias_Course;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}



class Nias_course_woocommerce extends \Elementor\Widget_Base
{

    // Widget name
    public function get_name()
    {
        return 'niaslessonswoo';
    }

    // Widget title
    public function get_title()
    {
        return esc_html__('دوره ووکامرس', 'nias-course-widget');
    }

    // Widget icon
    public function get_icon()
    {
        return 'eicon-navigator';
    }

    // Widget categories
    public function get_categories()
    {
        return ['nias-widget-category'];
    }

    // Script dependencies
    public function get_script_depends()
    {
        return ['nscourse-js'];
    }

    // Style dependencies
    public function get_style_depends()
    {
        return ['nscourse-css'];
    }

    // Widget controls
    protected function _register_controls()
    {

        $this->start_controls_section(
            'lesson_section',
            [
                'label' => esc_html__('تنظیمات فصل', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::SECTION,

            ]
        );

        $this->add_control(
            'image_woocommerce',
            [
                'label' => __('آیکون فصل(عمومی)', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::MEDIA,
                'default' => [
                    'url' => \Elementor\Utils::get_placeholder_image_src(),
                    'dynamic' => [
                        'active' => true,
                    ],
                ],
            ]
        );


        ///nias custom icon for private leeson
        $this->add_control(
            'nsarrowicon',
            [
                'label' => esc_html__('آیکون باز و بسته شدن', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::ICONS,
                'default' => [
                    'value' => 'fas fa-chevron-down',
                    'library' => 'fa-solid',
                ],
                'recommended' => [
                    'fa-solid' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                    'fa-regular' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                ],
            ]
        );
        $this->add_control(
            'nspreviewicon',
            [
                'label' => esc_html__('آیکون پیش نمایش', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::ICONS,
                'default' => [
                    'value' => 'fa fa-play-circle',
                    'library' => 'fa-solid',
                ],
                'recommended' => [
                    'fa-solid' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                    'fa-regular' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                ],
            ]
        );
        $this->add_control(
            'nspreviewtext',
            [
                'label' => esc_html__('متن دکمه پیش نمایش', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => esc_html__('پیش نمایش', 'nias-course-widget'),
                'placeholder' => esc_html__('پیش نمایش', 'nias-course-widget'),
                'dynamic' => [
                    'active' => true,
                ],
            ]
        );

        $this->add_control(
            'nsprivatetextcontent',
            [
                'label' => esc_html__('متن دوره خصوصی در محتوا', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::WYSIWYG,
                'dynamic' => [
                    'active' => true,
                ],
                'default' => esc_html__('این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget'),
                'placeholder' => esc_html__('این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget'),
            ]
        );
        $this->add_control(
            'singlelessonicon',
            [
                'label' => esc_html__('آیکن مخصوص این درس', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::ICONS,
                'default' => [
                    'value' => 'fas fa-book',
                    'library' => 'fa-solid',
                ],
                'recommended' => [
                    'fa-solid' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                    'fa-regular' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                ],
            ]
        );

        $this->add_control(
            'nsdownloadicon',
            [
                'label' => esc_html__('آیکون دانلود', 'nias-course-widget'),
                'type' => \Elementor\Controls_Manager::ICONS,
                'default' => [
                    'value' => 'fa fa-download',
                    'library' => 'fa-solid',
                ],
                'recommended' => [
                    'fa-solid' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                    'fa-regular' => [
                        'circle',
                        'dot-circle',
                        'square-full',
                    ],
                ],
            ]
        );

        $this->end_controls_section();


        /* -------------------------------------------------------------------------- */
        /*                                  style tab                                 */
        /* -------------------------------------------------------------------------- */

        /* ----------------------------- each box style ----------------------------- */
        
		 $this->start_controls_section(
			'nsstylewidget',
			[
				'label' => esc_html__( 'استایل باکس', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'backgroundnscourse-section',
				'types' => [ 'classic', 'gradient', 'video' ],
				'selector' => '{{WRAPPER}} .nias_course_section',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'bordernscourse-section',
				'selector' => '{{WRAPPER}} .nias_course_section',
			]
		);

		$this->add_responsive_control(
			'nsmainradius',
			[
				'label' => esc_html__( 'نرمی حاشیه', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nias_course_section' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'nsmainpadding',
			[
				'label' => esc_html__( 'فاصله داخلی', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nias_course_section' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->end_controls_section();

        /* -------------------------- each head part style -------------------------- */
        $this->start_controls_section(
			'nsstylewidget',
			[
				'label' => esc_html__( 'استایل هد/فصل', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'backgroundnscourse-section',
				'types' => [ 'classic', 'gradient', 'video' ],
				'selector' => '{{WRAPPER}} .section_header',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'bordernscourse-section',
				'selector' => '{{WRAPPER}} .section_header',
			]
		);

		$this->add_responsive_control(
			'nsmainradius',
			[
				'label' => esc_html__( 'نرمی حاشیه', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .section_header' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'nsmainpadding',
			[
				'label' => esc_html__( 'فاصله داخلی', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .section_header' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->end_controls_section();


    }

    // Widget output
    protected function render()
    {

        $settings = $this->get_settings_for_display();

        /* --------- for make sure that user bought product to add condition -------- */

        $bought_course = false;
        $current_user = wp_get_current_user();
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if (!empty($current_user->user_login) && !empty($current_user->ID)) {
                global $post;
                if (isset($post) && !empty($post->ID)) {
                    $product_id = $post->ID;
                    if (wc_customer_bought_product($current_user->user_login, $current_user->ID, $product_id)) {
                        $bought_course = true;
                    }
                }
            }
        }
        /* ------------------ end of condition to use in main code ------------------ */

        $post_id = get_the_ID();
        $sections = get_post_meta($post_id, 'nias_course_sections_list', true);

        if ($sections) { ?>
            <div id="nias_course_sections">
                <?php foreach ($sections as $index => $section) { ?>

                    <div class="nias_course_section">
                        <div class="section_header toggle_section">
                            <?php echo '<img width="50" height="50" src="' . esc_url($settings['image_woocommerce']['url']) . '">'; ?>
                            <h3 class="section_title"><?php echo esc_html($section['section_title']); ?></h3>
                            <span class="section_subtitle"><?php echo esc_html($section['section_subtitle']); ?></span>
                            <i class="nsarrowicon nias-course-icon">
                                <?php \Elementor\Icons_Manager::render_icon($settings['nsarrowicon'], ['aria-hidden' => 'true']);
                                ?>
                            </i>
                        </div>
                        <div class="section_content" style="display: none;">
                            <?php if (!empty($section['lessons'])) { ?>
                                <ul class="lessons_list">
                                    <?php foreach ($section['lessons'] as $lesson) { ?>
                                        <li class="lesson_item">
                                            <div class="lesson_header toggle_lesson">

                                                <div class="nias-right-head">
                                                    <?php if (!empty($lesson['lesson_icon'])) { ?>
                                                        <img src="<?php echo esc_url($lesson['lesson_icon']); ?>" alt="<?php echo esc_attr($lesson['lesson_title']); ?>" />
                                                    <?php } else { ?>
                                                        <i class="ns-icon-wrapper nias-course-icon">
                                                            <?php \Elementor\Icons_Manager::render_icon($settings['singlelessonicon'], ['aria-hidden' => 'true']);
                                                            ?>
                                                        </i>

                                                    <?php
                                                    } ?>
                                                    <div class="nias-lesson-text">
                                                        <h4 class="lesson_title"><?php echo esc_html($lesson['lesson_title']); ?></h4>
                                                        <span class="lesson_label"><?php echo esc_html($lesson['lesson_label']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="nias-left-head">
                                                    <?php if (!empty($lesson['lesson_preview_video'])) : ?>
                                                        <a class="nias-preview-tag" target="_blank" href="<?php echo esc_url($lesson['lesson_preview_video']); ?>">
                                                            <i class="nspreviewicon nias-course-icon">
                                                                <?php
                                                                //nias preview icon
                                                                \Elementor\Icons_Manager::render_icon($settings['nspreviewicon'], ['aria-hidden' => 'true']);
                                                                ?>

                                                            </i>
                                                            <span class="nsspanpreviewtext">
                                                            <?php echo esc_html($settings['nspreviewtext']); ?>
                                                        </span>
                                                        </a>

                                                    <?php endif; ?>
                                                    <?php if ($lesson['lesson_private'] === 'yes') {
                                                        if ($bought_course) {
                                                            if (!empty($lesson['lesson_download'])) {
                                                    ?>
                                                                <a class="nsdownload-button nias-course-icon" target="_blank" href="<?php echo esc_url($lesson['lesson_download']); ?>">
                                                                    <i>
                                                                        <?php
                                                                        //nias download icon
                                                                        \Elementor\Icons_Manager::render_icon($settings['nsdownloadicon'], ['aria-hidden' => 'true']);
                                                                        ?>
                                                                    </i>

                                                                </a>
                                                            <?php
                                                            }
                                                        } else {
                                                            ?>
                                                            <div class="ns-private-lesson">
                                                                <i class="ns-private-icon nias-course-icon">
                                                                    <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M16.0596 9.34865C15.0136 9.10565 13.7526 8.99465 12.2496 8.99465C10.7466 8.99465 9.4856 9.10565 8.4396 9.34865V7.91665C8.4726 5.84265 10.1236 4.19765 12.1996 4.17065C13.2306 4.14265 14.1816 4.54265 14.9106 5.25365C15.6386 5.96465 16.0476 6.91565 16.0596 7.92465V9.34865ZM12.9996 17.0477C12.9996 17.4617 12.6636 17.7977 12.2496 17.7977C11.8356 17.7977 11.4996 17.4617 11.4996 17.0477V14.8267C11.4996 14.4127 11.8356 14.0767 12.2496 14.0767C12.6636 14.0767 12.9996 14.4127 12.9996 14.8267V17.0477ZM17.5596 9.86165V7.91565C17.5416 6.49665 16.9736 5.16965 15.9566 4.17965C14.9416 3.18965 13.5876 2.69865 12.1796 2.67065C9.2886 2.70765 6.9866 5.00165 6.9396 7.90565V9.86265C4.9056 10.8327 4.0896 12.6837 4.0896 15.7657C4.0896 20.7657 6.2256 22.5377 12.2496 22.5377C18.2746 22.5377 20.4106 20.7657 20.4106 15.7657C20.4106 12.6837 19.5936 10.8317 17.5596 9.86165Z" fill="#737373" />
                                                                    </svg>
                                                                </i>
                                                                <span>خصوصی</span>
                                                            </div>

                                                        <?php
                                                        }
                                                    } elseif ($lesson['lesson_private'] !== 'yes') {
                                                        if (!empty($lesson['lesson_download'])) {
                                                        ?>
                                                            <a target="_blank" href="<?php echo esc_url($lesson['lesson_download']); ?>">
                                                                <div class="nsdownload-button nias-course-icon">

                                                                    <i>
                                                                        <?php
                                                                        //nias download icon
                                                                        \Elementor\Icons_Manager::render_icon($settings['nsdownloadicon'], ['aria-hidden' => 'true']);
                                                                        ?>
                                                                    </i>
                                                                </div>


                                                            </a>
                                                    <?php
                                                        }
                                                    }

                                                    ?>
                                                    <i class="nsarrowicon nias-course-icon">
                                                        <?php \Elementor\Icons_Manager::render_icon($settings['nsarrowicon'], ['aria-hidden' => 'true']);
                                                        ?>
                                                    </i>
                                                </div>
                                            </div>
                                            <div class="lesson_content" style="display: none;">
                                                <?php if ($lesson['lesson_private'] === 'yes') {
                                                    if ($bought_course) {
                                                        echo wp_kses_post($lesson['lesson_content']);
                                                    } else {
                                                        echo wp_kses_post($settings['nsprivatetextcontent']);
                                                    }
                                                } elseif ($lesson['lesson_private'] !== 'yes') {
                                                    echo wp_kses_post($lesson['lesson_content']);
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


        ?>



        <style>
            .nias-lesson-text {
                flex-direction: column;
                gap: 5px !important;
                align-items: flex-start !important;
            }

            @media only screen and (max-width:700px) {
                .nsspanpreviewtext {
                    display: none !important;
                }
            }

            ul.lessons_list {
                padding-right: 0;
            }

            .nias-left-head,
            .nias-right-head,
            .nias-left-head *:not(a),
            .nias-right-head *:not(a) {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 20px;
            }

            .nias_course_section {
                margin-bottom: 20px;
                border: 1px solid #ddd;
            }

            .lesson_header img {
                width: 30px;
                height: 30px;
            }


            .section_content li {
                list-style: none;
            }

            .section_header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background-color: #f9f9f9;
                padding: 10px;
                cursor: pointer;
                gap: 20px;
            }

            .section_title {
                margin: 0;
                font-size: 18px;
                font-weight: bold;
            }

            .section_subtitle {
                margin: 0;
                font-size: 14px;
                color: #777;
            }

            .section_content {
                padding: 10px;
                border-top: 1px solid #ddd;
            }

            .lesson_item {
                margin-bottom: 15px;
                border: 1px solid #eee;
                padding: 10px;
            }

            .lesson_header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 20px;
            }

            .lesson_title {
                margin: 0;
                font-size: 16px;
                font-weight: bold;
            }

            .lesson_content {
                margin-top: 10px;
                display: none;
            }

            button.toggle_section,
            button.toggle_lesson {
                background-color: #0073aa;
                color: #fff;
                padding: 5px 10px;
                border: none;
                cursor: pointer;
            }

            .lesson_header:hover span {
                opacity: 1;
                transform: scale(1) rotate(0deg);
            }

            .nias-course-icon svg {
                width: 1em;
                height: 1em;
                position: relative;
                display: block;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // باز و بسته کردن فصل‌ها
                $('.toggle_section').on('click', function() {
                    $(this).closest('.nias_course_section').find('.section_content').slideToggle();
                    $(this).toggleClass('active');
                });

                // باز و بسته کردن دروس
                $('.toggle_lesson').on('click', function() {
                    $(this).closest('.lesson_item').find('.lesson_content').slideToggle();
                    $(this).toggleClass('active');

                });
            });
        </script>

<?php

    }
}

// Register the widget
\Elementor\Plugin::instance()->widgets_manager->register_widget_type(new Nias_course_woocommerce());
