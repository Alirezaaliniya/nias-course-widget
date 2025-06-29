<?php
defined('ABSPATH') || exit;

// بررسی وجود ویجت‌های `niaslessonswoo` یا `niaslessons` و اضافه کردن کد مودال
add_action('elementor/frontend/after_render', function($element) {
    if (in_array($element->get_name(), ['niaslessonswoo', 'niaslessons'])) {
        add_action('wp_footer', 'nias_modal_player');  // اضافه کردن کد مودال به فوتر
        add_action('wp_footer', 'nias_modal_player_script');  // اضافه کردن اسکریپت و استایل به فوتر
    }
});

// تابع برای ایجاد کد مودال
function nias_modal_player() {
    ?>
    <div id="videoModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); justify-content:center; align-items:center; z-index:9999;">
        <div style="position:relative; width:80%; max-width:800px;">
            <button id="closeModal" style="top:10px;right:10px;background:none;color:white;font-size: 12px;border:none;cursor:pointer;font-family: unset;background-color: red;border-radius: 7px 7px 0 0;padding: 5px 10px;">بستن ویدیو</button>
            <video id="modalVideo" class="plyr-video" controls></video>
        </div>
    </div>
    <?php
}

// تابع برای اضافه کردن استایل و اسکریپت‌های لازم
function nias_modal_player_script() {
    ?>
    <!-- استایل و اسکریپت‌های Plyr -->
    <link rel="stylesheet" href="<?php echo plugin_dir_url(__DIR__) . 'assets/niasplyr.css?v=',NIAS_COURSE_VERSION;?>" />
    <script src="<?php echo plugin_dir_url(__DIR__) . 'assets/niasplyrscript.js?v=',NIAS_COURSE_VERSION;?>"></script>
    <script>
    jQuery(document).ready(function($) {
        // صبر تا Plyr بارگذاری شود
        if (typeof Plyr !== 'undefined') {
            const player = new Plyr('#modalVideo');

            // تابع برای باز کردن مودال و نمایش ویدیو
            function openModal(videoSrc) {
                $('#videoModal').css('display', 'flex');
                player.source = {
                    type: 'video',
                    sources: [
                        {
                            src: videoSrc,
                            type: 'video/mp4' // نوع ویدیو
                        }
                    ]
                };
                player.play();
            }

            // رویداد کلیک برای بستن مودال
            $('#closeModal').on('click', function() {
                $('#videoModal').hide();
                player.stop(); // توقف ویدیو و تنظیم مجدد
            });

            // اضافه کردن رویداد کلیک به لینک‌ها با هر دو کلاس
            $('.nias-preview-tag, .video-lesson-preview').on('click', function(event) {
                event.preventDefault(); // جلوگیری از باز شدن لینک
                const videoSrc = $(this).attr('href'); // لینک ویدیو
                openModal(videoSrc);
            });
        } else {
            console.error('Plyr library failed to load.');
        }
    });
    </script>
    <?php
}
?>
