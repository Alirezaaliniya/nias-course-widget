<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «ماژول‌ها» settings page: optional features, each switched on separately.
 * A module's code runs only when it is on.
 *
 * @package nias-course-widget
 */

function nias_modules_fields()
{
    return array(
        'nias_drip_enabled' => 'radio',
        'nias_drip_email'   => 'radio',
        'nias_assign_enabled'   => 'radio',
        'nias_stats_enabled'    => 'radio',
        'nias_notes_enabled'        => 'radio',
        'nias_paths_enabled'        => 'radio',
        'nias_transfer_enabled'     => 'radio',
        'nias_live_enabled'         => 'radio',
        'nias_gm_enabled'           => 'radio',
        'nias_gm_board'             => 'radio',
        'nias_gm_names'             => 'radio',
        'nias_rev_enabled'          => 'radio',
        'nias_rev_rate'             => 'text',
        'nias_rev_hold'             => 'number',
        'nias_rev_min'              => 'number',
        'nias_reviews_enabled'      => 'radio',
        'nias_reviews_min_progress' => 'number',
        'nias_reviews_moderate'     => 'radio',
        'nias_inactive_enabled' => 'radio',
        'nias_inactive_days'    => 'text',
        'nias_inactive_hours'   => 'text',
        'nias_inactive_email'   => 'radio',
        'nias_access_enabled'      => 'radio',
        'nias_access_default_days' => 'number',
        'nias_access_remind'       => 'text',
        'nias_access_email'        => 'radio',
        'nias_protect_enabled' => 'radio',
        'nias_protect_send'    => 'radio',
        'nias_dev_enabled'     => 'radio',
        'nias_dev_max'         => 'number',
        'nias_dev_mode'        => 'radio',
        'nias_dev_single'      => 'radio',
        'nias_dev_exempt_inst' => 'radio',
        'nias_dev_self'        => 'radio',
        'nias_dev_removals'    => 'number',
    );
}

add_action('admin_menu', function () {
    add_submenu_page('nias-course-settings', __('Modules', 'nias-course-widget'), __('Modules', 'nias-course-widget'), 'manage_options', 'nias-course-modules', 'nias_modules_page');
}, 30);

/** Modules planned for the next versions (shown as «به‌زودی»). */
function nias_modules_upcoming()
{
    return array(
    );
}

