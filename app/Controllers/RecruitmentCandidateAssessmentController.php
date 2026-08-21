<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\RecruitmentCandidateAssessment;

/**
 * Read-only cross-candidate listing of assessments. Assessments are
 * recorded and deleted from the candidate's show page, this screen exists
 * so staff can browse/search all assessments in one place -- matching the
 * reference module's standalone "Candidate Assessments" nav item.
 */
class RecruitmentCandidateAssessmentController extends Controller
{
    private RecruitmentCandidateAssessment $assessments;

    public function __construct()
    {
        $this->assessments = new RecruitmentCandidateAssessment();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'assessment_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->assessments->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/candidate-assessments/index', [
            'title' => 'Candidate Assessments',
            'assessments' => $result['rows'],
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
