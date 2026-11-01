<?php
/** One line above a filtered list: how many rows match, and a hint when the list was cut off. $count = rows returned, $limit = the cap that applied. */
$count = (int) ($count ?? 0);
$limit = (int) ($limit ?? 0);
$active = !empty($filtersActive);
?>
<?php if ($active || ($limit > 0 && $count >= $limit)): ?>
  <p class="small text-muted mb-2" data-list-count>
    <?= number_format($count) ?> record<?= $count === 1 ? '' : 's' ?><?= $active ? ' match your filters' : '' ?>.
    <?php if ($limit > 0 && $count >= $limit): ?>
      Showing the latest <?= number_format($limit) ?>. Narrow the filters, or download the CSV for up to <?= number_format(\App\Support\ListFilter::EXPORT_LIMIT) ?>.
    <?php endif; ?>
  </p>
<?php endif; ?>
