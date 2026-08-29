<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SecurityBlockedSource;

class SecurityBlockedSourceController extends Controller
{
    private SecurityBlockedSource $blocks;

    public function __construct()
    {
        $this->blocks = new SecurityBlockedSource();
    }

    public function index(): void
    {
        Auth::authorize('security.view');

        $filters = [
            'status' => trim((string) ($_GET['status'] ?? 'Active')),
            'block_type' => trim((string) ($_GET['block_type'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 25));

        $result = $this->blocks->paginated($filters, $page, $perPage);

        $this->view('security/blocked_sources/index', [
            'title' => 'Blocked Sources',
            'blocks' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    public function lift(string $id): void
    {
        Auth::authorize('security.blocks.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/security/blocked-sources');
            return;
        }

        $block = $this->blocks->find($id);
        if (!$block || $block['status'] !== 'Active') {
            Session::flash('error', 'Block not found or already lifted.');
            $this->redirect('/security/blocked-sources');
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        $userId = (int) (Auth::user()['id'] ?? 0);
        $this->blocks->lift($id, $userId, $reason ?: 'Lifted by administrator');

        Audit::log(
            'Update',
            'Security',
            'Lifted ' . $block['block_type'] . ' block on ' . $block['block_value'] . ($reason !== '' ? ': ' . $reason : ''),
            ['block_id' => $id]
        );

        Session::flash('success', 'Block lifted.');
        $this->redirect('/security/blocked-sources');
    }
}
