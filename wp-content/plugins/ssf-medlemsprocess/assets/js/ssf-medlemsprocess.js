(function () {
  var form = document.querySelector('[data-ssf-application-form]');
  if (!form) return;

  var primarySteps = Array.prototype.slice.call(form.querySelectorAll('.ssf-process-step'));
  var indicators = Array.prototype.slice.call(form.querySelectorAll('[data-primary-indicator]'));
  var previous = form.querySelector('[data-ssf-prev]');
  var next = form.querySelector('[data-ssf-next]');
  var submit = form.querySelector('[data-ssf-submit]');
  var count = form.querySelector('[data-ssf-step-count]');
  var progress = form.querySelector('[data-ssf-progress]');
  var sectionTitle = form.querySelector('[data-vessel-section-title]');
  var sectionCount = form.querySelector('[data-vessel-section-count]');
  var routeContext = form.querySelector('[data-route-context]');
  var review = form.querySelector('[data-ssf-review]');
  var primaryIndex = 0;
  var sectionIndex = 0;

  function selectedRoute() {
    var selected = form.querySelector('[name="application_route"]:checked');
    return selected ? selected.value : '';
  }

  function routesFor(element) {
    return (element.getAttribute('data-routes') || '').split(',').filter(Boolean);
  }

  function routeMatches(element) {
    var routes = routesFor(element);
    return !routes.length || routes.indexOf(selectedRoute()) !== -1;
  }

  function updateRouteFields() {
    form.querySelectorAll('.ssf-vessel-profile-section[data-routes]').forEach(function (section) {
      section.hidden = !routeMatches(section);
    });
    form.querySelectorAll('.ssf-vessel-field').forEach(function (field) {
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
  }

  function visibleSections() {
    return Array.prototype.slice.call(form.querySelectorAll('.ssf-vessel-profile-section')).filter(routeMatches);
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

  function addReviewRow(list, label, value) {
    if (!value) return;
    var row = document.createElement('div');
    var term = document.createElement('dt');
    var description = document.createElement('dd');
    term.textContent = label;
    description.textContent = value;
    row.appendChild(term);
    row.appendChild(description);
    list.appendChild(row);
  }

  function updateReview() {
    if (!review) return;
    review.innerHTML = '';
    var heading = document.createElement('h4');
    heading.textContent = textValue('post_title') || 'Fartyget';
    var list = document.createElement('dl');
    var routeHeading = form.querySelector('.ssf-route-card.is-selected strong');
    addReviewRow(list, 'Ansökningsväg', routeHeading ? routeHeading.textContent : '');
    addReviewRow(list, 'Fartygstyp', textValue('tax_fartygstyp'));
    addReviewRow(list, 'Längd i huvuddäck', textValue('_ssf_main_deck_length'));
    addReviewRow(list, 'Bredd', textValue('_ssf_beam'));
    addReviewRow(list, 'Hemmahamn', textValue('_ssf_home_port'));
    addReviewRow(list, 'Kort presentation', textValue('post_excerpt'));
    addReviewRow(list, 'Historik', textValue('_ssf_history'));
    if (selectedRoute() === 'small_registered') addReviewRow(list, 'Registreringsnummer', textValue('_ssf_registry_number'));
    if (selectedRoute() === 'restoration') addReviewRow(list, 'Restaureringens mål', textValue('_ssf_restoration_goal'));
    if (selectedRoute() === 'new_traditional') addReviewRow(list, 'Historisk fartygstyp', textValue('_ssf_traditional_archetype'));
    var mainImage = form.querySelector('[name="ssf_application_main_image"]');
    var gallery = form.querySelector('[name="ssf_application_gallery[]"]');
    var documents = form.querySelector('[name="ssf_application_documents[]"]');
    addReviewRow(list, 'Huvudbild', mainImage && mainImage.files[0] ? mainImage.files[0].name : 'Ingen vald');
    addReviewRow(list, 'Fler bilder', gallery && gallery.files.length ? gallery.files.length + ' valda' : 'Inga valda');
    addReviewRow(list, 'Dokument', documents && documents.files.length ? documents.files.length + ' valda' : 'Inga valda');
    addReviewRow(list, 'Fartygsombud', textValue('applicant_name'));
    addReviewRow(list, 'E-post', textValue('applicant_email'));
    review.appendChild(heading);
    review.appendChild(list);
  }

  function show() {
    updateRouteFields();
    primarySteps.forEach(function (step, position) {
      step.hidden = position !== primaryIndex;
      step.classList.toggle('is-active', position === primaryIndex);
    });
    indicators.forEach(function (indicator, position) {
      indicator.classList.toggle('is-current', position === primaryIndex);
      indicator.classList.toggle('is-complete', position < primaryIndex);
    });
    progress.style.width = primaryIndex === 0 ? '50%' : '100%';

    if (primaryIndex === 0) {
      count.textContent = 'Steg 1 av 2: Välj fartygstyp';
      previous.hidden = true;
      next.hidden = false;
      next.textContent = 'Fortsätt till fartygsuppgifter';
      submit.hidden = true;
      return;
    }

    var sections = visibleSections();
    sectionIndex = Math.max(0, Math.min(sectionIndex, sections.length - 1));
    form.querySelectorAll('.ssf-vessel-profile-section').forEach(function (section) { section.hidden = true; });
    sections.forEach(function (section, position) { section.hidden = position !== sectionIndex; });
    var active = sections[sectionIndex];
    var heading = active ? active.querySelector('h3') : null;
    sectionTitle.textContent = heading ? heading.textContent : 'Fartygsuppgifter';
    sectionCount.textContent = 'Avsnitt ' + (sectionIndex + 1) + ' av ' + sections.length;
    count.textContent = 'Steg 2 av 2: Fartygsuppgifter';
    previous.hidden = false;
    next.hidden = sectionIndex === sections.length - 1;
    next.textContent = sectionIndex === sections.length - 2 ? 'Granska ansökan' : 'Nästa avsnitt';
    submit.hidden = sectionIndex !== sections.length - 1;
    var selectedCard = form.querySelector('.ssf-route-card.is-selected strong');
    routeContext.textContent = selectedCard ? 'Vald ansökningsväg: ' + selectedCard.textContent : '';
    if (active && active.getAttribute('data-vessel-section') === 'review') updateReview();
  }

  next.addEventListener('click', function () {
    if (primaryIndex === 0) {
      if (!validate(primarySteps[0])) return;
      primaryIndex = 1;
      sectionIndex = 0;
    } else {
      var sections = visibleSections();
      if (!validate(sections[sectionIndex])) return;
      sectionIndex += 1;
    }
    show();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  previous.addEventListener('click', function () {
    if (primaryIndex === 1 && sectionIndex > 0) sectionIndex -= 1;
    else primaryIndex = 0;
    show();
  });

  form.querySelectorAll('[name="application_route"]').forEach(function (radio) {
    radio.addEventListener('change', updateRouteFields);
  });
  show();
}());
