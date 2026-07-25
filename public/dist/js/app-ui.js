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
})();
