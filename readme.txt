=== Nias Course Builder ===
Contributors: Niasir
Tags: lms, online course, elearning, certificate, woocommerce
Donate link: https://nias.ir/
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build, sell and run online courses: lessons, a modern course player, quizzes, certificates and instructors. Works with or without WooCommerce.

== Description ==

Nias Course Builder turns any WordPress site into a complete learning platform. Build chapters and lessons in a fast drag-and-drop editor, sell them with WooCommerce or publish free courses without it, and give your students a clean, distraction-free course view with a smart video player, quizzes and verifiable certificates.

Everything is free, and every advanced feature is an optional module you switch on only when you need it.

= Build your course =

* Unlimited chapters and lessons in a drag-and-drop editor: video, audio, text, embeds and downloadable files
* Private lessons and free previews, with fine control over what stays locked
* Free courses without WooCommerce through the built-in "Course" post type
* Drip content: open each lesson on a chosen day and time after enrolment
* Prerequisites and learning paths that guide students from one course to the next
* Copy, export and import whole courses between sites

= A modern learning experience =

* A focused course view with progress tracking, curriculum and certificate in one place
* The Nias video player: real watch tracking, resume, speed control, keyboard shortcuts, quality switching, HLS and DASH streaming
* Timestamped notes on every video, plus a "My notes" page
* Q&A under each lesson, answered by the course instructor
* Live classes with countdown, "Add to calendar" and SMS reminders
* Points, badges and a leaderboard for each course

= Quizzes, assignments and certificates =

* Quiz builder with six question types, attempts limits and essay grading
* Question banks: draw a random set of questions for each student, per difficulty
* Assignments with file upload, scoring and feedback
* Certificate designer with drag and drop, QR codes, PDF digital signatures and a public verification page

= Sell and manage access =

* WooCommerce integration for selling courses next to any other product
* Time-limited access with renewal products
* Instructor revenue share with holding period and payout requests
* Built-in SpotPlayer license support, including licenses issued by the original SpotPlayer plugin
* Analytics for every course: completion, lesson-by-lesson drop-off, quizzes and sales

= Instructors =

* An instructor panel where teachers manage their own courses, quizzes, students and earnings, without wp-admin
* Optional review step: instructors' changes go live only after approval

= Protect your content =

* Personal signed links for lesson videos and files
* A protected folder that refuses direct file access
* Device limits and single active session
* Download prevention and a personal watermark on every video

= Works your way =

* Works with any WordPress theme
* Elementor is optional: when it is active, the plugin adds its own course widgets
* Shortcodes for every part of the course view
* SMS notifications through the Nias Login plugin
* Complete Persian (Farsi) translation and full right-to-left support

= Support =

