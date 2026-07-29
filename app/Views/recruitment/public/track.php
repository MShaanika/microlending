<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
$statusBadge = ['New' => 'info', 'Screening' => 'secondary', 'Interview' => 'warning', 'Offer' => 'primary', 'Hired' => 'success', 'Rejected' => 'danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Track Application | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card mb-3">
        <div class="card-body">
          <h4 class="card-title">Track My Application</h4>
          <form method="get" action="<?= url('/careers/track') ?>" class="d-flex gap-2">
            <input type="text" name="tracking_id" class="form-control" placeholder="e.g. TRK-260729-A1B2C3" value="<?= e($trackingId ?? '') ?>">
            <button type="submit" class="btn btn-info">Track</button>
          </form>
        </div>
      </div>

      <?php if (!empty($notFound)): ?>
        <div class="alert alert-warning">No application found for that reference number.</div>
      <?php endif; ?>

      <?php if (!empty($result)): ?>
        <div class="card">
          <div class="card-body">
            <h5 class="card-title"><?= e($result['first_name'] . ' ' . $result['last_name']) ?></h5>
            <p class="text-muted"><?= e($result['job_title'] ?: 'Uncategorized') ?></p>
            <p>Status: <span class="badge bg-<?= $statusBadge[$result['status']] ?? 'secondary' ?>"><?= e($result['status']) ?></span></p>
            <p class="text-muted small">Applied on <?= e(date('d M Y', strtotime($result['application_date']))) ?></p>

            <?php if (!empty($pendingOffers)): ?>
              <hr>
              <h6>You have an offer awaiting your response</h6>
              <?php foreach ($pendingOffers as $o): ?>
                <a href="<?= url('/careers/offer/' . $result['tracking_id'] . '/' . $o['id']) ?>" class="btn btn-success btn-sm mb-1">View Offer: <?= e($o['position']) ?></a><br>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="text-center mt-3">
        <a href="<?= url('/careers') ?>" class="btn btn-outline-secondary btn-sm">Back to Careers</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
