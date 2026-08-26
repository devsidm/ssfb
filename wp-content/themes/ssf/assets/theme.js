(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.site-menu-toggle');
    var nav = document.querySelector('.site-nav');
    if (!toggle || !nav) {
      return;
    }

    var setSubmenuState = function (item, isOpen) {
      item.classList.toggle('is-submenu-open', isOpen);
      var button = item.querySelector(':scope > .submenu-toggle');
      if (button) {
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
    };

    var closeSubmenus = function (except) {
      nav.querySelectorAll('.menu-item-has-children.is-submenu-open').forEach(function (item) {
        if (item === except) {
          return;
        }
        setSubmenuState(item, false);
      });
    };

    var openSubmenu = function (item) {
      closeSubmenus(item);
      setSubmenuState(item, true);
    };

    nav.querySelectorAll('.menu-item-has-children').forEach(function (item, index) {
      var link = item.querySelector(':scope > a');
      var submenu = item.querySelector(':scope > .sub-menu');
      if (!link || !submenu) {
        return;
      }

      var submenuId = submenu.id || 'ssf-submenu-' + index;
      submenu.id = submenuId;
      var submenuToggle = document.createElement('button');
      submenuToggle.type = 'button';
      submenuToggle.className = 'submenu-toggle';
      submenuToggle.setAttribute('aria-expanded', 'false');
      submenuToggle.setAttribute('aria-controls', submenuId);
      submenuToggle.setAttribute('aria-label', 'Visa undermeny för ' + link.textContent.trim());
      item.insertBefore(submenuToggle, submenu);

      submenuToggle.addEventListener('click', function () {
        var willOpen = !item.classList.contains('is-submenu-open');
        if (willOpen) {
          openSubmenu(item);
        } else {
          setSubmenuState(item, false);
        }
      });

      item.addEventListener('mouseenter', function () {
        if (window.matchMedia('(min-width: 721px)').matches) {
          openSubmenu(item);
        }
      });

      item.addEventListener('mouseleave', function () {
        if (window.matchMedia('(min-width: 721px)').matches && !item.contains(document.activeElement)) {
          setSubmenuState(item, false);
        }
      });

      item.addEventListener('focusin', function () {
        openSubmenu(item);
      });

      item.addEventListener('focusout', function () {
        window.setTimeout(function () {
          if (!item.contains(document.activeElement)) {
            setSubmenuState(item, false);
          }
        }, 0);
      });
    });

    toggle.addEventListener('click', function () {
      var isOpen = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (!isOpen) {
        closeSubmenus();
      }
    });

    document.addEventListener('click', function (event) {
      if (!nav.contains(event.target) && !toggle.contains(event.target)) {
        closeSubmenus();
      }
    });

    document.addEventListener('keydown', function (event) {
      if ('Escape' === event.key) {
        var openItem = nav.querySelector('.menu-item-has-children.is-submenu-open');
        closeSubmenus();
        if (openItem) {
          var parentLink = openItem.querySelector(':scope > a');
          if (parentLink) {
            parentLink.focus();
          }
        }
      }
    });
  });
}());
