(function($) {
    'use strict';

    jQuery(document).ready(function($) {
        
//nias copy button
        $(".nsspotcopybtn").click(function(){
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val($(".nsspotlicense").text()).select();
            document.execCommand("copy");
            $temp.remove();
            $(this).text("کپی شد!").prop('disabled', true);
        });

        $(document).on('click', '.nscourse-section-title-elementory.cursor-pointer', function(event) {
            event.preventDefault();
            $(this).parent().toggleClass('active');
            $(this).next('.nspanel-group').slideToggle(300);
        });

        $(document).on('click', '.notif-row', function(event) {
            event.preventDefault();
            $(this).parent().toggleClass('active');
            $(this).next('.notif-content').slideToggle(300);
        });

    });
})(jQuery);
(function($) {
    'use strict';

    jQuery(document).ready(function($) {

        $(document).on('click', 'h5.nscourse-section-title.cursor-pointer', function(event) {
            event.preventDefault();
            $(this).parent().toggleClass('active');
            $(this).next('.nspanel-group').slideToggle(300);
        });

    });
})(jQuery);

document.addEventListener("DOMContentLoaded", function() {
  var headings = document.querySelectorAll(".nscourse-panel-heading");

  headings.forEach(function(heading) {
    heading.addEventListener("click", function() {
      var content = this.nextElementSibling;
      var innerContent = content.querySelector(".nspanel-content-inner");
      var contentHeight = innerContent.offsetHeight;
      content.style.maxHeight = content.classList.contains("active") ? "0" : contentHeight + "px";
      content.classList.toggle("active");
      heading.classList.toggle("active");
    });
  });
});
jQuery(document).ready(function($) {
    // باز و بسته کردن فصل‌ها
    // از بایند تفویض‌شده (delegated) استفاده می‌کنیم تا در ویرایشگر المنتور هم که
    // ویجت پس از آماده‌شدن صفحه و به‌صورت پویا رندر/بازرندر می‌شود، کار کند.
    $(document).on('click', '.toggle_section', function() {
        $(this).closest('.nias_course_section').find('.section_content').slideToggle();
        $(this).toggleClass('active');
    });

    // باز و بسته کردن دروس
    $(document).on('click', '.toggle_lesson', function(e) {
        // کلیک روی دکمه‌های پیش‌نمایش/دانلود (یا هر لینک دیگری) نباید
        // آکاردئون درس را باز/بسته کند؛ فقط بگذار خود لینک عمل کند.
        if ($(e.target).closest('a, .nias-preview-tag, .nsdownload-button').length) {
            return;
        }
        $(this).closest('.lesson_item').find('.lesson_content').slideToggle();
        $(this).toggleClass('active');

    });
    // مقداردهی Plyr برای ویدیو

});