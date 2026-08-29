<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\DataQualityIssue;
use App\Services\DataQualityService;

/** Data Quality dashboard (Part 28-33) -- proactive detection, never automatic correction. */
class DataQualityController extends Controller
{
    private DataQualityIssue $issues;

    public function __construct()
    {
        $this->issues = new DataQualityIssue();
    }

    public function index(): void
    {
        Auth::authorize('data_quality.view');

        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'dimension' => trim((string) ($_GET['dimension'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->view('data_quality/index', [
            'title' => 'Data Quality',
            'counts' => $this->issues->counts(),
            'byDimension' => $this->issues->openCountsByDimension(),
            'issues' => $this->issues->paginated($filters, $page),
            'filters' => $filters,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('data_quality.view');
        $issue = $this->issues->find((int) $id);
        if (!$issue) {
            Session::flash('error', 'Issue not found.');
            $this->redirect('/data-quality');
            return;
        }
        $this->view('data_quality/show', ['title' => 'Data Quality Issue #' . $id, 'issue' => $issue]);
    }

    /** RESOLVED / FALSE_POSITIVE / REVIEWING -- always requires a note (Part 27: root cause / resolution captured, never a silent status flip). */
    public function updateStatus(string $id): void
    {
        Auth::authorize('data_quality.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/data-quality/' . $id);
            return;
        }

        $status = in_array($_POST['status'] ?? '', ['REVIEWING', 'CONFIRMED', 'RESOLVED', 'FALSE_POSITIVE'], true) ? $_POST['status'] : null;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!$status || (in_array($status, ['RESOLVED', 'FALSE_POSITIVE'], true) && $notes === '')) {
            Session::flash('error', 'A note is required when resolving or marking an issue as a false positive.');
            $this->redirect('/data-quality/' . $id);
            return;
        }

        $this->issues->markStatus($id, $status, Auth::user()['id'] ?? null, $notes ?: null);
        Audit::log('Update', 'Quality', "Data quality issue #$id marked $status" . ($notes ? ": $notes" : ''), ['issue_id' => $id]);

        Session::flash('success', 'Updated.');
        $this->redirect('/data-quality/' . $id);
    }

    /** Runs every active rule immediately -- the same scan bin/scan_data_quality.php runs on schedule, exposed here so an admin doesn't have to wait for the next sweep to see fresh results. */
    public function runScan(): void
    {
        Auth::authorize('data_quality.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/data-quality');
            return;
        }
        $summary = DataQualityService::scan();
        $total = array_sum(array_column($summary, 'failing'));
        Session::flash('success', "Scan complete. $total issue(s) currently failing across " . count($summary) . ' active rule(s).');
        $this->redirect('/data-quality');
    }

    // -- Rule administration (edit-only, same pattern as Security Rules) --

    public function rules(): void
    {
        Auth::authorize('data_quality.view');
        $this->view('data_quality/rules', ['title' => 'Data Quality Rules', 'rules' => $this->issues->allRules()]);
    }

    public function updateRule(string $id): void
    {
        Auth::authorize('data_quality.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/data-quality/rules');
            return;
        }

        $rule = $this->issues->findRule($id);
        if (!$rule) {
            Session::flash('error', 'Rule not found.');
            $this->redirect('/data-quality/rules');
            return;
        }

        $severity = in_array($_POST['severity'] ?? '', ['Low', 'Medium', 'High', 'Critical'], true) ? $_POST['severity'] : $rule['severity'];

        $this->issues->updateRule($id, [
            'severity' => $severity,
            'auto_create_exception' => !empty($_POST['auto_create_exception']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Quality', "Updated data quality rule {$rule['rule_key']}", ['rule_id' => $id]);
        Session::flash('success', 'Rule updated.');
        $this->redirect('/data-quality/rules');
    }
}
