<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
$primaryColor = $company['primary_color'] ?? '#25a9e0';
$j = $job;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($j['title']) ?> | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
  <style>.careers-header { background-color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>; color: #fff; }</style>
</head>
<body>
<div class="careers-header py-3 mb-4">
  <div class="container">
    <a href="<?= url('/careers') ?>" class="text-white text-decoration-none">&larr; Back to all positions</a>
  </div>
</div>

<div class="container pb-5">
<?php require __FILE__ . '.content'; ?>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
