(function ($) {
  function preview(control, attachment) {
    control.find('input').val(attachment ? attachment.id : '');
    control.find('.ssf-content-image-preview').html(attachment ? '<img src="' + attachment.url + '" alt="">' : '<span>Ingen egen bild vald</span>');
  }

  $(document).on('click', '[data-ssf-select-image]', function (event) {
    event.preventDefault();
    var control = $(this).closest('.ssf-content-image-control');
    var frame = wp.media({ title: 'Välj bild', button: { text: 'Använd bilden' }, multiple: false });
    frame.on('select', function () { preview(control, frame.state().get('selection').first().toJSON()); });
    frame.open();
  });

  $(document).on('click', '[data-ssf-remove-image]', function (event) {
    event.preventDefault();
    preview($(this).closest('.ssf-content-image-control'), null);
  });
}(jQuery));
