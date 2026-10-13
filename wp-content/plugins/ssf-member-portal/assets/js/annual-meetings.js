(function () {
  'use strict';

  function personName(card, index) {
    var fields = card.querySelectorAll('[data-ssf-person-name]');
    var name = Array.prototype.map.call(fields, function (field) { return field.value.trim(); }).join(' ').trim();
    return name || 'Person ' + (index + 1);
  }

  function reindex(form) {
    var cards = form.querySelectorAll('[data-ssf-person]');
    cards.forEach(function (card, index) {
      var oldIndex = card.getAttribute('data-person-index');
      card.setAttribute('data-person-index', index);
      card.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace('participants[' + oldIndex + ']', 'participants[' + index + ']');
      });
      card.querySelector('[data-ssf-person-heading]').textContent = personName(card, index);
    });
    syncPeople(form);
  }

  function syncPeople(form) {
    var people = Array.prototype.map.call(form.querySelectorAll('[data-ssf-person]'), function (card, index) {
      var idField = card.querySelector('input[type="hidden"][name$="[id]"]');
      var id = card.getAttribute('data-person-id') || (idField ? idField.value : 'person_' + (index + 1));
      card.setAttribute('data-person-id', id);
      return { index: index, id: id, name: personName(card, index) };
    });
    form.querySelectorAll('[data-ssf-activity]').forEach(function (activity) {
      var key = activity.getAttribute('data-ssf-activity');
      var container = activity.querySelector('[data-ssf-activity-people]');
      var selected = {};
      container.querySelectorAll('input:checked').forEach(function (input) { selected[input.getAttribute('data-person-id')] = true; });
      container.innerHTML = '';
      people.forEach(function (person) {
        var label = document.createElement('label');
        label.className = 'ssf-am-option';
        label.innerHTML = '<input type="checkbox" data-person-id="' + person.id + '" name="participants[' + person.index + '][activities][' + key + ']" value="1"> <span></span>';
        label.querySelector('span').textContent = person.name;
        label.querySelector('input').checked = !!selected[person.id];
        label.querySelector('input').disabled = activity.getAttribute('data-disabled') === '1' && !selected[person.id];
        container.appendChild(label);
      });
    });
    syncActivityQuestions(form, people);
    refreshCounts(form);
  }

  function syncActivityQuestions(form, people) {
    var visibleGroups = 0;
    form.querySelectorAll('[data-ssf-activity-question-group]').forEach(function (group) {
      var activityKey = group.getAttribute('data-activity-key');
      var selected = {};
      form.querySelectorAll('[data-ssf-activity="' + activityKey + '"] input:checked').forEach(function (input) { selected[input.getAttribute('data-person-id')] = true; });
      var anySelected = Object.keys(selected).length > 0;
      group.hidden = !anySelected;
      if (anySelected) { visibleGroups++; }
      if (group.getAttribute('data-question-scope') === 'registration') {
        group.querySelectorAll('input, select, textarea').forEach(function (field) {
          field.disabled = !anySelected;
          if (field.dataset.required === '1') { field.required = anySelected; }
        });
        return;
      }
      var container = group.querySelector('[data-ssf-question-people]');
      var existing = {};
      container.querySelectorAll('[data-ssf-question-person]').forEach(function (card) { existing[card.getAttribute('data-person-id')] = card; });
      var base = container.querySelector('[data-ssf-question-person]');
      people.forEach(function (person) {
        var card = existing[person.id];
        if (!card && base) {
          card = base.cloneNode(true);
          var oldId = card.getAttribute('data-person-id');
          card.setAttribute('data-person-id', person.id);
          card.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('[participants][' + oldId + ']', '[participants][' + person.id + ']');
            field.checked = false;
            if (field.type !== 'checkbox' && field.type !== 'radio') { field.value = ''; }
          });
          container.appendChild(card);
          existing[person.id] = card;
        }
        if (!card) { return; }
        card.querySelector('h3').textContent = person.name;
        var active = !!selected[person.id];
        card.hidden = !active;
        card.querySelectorAll('input, select, textarea').forEach(function (field) {
          field.disabled = !active;
          if (field.dataset.required === '1') { field.required = active; }
        });
      });
      Object.keys(existing).forEach(function (id) { if (!people.some(function (person) { return person.id === id; })) existing[id].remove(); });
    });
    var empty = form.querySelector('[data-ssf-no-activity-questions]');
    if (empty) { empty.hidden = visibleGroups > 0; }
  }

  function refreshCounts(form) {
    form.querySelectorAll('[data-ssf-activity]').forEach(function (activity) {
      var count = activity.querySelectorAll('input:checked').length;
      activity.querySelector('[data-ssf-activity-count]').textContent = count + (count === 1 ? ' person' : ' personer');
    });
  }

  function answerText(container) {
    var values = [];
    container.querySelectorAll('input:not([disabled]), textarea:not([disabled]), select:not([disabled])').forEach(function (field) {
      if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) { return; }
      if (field.value) { values.push(field.value === 'yes' ? 'Ja' : (field.value === 'no' ? 'Nej' : field.value)); }
    });
    return values.join(', ');
  }

  function buildReview(form) {
    var review = form.querySelector('[data-ssf-review]');
    review.innerHTML = '';
    form.querySelectorAll('[data-ssf-person]').forEach(function (card, index) {
      var article = document.createElement('article');
      article.className = 'ssf-am-review-person';
      var activities = [];
      form.querySelectorAll('[data-ssf-activity]').forEach(function (activity) {
        if (activity.querySelector('input[name^="participants[' + index + ']"]:checked')) activities.push(activity.querySelector('h3').textContent);
      });
      article.innerHTML = '<h3></h3><p></p>';
      article.querySelector('h3').textContent = personName(card, index);
      article.querySelector('p').textContent = activities.length ? activities.join(' · ') : 'Inga aktiviteter valda';
      var personId = card.getAttribute('data-person-id');
      form.querySelectorAll('[data-ssf-question-person][data-person-id="' + personId + '"]:not([hidden])').forEach(function (answerCard) {
        var value = answerText(answerCard);
        var legend = answerCard.closest('[data-ssf-activity-question-group]').querySelector('legend').textContent.replace('*', '').trim();
        if (value) { var answerRow = document.createElement('p'); answerRow.innerHTML = '<strong></strong> <span></span>'; answerRow.querySelector('strong').textContent = legend + ':'; answerRow.querySelector('span').textContent = value; article.appendChild(answerRow); }
      });
      review.appendChild(article);
    });
    var summary = document.createElement('article');
    summary.className = 'ssf-am-review-summary';
    summary.innerHTML = '<h3>Sammanfattning</h3><p>' + form.querySelectorAll('[data-ssf-person]').length + ' deltagare</p>';
    form.querySelectorAll('[data-ssf-activity]').forEach(function (activity) {
      summary.insertAdjacentHTML('beforeend', '<div><span></span><strong>' + activity.querySelectorAll('input:checked').length + '</strong></div>');
      summary.lastElementChild.querySelector('span').textContent = activity.querySelector('h3').textContent;
    });
    form.querySelectorAll('[data-ssf-activity-question-group][data-question-scope="registration"]:not([hidden])').forEach(function (group) {
      var value = answerText(group);
      if (value) { summary.insertAdjacentHTML('beforeend', '<p><strong></strong> <span></span></p>'); summary.lastElementChild.querySelector('strong').textContent = group.querySelector('legend').textContent.replace('*', '').trim() + ':'; summary.lastElementChild.querySelector('span').textContent = value; }
    });
    review.appendChild(summary);
  }

  document.querySelectorAll('[data-ssf-error-message]').forEach(function (message) { message.focus(); });
  document.querySelectorAll('.ssf-am-calendar-menu').forEach(function (menu) { menu.addEventListener('keydown', function (event) { if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); } }); });
  document.querySelectorAll('[data-ssf-group-registration]').forEach(function (form) {
    var current = 1;
    form.classList.add('is-step-flow');
    function show(step, moveFocus) {
      current = Math.max(1, Math.min(3, step));
      form.querySelectorAll('[data-ssf-step]').forEach(function (section) { section.hidden = Number(section.getAttribute('data-ssf-step')) !== current; });
      form.querySelectorAll('[data-ssf-step-indicator]').forEach(function (item) {
        var active = Number(item.getAttribute('data-ssf-step-indicator')) === current;
        item.classList.toggle('is-active', active);
        if (active) { item.setAttribute('aria-current', 'step'); } else { item.removeAttribute('aria-current'); }
      });
      if (current === 2) syncPeople(form);
      if (current === 3) buildReview(form);
      var section = form.querySelector('[data-ssf-step="' + current + '"]');
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (moveFocus !== false) { section.querySelector('h2').focus({ preventScroll: true }); }
    }
    function currentStepIsValid() {
      var section = form.querySelector('[data-ssf-step="' + current + '"]');
      var fields = section ? section.querySelectorAll('input, select, textarea') : [];
      for (var index = 0; index < fields.length; index++) {
        if (!fields[index].checkValidity()) { fields[index].reportValidity(); return false; }
      }
      if (current === 2) {
        var people = form.querySelectorAll('[data-ssf-person]');
        for (var personIndex = 0; personIndex < people.length; personIndex++) {
          if (!section.querySelector('input[name^="participants[' + personIndex + '][activities]"]:checked')) {
            window.alert('Välj minst en aktivitet för ' + personName(people[personIndex], personIndex) + '.');
            return false;
          }
        }
      }
      return true;
    }
    syncPeople(form); show(1, !document.querySelector('[data-ssf-error-message]'));
    form.addEventListener('input', function (event) { if (event.target.matches('[data-ssf-person-name]')) syncPeople(form); });
    form.addEventListener('change', function () { syncPeople(form); });
    form.addEventListener('click', function (event) {
      if (event.target.closest('[data-ssf-next]')) { if (currentStepIsValid()) show(current + 1); }
      if (event.target.closest('[data-ssf-back]')) show(current - 1);
      if (event.target.closest('[data-ssf-add-person]')) {
        var index = form.querySelectorAll('[data-ssf-person]').length;
        var html = form.querySelector('[data-ssf-person-template]').innerHTML.replaceAll('__INDEX__', index).replaceAll('__NUMBER__', index + 1).replaceAll('__TOKEN__', Date.now());
        form.querySelector('[data-ssf-participants]').insertAdjacentHTML('beforeend', html); reindex(form);
      }
      var remove = event.target.closest('[data-ssf-remove-person]');
      if (remove) { remove.closest('[data-ssf-person]').remove(); reindex(form); }
    });
    form.addEventListener('submit', function () { var button = form.querySelector('[data-ssf-submit]'); if (button) { button.disabled = true; button.textContent = button.getAttribute('data-sending-label'); } });
  });
}());
