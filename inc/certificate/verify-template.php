<?php
/**
 * Template for the built-in certificate verification page (?nias_verify=CODE)
 * in classic themes: the site's own header and footer around the form.
 *
 * @package nias-course-widget
 */
if (!defined('ABSPATH')) {
    exit;
}
get_header();
echo '<main class="nias-verify-main" style="padding:30px 16px">'
    . nias_cert_verify_shortcode(array('param' => NIAS_CERT_VERIFY_QV, 'action' => home_url('/'))) // phpcs:ignore -- escaped inside
    . '</main>';
get_footer();
