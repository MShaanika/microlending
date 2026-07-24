<?php
use App\Core\Session;

$error = class_exists(Session::class) ? Session::flash('error') : null;
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <title>Reset Password | Micro Lending System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>">
    <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
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
                <h3 class="box-title mb-3">Reset Password</h3>
                <p class="text-muted">Choose a new password for your account.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form class="form-horizontal mt-3 form-material" method="post" action="<?= url('/portal/reset-password/' . $token) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group mb-3">
                    <input class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" name="password" type="password" required placeholder="New Password" minlength="8">
                    <?php if (isset($errors['password'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                </div>

                <div class="form-group mb-4">
                    <input class="form-control" name="password_confirmation" type="password" required placeholder="Confirm New Password" minlength="8">
                </div>

                <div class="form-group text-center mt-4 mb-3">
                    <button class="btn btn-info d-block w-100 waves-effect waves-light" type="submit">
                        Reset Password
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
