/* Public signing page logic — vendor-free.
 * Tiny canvas pad implementation; the report PDF is provided via a
 * "View report" link that opens in a new tab rather than rendered
 * with pdf.js.
 */
(() => {
  'use strict';
  const cfg = window.TicketReportSign;
  if (!cfg) return;

  const sigCanvas = document.getElementById('sig');
  const msg       = document.getElementById('msg');

  const setMsg = (t, ok) => {
    if (!msg) return;
    msg.textContent = t || '';
    msg.style.color = ok ? '#080' : '#a00';
  };

  if (!sigCanvas) return; // already-signed (read-only) branch — no pad on this page load

  function makePad(canvas) {
    const ratio = window.devicePixelRatio || 1;
    const rect  = canvas.getBoundingClientRect();
    canvas.width  = Math.max(1, Math.floor(rect.width  * ratio));
    canvas.height = Math.max(1, Math.floor(rect.height * ratio));
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth   = 2;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    ctx.strokeStyle = '#111';

    let drawing = false;
    let empty   = true;
    let last    = null;

    const pos = (e) => {
      const r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    };
    const start = (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      drawing = true; empty = false;
      last = pos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(last.x + 0.1, last.y + 0.1);
      ctx.stroke();
      try { canvas.setPointerCapture(e.pointerId); } catch (_e) {}
    };
    const move = (e) => {
      if (!drawing) return;
      e.preventDefault();
      const p = pos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
    };
    const end = (e) => {
      if (!drawing) return;
      drawing = false;
      try { canvas.releasePointerCapture(e.pointerId); } catch (_e) {}
    };

    canvas.addEventListener('pointerdown',   start);
    canvas.addEventListener('pointermove',   move);
    canvas.addEventListener('pointerup',     end);
    canvas.addEventListener('pointercancel', end);
    canvas.addEventListener('pointerleave',  (e) => { if (drawing) end(e); });

    return {
      isEmpty:   () => empty,
      clear:     () => {
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
        empty = true;
      },
      toDataURL: () => canvas.toDataURL('image/png'),
    };
  }

  const pad = makePad(sigCanvas);

  document.getElementById('clearSig').addEventListener('click', () => pad.clear());

  document.getElementById('submitSig').addEventListener('click', async () => {
    if (pad.isEmpty()) {
      setMsg('Por favor, dibuje su firma.');
      return;
    }
    const confirmBox = document.getElementById('clientConfirms');
    if (confirmBox && !confirmBox.checked) {
      setMsg('Por favor, confirme que esta firma es suya.');
      return;
    }
    const fd = new FormData();
    fd.append('reports_id',  String(cfg.reportId));
    fd.append('signature',   pad.toDataURL());
    fd.append('signer_name', (document.getElementById('signerName').value || '').trim());
    fd.append('token',       cfg.token);
    fd.append('_glpiticketreportsign_client_confirms', '1');
    try {
      const res  = await fetch(cfg.submitUrl, { method: 'POST', body: fd, headers: { 'X-Glpi-Csrf-Token': cfg.csrfToken || '' } });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        setMsg((data && data.error) || 'No se pudo guardar la firma.');
        return;
      }
      document.getElementById('submitSig').disabled = true;

      if (data.token && Array.isArray(data.siblings) && data.siblings.length > 1) {
        setMsg('Firma guardada.', true);
        const chooseBox = document.getElementById('chooseVersion');
        if (chooseBox) {
          chooseBox.style.display = 'block';
          chooseBox.querySelectorAll('.chooseBtn').forEach((btn) => {
            btn.addEventListener('click', async () => {
              chooseBox.querySelectorAll('.chooseBtn').forEach((b) => { b.disabled = true; });
              try {
                const cfd = new FormData();
                cfd.append('token', data.token);
                cfd.append('reports_id', btn.dataset.reportsId);
                const cres = await fetch(cfg.chooseUrl, { method: 'POST', body: cfd, headers: { 'X-Glpi-Csrf-Token': cfg.csrfToken || '' } });
                const cdata = await cres.json().catch(() => ({}));
                setMsg(cres.ok && cdata.ok
                  ? 'Firma guardada. Le enviamos esa versión por correo.'
                  : 'Firma guardada, pero no pudimos enviar el correo.', cres.ok && cdata.ok);
              } catch (e) {
                setMsg('Firma guardada, pero hubo un error de red al enviar el correo.');
              }
              chooseBox.style.display = 'none';
            });
          });
        }
      } else {
        setMsg('Firma guardada. Puede cerrar esta página.', true);
      }
    } catch (e) {
      setMsg('Error de red: ' + e.message);
    }
  });
})();
