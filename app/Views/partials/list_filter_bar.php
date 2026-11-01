<?php
/**
 * The shared filter bar of a list screen. Fields appear only when the list gives them:
 *   $bar = [
 *     'action' => url('/loans'),
 *     'search' => ['value' => '', 'placeholder' => 'Search ...'],
 *     'status' => ['value' => '', 'options' => ['Active', ...], 'all' => 'All Statuses'],
 *     'branches' => [...rows with id + branch_name...], 'branch' => selected id or null,
 *     'date' => ['label' => 'Received', 'from' => '', 'to' => ''],
 *     'amount' => ['label' => 'Amount', 'min' => '', 'max' => ''],
 *     'selects' => [['name' => 'category_id', 'label' => 'Category', 'all' => 'All Categories', 'value' => '', 'options' => [id => label]]],
 *     'export' => true,
 *   ];
 * The form is enhanced by dist/js/list-filters.js (the table below it refreshes without reloading the page); without JavaScript it is an
 * ordinary GET form with a Filter button.
 */
$bar = $bar ?? [];
$search = $bar['search'] ?? null;
$statusF = $bar['status'] ?? null;
$dateF = $bar['date'] ?? null;
$amountF = $bar['amount'] ?? null;
$branchRows = $bar['branches'] ?? [];
$selectsF = $bar['selects'] ?? [];
$hasActive = ($search['value'] ?? '') !== '' || ($statusF['value'] ?? '') !== '' || !empty($bar['branch']) || ($dateF['from'] ?? '') !== '' || ($dateF['to'] ?? '') !== '' || ($amountF['min'] ?? '') !== '' || ($amountF['max'] ?? '') !== '';
foreach ($selectsF as $sf) {
    $hasActive = $hasActive || (string) ($sf['value'] ?? '') !== '' && (string) $sf['value'] !== '0';
}
?>
<form method="get" action="<?= e($bar['action']) ?>" class="row g-2 mb-3 align-items-end" data-list-filter>
  <?php if ($search): ?>
    <div class="col-lg-3 col-md-6">
      <label class="form-label small mb-0" for="lf-q">Search</label>
      <input type="search" id="lf-q" name="q" class="form-control" value="<?= e($search['value']) ?>" placeholder="<?= e($search['placeholder'] ?? 'Search') ?>" autocomplete="off">
    </div>
  <?php endif; ?>
  <?php if ($statusF): ?>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-status">Status</label>
      <select id="lf-status" name="status" class="form-select">
        <option value=""><?= e($statusF['all'] ?? 'All Statuses') ?></option>
        <?php foreach ($statusF['options'] as $s): ?>
          <option value="<?= e($s) ?>" <?= ($statusF['value'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <?php foreach ($selectsF as $i => $sf): ?>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-sel<?= (int) $i ?>"><?= e($sf['label']) ?></label>
      <select id="lf-sel<?= (int) $i ?>" name="<?= e($sf['name']) ?>" class="form-select">
        <option value=""><?= e($sf['all'] ?? 'All') ?></option>
        <?php foreach ($sf['options'] as $optValue => $optLabel): ?>
          <option value="<?= e((string) $optValue) ?>" <?= (string) ($sf['value'] ?? '') === (string) $optValue ? 'selected' : '' ?>><?= e($optLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endforeach; ?>
  <?php if (!empty($branchRows)): ?>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-branch">Branch</label>
      <select id="lf-branch" name="branch_id" class="form-select">
        <option value="">All Branches</option>
        <?php foreach ($branchRows as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= (int) ($bar['branch'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['branch_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <?php if ($dateF): ?>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-from"><?= e($dateF['label'] ?? 'Date') ?> from</label>
      <input type="date" id="lf-from" name="from" class="form-control" value="<?= e($dateF['from'] ?? '') ?>">
    </div>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-to">to</label>
      <input type="date" id="lf-to" name="to" class="form-control" value="<?= e($dateF['to'] ?? '') ?>">
    </div>
  <?php endif; ?>
  <?php if ($amountF): ?>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-min"><?= e($amountF['label'] ?? 'Amount') ?> min</label>
      <input type="number" step="0.01" min="0" id="lf-min" name="min" class="form-control" value="<?= e($amountF['min'] ?? '') ?>" placeholder="0.00">
    </div>
    <div class="col-lg-2 col-md-4 col-6">
      <label class="form-label small mb-0" for="lf-max">max</label>
      <input type="number" step="0.01" min="0" id="lf-max" name="max" class="form-control" value="<?= e($amountF['max'] ?? '') ?>" placeholder="any">
    </div>
  <?php endif; ?>
  <div class="col-auto d-flex align-items-end gap-2">
    <button class="btn btn-outline-secondary" type="submit" data-list-submit><i class="mdi mdi-magnify"></i> Filter</button>
    <a class="btn btn-link px-1" href="<?= e($bar['action']) ?>" data-list-clear <?= $hasActive ? '' : 'hidden' ?>>Clear</a>
    <?php if (!empty($bar['export'])): ?>
      <a class="btn btn-outline-success" href="<?= e($bar['action'] . '?' . \App\Support\ListFilter::exportQuery($_GET)) ?>" data-list-export><i class="mdi mdi-download"></i> Download CSV</a>
    <?php endif; ?>
  </div>
</form>
