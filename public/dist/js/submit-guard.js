/**
 * System-wide duplicate-submission guard for every full-page (non-modal)
 * form. Complements, and is intentionally separate from, app-ui.js's own
 * submit interceptor, which already handles forms inside #appAjaxModal --
 * this module explicitly cedes to that one and only ever touches forms
 * outside it.
 *
 * This is deliberately NOT a fetch()-based interceptor: it does not
 * preventDefault() or change how a form is submitted or how its response
 * is handled. It only (a) disables the submit button the instant a valid
 * submit is accepted, swapping in a "Saving..." label + spinner so the
 * user gets immediate feedback and can't usefully click again, (b) blocks
 * a second submit of the same form instance (double-click, Enter-key
 * resubmit) at the DOM level, and (c) attaches a fresh per-form-instance
 * idempotency key so the server can recognize and safely replay a request
 * that reaches it more than once (see app/Core/Idempotency.php) -- the
 * server-side guard is the real protection; this is the frontend half.
 *
 * Opt out of this on a specific form with data-no-submit-guard -- e.g. a
 * form with its own bespoke upload-progress UI, or a genuine
 * multi-independent-action form where disabling sibling buttons would be
 * wrong.
 */
(function () {
  'use strict';

  var BUSY_LABELS = ['Saving...', 'Processing...', 'Submitting...', 'Please wait...'];

  function pickBusyLabel(button) {
    var explicit = button.getAttribute('data-busy-label');
    if (explicit) {
      return explicit;
    }
    var text = (button.textContent || '').toLowerCase();
    if (text.indexOf('approve') !== -1 || text.indexOf('post') !== -1 || text.indexOf('release') !== -1 || text.indexOf('disburse') !== -1 || text.indexOf('pay') !== -1) {
      return 'Processing...';
    }
    return BUSY_LABELS[0];
  }

  function ensureIdempotencyKey(form) {
    var existing = form.querySelector('input[name="_idempotency_key"]');
    if (existing && existing.value) {
      return;
    }
    var input = existing || document.createElement('input');
    input.type = 'hidden';
    input.name = '_idempotency_key';
    input.value = (window.crypto && window.crypto.randomUUID)
      ? window.crypto.randomUUID()
      : 'k-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    if (!existing) {
      form.appendChild(input);
    }
  }

  function disableSiblingSubmitters(form, clicked) {
    var selector = 'button[type="submit"], input[type="submit"]';
    form.querySelectorAll(selector).forEach(function (btn) {
      if (btn === clicked) {
        return;
      }
      btn.disabled = true;
    });
  }

  function busyify(button) {
    if (!button || button.dataset.guardOriginalHtml) {
      return;
    }
    button.dataset.guardOriginalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + pickBusyLabel(button);
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }
    if (form.hasAttribute('data-no-submit-guard')) {
      return; // explicit opt-out
    }
    if (form.closest && form.closest('#appAjaxModal')) {
      return; // app-ui.js's own interceptor already owns this one
    }
    if (e.defaultPrevented) {
      return; // something else (e.g. a cancelled confirm dialog) already blocked this submit
    }
    if (!form.checkValidity()) {
      return; // let the browser's native validation UI show; nothing is actually submitting yet
    }
    if (form.dataset.guardSubmitted === '1') {
      e.preventDefault();
      return; // re-entrant submit (Enter-key resubmit, a second click that slipped through)
    }
    form.dataset.guardSubmitted = '1';

    ensureIdempotencyKey(form);

    var clicked = e.submitter || form.querySelector('button[type="submit"]:focus') || null;
    disableSiblingSubmitters(form, clicked);
    busyify(clicked);

    // Bfcache safety: if the user navigates back to this exact page state
    // (e.g. the submit failed server-side with no redirect and the browser
    // restored the page from cache), the form must not stay permanently
    // disabled from the previous attempt.
    window.addEventListener('pageshow', function () {
      form.dataset.guardSubmitted = '0';
      form.querySelectorAll('[data-guard-original-html]').forEach(function (btn) {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.guardOriginalHtml;
        delete btn.dataset.guardOriginalHtml;
      });
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.disabled = false;
      });
    }, { once: true });
  });
})();
