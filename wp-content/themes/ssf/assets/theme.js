(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.site-menu-toggle');
    var nav = document.querySelector('.site-nav');
    if (!toggle || !nav) {
      return;
    }

    toggle.addEventListener('click', function () {
      var isOpen = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  });
}());
