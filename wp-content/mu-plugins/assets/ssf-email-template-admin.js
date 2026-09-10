(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var select = event.target.closest('[data-ssf-email-logo-select]');
    var clear = event.target.closest('[data-ssf-email-logo-clear]');
    var input = document.querySelector('[data-ssf-email-logo-id]');
    var preview = document.querySelector('[data-ssf-email-logo-preview]');

    if (select && window.wp && wp.media) {
      event.preventDefault();
      var frame = wp.media({ title: 'Välj e-postlogotyp', button: { text: 'Använd logotyp' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        input.value = attachment.id;
        preview.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
      });
      frame.open();
    }

    if (clear) {
      event.preventDefault();
      input.value = '';
      preview.src = ssfEmailTemplateAdmin.defaultLogo;
    }
  });
}());
