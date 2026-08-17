(function () {
  document.addEventListener('click', function (event) {
    var link = event.target.closest('.ssf-ship-gallery a');
    if (!link) {
      return;
    }

    event.preventDefault();
    window.open(link.href, '_blank', 'noopener');
  });
}());
