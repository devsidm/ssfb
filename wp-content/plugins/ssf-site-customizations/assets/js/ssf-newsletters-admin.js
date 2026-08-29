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

  function syncYearOnly() {
    var yearOnly = $("[data-ssf-newsletter-year-only]").is(":checked");
    $("#ssf-newsletter-date").prop("disabled", yearOnly);
    $("[data-ssf-newsletter-year-field]").toggle(yearOnly);
    if (!yearOnly && $("#ssf-newsletter-date").val()) {
      $("#ssf-newsletter-year").val($("#ssf-newsletter-date").val().slice(0, 4));
    }
  }

  $(document).on("change", "[data-ssf-newsletter-year-only], #ssf-newsletter-date", syncYearOnly);
  $(syncYearOnly);

  function parseNewsletterFilename(filename) {
    var base = (filename || "").replace(/\.pdf$/i, "");
    var yearMatch = base.match(/(19|20)\d{2}/);
    var year = yearMatch ? yearMatch[0] : "";
    var issue = "";
    var withoutYear = year ? base.replace(year, "") : base;
    var issueMatch = withoutYear.match(/(?:^|[-_\s])(?:nr|no)?[-_\s]*0?(\d{1,2})(?:$|[-_\s])/i);
    if (issueMatch) {
      issue = String(parseInt(issueMatch[1], 10));
    }
    var series = /f(ö|o)rdevind/i.test(base) ? "Fördevind" : "";
    var fallbackTitle = base.replace(/[-_]+/g, " ").replace(/\s+/g, " ").trim();
    var title = series ? (series + (issue ? " nr " + issue : "") + (year ? " - " + year : "")) : fallbackTitle;

    return {
      title: title,
      series: series,
      issue: issue,
      year: year
    };
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, function (character) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "\"": "&quot;",
        "'": "&#039;"
      }[character];
    });
  }

  $(document).on("click", "[data-ssf-select-newsletter-import]", function (event) {
    event.preventDefault();

    var frame = wp.media({
      title: "Välj äldre nyhetsbrev",
      button: { text: "Använd valda PDF:er" },
      library: { type: "application/pdf" },
      multiple: true
    });

    frame.on("select", function () {
      var rows = [];
      frame.state().get("selection").each(function (item, index) {
        var attachment = item.toJSON();
        var parsed = parseNewsletterFilename(attachment.filename || attachment.title);
        rows.push(
          "<tr>" +
            "<td><strong>" + escapeHtml(attachment.filename || attachment.title) + "</strong><input type=\"hidden\" name=\"newsletter_import[" + index + "][pdf_id]\" value=\"" + escapeHtml(attachment.id) + "\"></td>" +
            "<td><input class=\"regular-text\" name=\"newsletter_import[" + index + "][title]\" value=\"" + escapeHtml(parsed.title) + "\"></td>" +
            "<td><input name=\"newsletter_import[" + index + "][series]\" value=\"" + escapeHtml(parsed.series) + "\"></td>" +
            "<td><input name=\"newsletter_import[" + index + "][issue]\" value=\"" + escapeHtml(parsed.issue) + "\"></td>" +
            "<td><input type=\"number\" min=\"1900\" max=\"2100\" name=\"newsletter_import[" + index + "][year]\" value=\"" + escapeHtml(parsed.year) + "\"></td>" +
          "</tr>"
        );
      });

      $("[data-ssf-newsletter-import-table]").html(
        "<table class=\"widefat striped\"><thead><tr><th>Fil</th><th>Titel</th><th>Serie</th><th>Nummer</th><th>År</th></tr></thead><tbody>" +
          rows.join("") +
        "</tbody></table>"
      );
    });

    frame.open();
  });
})(jQuery);
