<?php

namespace Nias_Course;



// nias-controls.php
trait Nias_course_controls {
	
    protected function register_controls() {
	
		$this->start_controls_section(
			'lesson_section',
			[
				'label' => esc_html__( 'تنظیمات فصل', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SECTION,
	
			]
		);

		$this->add_control(
			'image',
			[
			   'label' => __( 'آیکون فصل(اختیاری)', 'nias-course-widget' ),
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
			   'label' => __( 'عنوان فصل دوره', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::TEXT,
  				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
		
				    $this->add_control(
      'tag_selector_titlelesson',
      [
        'label' => __('تگ html عنوان فصل', 'nias-course-widget'),
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
			   'label' => __( 'زیرنویس عنوان فصل دوره', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::TEXTAREA,
  				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
  
  
		 $this->add_control(
			'arrowsection',
			[
			   'label' => __( 'قابلیت باز و بسته شدن؟', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SWITCHER,
			   'label_on' => esc_html__("بله", 'nias-course-widget'),
					   'label_off' => esc_html__("خیر", 'nias-course-widget'),
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
				'label' => esc_html__( 'آیکون باز و بسته شدن', 'nias-course-widget' ),
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
			   'label' => esc_html__( 'درس ها', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SECTION,
			]
		 );
   
		 $repeater = new \Elementor\Repeater();
   
   
   
		   $repeater->add_control(
			  'private_lesson',
			  [
				 'label' => __( 'دوره خصوصی است؟', 'nias-course-widget' ),
                 'description' => esc_html__( 'چنانچه دوره خصوصی باشد مطالب و لینک دوره فقط به کاربری که محصول فعلی را خریده نمایش داده میشود', 'nias-course-widget' ),
				 'type' => \Elementor\Controls_Manager::SELECT,
				 'default' => 'no',
				 'options' => [
					'yes' => __( 'بله', 'nias-course-widget' ),
					'no' => __( 'خیر', 'nias-course-widget' ),
				 ],
			  ]
		   );
   
   
		   $repeater->add_control(
			  'subtitlelesson',
			  [
				 'label' => __( 'عنوان درس', 'nias-course-widget' ),
				 'type' => \Elementor\Controls_Manager::TEXT,
   				                'dynamic' => [
                    'active' => true,
                ],
			  ]
		   );
		

   
		   $repeater->add_control(
			  'subtitlelesson_sub',
			  [
				 'label' => __( 'زیرنویس عنوان', 'nias-course-widget' ),
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
				'label' => esc_html__( 'آیکن مخصوص این درس', 'nias-course-widget' ),
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
				'label' => __( 'لیبل درس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
												 'dynamic' => [
				   'active' => true,
			   ],
  
			 ]
		 );
   
   
		 $repeater->add_control(
			'preview_video',
			[
			   'label' => __( 'پیشنمایش ویدئویی', 'nias-course-widget' ),
               'description' => esc_html__( 'میتوانید با انتخاب آیکن داینامیک از لایت باکس استفاده کنید', 'nias-course-widget' ),
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

			   'label' => __( 'لینک فایل خصوصی درس', 'nias-course-widget' ),
               'description' => esc_html__( 'در این روش لینک مستقیم فایل به کاربر نمایش داده میشود (لینک میتواند توسط دیگران هم دانلود شود)', 'nias-course-widget' ),
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
				   'label' => __( 'آیا میخواهید از لینک های محافظت شده ووکامرس استفاده کنید؟', 'nias-course-widget' ),
				   'description' => esc_html__( 'لینک های محافظت شده بصورت غیر مستقیم نمایش داده میشوند و فقط توسط کاربر فعلی قابل دانلود است البته برای استفاده از این روش باید لینک ها را در صفحه ویرایش محصول دانلودی درج کنید', 'nias-course-widget' ),
				   'type' => \Elementor\Controls_Manager::SELECT,
				   'default' => 'no',
				   'options' => [
					  'yes' => __( 'بله', 'nias-course-widget' ),
					  'no' => __( 'خیر', 'nias-course-widget' ),
				   ],
				]
			 );
			 $repeater->add_control(
				 'downloads_list',
				 [
					'condition' => [
						'protect_downloadlink' => 'yes',
					],
					 'label' => esc_html__('فایل های موجوداین محصول', 'nias-course-widget'),
					 'type' => \Elementor\Controls_Manager::SELECT,
					 'options' => $options,
					 'default' => '',
				 ]
			 );
			}

   
			$repeater->add_control(
				'lesson_content',
				[
				   'label' => __( 'محتوای دوره', 'nias-course-widget' ),
				   'type' => \Elementor\Controls_Manager::WYSIWYG,
													'dynamic' => [
						'active' => true,
					],
				]
			 );

   
   
		 $this->add_control(
			'lessons_list',
			[
			   'label' => __( 'لیست دروس', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::REPEATER,
			   'fields' => $repeater->get_controls(),
			   'title_field' => '{{{subtitlelesson}}}',
   
			]
		 );
   						    $this->add_control(
      'tag_selector_subtitlelesson',
      [
        'label' => __('تگ html عنوان درس', 'nias-course-widget'),
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
			   'label' => esc_html__( 'آیکن و متن عمومی', 'nias-course-widget' ),
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
				'heading' => esc_html__( 'اطلاعیه', 'nias-course-widget' ),
				'content' => esc_html__( 'مواردی که از این قسمت تنظیم میشود برای تمامی دروس ویجت اعمال میشود', 'nias-course-widget' ),
			]
		);

		///nias custom icon for private leeson
		$this->add_control(
			'privateicon',
			[
				'label' => esc_html__( 'آیکن قفل درس', 'nias-course-widget' ),
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
				'label' => esc_html__( 'آیکن بازشدن درس', 'nias-course-widget' ),
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
				'label' => esc_html__( 'آیکون دانلود', 'nias-course-widget' ),
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
				'label' => esc_html__( 'آیکون پیش نمایش', 'nias-course-widget' ),
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
				'label' => esc_html__( 'متن دکمه پیش نمایش', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'پیش نمایش', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'پیش نمایش', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);
		$this->add_control(
			'nsdastresi',
			[
				'label' => esc_html__( 'متن دسترسی به دوره', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'دسترسی دارید', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'دسترسی دارید', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);
		$this->add_control(
			'nskhososi',
			[
				'label' => esc_html__( 'متن عدم دسترسی به دوره', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'خصوصی', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'خصوصی', 'nias-course-widget' ),
				'dynamic' => [
                    'active' => true,
                ],
			]
		);

		
		$this->add_control(
			'nsprivatetextcontent',
			[
			   'label' => esc_html__( 'متن دوره خصوصی در محتوا', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::WYSIWYG,	
				'dynamic' => [
                    'active' => true,
                ],
				'default' => esc_html__( 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget' ),
			]
		 );

		 $this->end_controls_section();

	
   
		 $this->start_controls_section(
			'nsspotplayer',
			[
			   'label' => esc_html__( 'اسپات پلیر', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SECTION,
			]
		 );

		 $this->add_control(
			'ns_show_spot',
			[
				'label' => esc_html__( 'نمایش لایسنس اسپات', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'نمایش', 'nias-course-widget' ),
				'label_off' => esc_html__( 'پنهان سازی', 'nias-course-widget' ),
				'return_value' => 'yes',
				'default' => 'no',
			]
		);
		$this->add_control(
			'ns_show_spotdl',
			[
				'label' => esc_html__( 'نمایش باکس دانلود اسپات', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'نمایش', 'nias-course-widget' ),
				'label_off' => esc_html__( 'پنهان سازی', 'nias-course-widget' ),
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
				'heading' => esc_html__( 'خیلی مهم', 'nias-course-widget' ),
				'content' => esc_html__( 'لطفاً فقط یکبار در یکی از ویجت های درس از این گزینه استفاده کنید!', 'nias-course-widget' ),
			]
		);

		 $this->end_controls_section();

////////////////////////////////////////////////////////////////////////////////////////////////
		 $this->start_controls_section(
			'nshelpnotife',
			[
				'label' => esc_html__( 'راهنمای استفاده', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::SECTION,
	
			]
		);

        $this->add_control(
			'ns_totalesales_notif',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'warning',
				'dismissible' => false,
				'heading' => esc_html__( 'تعداد دانشجویان', 'nias-course-widget' ),
				'content' => esc_html__( 'برای نمایش تعداد خریداران یا دانشجویان از متای  total_sales  استفاده کنید', 'nias-course-widget' ),
			]
		);

		$this->add_control(
			'ns_orginal_notif',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'info',
				'dismissible' => false,
				'heading' => esc_html__( 'اصالت پلاگین', 'nias-course-widget' ),
				'content' => esc_html__( 'توسعه دهنده پلاگین نیاس میباشد و مرجع انتشار آپدیت ها و آموزش های مرتبط  با این پلاگین وبسایت نیاس است', 'nias-course-widget' ). ' <div style="padding:10px;"><a style="background-color:blue;padding:5px 15px;border-radius:10px;color:#fff;" target="_blank" href="https://nias.ir/">' . esc_html__( 'مشاهده وبسایت نیاس', 'nias-course-widget' ) . '</a></div>',
			]
		);

		$this->add_control(
			'ns_dynamic_noticelink',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'danger',
				'dismissible' => false,
				'heading' => esc_html__( 'داینامیک سازی فیلد و لینک', 'nias-course-widget' ),
				'content' => esc_html__( 'برای داینامیک سازی لینک ها و فیلد های متنی میتوانید از متاساز نیاس استفاده کنید', 'nias-course-widget' ). ' <div style="padding:10px;"><a style="background-color:red;padding:5px 15px;border-radius:10px;color:#fff;" target="_blank" href="https://nias.ir/wordpress-meta-builder/">' . esc_html__( 'مشاهده متا ساز نیاس', 'nias-course-widget' ) . '</a></div>',
			]
		);

		 $this->end_controls_section();


/////////////////////////////////////////////////style

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
				'label' => esc_html__( 'نرمی حاشیه', 'nias-course-widget' ),
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
				'label' => esc_html__( 'فاصله داخلی', 'nias-course-widget' ),
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
				'label' => esc_html__( 'استایل فصل', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'nsheadimgwidth',
			[
				'label' => esc_html__( 'عرض عکس فصل', 'nias-course-widget' ),
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
				'label' => esc_html__( 'سایز عنوان', 'nias-course-widget' ),
				'name' => 'nstitleseson',
				'selector' => '{{WRAPPER}} .nstitleseson',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'سایز زیر عنوان', 'nias-course-widget' ),
				'name' => 'nsnssubtitle-lesson',
				'selector' => '{{WRAPPER}} .nssubtitle-lesson',
			]
		);
		$this->add_control(
			'nssubtitlemargin',
			[
				'label' => esc_html__( 'فاصله از بالا', 'nias-course-widget' ),
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
				'label' => esc_html__( 'اندازه آیکون باز و بسته شدن', 'nias-course-widget' ),
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
				'label' => esc_html__( 'رنگ آیکن باز و بسته شدن', 'nias-course-widget' ),
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
				'label' => esc_html__( '(ناحیه راست) استایل درس', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'nslessoniconsize',
			[
				'label' => esc_html__( 'اندازه آیکن درس', 'nias-course-widget' ),
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
				'label' => esc_html__( 'رنگ آیکن باز و بسته شدن', 'nias-course-widget' ),
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
				'label' => esc_html__( 'حالت هاور رنگ آیکن باز و بسته شدن', 'nias-course-widget' ),
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
				'label' => esc_html__( 'فونت لیبل درس', 'nias-course-widget' ),
				'name' => 'nsbadgtypograpy',
				'selector' => '{{WRAPPER}} .nsbadge-item',
			]
		);
		$this->add_control(
			'nsbadge-itemcolor',
			[
				'label' => esc_html__( 'رنگ لیبل درس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsbadge-item' => 'color: {{VALUE}}',
				],
			]
		);
		$this->add_control(
			'nsbadge-itembackcolor',
			[
				'label' => esc_html__( 'رنگ بک گراند لیبل درس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsbadge-item' => 'background-color: {{VALUE}}',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'فونت عنوان درس', 'nias-course-widget' ),
				'name' => 'nsstitlecoursetypography',
				'selector' => '{{WRAPPER}} .nsstitlecourse',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'فونت زیر عنوان درس', 'nias-course-widget' ),
				'name' => 'nssubtitletypography',
				'selector' => '{{WRAPPER}} .nssubtitle',
			]
		);
		$this->end_controls_section();

///////////////////////////////////////////////////////////////////////////////////left side style/////////////////////
				$this->start_controls_section(
			'nsstyle_eachcourseleft',
			[
				'label' => esc_html__( '(ناحیه چپ) استایل درس', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'label' => esc_html__( 'فونت دکمه پیش نمایش', 'nias-course-widget' ),
				'name' => 'nsvideopretext',
				'selector' => '{{WRAPPER}} .video-lesson-preview.preview-button .nsspanpreviewtext',
			]
		);

		$this->add_control(
			'nspreviewback',
			[
				'label' => esc_html__( 'رنگ بک گراند پیش نمایش', 'nias-course-widget' ),
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
				'label' => esc_html__( 'فاصله داخلی پیش نمایش', 'nias-course-widget' ),
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
				'label' => esc_html__( 'نرمی حاشیه پیش نمایش', 'nias-course-widget' ),
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
				'label' => esc_html__( 'اندازه آیکن پیش نمایش', 'nias-course-widget' ),
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
				'label' => esc_html__( 'رنگ بک گراند دکمه دانلود', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .nsdownload-button' => 'background-color: {{VALUE}}',
				],
			]

		);
		$this->add_control(
			'nsdownloadbtncolor',
			[
				'label' => esc_html__( 'رنگ دکمه دانلود', 'nias-course-widget' ),
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
				'label' => esc_html__( 'فاصله داخلی دکمه دانلود', 'nias-course-widget' ),
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
				'label' => esc_html__( 'نرمی حاشیه دکمه دانلود', 'nias-course-widget' ),
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
				'label' => esc_html__( 'اندازه آیکن دانلود', 'nias-course-widget' ),
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
				'label' => esc_html__( 'رنگ ایکن دوره خصوصی', 'nias-course-widget' ),
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
				'label' => esc_html__( 'رنگ ایکن دوره باز', 'nias-course-widget' ),
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
				'label' => esc_html__( 'استایل اسپات پلیر', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'ns_spot_licenseback',
			[
				'label' => esc_html__( 'رنگ بک گراند جعبه لایسنس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-spotlicense' => 'background-color: {{VALUE}}',
				],
			]

		);
		$this->add_control(
			'ns_spot_licensetext',
			[
				'label' => esc_html__( 'رنگ متن جعبه لایسنس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ns-spotlicense' => 'color: {{VALUE}}',
				],
			]

		);
		$this->end_controls_section();    }
}


?>