function nias_modules_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = false;
    if (isset($_POST['nias_save_modules']) && check_admin_referer('nias_modules', 'nias_modules_nonce')) {
        nias_course_save_theme_options(nias_modules_fields());
        $saved = true;
    }
    if (function_exists('nias_picker_enqueue')) {
        nias_picker_enqueue();
    }
    $drip_on = function_exists('nias_drip_enabled') && nias_drip_enabled();
    $sms_url = admin_url('admin.php?page=nias-course-sms');
    ?>
    <div class="nias-settings-app" dir="rtl">
        <?php nias_settings_topbar('modules'); ?>
        <div class="nias-set-shell"><div class="nias-set-main">
            <?php nias_set_saved_banner($saved); ?>
            <form method="post">
                <?php wp_nonce_field('nias_modules', 'nias_modules_nonce'); ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Drip content', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_drip_enabled',
                        __('Enable drip content', 'nias-course-widget'),
                        __('After a student enrols, lessons open in the order the instructor sets (day and time); for example the first lesson today, the second tomorrow. Set each lesson in the lesson editor (dashboard and instructor panel) with the "Drip" option. Day 1 is the enrolment day; an empty time means 00:00. The content of a closed lesson is never sent to the browser.', 'nias-course-widget'),
                        'off',
                        __('New', 'nias-course-widget')
                    );
                    nias_set_toggle_row(
                        'nias_drip_email',
                        __('"New lesson opened" email', 'nias-course-widget'),
                        sprintf(__('When a lesson opens for a student, send a notification email with the course link. SMS for this event is set on the <a href="%s">SMS</a> page ("Drip lesson opened" event).', 'nias-course-widget'), esc_url($sms_url)),
                        'off',
                        '',
                        'data-nias-show-when="nias_drip_enabled=on"'
                    );
                    ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="9" r="6" stroke="#3858e9" stroke-width="1.8"/><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Points & badges', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_gm_enabled', __('Enable points and badges', 'nias-course-widget'), __('Students earn points and badges for completing a lesson (+10), passing a quiz (+20), passing an assignment (+30), finishing a course (+50), a review (+5) and a question (+2) (each action once). Below the course page the points, rank, badges and the top students table of that course are shown, and a message appears when a new badge is earned. Shortcode [nias_my_badges] for the user account. Points are counted from the day of activation.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                    <div data-nias-show-when="nias_gm_enabled=on">
                        <?php nias_set_toggle_row('nias_gm_board', __('Top students table', 'nias-course-widget'), __('The top 10 of each course below the course page.', 'nias-course-widget'), 'on'); ?>
                        <div class="nias-row"><div class="nias-field"><label class="nias-flabel"><?php echo esc_html__('Student names in the table', 'nias-course-widget'); ?></label><?php nias_set_segmented('nias_gm_names', array('short' => __('First name + last name initial', 'nias-course-widget'), 'full' => __('Full name', 'nias-course-widget'), 'hidden' => __('Hidden', 'nias-course-widget')), 'short'); ?></div></div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="15" height="14" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="m17 10 5-3v10l-5-3" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Online class', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_live_enabled', __('Enable online class', 'nias-course-widget'), __('A "Live class" option is added to the lesson editor: class link (Skyroom, BigBlueButton, Google Meet, …), start time and duration. Before the class, students see a countdown and "Add to calendar"; the join link is given only from 10 minutes before until the end of the class, and only to people with access to the lesson. An SMS reminder goes out one hour before. Put the recording in the same lesson later so it plays instead of the class card.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="2" y="6" width="20" height="13" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="M2 10h20M6 15h4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Instructor revenue share', 'nias-course-widget')); ?>
                    <?php
                    $wc_ok2 = function_exists('nias_wc_active') && nias_wc_active();
                    nias_set_toggle_row('nias_rev_enabled', __('Enable instructor revenue share', 'nias-course-widget'), $wc_ok2 ? __('When an order is completed, the course instructor\'s (or instructors\') share is recorded in their account and can be withdrawn after the holding period; a refund or cancellation reverses the share. Instructors see their balance, sales and payout requests in the "Income" panel, and the admin pays or rejects requests in the "Instructor earnings" tab. The percentage can be changed per instructor (user edit page) and per course (product page). Sales before activation are not counted.', 'nias-course-widget') : __('This module needs WooCommerce.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget'));
                    ?>
                    <div data-nias-show-when="nias_rev_enabled=on"><div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px">
                        <?php nias_set_number_field('nias_rev_rate', __('Default instructor share (%)', 'nias-course-widget'), __('Of each course\'s sale amount (after discount).', 'nias-course-widget'), 70, 100); ?>
                        <?php nias_set_number_field('nias_rev_hold', __('Holding period (days)', 'nias-course-widget'), __('The share becomes withdrawable after this period.', 'nias-course-widget'), 7, 90); ?>
                        <?php nias_set_number_field('nias_rev_min', __('Minimum payout amount', 'nias-course-widget'), __('In the store\'s currency.', 'nias-course-widget'), 500000); ?>
                    </div></div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="12" height="12" rx="2" stroke="#3858e9" stroke-width="1.8"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="#3858e9" stroke-width="1.8"/></svg>', __('Course copy and transfer', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_transfer_enabled', __('Enable copy and transfer', 'nias-course-widget'), __('"Copy course" and "Export course" are added under each course name in the courses list. Copy: a draft with the same content, lessons, settings and separate copies of the quizzes (the two courses\' results do not mix). Export: a JSON file that is imported on the "Course transfer" page of this site or another site.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="5" cy="6" r="2.5" stroke="#3858e9" stroke-width="1.8"/><circle cx="19" cy="18" r="2.5" stroke="#3858e9" stroke-width="1.8"/><path d="M7.5 6H15a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h7.5" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Prerequisites and learning paths', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_paths_enabled', __('Enable prerequisites and learning paths', 'nias-course-widget'), __('A "Course prerequisite" box is added to each course\'s edit page: courses that must be finished first (100% progress or getting the certificate), and whether buying is allowed before that with only the lessons locked, or buying is not possible either. A "Learning paths" menu is added too: several courses in order with overall progress, shown with the shortcode [nias_path id=""] and on the path\'s own page; the "Sequential" option makes each course depend on the previous one. Admins and the course instructor are never restricted.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 4h16v12H8l-4 4V4Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 9h8M8 12h5" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Timestamped notes and bookmarks', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_notes_enabled', __('Enable notes', 'nias-course-widget'), __('A "Notes" tab is added to video lessons: students add a note at any moment and clicking the note\'s time jumps back to that moment. All notes are shown in "Account › My notes" (or the shortcode [nias_my_notes]) with search, a link to the same moment and a text file download. Notes are private.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/></svg>', __('Buyer ratings and reviews', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_reviews_enabled', __('Enable ratings and reviews', 'nias-course-widget'), __('A "Student reviews" section with the average rating, star distribution and reviews is added below the course page. Only the course\'s own students (after a set progress percentage) can give 1 to 5 stars and a review, and each person has one review they can edit. The instructor and admin reply under each review. The rating is also shown on the "My courses" card and (for products) in WooCommerce ratings and schema.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                    <div data-nias-show-when="nias_reviews_enabled=on">
                        <div class="nias-row"><?php nias_set_number_field('nias_reviews_min_progress', __('Minimum progress to review (%)', 'nias-course-widget'), __('Students cannot rate until they have watched this much of the course.', 'nias-course-widget'), 30, 100); ?></div>
                        <?php nias_set_toggle_row('nias_reviews_moderate', __('Approve reviews before showing', 'nias-course-widget'), __('A new review is not shown until approved in WordPress "Comments".', 'nias-course-widget'), 'off'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Analytics report', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_stats_enabled', __('Enable reports', 'nias-course-widget'), __('For each course: number of students, start and completion rate, average progress, lesson-by-lesson drop-off chart (based on real video watch percentage), quiz and assignment status, students falling behind and 12-month sales. The admin sees all courses in the "Reports" tab and instructors their own courses in the instructor panel. Each report is kept for 6 hours to keep the server light.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v5h5M9 14l2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Assignments with file upload', 'nias-course-widget')); ?>
                    <?php nias_set_toggle_row('nias_assign_enabled', __('Enable assignments', 'nias-course-widget'), __('An "Assignment" option is added to the lesson editor (dashboard and instructor panel): description, allowed extensions, maximum size and pass score. Students send a file from the lesson\'s "Assignment" tab (in a protected folder) and the instructor gives a score and feedback, or asks for a resubmission, from "Assignments". Students are notified by email and SMS and instructors by SMS.', 'nias-course-widget'), 'off', __('New', 'nias-course-widget')); ?>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Inactive student reminders', 'nias-course-widget')); ?>
                    <?php
                    $in_on = function_exists('nias_inactive_enabled') && nias_inactive_enabled();
                    nias_set_toggle_row(
                        'nias_inactive_enabled',
                        __('Enable course continuation reminders', 'nias-course-widget'),
                        __('If a student does not visit the course for a few days (does not open the course, watch a video or take a quiz), a "Continue from lesson X" message with the course link is sent; a student who bought the course but has not started also gets reminders. Any activity resets the count. Nothing is sent for finished courses, expired access, admins or instructors, and every email has an unsubscribe link. Current students are counted from the activation day (no flood of messages).', 'nias-course-widget') . ($in_on ? ' <b>' . esc_html(sprintf(__('Sent in the last 30 days: %s', 'nias-course-widget'), nias_fa_digits(nias_inactive_sent_count(30)))) . '</b>' : ''),
                        'off',
                        __('New', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_inactive_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <div class="nias-field" style="max-width:300px"><label class="nias-flabel"><?php echo esc_html__('After this many inactive days', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('Steps separated by commas; e.g. 3,7,14 means once after 3 days, once after 7 and once after 14 days.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_inactive_days" value="<?php echo esc_attr((string) carbon_get_theme_option('nias_inactive_days') !== '' ? carbon_get_theme_option('nias_inactive_days') : '3,7,14'); ?>"></div>
                            <div class="nias-field" style="max-width:240px"><label class="nias-flabel"><?php echo esc_html__('Allowed sending hours', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('E.g. 9-21; no message is sent outside these hours.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_inactive_hours" value="<?php echo esc_attr((string) carbon_get_theme_option('nias_inactive_hours') !== '' ? carbon_get_theme_option('nias_inactive_hours') : '9-21'); ?>"></div>
                        </div>
                        <?php nias_set_toggle_row('nias_inactive_email', __('Send by email', 'nias-course-widget'), sprintf(__('Email with a "Continue the course" button and an unsubscribe link. SMS for this reminder is set on the <a href="%s">SMS</a> page ("Course continuation reminder" event).', 'nias-course-widget'), esc_url(admin_url('admin.php?page=nias-course-sms'))), 'on'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="13" r="8" stroke="#3858e9" stroke-width="1.8"/><path d="M12 9v4l3 2M9 2h6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Time-limited access and renewal', 'nias-course-widget')); ?>
                    <?php
                    $wc_ok = function_exists('nias_wc_active') && nias_wc_active();
                    nias_set_toggle_row(
                        'nias_access_enabled',
                        __('Enable time-limited access', 'nias-course-widget'),
                        $wc_ok
                            ? __('Set an access period after purchase for each course (on the product edit page, "Time-limited access" box); after it, private lessons are closed like for someone who has not bought the course and the student sees a "Renew access" button. Renew by buying the course again or a separate renewal product. Students who bought before the period was set are counted from that day. Admins and the course instructor are never restricted.', 'nias-course-widget')
                            : __('This module is for selling courses and needs WooCommerce.', 'nias-course-widget'),
                        'off',
                        __('New', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_access_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <?php nias_set_number_field('nias_access_default_days', __('Default period (days)', 'nias-course-widget'), __('For courses without their own period. 0 = unlimited.', 'nias-course-widget'), 0, 3650); ?>
                            <div class="nias-field" style="max-width:300px"><label class="nias-flabel"><?php echo esc_html__('Reminder before the end (days)', 'nias-course-widget'); ?></label><div class="nias-fdesc"><?php echo esc_html__('Separate with commas; e.g. 7,1. The end day is notified too.', 'nias-course-widget'); ?></div>
                                <input type="text" class="nias-input" dir="ltr" name="nias_access_remind" value="<?php echo esc_attr(carbon_get_theme_option('nias_access_remind') !== '' && carbon_get_theme_option('nias_access_remind') !== null ? carbon_get_theme_option('nias_access_remind') : '7,1'); ?>"></div>
                        </div>
                        <?php nias_set_toggle_row('nias_access_email', __('Reminder and access-ended email', 'nias-course-widget'), sprintf(__('Email with a "Renew access" button. SMS for these events is set on the <a href="%s">SMS</a> page ("Access ending reminder" and "Access ended"). The admin can renew, make unlimited or end access from each user\'s edit page ("Time-limited course access" section).', 'nias-course-widget'), esc_url(admin_url('admin.php?page=nias-course-sms'))), 'off'); ?>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6l-8-3Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Block direct access to files', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_protect_enabled',
                        __('Enable the protected folder', 'nias-course-widget'),
                        __('Videos and files uploaded from the lesson editor are stored in the "nias-protected" folder, which cannot be opened by direct address; only with a signed link (for a private lesson: that user\'s personal link, for a public lesson: a temporary link). Even if a file\'s address leaks, it does not work. Move existing files with the tool below.', 'nias-course-widget'),
                        'off',
                        __('New', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_protect_enabled=on">
                        <div class="nias-row"><div class="nias-row-main" style="width:100%">
                            <?php echo nias_protect_settings_html(); // phpcs:ignore -- escaped inside ?>
                            <div class="nias-field" style="margin-top:10px"><label class="nias-flabel"><?php echo esc_html__('File delivery', 'nias-course-widget'); ?></label>
                                <?php nias_set_segmented('nias_protect_send', array('php' => __('With PHP (works everywhere)', 'nias-course-widget'), 'xsendfile' => __('X-Sendfile (Apache)', 'nias-course-widget'), 'xaccel' => __('X-Accel-Redirect (Nginx)', 'nias-course-widget')), 'php'); ?>
                                <div class="nias-fdesc" style="margin-top:6px"><?php echo esc_html__('For large videos and heavy traffic, delivery by the web server itself is lighter; choose it only when the mod_xsendfile module is installed on Apache or the internal rule above has been added on Nginx.', 'nias-course-widget'); ?></div>
                            </div>
                        </div></div>
                    </div>
                <?php nias_set_card_close(); ?>

                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="6" y="2" width="12" height="20" rx="3" stroke="#3858e9" stroke-width="1.8"/><path d="M11 18h2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('Device limit and simultaneous login', 'nias-course-widget')); ?>
                    <?php
                    nias_set_toggle_row(
                        'nias_dev_enabled',
                        __('Enable device limit', 'nias-course-widget'),
                        __('Prevents several people from using one account. Each browser counts as one device and the account stays active only on a set number of devices. Works with all login methods (Nias Login SMS and Google login, WooCommerce and the WordPress form). Admins and shop managers are never restricted.', 'nias-course-widget'),
                        'off',
                        __('New', 'nias-course-widget')
                    );
                    ?>
                    <div data-nias-show-when="nias_dev_enabled=on">
                        <div class="nias-row" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start">
                            <?php nias_set_number_field('nias_dev_max', __('Maximum number of devices', 'nias-course-widget'), __('Per user (1 to 20).', 'nias-course-widget'), 2, 20); ?>
                            <div class="nias-field"><label class="nias-flabel"><?php echo esc_html__('When the limit is reached and a new device logs in', 'nias-course-widget'); ?></label>
                                <?php nias_set_segmented('nias_dev_mode', array('block' => __('Reject the login', 'nias-course-widget'), 'oldest' => __('Log out the oldest device', 'nias-course-widget')), 'block'); ?>
                                <div class="nias-fdesc" style="margin-top:6px"><?php echo esc_html__('"Reject the login": the user sees a message that they must remove one of their previous devices. "Log out the oldest": the device unused for the longest time is removed automatically.', 'nias-course-widget'); ?></div>
                            </div>
                        </div>
                        <?php
                        nias_set_toggle_row('nias_dev_single', __('Simultaneous login not allowed', 'nias-course-widget'), __('Only one device is active at a time: activity on one device logs the account out on the others (the devices stay in the list).', 'nias-course-widget'), 'off');
                        nias_set_toggle_row('nias_dev_exempt_inst', __('Do not restrict instructors', 'nias-course-widget'), __('Instructors (who usually work from several devices) are not restricted.', 'nias-course-widget'), 'on');
                        nias_set_toggle_row('nias_dev_self', __('Device removal by the user', 'nias-course-widget'), __('A "My devices" tab in the user account (or the shortcode [nias_my_devices]) where users see their devices and can remove one. The admin can always see and remove devices from the user edit page ("User devices" section).', 'nias-course-widget'), 'on');
                        ?>
                        <div class="nias-row" data-nias-show-when="nias_dev_self=on">
                            <?php nias_set_number_field('nias_dev_removals', __('Maximum removals every 30 days', 'nias-course-widget'), __('0 = no limit. Limiting it prevents passing an account around by repeatedly removing and logging in.', 'nias-course-widget'), 2, 50); ?>
                        </div>
                    </div>
                <?php nias_set_card_close(); ?>
                <?php nias_set_save_button('nias_save_modules'); ?>
            </form>

            <?php if (function_exists('nias_protect_enabled') && nias_protect_enabled()) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" stroke="#3858e9" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10v5m0 0-2-2m2 2 2-2" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>', __('Move the courses\' current files to the protected folder', 'nias-course-widget')); ?>
                    <div class="nias-row"><div class="nias-row-main" style="width:100%"><?php echo nias_protect_tool_html(); // phpcs:ignore -- escaped inside ?></div></div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php if ($drip_on) : ?>
                <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#3858e9" stroke-width="1.8"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke="#3858e9" stroke-width="1.8" stroke-linecap="round"/></svg>', __('A student\'s drip schedule', 'nias-course-widget')); ?>
                    <div class="nias-row"><div class="nias-row-main" style="width:100%">
                        <div class="nias-row-desc" style="margin-bottom:12px"><?php echo esc_html__('Find a course and student to see when their lessons open; you can open all lessons for them or move their course start. Instructors have the same tool for their own courses in the instructor panel.', 'nias-course-widget'); ?></div>
                        <?php echo nias_drip_tool_html(0); // phpcs:ignore -- built with escaping ?>
                    </div></div>
                <?php nias_set_card_close(); ?>
            <?php endif; ?>

            <?php if (nias_modules_upcoming()) : ?>
            <?php nias_set_card_open('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/></svg>', __('Upcoming modules', 'nias-course-widget')); ?>
                <div class="nias-row"><div class="nias-row-main">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach (nias_modules_upcoming() as $m) : ?>
                            <span class="nias-chip" style="background:#f1f5f9;color:#64748b;padding:5px 12px"><?php echo esc_html($m); ?> · <?php echo esc_html__('Coming soon', 'nias-course-widget'); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div></div>
            <?php nias_set_card_close(); ?>
            <?php endif; ?>
        </div></div>
    </div>
    <?php
}
