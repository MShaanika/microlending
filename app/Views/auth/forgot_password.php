<?php
use App\Core\Session;
use App\Models\Company;

$error = class_exists(Session::class) ? Session::flash('error') : null;
$success = class_exists(Session::class) ? Session::flash('success') : null;
$company = (new Company())->primary() ?: [];
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Micro Lending System';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : (!empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-icon.png'));
$loginLogoUrl = !empty($company['logo']) ? asset($company['logo']) : asset('assets/images/logo-icon.png');
$primaryColor = $company['primary_color'] ?? '#25a9e0';
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <title>Forgot Password | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
    <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
    <style>
        .btn-info { background-color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?> !important; border-color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?> !important; }
        .btn-info:hover { filter: brightness(90%); }
        .auth-box a.link { color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?> !important; }
    </style>
</head>

<body>
<div class="main-wrapper">

    <div class="preloader">
        <div class="spinner-border text-info" role="status"></div>
    </div>

    <div class="auth-wrapper d-flex no-block justify-content-center align-items-center"
         style="background:url('<?= asset('assets/images/background/login-register.jpg') ?>') no-repeat center center; background-size:cover; min-height:100vh;">

        <div class="auth-box p-4 bg-white rounded shadow">

            <div class="logo text-center">
                <img src="<?= $loginLogoUrl ?>" alt="logo" style="max-height: 204px; max-width: 260px; object-fit: contain;" class="mb-2">
            </div>

            <h3 class="font-weight-medium mb-2 text-center">Forgot Password</h3>
            <p class="text-muted small text-center">Enter your username or email and we'll send you a link to reset your password.</p>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success py-2">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form class="form-horizontal mt-3 form-material" method="post" action="<?= url('/forgot-password') ?>">
                <?= csrf_field() ?>

                <div class="form-group mb-3">
                    <input class="form-control" name="login" type="text" required placeholder="Username or Email">
                </div>

                <div class="form-group text-center mt-4 mb-3">
                    <button class="btn btn-info d-block w-100 waves-effect waves-light" type="submit">
                        Send Reset Link
                    </button>
                </div>

                <div class="form-group mb-0 mt-2 text-center">
                    <a href="<?= url('/login') ?>" class="link font-weight-medium">
                        <i class="fa fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </form>
        </div>

    </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>

<script>
$(function(){
    $('.preloader').fadeOut();
});
</script>

</body>
</html>
