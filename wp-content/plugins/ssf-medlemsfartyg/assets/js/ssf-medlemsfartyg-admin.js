(function ($) {
  function addHiddenField(form, name, value) {
    $('<input>', { type: 'hidden', name: name, value: value }).appendTo(form);
  }

  $(document).on('click', '[data-ssf-token-action]', function () {
    var button = $(this);
    var container = button.closest('.ssf-ship-collection');
    var action = button.data('ssf-token-action');
    var actionMap = {
      generate: 'ssf_ship_generate_token',
      send: 'ssf_ship_send_token',
      revoke: 'ssf_ship_revoke_token'
    };

    if (!container.length || !actionMap[action]) {
      return;
    }

    if (action === 'revoke' && !window.confirm('Vill du spärra länken?')) {
      return;
    }

    var form = $('<form>', {
      method: 'post',
      action: container.data('action-url'),
      css: { display: 'none' }
    });

    addHiddenField(form, 'action', actionMap[action]);
    addHiddenField(form, 'ship_id', container.data('ship-id'));
    addHiddenField(form, '_wpnonce', container.data('nonce'));

    if (action === 'generate') {
      addHiddenField(form, 'recipient_name', container.find('.ssf-token-recipient-name').val());
      addHiddenField(form, 'recipient_email', container.find('.ssf-token-recipient-email').val());
      addHiddenField(form, 'expires_at', container.find('.ssf-token-expires-at').val());
    } else {
      addHiddenField(form, 'token_id', container.data('token-id'));
    }

    $('body').append(form);
    form[0].submit();
  });

  $(document).on('click', '[data-ssf-submission-action]', function () {
    var button = $(this);
    var container = button.closest('.ssf-submission-review');
    var action = button.data('ssf-submission-action');
    var actionMap = {
      approve: 'ssf_approve_ship_submission',
      reject: 'ssf_reject_ship_submission'
    };

    if (!container.length || !actionMap[action]) {
      return;
    }

    if (action === 'reject' && !window.confirm('Vill du avvisa inskicket?')) {
      return;
    }

    var form = $('<form>', {
      method: 'post',
      action: container.data('action-url'),
      css: { display: 'none' }
    });

    addHiddenField(form, 'action', actionMap[action]);
    addHiddenField(form, 'submission_id', container.data('submission-id'));
    addHiddenField(form, '_wpnonce', container.data('nonce'));

    if (action === 'approve') {
      addHiddenField(form, 'featured_image', container.find('.ssf-submission-featured-image:checked').val() || '');
    } else {
      addHiddenField(form, 'review_note', container.find('.ssf-submission-review-note').val());
    }

    $('body').append(form);
    form[0].submit();
  });

  $(document).on('click', '.ssf-gallery-button', function (event) {
    event.preventDefault();
    var button = $(this);
    var field = button.siblings('.ssf-gallery-field');
    var frame = wp.media({
      title: 'Välj bilder',
      button: { text: 'Använd bilder' },
      multiple: true
    });

    frame.on('select', function () {
      var ids = frame.state().get('selection').map(function (attachment) {
        return attachment.id;
      });
      field.val(ids.join(','));
    });

    frame.open();
  });
}(jQuery));
