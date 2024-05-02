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

		
		   $this->add_control(
			'icon',
			[
				'label' => esc_html__( 'آیکن درس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::ICONS,
				'default' => [
					'value' => 'fas fa-circle',
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
   
		$repeater->add_control(
			'singlelessonicon',
			[
				'label' => esc_html__( 'آیکن مخصوص این درس', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::ICONS,
				'default' => [
					'value' => 'far fa-circle', // Default icon (far fa-circle is a regular circle)
					'library' => 'fa-regular', // Default library (Font Awesome regular)
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
   
   
   

		}
 


	 protected function render() {

		// get our input from the widget settings.
		$settings = $this->get_settings_for_display();
		   $tag = $settings['tag_selector_titlelesson'];
		 $tagsub = $settings['tag_selector_subtitlelesson'];
		$bought_course = false;
		$current_user = wp_get_current_user();

	 
  
  
  
  
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
  
  
	?>
  
  
  
  
  
  <div class="elementory-section">
  <div class="course-section">
  
	<div class="course-section-title-elementory <?php if (  'yes' == $settings['arrowsection'] ) : echo('cursor-pointer'); ?><?php endif; ?>" >
	  <?php echo '<img src="' . $settings['image']['url'] . '">'; ?>
	  <div class="gheadlinel">
		  <?php     echo '<' . $tag . '>' . $settings['titlelesson'] . '</' . $tag . '>'; ?>
		<p class="subtitle-lesson"><?php echo $settings['subtitlelesson']; ?> </p>

	  </div>
	  <?php if (  'yes' == $settings['arrowsection'] ) : echo($arrow_section); ?><?php endif; ?>
	</div>
  
	<div class="panel-group <?php if (  'yes' == $settings['arrowsection'] ) : echo('deactive'); ?><?php endif; ?>">
	<?php foreach (  $settings['lessons_list'] as $lesson_single ): ?>
	  <div class="course-panel-heading">
		<div class="panel-heading-left">
		  <div class="course-lesson-icon">
					<i class="ns-icon-wrapper">
			<?php

			//nias fix icon load in elementor
			\Elementor\Icons_Manager::render_icon( $settings['icon'], [ 'aria-hidden' => 'true' ] ); 
			
			
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
		  <a class="video-lesson-preview preview-button" href="<?php echo esc_url( $preview_video ); ?>"><i class="fa fa-play-circle"></i><?php esc_html_e( 'پیش نمایش', 'nias-course-widget' ); ?></a>
		  <a class="video-lesson-preview preview-button for-mobile" href="<?php echo esc_url( $preview_video ); ?>"><i class="fa fa-play-circle"></i></a>
		  <?php endif; ?>
		
  
  
  
		  <?php
				$download_lesson = $lesson_single['download_lesson']['url'];
				$download_lesson = apply_filters('wcpl_download_lesson', $download_lesson);
				  if(!empty($download_lesson)):
		  ?>
				<?php if($bought_course): ?>
			<a class="download-button" href="<?php echo esc_url( $download_lesson ); ?>"><i class="fa fa-download"></i></a>
				<?php elseif ($lesson_single["private_lesson"] !== "yes") : ?>
			<a class="download-button" href="<?php echo esc_url( $download_lesson ); ?>"><i class="fa fa-download"></i></a>
		  <?php elseif ($lesson_single["private_lesson"] !== "no") : ?>
					<div class="download-button gray"><i class="fa fa-download"></i></div>
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
			<?php esc_html_e('دسترسی دارید', 'nias-course-widget'); ?>
			 <?php else : ?>
			  <?php  esc_html_e('خصوصی', 'nias-course-widget'); ?>
			<?php endif; ?>
		  </span>
  
		  </div>
		  <?php endif; ?>
  
		</div>
  
	</div>
  
	<div class="panel-content">
	  <div class="panel-content-inner">
  
		<?php
		if( $lesson_single["private_lesson"] !== "no" ) {
		if($bought_course) {
		 echo $lesson_single['lesson_content'];
	   } else {
		 esc_html_e( 'این دوره خصوصی است برای دسترسی کامل باید دوره را خریداری کنید', 'nias-course-widget' );
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
  
  