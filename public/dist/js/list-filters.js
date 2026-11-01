/**
 * AJAX filtering for list screens. A page opts in with <form data-list-filter> (the shared filter bar) and a <div data-list-results> around
 * everything that should refresh. Changing a field reloads only that region, keeps the address bar in step (so refresh, back and shared
 * links keep the filter), and keeps the Download CSV link pointing at the current filter. Without JavaScript the form is a normal GET form.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-list-filter]');
  var results = document.querySelector('[data-list-results]');
  if (!form || !results || !window.fetch || !window.URLSearchParams) {
    return;
  }

  var exportLink = form.querySelector('[data-list-export]');
  var clearLink = form.querySelector('[data-list-clear]');
  var submitButton = form.querySelector('[data-list-submit]');
  var path = form.getAttribute('action') || window.location.pathname;
  var pending = null;
  var timer = null;

  // The Filter button is only a fallback for no-JS; with JS the list follows the fields.
  if (submitButton) {
    submitButton.classList.add('d-none');
  }

  function currentParams() {
    var params = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      if (typeof value === 'string' && value.trim() !== '') {
        params.append(key, value.trim());
      }
    });
    return params;
  }

  function withQuery(params) {
    var query = params.toString();
    return path + (query ? '?' + query : '');
  }

  function syncLinks(params) {
    if (clearLink) {
      clearLink.hidden = params.toString() === '';
    }
    if (exportLink) {
      var exportParams = new URLSearchParams(params.toString());
      exportParams.delete('page');
      exportParams.set('export', 'csv');
      exportLink.setAttribute('href', path + '?' + exportParams.toString());
    }
  }

  function afterSwap() {
    if (typeof window.feather !== 'undefined') {
      window.feather.replace();
    }
    if (typeof window.initDataTables === 'function') {
      window.initDataTables();
    }
    if (typeof window.initSelect2 === 'function') {
      window.initSelect2();
    }
    document.dispatchEvent(new CustomEvent('list:updated', { detail: { container: results } }));
  }

  function load(url) {
    if (pending) {
      pending.abort();
    }
    pending = new AbortController();
    results.style.opacity = '.55';
    results.setAttribute('aria-busy', 'true');

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: pending.signal })
      .then(function (response) {
        if (response.redirected && /\/login/.test(response.url)) {
          window.location.href = url; // session ended: let the normal page handle it
          return null;
        }
        if (!response.ok) {
          throw new Error('status ' + response.status);
        }
        return response.text();
      })
      .then(function (html) {
        if (html === null) {
          return;
        }
        var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-list-results]');
        if (!fresh) {
          window.location.href = url;
          return;
        }
        results.innerHTML = fresh.innerHTML;
        window.history.replaceState(null, '', url);
        afterSwap();
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        window.location.href = url; // anything unexpected: fall back to a normal page load
      })
      .then(function () {
        results.style.opacity = '';
        results.removeAttribute('aria-busy');
      });
  }

  function refresh() {
    var params = currentParams();
    syncLinks(params);
    load(withQuery(params));
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    window.clearTimeout(timer);
    refresh();
  });

  // typing waits a moment; picking a date, status, branch or branch-like select applies straight away
  form.addEventListener('input', function (event) {
    var type = event.target.type;
    if (type === 'search' || type === 'text' || type === 'number') {
      window.clearTimeout(timer);
      timer = window.setTimeout(refresh, 400);
    }
  });
  form.addEventListener('change', function (event) {
    if (event.target.type === 'search' || event.target.type === 'text' || event.target.type === 'number') {
      return; // handled by the debounced input listener
    }
    window.clearTimeout(timer);
    refresh();
  });

  if (clearLink) {
    clearLink.addEventListener('click', function (event) {
      event.preventDefault();
      form.querySelectorAll('input:not([type="hidden"])').forEach(function (input) { input.value = ''; });
      form.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
      refresh();
    });
  }

  // page links inside the refreshed region stay in the region
  results.addEventListener('click', function (event) {
    var link = event.target.closest('a.page-link, a[data-list-page]');
    if (!link || !link.getAttribute('href') || link.getAttribute('href').charAt(0) === '#') {
      return;
    }
    event.preventDefault();
    load(link.getAttribute('href'));
  });

  syncLinks(currentParams());
})();
