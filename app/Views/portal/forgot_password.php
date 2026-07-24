<?php
use App\Core\Session;
use App\Services\TurnstileService;

$error = class_exists(Session::class) ? Session::flash('error') : null;
$success = class_exists(Session::class) ? Session::flash('success') : null;
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <title>Forgot Password | Micro Lending System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>">
    <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
        .preloader { display: flex; align-items: center; justify-content: center; }
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
                <img src="<?= asset('assets/images/logo-icon.png') ?>" alt="logo" style="height:48px" class="mb-2">
                <h3 class="box-title mb-3">Forgot Password</h3>
                <p class="text-muted">Enter your username or email and we'll send you a link to reset your password.</p>
            </div>

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

            <form class="form-horizontal mt-3 form-material" method="post" action="<?= url('/portal/forgot-password') ?>">
                <?= csrf_field() ?>

                <div class="form-group mb-3">
                    <input class="form-control" name="login" type="text" required placeholder="Username or Email">
                </div>

                <div class="form-group mb-3">
                    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(TurnstileService::siteKey(), ENT_QUOTES, 'UTF-8') ?>"></div>
                </div>

                <div class="form-group text-center mt-4 mb-3">
                    <button class="btn btn-info d-block w-100 waves-effect waves-light" type="submit">
                        Send Reset Link
                    </button>
                </div>

                <div class="form-group mb-0 mt-2 text-center">
                    <a href="<?= url('/portal/login') ?>" class="small">
                        <i class="fa fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </form>
        </div>

    </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script>
$(function(){
    $('.preloader').fadeOut();
});
</script>

</body>
</html>
