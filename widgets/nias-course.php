<?php
namespace Nias_Course;

// nias-course.php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Nias course widget.
 *
 * render course widget in product page.
 *
 * @since 1.0.0
 */


// Define the function to fetch user orders meta values
function get_user_orders_meta_values($user_id, $product_id) {
    $orders = wc_get_orders(array(
        'customer' => $user_id,
    ));

    $meta_values = array();

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_product_id() == $product_id) {
                $meta_data = $order->get_meta('_spotplayer_data', true);
                if ($meta_data) {
                    $unserialized_data = maybe_unserialize($meta_data);
                    if ($unserialized_data !== false) {
                        $meta_values[] = array(
                            'name' => $unserialized_data['name'],
                            'watermark' => $unserialized_data['watermark']['texts'][0]['text'],
                            'key' => $unserialized_data['key']
                        );
                    }
                }
                break;
            }
        }
    }

    return $meta_values;
}

// Define the function to display spot license
function showspotlisence($meta_values) {
    if (!empty($meta_values)) {
        echo '<div class="ns-spotlicense">
        <p class="ns-spotconfirm">لایسنس این دوره با اطلاعات زیر برای شما ثبت شد</p>';
        foreach ($meta_values as $meta_value) {
            echo '<p class="ns-spotuserinfo"> نام: ' . esc_attr($meta_value['name']) . '</p>';
            echo '<p class="ns-spotuserinfo">واترمارک: ' . esc_attr($meta_value['watermark']) . '</p>';
            echo '
            <p class="ns-spotuserinfo">کلید لایسنس:</p>
            <textarea class="nsspotlicense" readonly rows="3">' . esc_attr($meta_value['key']) . '</textarea>
            <button class="nsspotcopybtn">کپی لایسنس</button>
            ';
        }
        echo '</div>';
    } else {
        return;
    }
}

// Define the function to display spot download box
function showspotdlbox($meta_values) {
    if (!empty($meta_values)) {
        echo '<p class="ns-spotdltitle">دانلود برنامه اسپات پلیر جهت مشاهده دوره</p>
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
		</div>';
    } else {
        return;
    }
}


require_once( __DIR__ . '/nias-render.php' );
require_once( __DIR__ . '/nias-controls.php' );
class Nias_course_widget extends \Elementor\Widget_Base {

	public function __construct($data = [], $args = null) {
        parent::__construct($data, $args);
  
        wp_register_script('nscourse-js', plugin_dir_url(__DIR__) . 'assets/niascourse.js', array('jquery'), false);
        wp_enqueue_style('nscourse-css', plugin_dir_url(__DIR__) . 'assets/niascourse.css');

     }

	public function get_name() {
		return 'niaslessons';
	 }
  
	 public function get_title() {
		return esc_html__( 'دوره ساز نیاس', 'nias-course-widget' );
	 }
  
	 public function get_icon() {
		  return 'nias-course-maker';
	 }
  
	 public function get_categories() {
		return [ 'nias-widget-category' ];
	}
	
	public function get_script_depends() {
		return [ 'nscourse-js' ];
	}

	public function get_style_depends() {
		return [ 'nscourse-css' ];
	}
    // ارث‌بری از کلاس Nias_course_render
    use Nias_course_render;

    // ارث‌بری از کلاس Nias_course_controls
    use Nias_course_controls;

}




  
  