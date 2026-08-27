(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var add = event.target.closest('[data-ssf-add-row]');
    if (add) {
      var kind = add.getAttribute('data-ssf-add-row');
      var template = document.getElementById('tmpl-ssf-am-' + kind + '-row');
      var table = document.querySelector('[data-ssf-repeater="' + kind + '"] tbody');
      if (template && table) {
        var index = table.querySelectorAll('tr').length;
        table.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(index)));
      }
      return;
    }
    var remove = event.target.closest('[data-ssf-remove-row]');
    if (remove) {
      var row = remove.closest('tr');
      if (row) {
        row.remove();
      }
    }
  });
}());
