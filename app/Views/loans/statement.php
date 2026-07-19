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
  <button class="btn btn-info" onclick="window.print()"><i class="mdi mdi-printer"></i> Print / Save as PDF</button>
  <a href="<?= url('/loans/' . $loan['id'] . '/statement.xlsx') ?>" class="btn btn-outline-success"><i class="mdi mdi-file-excel"></i> Download Excel</a>
  <a href="<?= url('/loans/' . $loan['id']) ?>" class="btn btn-outline-secondary">Back to Loan</a>
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
    <h5>Borrower</h5>
    <p class="mb-0"><?= e($borrower['first_name'] . ' ' . $borrower['last_name']) ?></p>
    <p class="mb-0"><?= e($borrower['borrower_no']) ?></p>
    <p class="mb-0"><?= e($borrower['phone'] ?: '') ?></p>
    <p class="mb-0"><?= e($borrower['physical_address'] ?: '') ?></p>
  </div>
  <div class="col-6 text-end">
    <h5>Loan Summary</h5>
    <p class="mb-0">Product: <?= e($loan['product_name']) ?></p>
    <p class="mb-0">Principal: <?= format_money($loan['principal_amount']) ?></p>
    <p class="mb-0">Total Payable: <?= format_money($loan['total_payable']) ?></p>
    <p class="mb-0">Status: <?= e($loan['loan_status']) ?></p>
  </div>
</div>

<?php
  $totalPaid = array_sum(array_column($schedule, 'total_paid'));
  $totalDue = array_sum(array_column($schedule, 'total_due'));
  $balance = $totalDue - $totalPaid;
?>

<h4>Amortization Schedule</h4>
<table class="table table-bordered invoice-table">
  <thead class="table-light">
    <tr><th>#</th><th>Due Date</th><th>Principal</th><th>Interest</th><th>Total Due</th><th>Paid</th><th>Balance</th></tr>
  </thead>
  <tbody>
    <?php foreach ($schedule as $row): ?>
      <tr>
        <td><?= (int) $row['installment_no'] ?></td>
        <td><?= e($row['due_date']) ?></td>
        <td><?= format_money($row['principal_due']) ?></td>
        <td><?= format_money($row['interest_due']) ?></td>
        <td><?= format_money($row['total_due']) ?></td>
        <td><?= format_money($row['total_paid']) ?></td>
        <td><?= format_money($row['total_due'] - $row['total_paid']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td colspan="4" class="text-end">Total</td>
      <td><?= format_money($totalDue) ?></td>
      <td><?= format_money($totalPaid) ?></td>
      <td><?= format_money($balance) ?></td>
    </tr>
  </tfoot>
</table>
<p class="text-muted small mt-2">NAMFISA Levy and Duty Stamp are statutory charges remitted to the relevant Namibian authorities and are included in your total repayable amount.</p>

<h4 class="mt-4">Loan Statement (Transaction History)</h4>
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
