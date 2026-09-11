(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var select = event.target.closest('[data-ssf-email-logo-select]');
    var clear = event.target.closest('[data-ssf-email-logo-clear]');
    var input = document.querySelector('[data-ssf-email-logo-id]');
    var preview = document.querySelector('[data-ssf-email-logo-preview]');
    var fallback = document.querySelector('[data-ssf-email-logo-fallback]');

    if (select && window.wp && wp.media) {
      event.preventDefault();
      var frame = wp.media({ title: 'Välj e-postlogotyp', button: { text: 'Använd logotyp' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        input.value = attachment.id;
        preview.src = attachment.url;
        preview.hidden = false;
        fallback.hidden = true;
      });
      frame.open();
    }

    if (clear) {
      event.preventDefault();
      input.value = '';
      preview.removeAttribute('src');
      preview.hidden = true;
      fallback.hidden = false;
    }
  });

  function updateContactSummary(categorySelect) {
    var option = categorySelect.options[categorySelect.selectedIndex];
    var form = categorySelect.closest('form');
    var summary = form ? form.querySelector('[data-ssf-email-contact-summary]') : null;
    if (!option || !summary) {
      return;
    }
    summary.textContent = 'Sidfot: ' + option.dataset.contactLabel + ' ' + option.dataset.contactEmail + ' | Reply-To: ' + option.dataset.contactEmail;
  }

  document.querySelectorAll('[data-ssf-email-category]').forEach(function (categorySelect) {
    updateContactSummary(categorySelect);
    categorySelect.addEventListener('change', function () {
      updateContactSummary(categorySelect);
    });
  });

  document.querySelectorAll('[data-ssf-email-template]').forEach(function (templateSelect) {
    templateSelect.addEventListener('change', function () {
      var form = templateSelect.closest('form');
      var categorySelect = form ? form.querySelector('[data-ssf-email-category]') : null;
      var option = templateSelect.options[templateSelect.selectedIndex];
      if (categorySelect && option && option.dataset.category) {
        categorySelect.value = option.dataset.category;
        updateContactSummary(categorySelect);
      }
    });
  });
}());
