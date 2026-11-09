<?php

namespace Nias_Course;



// nias-controls.php
trait Nias_course_controls {
	
    protected function register_controls() {
	
		$this->start_controls_section(
			'lesson_section',
			[
				'label' => esc_html__( 'Chapter settings', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SECTION,
	
			]
		);

		$this->add_control(
			'image',
			[
			   'label' => __( 'Chapter icon (optional)', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::MEDIA,
			   'default' => [
				  'url' => \Elementor\Utils::get_placeholder_image_src(),
				   				                'dynamic' => [
                    'active' => true,
                ],
			   ],
			]
		 );
  
		 $this->add_control(
			'titlelesson',
			[
			   'label' => __( 'Course chapter title', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::TEXT,
  				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
		
				    $this->add_control(
      'tag_selector_titlelesson',
      [
        'label' => __('Chapter title HTML tag', 'nias-course-widget'),
        'type' => \Elementor\Controls_Manager::SELECT,
        'options' => [
          'h1' => 'H1',
          'h2' => 'H2',
			'h3' => 'H3',
			'h4' => 'H4',
			'h5' => 'H5',
			'h6' => 'H6',
			'p' => 'p',
          'span' => 'Span',
        ],
        'default' => 'span', // Set the default tag
      ]
    );
		 $this->add_control(
			'subtitlelesson',
			[
			   'label' => __( 'Course chapter subtitle', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::TEXTAREA,
  				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
  
  
		 $this->add_control(
			'arrowsection',
			[
			   'label' => __( 'Collapsible?', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SWITCHER,
			   'label_on' => esc_html__("Yes", 'nias-course-widget'),
					   'label_off' => esc_html__("No", 'nias-course-widget'),
			   'default' => 'yes'
  
			]
		 );

		 		///nias custom icon for private leeson
		$this->add_control(
			'nsarrowicon',
			[
				'condition' => [
					'arrowsection' => 'yes',
				],
				'label' => esc_html__( 'Expand/collapse icon', 'nias-course-widget' ),
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

		 $this->end_controls_section();

		 $this->start_controls_section(
			'lessons_section',
			[
			   'label' => esc_html__( 'Lessons', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SECTION,
			]
		 );
   
		 $repeater = new \Elementor\Repeater();
   
   
   
		   $repeater->add_control(
			  'private_lesson',
			  [
				 'label' => __( 'Is the lesson private?', 'nias-course-widget' ),
                 'description' => esc_html__( 'If the lesson is private, its content and link are shown only to users who bought the current product', 'nias-course-widget' ),
				 'type' => \Elementor\Controls_Manager::SELECT,
				 'default' => 'no',
				 'options' => [
					'yes' => __( 'Yes', 'nias-course-widget' ),
					'no' => __( 'No', 'nias-course-widget' ),
				 ],
			  ]
		   );
   
   
		   $repeater->add_control(
			  'subtitlelesson',
			  [
				 'label' => __( 'Lesson title', 'nias-course-widget' ),
				 'type' => \Elementor\Controls_Manager::TEXT,
   				                'dynamic' => [
                    'active' => true,
                ],
			  ]
		   );
		

   
		   $repeater->add_control(
			  'subtitlelesson_sub',
			  [
				 'label' => __( 'Title subtitle', 'nias-course-widget' ),
				 'type' => \Elementor\Controls_Manager::TEXT,
				  				                'dynamic' => [
                    'active' => true,
                ],
   
			  ]
		   );

	

   ///nias custom icon for every leeson
		$repeater->add_control(
			'singlelessonicon',
			[
				'label' => esc_html__( 'Icon for this lesson', 'nias-course-widget' ),
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
		 $repeater->add_control(
			'label_lesson',
			[
				'label' => __( 'Lesson label', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
												 'dynamic' => [
				   'active' => true,
			   ],
  
			 ]
		 );
   
   
		 $repeater->add_control(
			'preview_video',
			[
			   'label' => __( 'Video preview', 'nias-course-widget' ),
               'description' => esc_html__( 'You can use the lightbox by choosing a dynamic icon', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::URL,
				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );

		 $repeater->add_control(
			'download_lesson',
			[
				/*
				'condition' => [
					'private_lesson' => 'yes',
					
				],
				*/

			   'label' => __( 'Lesson private file link', 'nias-course-widget' ),
               'description' => esc_html__( 'In this method the direct file link is shown to the user (others can download the link too)', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::URL,
								                'dynamic' => [
                    'active' => true,
                ],
			]
		 );




		 if (is_product()) {
			global $product;
			 $product = wc_get_product(get_the_ID()); // Get product details for display
			 $files = $product->get_downloads();
			 $options = [];
			//$current_product_id = $product->get_id();
			 
			 foreach ($files as $file_key => $file) {
				 $options[$file_key] = $file['name']; // Add option with file name to options array
			 }
	
			 $repeater->add_control(
				'protect_downloadlink',
				[
				   'label' => __( 'Do you want to use WooCommerce protected links?', 'nias-course-widget' ),
				   'description' => esc_html__( 'Protected links are shown indirectly and can only be downloaded by the current user; to use this method you must add the links on the downloadable product\'s edit page', 'nias-course-widget' ),
				   'type' => \Elementor\Controls_Manager::SELECT,
				   'default' => 'no',
				   'options' => [
					  'yes' => __( 'Yes', 'nias-course-widget' ),
					  'no' => __( 'No', 'nias-course-widget' ),
				   ],
				]
			 );
			 $repeater->add_control(
				 'downloads_list',
				 [
					'condition' => [
						'protect_downloadlink' => 'yes',
					],
					 'label' => esc_html__('Files of this product', 'nias-course-widget'),
					 'type' => \Elementor\Controls_Manager::SELECT,
					 'options' => $options,
					 'default' => '',
				 ]
			 );
			}

   
			$repeater->add_control(
				'lesson_content',
				[
				   'label' => __( 'Course content', 'nias-course-widget' ),
				   'type' => \Elementor\Controls_Manager::WYSIWYG,
													'dynamic' => [
						'active' => true,
					],
				]
			 );

   
   
		 $this->add_control(
			'lessons_list',
			[
			   'label' => __( 'Lessons list', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::REPEATER,
			   'fields' => $repeater->get_controls(),
			   'title_field' => '{{{subtitlelesson}}}',
   
			]
		 );
   						    $this->add_control(
      'tag_selector_subtitlelesson',
      [
        'label' => __('Lesson title HTML tag', 'nias-course-widget'),
        'type' => \Elementor\Controls_Manager::SELECT,
        'options' => [
          'h1' => 'H1',
          'h2' => 'H2',
			'h3' => 'H3',
			'h4' => 'H4',
			'h5' => 'H5',
			'h6' => 'H6',
			'p' => 'p',
          'span' => 'Span',
        ],
        'default' => 'span', // Set the default tag
      ]
    );
		 $this->end_controls_section();
   
		 $this->start_controls_section(
			'nspublicicontext',
			[
			   'label' => esc_html__( 'General icon and text', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SECTION,
			]
		 );
   
		
///nias notice about icon lesson publicc
		$this->add_control(
			'ns_public_notice',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'info',
				'dismissible' => true,
				'heading' => esc_html__( 'Notice', 'nias-course-widget' ),
				'content' => esc_html__( 'Items set in this section apply to all lessons of the widget', 'nias-course-widget' ),
			]
		);

		///nias custom icon for private leeson
		$this->add_control(
			'privateicon',
			[
				'label' => esc_html__( 'Lesson lock icon', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::ICONS,
				'default' => [
					'value' => 'fa fa-lock',
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

		///nias custom icon for private leeson
		$this->add_control(
			'unprivateicon',
			[
				'label' => esc_html__( 'Lesson open icon', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::ICONS,
				'default' => [
					'value' => 'fa fa-unlock',
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
	
		///nias download icon
		$this->add_control(
			'nsdownloadicon',
			[
				'label' => esc_html__( 'Download icon', 'nias-course-widget' ),
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
		
		

		///nias custom icon for private leeson
		$this->add_control(
			'nspreviewicon',
			[
				'label' => esc_html__( 'Preview icon', 'nias-course-widget' ),
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
				'label' => esc_html__( 'Preview button text', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'Preview', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'Preview', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);
		$this->add_control(
			'nsdastresi',
			[
				'label' => esc_html__( 'Course access text', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'You have access', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'You have access', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);
		$this->add_control(
			'nskhososi',
			[
				'label' => esc_html__( 'No course access text', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'Private', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'Private', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);

		
		$this->add_control(
			'nsprivatetextcontent',
			[
			   'label' => esc_html__( 'Private course text in content', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::WYSIWYG,	
				'dynamic' => [
                    'active' => true,
                ],
				'default' => esc_html__( 'This course is private; buy the course for full access', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'This course is private; buy the course for full access', 'nias-course-widget' ),
			]
		 );

		 $this->end_controls_section();

	
   
		 $this->start_controls_section(
			'nsspotplayer',
			[
			   'label' => esc_html__( 'SpotPlayer', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SECTION,
			]
		 );

		 $this->add_control(
			'ns_show_spot',
			[
				'label' => esc_html__( 'Show SpotPlayer license', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'Show', 'nias-course-widget' ),
				'label_off' => esc_html__( 'Hide', 'nias-course-widget' ),
				'return_value' => 'yes',
				'default' => 'no',
			]
		);
		$this->add_control(
			'ns_show_spotdl',
			[
				'label' => esc_html__( 'Show SpotPlayer download box', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'Show', 'nias-course-widget' ),
				'label_off' => esc_html__( 'Hide', 'nias-course-widget' ),
				'return_value' => 'yes',
				'default' => 'no',
			]
		);

		$this->add_control(
			'ns_spot_notice',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'danger',
				'dismissible' => true,
				'heading' => esc_html__( 'Very important', 'nias-course-widget' ),
				'content' => esc_html__( 'Please use this option only once, in one of the lesson widgets!', 'nias-course-widget' ),
			]
		);

		 $this->end_controls_section();

////////////////////////////////////////////////////////////////////////////////////////////////
		 $this->start_controls_section(
			'nshelpnotife',
			[
				'label' => esc_html__( 'Usage guide', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SECTION,
	
			]
		);

        $this->add_control(
			'ns_totalesales_notif',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'warning',
				'dismissible' => false,
				'heading' => esc_html__( 'Number of students', 'nias-course-widget' ),
				'content' => esc_html__( 'To show the number of buyers or students, use the total_sales meta', 'nias-course-widget' ),
			]
		);

		$this->add_control(
			'ns_orginal_notif',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'info',
				'dismissible' => false,
				'heading' => esc_html__( 'Plugin authenticity', 'nias-course-widget' ),
				'content' => esc_html__( 'The plugin developer is Nias, and the Nias website is the official source of updates and tutorials for this plugin', 'nias-course-widget' ). ' <div style="padding:10px;"><a style="background-color:blue;padding:5px 15px;border-radius:10px;color:#fff;" target="_blank" href="https://nias.ir/">' . esc_html__( 'Visit the Nias website', 'nias-course-widget' ) . '</a></div>',
			]
		);

		$this->add_control(
			'ns_dynamic_noticelink',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'danger',
				'dismissible' => false,
				'heading' => esc_html__( 'Dynamic fields and links', 'nias-course-widget' ),
				'content' => esc_html__( 'To make links and text fields dynamic you can use Nias Meta Builder', 'nias-course-widget' ). ' <div style="padding:10px;"><a style="background-color:red;padding:5px 15px;border-radius:10px;color:#fff;" target="_blank" href="https://nias.ir/wordpress-meta-builder/">' . esc_html__( 'View Nias Meta Builder', 'nias-course-widget' ) . '</a></div>',
			]
		);

		 $this->end_controls_section();


/////////////////////////////////////////////////style

		 $this->start_controls_section(
			'nsstylewidget',
			[
				'label' => esc_html__( 'Box style', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'backgroundnscourse-section',
				'types' => [ 'classic', 'gradient', 'video' ],
				'selector' => '{{WRAPPER}} .nscourse-section',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'bordernscourse-section',
				'selector' => '{{WRAPPER}} .nscourse-section',
			]
		);

		$this->add_responsive_control(
			'nsmainradius',
			[
				'label' => esc_html__( 'Border radius', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nscourse-section' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'nsmainpadding',
			[
				'label' => esc_html__( 'Padding', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nscourse-section' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->end_controls_section();


/////////////////////////////////////seson style////////////////////////////////////		
		$this->start_controls_section(
			'nsstylewidget_head',
			[
				'label' => esc_html__( 'Chapter style', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'nsheadimgwidth',
			[
				'label' => esc_html__( 'Chapter image width', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 1000,
						'step' => 1,
					],
					'%' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .nscourse-section-title-elementory.cursor-pointer img' => 'width: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Title size', 'nias-course-widget' ),
				'name' => 'nstitleseson',
				'selector' => '{{WRAPPER}} .nstitleseson',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Subtitle size', 'nias-course-widget' ),
				'name' => 'nsnssubtitle-lesson',
				'selector' => '{{WRAPPER}} .nssubtitle-lesson',
			]
		);
		$this->add_control(
			'nssubtitlemargin',
			[
				'label' => esc_html__( 'Top spacing', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 70,
						'step' => 1,
					],
					'%' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .nssubtitle-lesson' => 'margin-top: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'nsarrowiconsize',
			[
				'label' => esc_html__( 'Expand/collapse icon size', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 70,
						'step' => 1,
					],
					'%' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .nsarrowicon' => 'font-size: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'nsarrowicon_color',
			[
				'label' => esc_html__( 'Expand/collapse icon color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsarrowicon' => 'color: {{VALUE}}',
					'{{WRAPPER}} .nsarrowicon svg' => 'fill: {{VALUE}}',
				],
			]
		);

		$this->end_controls_section();

///////////////////////////////////right side style/////////////////////
		$this->start_controls_section(
			'nsstyle_eachcourse',
			[
				'label' => esc_html__( '(right area) Lesson style', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'nslessoniconsize',
			[
				'label' => esc_html__( 'Lesson icon size', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
						'step' => 1,
					],
				],
				
				'selectors' => [
					'{{WRAPPER}} .ns-icon-wrapper i' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ns-icon-wrapper svg' => 'height: {{SIZE}}{{UNIT}};',


				],
			]
		);

		$this->add_control(
			'nslessonicon_color',
			[
				'label' => esc_html__( 'Expand/collapse icon color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-icon-wrapper i' => 'color: {{VALUE}}',
					'{{WRAPPER}} .ns-icon-wrapper i svg' => 'fill: {{VALUE}}',

				],
			]
		);

		$this->add_control(
			'nslessonicon_color_hover',
			[
				'label' => esc_html__( 'Expand/collapse icon hover color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nscourse-panel-heading:hover .ns-icon-wrapper i' => 'color: {{VALUE}}',
					'{{WRAPPER}} .nscourse-panel-heading.active .ns-icon-wrapper i' => 'color: {{VALUE}}',
					'{{WRAPPER}} .nscourse-panel-heading:hover .ns-icon-wrapper i svg' => 'fill: {{VALUE}}',
					'{{WRAPPER}} .nscourse-panel-heading.active .ns-icon-wrapper i svg' => 'fill: {{VALUE}}',

				],
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Lesson label font', 'nias-course-widget' ),
				'name' => 'nsbadgtypograpy',
				'selector' => '{{WRAPPER}} .nsbadge-item',
			]
		);
		$this->add_control(
			'nsbadge-itemcolor',
			[
				'label' => esc_html__( 'Lesson label color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsbadge-item' => 'color: {{VALUE}}',
				],
			]
		);
		$this->add_control(
			'nsbadge-itembackcolor',
			[
				'label' => esc_html__( 'Lesson label background color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsbadge-item' => 'background-color: {{VALUE}}',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Lesson title font', 'nias-course-widget' ),
				'name' => 'nsstitlecoursetypography',
				'selector' => '{{WRAPPER}} .nsstitlecourse',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Lesson subtitle font', 'nias-course-widget' ),
				'name' => 'nssubtitletypography',
				'selector' => '{{WRAPPER}} .nssubtitle',
			]
		);
		$this->end_controls_section();

///////////////////////////////////////////////////////////////////////////////////left side style/////////////////////
				$this->start_controls_section(
			'nsstyle_eachcourseleft',
			[
				'label' => esc_html__( '(left area) Lesson style', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'Preview button font', 'nias-course-widget' ),
				'name' => 'nsvideopretext',
				'selector' => '{{WRAPPER}} .video-lesson-preview.preview-button .nsspanpreviewtext',
			]
		);

		$this->add_control(
			'nspreviewback',
			[
				'label' => esc_html__( 'Preview background color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .video-lesson-preview' => 'background-color: {{VALUE}}',
				],
			]

		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'nspreviewborder',
				'selector' => '{{WRAPPER}} .video-lesson-preview',
			]
		);

		$this->add_responsive_control(
			'nspreviewpadding',
			[
				'label' => esc_html__( 'Preview padding', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .video-lesson-preview' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'nspreviewborder_radius',
			[
				'label' => esc_html__( 'Preview border radius', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .video-lesson-preview' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'nspreviewiconsize',
			[
				'label' => esc_html__( 'Preview icon size', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
						'step' => 1,
					],
				],
				
				'selectors' => [
					'{{WRAPPER}} .nspreviewicon i' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .nspreviewicon svg' => 'height: {{SIZE}}{{UNIT}};',


				],
			]
		);

		$this->add_control(
			'nsdownloadbtnback',
			[
				'label' => esc_html__( 'Download button background color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsdownload-button' => 'background-color: {{VALUE}}',
				],
			]

		);
		$this->add_control(
			'nsdownloadbtncolor',
			[
				'label' => esc_html__( 'Download button color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsdownload-button' => 'color: {{VALUE}}',
					'{{WRAPPER}} .nsdownload-button svg' => 'fill: {{VALUE}}',

				],
			]

		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'nsdownloadbtnborder',
				'selector' => '{{WRAPPER}} .nsdownload-button',
			]
		);

		$this->add_responsive_control(
			'nsdownloadbtnborder',
			[
				'label' => esc_html__( 'Download button padding', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nsdownload-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'nsdownloadbtnborder_radius',
			[
				'label' => esc_html__( 'Download button border radius', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'isLinked' => true,
				],
				'selectors' => [
					'{{WRAPPER}} .nsdownload-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'nsdownload_iconsize',
			[
				'label' => esc_html__( 'Download icon size', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
						'step' => 1,
					],
				],
				
				'selectors' => [
					'{{WRAPPER}} .nsdownload-icon i' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .nsdownload-icon svg' => 'height: {{SIZE}}{{UNIT}};',


				],
			]
		);

		$this->add_control(
			'ns_private_iconcolor',
			[
				'label' => esc_html__( 'Private course icon color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-private-icon i' => 'color: {{VALUE}}',
					'{{WRAPPER}} .ns-private-icon i svg' => 'fill: {{VALUE}}',

				],
			]

		);
		$this->add_control(
			'ns_unprivate_iconcolor',
			[
				'label' => esc_html__( 'Open course icon color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-unprivate-icon i' => 'color: {{VALUE}}',
					'{{WRAPPER}} .ns-unprivate-icon i svg' => 'fill: {{VALUE}}',

				],
			]

		);

		$this->end_controls_section();

		///////////////////////////////////////////////////////////////////////////////////left side style/////////////////////
		$this->start_controls_section(
			'nsstyle_spotplayer',
			[
				'label' => esc_html__( 'SpotPlayer style', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'ns_spot_licenseback',
			[
				'label' => esc_html__( 'License box background color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-spotlicense' => 'background-color: {{VALUE}}',
				],
			]

		);
		$this->add_control(
			'ns_spot_licensetext',
			[
				'label' => esc_html__( 'License box text color', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-spotlicense' => 'color: {{VALUE}}',
				],
			]

		);
		$this->end_controls_section();    }
}


?>