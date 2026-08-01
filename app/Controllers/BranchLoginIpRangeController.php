<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BranchLoginIpRange;

/**
 * Manages the per-branch login IP allow-list (App\Core\Auth::ipAllowed()).
 * A branch with zero ranges here is unrestricted -- adding the first range
 * is what activates restriction for that branch, so this screen is both
 * "view what's configured" and "the switch that turns it on".
 */
class BranchLoginIpRangeController extends Controller
{
    private BranchLoginIpRange $ranges;

    public function __construct()
    {
        $this->ranges = new BranchLoginIpRange();
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('settings/branch_ip_ranges/index', [
            'title' => 'Branch Login IP Restrictions',
            'branches' => $this->ranges->allBranchesWithRanges(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/branch-ip-ranges');
            return;
        }

        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $ipRange = trim($_POST['ip_range'] ?? '');
        $label = trim($_POST['label'] ?? '') ?: null;

        $errors = [];
        if (!$branchId) {
            $errors['branch_id'] = 'Select a branch.';
        }
        if ($ipRange === '') {
            $errors['ip_range'] = 'Enter an IP address or CIDR range.';
        } elseif (!self::isValidRange($ipRange)) {
            $errors['ip_range'] = 'Enter a valid IPv4 address (e.g. 41.182.1.5) or CIDR range (e.g. 41.182.1.0/24).';
        }

        if (!empty($errors)) {
            $this->view('settings/branch_ip_ranges/index', [
                'title' => 'Branch Login IP Restrictions',
                'branches' => $this->ranges->allBranchesWithRanges(),
                'errors' => $errors,
                'old' => $_POST,
            ]);
            return;
        }

        $this->ranges->create([
            'branch_id' => $branchId,
            'ip_range' => $ipRange,
            'label' => $label,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Security', 'Added login IP range ' . $ipRange . ' for branch #' . $branchId);
        Session::flash('success', 'IP range added.');
        $this->redirect('/settings/branch-ip-ranges');
    }

    public function delete(string $id): void
    {
        Auth::authorize('admin.system_settings');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/branch-ip-ranges');
            return;
        }

        $this->ranges->delete($id);
        Audit::log('Delete', 'Security', 'Removed login IP range #' . $id);
        Session::flash('success', 'IP range removed.');
        $this->redirect('/settings/branch-ip-ranges');
    }

    private static function isValidRange(string $range): bool
    {
        if (!str_contains($range, '/')) {
            return filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
        if ($bits === null || !ctype_digit($bits)) {
            return false;
        }
        return filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $bits >= 0 && (int) $bits <= 32;
    }
}
