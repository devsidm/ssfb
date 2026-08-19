(function () {
  var form = document.querySelector('[data-ssf-application-form]');
  if (!form) return;
  var steps = Array.prototype.slice.call(form.querySelectorAll('.ssf-process-step'));
  var previous = form.querySelector('[data-ssf-prev]');
  var next = form.querySelector('[data-ssf-next]');
  var submit = form.querySelector('[data-ssf-submit]');
  var count = form.querySelector('[data-ssf-step-count]');
  var progress = form.querySelector('[data-ssf-progress]');
  var index = 0;
  function showStep() {
    steps.forEach(function (step, position) { step.hidden = position !== index; step.classList.toggle('is-active', position === index); });
    previous.hidden = index === 0;
    next.hidden = index === steps.length - 1;
    submit.hidden = index !== steps.length - 1;
    count.textContent = 'Steg ' + (index + 1) + ' av ' + steps.length;
    progress.style.width = (((index + 1) / steps.length) * 100) + '%';
  }
  function validStep() {
    var controls = steps[index].querySelectorAll('input, textarea, select');
    for (var i = 0; i < controls.length; i += 1) if (!controls[i].checkValidity()) { controls[i].reportValidity(); return false; }
    return true;
  }
  next.addEventListener('click', function () { if (validStep()) { index += 1; showStep(); } });
  previous.addEventListener('click', function () { index -= 1; showStep(); });
  showStep();
}());
