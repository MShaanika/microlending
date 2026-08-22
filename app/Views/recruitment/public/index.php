<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : (!empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-icon.png'));
$logoUrl = !empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-icon.png');
$primaryColor = $company['primary_color'] ?? '#25a9e0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Careers | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
  <style>
    .careers-header { background-color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>; color: #fff; }
    .job-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
  </style>
</head>
<body>
<div class="careers-header py-4 mb-4">
  <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= $logoUrl ?>" alt="logo" style="max-height:48px;">
      <h4 class="mb-0 text-white">Careers at <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></h4>
    </div>
    <a href="<?= url('/careers/track') ?>" class="btn btn-light btn-sm">Track My Application</a>
  </div>
</div>

<div class="container pb-5">
  <?php if (!empty($settings['about_company'])): ?>
    <p class="lead"><?= nl2br(e($settings['about_company'])) ?></p>
  <?php endif; ?>

  <h5 class="mb-3">Open Positions</h5>

  <?php if (empty($jobs)): ?>
    <div class="alert alert-secondary">There are no open positions at this time. Please check back soon.</div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach ($jobs as $job): ?>
      <div class="col-md-6">
        <div class="card job-card h-100">
          <div class="card-body">
            <h5 class="card-title"><?= e($job['title']) ?> <?php if ($job['is_featured']): ?><span class="badge bg-warning">Featured</span><?php endif; ?></h5>
            <p class="text-muted mb-2"><?= e($job['job_type_name'] ?: 'General') ?> &middot; <?= e($job['location_name'] ?: 'Location TBD') ?></p>
            <?php if (!empty($job['description'])): ?>
              <p class="card-text"><?= e(mb_strimwidth(strip_tags($job['description']), 0, 160, '...')) ?></p>
            <?php endif; ?>
            <a href="<?= url('/careers/' . $job['posting_code']) ?>" class="btn btn-info btn-sm" data-modal-url="<?= url('/careers/' . $job['posting_code']) ?>" data-modal-title="<?= e($job['title']) ?>" data-modal-size="xl" data-modal-refresh="0">View &amp; Apply</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('dist/js/app-ui.js') ?>"></script>
</body>
</html>
