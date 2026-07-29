<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Application Submitted | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card">
        <div class="card-body text-center">
          <i class="mdi mdi-check-circle text-success" style="font-size:48px;"></i>
          <h4 class="mt-2">Application Submitted</h4>
          <p class="text-muted">Thank you for applying. Keep the reference number below to track your application status.</p>
          <h3 class="my-3"><code><?= e($trackingId) ?></code></h3>
          <a href="<?= url('/careers/track?tracking_id=' . urlencode($trackingId)) ?>" class="btn btn-info">Track My Application</a>
          <a href="<?= url('/careers') ?>" class="btn btn-outline-secondary">Back to Careers</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
