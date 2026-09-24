/* Loaded on every GLPI page (Hooks::ADD_JAVASCRIPT). On a ticket page,
 * asks ajax/pending_signature.php whether the current user (a
 * requester) has a client signature pending, and if so opens the
 * ticket straight on the Report tab — without touching any other
 * navigation. No-ops instantly everywhere else.
 */
(() => {
  'use strict';

  const m = window.location.pathname.match(/\/front\/ticket\.form\.php$/);
  if (!m) return;

  const params = new URLSearchParams(window.location.search);
  const ticketId = parseInt(params.get('id') || '0', 10);
  if (ticketId <= 0) return;
  if (params.has('forcetab')) return; // already navigating to a specific tab — don't override

  const scripts = document.querySelectorAll('script[src*="pending-signature-redirect.js"]');
  if (scripts.length === 0) return;
  const base = (scripts[scripts.length - 1].getAttribute('src') || '')
    .replace(/\/public\/js\/pending-signature-redirect\.js.*$/, '');
  if (!base) return;

  fetch(`${base}/ajax/pending_signature.php?tickets_id=${ticketId}`, { credentials: 'same-origin' })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => {
      if (!data || !data.pending || !data.forcetab) return;
      const url = new URL(window.location.href);
      url.searchParams.set('forcetab', data.forcetab);
      window.location.replace(url.toString());
    })
    .catch(() => {});
})();
