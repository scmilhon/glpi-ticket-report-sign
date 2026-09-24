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
      // Draws a previously-saved signature onto the pad (a saved
      // signature counts as "not empty" so Save works without the
      // user drawing anything).
      loadDataURL: (dataUrl) => new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
          const rect = canvas.getBoundingClientRect();
          ctx.drawImage(img, 0, 0, rect.width, rect.height);
          empty = false;
          resolve();
        };
        img.onerror = () => resolve();
        img.src = dataUrl;
      }),
    };
  }

  const techAlreadySigned = !!(window.TicketReportSignPage && window.TicketReportSignPage.techAlreadySigned);
  const clientOnly        = !!(window.TicketReportSignPage && window.TicketReportSignPage.clientOnly);

  let padTech, padClient;
  try {
    // Client-only mode (requester self-service): the tech canvas
    // doesn't exist in the DOM, so we only set up the client pad.
    const stubPad = { isEmpty: () => true, clear: () => {}, toDataURL: () => '', loadDataURL: () => Promise.resolve() };
    if (clientOnly) {
      padTech = stubPad;
      padClient = makePad(sigClientEl);
    } else {
      padTech = makePad(sigTechEl);
      // Only wire up the client pad once a technician signature is on
      // file — the canvas is rendered disabled and the Save buttons are
      // disabled in HTML too, but we skip pad initialisation here so a
      // user playing with devtools can't draw into a dead canvas.
      padClient = techAlreadySigned ? makePad(sigClientEl) : stubPad;
    }
    setStatus('Listo — dibuje su firma', true);
  } catch (e) {
    setStatus('Error inicializando: ' + e.message, false);
    console.error('sign-internal init failed', e);
    return;
  }

  const cfgSig = window.TicketReportSignPage || {};

  // Show what's already on file for THIS report first — "already
  // signed" shouldn't mean staring at a blank canvas.
  if (cfgSig.existingTechSignature && padTech) {
    padTech.loadDataURL(cfgSig.existingTechSignature).then(() => {
      setStatus('Firma actual del técnico cargada', true);
    });
  } else if (cfgSig.savedTechSignature && padTech && !techAlreadySigned) {
    // No signature on this report yet — offer the reusable one.
    padTech.loadDataURL(cfgSig.savedTechSignature).then(() => {
      setStatus('Firma guardada cargada — listo para guardar', true);
    });
  }
  if (cfgSig.existingClientSignature && padClient) {
    padClient.loadDataURL(cfgSig.existingClientSignature);
  }

  // Walk-in signer who isn't one of the ticket's existing requesters:
  // the technician types an email address here, we mint+send them
  // their own code exactly like the automatic email, then the
  // technician enters that code in trOtpCode like any other signer's.
  const otpAdhocToggle = document.getElementById('trOtpAdhocToggle');
  const otpAdhocBox    = document.getElementById('trOtpAdhocBox');
  if (otpAdhocToggle && otpAdhocBox) {
    otpAdhocToggle.addEventListener('click', () => {
      otpAdhocBox.classList.toggle('d-none');
      otpAdhocBox.classList.toggle('d-flex');
    });
  }
  const otpAdhocSend = document.getElementById('trOtpAdhocSend');
  if (otpAdhocSend) {
    otpAdhocSend.addEventListener('click', async () => {
      const cfg     = window.TicketReportSignPage || {};
      const nameEl  = document.getElementById('trOtpAdhocName');
      const emailEl = document.getElementById('trOtpAdhocEmail');
      const adhocMsgEl = document.getElementById('trOtpAdhocMsg');
      const setAdhocMsg = (t, ok) => {
        if (!adhocMsgEl) return;
        adhocMsgEl.textContent = t || '';
        adhocMsgEl.className   = 'small mt-1 ' + (ok ? 'text-success' : 'text-danger');
      };
      const name  = ((nameEl  && nameEl.value)  || '').trim();
      const email = ((emailEl && emailEl.value) || '').trim();
      if (!name) {
        setAdhocMsg('Ingrese el nombre del firmante.');
        return;
      }
      if (!email) {
        setAdhocMsg('Ingrese un correo.');
        return;
      }
      otpAdhocSend.disabled = true;
      try {
        const fd = new FormData();
        fd.append('reports_id', String(cfg.reportId || ''));
        fd.append('email', email);
        fd.append('name', name);
        fd.append('_glpi_csrf_token', cfg.csrf || '');
        const res  = await fetch(cfg.mintWalkinUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error((data && data.error) || 'error');
        }
        setAdhocMsg('Código enviado. Pídale que lo revise en su correo.', true);
      } catch (e) {
        setAdhocMsg('No se pudo enviar el código: ' + e.message);
      } finally {
        otpAdhocSend.disabled = false;
      }
    });
  }

  // The Name field / signature canvas stay hidden (trClientFields)
  // until this succeeds — see front/sign.form.php's $needsOtp block.
  // Verifying here does NOT consume the code; only actually saving
  // the signature does (front/sign.submit.php), so a technician can
  // freely re-check a mistyped code without burning it.
  const otpVerifyBtn    = document.getElementById('trOtpVerify');
  const otpVerifyMsgEl  = document.getElementById('trOtpVerifyMsg');
  const clientFields    = document.getElementById('trClientFields');
  const clientNameInput = document.getElementById('trSigClientName');
  if (otpVerifyBtn) {
    otpVerifyBtn.addEventListener('click', async () => {
      const cfg   = window.TicketReportSignPage || {};
      const otpEl = document.getElementById('trOtpCode');
      const otp   = ((otpEl && otpEl.value) || '').trim();
      const setVerifyMsg = (t, ok) => {
        if (!otpVerifyMsgEl) return;
        otpVerifyMsgEl.textContent = t || '';
        otpVerifyMsgEl.className   = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
      };
      if (!/^\d{6}$/.test(otp)) {
        setVerifyMsg('Ingrese el código de 6 dígitos.');
        return;
      }
      otpVerifyBtn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('reports_id', String(cfg.reportId || ''));
        fd.append('otp_code', otp);
        fd.append('_glpi_csrf_token', cfg.csrf || '');
        const res  = await fetch(cfg.verifyOtpUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error((data && data.error) || 'error');
        }
        setVerifyMsg('Código verificado.', true);
        // readOnly, not disabled: a disabled field is excluded from the
        // form's own POST, and the server re-checks this same code
        // when the signature is actually saved (front/sign.submit.php).
        if (otpEl) otpEl.readOnly = true;
        otpVerifyBtn.disabled = true;
        otpVerifyBtn.textContent = 'Verificado';
        if (clientFields) {
          clientFields.classList.remove('d-none');
          // The canvas was rendered inside a hidden (display:none)
          // container, so its initial fit() saw a 0x0 box and fell
          // back to a default size — re-run it now that the real
          // dimensions are available (padClient listens for resize).
          window.dispatchEvent(new Event('resize'));
        }
        if (clientNameInput && data.name) {
          clientNameInput.value = data.name;
        }
      } catch (e) {
        otpVerifyBtn.disabled = false;
        setVerifyMsg('Código inválido o vencido. Revíselo con el firmante.');
      }
    });
  }

  const deleteSavedBtn = document.getElementById('trDeleteSavedSig');
  if (deleteSavedBtn) {
    deleteSavedBtn.addEventListener('click', async () => {
      const cfg = window.TicketReportSignPage || {};
      deleteSavedBtn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('_glpi_csrf_token', cfg.csrf || '');
        await fetch(cfg.clearSavedSigUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        if (padTech) padTech.clear();
        deleteSavedBtn.remove();
        const hint = document.getElementById('trSavedSigHint');
        if (hint) hint.remove();
        setMsg('Firma guardada eliminada.', true);
      } catch (e) {
        deleteSavedBtn.disabled = false;
        setMsg('No se pudo eliminar la firma guardada: ' + e.message, false);
      }
    });
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
    if (side === 'client' && window.TicketReportSignPage && window.TicketReportSignPage.needsOtp) {
      const otpEl = document.getElementById('trOtpCode');
      if (!otpEl || !/^\d{6}$/.test(otpEl.value.trim())) {
        setMsg('Ingrese el código de verificación de 6 dígitos.');
        return;
      }
    }
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
