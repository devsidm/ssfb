(function () {
  function submit(data, action) {
    var form = document.createElement('form'); form.method = 'post'; form.action = action; form.style.display = 'none';
    Object.keys(data).forEach(function (key) { var input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = data[key]; form.appendChild(input); });
    document.body.appendChild(form); form.submit();
  }
  document.addEventListener('click', function (event) {
    var note = event.target.closest('[data-ssf-add-note]');
    if (note) { var box = note.closest('.ssf-process-note-form'); submit({ action: 'ssf_add_application_note', application_id: box.dataset.applicationId, _wpnonce: box.dataset.nonce, message: box.querySelector('.ssf-process-note-text').value, visibility: box.querySelector('.ssf-process-note-visibility').value, send_email: box.querySelector('.ssf-process-note-email').checked ? '1' : '' }, box.dataset.actionUrl); }
    var token = event.target.closest('[data-ssf-token-action]');
    if (token) { if (token.dataset.ssfTokenAction === 'revoke' && !window.confirm('Vill du återkalla den befintliga statuslänken?')) return; submit({ action: 'ssf_application_token_action', application_id: token.dataset.applicationId, token_action: token.dataset.ssfTokenAction, _wpnonce: token.dataset.nonce }, window.ajaxurl.replace('admin-ajax.php', 'admin-post.php')); }
    if (event.target.closest('[data-ssf-print-report]')) window.print();
  });
}());
