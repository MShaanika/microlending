/**
 * Shared UI helpers: Bootstrap toasts for flash-message feedback, and a
 * single reusable Bootstrap modal standing in for window.confirm() before
 * a form submits. Both build their DOM lazily on first use so no markup
 * needs to live in the layout templates.
 */
(function () {
  'use strict';

  function buildToastContainer() {
    var container = document.getElementById('appToastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'appToastContainer';
      container.className = 'toast-container position-fixed top-0 end-0 p-3';
      container.style.zIndex = 1080;
      document.body.appendChild(container);
    }
    return container;
  }

  window.showToast = function (message, type) {
    type = type || 'success';
    var container = buildToastContainer();
    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white bg-' + type + ' border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    var body = document.createElement('div');
    body.className = 'd-flex';

    var toastBody = document.createElement('div');
    toastBody.className = 'toast-body';
    var icon = document.createElement('i');
    icon.className = 'mdi ' + (type === 'danger' ? 'mdi-alert-circle' : 'mdi-check-circle') + ' me-1';
    toastBody.appendChild(icon);
    toastBody.appendChild(document.createTextNode(' ' + message));

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');
    closeBtn.setAttribute('aria-label', 'Close');

    body.appendChild(toastBody);
    body.appendChild(closeBtn);
    toastEl.appendChild(body);
    container.appendChild(toastEl);

    var toast = new bootstrap.Toast(toastEl, { delay: 6000 });
    toastEl.addEventListener('hidden.bs.toast', function () {
      toastEl.remove();
    });
    toast.show();
  };

  function showFlashToasts() {
    document.querySelectorAll('.js-flash-toast').forEach(function (el) {
      window.showToast(el.dataset.toastMessage, el.dataset.toastType);
      el.remove();
    });
  }

  function ensureConfirmModal() {
    var modalEl = document.getElementById('appConfirmModal');
    if (modalEl) {
      return modalEl;
    }
    modalEl = document.createElement('div');
    modalEl.id = 'appConfirmModal';
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.innerHTML =
      '<div class="modal-dialog modal-dialog-centered">' +
        '<div class="modal-content">' +
          '<div class="modal-header">' +
            '<h5 class="modal-title">Please confirm</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
          '</div>' +
          '<div class="modal-body js-confirm-message"></div>' +
          '<div class="modal-footer">' +
            '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>' +
            '<button type="button" class="btn btn-primary js-confirm-btn">Confirm</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modalEl);
    return modalEl;
  }

  // Replaces `onsubmit="return confirm('...')"` / `onclick="return confirm('...')"`.
  // Call as confirmSubmit(this, message) from onsubmit, or
  // confirmSubmit(this.form, message) from a submit button's onclick.
  // Always returns false to block the native, synchronous submit; the
  // confirm button then submits the form for real via form.submit(),
  // which does not re-trigger the onsubmit handler.
  window.confirmSubmit = function (form, message) {
    var modalEl = ensureConfirmModal();
    modalEl.querySelector('.js-confirm-message').textContent = message;
    var confirmBtn = modalEl.querySelector('.js-confirm-btn');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);

    var handler = function () {
      confirmBtn.removeEventListener('click', handler);
      modal.hide();
      form.submit();
    };
    confirmBtn.addEventListener('click', handler);
    modal.show();
    return false;
  };

  document.addEventListener('DOMContentLoaded', showFlashToasts);

  // ------------------------------------------------------------------
  // AJAX modals -- one shared modal shell that loads a controller's
  // existing .content fragment (see Controller::fragment()) via fetch(),
  // and a delegated form-submit handler that posts through the same
  // route and expects JSON back (see Controller::jsonSuccess/jsonErrors)
  // instead of a flash+redirect. Every trigger keeps its real href, so
  // plain navigation to the same URL still renders the full page exactly
  // as before -- this is pure progressive enhancement, not a new source
  // of truth.
  // ------------------------------------------------------------------

  function ensureAjaxModal() {
    var modalEl = document.getElementById('appAjaxModal');
    if (modalEl) {
      return modalEl;
    }
    modalEl = document.createElement('div');
    modalEl.id = 'appAjaxModal';
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.innerHTML =
      '<div class="modal-dialog modal-dialog-scrollable modal-lg">' +
        '<div class="modal-content">' +
          '<div class="modal-header">' +
            '<h5 class="modal-title"></h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
          '</div>' +
          '<div class="modal-body"></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modalEl);
    return modalEl;
  }

  function loadingSpinner() {
    return '<div class="text-center py-5"><div class="spinner-border text-info" role="status"><span class="visually-hidden">Loading...</span></div></div>';
  }

  function setModalBody(modalEl, html) {
    var body = modalEl.querySelector('.modal-body');
    body.innerHTML = html;
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
  }

  function fetchFragment(url) {
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (res) {
      if (!res.ok) {
        throw new Error('Request failed (' + res.status + ')');
      }
      return res.text();
    });
  }

  function reloadAjaxModal(modalEl) {
    var url = modalEl.dataset.currentUrl;
    if (!url) {
      return;
    }
    setModalBody(modalEl, loadingSpinner());
    fetchFragment(url)
      .then(function (html) { setModalBody(modalEl, html); })
      .catch(function (err) { setModalBody(modalEl, '<div class="alert alert-danger mb-0">' + err.message + '</div>'); });
  }

  // opts: { title, size ('sm'|'lg'|'xl'), refresh (false to skip refreshing
  // the underlying list on successful submit -- true by default) }
  window.openAjaxModal = function (url, opts) {
    opts = opts || {};
    var modalEl = ensureAjaxModal();

    var dialog = modalEl.querySelector('.modal-dialog');
    dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
    dialog.classList.add('modal-' + (opts.size || 'lg'));

    modalEl.querySelector('.modal-title').textContent = opts.title || '';
    modalEl.dataset.currentUrl = url;
    modalEl.dataset.refresh = opts.refresh === false ? '0' : '1';

    setModalBody(modalEl, loadingSpinner());

    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();

    fetchFragment(url)
      .then(function (html) { setModalBody(modalEl, html); })
      .catch(function (err) { setModalBody(modalEl, '<div class="alert alert-danger mb-0">Could not load this. ' + err.message + '</div>'); });

    return modal;
  };

  // Re-fetches the current page's own URL (fragment form) and swaps it into
  // #pageContent -- the same wrapper every layout page already renders its
  // content into (layouts/main.php), so this works for any list/report page
  // without per-page markup changes. Re-runs DataTables on the fresh table.
  window.refreshPageContent = function () {
    var container = document.getElementById('pageContent');
    if (!container) {
      return;
    }
    fetchFragment(window.location.href).then(function (html) {
      container.innerHTML = html;
      if (typeof feather !== 'undefined') {
        feather.replace();
      }
      if (typeof window.initDataTables === 'function') {
        window.initDataTables();
      }
      if (typeof window.initSelect2 === 'function') {
        window.initSelect2();
      }
    });
  };

  function fieldInput(form, field) {
    return form.querySelector('[name="' + field + '"]') || form.querySelector('[name="' + field + '[]"]');
  }

  // Most .content forms already render a .invalid-feedback element next to
  // every validated input (sometimes only when PHP already found an error,
  // i.e. absent on a clean load) -- find it by walking siblings rather than
  // assuming a specific wrapper class, since field markup varies (mb-3,
  // col-md-*, plain div) from screen to screen; create one if it's missing
  // so this works regardless of which of the two conventions a given form
  // happens to use.
  function showFieldError(form, field, message) {
    var input = fieldInput(form, field);
    if (!input) {
      window.showToast(message, 'danger');
      return;
    }
    input.classList.add('is-invalid');
    var node = input.nextElementSibling;
    while (node) {
      if (node.classList && node.classList.contains('invalid-feedback')) {
        node.classList.add('d-block');
        node.textContent = message;
        return;
      }
      node = node.nextElementSibling;
    }
    var feedback = document.createElement('div');
    feedback.className = 'invalid-feedback d-block';
    feedback.textContent = message;
    input.insertAdjacentElement('afterend', feedback);
  }

  function clearFieldErrors(form) {
    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
  }

  function setSubmitBusy(form, busy) {
    form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
      if (busy) {
        btn.dataset.originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + btn.textContent.trim();
      } else if (btn.dataset.originalHtml) {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalHtml;
      }
    });
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-modal-url]');
    if (!trigger) {
      return;
    }
    e.preventDefault();
    window.openAjaxModal(trigger.getAttribute('data-modal-url'), {
      title: trigger.getAttribute('data-modal-title') || '',
      size: trigger.getAttribute('data-modal-size') || 'lg',
      refresh: trigger.getAttribute('data-modal-refresh') !== '0',
    });
  });

  // Offer letter template preview (recruitment/offers/show) -- delegated so it
  // works whether the fragment loads via a full page or is injected into the
  // AJAX modal via innerHTML, which never executes embedded <script> tags.
  document.addEventListener('change', function (e) {
    var select = e.target.closest('[data-offer-preview-vars]');
    if (!select) {
      return;
    }
    var preview = document.getElementById('letterPreview');
    var printBtn = document.getElementById('printLetter');
    if (!preview || !printBtn) {
      return;
    }
    if (!select.value) {
      preview.classList.add('d-none');
      printBtn.classList.add('d-none');
      return;
    }
    var vars = JSON.parse(select.getAttribute('data-offer-preview-vars'));
    var text = select.value;
    Object.keys(vars).forEach(function (k) {
      text = text.split('{{' + k + '}}').join(vars[k]);
    });
    preview.textContent = text;
    preview.classList.remove('d-none');
    printBtn.classList.remove('d-none');
  });

  // Delegated so it works for the initial fragment AND anything the
  // fragment loads later (e.g. a nested add-item mini-form).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var modalEl = form.closest ? form.closest('#appAjaxModal') : null;
    if (!modalEl) {
      return; // Not inside the AJAX modal -- let it submit normally (full-page fallback).
    }
    if (e.defaultPrevented) {
      return; // An inline onsubmit (e.g. a confirm() dialog the user cancelled) already blocked this submit.
    }
    e.preventDefault();

    if (form.dataset.submitting === '1') {
      return; // Duplicate-submit guard.
    }
    form.dataset.submitting = '1';

    clearFieldErrors(form);
    setSubmitBusy(form, true);

    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('Unexpected response from the server.');
        });
      })
      .then(function (data) {
        form.dataset.submitting = '0';

        if (data.success) {
          window.showToast(data.message || 'Saved.', 'success');

          if (data.redirect) {
            window.location.href = data.redirect;
            return;
          }

          var behavior = form.getAttribute('data-modal-behavior') || 'close';
          if (behavior === 'reload') {
            setSubmitBusy(form, false);
            reloadAjaxModal(modalEl);
          } else {
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
              modal.hide();
            }
          }

          if (modalEl.dataset.refresh !== '0') {
            window.refreshPageContent();
          }
        } else {
          setSubmitBusy(form, false);
          var errors = data.errors || {};
          Object.keys(errors).forEach(function (field) {
            if (field === '_csrf') {
              window.showToast(errors[field], 'danger');
            } else {
              showFieldError(form, field, errors[field]);
            }
          });
          var firstInvalid = form.querySelector('.is-invalid');
          if (firstInvalid) {
            firstInvalid.focus();
          }
        }
      })
      .catch(function (err) {
        form.dataset.submitting = '0';
        setSubmitBusy(form, false);
        window.showToast(err.message || 'Something went wrong. Please try again.', 'danger');
      });
  });
})();
