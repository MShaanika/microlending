<?php
$brandName = $company['brand_name'] ?: ($company['company_name'] ?? '') ?: 'Careers';
$faviconUrl = !empty($company['favicon']) ? asset($company['favicon']) : asset('assets/images/logo-icon.png');
$primaryColor = $company['primary_color'] ?? '#25a9e0';
$j = $job;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($j['title']) ?> | <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
  <link href="<?= asset('dist/css/style.min.css') ?>" rel="stylesheet">
  <style>.careers-header { background-color: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>; color: #fff; }</style>
</head>
<body>
<div class="careers-header py-3 mb-4">
  <div class="container">
    <a href="<?= url('/careers') ?>" class="text-white text-decoration-none">&larr; Back to all positions</a>
  </div>
</div>

<div class="container pb-5">
  <div class="row">
    <div class="col-md-7">
      <h3><?= e($j['title']) ?></h3>
      <p class="text-muted"><?= e($j['job_type_name'] ?: 'General') ?> &middot; <?= e($j['location_name'] ?: 'Location TBD') ?> &middot; <?= (int) $j['position'] ?> opening(s)</p>
      <?php if ($j['min_salary'] !== null || $j['max_salary'] !== null): ?>
        <p><strong>Salary:</strong> <?= $j['min_salary'] !== null ? format_money($j['min_salary']) : '—' ?> &ndash; <?= $j['max_salary'] !== null ? format_money($j['max_salary']) : '—' ?></p>
      <?php endif; ?>
      <?php if (!empty($j['description'])): ?><h6 class="mt-4">About the Role</h6><p><?= nl2br(e($j['description'])) ?></p><?php endif; ?>
      <?php if (!empty($j['requirements'])): ?><h6 class="mt-4">Requirements</h6><p><?= nl2br(e($j['requirements'])) ?></p><?php endif; ?>
      <?php if (!empty($j['skills'])): ?><h6 class="mt-4">Skills</h6><p><?= e($j['skills']) ?></p><?php endif; ?>
      <?php if (!empty($j['benefits'])): ?><h6 class="mt-4">Benefits</h6><p><?= nl2br(e($j['benefits'])) ?></p><?php endif; ?>
      <?php if ($j['application_deadline']): ?><p class="text-muted small">Applications close <?= e(date('d M Y', strtotime($j['application_deadline']))) ?></p><?php endif; ?>
    </div>

    <div class="col-md-5">
      <?php if (!empty($settings['application_tips'])): ?>
        <div class="card mb-3">
          <div class="card-body">
            <h6 class="card-title"><i class="mdi mdi-lightbulb-on-outline"></i> Application Tips</h6>
            <p class="mb-0 small"><?= nl2br(e($settings['application_tips'])) ?></p>
          </div>
        </div>
      <?php endif; ?>
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Apply for this Position</h5>

          <?php if (!empty($errors['_general'])): ?><div class="alert alert-danger"><?= e($errors['_general']) ?></div><?php endif; ?>

          <form method="post" action="<?= url('/careers/' . $j['posting_code'] . '/apply') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="row">
              <div class="col-6 mb-2">
                <label class="form-label">First Name *</label>
                <input type="text" name="first_name" class="form-control form-control-sm <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" value="<?= e($old['first_name'] ?? '') ?>">
                <?php if (isset($errors['first_name'])): ?><div class="invalid-feedback"><?= e($errors['first_name']) ?></div><?php endif; ?>
              </div>
              <div class="col-6 mb-2">
                <label class="form-label">Last Name *</label>
                <input type="text" name="last_name" class="form-control form-control-sm <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" value="<?= e($old['last_name'] ?? '') ?>">
                <?php if (isset($errors['last_name'])): ?><div class="invalid-feedback"><?= e($errors['last_name']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Email *</label>
              <input type="email" name="email" class="form-control form-control-sm <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e($old['email'] ?? '') ?>">
              <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="mb-2">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($old['phone'] ?? '') ?>">
            </div>
            <div class="row">
              <div class="col-6 mb-2">
                <label class="form-label">Experience (years)</label>
                <input type="number" step="0.5" min="0" name="experience_years" class="form-control form-control-sm" value="<?= e($old['experience_years'] ?? '') ?>">
              </div>
              <div class="col-6 mb-2">
                <label class="form-label">Expected Salary</label>
                <input type="number" step="0.01" min="0" name="expected_salary" class="form-control form-control-sm" value="<?= e($old['expected_salary'] ?? '') ?>">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Current Company / Position</label>
              <div class="row">
                <div class="col-6"><input type="text" name="current_company" class="form-control form-control-sm" placeholder="Company" value="<?= e($old['current_company'] ?? '') ?>"></div>
                <div class="col-6"><input type="text" name="current_position" class="form-control form-control-sm" placeholder="Position" value="<?= e($old['current_position'] ?? '') ?>"></div>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Skills</label>
              <input type="text" name="skills" class="form-control form-control-sm" value="<?= e($old['skills'] ?? '') ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">LinkedIn / Portfolio URL</label>
              <input type="text" name="linkedin_url" class="form-control form-control-sm mb-1" placeholder="LinkedIn" value="<?= e($old['linkedin_url'] ?? '') ?>">
              <input type="text" name="portfolio_url" class="form-control form-control-sm" placeholder="Portfolio" value="<?= e($old['portfolio_url'] ?? '') ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Resume (PDF/JPG/PNG, max 5MB)</label>
              <input type="file" name="resume" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="mb-2">
              <label class="form-label">Cover Letter</label>
              <input type="file" name="cover_letter" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
            </div>

            <?php if (!empty($questions)): ?>
              <hr>
              <?php foreach ($questions as $q): ?>
                <div class="mb-2">
                  <label class="form-label"><?= e($q['question']) ?><?= $q['is_required'] ? ' *' : '' ?></label>
                  <?php if ($q['type'] === 'textarea'): ?>
                    <textarea name="question_<?= (int) $q['id'] ?>" class="form-control form-control-sm" rows="2" <?= $q['is_required'] ? 'required' : '' ?>></textarea>
                  <?php elseif (in_array($q['type'], ['select', 'radio', 'checkbox'], true) && !empty($q['options'])): ?>
                    <select name="question_<?= (int) $q['id'] ?>" class="form-select form-select-sm" <?= $q['is_required'] ? 'required' : '' ?>>
                      <option value="">— Select —</option>
                      <?php foreach (explode("\n", $q['options']) as $opt): $opt = trim($opt); if ($opt === '') continue; ?>
                        <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($q['type'] === 'date'): ?>
                    <input type="date" name="question_<?= (int) $q['id'] ?>" class="form-control form-control-sm" <?= $q['is_required'] ? 'required' : '' ?>>
                  <?php elseif ($q['type'] === 'number'): ?>
                    <input type="number" name="question_<?= (int) $q['id'] ?>" class="form-control form-control-sm" <?= $q['is_required'] ? 'required' : '' ?>>
                  <?php else: ?>
                    <input type="text" name="question_<?= (int) $q['id'] ?>" class="form-control form-control-sm" <?= $q['is_required'] ? 'required' : '' ?>>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($j['terms_condition'])): ?>
              <div class="form-check my-2">
                <input type="checkbox" class="form-check-input" id="terms" required>
                <label class="form-check-label small" for="terms">I agree to the <a href="#termsText" data-bs-toggle="collapse">terms &amp; conditions</a></label>
                <div class="collapse small text-muted" id="termsText"><?= nl2br(e($j['terms_condition'])) ?></div>
              </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-info w-100 mt-2">Submit Application</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= asset('assets/libs/jquery/dist/jquery.min.js') ?>"></script>
<script src="<?= asset('assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
