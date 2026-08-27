(function () {
  'use strict';

  function refreshRelations(form) {
    var checked = form.querySelector('[name="relationship"]:checked');
    var relationship = checked ? checked.value : '';
    form.querySelectorAll('[data-ssf-relationship]').forEach(function (element) {
      element.hidden = element.getAttribute('data-ssf-relationship') !== relationship;
    });
    var association = form.querySelector('[data-ssf-associated-toggle]');
    var vessels = form.querySelector('[data-ssf-associated-vessels]');
    if (vessels) {
      vessels.hidden = !association || !association.checked;
    }
  }

  document.querySelectorAll('.ssf-am-form').forEach(function (form) {
    refreshRelations(form);
    form.addEventListener('change', function (event) {
      if (event.target.matches('[name="relationship"], [data-ssf-associated-toggle]')) {
        refreshRelations(form);
      }
    });
    form.addEventListener('click', function (event) {
      var add = event.target.closest('[data-ssf-add-vessel]');
      if (add) {
        var group = add.previousElementSibling;
        if (!group || !group.hasAttribute('data-ssf-vessels')) {
          return;
        }
        var first = group.querySelector('input');
        if (!first) {
          return;
        }
        var item = document.createElement('span');
        item.className = 'ssf-am-vessel';
        item.innerHTML = '<input name="' + first.name + '" placeholder="Fartygsnamn"><button type="button" class="ssf-am-icon-button" data-ssf-remove-vessel aria-label="Ta bort fartyg">×</button>';
        group.appendChild(item);
        item.querySelector('input').focus();
      }
      var remove = event.target.closest('[data-ssf-remove-vessel]');
      if (remove) {
        var vessel = remove.closest('.ssf-am-vessel');
        var container = vessel && vessel.parentElement;
        if (vessel && container && container.querySelectorAll('.ssf-am-vessel').length > 1) {
          vessel.remove();
        } else if (vessel) {
          vessel.querySelector('input').value = '';
        }
      }
    });
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      }
    });
  });
}());
