(function () {
  var form = document.querySelector('[data-ssf-application-form]');
  if (!form) return;

  var steps = Array.prototype.slice.call(form.querySelectorAll('[data-application-step]'));
  var indicators = Array.prototype.slice.call(form.querySelectorAll('[data-step-indicator]'));
  var previous = form.querySelector('[data-ssf-prev]');
  var next = form.querySelector('[data-ssf-next]');
  var submit = form.querySelector('[data-ssf-submit]');
  var count = form.querySelector('[data-ssf-step-count]');
  var progress = form.querySelector('[data-ssf-progress]');
  var routeContext = form.querySelector('[data-route-context]');
  var review = form.querySelector('[data-ssf-review]');
  var index = 0;

  function selectedRoute() {
    var selected = form.querySelector('[name="application_route"]:checked');
    return selected ? selected.value : '';
  }

  function routeMatches(element) {
    var routes = (element.getAttribute('data-routes') || '').split(',').filter(Boolean);
    return !routes.length || routes.indexOf(selectedRoute()) !== -1;
  }

  function updateRouteFields() {
    form.querySelectorAll('.ssf-vessel-profile-section[data-routes]').forEach(function (section) {
      section.hidden = !routeMatches(section);
    });
    form.querySelectorAll('.ssf-vessel-field[data-routes]').forEach(function (field) {
      var visible = routeMatches(field);
      field.hidden = !visible;
      field.querySelectorAll('input, textarea, select').forEach(function (control) {
        control.disabled = !visible;
        if (field.hasAttribute('data-route-required')) control.required = visible;
      });
    });
    form.querySelectorAll('.ssf-route-card').forEach(function (card) {
      var radio = card.querySelector('input[type="radio"]');
      card.classList.toggle('is-selected', !!radio && radio.checked);
    });
    var selectedCard = form.querySelector('.ssf-route-card.is-selected strong');
    if (routeContext) routeContext.textContent = selectedCard ? 'Vald medlemsväg: ' + selectedCard.textContent : '';
  }

  function validate(container) {
    var controls = container.querySelectorAll('input:not(:disabled), textarea:not(:disabled), select:not(:disabled)');
    for (var i = 0; i < controls.length; i += 1) {
      if (!controls[i].checkValidity()) {
        controls[i].reportValidity();
        controls[i].focus();
        return false;
      }
    }
    return true;
  }

  function textValue(name) {
    var checked = form.querySelector('[name="' + name + '"]:checked');
    var field = checked || form.querySelector('[name="' + name + '"]');
    if (!field) return '';
    if (field.tagName === 'SELECT' && field.selectedIndex >= 0) return field.options[field.selectedIndex].text;
    return field.value;
  }

  function addReviewSection(title, rows) {
    var section = document.createElement('section');
    var heading = document.createElement('h4');
    var list = document.createElement('dl');
    heading.textContent = title;
    rows.forEach(function (row) {
      if (!row[1]) return;
      var wrapper = document.createElement('div');
      var term = document.createElement('dt');
      var description = document.createElement('dd');
      term.textContent = row[0];
      description.textContent = row[1];
      wrapper.appendChild(term);
      wrapper.appendChild(description);
      list.appendChild(wrapper);
    });
    section.appendChild(heading);
    section.appendChild(list);
    review.appendChild(section);
  }

  function filenames(name) {
    var input = form.querySelector('[name="' + name + '"]');
    return input && input.files.length ? Array.prototype.map.call(input.files, function (file) { return file.name; }).join(', ') : 'Inga valda';
  }

  function updateReview() {
    if (!review) return;
    review.innerHTML = '';
    var routeHeading = form.querySelector('.ssf-route-card.is-selected strong');
    addReviewSection('Ansökningsväg', [['Vald väg', routeHeading ? routeHeading.textContent : '']]);
    addReviewSection('Fartygsombud', [
      ['Namn', [textValue('applicant_first_name'), textValue('applicant_last_name')].filter(Boolean).join(' ')], ['Postadress', [textValue('applicant_street'), textValue('applicant_postal_code'), textValue('applicant_city')].filter(Boolean).join(', ')],
      ['Telefon', textValue('applicant_phone')], ['E-post', textValue('applicant_email')], ['Faktura-e-post', textValue('applicant_invoice_email')], ['Hemsida', textValue('applicant_website')]
    ]);
    addReviewSection('Fartyget', [
      ['Namn', textValue('post_title')], ['Fartygstyp', textValue('tax_fartygstyp')], ['Fartygskategori', textValue('_ssf_vessel_operation')], ['Hemmahamn', textValue('_ssf_home_port')],
      ['Byggår', textValue('_ssf_build_year')], ['Längd i huvuddäck', textValue('_ssf_main_deck_length')], ['Bredd', textValue('_ssf_beam')],
      ['Nuvarande rigg', textValue('_ssf_rig')], ['Historik', textValue('_ssf_history')], ['Användning idag', textValue('_ssf_today')]
    ]);
    var special = [];
    if (selectedRoute() === 'small_registered') special.push(['Registreringsnummer', textValue('_ssf_registry_number')]);
    if (selectedRoute() === 'restoration') special.push(['Restaureringens mål', textValue('_ssf_restoration_goal')]);
    if (selectedRoute() === 'new_traditional') special.push(['Historisk förebild', textValue('_ssf_traditional_reference')]);
    if (special.length) addReviewSection('Särskilda uppgifter', special);
    addReviewSection('Bilder och bilagor', [
      ['Huvudbild', filenames('ssf_application_main_image')], ['Övriga bilder', filenames('ssf_application_gallery[]')], ['Bilagor', filenames('ssf_application_documents[]')]
    ]);
  }

  function renderFileList(input) {
    var old = input.parentNode.querySelector('.ssf-process-file-list');
    if (old) old.remove();
    if (!input.files.length) return;
    var list = document.createElement('ul');
    list.className = 'ssf-process-file-list';
    Array.prototype.forEach.call(input.files, function (file, fileIndex) {
      var item = document.createElement('li');
      var label = document.createElement('span');
      var remove = document.createElement('button');
      label.textContent = file.name + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' kB)';
      remove.type = 'button';
      remove.textContent = '×';
      remove.title = 'Ta bort ' + file.name;
      remove.setAttribute('aria-label', remove.title);
      remove.addEventListener('click', function () {
        if (typeof DataTransfer === 'undefined') return;
        var transfer = new DataTransfer();
        Array.prototype.forEach.call(input.files, function (candidate, candidateIndex) {
          if (candidateIndex !== fileIndex) transfer.items.add(candidate);
        });
        input.files = transfer.files;
        renderFileList(input);
      });
      item.appendChild(label);
      item.appendChild(remove);
      list.appendChild(item);
    });
    input.parentNode.appendChild(list);
  }

  function show() {
    updateRouteFields();
    steps.forEach(function (step, position) {
      step.hidden = position !== index;
      step.classList.toggle('is-active', position === index);
    });
    indicators.forEach(function (indicator, position) {
      indicator.classList.toggle('is-current', position === index);
      indicator.classList.toggle('is-complete', position < index);
    });
    progress.style.width = (((index + 1) / steps.length) * 100) + '%';
    count.textContent = 'Steg ' + (index + 1) + ' av ' + steps.length + ': ' + steps[index].getAttribute('data-application-step');
    previous.hidden = index === 0;
    next.hidden = index === steps.length - 1;
    next.textContent = index === steps.length - 2 ? 'Granska ansökan' : 'Nästa';
    submit.hidden = index !== steps.length - 1;
    if (index === steps.length - 1) updateReview();
  }

  next.addEventListener('click', function () {
    if (!validate(steps[index])) return;
    index += 1;
    show();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    var legend = steps[index].querySelector('legend');
    if (legend) legend.setAttribute('tabindex', '-1');
    if (legend) legend.focus();
  });
  previous.addEventListener('click', function () { index = Math.max(0, index - 1); show(); });
  form.querySelectorAll('[name="application_route"]').forEach(function (radio) { radio.addEventListener('change', updateRouteFields); });
  form.querySelectorAll('[data-file-input]').forEach(function (input) { input.addEventListener('change', function () { renderFileList(input); }); });
  form.addEventListener('submit', function () { submit.disabled = true; submit.textContent = 'Skickar…'; });
  show();
}());
