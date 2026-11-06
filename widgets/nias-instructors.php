<?php

namespace Nias_Course;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elementor widget «مدرس دوره»: the instructors of the current course
 * (WooCommerce product or «دوره»), for single-product / single-course
 * templates. Same output as [nias_course_instructors].
 */
class Nias_course_instructors extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'nias_course_instructors';
    }

    public function get_title()
    {
        return esc_html__('مدرس دوره', 'nias-course-widget');
    }

    public function get_icon()
    {
        return 'eicon-person';
    }

    public function get_categories()
    {
        return array('nias-widget-category');
    }

    public function get_keywords()
    {
        return array('instructor', 'teacher', 'مدرس', 'استاد');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => esc_html__('مدرس دوره', 'nias-course-widget')));
        $this->add_control('layout', array(
            'label'   => esc_html__('نمایش', 'nias-course-widget'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'inline',
            'options' => array('inline' => esc_html__('یک خط (نام و تصویر)', 'nias-course-widget'), 'card' => esc_html__('کارت با بیوگرافی', 'nias-course-widget')),
        ));
        $this->add_control('avatar', array(
            'label'        => esc_html__('تصویر مدرس', 'nias-course-widget'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('size', array(
            'label'   => esc_html__('اندازهٔ تصویر', 'nias-course-widget'),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 36, 'min' => 16, 'max' => 160,
            'condition' => array('avatar' => 'yes'),
        ));
        $this->add_control('label', array(
            'label'       => esc_html__('برچسب', 'nias-course-widget'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => esc_html__('خالی = «مدرس:» / «مدرسین:»', 'nias-course-widget'),
        ));
        $this->add_control('hide_label', array(
            'label'        => esc_html__('بدون برچسب', 'nias-course-widget'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ));
        $this->add_control('link', array(
            'label'        => esc_html__('لینک به صفحهٔ نویسنده', 'nias-course-widget'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ));
        $this->add_control('course_id', array(
            'label'       => esc_html__('شناسهٔ دوره (اختیاری)', 'nias-course-widget'),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'description' => esc_html__('خالی = همین دوره/محصول', 'nias-course-widget'),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style', array('label' => esc_html__('ظاهر', 'nias-course-widget'), 'tab' => \Elementor\Controls_Manager::TAB_STYLE));
        $this->add_control('name_color', array('label' => esc_html__('رنگ نام', 'nias-course-widget'), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .nias-ci-u, {{WRAPPER}} .nias-ci-card b' => 'color: {{VALUE}}')));
        $this->add_control('label_color', array('label' => esc_html__('رنگ برچسب', 'nias-course-widget'), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array('{{WRAPPER}} .nias-ci-l, {{WRAPPER}} .nias-ci-card small' => 'color: {{VALUE}}')));
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), array('name' => 'typo', 'selector' => '{{WRAPPER}} .nias-ci, {{WRAPPER}} .nias-ci-card b'));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s   = $this->get_settings_for_display();
        $pid = !empty($s['course_id']) ? (int) $s['course_id'] : (int) get_the_ID();
        if (!$pid || !function_exists('nias_course_instructors_html') || !nias_is_course_post($pid)) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<div style="padding:10px;border:1px dashed #c3c4c7;border-radius:8px;color:#646970">' . esc_html__('مدرس دوره: این ویجت در قالب تک‌محصول/تک‌دوره، نام مدرس همان دوره را نشان می‌دهد.', 'nias-course-widget') . '</div>';
            }
            return;
        }
        $html = nias_course_instructors_html($pid, array(
            'avatar' => $s['avatar'] === 'yes' ? 'yes' : 'no',
            'size'   => isset($s['size']) ? (int) $s['size'] : 36,
            'label'  => $s['hide_label'] === 'yes' ? '' : ($s['label'] !== '' ? $s['label'] : 'default'),
            'link'   => $s['link'] === 'yes' ? 'author' : 'none',
            'layout' => $s['layout'] === 'card' ? 'card' : 'inline',
        ));
        if ($html === '' && \Elementor\Plugin::$instance->editor->is_edit_mode()) {
            $html = '<div style="padding:10px;border:1px dashed #c3c4c7;border-radius:8px;color:#646970">' . esc_html__('برای این دوره مدرسی انتخاب نشده است (ویرایش جلسات و فصل‌ها › مدرسین دوره).', 'nias-course-widget') . '</div>';
        }
        echo $html; // phpcs:ignore -- escaped in nias_course_instructors_html()
    }
}
