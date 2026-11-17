<?php
/** The question of one Loan Questions card and the form that answers it. Needs $f, $q, $instalments, $flow, $post, $asked, $termAsked; $returnTo (optional) sends the person back to the page they were on. */
?>
<p class="fw-bold mb-2"><?= e($q['text']) ?></p>

<?php if (in_array($q['key'], ['approved', 'plans', 'disbursed'], true)): ?>
  <form method="post" data-no-submit-guard action="<?= $post ?>" class="d-flex gap-2 flex-wrap">
    <?= csrf_field() ?><?php if (!empty($returnTo)): ?><input type="hidden" name="return" value="<?= e($returnTo) ?>"><?php endif; ?>
    <input type="hidden" name="question" value="<?= e($q['key']) ?>">
    <button type="submit" name="answer" value="Yes" class="btn btn-success">Yes</button>
    <button type="submit" name="answer" value="No" class="btn btn-outline-danger">No</button>
    <?php if ($q['key'] !== 'disbursed'): ?><button type="submit" name="answer" value="Not sure" class="btn btn-outline-secondary">Not sure</button><?php endif; ?>
  </form>

<?php elseif ($q['key'] === 'amount'): ?>
  <form method="post" data-no-submit-guard action="<?= $post ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?><?php if (!empty($returnTo)): ?><input type="hidden" name="return" value="<?= e($returnTo) ?>"><?php endif; ?>
    <input type="hidden" name="question" value="amount">
    <div class="col-md-3"><label class="form-label small mb-0">Amount approved (N$)</label>
      <input type="text" inputmode="decimal" name="amount" class="form-control" value="<?= e((string) (int) $asked) ?>" required></div>
    <div class="col-md-2"><label class="form-label small mb-0">Months</label>
      <select name="term" class="form-select">
        <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $termAsked ? 'selected' : '' ?>><?= $m ?></option><?php endfor; ?>
      </select></div>
    <div class="col-md-3"><button type="submit" class="btn btn-primary">Save</button></div>
  </form>

<?php elseif ($q['key'] === 'payout'): ?>
  <form method="post" data-no-submit-guard action="<?= $post ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?><?php if (!empty($returnTo)): ?><input type="hidden" name="return" value="<?= e($returnTo) ?>"><?php endif; ?>
    <input type="hidden" name="question" value="payout">
    <div class="col-md-3"><label class="form-label small mb-0">Date paid out</label>
      <input type="date" name="paid_out" class="form-control" max="<?= e(date('Y-m-d')) ?>" required></div>
    <div class="col-md-3"><label class="form-label small mb-0">Client's pay day (day of the month)</label>
      <input type="number" name="pay_day" class="form-control" min="1" max="31" value="<?= e((string) ($f['application_pay_day'] ?? '')) ?>" required></div>
    <div class="col-md-3"><button type="submit" class="btn btn-primary">Save</button></div>
  </form>

<?php elseif ($q['key'] === 'instalment'): ?>
  <?php $inst = null; foreach ($instalments as $x) { if ((int) $x['id'] === (int) $q['instalment_id']) { $inst = $x; } } ?>
  <form method="post" data-no-submit-guard action="<?= url('/loans/followups/' . $f['id'] . '/instalments/' . $q['instalment_id']) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?><?php if (!empty($returnTo)): ?><input type="hidden" name="return" value="<?= e($returnTo) ?>"><?php endif; ?>
    <div class="col-md-2"><label class="form-label small mb-0">Did they pay?</label>
      <select name="answer" class="form-select" required>
        <option value="Yes">Yes, in full</option><option value="Partly">Partly</option><option value="No">No</option>
      </select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Amount received this time (N$) &mdash; leave empty if the whole instalment</label>
      <input type="text" inputmode="decimal" name="amount" class="form-control" value=""></div>
    <div class="col-md-3"><label class="form-label small mb-0">Date it was received</label>
      <input type="date" name="paid_on" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
    <div class="col-md-2"><button type="submit" class="btn btn-primary">Save</button></div>
  </form>
  <?php if ($inst): ?><div class="text-muted small mt-2">Answer by <?= e(date('d M Y', strtotime($flow->answerByDate($inst['due_date'])))) ?>.</div><?php endif; ?>
<?php endif; ?>
