/* TicketReport — in-app tab logic.
 *
 * Loaded once on every GLPI page via Hooks::ADD_JAVASCRIPT. The
 * Ticket form tabs are AJAX-injected, so we can't rely on
 * DOMContentLoaded or addEventListener-on-element wiring. Instead,
 * the rendered buttons in TicketTab.php invoke the functions
 * exposed on `window` directly (onclick="window.TicketReport.generate(this)").
 * Inline onclick is the most resilient way to wire AJAX-injected
 * markup back to JS, and avoids "click does nothing" mysteries.
 */
(() => {
  'use strict';
  console.log('[ticketreport] script loaded');

  let signaturePad   = null;
  let activeReportId = null;

  function ctxFor(btn) {
    const root = btn.closest('.ticketreport-tab');
    if (!root) {
      throw new Error('Report tab root not found in DOM');
    }
    return {
      ticketId: parseInt(root.dataset.ticketId, 10),
      baseUrl:  root.dataset.baseUrl,
      csrf:     root.dataset.csrf,
      vendor: {
        pdfJs:     root.dataset.vendorPdfjs,
        pdfWorker: root.dataset.vendorPdfworker,
        sigPad:    root.dataset.vendorSigpad,
      },
    };
  }

  async function generate(btn) {
    console.log('[ticketreport] generate clicked');
    let ctx;
    try { ctx = ctxFor(btn); } catch (e) { alert(e.message); return; }

    const fd = new FormData();
    fd.append('tickets_id', String(ctx.ticketId));
    fd.append('_glpi_csrf_token', ctx.csrf);

    btn.disabled = true;
    try {
      const res  = await fetch(`${ctx.baseUrl}/ajax/generate.php`, {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch (_e) { console.error('[ticketreport] non-JSON response:', text); throw new Error('Server returned non-JSON (HTTP ' + res.status + '). See console.'); }
      if (!res.ok || !data.ok) {
        throw new Error((data && data.error) || `HTTP ${res.status}`);
      }
      window.location.reload();
    } catch (e) {
      console.error('[ticketreport]', e);
      alert('Generate failed: ' + (e && e.message || e));
    } finally {
      btn.disabled = false;
    }
  }

  async function openSign(btn) {
    console.log('[ticketreport] open-sign clicked');
    const reportId = parseInt(btn.dataset.reportId || '0', 10);
    activeReportId = reportId;

    let ctx;
    try { ctx = ctxFor(btn); } catch (e) { alert(e.message); return; }

    try {
      await loadVendor(ctx.vendor);
    } catch (e) {
      alert('Could not load PDF / signature libraries: ' + e.message);
      return;
    }

    const modalEl = document.getElementById('trSignModal');
    if (!modalEl) { alert('Sign modal missing in DOM'); return; }
    if (!window.bootstrap || !window.bootstrap.Modal) {
      alert('Bootstrap JS not available — cannot open modal');
      return;
    }
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const pdfCanvas = document.getElementById('trPdfCanvas');
    try {
      await renderPdfFirstPage(`${ctx.baseUrl}/ajax/pdf_bytes.php?id=${reportId}`, pdfCanvas);
    } catch (e) {
      console.error('[ticketreport] pdf preview', e);
    }

    const sigCanvas = document.getElementById('trSignaturePad');
    sizeCanvasForDpr(sigCanvas);
    signaturePad = new window.SignaturePad(sigCanvas, { minWidth: 0.6, maxWidth: 2.0 });

    modalEl.dataset.submitUrl = `${ctx.baseUrl}/ajax/sign_submit.php`;
    modalEl.dataset.csrf      = ctx.csrf;
  }

  async function submitSignature() {
    if (!signaturePad || signaturePad.isEmpty()) {
      alert('Please draw a signature first.');
      return;
    }
    const modalEl = document.getElementById('trSignModal');
    const fd = new FormData();
    fd.append('reports_id',  String(activeReportId));
    fd.append('signature',   signaturePad.toDataURL('image/png'));
    fd.append('signer_name', (document.getElementById('trSignerName').value || '').trim());
    fd.append('_glpi_csrf_token', modalEl.dataset.csrf || '');

    try {
      const res  = await fetch(modalEl.dataset.submitUrl, {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        alert((data && data.error) || `HTTP ${res.status}`);
        return;
      }
      window.location.reload();
    } catch (e) {
      alert('Network error: ' + e.message);
    }
  }

  function clearSignature() {
    if (signaturePad) signaturePad.clear();
  }

  async function emailSign(btn) {
    console.log('[ticketreport] email-sign clicked');
    const reportId = parseInt(btn.dataset.reportId || '0', 10);
    let ctx;
    try { ctx = ctxFor(btn); } catch (e) { alert(e.message); return; }

    const email = window.prompt('Email address to send the signing link to:');
    if (!email) return;

    const fd = new FormData();
    fd.append('reports_id', String(reportId));
    fd.append('recipient_email', email);
    fd.append('_glpi_csrf_token', ctx.csrf);
    try {
      const res  = await fetch(`${ctx.baseUrl}/ajax/send_sign_email.php`, {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        alert((data && data.error) || `HTTP ${res.status}`);
        return;
      }
      alert('Signing link emailed.');
    } catch (e) {
      alert('Network error: ' + e.message);
    }
  }

  // ---- pdf.js helpers ----

  async function renderPdfFirstPage(url, canvas) {
    const pdfjs = window.pdfjsLib;
    if (!pdfjs) throw new Error('pdf.js not loaded');
    const pdf  = await pdfjs.getDocument({ url, withCredentials: true }).promise;
    const page = await pdf.getPage(1);

    const ratio   = window.devicePixelRatio || 1;
    const wrap    = canvas.parentElement;
    const targetW = wrap.clientWidth || 600;
    const vp1     = page.getViewport({ scale: 1 });
    const scale   = targetW / vp1.width;
    const vp      = page.getViewport({ scale });

    canvas.width  = Math.floor(vp.width  * ratio);
    canvas.height = Math.floor(vp.height * ratio);
    canvas.style.width  = vp.width  + 'px';
    canvas.style.height = vp.height + 'px';
    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
  }

  function sizeCanvasForDpr(canvas) {
    const ratio = window.devicePixelRatio || 1;
    const rect  = canvas.getBoundingClientRect();
    canvas.width  = Math.max(1, Math.floor(rect.width  * ratio));
    canvas.height = Math.max(1, Math.floor(rect.height * ratio));
    canvas.getContext('2d').scale(ratio, ratio);
  }

  // ---- vendor loader ----

  async function loadVendor(v) {
    if (!v || !v.pdfJs || !v.sigPad) {
      throw new Error('Vendor URLs missing on tab root (data-vendor-* attributes)');
    }
    if (!window.pdfjsLib)     await loadScript(v.pdfJs);
    if (!window.SignaturePad) await loadScript(v.sigPad);
    if (window.pdfjsLib && v.pdfWorker) {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = v.pdfWorker;
    }
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('failed to load ' + src));
      document.head.appendChild(s);
    });
  }

  // Expose namespaced API for inline onclick wiring.
  window.TicketReport = {
    generate, openSign, emailSign, submitSignature, clearSignature,
  };
})();
