(function () {
  var tabs = document.querySelectorAll('[data-ssf-inspector-tab]');
  var panels = document.querySelectorAll('[data-ssf-inspector-panel]');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var target = tab.getAttribute('data-ssf-inspector-tab');
      tabs.forEach(function (item) { item.classList.toggle('is-active', item === tab); });
      panels.forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-ssf-inspector-panel') === target); });
    });
  });

  var updateProgress = function () {
    var selects = document.querySelectorAll('[data-ssf-check-status]');
    var complete = 0;
    selects.forEach(function (select) { if (select.value) { complete += 1; } });
    var label = document.querySelector('[data-ssf-progress-label]');
    var summary = document.querySelector('[data-ssf-progress-summary]');
    var bar = document.querySelector('[data-ssf-progress-bar]');
    if (label) { label.textContent = complete + ' av ' + selects.length; }
    if (summary) { summary.textContent = complete + ' av ' + selects.length + ' bedömda'; }
    if (bar) { bar.style.width = (selects.length ? Math.round(complete / selects.length * 100) : 0) + '%'; }
  };
  document.querySelectorAll('[data-ssf-check-status]').forEach(function (select) { select.addEventListener('change', updateProgress); });
  var completeButton = document.querySelector('[data-ssf-complete-report]');
  if (completeButton) {
    completeButton.addEventListener('click', function (event) {
      if (!window.confirm('Markera rapporten som klar? Alla kontrollpunkter måste vara bedömda och rapporten skickas vidare när övriga tilldelade inspektörer också är klara.')) {
        event.preventDefault();
      }
    });
  }
}());
