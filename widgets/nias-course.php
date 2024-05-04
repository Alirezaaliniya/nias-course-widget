<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor List Widget.
 *
 * Elementor widget that inserts an embbedable content into the page, from any given URL.
 *
 * @since 1.0.0
 */


 //spot player function
 function niasspotdata(){
    $current_user_id = get_current_user_id();
    $orders = wc_get_orders( array(
        'customer' => $current_user_id,
    ) );
    $meta_values = array();
    foreach ( $orders as $order ) {
        $meta_data = $order->get_meta( '_spotplayer_data', true );
        if ( $meta_data ) {
            $unserialized_data = maybe_unserialize( $meta_data );
            if ( $unserialized_data !== false ) {
                $meta_values[] = array(
                    'name' => $unserialized_data['name'],
                    'watermark' => $unserialized_data['watermark']['texts'][0]['text'],
                    'key' => $unserialized_data['key']
                );
            }
        }
    }
    if ( !empty( $meta_values ) ) {
    echo '<div class="ns-spotlicense">
		<p class="ns-spotconfirm">لایسنس این دوره با اطلاعات زیر برای شما ثبت شد</p>';
        foreach ( $meta_values as $meta_value ) {
            echo '<p class="ns-spotuserinfo"> نام: ' . esc_html( $meta_value['name'] ) . '</p>';
            echo '<p class="ns-spotuserinfo">واترمارک: ' . esc_html( $meta_value['watermark'] ) . '</p>';
            echo '
			<p class="ns-spotuserinfo">کلید لایسنس:</p>
			<textarea class="nsspotlicense" readonly rows = "3">' . esc_html( $meta_value['key'] ) . '</textarea>
			<button class="nsspotcopybtn">کپی لایسنس</button>
			';
        }
        echo '</div>';
    } else {
        return;
    }
} 



///ns main code of widget
class Nias_course_widget extends \Elementor\Widget_Base {
	public function get_name() {
		return 'lessons';
	 }
  
	 public function get_title() {
		return esc_html__( 'درس ها', 'nias-course-widget' );
	 }
  
	 public function get_icon() {
		  return 'eicon-navigator';
	 }
  
	 public function get_categories() {
		return [ 'general' ];
	}
  
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
			   'label_on' => esc_html_x("بله", 'pelleh'),
					   'label_off' => esc_html_x("خیر", 'pelleh'),
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
					'value' => 'fab fa-youtube',
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
			   'type' => \Elementor\Controls_Manager::URL,
				                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
   
