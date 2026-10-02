(function () {
  'use strict';

  function initAdminMobileMenu() {
    var sidebar = document.getElementById('adminSidebar');
    if (!sidebar) return;

    var toggle = sidebar.querySelector('.admin-sidebar__toggle');
    if (!toggle) return;

    function setOpen(open) {
      sidebar.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? '관리자 메뉴 닫기' : '관리자 메뉴 열기');
    }

    toggle.addEventListener('click', function () {
      setOpen(!sidebar.classList.contains('is-open'));
    });

    sidebar.addEventListener('click', function (event) {
      var link = event.target.closest('a');
      if (link && window.matchMedia('(max-width: 900px)').matches) setOpen(false);
    });

    document.addEventListener('click', function (event) {
      if (!sidebar.contains(event.target) && window.matchMedia('(max-width: 900px)').matches) setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') setOpen(false);
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 900) setOpen(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminMobileMenu);
  } else {
    initAdminMobileMenu();
  }
})();
