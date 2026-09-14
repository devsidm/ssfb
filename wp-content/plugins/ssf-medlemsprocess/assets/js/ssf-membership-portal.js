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

  root.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-ssf-dialog-open]');
    if (opener) {
      var dialog = document.getElementById(opener.getAttribute('data-ssf-dialog-open'));
      if (!dialog) return;
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', 'open');
      }
      return;
    }

    if (event.target.matches('[data-ssf-dialog-close]')) {
      var closeDialog = event.target.closest('dialog');
      if (closeDialog && typeof closeDialog.close === 'function') {
        closeDialog.close();
      } else if (closeDialog) {
        closeDialog.removeAttribute('open');
      }
    }
  });
}());
