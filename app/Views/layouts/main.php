<?php
use App\Core\Auth;
use App\Models\Company;
$user = Auth::user();
$company = (new Company())->primary() ?: [];
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'DesertLedger';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : (!empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-icon.png'));
$sidebarLogoUrl = !empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-light-text.png');
$primaryColor = $company['primary_color'] ?? '#25a9e0';
$sidebarColor = !empty($company['sidebar_color']) ? $company['sidebar_color'] : '#ffffff';
$sidebarRgb = array_map('hexdec', str_split(ltrim($sidebarColor, '#'), 2));
// YIQ brightness formula: pick white or dark text so it stays readable against whatever color the admin picks.
$sidebarYiq = (($sidebarRgb[0] * 299) + ($sidebarRgb[1] * 587) + ($sidebarRgb[2] * 114)) / 1000;
$sidebarTextColor = $sidebarYiq >= 150 ? '#1a1a1a' : '#ffffff';
$footerTagline = $company['footer_tagline'] ?? 'Your trusted Loan Manager';
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title><?= e($title ?? 'Dashboard') ?> | <?= e($brandName) ?></title>

  <link rel="icon" type="image/png" sizes="16x16" href="<?= $faviconUrl ?>" />
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet" />
  <link href="<?= asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') ?>" rel="stylesheet" />
  <link href="<?= asset('assets/extra-libs/datatables-buttons/css/buttons.bootstrap4.min.css') ?>" rel="stylesheet" />

  <style>
    .sidebar-nav ul .sidebar-item .sidebar-link { font-size:15px; }
    .module-card { transition:.2s; }
    .module-card:hover {
      transform:translateY(-3px);
      box-shadow:0 10px 25px rgba(0,0,0,.08);
    }
    /* White-label accent color -- overrides the theme's Bootstrap "info"
       accent everywhere it's used (buttons, badges, active nav, links). */
    :root { --bs-info: <?= e($primaryColor) ?>; --bs-info-rgb: <?= implode(',', array_map('hexdec', str_split(ltrim($primaryColor, '#'), 2))) ?>; }
    .btn-info, .badge.bg-info, .bg-info, .page-item.active .page-link, .sidebar-nav ul .sidebar-item.selected > .sidebar-link {
      background-color: <?= e($primaryColor) ?> !important;
      border-color: <?= e($primaryColor) ?> !important;
    }
    .text-info, a.link { color: <?= e($primaryColor) ?> !important; }
    .border-info { border-color: <?= e($primaryColor) ?> !important; }
    .btn-info:hover, .btn-info:focus, .btn-outline-info:hover {
      filter: brightness(90%);
    }
    .btn-outline-info { color: <?= e($primaryColor) ?> !important; border-color: <?= e($primaryColor) ?> !important; }
    .btn-outline-info:hover { background-color: <?= e($primaryColor) ?> !important; color: #fff !important; }
    /* The vendor template's own JS (app.js setnavbarbg()/setlogobg()) stamps
       data-navbarbg="skin1"/data-logobg="skin1" on these elements on every
       page load (unconditionally, since NavbarBg/LogoBg are never set in
       app.init.js), which paints them with the template's default blue via
       its own [data-navbarbg=skin1]/[data-logobg=skin1] CSS rules -- on top
       of and independent of .topbar's own background. All three need the
       override, not just .topbar itself. */
    .topbar, .topbar .navbar-collapse, .topbar .top-navbar .navbar-header {
      background-color: <?= e($primaryColor) ?> !important;
    }

    /* Sidebar background -- admin-set separately from the primary color above
       (Settings > Company > Sidebar Background Color), defaulting to match
       the primary color when no override is set. Text/icon color is picked
       automatically for contrast against whatever color is chosen. */
    .left-sidebar, .scroll-sidebar { background-color: <?= e($sidebarColor) ?> !important; }
    .sidebar-nav ul .sidebar-item .sidebar-link,
    .sidebar-nav ul .sidebar-item .sidebar-link i,
    .sidebar-nav .nav-small-cap {
      color: <?= e($sidebarTextColor) ?> !important;
      opacity: .85;
    }
    .sidebar-nav ul .sidebar-item .sidebar-link:hover,
    .sidebar-nav ul .sidebar-item.selected > .sidebar-link {
      opacity: 1;
    }
    .sidebar-nav ul .sidebar-item.selected > .sidebar-link,
    .sidebar-nav ul .sidebar-item.selected > .sidebar-link i {
      color: #fff !important;
    }

    /* Printing a report page (Trial Balance, General Journal/Ledger, etc.)
       should only print the report itself -- not the sidebar, topbar,
       breadcrumb or footer chrome around it. Pages also use a .no-print
       class on their own toolbar buttons/forms, which only takes effect
       because of this rule. */
    @media print {
      .left-sidebar, .topbar, .page-titles, .footer, .no-print {
        display: none !important;
      }
      .page-wrapper {
        margin-left: 0 !important;
        background: #fff !important;
      }
      body, .container-fluid {
        padding: 0 !important;
      }
    }

    /* The preloader spinner had no centering rule at all -- it just sat at
       the default top-left flow position inside its full-screen overlay. */
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

<div id="main-wrapper">

  <header class="topbar">
    <nav class="navbar top-navbar navbar-expand-md navbar-dark">
      <div class="navbar-header">
        <a class="nav-toggler waves-effect waves-light d-block d-md-none" href="javascript:void(0)">
          <i class="ti-menu ti-close"></i>
        </a>

        <a class="navbar-brand" href="<?= url('/dashboard') ?>">
          
          <span class="logo-text">
            <img src="<?= $sidebarLogoUrl ?>" class="light-logo" alt="homepage" style="height: 76px; max-width: 220px; object-fit: contain;" />
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

          <?php if (Auth::can('dashboard.view')): ?>
          <li class="sidebar-item">
            <a class="sidebar-link waves-effect waves-dark sidebar-link" href="<?= url('/dashboard') ?>">
              <i class="mdi mdi-gauge"></i>
              <span class="hide-menu">Dashboard</span>
            </a>
          </li>
          <?php endif; ?>

          <li class="nav-small-cap">
            <i class="mdi mdi-dots-horizontal"></i>
            <span class="hide-menu"><?= e($brandName) ?></span>
          </li>

          <?php
          $menus = [
            'Borrowers' => ['icon' => 'mdi-account-multiple', 'items' => [
              ['label' => 'Borrower List', 'url' => url('/borrowers'), 'perm' => 'borrowers.view'],
              ['label' => 'Add Borrower', 'url' => url('/borrowers/create'), 'perm' => 'borrowers.create'],
            ]],
            'Loans' => ['icon' => 'mdi-cash-multiple', 'items' => [
              ['label' => 'Loan List', 'url' => url('/loans'), 'perm' => 'loans.view'],
              ['label' => 'New Loan', 'url' => url('/loans/create'), 'perm' => 'loans.create'],
              ['label' => 'Loan Products & Plans', 'url' => url('/loan-products'), 'perm' => 'loans.view'],
              ['label' => 'Portal Loan Requests', 'url' => url('/loan-requests'), 'perm' => 'loans.view'],
              ['label' => 'Loan Reschedules', 'url' => url('/reschedules'), 'perm' => 'reschedules.view'],
            ]],
            'Collections' => ['icon' => 'mdi-bank', 'items' => [
              ['label' => 'Payments', 'url' => url('/payments'), 'perm' => 'collections.view'],
              ['label' => 'Refund Claims', 'url' => url('/refund-claims'), 'perm' => 'refunds.view'],
              ['label' => 'Collections Worklist', 'url' => url('/collections/worklist'), 'perm' => 'collections.arrears'],
              ['label' => 'Debit Orders', 'url' => url('/debit-orders'), 'perm' => 'collections.debit_orders'],
              ['label' => 'Debit Order Runs', 'url' => url('/debit-order-runs'), 'perm' => 'collections.debit_orders'],
              ['label' => 'Collection Reports', 'url' => url('/debit-order-collections'), 'perm' => 'collections.debit_orders'],
              ['label' => 'Debit Order Cancellations', 'url' => url('/debit-order-cancellations'), 'perm' => 'collections.debit_orders'],
            ]],
            'Fixed Assets' => ['icon' => 'mdi-trending-up', 'items' => [
              ['label' => 'Asset Register', 'url' => url('/fixed-assets'), 'perm' => 'assets.view'],
              ['label' => 'Register Asset', 'url' => url('/fixed-assets/create'), 'perm' => 'assets.manage'],
            ]],
            'Applications' => ['icon' => 'mdi-telegram', 'items' => [
              ['label' => 'All Applications', 'url' => url('/applications'), 'perm' => 'applications.view'],
              ['label' => 'New Applications', 'url' => url('/applications?status=Submitted'), 'perm' => 'applications.view'],
              ['label' => 'Screening', 'url' => url('/applications?status=Screening'), 'perm' => 'applications.view'],
              ['label' => 'Rejected Applications', 'url' => url('/applications?status=Rejected'), 'perm' => 'applications.view'],
            ]],
            'Accounting' => ['icon' => 'mdi-calculator', 'items' => [
              ['label' => 'Chart of Accounts', 'url' => url('/accounting/accounts'), 'perm' => 'accounting.chart'],
              ['label' => 'Bank Accounts', 'url' => url('/accounting/bank-accounts'), 'perm' => 'accounting.bank_accounts'],
              ['label' => 'General Journal', 'url' => url('/accounting/journals'), 'perm' => 'accounting.journals'],
              ['label' => 'General Ledger', 'url' => url('/accounting/general-ledger'), 'perm' => 'accounting.journals'],
              ['label' => 'New Manual Journal', 'url' => url('/accounting/journals/create'), 'perm' => 'accounting.journals'],
              ['label' => 'Adjustment Journals', 'url' => url('/accounting/adjustment-journals'), 'perm' => 'accounting.adjustment_journals'],
              ['label' => 'Recurring Journals', 'url' => url('/accounting/recurring-journals'), 'perm' => 'accounting.recurring_journals'],
              ['label' => 'Fiscal Years & Periods', 'url' => url('/accounting/fiscal-years'), 'perm' => 'accounting.settings'],
              ['label' => 'Trial Balance', 'url' => url('/accounting/trial-balance'), 'perm' => 'accounting.trial_balance'],
              ['label' => 'Cash Book', 'url' => url('/accounting/cash-book'), 'perm' => 'accounting.cashbook'],
              ['label' => 'AFS Export', 'url' => url('/accounting/afs-export'), 'perm' => 'accounting.balance_sheet'],
              ['label' => 'Bad Debt Provisioning', 'url' => url('/accounting/bad-debt-provisions'), 'perm' => 'accounting.provisions'],
              ['label' => 'Bad Debts & Write-Offs', 'url' => url('/accounting/bad-debts'), 'perm' => 'accounting.provisions'],
              ['label' => 'Loan Write-Offs', 'url' => url('/accounting/loan-write-offs'), 'perm' => 'accounting.writeoffs'],
              ['label' => 'Penalty Accruals', 'url' => url('/accounting/penalty-accruals'), 'perm' => 'accounting.view'],
              ['label' => 'Bank Reconciliation', 'url' => url('/accounting/bank-reconciliation'), 'perm' => 'accounting.bank_reconciliation'],
              ['label' => 'Expenses', 'url' => url('/expenses'), 'perm' => 'expenses.view'],
              ['label' => 'Expense Categories', 'url' => url('/expense-categories'), 'perm' => 'expenses.view'],
            ]],
            'Reports' => ['icon' => 'mdi-chart-bar', 'items' => [
              ['label' => 'Operational Reports', 'url' => url('/reports/operational'), 'perm' => 'reports.operational'],
              ['label' => 'Financial Reports', 'url' => url('/reports'), 'perm' => 'reports.financial'],
              ['label' => 'Regulatory Reports', 'url' => url('/reports/regulatory'), 'perm' => 'reports.regulatory'],
            ]],
            'Documents' => ['icon' => 'mdi-file-document', 'items' => [
              ['label' => 'Templates', 'url' => url('/templates'), 'perm' => 'documents.templates'],
              ['label' => 'Generated Documents', 'url' => url('/generated-documents'), 'perm' => 'documents.view'],
              ['label' => 'Letters', 'url' => url('/letters'), 'perm' => 'documents.view'],
            ]],
            'Compliance' => ['icon' => 'mdi-gavel', 'items' => [
              ['label' => 'NAMFISA Reports', 'url' => url('/compliance/namfisa'), 'perm' => 'compliance.namfisa'],
              ['label' => 'Duty Stamps', 'url' => url('/compliance/duty-stamps'), 'perm' => 'compliance.duty_stamp'],
              ['label' => 'Payment Methods', 'url' => url('/compliance/payment-methods'), 'perm' => 'compliance.payment_methods'],
              ['label' => 'Quarterly Reports', 'url' => url('/compliance/quarterly-reports'), 'perm' => 'compliance.quarterly'],
            ]],
            'Notifications' => ['icon' => 'mdi-bell', 'items' => [
              ['label' => 'SMS Queue', 'url' => url('/notifications/sms'), 'perm' => 'notifications.view'],
              ['label' => 'Email Queue', 'url' => url('/notifications/email'), 'perm' => 'notifications.view'],
              ['label' => 'Templates', 'url' => url('/notifications/templates'), 'perm' => 'notifications.templates'],
              ['label' => 'Settings', 'url' => url('/notifications/settings'), 'perm' => 'notifications.settings'],
            ]],
            'Settings' => ['icon' => 'mdi-settings', 'items' => [
              ['label' => 'Users', 'url' => url('/settings/users'), 'perm' => 'admin.users'],
              ['label' => 'Roles', 'url' => url('/settings/roles'), 'perm' => 'admin.roles'],
              ['label' => 'Permissions', 'url' => url('/settings/permissions'), 'perm' => 'admin.permissions'],
              ['label' => 'Company Settings', 'url' => url('/settings/company'), 'perm' => 'admin.company'],
              ['label' => 'AI Settings', 'url' => url('/settings/ai'), 'perm' => 'admin.system_settings'],
              ['label' => 'Intake Sources', 'url' => url('/settings/intake-sources'), 'perm' => 'admin.system_settings'],
            ]],
          ];

          // Drop items the current user's role(s) can't reach, then drop any
          // group left with zero visible items -- keeps the sidebar honest
          // about what's actually clickable instead of just what exists.
          foreach ($menus as $menuName => $menu) {
            $menus[$menuName]['items'] = array_values(array_filter(
                $menu['items'],
                fn ($item) => Auth::can($item['perm'])
            ));
            if (empty($menus[$menuName]['items'])) {
                unset($menus[$menuName]);
            }
          }
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
                  <h6 class="mb-0"><small><?= e($brandName) ?></small></h6>
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
		<strong><?= e($brandName) ?></strong><br>
		<small><?= e($footerTagline) ?></small><br>
		<small>
			Proudly Powered by
			<strong>
				<a href="https://kodecamp.org/" target="_blank">
					Kodecamp Technologies
				</a>
			</strong>
			&copy; <?= date('Y') ?>
		</small>
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

<script src="<?= asset('assets/extra-libs/datatables.net/js/jquery.dataTables.min.js') ?>"></script>
<script src="<?= asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/dataTables.buttons.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/buttons.bootstrap4.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/jszip.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/pdfmake.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/vfs_fonts.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/buttons.html5.min.js') ?>"></script>
<script src="<?= asset('assets/extra-libs/datatables-buttons/js/buttons.print.min.js') ?>"></script>
<script src="<?= asset('dist/js/pages/datatable/app-datatables-init.js') ?>"></script>

<script>
  $('.preloader').fadeOut();

  if (typeof feather !== 'undefined') {
    feather.replace();
  }
</script>

</body>
</html>