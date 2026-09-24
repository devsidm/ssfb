(function () {
  'use strict';

  var editor = document.querySelector('[data-ssf-promotion-editor]');
  if (!editor) {
    return;
  }

  var preview = document.querySelector('[data-promotion-preview] .ssf-promotion');

  function updateLinkFields() {
    var selected = editor.querySelector('input[name="ssf_promotion_link_mode"]:checked');
    var mode = selected ? selected.value : 'page';
    editor.querySelectorAll('[data-link-field]').forEach(function (field) {
      var kind = field.getAttribute('data-link-field');
      field.hidden = kind !== mode && !(kind === 'cta' && mode !== 'none');
    });
    if (preview) {
      var action = preview.querySelector('.ssf-promotion__cta');
      if (action) action.style.display = mode === 'none' ? 'none' : '';
    }
  }

  function updatePreview() {
    if (!preview) {
      return;
    }
    var title = document.getElementById('title');
    var text = document.querySelector('[data-preview-text]');
    var cta = document.querySelector('[data-preview-cta]');
    preview.querySelector('.ssf-promotion__title').textContent = title && title.value ? title.value : 'Rubrik för budskapet';
    preview.querySelector('.ssf-promotion__text').textContent = text && text.value ? text.value : 'Den korta texten visas här.';
    preview.querySelector('.ssf-promotion__cta').firstChild.nodeValue = cta && cta.value ? cta.value : 'Läs mer';
  }

  updateLinkFields();
  updatePreview();
  editor.addEventListener('change', updateLinkFields);
  document.addEventListener('input', updatePreview);
  document.addEventListener('change', updatePreview);
}());
