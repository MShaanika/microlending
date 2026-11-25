<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
  <style>
    body { padding: 30px; }
    .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
    .invoice-header h2 { margin-bottom: 4px; }
    table.invoice-table th, table.invoice-table td { padding: 6px 10px; }
    @media print {
      .no-print { display: none !important; }
      body { padding: 0; }
    }
  </style>
</head>
<body>

<div class="no-print mb-3">
  <?= flash_messages() ?>
  <button class="btn btn-info" onclick="window.print()"><i class="mdi mdi-printer"></i> Print</button>
  <a href="<?= url('/loans/' . $loan['id'] . '/statement.pdf') ?>" class="btn btn-outline-danger"><i class="mdi mdi-file-pdf-box"></i> Download PDF</a>
  <a href="<?= url('/loans/' . $loan['id'] . '/statement.xlsx') ?>" class="btn btn-outline-success"><i class="mdi mdi-file-excel"></i> Download Excel</a>
  <a href="<?= url('/loans/' . $loan['id']) ?>" class="btn btn-outline-secondary">Back to Loan</a>

  <form method="post" action="<?= url('/loans/' . $loan['id'] . '/statement/email') ?>" class="d-inline-flex align-items-center gap-2 ms-2">
    <?= csrf_field() ?>
    <input type="email" name="recipient_email" class="form-control form-control-sm" style="width: 260px; display: inline-block;" placeholder="Send to email address" required>
    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="mdi mdi-email-send"></i> Email Statement</button>
  </form>
  <?php if (!empty($summary['problems'])): ?>
    <div class="alert alert-danger mt-3 mb-0">
      <strong>These figures do not agree (staff only, not printed):</strong>
      <ul class="mb-0"><?php foreach ($summary['problems'] as $problem): ?><li><?= e($problem) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
</div>

<div class="invoice-header">
  <div>
    <h2><?= e($company['company_name'] ?? 'Micro Lending System') ?></h2>
    <p class="mb-0 text-muted"><?= e($company['address'] ?? '') ?></p>
    <p class="mb-0 text-muted"><?= e($company['email'] ?? '') ?> <?= $company['phone'] ? '&middot; ' . e($company['phone']) : '' ?></p>
    <p class="mb-0 text-muted">Reg No: <?= e($company['registration_no'] ?? '') ?></p>
  </div>
  <div class="text-end">
    <h3>STATEMENT OF ACCOUNT</h3>
    <p class="mb-0">Loan No: <strong><?= e($loan['loan_no']) ?></strong></p>
    <p class="mb-0">Date: <?= e(date('d M Y')) ?></p>
  </div>
</div>

<div class="row mb-4">
  <div class="col-6">
    <h5>Client</h5>
    <p class="mb-0"><?= e($borrower['first_name'] . ' ' . $borrower['last_name']) ?></p>
    <p class="mb-0"><?= e($borrower['borrower_no']) ?></p>
    <p class="mb-0"><?= e($borrower['phone'] ?: '') ?></p>
    <p class="mb-0"><?= e($borrower['physical_address'] ?: '') ?></p>
  </div>
  <div class="col-6 text-end">
    <h5>Loan Summary</h5>
    <p class="mb-0">Product: <?= e($loan['product_name']) ?></p>
    <?php $s = $summary ?? \App\Services\LoanStatementSummary::build($loan, $schedule, $ledger); ?>
    <p class="mb-0"><?= e($s['principal_label']) ?>: <?= format_money($s['principal']) ?></p>
    <?php if ($s['levy'] > 0): ?><p class="mb-0"><?= e($s['levy_label']) ?>: <?= format_money($s['levy']) ?></p><?php endif; ?>
    <?php if ($s['stamp'] > 0): ?><p class="mb-0"><?= e($s['stamp_label']) ?>: <?= format_money($s['stamp']) ?></p><?php endif; ?>
    <p class="mb-0"><?= e($s['opening_label']) ?>: <?= format_money($s['opening_balance']) ?></p>
    <p class="mb-0"><?= e($s['interest_label']) ?>: <?= format_money($s['interest']) ?></p>
    <?php if ($s['admin_fee'] > 0): ?><p class="mb-0">+ Admin fee: <?= format_money($s['admin_fee']) ?></p><?php endif; ?>
    <p class="mb-0">Total payable: <?= format_money($s['total_payable']) ?></p>
    <?php if ($s['penalties'] > 0): ?><p class="mb-0">Penalties charged: <?= format_money($s['penalties']) ?></p><?php endif; ?>
    <p class="mb-0">Paid: <?= format_money($s['paid']) ?></p>
    <p class="mb-0"><strong>Still to pay: <?= format_money($s['outstanding']) ?></strong><?= $s['paid_out'] ? '' : ' (not paid out yet)' ?></p>
    <?php if ($s['disbursed_on']): ?><p class="mb-0">Paid out on: <?= e($s['disbursed_on']) ?></p><?php endif; ?>
    <p class="mb-0">Status: <?= e($loan['loan_status']) ?></p>
  </div>
