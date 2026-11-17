<?php
/**
 * The Loan Questions pop-up: on every page, for whoever answers the Loan Questions, so a waiting question is never missed because nobody opened a certain page.
 * It shows the first question that is due, with the same form as the Loan Questions page; after an answer the person lands back on the page they were on and the
 * next question (if any) pops up. "Remind me later" hides it for 30 minutes in this browser; then it comes back on the next page that is opened.
 * Not shown on the Loan Questions pages themselves, while somebody is logged in as another user, or when nothing is due.
 */
$lqUser = \App\Core\Auth::check() ? \App\Core\Auth::user() : null;
$lqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
if ($lqUser && !\App\Core\Auth::isImpersonating() && \App\Core\Auth::can('loans.followups') && !str_contains($lqPath, '/loans/followups')) {
    $lqPopup = (new \App\Services\LoanFollowupAlerts())->popup(date('Y-m-d H:i:s'));
    if ($lqPopup) {
        $c = $lqPopup['card'];
        $f = $c['f'];
        $q = $c['q'];
        $instalments = $c['instalments'];
        $flow = new \App\Services\LoanFollowupFlow();
        $post = url('/loans/followups/' . $f['id'] . '/answer');
        $asked = (float) ($f['requested_amount'] ?? 0);
        $termAsked = (int) ($f['requested_term_months'] ?? 1);
        // only a page that was opened (GET) can be returned to; after a form was posted the page has no address of its own
        $returnTo = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? (string) ($_SERVER['REQUEST_URI'] ?? '/') : '';
        $lqName = trim($f['applicant_first_name'] . ' ' . ($f['applicant_middle_name'] ?? '') . ' ' . $f['applicant_last_name']);
        ?>
<div class="modal fade" id="loanQuestionModal" tabindex="-1" aria-labelledby="loanQuestionTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="loanQuestionTitle">Loan question waiting<?= $lqPopup['count'] > 1 ? ' (1 of ' . (int) $lqPopup['count'] . ')' : '' ?></h5>
      </div>
      <div class="modal-body">
        <h6 class="mb-0"><?= e($lqName) ?> <span class="text-muted small">&mdash; <?= e($f['application_no']) ?></span></h6>
        <div class="text-muted small mb-2">
          Applied <?= e(date('d M Y', strtotime($f['applied_at']))) ?> for N$ <?= number_format($asked, 2, '.', ' ') ?> over <?= $termAsked ?> month(s)
          <?php if ($f['approved_amount'] !== null): ?> &middot; approved N$ <?= number_format((float) $f['approved_amount'], 2, '.', ' ') ?><?php endif; ?>
        </div>
        <?php if ($q['attention']): ?><div class="alert alert-danger py-1 small">Asked <?= (int) $f['ask_count'] ?> times &mdash; please settle it.</div><?php endif; ?>
        <?php require VIEW_PATH . '/loans/followups/_question.php'; ?>
      </div>
      <div class="modal-footer justify-content-between">
        <a href="<?= url('/loans/followups') ?>" class="btn btn-link">Open all Loan Questions</a>
        <button type="button" class="btn btn-outline-secondary" id="loanQuestionLater">Remind me in 30 minutes</button>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    var key = 'loanQuestionSnooze_<?= (int) $lqUser['id'] ?>';
    var until = 0;
    try { until = parseInt(window.localStorage.getItem(key) || '0', 10) || 0; } catch (e) { until = 0; }
    var el = document.getElementById('loanQuestionModal');
    if (!el || Date.now() < until) { return; }
    window.addEventListener('load', function () {
      var modal = new bootstrap.Modal(el);
      document.getElementById('loanQuestionLater').addEventListener('click', function () {
        try { window.localStorage.setItem(key, String(Date.now() + 30 * 60 * 1000)); } catch (e) { /* no storage: it simply comes back on the next page */ }
        modal.hide();
      });
      modal.show();
    });
  })();
</script>
        <?php
    }
}
