(function () {
    function fallbackCopy(value) {
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ssf-copy]');
        if (! button) {
            return;
        }
        var target = document.querySelector(button.getAttribute('data-ssf-copy'));
        if (! target) {
            return;
        }
        var value = target.value || target.textContent || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value);
        } else {
            fallbackCopy(value);
        }
    });
}());
