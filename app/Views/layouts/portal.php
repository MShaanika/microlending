<?php use App\Core\PortalAuth; $portalUser = PortalAuth::user(); ?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title><?= e($title ?? 'My Portal') ?> | Micro Lending System</title>

  <link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>" />
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet" />
  <style>
    .portal-sidebar {
      position: fixed; top: 0; left: 0; bottom: 0; width: 240px; z-index: 1030;
      background: #29354a; overflow-y: auto;
    }
    .portal-sidebar .brand { padding: 20px 16px; color: #fff; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,.08); }
    .portal-sidebar .nav-link { color: rgba(255,255,255,.75); padding: 12px 20px; border-radius: 0; }
    .portal-sidebar .nav-link:hover, .portal-sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.08); }
    .portal-sidebar .nav-link i { width: 22px; display: inline-block; }
    .portal-main { margin-left: 240px; min-height: 100vh; display: flex; flex-direction: column; }
    .portal-topbar { background: #fff; border-bottom: 1px solid #e9ecef; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
    @media (max-width: 767px) {
      .portal-sidebar { width: 100%; height: auto; position: relative; }
      .portal-main { margin-left: 0; }
    }
    .preloader {
      display: flex;
      align-items: center;
      justify-content: center;
    }
  </style>
</head>
<body>
<div class="preloader">
  <div class="spinner-border text-info" role="status"></div>
</div>

<div class="d-md-flex">
  <nav class="portal-sidebar d-none d-md-block">
    <div class="brand"><i class="mdi mdi-account-circle"></i> Borrower Portal</div>
    <ul class="nav flex-column py-2">
      <?php
        $navItems = [
          ['url' => '/portal/dashboard', 'icon' => 'mdi-view-dashboard', 'label' => 'Dashboard'],
          ['url' => '/portal/loans', 'icon' => 'mdi-cash-multiple', 'label' => 'My Loans'],
          ['url' => '/portal/loan-requests', 'icon' => 'mdi-file-document-edit', 'label' => 'Loan Requests'],
          ['url' => '/portal/payments', 'icon' => 'mdi-bank-outline', 'label' => 'My Payments'],
          ['url' => '/portal/letters', 'icon' => 'mdi-email-outline', 'label' => 'Letters'],
          ['url' => '/portal/refund-claims', 'icon' => 'mdi-cash-refund', 'label' => 'Refund Claims'],
        ];
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
      ?>
      <?php foreach ($navItems as $item): ?>
        <li class="nav-item">
          <a class="nav-link <?= str_contains($currentPath, $item['url']) ? 'active' : '' ?>" href="<?= url($item['url']) ?>">
            <i class="mdi <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
          </a>
        </li>
      <?php endforeach; ?>
      <li class="nav-item mt-3 border-top pt-2" style="border-color: rgba(255,255,255,.08) !important;">
        <a class="nav-link" href="<?= url('/portal/logout') ?>"><i class="mdi mdi-logout"></i> Logout</a>
      </li>
    </ul>
  </nav>

  <div class="portal-main w-100">
    <div class="portal-topbar d-flex d-md-none mb-0" data-bs-toggle="collapse" data-bs-target="#portalMobileNav" style="cursor:pointer;">
      <span><i class="mdi mdi-menu"></i> Borrower Portal</span>
    </div>
    <div class="collapse d-md-none" id="portalMobileNav">
      <ul class="list-group list-group-flush">
        <?php foreach ($navItems as $item): ?>
          <li class="list-group-item"><a href="<?= url($item['url']) ?>"><i class="mdi <?= $item['icon'] ?>"></i> <?= $item['label'] ?></a></li>
        <?php endforeach; ?>
        <li class="list-group-item"><a href="<?= url('/portal/logout') ?>"><i class="mdi mdi-logout"></i> Logout</a></li>
      </ul>
    </div>

    <div class="portal-topbar">
      <h5 class="mb-0"><?= e($title ?? 'My Portal') ?></h5>
      <span class="text-muted"><i class="mdi mdi-account"></i> <?= e(($portalUser['first_name'] ?? '') . ' ' . ($portalUser['last_name'] ?? '')) ?></span>
    </div>

    <div class="container-fluid py-4 flex-grow-1">
      <?php require $content; ?>
    </div>

    <footer class="footer text-center py-3">
      Micro Lending System &mdash; Borrower Portal
    </footer>
  </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
<script>
  $('.preloader').fadeOut();
</script>
</body>
</html>