</div>

<?php
  $colSum = fn (string $col) => array_sum(array_map('floatval', array_column($schedule, $col)));
?>

<h4>Amortization Schedule</h4>
<table class="table table-bordered invoice-table">
  <thead class="table-light">
    <tr><th>#</th><th>Due Date</th><th>Opening Principal</th><th>Principal</th><th>Interest</th><th>Penalty Interest</th><th>Fees</th><th>Total Due</th><th>Paid</th><th>Closing Principal</th><th>Status</th></tr>
  </thead>
  <tbody>
    <?php foreach ($schedule as $row): ?>
      <tr>
        <td><?= (int) $row['installment_no'] ?></td>
        <td><?= e($row['due_date']) ?></td>
        <td><?= format_money($row['opening_balance']) ?></td>
        <td><?= format_money($row['principal_due']) ?></td>
        <td><?= format_money($row['interest_due']) ?></td>
        <td><?= format_money($row['penalty_due']) ?></td>
        <td><?= format_money($row['fees_due']) ?></td>
        <td><?= format_money($row['total_due']) ?></td>
        <td><?= format_money($row['total_paid']) ?></td>
        <td><?= format_money($row['closing_balance']) ?></td>
        <td><?= e(schedule_status_label($row)) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="3" class="text-end">Total</td>
      <td><?= format_money($colSum('principal_due')) ?></td>
      <td><?= format_money($colSum('interest_due')) ?></td>
      <td><?= format_money($colSum('penalty_due')) ?></td>
      <td><?= format_money($colSum('fees_due')) ?></td>
      <td><?= format_money($s['schedule_due']) ?></td>
      <td><?= format_money($s['paid']) ?></td>
      <td></td>
      <td></td>
    </tr>
    <tr class="fw-bold">
      <td colspan="11" class="text-end"><?= e(\App\Services\LoanStatementSummary::stillToPayLine($s)) ?></td>
    </tr>
  </tfoot>
</table>
<p class="text-muted small mt-2"><?= e(\App\Services\LoanStatementSummary::scheduleNote($s)) ?></p>

<h4 class="mt-4">Loan Statement (Transaction History)</h4>
<?php if (!empty($ledger['notice'])): ?>
  <p class="alert alert-warning small mb-2"><?= e($ledger['notice']) ?></p>
<?php endif; ?>
<table class="table table-bordered invoice-table">
  <thead class="table-light">
    <tr><th>Date</th><th>Type</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr>
  </thead>
  <tbody>
    <?php foreach ($ledger['events'] as $event): ?>
      <tr>
        <td><?= e($event['date'] ?: '-') ?></td>
        <td><?= e($event['type']) ?></td>
        <td><?= e($event['description']) ?></td>
        <td class="text-end"><?= $event['debit'] > 0 ? format_money($event['debit']) : '' ?></td>
        <td class="text-end"><?= $event['credit'] > 0 ? format_money($event['credit']) : '' ?></td>
        <td class="text-end"><?= format_money($event['balance']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="5" class="text-end">Closing Balance</td>
      <td class="text-end"><?= format_money($ledger['closing_balance']) ?></td>
    </tr>
  </tfoot>
</table>

<p class="text-muted small mt-4">This is a system-generated statement and does not require a signature.</p>

</body>
</html>
