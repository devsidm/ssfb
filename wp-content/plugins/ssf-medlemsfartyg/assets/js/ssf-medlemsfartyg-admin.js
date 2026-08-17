(function ($) {
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
