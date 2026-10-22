<?php
/**
 * Shared by the payment screen ($mode = 'payment'), the loan page and the debit order page ($mode = 'notice').
 * Expects $collectionCheck (MandateCollectionCheck::forLoan) and $loan. Prints nothing when there is no live mandate.
 */
(function ($collectionCheck, $loan, $mode) {
$cc = $collectionCheck ?? null;
if (!$cc || empty($cc['mandate'])) {
    return;
}
$mandate = $cc['mandate'];
$canClick = !empty($cc['canStop']) && \App\Core\Auth::can('collections.debit_orders');
$fmtDate = fn ($d) => date('d M Y', strtotime($d));
$when = function (string $d): string {
    $today = date('Y-m-d');
    return $d <= $today ? 'today' : ($d <= date('Y-m-d', strtotime('+1 day')) ? 'tomorrow' : date('d M Y', strtotime($d)));
};
$stopUrl = url('/loans/' . (int) $loan['id'] . '/collections/stop');
$hasProblem = !empty($cc['paidButScheduled']) || !empty($cc['surplus']);

if ($mode === 'notice' && !$hasProblem) {
    return;
}
?>
<?php if ($mode === 'payment'): ?>
  <?php $imminent = array_filter($cc['upcoming'], fn ($u) => !empty($u['imminent'])); ?>
  <div class="alert alert-<?= $imminent ? 'danger' : 'warning' ?>">
    <i class="mdi mdi-alert-outline"></i>
    <strong>This loan has a live debit order (<?= e($mandate['debit_order_no']) ?>).</strong>
    Collexia will still collect:
    <ul class="mb-1 mt-1">
      <?php foreach (array_slice($cc['upcoming'], 0, 4) as $u): ?>
        <li>
          <?= format_money($u['amount']) ?> on <strong><?= e($when($u['collects_on'])) ?></strong>
          <?php if ($u['installment_no']): ?>for installment #<?= (int) $u['installment_no'] ?> (mandate <?= (int) $u['mandate'] ?>)<?php else: ?>(recurring mandate)<?php endif; ?>
          <?php if (!empty($u['paid'])): ?><span class="badge bg-danger">already paid</span><?php endif; ?>
          <?php if (!empty($u['imminent'])): ?><span class="badge bg-danger">can no longer be stopped</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$cc['upcoming']): ?><li class="text-muted">no upcoming collections are recorded</li><?php endif; ?>
    </ul>
    <?php if ($imminent): ?>
      <div class="fw-semibold">A collection is due today or tomorrow and can no longer be stopped. If this payment clears that instalment, the borrower will be debited as well &mdash; the extra amount would have to be refunded as an overpayment.</div>
    <?php endif; ?>
    <div class="small">A payment made here does not change the mandate. If it clears an instalment that is still scheduled, you can stop that collection from the loan page straight after recording it.</div>
  </div>
<?php else: ?>
  <div class="alert alert-danger">
    <div class="mb-1"><i class="mdi mdi-alert-octagon-outline"></i> <strong>Paid, but Collexia is still set to collect it.</strong></div>

    <?php foreach ($cc['paidButScheduled'] as $p): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-top pt-1 mt-1">
        <div>
          Installment <strong>#<?= (int) $p['installment_no'] ?></strong> is already paid, but mandate <?= (int) $p['mandate'] ?> will still collect
          <strong><?= format_money($p['amount']) ?></strong> on <strong><?= e($when($p['collects_on'])) ?></strong>.
          <?php if (!empty($p['imminent'])): ?><span class="fw-semibold">Due today or tomorrow &mdash; this can no longer be stopped. If the borrower is debited, refund the overpayment.</span><?php endif; ?>
        </div>
        <?php if (empty($p['imminent']) && $canClick): ?>
          <form method="post" action="<?= $stopUrl ?>" onsubmit="return confirmSubmit(this, 'Stop the <?= e($fmtDate($p['collects_on'])) ?> collection of <?= e(number_format($p['amount'], 2)) ?> for installment #<?= (int) $p['installment_no'] ?> at Collexia? It will not be collected.');">
            <?= csrf_field() ?>
            <input type="hidden" name="schedule_id" value="<?= (int) $p['schedule_id'] ?>">
            <button class="btn btn-sm btn-danger"><i class="mdi mdi-cancel"></i> Stop this collection</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($cc['surplus'])): ?>
      <?php $s = $cc['surplus']; ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-top pt-1 mt-1">
        <div>
          The recurring mandate <?= e($mandate['debit_order_no']) ?> still has more collections scheduled than the loan needs: it will over-collect about
          <strong><?= format_money($s['amount']) ?></strong> (<?= (int) $s['count'] ?> &times; <?= format_money($s['per']) ?>), the last ones being
          <?= e(implode(', ', array_map($fmtDate, $s['dates']))) ?>.
          <?php if ($s['stoppable'] < $s['count']): ?><span class="fw-semibold"><?= (int) ($s['count'] - $s['stoppable']) ?> of them are due today or tomorrow and can no longer be stopped.</span><?php endif; ?>
        </div>
        <?php if ($s['stoppable'] > 0 && $canClick): ?>
          <form method="post" action="<?= $stopUrl ?>" onsubmit="return confirmSubmit(this, 'Cancel the last <?= (int) $s['stoppable'] ?> scheduled collection(s) of this recurring mandate at Collexia? Collexia is asked again at that moment, and only collections the loan no longer needs are cancelled.');">
            <?= csrf_field() ?>
            <input type="hidden" name="surplus" value="1">
            <button class="btn btn-sm btn-danger"><i class="mdi mdi-cancel"></i> Cancel the last <?= (int) $s['stoppable'] ?> collection<?= $s['stoppable'] === 1 ? '' : 's' ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$canClick): ?>
      <div class="small mt-2">This mandate cannot be stopped from here (it was not placed through the Collexia API, or you do not have access) &mdash; ask Collexia to cancel it.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php })($collectionCheck ?? null, $loan, $mode ?? 'notice'); ?>
