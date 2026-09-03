(function () {
  'use strict';

  var editor = document.querySelector('[data-ssf-am-editor]');
  if (!editor) {
    return;
  }

  function activateTab(key) {
    editor.querySelectorAll('[data-ssf-admin-tab]').forEach(function (tab) {
      var active = tab.getAttribute('data-ssf-admin-tab') === key;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    editor.querySelectorAll('[data-ssf-admin-panel]').forEach(function (panel) {
      var active = panel.getAttribute('data-ssf-admin-panel') === key;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
  }

  function updateModule(key, checked, source) {
    editor.querySelectorAll('[data-ssf-module-toggle="' + key + '"]').forEach(function (toggle) {
      if (toggle !== source) {
        toggle.checked = checked;
      }
    });
    editor.querySelectorAll('[data-ssf-module-fields="' + key + '"]').forEach(function (fields) {
      fields.hidden = !checked;
    });
  }

  function updateRegistrationFields(row) {
    var toggle = row.querySelector('[data-ssf-registration-toggle]');
    var fields = row.querySelector('[data-ssf-registration-fields]');
    if (!toggle || !fields) {
      return;
    }
    fields.hidden = !toggle.checked;
    fields.querySelectorAll('input, select, textarea').forEach(function (control) {
      control.disabled = !toggle.checked;
    });
  }

  function updateProgramDays() {
    var start = editor.querySelector('[data-ssf-weekend-start]');
    var duration = editor.querySelector('[data-ssf-weekend-duration]');
    var days = duration ? Number(duration.value) : 1;
    var startDate = start && start.value ? new Date(start.value + 'T12:00:00') : null;
    editor.querySelectorAll('[data-ssf-program-day]').forEach(function (select) {
      select.querySelectorAll('option').forEach(function (option) {
        var day = Number(option.value);
        var available = day <= days;
        option.disabled = !available;
        option.hidden = !available;
        if (startDate) {
          var date = new Date(startDate.getTime());
          date.setDate(date.getDate() + day - 1);
          option.textContent = 'Dag ' + day + ' - ' + date.toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' });
        } else {
          option.textContent = 'Dag ' + day;
        }
      });
      if (Number(select.value) > days) {
        select.value = String(days);
      }
    });
  }

  function updateOrder(repeater) {
    repeater.querySelectorAll('[data-ssf-repeater-row]').forEach(function (row, index) {
      var order = row.querySelector('[data-ssf-order]');
      if (order) {
        order.value = String(index);
      }
    });
  }

  function initialiseRow(row) {
    updateRegistrationFields(row);
    var input = row.querySelector('[data-ssf-title-input]');
    if (input) {
      input.addEventListener('input', function () {
        var title = row.querySelector('[data-ssf-item-title]');
        if (title) {
          title.textContent = input.value.trim() || 'Ny post';
        }
      });
    }
  }

  function selectMedia(field) {
    if (!window.wp || !wp.media) {
      return;
    }
    var frame = wp.media({
      title: 'Välj PDF',
      button: { text: 'Använd filen' },
      library: { type: field.getAttribute('data-mime') || 'application/pdf' },
      multiple: false
    });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      field.querySelector('[data-ssf-media-id]').value = attachment.id;
      field.querySelector('[data-ssf-media-name]').textContent = attachment.filename || attachment.title;
      var remove = field.querySelector('[data-ssf-remove-media]');
      if (remove) {
        remove.hidden = false;
      }
    });
    frame.open();
  }

  editor.addEventListener('click', function (event) {
    var tab = event.target.closest('[data-ssf-admin-tab]');
    if (tab) {
      activateTab(tab.getAttribute('data-ssf-admin-tab'));
      return;
    }

    var add = event.target.closest('[data-ssf-add-row]');
    if (add) {
      var kind = add.getAttribute('data-ssf-add-row');
      var template = document.getElementById('tmpl-ssf-am-' + kind + '-row');
      var repeater = editor.querySelector('[data-ssf-repeater="' + kind + '"]');
      if (template && repeater) {
        var index = Date.now();
        repeater.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(index)));
        var row = repeater.lastElementChild;
        initialiseRow(row);
        updateOrder(repeater);
        if (kind === 'program') {
          updateProgramDays();
        }
      }
      return;
    }

    var remove = event.target.closest('[data-ssf-remove-row]');
    if (remove) {
      var row = remove.closest('[data-ssf-repeater-row]');
      var parent = row && row.parentElement;
      if (row && window.confirm('Ta bort posten?')) {
        row.remove();
        updateOrder(parent);
      }
      return;
    }

    var move = event.target.closest('[data-ssf-move]');
    if (move) {
      var movingRow = move.closest('[data-ssf-repeater-row]');
      var direction = move.getAttribute('data-ssf-move');
      if (movingRow && direction === 'up' && movingRow.previousElementSibling) {
        movingRow.parentElement.insertBefore(movingRow, movingRow.previousElementSibling);
      } else if (movingRow && direction === 'down' && movingRow.nextElementSibling) {
        movingRow.parentElement.insertBefore(movingRow.nextElementSibling, movingRow);
      }
      if (movingRow) {
        updateOrder(movingRow.parentElement);
      }
      return;
    }

    var select = event.target.closest('[data-ssf-select-media]');
    if (select) {
      selectMedia(select.closest('[data-ssf-media-field]'));
      return;
    }

    var removeMedia = event.target.closest('[data-ssf-remove-media]');
    if (removeMedia) {
      var field = removeMedia.closest('[data-ssf-media-field]');
      field.querySelector('[data-ssf-media-id]').value = '';
      field.querySelector('[data-ssf-media-name]').textContent = 'Ingen fil vald';
      removeMedia.hidden = true;
    }
  });

  editor.addEventListener('change', function (event) {
    if (event.target.matches('[data-ssf-module-toggle]')) {
      updateModule(event.target.getAttribute('data-ssf-module-toggle'), event.target.checked, event.target);
    }
    if (event.target.matches('[data-ssf-registration-toggle]')) {
      updateRegistrationFields(event.target.closest('[data-ssf-repeater-row]'));
    }
    if (event.target.matches('[data-ssf-weekend-start], [data-ssf-weekend-duration]')) {
      updateProgramDays();
    }
  });

  editor.querySelectorAll('[data-ssf-module-toggle]').forEach(function (toggle) {
    updateModule(toggle.getAttribute('data-ssf-module-toggle'), toggle.checked, toggle);
  });
  editor.querySelectorAll('[data-ssf-repeater-row]').forEach(initialiseRow);
  updateProgramDays();
}());
