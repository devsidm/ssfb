(function () {
  'use strict';

  var root = document.querySelector('[data-ssf-membership-portal]');
  if (!root) return;

  var dragged = null;

  root.addEventListener('dragstart', function (event) {
    var card = event.target.closest('.ssf-kanban-card');
    if (!card) return;
    dragged = card;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', card.dataset.caseUrl || '');
  });

  root.addEventListener('dragover', function (event) {
    var column = event.target.closest('.ssf-kanban-column');
    if (!column || !dragged) return;
    event.preventDefault();
    column.classList.add('is-drop-target');
  });

  root.addEventListener('dragleave', function (event) {
    var column = event.target.closest('.ssf-kanban-column');
    if (column) column.classList.remove('is-drop-target');
  });

  root.addEventListener('drop', function (event) {
    var column = event.target.closest('.ssf-kanban-column');
    if (!column || !dragged) return;
    event.preventDefault();
    column.classList.remove('is-drop-target');
    var url = dragged.dataset.caseUrl;
    if (url) {
      var join = url.indexOf('?') === -1 ? '?' : '&';
      window.location.href = url + join + 'portal_message=drag_opened#next-step';
    }
  });

  root.addEventListener('dragend', function () {
    root.querySelectorAll('.is-drop-target').forEach(function (column) {
      column.classList.remove('is-drop-target');
    });
    dragged = null;
  });
}());
