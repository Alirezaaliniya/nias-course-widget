<?php
namespace Nias_Course;

// nias-render.php
trait Nias_course_render {
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
            $current_user_id = get_current_user_id();
            $current_product_id = get_the_ID();
            $meta_values = get_user_orders_meta_values($current_user_id, $current_product_id);
            showspotlisence($meta_values);		}

if (  'yes' == $settings['ns_show_spotdl'] ) {
    $current_user_id = get_current_user_id();
    $current_product_id = get_the_ID();
    $meta_values = get_user_orders_meta_values($current_user_id, $current_product_id);
    showspotdlbox($meta_values);
}




	?>  
  <div class="nselementory-section">
  <div class="nscourse-section">
  
  <div class="nscourse-section-title-elementory <?php if ('yes' == $settings['arrowsection']) echo esc_attr(' cursor-pointer'); ?>">
	<?php echo '<img src="' . esc_url($settings['image']['url']) . '">'; ?>
	  <div class="nsgheadlinel">
	  <?php echo '<' . esc_html($tag) . ' class="nstitleseson">' . esc_html($settings['titlelesson']) . '</' . esc_html($tag) . '>'; ?>
	  <p class="nssubtitle-lesson"><?php echo esc_html($settings['subtitlelesson']); ?></p>

	  </div>
	  <i class="nsarrowicon">
	  <?php	\Elementor\Icons_Manager::render_icon( $settings['nsarrowicon'], [ 'aria-hidden' => 'true' ] );
?>
</i>
	</div>
  
	<div class="nspanel-group <?php if ('yes' == $settings['arrowsection']) echo esc_attr('deactive'); ?>">
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
		  <?php echo '<' . esc_html($tagsub) . ' class="nsstitlecourse">' . esc_html($lesson_single['subtitlelesson']) . '</' . esc_html($tagsub) . '>';?>
			  <span class="nsbadge-item"><?php echo esc_html($lesson_single['label_lesson']); ?></span>
			  <p class="nssubtitle"><?php echo esc_html($lesson_single['subtitlelesson_sub']); ?></p>
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
<?php echo esc_html($settings['nsdastresi']); ?>
<?php else : ?>
<?php echo esc_html($settings['nskhososi']); ?>
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
		  <?php echo esc_html($settings['nspreviewtext']); ?>
		  </span>
		</a>
		  <?php endif; ?>
		
  
  
  
		  <?php

if (is_product()) {
global $product;
$current_product_id = $product->get_id();

// Get downloadable products for the user
$woo_downloads = WC()->customer->get_downloadable_products();

// Flag to track if any downloadable files were found
$has_downloads = false;

$selectedfile = $lesson_single['downloads_list'];

$product = wc_get_product(); // Get the current product
$current_product_id = $product->get_id(); // Get the current product ID
$files = $product->get_downloads(); // Get the downloadable files

// Loop through downloadable products
foreach ($woo_downloads as $download) {
    if ($download['product_id'] == $current_product_id) {
        $has_downloads = true;

        // Escape and sanitize download URL and file name
        $download_url = esc_url($download['download_url']);
       // $file_name = esc_html($download['file']['name']);

        // Render the download link within the loop for each downloadable file
        foreach ($files as $file_key => $file) {
			if ($file_key === $selectedfile){
            // Only render the anchor tag for the file that matches the download
            if ($download['file']['name'] == $file['name']) {
                ?>
                <a href="<?php echo $download_url; ?>" class="nsdownload-button" data-key="<?php echo esc_attr($file_key); ?>">
				<i class="nsdownload-icon">
			<?php 
//nias download icon
				  \Elementor\Icons_Manager::render_icon( $settings['nsdownloadicon'], [ 'aria-hidden' => 'true' ] ); 
			?>
			</i>
                </a>
                <?php
                break; // Stop the loop once the matching file is found
            }
        }
	}
    }
}
}




		  
				$download_lesson = $lesson_single['download_lesson']['url'];
				$download_lesson = apply_filters('nias_course_download_lesson', $download_lesson);
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
$allowed_tags = array(
    'iframe' => array(
        'src'             => true,
        'width'           => true,
        'height'          => true,
        'frameborder'     => true,
        'allowfullscreen' => true,
    ),
    // Include other tags that you want to allow
);
if ($lesson_single["private_lesson"] !== "no") {
if ($bought_course) {
echo $lesson_single['lesson_content'];
} else {
	echo $settings['nsprivatetextcontent'];
}
} elseif ($lesson_single["private_lesson"] !== "yes") {
echo $lesson_single['lesson_content'];
}
?>
</div>
   </div>
 
 
 
 <?php endforeach; ?>
 </div>
 </div>
 </div>
 
	<?php 
 
    }
}

?>
