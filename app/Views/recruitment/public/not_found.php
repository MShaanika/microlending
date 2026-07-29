<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Not Found | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container py-5 text-center">
  <h3>Not Found</h3>
  <p class="text-muted">The page you're looking for doesn't exist or is no longer available.</p>
  <a href="<?= url('/careers') ?>" class="btn btn-info">Back to Careers</a>
</div>
</body>
</html>
