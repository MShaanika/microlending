<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\RecruitmentInterviewFeedback;

/**
 * Read-only cross-interview listing of feedback. Feedback is recorded and
 * deleted from the interview's show page, this screen exists so staff can
 * browse/search all submitted feedback in one place -- matching the
 * reference module's standalone "Interview Feedback" nav item.
 */
class RecruitmentInterviewFeedbackController extends Controller
{
    private RecruitmentInterviewFeedback $feedback;

    public function __construct()
    {
        $this->feedback = new RecruitmentInterviewFeedback();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'created_at');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->feedback->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/interview-feedback/index', [
            'title' => 'Interview Feedback',
            'feedback' => $result['rows'],
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