* [Documentation and support on Nias](https://nias.ir/)
* [Telegram channel and group](https://t.me/niasir)

== Installation ==

1. Upload the `nias-course-widget` folder to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Open "Nias Course Builder" in the admin menu, choose your settings and turn on the modules you need.
4. Edit a WooCommerce product (or a "Course" when WooCommerce is not installed) and click "Edit lessons and chapters" to build your course.

== Frequently Asked Questions ==

= What do I need to use this plugin? =

Only WordPress. WooCommerce is needed if you want to sell courses, and Elementor only if you want to use the plugin's Elementor widgets.

= Which themes does it work with? =

Any WordPress theme. If you build your pages with Elementor, we recommend the free Hello Elementor theme.

= Is everything really free? =

Yes. The plugin is free and will stay free. Your support and recommendations keep the updates coming.

= Can I use it in Persian (Farsi)? =

Yes. The plugin ships with a complete Persian translation and full right-to-left support. Set your site language to Persian and everything appears in Persian.

= How can I ask questions or request features? =

Ask in our Telegram channel and group: [t.me/niasir](https://t.me/niasir), or on [nias.ir](https://nias.ir/).

== Screenshots ==

1. The modern course view: video player, progress and curriculum on one screen.
2. The lessons editor: drag-and-drop chapters and lessons with drip, quiz and privacy options.
3. The quiz builder with question banks that draw a different set for every student.
4. Certificates with QR verification and PDF digital signature.
5. The instructor panel: students, income and payouts at a glance.
6. Optional modules you can switch on one by one.

== Changelog ==

= 1.7.0 =

* New: the plugin works without Elementor and without WooCommerce; the base language is now English with a complete Persian translation.
* New modules (each can be turned on separately): drip content, device limit and single session, protected files folder, time-limited access and renewal, inactive student reminders, assignments, analytics, ratings and reviews, timestamped notes, prerequisites and learning paths, course copy and transfer, instructor revenue share, live classes, points and badges.
* New: question banks with per-difficulty random draws, video quality switching, download prevention and personal signed links for lesson files.
* New: SMS notifications through the Nias Login plugin.
* SpotPlayer: licenses issued by the original SpotPlayer plugin are recognized, with a one-click migration.
* "My courses" also lists free courses the user has started.
* Security: quiz submissions require access to the course, progress is recorded only for enrolled users, SpotPlayer sync is protected with a nonce and limited to spotplayer.ir, and SSL verification is on for SpotPlayer API requests.

= 1.6.0 =

* New Nias video player (replaces Plyr) with real watch tracking, resume, keyboard shortcuts, picture in picture and HLS/DASH streaming.
* Video watch report for every student and lesson.
* New "Video player" settings page with a live preview.
* mPDF and FPDI updated for PHP 8.3.

= 1.5.2 =

* Full HLS (m3u8) support in every player.
* Lessons without content show a light "coming soon" card.

= 1.5.1 =

* PDF digital signatures for certificates.
* Issued certificates keep the design they had when issued.
* Verification log and automatic certificate emails.

= 1.5.0 =

* Advanced certificate designer: multi-select, Code128 barcodes, shapes, auto-save and template import/export.
* Safer verification codes, rate limiting and several privacy fixes.

= 1.4.1 =

* Much faster course loading: each course's curriculum is stored in a single row, with automatic migration.

= 1.4.0 =

* Drag-and-drop certificate designer with ready templates.
* Certificate verification form, requirements and "Issued certificates" screen.
* "My certificates" tab in the WooCommerce account.

= 1.3.3 =

* SpotPlayer license box redesigned on the order screen, with license management and per-product license settings.
* Watermark style settings for SpotPlayer licenses.

= 1.3.2 =

* Fixed saving embed codes in lesson previews.

= 1.3.1 =

* Bug fixes and stability improvements.

= 1.3.0 =

* Rebuilt course editor on the product screen, modern course view, built-in SpotPlayer support, instructors, quiz builder, meta builder and lesson locking options.

= 1.2.10 =

* Fixed private lesson previews and Aparat video display; added the license shortcode and SpotPlayer lesson sync.

= 1.2.9 =

* Fixed audio/video display, dashboard icons, the account courses tab and certificate display.

= 1.2.8 =

* Fixed an installation error.

= 1.2.7 =

* Security fixes, certificates with a personal barcode, courses in the WooCommerce account.

= 1.2.6 =

* Security fixes.

= 1.2.4 =

* Two-way purchase check to fix courses not opening for some students.

= 1.2.2 =

* WooCommerce editor fields moved to the modern editor, with a sync option for older versions.

= 1.2.1 =

* Added a modal video player to the preview button
* Fixed font issues in the admin dashboard
* Resolved iframe display issues
* Added a guide for using the new product course builder to the settings section

= 1.2.0 =

* Added iframe display capability
* Added the ability to create lessons from WooCommerce product editing
* Created a special widget to display added content from product editing

= 1.1.3 =

* Added WooCommerce protected links
* Fixed style loading issues
* Added settings page

= 1.1.1 =

* Fixed PHP errors

= 1.1.0 =

* Fixed code issues
* Added style tab
* Added spot configuration
* Made all fields customizable
