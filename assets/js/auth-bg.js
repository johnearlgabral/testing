/* =========================================================
   UBMSConnect - Shared rotating campus background
   ---------------------------------------------------------
   Presentation-only helper used by the authentication
   pages (login.php, register.php, forgot_password.php).
   Each of those pages renders the
   .auth-bg-slides layers (see the .auth-bg-slides rules in
   assets/css/style.css); this script simply moves the
   .is-active class to the next layer every 6 seconds and the
   CSS transition performs the crossfade.

   It never touches the form: if this file fails to load or
   the browser has no JS, the first photo (.is-active in the
   markup) simply stays on screen. Visitors who prefer
   reduced motion keep that first photo with no animation.
   ========================================================= */
(function () {
    var slides = document.querySelectorAll('.auth-bg-slide');
    if (slides.length < 2) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var i = 0;
    setInterval(function () {
        slides[i].classList.remove('is-active');
        i = (i + 1) % slides.length;
        slides[i].classList.add('is-active');
    }, 6000);
})();
