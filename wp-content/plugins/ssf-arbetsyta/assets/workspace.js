(function () {
    'use strict';
    var button = document.querySelector('.ssf-workspace-menu-toggle');
    var nav = document.getElementById('ssf-workspace-nav');
    if (button && nav) {
        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            nav.classList.toggle('is-open', open);
        });
    }
    document.querySelectorAll('[data-ssf-dialog-open]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var dialog = document.getElementById(trigger.getAttribute('data-ssf-dialog-open'));
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        });
    });
    document.querySelectorAll('[data-ssf-dialog-close]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var dialog = trigger.closest('dialog');
            if (dialog) dialog.close();
        });
    });
    document.querySelectorAll('.ssf-user-dialog').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
    });
})();