		 $repeater->add_control(
			'download_lesson',
			[
			   'label' => __( 'لینک فایل خصوصی درس', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::URL,
								                'dynamic' => [
                    'active' => true,
                ],
			]
		 );
   
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
			'nscontorol_privattext',
			[
			   'label' => __( 'میخواهید متن دوره خصوصی را تغییر دهید؟', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::SELECT,
			   'default' => 'no',
			   'options' => [
				  'yes' => __( 'بله', 'nias-course-widget' ),
				  'no' => __( 'خیر', 'nias-course-widget' ),
			   ],
			]
		 );

		$this->add_control(
			'nsprivatetextcontent',
			[
			   'label' => esc_html__( 'متن دوره خصوصی در محتوا', 'nias-course-widget' ),
			   'type' => \Elementor\Controls_Manager::WYSIWYG,
			   'condition' => [
				'nscontorol_privattext' => 'yes',
			],			
				'dynamic' => [
                    'active' => true,
                ],
				'default' => esc_html__( 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget' ),
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
		$this->end_controls_section();

		}
 


	 protected function render() {

		// get our input from the widget settings.
		$settings = $this->get_settings_for_display();
		   $tag = $settings['tag_selector_titlelesson'];
		 $tagsub = $settings['tag_selector_subtitlelesson'];
		$bought_course = false;
		$current_user = wp_get_current_user();


	///spotplayer
  
		if( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			if( !empty($current_user->user_login) && !empty($current_user->ID) ) {
				global $post;
				if( isset($post) && !empty($post->ID) ) {
					$product_id = $post->ID;
					if ( wc_customer_bought_product( $current_user->user_login, $current_user->ID, $product_id ) ) {
						$bought_course = true;
					}
				}
			}
		}
		
		global $product;
		if (  'yes' == $settings['ns_show_spot'] ) {
			niasspotdata();
		}
if (  'yes' == $settings['ns_show_spotdl'] ) {
	?>
	<p class="ns-spotdltitle">دانلود برنامه اسپات پلیر جهت مشاهده دوره</p>
	<div class="ns-spotdlicon">
		<a href="https://app.spotplayer.ir/" target="_blank">
		<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" fill="none">
<g clip-path="url(#clip0_28_24)">
<path d="M128 70H242C252.142 89.9589 256.872 112.229 255.717 134.587C254.562 156.945 247.564 178.609 235.42 197.417C223.276 216.225 206.411 231.519 186.509 241.772C166.607 252.026 144.364 256.88 122 255.85" fill="#FFCC44"/>
<path d="M178.5 157L122 255.85C99.5538 254.828 77.7721 247.916 58.8443 235.807C39.9165 223.699 24.5095 206.821 14.1721 186.871C3.83463 166.921 -1.06903 144.601 -0.0460143 122.155C0.977003 99.7086 7.89065 77.9272 20 59" fill="#0F9D58"/>
<path d="M128 69.9999H242C231.788 49.8772 216.438 32.8118 197.505 20.5333C178.572 8.25479 156.73 1.19964 134.192 0.082892C111.654 -1.03386 89.2211 3.82748 69.1672 14.1742C49.1134 24.5209 32.1513 39.9853 20 58.9999L77.5 157" fill="#DB4437"/>
<path d="M128 180.5C156.995 180.5 180.5 156.995 180.5 128C180.5 99.0051 156.995 75.5 128 75.5C99.0051 75.5 75.5 99.0051 75.5 128C75.5 156.995 99.0051 180.5 128 180.5Z" fill="#4285F4" stroke="#F1F1F1" stroke-width="12"/>
</g>
<defs>
<clipPath id="clip0_28_24">
<rect width="256" height="256" fill="white"/>
</clipPath>
</defs>
</svg>	
		نسخه Web</a>

		<a href="https://app.spotplayer.ir/assets/bin/spotplayer/setup.exe" target="_blank">
		<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" fill="none">
<path d="M0.498047 36.2155L104.711 22.024L104.756 122.545L0.592677 123.138L0.498047 36.2165V36.2155ZM104.661 134.126L104.741 234.735L0.578732 220.414L0.572755 133.452L104.661 134.126ZM117.294 20.1662L255.47 0V121.266L117.294 122.362V20.1662ZM255.502 135.072L255.469 255.792L117.293 236.29L117.1 134.846L255.502 135.072Z" fill="#00ADEF"/>
</svg>	
		نسخه Windows</a>

		<a href="https://app.spotplayer.ir/assets/bin/spotplayer/setup.dmg" target="_blank">

<svg xmlns="http://www.w3.org/2000/svg" width="138" height="151" viewBox="0 0 138 151" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M74.6458 111.349C56.6712 112.049 37.2982 108.665 20.9598 100.494L24.3439 95.1261C40.216 102.595 58.423 105.164 74.6458 104.463C74.6458 105.046 75.343 93.4912 76.3936 87.1893H52.0029C54.2195 61.7468 62.0394 39.1053 73.7108 19.8491H0.768677V130.956H77.4441C75.6937 124.302 74.6458 111.115 74.6458 111.349Z" fill="#D0E9FB"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M81.9968 103.995C95.3018 102.711 106.972 99.21 114.092 94.6586L118.294 100.027C108.489 105.747 95.768 109.48 81.9968 110.881C82.3475 117.768 83.398 124.302 85.1484 130.956H137.666V19.8491H81.7631C69.8593 38.0561 63.09 58.3628 60.5227 79.8369H85.6159C83.5135 88.0061 82.2306 96.0597 81.9968 103.995ZM33.9128 36.6549H41.0328V53.1115H33.9128V36.6549Z" fill="#00ACEC"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M93.1994 36.6549H100.321V53.1114H93.1994V36.6549Z" fill="black"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M81.9968 103.995C95.3018 102.711 106.972 99.21 114.092 94.6585L118.294 100.027C108.489 105.747 95.7679 109.48 81.9968 110.881C82.5799 123.251 85.6159 135.506 91.6827 147.294L85.6159 150.56C74.5289 133.055 72.5447 109.48 76.3949 87.1893H52.0029C55.0376 52.5271 68.3413 23.1162 87.7156 0.24231L92.9656 4.79372C74.0614 26.7353 63.6743 52.2947 60.5227 79.8369H85.6159C83.5135 88.0061 82.2306 96.0597 81.9968 103.995Z" fill="black"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M74.6458 104.463V111.349C56.6712 112.049 37.2982 108.665 20.9598 100.494L24.3438 95.126C40.2147 102.595 58.4217 105.162 74.6458 104.463Z" fill="#00ACEC"/>
</svg>	
		نسخه MacOS
			
		</a>

		<a href="https://app.spotplayer.ir/assets/bin/spotplayer/setup.apk" target="_blank">
		<svg xmlns="http://www.w3.org/2000/svg" width="224" height="256" viewBox="0 0 224 256" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M142.544 51.1992C138.32 51.1992 134.907 47.8765 134.907 43.7698C134.907 39.6632 138.32 36.3459 142.544 36.3459C146.768 36.3459 150.181 39.6578 150.181 43.7698C150.181 47.8765 146.768 51.1992 142.544 51.1992ZM81.456 51.1992C77.232 51.1992 73.8187 47.8765 73.8187 43.7698C73.8187 39.6632 77.232 36.3459 81.456 36.3459C85.68 36.3459 89.0933 39.6578 89.0933 43.7698C89.0933 47.8765 85.68 51.1992 81.456 51.1992ZM147.056 21.4339L149.904 17.2472L152.752 13.1245L159.093 3.84985C159.279 3.58018 159.408 3.27564 159.473 2.95464C159.538 2.63363 159.537 2.30285 159.47 1.98228C159.403 1.66171 159.272 1.35802 159.084 1.08958C158.897 0.821145 158.657 0.593534 158.379 0.420516C157.82 0.0523087 157.139 -0.0813641 156.482 0.0483534C155.825 0.178071 155.246 0.560774 154.869 1.11385L145.189 15.2152L142.283 19.4605C132.598 15.8425 122.338 14.0067 112 14.0419C101.662 14.008 91.4024 15.8438 81.7173 19.4605L78.8267 15.2152L75.968 11.0445L69.1627 1.11385C68.7756 0.564064 68.1911 0.185019 67.5313 0.0558308C66.8714 -0.0733573 66.1872 0.0572946 65.6213 0.420516C65.3462 0.596101 65.109 0.824932 64.9236 1.09355C64.7382 1.36217 64.6084 1.66515 64.5418 1.98467C64.4752 2.30419 64.4732 2.6338 64.5358 2.95412C64.5985 3.27443 64.7246 3.57898 64.9067 3.84985L71.2533 13.1245L74.096 17.2472L76.9547 21.4339C55.3547 31.2312 40.736 49.7912 40.736 71.0285H183.264C183.264 49.7912 168.651 31.2312 147.061 21.4285L147.056 21.4339ZM41.8293 80.9325H40.736V189.935C40.736 198.591 47.9573 205.637 56.864 205.637H68.496C68.0845 206.972 67.8759 208.362 67.8773 209.759V241.141C67.8773 249.349 74.7307 255.999 83.1573 255.999C91.584 255.999 98.4373 249.349 98.4373 241.141V209.759C98.4373 208.319 98.2027 206.943 97.8187 205.637H126.181C125.783 206.974 125.58 208.363 125.579 209.759V241.141C125.579 249.349 132.416 255.999 140.843 255.999C149.285 255.999 156.139 249.349 156.139 241.141V209.759C156.139 208.319 155.904 206.943 155.504 205.637H167.152C176.059 205.637 183.264 198.597 183.264 189.935V80.9325H41.8293ZM15.28 80.9325C6.83733 80.9325 0 87.5832 0 95.7859V159.381C0 167.583 6.83733 174.239 15.28 174.239C23.7067 174.239 30.544 167.583 30.544 159.381V95.7859C30.544 87.5832 23.7067 80.9325 15.28 80.9325ZM208.736 80.9325C200.293 80.9325 193.456 87.5832 193.456 95.7859V159.381C193.456 167.583 200.293 174.239 208.736 174.239C217.163 174.239 224 167.583 224 159.381V95.7859C224 87.5832 217.163 80.9325 208.736 80.9325Z" fill="#95CF00"/>
</svg>	
		نسخه Android</a>
	</div>
	 <?php
	}
	?>
  
  
  

  <div class="nselementory-section">
  <div class="nscourse-section">
  
	<div class="nscourse-section-title-elementory <?php if (  'yes' == $settings['arrowsection'] ) : echo('cursor-pointer'); ?><?php endif; ?>" >
	  <?php echo '<img src="' . $settings['image']['url'] . '">'; ?>
	  <div class="nsgheadlinel">
		  <?php     echo '<' . $tag . ' class="nstitleseson">' . $settings['titlelesson'] . '</' . $tag . '>'; ?>
		<p class="nssubtitle-lesson"><?php echo $settings['subtitlelesson']; ?> </p>

	  </div>
	  <i class="nsarrowicon">
	  <?php	\Elementor\Icons_Manager::render_icon( $settings['nsarrowicon'], [ 'aria-hidden' => 'true' ] );
?>
</i>
	</div>
  
	<div class="nspanel-group <?php if (  'yes' == $settings['arrowsection'] ) : echo('deactive'); ?><?php endif; ?>">
	<?php foreach (  $settings['lessons_list'] as $lesson_single ): ?>
	  <div class="nscourse-panel-heading">
		<div class="nspanel-heading-left">
		  <div class="nscourse-lesson-icon">
		<i class="ns-icon-wrapper">
   			 <?php \Elementor\Icons_Manager::render_icon( $lesson_single['singlelessonicon'], [ 'aria-hidden' => 'true' ] ); 
 ?>
 
		</i>
		  </div>
  
		  <div class="nstitle">
			  <?php    echo '<' . $tagsub . ' class="nsstitlecourse">' . $lesson_single['subtitlelesson'] . '</' . $tagsub . '>';?>
    <span class="nsbadge-item"><?php echo $lesson_single['label_lesson']; ?></span>
			<p class="nssubtitle"> <?php echo $lesson_single['subtitlelesson_sub']; ?></p>
		  </div>
  
		</div>
  
		<div class="panel-heading-right">
		<?php if( $lesson_single["private_lesson"] !== "no" ): ?>

<div class="ns-private-lesson">
<?php if($bought_course): ?>

	<i class="ns-unprivate-icon">
  <?php 
//nias fix icon load in elementor
  \Elementor\Icons_Manager::render_icon( $settings['unprivateicon'], [ 'aria-hidden' => 'true' ] ); 
	?>
	</i>
	
  <?php  else : ?>

	<i class="ns-private-icon">
  <?php 
//nias fix icon load in elementor
  \Elementor\Icons_Manager::render_icon( $settings['privateicon'], [ 'aria-hidden' => 'true' ] ); 
	?>
	</i>

<?php endif; ?>


<span>
<?php if($bought_course): ?>
<?php echo $settings['nsdastresi']; ?>
<?php else : ?>
<?php echo $settings['nskhososi']; ?>
<?php endif; ?>
</span>

</div>
<?php endif; ?>
		  <?php
		  $preview_video = $lesson_single['preview_video']['url'];
		  if(!empty($preview_video)): ?>
		  <a class="video-lesson-preview preview-button" href="<?php echo esc_url( $preview_video ); ?>">

		  <i class="nspreviewicon">
		 	 	<?php 
//nias preview icon
				  \Elementor\Icons_Manager::render_icon( $settings['nspreviewicon'], [ 'aria-hidden' => 'true' ] ); 
				?>

		  </i>
		  <span class="nsspanpreviewtext">
		  <?php echo $settings['nspreviewtext']; ?>
		  </span>
		</a>
		  <?php endif; ?>
		
  
  
  
		  <?php
				$download_lesson = $lesson_single['download_lesson']['url'];
				$download_lesson = apply_filters('wcpl_download_lesson', $download_lesson);
				  if(!empty($download_lesson)):
		  ?>
				<?php if($bought_course): ?>
			<a class="nsdownload-button" href="<?php echo esc_url( $download_lesson ); ?>">
			<i class="nsdownload-icon">
			<?php 
//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
			</a>
				<?php elseif ($lesson_single["private_lesson"] !== "yes") : ?>
			<a class="nsdownload-button" href="<?php echo esc_url( $download_lesson ); ?>">
			<i>
			<?php 
//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
			</a>
		  <?php elseif ($lesson_single["private_lesson"] !== "no") : ?>
			<div class="nsdownload-button gray">
						
			<i>
			<?php 
			//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
					</div>
				<?php endif; ?>
				<?php endif; ?>
  
  
  
			
  
  
  

  
		</div>
  
	</div>
  
	<div class="nspanel-content">
	  <div class="nspanel-content-inner">
  
		<?php
		if( $lesson_single["private_lesson"] !== "no" ) {
		if($bought_course) {
		 echo $lesson_single['lesson_content'];
	   } else {
		echo $settings['nsprivatetextcontent']; 
	   }
	 } elseif ( $lesson_single["private_lesson"] !== "yes" ) {
		 echo $lesson_single['lesson_content'];
	 }
	 ?>
  
	 </div>
   </div>
 
 
 
 <?php endforeach; ?>
 </div>
 </div>
 </div>
 
	<?php }
 
 }
  
  