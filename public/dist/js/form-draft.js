/**
 * Autosave/draft-recovery for long forms, opt-in via
 * data-draft-workflow="module.workflow" on the <form> (e.g.
 * "borrowers.create"). Not a global default like submit-guard.js --
 * autosave needs per-form judgment (which forms are long enough to matter,
 * what "resumable" means for their specific fields), so it's only wired up
 * on the two forms confirmed to need it (Add Borrower, Agent Referral).
 *
 * Storage is hybrid: every change is written to localStorage immediately
 * (survives a refresh/crash/offline gap on its own), and synced to the
 * server on a debounce + a 30s backstop timer whenever the browser is
 * online (see FormDraftController::save()) -- the server copy is what
 * lets the user resume on a different device/browser, or after clearing
 * local storage. The localStorage copy is cleared once a server sync
 * succeeds, so it's never the sole lingering copy of the data once
 * connectivity returns.
 */
(function () {
  'use strict';

  // Every other script in this app receives its URLs pre-built server-side
  // via url()/asset() (e.g. data-modal-url="<?= url(...) ?>"); this module
  // is the first to need to construct its own from scratch client-side, so
  // it needs the same base path (e.g. "/micro-lending-system-pro" locally,
  // "" in production at the domain root) that url() applies server-side --
  // exposed once as window.APP_BASE_URL by layouts/main.php.
  function appUrl(path) {
    var base = (window.APP_BASE_URL || '/').replace(/\/$/, '');
    return base + '/' + String(path).replace(/^\//, '');
  }

  function debounce(fn, wait) {
    var timer;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  function csrfToken(form) {
    var input = form.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  }

  function serializeForm(form) {
    var data = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.type === 'file' || el.name === '_csrf' || el.name === '_idempotency_key') {
        return;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) {
          data[el.name] = el.value;
        }
      } else if (el.tagName === 'SELECT' && el.multiple) {
        data[el.name] = Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
      } else {
        data[el.name] = el.value;
      }
    });
    return data;
  }

  function restoreForm(form, data) {
    Object.keys(data || {}).forEach(function (name) {
      var els = form.querySelectorAll('[name="' + name + '"]');
      if (!els.length) {
        return;
      }
      var value = data[name];
      els.forEach(function (el) {
        if (el.type === 'checkbox' || el.type === 'radio') {
          el.checked = Array.isArray(value) ? value.indexOf(el.value) !== -1 : el.value === value;
        } else if (el.type === 'file') {
          return; // browsers never allow programmatically restoring a file input's value
        } else {
          el.value = value;
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  }

  function currentStep(form) {
    var active = form.querySelector('.tab-pane.active');
    return active ? active.id : '';
  }

  function ensureStatusEl(form) {
    var el = form.querySelector('.js-draft-status');
    if (!el) {
      el = document.createElement('div');
      el.className = 'js-draft-status text-muted small mb-2';
      form.insertBefore(el, form.firstChild);
    }
    return el;
  }

  function setStatus(form, text) {
    ensureStatusEl(form).textContent = text;
  }

  function ensureDocumentsList(form) {
    var list = form.querySelector('.js-draft-documents');
    if (!list) {
      list = document.createElement('ul');
      list.className = 'js-draft-documents list-unstyled small text-muted mb-2';
      ensureStatusEl(form).insertAdjacentElement('afterend', list);
    }
    return list;
  }

  function renderDocuments(form, documents) {
    var list = ensureDocumentsList(form);
    list.innerHTML = '';
    (documents || []).forEach(function (doc) {
      var li = document.createElement('li');
      li.textContent = 'Already uploaded: ' + doc.original_name + ' (' + doc.field_name + ')';
      list.appendChild(li);
    });
  }

  function showRecoveryPrompt(form, draft, workflowKey) {
    var bar = document.createElement('div');
    bar.className = 'alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 js-draft-recovery';
    var when = draft.last_autosaved_at || draft.created_at || '';
    bar.innerHTML =
      '<span>You have an unfinished draft from ' + when + '.</span>' +
      '<span class="d-flex gap-2">' +
        '<button type="button" class="btn btn-sm btn-info js-draft-continue">Continue</button>' +
        '<a href="/my/drafts" class="btn btn-sm btn-outline-secondary">Review</a>' +
        '<button type="button" class="btn btn-sm btn-outline-danger js-draft-discard">Discard</button>' +
      '</span>';
    form.parentNode.insertBefore(bar, form);

    bar.querySelector('.js-draft-continue').addEventListener('click', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('draft', draft.uuid);
      window.location.href = url.toString();
    });
    bar.querySelector('.js-draft-discard').addEventListener('click', function () {
      var body = new URLSearchParams();
      body.set('_csrf', csrfToken(form));
      fetch(appUrl('/my/drafts/' + encodeURIComponent(draft.uuid) + '/discard'), {
        method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then(function () { bar.remove(); });
    });
  }

  function stageFileUploads(form, uuid) {
    form.querySelectorAll('input[type="file"][name]').forEach(function (input) {
      input.addEventListener('change', function () {
        if (!input.files || !input.files.length) {
          return;
        }
        Array.prototype.forEach.call(input.files, function (file) {
          var body = new FormData();
          body.append('_csrf', csrfToken(form));
          body.append('field_name', input.name);
          body.append('file', file);
          fetch(appUrl('/my/drafts/' + encodeURIComponent(uuid) + '/documents'), {
            method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' },
          }).then(function (res) { return res.json(); }).then(function (json) {
            if (json && json.success) {
              var list = ensureDocumentsList(form);
              var li = document.createElement('li');
              li.textContent = 'Uploaded: ' + json.document.original_name + ' (' + json.document.field_name + ')';
              list.appendChild(li);
            }
          });
        });
      });
    });
  }

  function initDraftForm(form) {
    var workflowKey = form.getAttribute('data-draft-workflow');
    if (!workflowKey) {
      return;
    }
    var module = form.getAttribute('data-draft-module') || workflowKey.split('.')[0];
    var params = new URLSearchParams(window.location.search);
    var resumeUuid = params.get('draft');
    var uuid = resumeUuid || ((window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'd-' + Date.now() + '-' + Math.random().toString(16).slice(2));
    var storageKey = 'draft:' + workflowKey + ':' + uuid;
    var syncing = false;
    var pendingResync = false;
    var everSynced = false;

    stageFileUploads(form, uuid);

    function localSave() {
      try {
        localStorage.setItem(storageKey, JSON.stringify({ data: serializeForm(form), savedAt: Date.now() }));
      } catch (e) { /* storage unavailable/full -- server sync below is still attempted */ }
    }

    function serverSync() {
      if (!navigator.onLine) {
        setStatus(form, "You're offline — changes are saved locally and will sync when connection returns.");
        return;
      }
      if (syncing) {
        pendingResync = true;
        return;
      }
      syncing = true;
      setStatus(form, 'Saving...');

      var body = new URLSearchParams();
      body.set('_csrf', csrfToken(form));
      body.set('draft_uuid', uuid);
      body.set('module', module);
      body.set('workflow_key', workflowKey);
      body.set('form_data', JSON.stringify(serializeForm(form)));
      body.set('current_step', currentStep(form));

      fetch(appUrl('/my/drafts'), { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          syncing = false;
          if (json && json.success) {
            everSynced = true;
            try { localStorage.removeItem(storageKey); } catch (e) { /* ignore */ }
            setStatus(form, 'Draft saved at ' + (json.saved_at || ''));
          } else {
            setStatus(form, "Draft not yet synced. We'll retry automatically.");
          }
          if (pendingResync) {
            pendingResync = false;
            serverSync();
          }
        })
        .catch(function () {
          syncing = false;
          setStatus(form, "Draft not yet synced. We'll retry automatically.");
        });
    }

    var debouncedSync = debounce(function () { localSave(); serverSync(); }, 800);

    form.addEventListener('input', function (e) {
      if (e.target && e.target.type === 'file') {
        return; // handled separately by stageFileUploads
      }
      localSave();
      debouncedSync();
    });
    form.addEventListener('change', function (e) {
      if (e.target && e.target.type === 'file') {
        return;
      }
      localSave();
      debouncedSync();
    });

    setInterval(function () {
      var stored = null;
      try { stored = localStorage.getItem(storageKey); } catch (e) { /* ignore */ }
      if (stored) {
        serverSync();
      }
    }, 30000);

    window.addEventListener('online', function () { serverSync(); });
    window.addEventListener('offline', function () {
      setStatus(form, "You're offline — changes are saved locally and will sync when connection returns.");
    });

    // Warn before an accidental navigation/close if something hasn't
    // synced yet -- avoided entirely once everything is confirmed saved.
    window.addEventListener('beforeunload', function (e) {
      var stored = null;
      try { stored = localStorage.getItem(storageKey); } catch (err) { /* ignore */ }
      if (stored && !everSynced) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    // Clear the local/server draft once the form actually submits for real
    // -- a successful submit is not an abandoned draft.
    form.addEventListener('submit', function () {
      try { localStorage.removeItem(storageKey); } catch (e) { /* ignore */ }
      var body = new URLSearchParams();
      body.set('_csrf', csrfToken(form));
      // Best-effort, fire-and-forget -- the real record is what matters now.
      navigator.sendBeacon && navigator.sendBeacon(appUrl('/my/drafts/' + encodeURIComponent(uuid) + '/discard'), body);
    });

    if (resumeUuid) {
      fetch(appUrl('/my/drafts/' + encodeURIComponent(resumeUuid)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success && json.draft) {
            restoreForm(form, json.draft.form_data || {});
            renderDocuments(form, json.documents || []);
            setStatus(form, 'Draft restored from ' + (json.draft.last_autosaved_at || ''));
          }
        });
      return;
    }

    fetch(appUrl('/my/drafts/latest?workflow_key=' + encodeURIComponent(workflowKey)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json && json.success && json.draft) {
          showRecoveryPrompt(form, json.draft, workflowKey);
        }
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-draft-workflow]').forEach(initDraftForm);
  });
})();
