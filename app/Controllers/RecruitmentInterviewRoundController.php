<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\RecruitmentInterviewRound;

/**
 * Read-only cross-job listing of interview rounds. Rounds are created and
 * deleted from the job posting's show page (they're job-specific), this
 * screen exists so staff can browse/search all rounds in one place --
 * matching the reference module's standalone "Interview Rounds" nav item.
 */
class RecruitmentInterviewRoundController extends Controller
{
    private RecruitmentInterviewRound $rounds;

    public function __construct()
    {
        $this->rounds = new RecruitmentInterviewRound();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'job');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->rounds->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/interview-rounds/index', [
            'title' => 'Interview Rounds',
            'rounds' => $result['rows'],
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
