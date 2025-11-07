(function () {
  'use strict';

  function copyTextFrom(targetId) {
    const el = document.getElementById(targetId);
    if (!el) return;
    const text = el.innerText || el.textContent || '';
    return navigator.clipboard.writeText(text);
  }

  function announce(btn, msg = 'Copied!') {
    const original = btn.textContent;
    btn.setAttribute('aria-live', 'polite');
    btn.textContent = msg;
    btn.disabled = true;
    setTimeout(() => {
      btn.textContent = original;
      btn.disabled = false;
    }, 1600);
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const targetId = btn.getAttribute('data-copy');
    copyTextFrom(targetId)
      .then(() => announce(btn))
      .catch(() => announce(btn, 'Press ⌘/Ctrl+C'));
  });

  // Smooth scroll for in-page anchors (reduced-motion aware)
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!prefersReduced) {
    document.querySelectorAll('a[href^="#"]').forEach(a => {
      a.addEventListener('click', (ev) => {
        const id = a.getAttribute('href').slice(1);
        const target = document.getElementById(id);
        if (target) {
          ev.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          target.setAttribute('tabindex', '-1');
          target.focus({ preventScroll: true });
        }
      });
    });
  }
})();
