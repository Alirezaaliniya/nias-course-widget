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
		?>
     <div>
		<p>لایسنس این دوره با اطلاعات زیر برای شما ثبت شد</p>
		<?php
        foreach ( $meta_values as $meta_value ) {
            echo '
			
            نام: ' . esc_html( $meta_value['name'] ) . '</p>';
            echo '<p>واترمارک: ' . esc_html( $meta_value['watermark'] ) . '</p>';
            echo '
			<label>کلید لایسنس:</label>
			<textarea>' . esc_html( $meta_value['key'] ) . '</textarea>
			<button>کپی لایسنس</button>
			';
        }
        echo '</div>';
    } else {
        echo '<p>لایسنس این دوره برای شما در اسپات ثبت نشده است</p>';
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
   
		
		 $this->add_control(
			'icon',
			[
				'label' => esc_html__( 'آیکن درس', 'nias-course-widget' ),
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
///nias notice about icon lesson publicc
		$this->add_control(
			'ns_icon_notice',
			[
				'type' => \Elementor\Controls_Manager::NOTICE,
				'notice_type' => 'info',
				'dismissible' => true,
				'heading' => esc_html__( 'آیکن درس', 'nias-course-widget' ),
				'content' => esc_html__( 'این آیکن برای تمام دروس نمایش داده میشود', 'nias-course-widget' ),
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

		 $this->add_control(
			'nspreviewtext',
			[
				'label' => esc_html__( 'متن دکمه پیش نمایش', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'پیش نمایش', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'پیش نمایش', 'nias-course-widget' ),
			]
		);
		$this->add_control(
			'nsdastresi',
			[
				'label' => esc_html__( 'متن دسترسی به دوره', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'دسترسی دارید', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'دسترسی دارید', 'nias-course-widget' ),
			]
		);
		$this->add_control(
			'nskhososi',
			[
				'label' => esc_html__( 'متن عدم دسترسی به دوره', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'خصوصی', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'خصوصی', 'nias-course-widget' ),
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
				'name' => 'background',
				'types' => [ 'classic', 'gradient', 'video' ],
				'selector' => '{{WRAPPER}} .nscourse-section',
			]
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'border',
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
						'step' => 5,
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
		$arrow_section = "<i class='fas fa-chevron-down'></i>";
  
		if (  'yes' == $settings['ns_show_spot'] ) {
			niasspotdata();
		}
	?>
  
  
  

  <div class="nselementory-section">
  <div class="nscourse-section">
  
	<div class="nscourse-section-title-elementory <?php if (  'yes' == $settings['arrowsection'] ) : echo('cursor-pointer'); ?><?php endif; ?>" >
	  <?php echo '<img src="' . $settings['image']['url'] . '">'; ?>
	  <div class="nsgheadlinel">
		  <?php     echo '<' . $tag . '>' . $settings['titlelesson'] . '</' . $tag . '>'; ?>
		<p class="nssubtitle-lesson"><?php echo $settings['subtitlelesson']; ?> </p>

	  </div>
	  <?php if (  'yes' == $settings['arrowsection'] ) : echo($arrow_section); ?><?php endif; ?>
	</div>
  
	<div class="nspanel-group <?php if (  'yes' == $settings['arrowsection'] ) : echo('deactive'); ?><?php endif; ?>">
	<?php foreach (  $settings['lessons_list'] as $lesson_single ): ?>
	  <div class="nscourse-panel-heading">
		<div class="nspanel-heading-left">
		  <div class="nscourse-lesson-icon">
					<i class="ns-icon-wrapper">
			<?php
			//nias fix icon load in elementor
			\Elementor\Icons_Manager::render_icon( $settings['icon'], [ 'aria-hidden' => 'true' ] ); 
			?>
		</i>
		<i class="ns-icon-wrapper2">
   			 <?php \Elementor\Icons_Manager::render_icon( $lesson_single['singlelessonicon'], [ 'aria-hidden' => 'true' ] ); 
 ?>
		</i>
		  </div>
  
		  <div class="title">
			  <?php    echo '<' . $tagsub . '>' . $lesson_single['subtitlelesson'] . '</' . $tagsub . '>';?>
    <span class="badge-item"><?php echo $lesson_single['label_lesson']; ?></span>
			<p class="subtitle"> <?php echo $lesson_single['subtitlelesson_sub']; ?></p>
		  </div>
  
		</div>
  
		<div class="panel-heading-right">
	
		  <?php
		  $preview_video = $lesson_single['preview_video']['url'];
		  if(!empty($preview_video)): ?>
		  <a class="video-lesson-preview preview-button" href="<?php echo esc_url( $preview_video ); ?>">

		  <i>
		 	 	<?php 
//nias preview icon
				  \Elementor\Icons_Manager::render_icon( $settings['nspreviewicon'], [ 'aria-hidden' => 'true' ] ); 
				?>

		  </i>
		  
		  <?php echo $settings['nspreviewtext']; ?>
		
		</a>
		  <a class="video-lesson-preview preview-button for-mobile" href="<?php echo esc_url( $preview_video ); ?>">
		  
		  <i>
		  <?php 
//nias preview icon
				  \Elementor\Icons_Manager::render_icon( $settings['nspreviewicon'], [ 'aria-hidden' => 'true' ] ); 
				?>

		  </i>
		
		</a>
		  <?php endif; ?>
		
  
  
  
		  <?php
				$download_lesson = $lesson_single['download_lesson']['url'];
				$download_lesson = apply_filters('wcpl_download_lesson', $download_lesson);
				  if(!empty($download_lesson)):
		  ?>
				<?php if($bought_course): ?>
			<a class="download-button" href="<?php echo esc_url( $download_lesson ); ?>">
			<i>
			<?php 
//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
			</a>
				<?php elseif ($lesson_single["private_lesson"] !== "yes") : ?>
			<a class="download-button" href="<?php echo esc_url( $download_lesson ); ?>">
			<i>
			<?php 
//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
			</a>
		  <?php elseif ($lesson_single["private_lesson"] !== "no") : ?>
			<div class="download-button gray">
						
			<i>
			<?php 
			//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
					</div>
				<?php endif; ?>
				<?php endif; ?>
  
  
  
			
  
  
  
		  <?php if( $lesson_single["private_lesson"] !== "no" ): ?>

				<div class="ns-private-lesson">
				<?php if($bought_course): ?>

					<i class="ns-icon-wrapper">
				  <?php 
//nias fix icon load in elementor
				  \Elementor\Icons_Manager::render_icon( $settings['unprivateicon'], [ 'aria-hidden' => 'true' ] ); 
					?>
					</i>
					
				  <?php  else : ?>

					<i class="ns-icon-wrapper">
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
  
  