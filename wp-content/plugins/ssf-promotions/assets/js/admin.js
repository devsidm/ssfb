(function () {
  'use strict';

  var editor = document.querySelector('[data-ssf-promotion-editor]');
  if (!editor) {
    return;
  }

  var relatedType = editor.querySelector('[data-related-type]');
  var providers = editor.querySelectorAll('[data-related-provider]');
  var anchor = editor.querySelector('[data-annual-anchor]');
  var manualUrl = editor.querySelector('[data-manual-url]');
  var preview = document.querySelector('[data-promotion-preview] .ssf-promotion');

  function updateRelations() {
    var selected = relatedType ? relatedType.value : '';
    providers.forEach(function (field) {
      field.hidden = field.getAttribute('data-related-provider') !== selected;
    });
    if (anchor) {
      anchor.hidden = selected !== 'annual_meeting';
    }
    if (manualUrl) {
      manualUrl.hidden = Boolean(selected);
    }
  }

  function selectedText(selector, fallback) {
    var field = document.querySelector(selector);
    if (!field || !field.options || field.selectedIndex < 0) {
      return fallback;
    }
    return field.options[field.selectedIndex].text;
  }

  function updatePreview() {
    if (!preview) {
      return;
    }
    var title = document.getElementById('title');
    var text = document.querySelector('[data-preview-text]');
    var cta = document.querySelector('[data-preview-cta]');
    var priority = parseInt(document.querySelector('[data-preview-priority]').value, 10) || 50;
    var layout = document.querySelector('[data-preview-layout]').value;
    var type = selectedText('[data-preview-type]', 'Information');
    var severity = priority >= 100 ? 'action' : (priority >= 80 ? 'important' : 'information');

    preview.className = 'ssf-promotion ssf-promotion--' + layout + ' ssf-promotion--' + severity;
    preview.querySelector('.ssf-promotion__type').textContent = type;
    preview.querySelector('.ssf-promotion__title').textContent = title && title.value ? title.value : 'Rubrik för budskapet';
    preview.querySelector('.ssf-promotion__text').textContent = text && text.value ? text.value : 'Den korta texten visas här.';
    preview.querySelector('.ssf-promotion__cta').firstChild.nodeValue = cta && cta.value ? cta.value : 'Läs mer';
  }

  updateRelations();
  updatePreview();
  if (relatedType) {
    relatedType.addEventListener('change', updateRelations);
  }
  document.addEventListener('input', updatePreview);
  document.addEventListener('change', updatePreview);
}());

