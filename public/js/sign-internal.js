/* sign.form.php JS — full-page authenticated signing, vendor-free.
 *
 * Tiny canvas drawer using mouse + touch events directly (not the
 * Pointer Events API). Mouse + touch is supported on every browser
 * including older iOS WebViews and corporate kiosk browsers, where
 * Pointer Events sometimes silently no-op.
 */
(() => {
  'use strict';

  const form           = document.getElementById('trSignForm');
  const sigTechEl      = document.getElementById('trSigTech');
  const sigClientEl    = document.getElementById('trSigClient');
  const sigTechField   = document.getElementById('trSigTechField');
  const sigClientField = document.getElementById('trSigClientField');
  const msgEl          = document.getElementById('trMsg');
  const statusEl       = document.getElementById('trStatus');

  function setStatus(text, ok) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.style.color = ok ? '#0a7d2c' : '#c62828';
  }
  function setMsg(t, ok) {
    if (!msgEl) return;
    msgEl.textContent = t || '';
    msgEl.className   = 'mt-2 ' + (ok ? 'text-success' : 'text-danger');
  }

  if (!form || !sigTechEl || !sigClientEl) {
    setStatus('Error: missing form elements', false);
    console.error('sign-internal: missing DOM nodes');
    return;
  }

  // ---- minimal canvas pad ---------------------------------------
  function makePad(canvas) {
    const ratio = window.devicePixelRatio || 1;

    function fit() {
      const rect = canvas.getBoundingClientRect();
      const w = rect.width  > 0 ? rect.width  : 600;
      const h = rect.height > 0 ? rect.height : 200;
      canvas.width  = Math.max(1, Math.floor(w * ratio));
      canvas.height = Math.max(1, Math.floor(h * ratio));
      const ctx = canvas.getContext('2d');
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth   = 2;
      ctx.lineCap     = 'round';
      ctx.lineJoin    = 'round';
      ctx.strokeStyle = '#111';
      return ctx;
    }

    let ctx = fit();
    let drawing = false;
    let empty   = true;
    let last    = null;

    function getPos(e) {
      const r = canvas.getBoundingClientRect();
      const t = (e.touches && e.touches.length) ? e.touches[0]
              : (e.changedTouches && e.changedTouches.length) ? e.changedTouches[0]
              : e;
      return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function start(e) {
      e.preventDefault();
      drawing = true;
      empty   = false;
      last    = getPos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(last.x + 0.1, last.y + 0.1);
      ctx.stroke();
    }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      const p = getPos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
    }
    function end() {
      drawing = false;
    }

    // Mouse
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup',   end);
    canvas.addEventListener('mouseleave', end);
    // Touch — passive:false so preventDefault stops the page scrolling
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove',  move,  { passive: false });
    canvas.addEventListener('touchend',   end);
    canvas.addEventListener('touchcancel', end);

    // Re-fit on resize so rotation / window changes don't ruin coords.
    window.addEventListener('resize', () => {
      const data = empty ? null : canvas.toDataURL();
      ctx = fit();
      if (data) {
        const img = new Image();
        img.onload = () => ctx.drawImage(img, 0, 0, canvas.getBoundingClientRect().width, canvas.getBoundingClientRect().height);
        img.src = data;
      }
    });

    return {
      isEmpty:   () => empty,
      clear:     () => {
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
        ctx = fit();
        empty = true;
      },
      toDataURL: () => canvas.toDataURL('image/png'),
    };
  }

  const techAlreadySigned = !!(window.TicketReportSignPage && window.TicketReportSignPage.techAlreadySigned);
  const clientOnly        = !!(window.TicketReportSignPage && window.TicketReportSignPage.clientOnly);

  let padTech, padClient;
  try {
    // Client-only mode (requester self-service): the tech canvas
    // doesn't exist in the DOM, so we only set up the client pad.
    if (clientOnly) {
      padTech = { isEmpty: () => true, clear: () => {}, toDataURL: () => '' };
      padClient = makePad(sigClientEl);
    } else {
      padTech = makePad(sigTechEl);
      // Only wire up the client pad once a technician signature is on
      // file — the canvas is rendered disabled and the Save buttons are
      // disabled in HTML too, but we skip pad initialisation here so a
      // user playing with devtools can't draw into a dead canvas.
      if (techAlreadySigned) {
        padClient = makePad(sigClientEl);
      } else {
        padClient = {
          isEmpty:   () => true,
          clear:     () => {},
          toDataURL: () => '',
        };
      }
    }
    setStatus('Listo — dibuje su firma', true);
  } catch (e) {
    setStatus('Error inicializando: ' + e.message, false);
    console.error('sign-internal init failed', e);
    return;
  }

  document.querySelectorAll('button[data-clear]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const which = btn.getAttribute('data-clear');
      if (which === 'trSigTech'   && padTech)   padTech.clear();
      if (which === 'trSigClient' && padClient) padClient.clear();
    });
  });

  // Two independent submit buttons — each saves only its own signature
  // and leaves the other untouched on the server. The hidden `which`
  // field tells the server which side to update.
  function submitOnly(side) {
    const pad   = side === 'tech'   ? padTech      : padClient;
    const field = side === 'tech'   ? sigTechField : sigClientField;
    const other = side === 'tech'   ? sigClientField : sigTechField;
    if (pad.isEmpty()) {
      setMsg(side === 'tech'
        ? 'Por favor, dibuje la firma del técnico.'
        : 'Por favor, dibuje la firma del cliente.');
      return;
    }
    document.getElementById('trWhich').value = side;
    field.value = pad.toDataURL();
    other.value = '';   // don't overwrite the other side on the server
    form.submit();
  }

  const btnTech   = document.getElementById('trSubmitTech');
  const btnClient = document.getElementById('trSubmitClient');
  if (btnTech)   btnTech.addEventListener('click',   () => submitOnly('tech'));
  if (btnClient) btnClient.addEventListener('click', () => submitOnly('client'));
})();
