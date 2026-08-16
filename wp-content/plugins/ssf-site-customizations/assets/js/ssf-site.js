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
      return ['Fartyget uppfyller inte grundkraven', 'Fartyget behover vara ett segelfartyg eller segelfartyg med hjalpmotor.'];
    }
    if (!hasHistory && !isTraditional) {
      return ['Fartyget behover kompletterande bedomning', 'Fartyget saknar angiven yrkeshistorik och ar inte markerat som nybyggt i traditionell stil.'];
    }
    if (length > 12 && width >= 4) {
      return ['Fartyget kan ga vidare till ansokan som aspirant', 'Utifran svaren uppfyller fartyget mattkraven. Ansokan kan skickas in for styrelsens provning.'];
    }
    if (registered) {
      return ['Sarskild provning kan vara mojlig', 'Fartyget uppfyller inte mattkraven, men ar registrerat i svenskt skeppsregister. Ansokan kan skickas in for sarskild provning.'];
    }
    if (length > 0 || width > 0) {
      return ['Fartyget uppfyller inte kraven for sarskild provning', 'Fartyg som understiger mattkraven behover vara registrerade i svenskt skeppsregister for att kunna provas sarskilt.'];
    }
    return ['Ansokan behover granskas av styrelsen', 'Svaren ger inte ett entydigt resultat. Skicka garna in uppgifterna sa kan styrelsen gora en bedomning enligt SSF:s stadgar.'];
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
