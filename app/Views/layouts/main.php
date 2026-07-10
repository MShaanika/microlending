<?php use App\Core\Auth; $user = Auth::user(); ?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title><?= e($title ?? 'Dashboard') ?> | Micro Lending System</title>

  <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('assets/images/favicon.png') ?>" />
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet" />

  <style>
    .sidebar-nav ul .sidebar-item .sidebar-link { font-size:15px; }
    .module-card { transition:.2s; }
    .module-card:hover {
      transform:translateY(-3px);
      box-shadow:0 10px 25px rgba(0,0,0,.08);
    }
  </style>
</head>

<body>
<div class="preloader">
  <div class="spinner-border text-info" role="status"></div>
</div>

<div id="main-wrapper">

  <header class="topbar">
    <nav class="navbar top-navbar navbar-expand-md navbar-dark">
      <div class="navbar-header">
        <a class="nav-toggler waves-effect waves-light d-block d-md-none" href="javascript:void(0)">
          <i class="ti-menu ti-close"></i>
        </a>

        <a class="navbar-brand" href="<?= url('/dashboard') ?>">
          
          <span class="logo-text">
            <img src="<?= asset('assets/images/logo-light-text.png') ?>" class="light-logo" alt="homepage" style="    height: 76px;" />
          </span>
        </a>

        <a class="topbartoggler d-block d-md-none waves-effect waves-light"
           href="javascript:void(0)"
           data-bs-toggle="collapse"
           data-bs-target="#navbarSupportedContent">
          <i class="ti-more"></i>
        </a>
      </div>

      <div class="navbar-collapse collapse" id="navbarSupportedContent">
        <ul class="navbar-nav me-auto">
          <li class="nav-item">
            <a class="nav-link sidebartoggler d-none d-md-block waves-effect waves-dark" href="javascript:void(0)">
              <i class="ti-menu"></i>
            </a>
          </li>

          <li class="nav-item d-none d-md-block search-box">
            <a class="nav-link d-none d-md-block waves-effect waves-dark" href="javascript:void(0)">
              <i class="ti-search"></i>
            </a>
            <form class="app-search">
              <input type="text" class="form-control" placeholder="Search & enter" />
              <a class="srh-btn"><i class="ti-close"></i></a>
            </form>
          </li>
        </ul>

        <ul class="navbar-nav">
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle waves-effect waves-dark" href="#" data-bs-toggle="dropdown">
              <img src="<?= asset('assets/images/users/1.jpg') ?>" alt="user" width="30" class="profile-pic rounded-circle" />
            </a>

            <div class="dropdown-menu dropdown-menu-end user-dd animated flipInY">
              <div class="d-flex no-block align-items-center p-3 bg-info text-white mb-2">
                <div>
                  <img src="<?= asset('assets/images/users/1.jpg') ?>" alt="user" class="rounded-circle" width="60" />
                </div>
                <div class="ms-2">
                  <h4 class="mb-0 text-white"><?= e($user['name'] ?? 'User') ?></h4>
                  <p class="mb-0"><?= e($user['email'] ?? '') ?></p>
                </div>
              </div>

              <a class="dropdown-item" href="#"><i data-feather="user" class="feather-sm text-info me-1 ms-1"></i> My Profile</a>
              <a class="dropdown-item" href="#"><i data-feather="settings" class="feather-sm text-warning me-1 ms-1"></i> Account Setting</a>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item" href="<?= url('/logout') ?>">
                <i data-feather="log-out" class="feather-sm text-danger me-1 ms-1"></i> Logout
              </a>
            </div>
          </li>
        </ul>
      </div>
    </nav>
  </header>

  <aside class="left-sidebar">
    <div class="scroll-sidebar">

     <div class="user-profile position-relative"
     style="
        background-image:url('<?= asset('assets/images/background/user-info.png') ?>');
        background-repeat:no-repeat;
        background-position:center center;
        background-size:cover;
        min-height:190px;
     ">
        <div class="profile-img">
          <img src="<?= asset('assets/images/users/1.jpg') ?>" alt="user" style="border-radius: 25px;" class="w-100" />
        </div>

        <div class="profile-text pt-1 dropdown">
          <a href="#"
             class="dropdown-toggle u-dropdown w-100 text-white d-block position-relative"
             id="dropdownMenuLink"
             data-bs-toggle="dropdown"
             aria-expanded="false">
            <?= e($user['name'] ?? 'System User') ?>
          </a>

          <div class="dropdown-menu animated flipInY" aria-labelledby="dropdownMenuLink">
            <a class="dropdown-item" href="#"><i data-feather="user" class="feather-sm text-info me-1 ms-1"></i> My Profile</a>
            <a class="dropdown-item" href="#"><i data-feather="mail" class="feather-sm text-success me-1 ms-1"></i> Inbox</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="#"><i data-feather="settings" class="feather-sm text-warning me-1 ms-1"></i> Account Setting</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= url('/logout') ?>">
              <i data-feather="log-out" class="feather-sm text-danger me-1 ms-1"></i> Logout
            </a>
          </div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <ul id="sidebarnav">

          <li class="nav-small-cap">
            <i class="mdi mdi-dots-horizontal"></i>
            <span class="hide-menu">Main</span>
          </li>

          <li class="sidebar-item">
            <a class="sidebar-link waves-effect waves-dark sidebar-link" href="<?= url('/dashboard') ?>">
              <i class="mdi mdi-gauge"></i>
              <span class="hide-menu">Dashboard</span>
            </a>
          </li>

          <li class="nav-small-cap">
            <i class="mdi mdi-dots-horizontal"></i>
            <span class="hide-menu">DesertLedger</span>
          </li>

          <?php
          $menus = [
            'Borrowers' => ['icon' => 'mdi-account-multiple', 'items' => [
              ['label' => 'Borrower List', 'url' => url('/borrowers')],
              ['label' => 'Add Borrower', 'url' => url('/borrowers/create')],
            ]],
            'Loans' => ['icon' => 'mdi-cash-multiple', 'items' => [
              ['label' => 'Loan List', 'url' => url('/loans')],
              ['label' => 'New Loan', 'url' => url('/loans/create')],
              ['label' => 'Loan Products & Plans', 'url' => url('/loan-products')],
              ['label' => 'Portal Loan Requests', 'url' => url('/loan-requests')],
            ]],
            'Collections' => ['icon' => 'mdi-bank', 'items' => [
              ['label' => 'Payments', 'url' => url('/payments')],
              ['label' => 'Refund Claims', 'url' => url('/refund-claims')],
            ]],
            'Fixed Assets' => ['icon' => 'mdi-trending-up', 'items' => [
              ['label' => 'Asset Register', 'url' => url('/fixed-assets')],
              ['label' => 'Register Asset', 'url' => url('/fixed-assets/create')],
            ]],
            'Applications' => ['icon' => 'mdi-telegram', 'items' => [
              ['label' => 'New Applications'], ['label' => 'Screening'], ['label' => 'Rejected Applications'],
            ]],
            'Accounting' => ['icon' => 'mdi-calculator', 'items' => [
              ['label' => 'Chart of Accounts', 'url' => url('/accounting/accounts')],
              ['label' => 'Bank Accounts', 'url' => url('/accounting/bank-accounts')],
              ['label' => 'General Ledger', 'url' => url('/accounting/journals')],
              ['label' => 'New Manual Journal', 'url' => url('/accounting/journals/create')],
              ['label' => 'Fiscal Years & Periods', 'url' => url('/accounting/fiscal-years')],
              ['label' => 'Trial Balance', 'url' => url('/accounting/trial-balance')],
              ['label' => 'Cash Book', 'url' => url('/accounting/cash-book')],
              ['label' => 'AFS Export', 'url' => url('/accounting/afs-export')],
              ['label' => 'Bad Debt Provisioning', 'url' => url('/accounting/bad-debt-provisions')],
              ['label' => 'Bad Debts & Write-Offs', 'url' => url('/accounting/bad-debts')],
              ['label' => 'Loan Write-Offs', 'url' => url('/accounting/loan-write-offs')],
              ['label' => 'Penalty Accruals', 'url' => url('/accounting/penalty-accruals')],
              ['label' => 'Bank Reconciliation', 'url' => url('/accounting/bank-reconciliation')],
            ]],
            'Reports' => ['icon' => 'mdi-chart-bar', 'items' => [
              ['label' => 'Operational Reports'], ['label' => 'Financial Reports'], ['label' => 'Regulatory Reports'],
            ]],
            'Documents' => ['icon' => 'mdi-file-document', 'items' => [
              ['label' => 'Templates'], ['label' => 'Generated Documents'],
              ['label' => 'Letters', 'url' => url('/letters')],
            ]],
            'Compliance' => ['icon' => 'mdi-gavel', 'items' => [
              ['label' => 'NAMFISA Reports'], ['label' => 'Duty Stamps'], ['label' => 'Quarterly Reports'],
            ]],
            'Notifications' => ['icon' => 'mdi-bell', 'items' => [
              ['label' => 'SMS Queue'], ['label' => 'Email Queue'], ['label' => 'Templates'],
            ]],
            'Settings' => ['icon' => 'mdi-settings', 'items' => [
              ['label' => 'Users', 'url' => url('/settings/users')],
              ['label' => 'Roles', 'url' => url('/settings/roles')],
              ['label' => 'Permissions', 'url' => url('/settings/permissions')],
              ['label' => 'Company Settings', 'url' => url('/settings/company')],
            ]],
          ];
          ?>

          <?php foreach ($menus as $menuName => $menu): ?>
            <li class="sidebar-item">
              <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false">
                <i class="mdi <?= $menu['icon'] ?>"></i>
                <span class="hide-menu"><?= e($menuName) ?></span>
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <?php foreach ($menu['items'] as $item): ?>
                  <li class="sidebar-item">
                    <a href="<?= $item['url'] ?? 'javascript:void(0)' ?>" class="sidebar-link <?= isset($item['url']) ? '' : 'disabled text-muted' ?>">
                      <i class="mdi mdi-adjust"></i>
                      <span class="hide-menu"><?= e($item['label']) ?><?= isset($item['url']) ? '' : ' <small>(soon)</small>' ?></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endforeach; ?>

        </ul>
      </nav>
    </div>
  </aside>

  <div class="page-wrapper">
  
  
  <div class="row page-titles">
          <div class="col-md-5 col-12 align-self-center">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item">
                <a href="<?= url('/dashboard') ?>">Home</a>
              </li>
              <li class="breadcrumb-item active"><?= e($title ?? 'Dashboard') ?></li>
            </ol>
          </div>
          <div class="col-md-7 col-12 align-self-center d-none d-md-block">
            <div class="d-flex mt-2 justify-content-end">
              <div class="d-flex me-3 ms-2">
                <div class="chart-text me-2">
                  <h6 class="mb-0"><small>DesertLedger</small></h6>
                </div>
                <div class="spark-chart">
                  <div id="monthchart"></div>
                </div>
              </div>
            </div>
          </div>
        </div>


    <div class="container-fluid">
      <?php require $content; ?>
    </div>

    <footer class="footer text-center">
		<strong>DesertLedger</strong><br>
		<small>Your trusted Loan Manager</small><br>
		<small>Proudly Powered by <strong>Kodecamp Technologies</strong> &copy; <?= date('Y') ?></small>
	</footer>
  </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/libs/perfect-scrollbar/dist/perfect-scrollbar.jquery.min.js') ?>"></script>
<script src="<?= asset('dist/js/feather.min.js') ?>"></script>

<script src="<?= asset('dist/js/app.min.js') ?>"></script>
<script src="<?= asset('dist/js/app.init.js') ?>"></script>
<script src="<?= asset('dist/js/app-style-switcher.js') ?>"></script>
<script src="<?= asset('dist/js/sidebarmenu.js') ?>"></script>
<script src="<?= asset('dist/js/custom.min.js') ?>"></script>

<script>
  $('.preloader').fadeOut();

  if (typeof feather !== 'undefined') {
    feather.replace();
  }
</script>

</body>
</html>