<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\FeatureFlag;

/**
 * Feature Flag administration (Part 39-42). Create/edit only -- a flag's
 * rollout_type is a fixed set of code-known strategies
 * (FeatureFlagService::isEnabled()), not an author-a-new-type UI.
 * Every change is audited: a flag flipped in production is exactly the
 * kind of change that needs a "who and when" trail.
 */
class FeatureFlagController extends Controller
{
    private const ROLLOUT_TYPES = ['OFF', 'ALL_USERS', 'SPECIFIC_USERS', 'SPECIFIC_ROLES', 'SPECIFIC_BRANCHES', 'PERCENTAGE', 'INTERNAL_ONLY'];

    private FeatureFlag $flags;

    public function __construct()
    {
        $this->flags = new FeatureFlag();
    }

    public function index(): void
    {
        Auth::authorize('feature_flags.view');
        $this->view('feature_flags/index', ['title' => 'Feature Flags', 'flags' => $this->flags->allFlags()]);
    }

    public function create(): void
    {
        Auth::authorize('feature_flags.manage');
        $this->view('feature_flags/form', ['title' => 'New Feature Flag', 'flag' => null, 'rolloutTypes' => self::ROLLOUT_TYPES]);
    }

    public function store(): void
    {
        Auth::authorize('feature_flags.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/feature-flags/create');
            return;
        }

        $flagKey = trim((string) ($_POST['flag_key'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($flagKey === '' || $name === '' || !preg_match('/^[a-z0-9_.]+$/', $flagKey)) {
            Session::flash('error', 'A flag key (lowercase letters, numbers, dots, underscores) and name are required.');
            $this->redirect('/feature-flags/create');
            return;
        }
        if ($this->flags->findByKey($flagKey)) {
            Session::flash('error', 'That flag key is already in use.');
            $this->redirect('/feature-flags/create');
            return;
        }

        $data = $this->collectFromRequest();
        $data['flag_key'] = $flagKey;
        $data['name'] = $name;
        $data['created_by'] = Auth::user()['id'] ?? null;

        $id = $this->flags->create($data);
        Audit::log('Create', 'Platform', "Created feature flag '$flagKey'", ['flag_id' => $id]);

        Session::flash('success', 'Feature flag created.');
        $this->redirect('/feature-flags');
    }

    public function edit(string $id): void
    {
        Auth::authorize('feature_flags.manage');
        $flag = $this->flags->find((int) $id);
        if (!$flag) {
            Session::flash('error', 'Flag not found.');
            $this->redirect('/feature-flags');
            return;
        }
        $this->view('feature_flags/form', ['title' => 'Edit Feature Flag', 'flag' => $flag, 'rolloutTypes' => self::ROLLOUT_TYPES]);
    }

    public function update(string $id): void
    {
        Auth::authorize('feature_flags.manage');
        $id = (int) $id;
        $flag = $this->flags->find($id);
        if (!$flag) {
            Session::flash('error', 'Flag not found.');
            $this->redirect('/feature-flags');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/feature-flags/' . $id . '/edit');
            return;
        }

        $data = $this->collectFromRequest();
        $data['updated_by'] = Auth::user()['id'] ?? null;

        $this->flags->updateFlag($id, $data);
        Audit::log('Update', 'Platform', "Updated feature flag '{$flag['flag_key']}' (enabled={$data['enabled']}, rollout={$data['rollout_type']})", ['flag_id' => $id]);

        Session::flash('success', 'Feature flag updated.');
        $this->redirect('/feature-flags');
    }

    /** @return array<string, mixed> */
    private function collectFromRequest(): array
    {
        $rolloutType = in_array($_POST['rollout_type'] ?? '', self::ROLLOUT_TYPES, true) ? $_POST['rollout_type'] : 'OFF';

        $metadata = [];
        if ($rolloutType === 'SPECIFIC_USERS') {
            $metadata['user_ids'] = self::parseIntList($_POST['user_ids'] ?? '');
        } elseif ($rolloutType === 'SPECIFIC_ROLES') {
            $metadata['role_names'] = self::parseStringList($_POST['role_names'] ?? '');
        } elseif ($rolloutType === 'SPECIFIC_BRANCHES') {
            $metadata['branch_ids'] = self::parseIntList($_POST['branch_ids'] ?? '');
        }

        return [
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'rollout_type' => $rolloutType,
            'rollout_percentage' => $rolloutType === 'PERCENTAGE' ? max(0, min(100, (int) ($_POST['rollout_percentage'] ?? 0))) : null,
            'environment' => trim((string) ($_POST['environment'] ?? 'production')) ?: 'production',
            'starts_at' => trim((string) ($_POST['starts_at'] ?? '')) ?: null,
            'ends_at' => trim((string) ($_POST['ends_at'] ?? '')) ?: null,
            'metadata' => $metadata ? json_encode($metadata) : null,
        ];
    }

    private static function parseIntList(string $raw): array
    {
        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $raw)))));
    }

    private static function parseStringList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
