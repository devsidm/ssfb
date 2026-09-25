(function () {
    'use strict';
    var button = document.querySelector('.ssf-workspace-menu-toggle');
    var nav = document.getElementById('ssf-workspace-nav');
    if (!button || !nav) return;
    button.addEventListener('click', function () {
        var open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        nav.classList.toggle('is-open', open);
    });
})();
