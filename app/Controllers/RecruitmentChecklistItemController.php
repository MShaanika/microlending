<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\RecruitmentChecklistItem;

/**
 * Read-only cross-checklist listing of checklist items. Items are added
 * and deleted from the onboarding checklist's show page, this screen
 * exists so staff can browse/search all items in one place -- matching
 * the reference module's standalone "Checklist Items" nav item.
 */
class RecruitmentChecklistItemController extends Controller
{
    private RecruitmentChecklistItem $items;

    public function __construct()
    {
        $this->items = new RecruitmentChecklistItem();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'checklist');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->items->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/checklist-items/index', [
            'title' => 'Checklist Items',
            'items' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }
}
