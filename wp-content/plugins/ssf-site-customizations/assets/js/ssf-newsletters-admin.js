(function ($) {
  "use strict";

  $(document).on("click", "[data-ssf-select-newsletter-pdf]", function (event) {
    event.preventDefault();

    var frame = wp.media({
      title: "Välj PDF till nyhetsbrevet",
      button: { text: "Använd denna PDF" },
      library: { type: "application/pdf" },
      multiple: false
    });

    frame.on("select", function () {
      var attachment = frame.state().get("selection").first().toJSON();
      $("[data-ssf-newsletter-pdf-id]").val(attachment.id);
      $("[data-ssf-newsletter-pdf-name]").text(attachment.filename || attachment.title);
      $("[data-ssf-remove-newsletter-pdf]").prop("disabled", false);
    });

    frame.open();
  });

  $(document).on("click", "[data-ssf-remove-newsletter-pdf]", function (event) {
    event.preventDefault();
    $("[data-ssf-newsletter-pdf-id]").val("");
    $("[data-ssf-newsletter-pdf-name]").text("Ingen PDF vald.");
    $(this).prop("disabled", true);
  });
})(jQuery);
