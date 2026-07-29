/* deeno-author — тема deeno. Собственный код. */
(function () {
  'use strict';

  var body = document.body;
  var burger = document.getElementById('menu-toggle');
  var overlay = document.getElementById('overlay');
  var searchOverlay = document.getElementById('search-overlay');

  // ── Цвет панели браузера (meta[name=theme-color], см. layout.php) ──
  // Chrome/Android и Safari <26 читают тег; Safari 26 — фон страницы.
  var BAR = { light: '#f9fafb', dark: '#0F1117', lightDim: '#91949c', darkDim: '#10141e' };
  function overlayOpen() {
    return body.classList.contains('menu-open') || (searchOverlay && !searchOverlay.hidden);
  }
  function syncBar() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var open = overlayOpen();
    meta.setAttribute('content', dark ? (open ? BAR.darkDim : BAR.dark)
                                      : (open ? BAR.lightDim : BAR.light));
  }

  // ── Стоп-скролл под открытым слоем (меню/поиск) ──
  var lockedY = 0;
  function syncLock() {
    var open = overlayOpen();
    if (open === body.classList.contains('is-locked')) return;
    if (open) {
      lockedY = window.pageYOffset || document.documentElement.scrollTop || 0;
      body.style.top = -lockedY + 'px';
      body.style.setProperty('--lock-y', lockedY + 'px');
      body.classList.add('is-locked');
    } else {
      body.classList.remove('is-locked');
      body.style.top = '';
      body.style.removeProperty('--lock-y');
      window.scrollTo(0, lockedY);
    }
  }
  function sync() { syncLock(); syncBar(); }

  function closeMenu() { body.classList.remove('menu-open'); sync(); }

  if (burger) {
    burger.addEventListener('click', function () {
      body.classList.toggle('menu-open');
      sync();
    });
  }
  if (overlay) overlay.addEventListener('click', function () { closeMenu(); closeSearch(); });

  // Клик по пункту меню на мобильном закрывает выпадашку
  var topnav = document.getElementById('topnav');
  if (topnav) {
    topnav.addEventListener('click', function (e) {
      if (e.target.closest('a')) closeMenu();
    });
  }

  // ── Переключение темы: data-theme на <html> + localStorage ──
  var themeBtn = document.getElementById('theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      if (isDark) document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', 'dark');
      syncBar();
      try { localStorage.setItem('deeno-site-theme', isDark ? 'light' : 'dark'); } catch (e) {}
    });
  }

  // ── Оверлей поиска ──
  var openBtn = document.getElementById('search-open');
  var field = document.getElementById('search-field');
  function openSearch() {
    closeMenu();
    if (searchOverlay) { searchOverlay.hidden = false; sync(); if (field) field.focus(); }
  }
  function closeSearch() {
    if (searchOverlay && !searchOverlay.hidden) { searchOverlay.hidden = true; sync(); }
  }
  if (openBtn) openBtn.addEventListener('click', openSearch);
  if (searchOverlay) {
    searchOverlay.addEventListener('click', function (e) {
      if (e.target === searchOverlay) closeSearch();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeSearch(); closeMenu(); }
  });
})();
