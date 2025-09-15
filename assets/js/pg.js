// assets/js/pg.js — robust tabs + underline + mobile 100vh fix
(() => {
  /* ---------- Mobile 100vh fix (prevents clipped content on iOS/Android) ---------- */
  function setVH() {
    document.documentElement.style.setProperty('--vh', window.innerHeight + 'px');
  }
  setVH();
  window.addEventListener('resize', setVH);
  window.addEventListener('orientationchange', setVH);

  /* ---------- Tabs logic (no undefined .classList) ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.pg');
    if (!root) return;

    const tabs = Array.from(root.querySelectorAll('.tab[data-target]'));
    const underline = root.querySelector('.underline');

    const byId = (id) => (id ? root.querySelector('#' + id) : null);
    const norm = (key) => (key || '').replace(/^pg-/, '');

    // Build a map of panes that actually exist
    const panes = {};
    ['login', 'register'].forEach((k) => {
      const p = byId('pg-' + k) || byId(k);
      if (p) panes[k] = p;
    });
    const paneKeys = Object.keys(panes);
    if (!paneKeys.length) return; // nothing to toggle

    // Move underline under the active tab
    function moveUnderline(el) {
      if (!underline || !el || !el.parentElement) return;
      const r = el.getBoundingClientRect();
      const base = el.parentElement.getBoundingClientRect().left;
      underline.style.width = r.width + 'px';
      underline.style.transform = `translateX(${r.left - base}px)`;
    }

    let current = null;

    function show(nextRaw) {
      const next = norm(nextRaw);
      const nextPane = panes[next];
      if (!nextPane) return;              // do nothing if pane doesn't exist
      if (current && panes[current]) {
        panes[current].classList.remove('active');
        panes[current].setAttribute('hidden', '');
      }
      nextPane.classList.add('active');
      nextPane.removeAttribute('hidden');

      tabs.forEach((t) =>
        t.classList.toggle('active', norm(t.dataset.target) === next)
      );
      moveUnderline(tabs.find((t) => t.classList.contains('active')));

      current = next;
    }

    // Choose a safe starting pane:
    // 1) ?tab=… in URL (accept "login" or "pg-login")
    // 2) a tab already marked .active
    // 3) first available pane
    const params = new URLSearchParams(location.search);
    let start = norm(params.get('tab'));
    if (!panes[start]) {
      const tabActive = tabs.find((t) => t.classList.contains('active'));
      start = tabActive ? norm(tabActive.dataset.target) : paneKeys[0];
    }
    if (!panes[start]) start = paneKeys[0]; // final fallback

    // Initialize
    show(start);

    // Handlers
    tabs.forEach((t) =>
      t.addEventListener('click', () => show(t.dataset.target))
    );
    const reposition = () =>
      moveUnderline(tabs.find((t) => t.classList.contains('active')));
    window.addEventListener('resize', reposition);
    if (document.fonts?.ready) document.fonts.ready.then(reposition).catch(() => {});
  });
})();
