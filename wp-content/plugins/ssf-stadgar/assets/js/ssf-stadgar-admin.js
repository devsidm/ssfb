(function ($) {
  'use strict';

  $(document).on('click', '.ssf-document-select-pdf', function (event) {
    event.preventDefault();
    var $box = $(this).closest('.ssf-document-admin-pdf');
    var frame = wp.media({
      title: 'Välj PDF',
      button: { text: 'Använd denna PDF' },
      library: { type: 'application/pdf' },
      multiple: false
    });

    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      $box.find('.ssf-document-pdf-id').val(attachment.id);
      $box.find('.ssf-document-pdf-name').html('<a href="' + attachment.url + '" target="_blank" rel="noopener">Öppna vald PDF</a>');
      $box.find('.ssf-document-analyse-pdf').prop('disabled', false);
      $box.find('.ssf-document-analysis-result').text('PDF vald. Klicka Uppdatera för att spara den, eller analysera den direkt efter sparandet.');
    });

    frame.open();
  });

  $(document).on('click', '.ssf-document-analyse-pdf', function (event) {
    event.preventDefault();
    var $box = $(this).closest('.ssf-document-admin-pdf');
    var $result = $box.find('.ssf-document-analysis-result');
    var documentId = $box.data('document-id');

    if (!documentId || !$box.find('.ssf-document-pdf-id').val()) {
      $result.text('Spara dokumentet med vald PDF innan analysen startas.');
      return;
    }

    $result.text('Analyserar PDF ...');
    $.post(ssfStadgarAdmin.ajaxUrl, {
      action: 'ssf_stadgar_extract_document',
      document_id: documentId,
      nonce: $box.data('nonce')
    }).done(function (response) {
      if (!response.success) {
        $result.text(response.data && response.data.message ? response.data.message : 'Analysen kunde inte genomföras.');
        return;
      }

      if (response.data.outline_text) {
        $('#ssf-document-outline').val(response.data.outline_text);
      }
      $result.text(response.data.message + (response.data.outline_text ? ' Snabböversikten har lagts i redigeringsfältet. Spara dokumentet när du har granskat den.' : ''));
    }).fail(function () {
      $result.text('Analysen kunde inte genomföras. Du kan fortfarande skriva snabböversikten manuellt.');
    });
  });

  $(document).on('change', 'input[name="ssf_document_current"]', function () {
    if (this.checked && !window.confirm(ssfStadgarAdmin.confirmCurrent)) {
      this.checked = false;
    }
  });
}(jQuery));
