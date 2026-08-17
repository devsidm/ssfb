(function () {
  function initCollectionForm(form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll('.ssf-collection-step'));
    var prev = form.querySelector('[data-collection-prev]');
    var next = form.querySelector('[data-collection-next]');
    var submit = form.querySelector('[data-collection-submit]');
    var progress = form.querySelector('.ssf-collection-progress span');
    var summary = form.querySelector('.ssf-collection-summary');
    var current = 0;

    if (!steps.length || !prev || !next || !submit || !progress) {
      return;
    }

    function fieldValue(name) {
      var field = form.querySelector('[name="' + name + '"]');
      return field ? field.value : '';
    }

    function updateSummary() {
      if (!summary) {
        return;
      }
      summary.innerHTML = [
        '<h3>' + (fieldValue('post_title') || 'Fartyg') + '</h3>',
        '<p><strong>Kort presentation:</strong> ' + (fieldValue('post_excerpt') || '') + '</p>',
        '<p><strong>Hemmahamn:</strong> ' + (fieldValue('_ssf_home_port') || '') + '</p>',
        '<p><strong>Ombud:</strong> ' + (fieldValue('_ssf_contact_name') || '') + '</p>',
        '<p><strong>Webbplats:</strong> ' + (fieldValue('_ssf_website') || '') + '</p>'
      ].join('');
    }

    function show(index) {
      current = Math.max(0, Math.min(index, steps.length - 1));
      steps.forEach(function (step, stepIndex) {
        step.classList.toggle('is-active', stepIndex === current);
      });
      prev.style.display = current === 0 ? 'none' : '';
      next.style.display = current === steps.length - 1 ? 'none' : '';
      submit.style.display = current === steps.length - 1 ? '' : 'none';
      progress.style.width = (((current + 1) / steps.length) * 100) + '%';
      if (current === steps.length - 1) {
        updateSummary();
      }
    }

    next.addEventListener('click', function () {
      var invalid = steps[current].querySelector(':invalid');
      if (invalid) {
        invalid.reportValidity();
        return;
      }
      show(current + 1);
    });

    prev.addEventListener('click', function () {
      show(current - 1);
    });

    show(0);
  }

  function initImagePreview(form) {
    var input = form.querySelector('[data-ssf-image-input]');
    var preview = form.querySelector('[data-ssf-upload-preview]');
    var featured = form.querySelector('[data-ssf-featured-index]');
    if (!input || !preview || !featured) {
      return;
    }

    input.addEventListener('change', function () {
      preview.innerHTML = '';
      Array.prototype.slice.call(input.files || []).forEach(function (file, index) {
        if (!file.type.startsWith('image/')) {
          return;
        }
        var card = document.createElement('label');
        card.className = 'ssf-upload-preview__item';
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'featured_image_choice';
        radio.value = String(index);
        radio.checked = index === 0;
        radio.addEventListener('change', function () {
          featured.value = radio.value;
        });
        var img = document.createElement('img');
        img.alt = file.name;
        img.src = URL.createObjectURL(file);
        var span = document.createElement('span');
        span.textContent = index === 0 ? 'Huvudbild' : 'Använd som huvudbild';
        card.appendChild(radio);
        card.appendChild(img);
        card.appendChild(span);
        preview.appendChild(card);
      });
      featured.value = '0';
    });
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('.ssf-ship-gallery a');
    if (!link) {
      return;
    }

    event.preventDefault();
    window.open(link.href, '_blank', 'noopener');
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ssf-collection-form').forEach(function (form) {
      initCollectionForm(form);
      initImagePreview(form);
    });
  });
}());
