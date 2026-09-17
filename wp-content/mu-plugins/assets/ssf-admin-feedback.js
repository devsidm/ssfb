(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var dismiss = event.target.closest('[data-ssf-toast-dismiss]');
    if (dismiss) dismiss.closest('.ssf-admin-toast').remove();
  });
  document.querySelectorAll('[data-ssf-toast-autoclose]').forEach(function (toast) {
    window.setTimeout(function () { toast.remove(); }, Number(toast.dataset.ssfToastAutoclose) || 5000);
  });
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.ssfSubmitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.ssfSubmitting = '1';
    var button = event.submitter || form.querySelector('[type="submit"]');
    if (!button) return;
    var original = button.value || button.textContent;
    var busy = button.dataset.ssfBusyLabel || (/test|kontroll/i.test(original) ? 'Testar...' : 'Sparar...');
    window.setTimeout(function () {
      button.disabled = true;
      button.classList.add('ssf-admin-is-busy');
      if ('value' in button) button.value = busy; else button.textContent = busy;
    }, 0);
  });
}());
