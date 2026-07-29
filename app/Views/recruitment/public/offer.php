<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
$o = $offer;
$canRespond = in_array($o['status'], ['Sent', 'Negotiating'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Your Offer | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-7">
      <div class="card">
        <div class="card-body">
          <h4 class="card-title">Your Offer: <?= e($o['position']) ?></h4>
          <table class="table table-sm table-borderless mb-3">
            <tr><th class="text-muted" style="width:30%">Salary</th><td><?= format_money($o['salary']) ?></td></tr>
            <?php if ($o['bonus'] !== null): ?><tr><th class="text-muted">Bonus</th><td><?= format_money($o['bonus']) ?></td></tr><?php endif; ?>
            <tr><th class="text-muted">Start Date</th><td><?= e(date('d M Y', strtotime($o['start_date']))) ?></td></tr>
            <?php if ($o['expiration_date']): ?><tr><th class="text-muted">Expires</th><td><?= e(date('d M Y', strtotime($o['expiration_date']))) ?></td></tr><?php endif; ?>
          </table>
          <?php if (!empty($o['benefits'])): ?>
            <h6>Benefits</h6>
            <p><?= nl2br(e($o['benefits'])) ?></p>
          <?php endif; ?>

          <?php if ($canRespond): ?>
            <hr>
            <div class="d-flex gap-2 mb-3">
              <form method="post" action="<?= url('/careers/offer/' . $trackingId . '/' . $o['id']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="decision" value="Accepted">
                <button type="submit" class="btn btn-success">Accept Offer</button>
              </form>
              <form method="post" action="<?= url('/careers/offer/' . $trackingId . '/' . $o['id']) ?>" onsubmit="return confirm('Are you sure you want to decline this offer?');">
                <?= csrf_field() ?>
                <input type="hidden" name="decision" value="Declined">
                <button type="submit" class="btn btn-outline-danger">Decline Offer</button>
              </form>
            </div>
          <?php elseif ($o['status'] === 'Accepted'): ?>
            <div class="alert alert-success">You have accepted this offer. We look forward to working with you!</div>
          <?php elseif ($o['status'] === 'Declined'): ?>
            <div class="alert alert-secondary">You have declined this offer.</div>
          <?php else: ?>
            <div class="alert alert-secondary">This offer is currently in status: <?= e($o['status']) ?>.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
