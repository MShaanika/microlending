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
  <link href="<?= asset('assets/libs/select2/dist/css/select2.min.css') ?>" rel="stylesheet" />

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

    /* Content area background image -- shows through in the gaps around and
       between cards, which stay opaque (white) on top of it so page content
       remains fully readable. Overridden back to plain white when printing,
       below. */
    .page-wrapper {
      background-image: url('<?= asset('assets/images/background/user-info.png') ?>');
      background-repeat: no-repeat;
      background-position: center center;
      background-size: cover;
      background-attachment: fixed;
      min-height: 100vh;
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

    /* Colorful stat-tile cards -- shared across every module's stat/KPI row
       (Dashboard, Security Overview, module index pages, etc.) so they all
       look like one consistent design instead of each page reinventing its
       own card markup. Left accent bar color and icon circle background are
       set per-card via an inline style="border-left-color:#hex" plus a
       matching background on .kpi-icon -- see dashboard/index.php.content
       for the reference usage. */
    .kpi-card { border: none; border-left: 4px solid transparent; border-radius: .6rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); transition: box-shadow .15s ease; }
    .kpi-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.1); }
    .kpi-number { font-size: 1.85rem; font-weight: 700; line-height: 1.1; }
    .kpi-icon { width: 46px; height: 46px; min-width: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .kpi-icon i { font-size: 1.3rem; color: #fff; }
    .kpi-footer { border-top: 1px solid rgba(0,0,0,.06); margin-top: .85rem; padding-top: .6rem; }
    .chart-card-icon-btn { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(0,0,0,.04); color: #6c757d; }
    .chart-card-icon-btn:hover { background: rgba(0,0,0,.08); color: #333; }

    /* Third sidebar level (Module > Subgroup > Page) -- the vendor
       theme's own .second-level/.third-level rules only apply inside
       [data-layout="horizontal"] mega-dropdowns, which this app doesn't
       use (it's data-layout="vertical"), so they never reach the normal
       collapsible sidebar. This mirrors the vendor's own selector
       nesting for .first-level (style.css ~line 10653) one level
       deeper, so its specificity reliably wins -- progressively smaller
       type and deeper indentation for the deepest level, per the "don't
       make all levels look identical" navigation guidance. */
    .sidebar-nav ul .sidebar-item .first-level .sidebar-item .second-level .sidebar-item .sidebar-link {
      padding: 8px 20px 8px 32px;
      font-size: 13.5px;
    }
    .sidebar-nav ul .sidebar-item .first-level .sidebar-item .second-level .sidebar-item .sidebar-link i.mdi-adjust {
      font-size: 9px;
    }

    /* The vendor sidebar is a fixed 240px at every screen size (only
       its on/off-canvas *offset* changes on mobile, never its width --
       see style.css ~line 10571) -- too narrow for 3 nested levels of
       real label text, so .hide-menu's overflow:hidden/text-overflow:
       ellipsis was cutting labels like "General Accounting" or
       "Adjustment Journals" off well before their natural width.
       Widened to 270px; every dependent selector below targets the
       exact same rules the vendor CSS uses (page content's offsetting
       margin, and the mobile hidden-state left offset, which must
       exactly match the sidebar's own width or a sliver stays visible
       when "hidden") so nothing misaligns. */
    .left-sidebar, .left-sidebar .sidebar-footer { width: 270px; }
    #main-wrapper[data-layout="vertical"][data-sidebartype="full"] .page-wrapper { margin-left: 270px; }
    @media (max-width: 767px) {
      #main-wrapper[data-sidebartype="mini-sidebar"] .left-sidebar,
      #main-wrapper[data-sidebartype="mini-sidebar"] .left-sidebar .sidebar-footer {
        left: -270px;
      }
      /* The hidden-offset rule above ties the vendor's own
         #main-wrapper.show-sidebar .left-sidebar{left:0} on specificity,
         so being later in the cascade let it always win -- the mobile
         hamburger correctly added .show-sidebar but the sidebar never
         actually slid into view. !important forces the shown state back
         regardless of any other rule's position in the cascade. */
      #main-wrapper[data-sidebartype="mini-sidebar"].show-sidebar .left-sidebar,
      #main-wrapper[data-sidebartype="mini-sidebar"].show-sidebar .left-sidebar .sidebar-footer {
        left: 0 !important;
      }
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

        <a class="navbar-brand" href="<?= url(Auth::homePath()) ?>">
          
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

              <a class="dropdown-item" href="<?= url('/profile') ?>"><i data-feather="user" class="feather-sm text-info me-1 ms-1"></i> My Profile</a>
              <a class="dropdown-item" href="<?= url('/profile') ?>"><i data-feather="settings" class="feather-sm text-warning me-1 ms-1"></i> Account Setting</a>
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
            <a class="dropdown-item" href="<?= url('/profile') ?>"><i data-feather="user" class="feather-sm text-info me-1 ms-1"></i> My Profile</a>
            <a class="dropdown-item" href="#"><i data-feather="mail" class="feather-sm text-success me-1 ms-1"></i> Inbox</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= url('/profile') ?>"><i data-feather="settings" class="feather-sm text-warning me-1 ms-1"></i> Account Setting</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item" href="<?= url('/logout') ?>">
              <i data-feather="log-out" class="feather-sm text-danger me-1 ms-1"></i> Logout
            </a>
          </div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <ul id="sidebarnav">


          <?php if (Auth::can('dashboard.view')): ?>
          <li class="sidebar-item">
            <a class="sidebar-link waves-effect waves-dark sidebar-link" href="<?= url(Auth::homePath()) ?>">
              <i class="mdi mdi-gauge"></i>
              <span class="hide-menu">Dashboard</span>
            </a>
          </li>
          <?php endif; ?>

          <li class="sidebar-item">
            <a class="sidebar-link waves-effect waves-dark sidebar-link" href="<?= url('/my/drafts') ?>">
              <i class="mdi mdi-history"></i>
              <span class="hide-menu">My Drafts</span>
            </a>
          </li>

          <?php
          // Three-level hierarchy: Main Module -> Subgroup -> Item. A
          // module with no natural subgroups (e.g. Collections) uses the
          // single reserved key '_flat' -- the render loop below skips the
          // extra <li> wrapper for that key so it renders as a plain list
          // directly under the module, one level shallower than a grouped
          // module. Every url()/perm value here is unchanged from the
          // previous flat 30-group structure -- this pass only regroups
          // them; Create-action items (Add Borrower, New Loan, Register
          // Asset, Raise Ticket, New Referral, New Manual Journal, Add
          // Employee) were dropped because their list page already has its
          // own "+ Create" button (verified against each view before
          // removing), not because the routes/permissions changed.
          $menus = [
            'Lending' => ['icon' => 'mdi-cash-multiple', 'groups' => [
              'Borrowers' => ['items' => [
                ['label' => 'Borrower List', 'url' => url('/borrowers'), 'perm' => 'borrowers.view'],
              ]],
              'Applications' => ['items' => [
                ['label' => 'All Applications', 'url' => url('/applications'), 'perm' => 'applications.view'],
                ['label' => 'New Applications', 'url' => url('/applications?status=Submitted'), 'perm' => 'applications.view'],
                ['label' => 'Screening', 'url' => url('/applications?status=Screening'), 'perm' => 'applications.view'],
                ['label' => 'Rejected Applications', 'url' => url('/applications?status=Rejected'), 'perm' => 'applications.view'],
              ]],
              'Loans' => ['items' => [
                ['label' => 'Loan List', 'url' => url('/loans'), 'perm' => 'loans.view'],
                ['label' => 'Loan Products & Plans', 'url' => url('/loan-products'), 'perm' => 'loans.view'],
                ['label' => 'Portal Loan Requests', 'url' => url('/loan-requests'), 'perm' => 'loans.view'],
                ['label' => 'Loan Reschedules', 'url' => url('/reschedules'), 'perm' => 'reschedules.view'],
              ]],
            ]],
            'Collections' => ['icon' => 'mdi-bank', 'groups' => [
              '_flat' => ['items' => [
                ['label' => 'Payments', 'url' => url('/payments'), 'perm' => 'collections.view'],
                ['label' => 'Collections Worklist', 'url' => url('/collections/worklist'), 'perm' => 'collections.arrears'],
                ['label' => 'Debit Orders', 'url' => url('/debit-orders'), 'perm' => 'collections.debit_orders'],
                ['label' => 'Debit Order Runs', 'url' => url('/debit-order-runs'), 'perm' => 'collections.debit_orders'],
                ['label' => 'Collection Reports', 'url' => url('/debit-order-collections'), 'perm' => 'collections.debit_orders'],
                ['label' => 'Debit Order Cancellations', 'url' => url('/debit-order-cancellations'), 'perm' => 'collections.debit_orders'],
                ['label' => 'Refund Claims', 'url' => url('/refund-claims'), 'perm' => 'refunds.view'],
              ]],
            ]],
            'Accounting & Finance' => ['icon' => 'mdi-calculator', 'groups' => [
              'General Accounting' => ['items' => [
                ['label' => 'Chart of Accounts', 'url' => url('/accounting/accounts'), 'perm' => 'accounting.chart'],
                ['label' => 'General Journal', 'url' => url('/accounting/journals'), 'perm' => 'accounting.journals'],
                ['label' => 'General Ledger', 'url' => url('/accounting/general-ledger'), 'perm' => 'accounting.journals'],
                ['label' => 'Adjustment Journals', 'url' => url('/accounting/adjustment-journals'), 'perm' => 'accounting.adjustment_journals'],
                ['label' => 'Recurring Journals', 'url' => url('/accounting/recurring-journals'), 'perm' => 'accounting.recurring_journals'],
                ['label' => 'Cash Book', 'url' => url('/accounting/cash-book'), 'perm' => 'accounting.cashbook'],
              ]],
              'Banking' => ['items' => [
                ['label' => 'Bank Accounts', 'url' => url('/accounting/bank-accounts'), 'perm' => 'accounting.bank_accounts'],
                ['label' => 'Bank Reconciliation', 'url' => url('/accounting/bank-reconciliation'), 'perm' => 'accounting.bank_reconciliation'],
                ['label' => 'Reconciliation History', 'url' => url('/accounting/bank-reconciliation/history'), 'perm' => 'accounting.bank_reconciliation'],
              ]],
              'Loan Accounting' => ['items' => [
                ['label' => 'Bad Debt Provisioning', 'url' => url('/accounting/bad-debt-provisions'), 'perm' => 'accounting.provisions'],
                ['label' => 'Bad Debts & Write-Offs', 'url' => url('/accounting/bad-debts'), 'perm' => 'accounting.provisions'],
                ['label' => 'Loan Write-Offs', 'url' => url('/accounting/loan-write-offs'), 'perm' => 'accounting.writeoffs'],
                ['label' => 'Interest Accruals', 'url' => url('/accounting/interest-accruals'), 'perm' => 'accounting.view'],
                ['label' => 'Penalty Accruals', 'url' => url('/accounting/penalty-accruals'), 'perm' => 'accounting.view'],
              ]],
              'Expenses' => ['items' => [
                ['label' => 'Expenses', 'url' => url('/expenses'), 'perm' => 'expenses.view'],
                ['label' => 'Expense Categories', 'url' => url('/expense-categories'), 'perm' => 'expenses.view'],
              ]],
              'Financial Statements' => ['items' => [
                ['label' => 'Trial Balance', 'url' => url('/accounting/trial-balance'), 'perm' => 'accounting.trial_balance'],
                ['label' => 'AFS Export', 'url' => url('/accounting/afs-export'), 'perm' => 'accounting.balance_sheet'],
              ]],
              'Accounting Admin' => ['items' => [
                ['label' => 'Fiscal Years & Periods', 'url' => url('/accounting/fiscal-years'), 'perm' => 'accounting.settings'],
              ]],
              'Utilities / Maintenance' => ['items' => [
                ['label' => 'Disbursement Accrual Restatement', 'url' => url('/accounting/disbursement-restatement'), 'perm' => 'accounting.adjustment_journals'],
                ['label' => 'Interest & Penalty Restatement', 'url' => url('/accounting/interest-restatement'), 'perm' => 'accounting.adjustment_journals'],
                ['label' => 'Loan Status Dimensions Backfill', 'url' => url('/accounting/loan-status-backfill'), 'perm' => 'accounting.adjustment_journals'],
              ]],
            ]],
            'Fixed Assets' => ['icon' => 'mdi-trending-up', 'groups' => [
              '_flat' => ['items' => [
                ['label' => 'Asset Register', 'url' => url('/fixed-assets'), 'perm' => 'assets.view'],
              ]],
            ]],
            'Reports & Compliance' => ['icon' => 'mdi-file-chart', 'groups' => [
              'Reports' => ['items' => [
                ['label' => 'Operational Reports', 'url' => url('/reports/operational'), 'perm' => 'reports.operational'],
                ['label' => 'Financial Reports', 'url' => url('/reports'), 'perm' => 'reports.financial'],
                ['label' => 'Regulatory Reports', 'url' => url('/reports/regulatory'), 'perm' => 'reports.regulatory'],
                ['label' => 'Credit Bureau (CPL) Export', 'url' => url('/reports/cpl-export'), 'perm' => 'reports.cpl_export'],
              ]],
              'Regulatory Compliance' => ['items' => [
                ['label' => 'NAMFISA Reports', 'url' => url('/compliance/namfisa'), 'perm' => 'compliance.namfisa'],
                ['label' => 'Quarterly Reports', 'url' => url('/compliance/quarterly-reports'), 'perm' => 'compliance.quarterly'],
                ['label' => 'Duty Stamps', 'url' => url('/compliance/duty-stamps'), 'perm' => 'compliance.duty_stamp'],
                ['label' => 'Payment Methods', 'url' => url('/compliance/payment-methods'), 'perm' => 'compliance.payment_methods'],
              ]],
            ]],
            'Documents' => ['icon' => 'mdi-file-document', 'groups' => [
              '_flat' => ['items' => [
                ['label' => 'Templates', 'url' => url('/templates'), 'perm' => 'documents.templates'],
                ['label' => 'Generated Documents', 'url' => url('/generated-documents'), 'perm' => 'documents.view'],
                ['label' => 'Letters', 'url' => url('/letters'), 'perm' => 'documents.view'],
              ]],
            ]],
            'Human Resources' => ['icon' => 'mdi-account-multiple-plus', 'groups' => [
              'Employee Management' => ['items' => [
                ['label' => 'Employees', 'url' => url('/hrm/employees'), 'perm' => 'hrm.view'],
                ['label' => 'Departments', 'url' => url('/hrm/departments'), 'perm' => 'hrm.view'],
                ['label' => 'Designations', 'url' => url('/hrm/designations'), 'perm' => 'hrm.view'],
                ['label' => 'Shifts', 'url' => url('/hrm/shifts'), 'perm' => 'hrm.view'],
                ['label' => 'Document Types', 'url' => url('/hrm/document-types'), 'perm' => 'hrm.manage'],
              ]],
              'Attendance & Leave' => ['items' => [
                ['label' => 'Attendance Records', 'url' => url('/hrm/attendance'), 'perm' => 'hrm.view'],
                ['label' => 'Attendance Report', 'url' => url('/hrm/attendance/report'), 'perm' => 'hrm.view'],
                ['label' => 'Leave Applications', 'url' => url('/hrm/leave-applications'), 'perm' => 'hrm.view'],
                ['label' => 'Leave Balance', 'url' => url('/hrm/leave-balance'), 'perm' => 'hrm.view'],
                ['label' => 'Leave Types', 'url' => url('/hrm/leave-types'), 'perm' => 'hrm.view'],
                ['label' => 'Holidays', 'url' => url('/hrm/holidays'), 'perm' => 'hrm.view'],
              ]],
              'Payroll & Benefits' => ['items' => [
                ['label' => 'Payroll', 'url' => url('/hrm/payrolls'), 'perm' => 'hrm.view'],
                ['label' => 'Allowances', 'url' => url('/hrm/allowances'), 'perm' => 'hrm.view'],
                ['label' => 'Deductions', 'url' => url('/hrm/deductions'), 'perm' => 'hrm.view'],
                ['label' => 'Staff Loans', 'url' => url('/hrm/staff-loans'), 'perm' => 'hrm.view'],
              ]],
              'Payroll Setup' => ['items' => [
                ['label' => 'Allowance Types', 'url' => url('/hrm/allowance-types'), 'perm' => 'hrm.manage'],
                ['label' => 'Deduction Types', 'url' => url('/hrm/deduction-types'), 'perm' => 'hrm.manage'],
                ['label' => 'Staff Loan Types', 'url' => url('/hrm/staff-loan-types'), 'perm' => 'hrm.manage'],
              ]],
              'Employee Relations' => ['items' => [
                ['label' => 'Awards', 'url' => url('/hrm/awards'), 'perm' => 'hrm.view'],
                ['label' => 'Complaints', 'url' => url('/hrm/complaints'), 'perm' => 'hrm.view'],
                ['label' => 'Warnings', 'url' => url('/hrm/warnings'), 'perm' => 'hrm.view'],
              ]],
              'Employee Relations Setup' => ['items' => [
                ['label' => 'Award Types', 'url' => url('/hrm/award-types'), 'perm' => 'hrm.manage'],
                ['label' => 'Complaint Types', 'url' => url('/hrm/complaint-types'), 'perm' => 'hrm.manage'],
                ['label' => 'Warning Types', 'url' => url('/hrm/warning-types'), 'perm' => 'hrm.manage'],
              ]],
              'Career Management' => ['items' => [
                ['label' => 'Promotions', 'url' => url('/hrm/promotions'), 'perm' => 'hrm.view'],
                ['label' => 'Transfers', 'url' => url('/hrm/transfers'), 'perm' => 'hrm.view'],
                ['label' => 'Terminations', 'url' => url('/hrm/terminations'), 'perm' => 'hrm.view'],
              ]],
              'Career Management Setup' => ['items' => [
                ['label' => 'Termination Types', 'url' => url('/hrm/termination-types'), 'perm' => 'hrm.manage'],
              ]],
              'Communications' => ['items' => [
                ['label' => 'Announcements', 'url' => url('/hrm/announcements'), 'perm' => 'hrm.view'],
                ['label' => 'Events', 'url' => url('/hrm/events'), 'perm' => 'hrm.view'],
                ['label' => 'Zoom Meetings', 'url' => url('/hrm/zoom-meetings'), 'perm' => 'hrm.view'],
              ]],
              'Communications Setup' => ['items' => [
                ['label' => 'Announcement Categories', 'url' => url('/hrm/announcement-categories'), 'perm' => 'hrm.manage'],
                ['label' => 'Event Types', 'url' => url('/hrm/event-types'), 'perm' => 'hrm.manage'],
              ]],
              'Performance' => ['items' => [
                ['label' => 'Employee Goals', 'url' => url('/performance/employee-goals'), 'perm' => 'performance.view'],
                ['label' => 'Employee Reviews', 'url' => url('/performance/employee-reviews'), 'perm' => 'performance.view'],
                ['label' => 'Review Cycles', 'url' => url('/performance/review-cycles'), 'perm' => 'performance.view'],
              ]],
              'Performance Setup' => ['items' => [
                ['label' => 'Goal Types', 'url' => url('/performance/goal-types'), 'perm' => 'performance.manage'],
                ['label' => 'Indicator Categories', 'url' => url('/performance/indicator-categories'), 'perm' => 'performance.manage'],
                ['label' => 'Indicators', 'url' => url('/performance/indicators'), 'perm' => 'performance.manage'],
              ]],
              'Recruitment' => ['items' => [
                ['label' => 'Job Postings', 'url' => url('/recruitment/job-postings'), 'perm' => 'recruitment.view'],
                ['label' => 'Candidates', 'url' => url('/recruitment/candidates'), 'perm' => 'recruitment.view'],
                ['label' => 'Interviews', 'url' => url('/recruitment/interviews'), 'perm' => 'recruitment.view'],
                ['label' => 'Interview Feedback', 'url' => url('/recruitment/interview-feedback'), 'perm' => 'recruitment.view'],
                ['label' => 'Candidate Assessments', 'url' => url('/recruitment/candidate-assessments'), 'perm' => 'recruitment.view'],
                ['label' => 'Offers', 'url' => url('/recruitment/offers'), 'perm' => 'recruitment.view'],
                ['label' => 'Onboarding', 'url' => url('/recruitment/candidate-onboardings'), 'perm' => 'recruitment.view'],
              ]],
              'Recruitment Setup' => ['items' => [
                ['label' => 'Interview Rounds', 'url' => url('/recruitment/interview-rounds'), 'perm' => 'recruitment.view'],
                ['label' => 'Checklist Items', 'url' => url('/recruitment/checklist-items'), 'perm' => 'recruitment.view'],
                ['label' => 'Onboarding Checklists', 'url' => url('/recruitment/onboarding-checklists'), 'perm' => 'recruitment.manage'],
                ['label' => 'Offer Letter Templates', 'url' => url('/recruitment/offer-letter-templates'), 'perm' => 'recruitment.manage'],
                ['label' => 'Job Types', 'url' => url('/recruitment/job-types'), 'perm' => 'recruitment.manage'],
                ['label' => 'Job Locations', 'url' => url('/recruitment/job-locations'), 'perm' => 'recruitment.manage'],
                ['label' => 'Candidate Sources', 'url' => url('/recruitment/candidate-sources'), 'perm' => 'recruitment.manage'],
                ['label' => 'Interview Types', 'url' => url('/recruitment/interview-types'), 'perm' => 'recruitment.manage'],
                ['label' => 'Application Questions', 'url' => url('/recruitment/custom-questions'), 'perm' => 'recruitment.manage'],
                ['label' => 'System Setup', 'url' => url('/recruitment/settings'), 'perm' => 'recruitment.manage'],
              ]],
              'Training' => ['items' => [
                ['label' => 'Trainings', 'url' => url('/training/trainings'), 'perm' => 'training.view'],
                ['label' => 'Trainers', 'url' => url('/training/trainers'), 'perm' => 'training.manage'],
              ]],
              'Training Setup' => ['items' => [
                ['label' => 'Training Types', 'url' => url('/training/types'), 'perm' => 'training.manage'],
              ]],
            ]],
            'Agents & Referrals' => ['icon' => 'mdi-account-star', 'groups' => [
              'Administrative' => ['items' => [
                ['label' => 'Agent Commissions', 'url' => url('/commissions'), 'perm' => 'commissions.manage'],
                ['label' => 'Agent Submissions', 'url' => url('/commissions/submissions'), 'perm' => 'commissions.manage'],
              ]],
              'My Referrals' => ['items' => [
                ['label' => 'Referral Dashboard', 'url' => url('/my/referrals'), 'perm' => 'referrals.submit'],
                ['label' => 'Referral History', 'url' => url('/my/referrals/list'), 'perm' => 'referrals.submit'],
                ['label' => "My Clients' Loans", 'url' => url('/my/loans'), 'perm' => 'referrals.submit'],
                ['label' => 'My Commissions', 'url' => url('/my/commissions'), 'perm' => 'referrals.submit'],
              ]],
            ]],
            'Governance & Control' => ['icon' => 'mdi-clipboard-check', 'groups' => [
              'Approvals' => ['items' => [
                ['label' => 'My Approvals', 'url' => url('/approvals'), 'perm' => 'approvals.view'],
              ]],
              'Delegation' => ['items' => [
                ['label' => 'Delegations', 'url' => url('/delegations'), 'perm' => 'delegations.view'],
              ]],
              'Exception Management' => ['items' => [
                ['label' => 'Exceptions', 'url' => url('/exceptions'), 'perm' => 'exceptions.view'],
              ]],
              'SLA & Escalations' => ['items' => [
                ['label' => 'SLA Policies', 'url' => url('/sla/policies'), 'perm' => 'sla.view'],
              ]],
              'Data Quality' => ['items' => [
                ['label' => 'Data Quality', 'url' => url('/data-quality'), 'perm' => 'data_quality.view'],
                ['label' => 'Data Quality Rules', 'url' => url('/data-quality/rules'), 'perm' => 'data_quality.view'],
              ]],
              'Data Governance' => ['items' => [
                ['label' => 'Retention Policies', 'url' => url('/retention'), 'perm' => 'retention.view'],
                ['label' => 'Legal Holds', 'url' => url('/retention/holds'), 'perm' => 'retention.view'],
              ]],
            ]],
            'Cyber Security' => ['icon' => 'mdi-shield', 'groups' => [
              'Monitoring' => ['items' => [
                ['label' => 'Security Overview', 'url' => url('/security/overview'), 'perm' => 'security.view'],
                ['label' => 'Security Events', 'url' => url('/security/events'), 'perm' => 'security.view'],
                ['label' => 'Security Incidents', 'url' => url('/security/incidents'), 'perm' => 'security.view'],
              ]],
              'Protection' => ['items' => [
                ['label' => 'Blocked Sources', 'url' => url('/security/blocked-sources'), 'perm' => 'security.view'],
                ['label' => 'Security Rules', 'url' => url('/security/rules'), 'perm' => 'security.view'],
              ]],
            ]],
            'Intelligence' => ['icon' => 'mdi-lightbulb-on', 'groups' => [
              'Management' => ['items' => [
                ['label' => 'Decision Intelligence', 'url' => url('/intelligence'), 'perm' => 'intelligence.view'],
              ]],
              'Marketing' => ['items' => [
                ['label' => 'Social & Web Analytics', 'url' => url('/social-analytics'), 'perm' => 'social_analytics.view'],
              ]],
            ]],
            'System & Platform' => ['icon' => 'mdi-server', 'groups' => [
              'System Health' => ['items' => [
                ['label' => 'System Health', 'url' => url('/health'), 'perm' => 'health.view'],
              ]],
              'Error Management' => ['items' => [
                ['label' => 'Error Tracking', 'url' => url('/errors'), 'perm' => 'errors.view'],
              ]],
              'Deployment & Features' => ['items' => [
                ['label' => 'Feature Flags', 'url' => url('/feature-flags'), 'perm' => 'feature_flags.view'],
              ]],
              'Business Continuity' => ['items' => [
                ['label' => 'Business Continuity', 'url' => url('/continuity'), 'perm' => 'continuity.view'],
                ['label' => 'Continuity Plans', 'url' => url('/continuity/plans'), 'perm' => 'continuity.view'],
              ]],
              'Integrations' => ['items' => [
                ['label' => 'Debit Order API Settings', 'url' => url('/collexia/settings'), 'perm' => 'collections.debit_orders'],
              ]],
            ]],
            'Support' => ['icon' => 'mdi-ticket', 'groups' => [
              '_flat' => ['items' => [
                ['label' => 'Support Tickets', 'url' => url('/tickets'), 'perm' => 'tickets.view'],
              ]],
            ]],
            'Administration' => ['icon' => 'mdi-settings', 'groups' => [
              'User & Access Management' => ['items' => [
                ['label' => 'Users', 'url' => url('/settings/users'), 'perm' => 'admin.users'],
                ['label' => 'Roles', 'url' => url('/settings/roles'), 'perm' => 'admin.roles'],
                ['label' => 'Permissions', 'url' => url('/settings/permissions'), 'perm' => 'admin.permissions'],
                ['label' => 'Branch Login IP Restrictions', 'url' => url('/settings/branch-ip-ranges'), 'perm' => 'admin.system_settings'],
              ]],
              'Organization' => ['items' => [
                ['label' => 'Company Settings', 'url' => url('/settings/company'), 'perm' => 'admin.company'],
                ['label' => 'Branches', 'url' => url('/branches'), 'perm' => 'admin.system_settings'],
                ['label' => 'Intake Sources', 'url' => url('/settings/intake-sources'), 'perm' => 'admin.system_settings'],
              ]],
              'System Configuration' => ['items' => [
                ['label' => 'AI Settings', 'url' => url('/settings/ai'), 'perm' => 'admin.system_settings'],
                ['label' => 'Analytics Settings', 'url' => url('/settings/social-analytics'), 'perm' => 'social_analytics.manage'],
              ]],
              'Audit' => ['items' => [
                ['label' => 'Activity Log', 'url' => url('/settings/audit-log'), 'perm' => 'admin.audit'],
              ]],
              'Communications' => ['items' => [
                ['label' => 'SMS Queue', 'url' => url('/notifications/sms'), 'perm' => 'notifications.view'],
                ['label' => 'Email Queue', 'url' => url('/notifications/email'), 'perm' => 'notifications.view'],
                ['label' => 'Notification Templates', 'url' => url('/notifications/templates'), 'perm' => 'notifications.templates'],
                ['label' => 'Notification Settings', 'url' => url('/notifications/settings'), 'perm' => 'notifications.settings'],
              ]],
            ]],
            'My Workspace' => ['icon' => 'mdi-briefcase', 'groups' => [
              'My HR' => ['items' => [
                ['label' => 'My Leave', 'url' => url('/my/leave'), 'perm' => 'dashboard.view'],
                ['label' => 'My Leave Balance', 'url' => url('/my/leave/balance'), 'perm' => 'dashboard.view'],
                ['label' => 'My Payslips', 'url' => url('/my/payslips'), 'perm' => 'dashboard.view'],
                ['label' => 'My Attendance', 'url' => url('/my/attendance'), 'perm' => 'dashboard.view'],
              ]],
            ]],
          ];

          // Drop items the current user's role(s) can't reach, then drop
          // any subgroup left with zero visible items, then drop any
          // module left with zero visible subgroups -- keeps the sidebar
          // honest about what's actually clickable instead of just what
          // exists. $menus (filtered) is reused below for the breadcrumb
          // lookup, so an item never appears there unless the current
          // user could actually reach it via the sidebar too.
          foreach ($menus as $menuName => $menu) {
            foreach ($menu['groups'] as $groupName => $group) {
              $menus[$menuName]['groups'][$groupName]['items'] = array_values(array_filter(
                  $group['items'],
                  fn ($item) => Auth::can($item['perm'])
              ));
              if (empty($menus[$menuName]['groups'][$groupName]['items'])) {
                  unset($menus[$menuName]['groups'][$groupName]);
              }
            }
            if (empty($menus[$menuName]['groups'])) {
                unset($menus[$menuName]);
            }
          }
          ?>

          <?php foreach ($menus as $menuName => $menu): ?>
            <li class="sidebar-item">
              <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false" title="<?= e($menuName) ?>">
                <i class="mdi <?= $menu['icon'] ?>"></i>
                <span class="hide-menu"><?= e($menuName) ?></span>
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <?php foreach ($menu['groups'] as $groupName => $group): ?>
                  <?php if ($groupName === '_flat'): ?>
                    <?php foreach ($group['items'] as $item): ?>
                      <li class="sidebar-item">
                        <a href="<?= $item['url'] ?? 'javascript:void(0)' ?>" class="sidebar-link <?= isset($item['url']) ? '' : 'disabled text-muted' ?>" title="<?= e($item['label']) ?>">
                          <i class="mdi mdi-adjust"></i>
                          <span class="hide-menu"><?= e($item['label']) ?><?= isset($item['url']) ? '' : ' <small>(soon)</small>' ?></span>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <li class="sidebar-item">
                      <a class="sidebar-link has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false" title="<?= e($groupName) ?>">
                        <span class="hide-menu"><?= e($groupName) ?></span>
                      </a>
                      <ul aria-expanded="false" class="collapse second-level">
                        <?php foreach ($group['items'] as $item): ?>
                          <li class="sidebar-item">
                            <a href="<?= $item['url'] ?? 'javascript:void(0)' ?>" class="sidebar-link <?= isset($item['url']) ? '' : 'disabled text-muted' ?>" title="<?= e($item['label']) ?>">
                              <span class="hide-menu"><?= e($item['label']) ?><?= isset($item['url']) ? '' : ' <small>(soon)</small>' ?></span>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endforeach; ?>
              <li class="sidebar-item">
                <a
                  class="sidebar-link waves-effect waves-dark sidebar-link"
                  href="<?= url('/logout') ?>"
                  aria-expanded="false"
                  ><i class="mdi mdi-directions"></i
                  ><span class="hide-menu">Log Out</span></a
                >
              </li>
        </ul>
      </nav>
    </div>

    <div class="sidebar-footer">
          <!-- item-->
          <a
            href="<?= url('/settings/company') ?>"
            class="link"
            data-bs-toggle="tooltip"
            data-bs-placement="top"
            title="Settings"
            ><i class="ti-settings"></i
          ></a>
          <!-- item-->
          <a
            href="<?= url('/notifications/email') ?>"
            class="link"
            data-bs-toggle="tooltip"
            data-bs-placement="top"
            title="Email"
            ><i class="mdi mdi-gmail"></i
          ></a>
          <!-- item-->
          <a
            href="<?= url('/logout') ?>"
            class="link"
            data-bs-toggle="tooltip"
            data-bs-placement="top"
            title="Logout"
            ><i class="mdi mdi-power"></i
          ></a>
        </div>
  </aside>

  <div class="page-wrapper">

  <?php $activeSupportSession = \App\Core\Auth::activeSupportSession(); ?>
  <?php if ($activeSupportSession): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-0 rounded-0" style="border-left: 5px solid #f1b44c;">
      <div>
        <i class="mdi mdi-key-variant"></i>
        <strong>Support Session Active</strong> &mdash; you have scoped, audited access to
        <a href="<?= url('/tickets/' . $activeSupportSession['ticket_id']) ?>">ticket #<?= (int) $activeSupportSession['ticket_id'] ?></a>'s branch data.
        Expires <?= e($activeSupportSession['expires_at']) ?>.
      </div>
      <form method="post" action="<?= url('/tickets/support-session/end') ?>" class="mb-0" onsubmit="return confirmSubmit(this, 'End your active support session now?');">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-dark" type="submit">End Session</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (\App\Core\Auth::isImpersonating()): ?>
    <?php $impersonator = \App\Core\Auth::impersonator(); ?>
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2 mb-0 rounded-0" style="border-left: 5px solid #dc3545;">
      <div>
        <i class="mdi mdi-account-switch"></i>
        <strong><?= e($impersonator['name'] ?? '') ?></strong>, you are currently logged in as
        <strong><?= e(\App\Core\Auth::user()['name'] ?? '') ?></strong>.
      </div>
      <form method="post" action="<?= url('/settings/users/stop-impersonation') ?>" class="mb-0">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-dark" type="submit">Return to My Account</button>
      </form>
    </div>
  <?php endif; ?>

  <?php
    // Shown on the dashboard only (not every page -- avoids nagging), and
    // only once per PHP session so navigating back to the dashboard later
    // in the same visit doesn't repeat it.
    $showDraftNotice = false;
    if (\App\Core\Auth::check() && str_contains($_SERVER['REQUEST_URI'] ?? '', '/dashboard') && empty($_SESSION['_drafts_notice_shown'])) {
        $unfinishedDraftCount = (new \App\Models\FormDraft())->countUnfinishedForUser((int) (\App\Core\Auth::user()['id'] ?? 0));
        if ($unfinishedDraftCount > 0) {
            $showDraftNotice = true;
            $_SESSION['_drafts_notice_shown'] = true;
        }
    }
  ?>
  <?php if ($showDraftNotice): ?>
    <div class="alert alert-info alert-dismissible d-flex justify-content-between align-items-center flex-wrap gap-2 mb-0 rounded-0">
      <div>
        <i class="mdi mdi-history"></i>
        You have <?= (int) $unfinishedDraftCount ?> unfinished draft<?= $unfinishedDraftCount === 1 ? '' : 's' ?>.
        <a href="<?= url('/my/drafts') ?>">Open Draft Centre</a>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <?php
    // Auto-derive the breadcrumb trail from the same (already
    // permission-filtered) $menus structure the sidebar just rendered,
    // instead of hand-maintaining a separate breadcrumb per page. An
    // exact match (including query string, e.g. distinguishing "All
    // Applications" from "New Applications") wins outright; a path-only
    // match is kept as a fallback. Pages whose URL isn't in the menu at
    // all (detail/edit screens with a dynamic ID) fall back to today's
    // plain "Home > $title".
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    $currentPath = parse_url($currentUri, PHP_URL_PATH) ?: '';
    $breadcrumbTrail = null;
    $breadcrumbFallback = null;
    foreach ($menus as $menuName => $menu) {
        foreach ($menu['groups'] as $groupName => $group) {
            foreach ($group['items'] as $item) {
                if (!isset($item['url'])) {
                    continue;
                }
                if ($item['url'] === $currentUri) {
                    $breadcrumbTrail = [$menuName, $groupName === '_flat' ? null : $groupName];
                    break 3;
                }
                if ($breadcrumbFallback === null && parse_url($item['url'], PHP_URL_PATH) === $currentPath) {
                    $breadcrumbFallback = [$menuName, $groupName === '_flat' ? null : $groupName];
                }
            }
        }
    }
    $breadcrumbTrail = $breadcrumbTrail ?? $breadcrumbFallback;
  ?>
  <div class="row page-titles">
          <div class="col-md-5 col-12 align-self-center">
            <ol class="breadcrumb mb-0">
              <li class="breadcrumb-item">
                <a href="<?= url(Auth::homePath()) ?>">Home</a>
              </li>
              <?php if ($breadcrumbTrail): ?>
                <li class="breadcrumb-item"><?= e($breadcrumbTrail[0]) ?></li>
                <?php if ($breadcrumbTrail[1]): ?>
                  <li class="breadcrumb-item"><?= e($breadcrumbTrail[1]) ?></li>
                <?php endif; ?>
              <?php endif; ?>
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


    <div class="container-fluid" id="pageContent">
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

<script>window.APP_BASE_URL = <?= json_encode(url('')) ?>;</script>
<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/select2/dist/js/select2.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('dist/js/app-ui.js') ?>"></script>
<script src="<?= asset('dist/js/submit-guard.js') ?>"></script>
<script src="<?= asset('dist/js/form-draft.js') ?>"></script>
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
<script src="<?= asset('dist/js/app-select2-init.js') ?>"></script>

<script>
  $('.preloader').fadeOut();

  if (typeof feather !== 'undefined') {
    feather.replace();
  }
</script>

</body>
</html>