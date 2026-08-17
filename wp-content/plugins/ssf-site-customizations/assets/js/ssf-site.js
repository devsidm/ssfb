(function () {
  function getValue(form, name) {
    var field = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
    return field ? field.value : '';
  }

  function applicationResult(form) {
    var isSail = getValue(form, 'segelfartyg') === 'ja';
    var hasHistory = getValue(form, 'yrkeshistorik') === 'ja';
    var isTraditional = getValue(form, 'traditionell_nybyggnad') === 'ja';
    var length = parseFloat((getValue(form, 'langd') || '').replace(',', '.')) || 0;
    var width = parseFloat((getValue(form, 'bredd') || '').replace(',', '.')) || 0;
    var registered = getValue(form, 'svenskt_register') === 'ja';

    if (!isSail) {
      return ['Fartyget uppfyller inte grundkraven', 'Fartyget behöver vara ett segelfartyg eller segelfartyg med hjälpmotor.'];
    }
    if (!hasHistory && !isTraditional) {
      return ['Fartyget behöver kompletterande bedömning', 'Fartyget saknar angiven yrkeshistorik och är inte markerat som nybyggt i traditionell stil.'];
    }
    if (length > 12 && width >= 4) {
      return ['Fartyget kan gå vidare till ansökan som aspirant', 'Utifrån svaren uppfyller fartyget måttkraven. Ansökan kan skickas in för styrelsens prövning.'];
    }
    if (registered) {
      return ['Särskild prövning kan vara möjlig', 'Fartyget uppfyller inte måttkraven, men är registrerat i svenskt skeppsregister. Ansökan kan skickas in för särskild prövning.'];
    }
    if (length > 0 || width > 0) {
      return ['Fartyget uppfyller inte kraven för särskild prövning', 'Fartyg som understiger måttkraven behöver vara registrerade i svenskt skeppsregister för att kunna prövas särskilt.'];
    }
    return ['Ansökan behöver granskas av styrelsen', 'Svaren ger inte ett entydigt resultat. Skicka gärna in uppgifterna så kan styrelsen göra en bedömning enligt SSF:s stadgar.'];
  }

  function initApplicationForm(form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll('.ssf-form-step'));
    var prev = form.querySelector('[data-ssf-prev]');
    var next = form.querySelector('[data-ssf-next]');
    var submit = form.querySelector('[data-ssf-submit]');
    var progress = form.querySelector('.ssf-progress span');
    var current = 0;

    function show(index) {
      current = Math.max(0, Math.min(index, steps.length - 1));
      steps.forEach(function (step, stepIndex) {
        step.classList.toggle('is-active', stepIndex === current);
      });
      prev.style.display = current === 0 ? 'none' : '';
      next.style.display = current === steps.length - 1 ? 'none' : '';
      submit.style.display = current === steps.length - 1 ? '' : 'none';
      progress.style.width = (((current + 1) / steps.length) * 100) + '%';

      if (current === steps.length - 1) {
        var result = applicationResult(form);
        var target = form.querySelector('.ssf-form-result');
        target.innerHTML = '<h3>' + result[0] + '</h3><p>' + result[1] + '</p>';
      }
    }

    next.addEventListener('click', function () {
      var invalid = steps[current].querySelector(':invalid');
      if (invalid) {
        invalid.reportValidity();
        return;
      }
      show(current + 1);
    });
    prev.addEventListener('click', function () {
      show(current - 1);
    });
    show(0);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ssf-application-form').forEach(initApplicationForm);
  });
}());
