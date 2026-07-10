<?php
use App\Core\Session;

$error = class_exists(Session::class) ? Session::flash('error') : null;
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <title>Login | Micro Lending System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>">
    <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
</head>

<body>
<div class="main-wrapper">

    <div class="preloader">
        <div class="spinner-border text-info" role="status"></div>
    </div>

    <div class="auth-wrapper d-flex no-block justify-content-center align-items-center"
         style="background:url('<?= asset('assets/images/background/login-register.jpg') ?>') no-repeat center center; background-size:cover; min-height:100vh;">

        <div class="auth-box p-4 bg-white rounded shadow">

            <div id="loginform">

                <div class="logo text-center">
                    <img src="<?= asset('assets/images/logo-icon.png') ?>" alt="logo" style="height:48px" class="mb-2">
                    <h3 class="box-title mb-3">Sign In</h3>
                    <p class="text-muted">Micro Lending System</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form class="form-horizontal mt-3 form-material" method="post" action="<?= url('/login') ?>">

                    <?= csrf_field() ?>

                    <div class="form-group mb-3">
                        <input class="form-control" name="login" type="text" required placeholder="Username or Email">
                    </div>

                    <div class="form-group mb-4">
                        <input class="form-control" name="password" type="password" required placeholder="Password">
                    </div>

                    <div class="form-group">
                        <div class="d-flex">
                            <div class="checkbox checkbox-info pt-0">
                                <input id="remember" name="remember" type="checkbox" value="1">
                                <label for="remember"> Remember me </label>
                            </div>

                            <div class="ms-auto">
                                <a href="javascript:void(0)" id="to-recover" class="link font-weight-medium">
                                    <i class="fa fa-lock me-1"></i> Forgot pwd?
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="form-group text-center mt-4 mb-3">
                        <button class="btn btn-info d-block w-100 waves-effect waves-light" type="submit">
                            Log In
                        </button>
                    </div>

                    <div class="form-group mb-0 mt-4">
                        <div class="col-sm-12 justify-content-center d-flex">
                            <p class="small text-muted">Demo: admin@example.com / Admin@123</p>
                        </div>
                    </div>

                </form>
            </div>

            <div id="recoverform" style="display:none">
                <div class="logo">
                    <h3 class="font-weight-medium mb-3">Recover Password</h3>
                    <span class="text-muted">Password recovery will be implemented later.</span>
                </div>

                <div class="row mt-3 form-material">
                    <div class="col-12">
                        <a href="javascript:void(0)" id="back-login" class="btn d-block w-100 btn-primary text-uppercase">
                            Back to Login
                        </a>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>

<script>
$(function(){
    $('.preloader').fadeOut();

    $('#to-recover').on('click', function(){
        $('#loginform').slideUp();
        $('#recoverform').fadeIn();
    });

    $('#back-login').on('click', function(){
        $('#recoverform').hide();
        $('#loginform').fadeIn();
    });
});
</script>

</body>
</html>