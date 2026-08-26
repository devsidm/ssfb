(function () {
  'use strict';
  document.querySelectorAll('.ssf-motion-form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      }
    });
  });
}());
