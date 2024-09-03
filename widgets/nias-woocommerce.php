<?php
namespace Nias_Course;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Nias_course_woocommerce extends \Elementor\Widget_Base {

	// Widget name
	public function get_name() {
		return 'niaslessonswoo';
	}

	// Widget title
	public function get_title() {
		return esc_html__( 'دوره ووکامرس', 'nias-course-widget' );
	}

	// Widget icon
	public function get_icon() {
		return 'eicon-navigator';
	}

	// Widget categories
	public function get_categories() {
		return [ 'nias-widget-category' ];
	}

	// Script dependencies
	public function get_script_depends() {
		return [ 'nscourse-js' ];
	}

	// Style dependencies
	public function get_style_depends() {
		return [ 'nscourse-css' ];
	}

	// Widget controls
	protected function _register_controls() {
		// Add your widget controls here
		$this->start_controls_section(
			'content_section',
			[
				'label' => esc_html__( 'Content', 'nias-course-widget' ),
				'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'title',
			[
				'label' => esc_html__( 'Title', 'nias-course-widget' ),
				'type' => \Elementor\Controls_Manager::TEXT,
				'default' => esc_html__( 'WooCommerce Course', 'nias-course-widget' ),
				'placeholder' => esc_html__( 'Enter your title here', 'nias-course-widget' ),
			]
		);

		$this->end_controls_section();
	}

	// Widget output
	protected function render() {
        $post_id = get_the_ID();
        $sections = get_post_meta($post_id, 'nias_course_sections_list', true);
        
        if ($sections) : ?>
            <div id="nias_course_sections">
                <?php foreach ($sections as $index => $section) : ?>
                    <div class="nias_course_section">
                        <div class="section_header">
                            <h3 class="section_title"><?php echo esc_html($section['section_title']); ?></h3>
                            <p class="section_subtitle"><?php echo esc_html($section['section_subtitle']); ?></p>
                            <button class="toggle_section"><?php _e('باز/بسته', 'nias-course-widget'); ?></button>
                        </div>
                        <div class="section_content" style="display: none;">
                            <?php if (!empty($section['lessons'])) : ?>
                                <ul class="lessons_list">
                                    <?php foreach ($section['lessons'] as $lesson) : ?>
                                        <li class="lesson_item">
                                            <div class="lesson_header">
                                                <?php if (!empty($lesson['lesson_icon'])) : ?>
                                                    <img src="<?php echo esc_url($lesson['lesson_icon']); ?>" alt="<?php echo esc_attr($lesson['lesson_title']); ?>" />
                                                <?php endif; ?>
                                                <div class="nias-right-head">
                                                <h4 class="lesson_title"><?php echo esc_html($lesson['lesson_title']); ?></h4>
                                                <p class="lesson_label"><?php echo esc_html($lesson['lesson_label']); ?></p>
                                                </div>

                                                <?php if (!empty($lesson['lesson_preview_video'])) : ?>
                                                    <a href="<?php echo esc_url($lesson['lesson_preview_video']); ?>">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M14.9694 10.2301L12.0694 8.56012C11.3494 8.14012 10.4794 8.14012 9.75938 8.56012C9.03938 8.98012 8.60938 9.72012 8.60938 10.5601V13.9101C8.60938 14.7401 9.03938 15.4901 9.75938 15.9101C10.1194 16.1201 10.5194 16.2201 10.9094 16.2201C11.3094 16.2201 11.6994 16.1201 12.0594 15.9101L14.9594 14.2401C15.6794 13.8201 16.1094 13.0801 16.1094 12.2401C16.1294 11.4001 15.6994 10.6501 14.9694 10.2301Z" fill="#FF0000"/>
                                                    </svg>
                                                    <path opacity="0.4" d="M11.9707 22C17.4936 22 21.9707 17.5228 21.9707 12C21.9707 6.47715 17.4936 2 11.9707 2C6.44786 2 1.9707 6.47715 1.9707 12C1.9707 17.5228 6.44786 22 11.9707 22Z" fill="#FF0000"/>

                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($lesson['lesson_download'])) : ?>
                                                    <a href="<?php echo esc_url($lesson['lesson_download']); ?>">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M15.8798 12.43L12.5298 15.78C12.2398 16.07 11.7598 16.07 11.4698 15.78L8.11984 12.43C7.82984 12.14 7.82984 11.66 8.11984 11.37C8.40984 11.08 8.88984 11.08 9.17984 11.37L11.2498 13.44V2.75C11.2498 2.34 11.5898 2 11.9998 2C12.4098 2 12.7498 2.34 12.7498 2.75V13.44L14.8198 11.37C14.9698 11.22 15.1598 11.15 15.3498 11.15C15.5398 11.15 15.7298 11.22 15.8798 11.37C16.1798 11.66 16.1798 12.13 15.8798 12.43Z" fill="#2666CF"/>
                                                    </svg>
                                                    <path opacity="0.4" d="M16.8 9H7.2C4 9 2 11 2 14.2V16.79C2 20 4 22 7.2 22H16.79C19.99 22 21.99 20 21.99 16.8V14.2C22 11 20 9 16.8 9Z" fill="#2666CF"/>


                                                    </a>
                                                <?php endif; ?>
                                                <button class="toggle_lesson"><?php _e('باز/بسته', 'nias-course-widget'); ?></button>
                                            </div>
                                            <div class="lesson_content" style="display: none;">
                                                <p><?php echo wp_kses_post($lesson['lesson_content']); ?></p>
                                                <?php if ($lesson['lesson_private'] === 'yes') : ?>
                                                    <p><?php _e('این درس خصوصی است.', 'nias-course-widget'); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <style>
            .nias_course_section {
    margin-bottom: 20px;
    border: 1px solid #ddd;
    padding: 10px;
}
.lesson_header img {
    width:50px;
    height:50px;
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

.toggle_section,
.toggle_lesson {
    background-color: #0073aa;
    color: #fff;
    padding: 5px 10px;
    border: none;
    cursor: pointer;
}

        </style>
        <script>
            jQuery(document).ready(function($) {
    // باز و بسته کردن فصل‌ها
    $('.toggle_section').on('click', function() {
        $(this).closest('.nias_course_section').find('.section_content').slideToggle();
    });

    // باز و بسته کردن دروس
    $('.toggle_lesson').on('click', function() {
        $(this).closest('.lesson_item').find('.lesson_content').slideToggle();
    });
});

        </script>
        <?php
	}

	// Editor output
	protected function _content_template() {
		?>
		<# if ( settings.title ) { #>
			<div class="nias-course-widget">
				<h2>{{{ settings.title }}}</h2>
			</div>
		<# } #>
		<?php
	}
}

// Register the widget
\Elementor\Plugin::instance()->widgets_manager->register_widget_type( new Nias_course_woocommerce() );
