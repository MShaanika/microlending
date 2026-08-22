<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewFeedback;
use App\Models\RecruitmentInterviewRound;
use App\Models\RecruitmentInterviewType;
use App\Models\User;

class RecruitmentInterviewController extends Controller
{
    private const STATUSES = ['Scheduled', 'Completed', 'Cancelled', 'No-show'];
    private const RECOMMENDATIONS = ['Strong Hire', 'Hire', 'Maybe', 'Reject', 'Strong Reject'];

    private RecruitmentInterview $interviews;
    private RecruitmentInterviewFeedback $feedback;
    private RecruitmentInterviewRound $rounds;
    private RecruitmentInterviewType $types;
    private RecruitmentCandidate $candidates;
    private User $users;

    public function __construct()
    {
        $this->interviews = new RecruitmentInterview();
        $this->feedback = new RecruitmentInterviewFeedback();
        $this->rounds = new RecruitmentInterviewRound();
        $this->types = new RecruitmentInterviewType();
        $this->candidates = new RecruitmentCandidate();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'scheduled_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->interviews->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Interviews',
            'interviews' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/interviews/index', $data);
            return;
        }
        $this->view('recruitment/interviews/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $data = [
            'title' => 'Schedule Interview',
            'candidates' => $this->candidates->interviewStage(),
            'types' => $this->types->activeTypes(),
            'users' => $this->users->allActive(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/interviews/create', $data);
            return;
        }
        $this->view('recruitment/interviews/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/interviews/create', [
                'title' => 'Schedule Interview',
                'candidates' => $this->candidates->interviewStage(),
                'types' => $this->types->activeTypes(),
                'users' => $this->users->allActive(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->interviews->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Scheduled interview #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Interview scheduled.');
        }
        Session::flash('success', 'Interview scheduled.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    /** Candidate is fixed once an interview is scheduled -- edit covers logistics (round/type/date/interviewers), not who it's for. */
    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $interview = $this->interviews->find($id);
        if (!$interview) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Interview not found.'], 404);
            }
            Session::flash('error', 'Interview not found.');
            $this->redirect('/recruitment/interviews');
            return;
        }
        $data = [
            'title' => 'Edit Interview',
            'interview' => $interview,
            'rounds' => $interview['job_id'] ? $this->rounds->forJob((int) $interview['job_id']) : [],
            'types' => $this->types->activeTypes(),
            'users' => $this->users->allActive(),
            'old' => array_merge($interview, ['interviewer_ids' => json_decode((string) $interview['interviewer_ids'], true) ?: []]),
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/interviews/edit', $data);
            return;
        }
        $this->view('recruitment/interviews/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id . '/edit');
            return;
        }

        $interview = $this->interviews->find($id);
        if (!$interview) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Interview not found.'], 404);
            }
            Session::flash('error', 'Interview not found.');
            $this->redirect('/recruitment/interviews');
            return;
        }

        $scheduledDate = $_POST['scheduled_date'] ?? '';
        $errors = [];
        if ($scheduledDate === '') {
            $errors['scheduled_date'] = 'Scheduled date is required.';
        }

        $interviewerIds = array_filter(array_map('intval', $_POST['interviewer_ids'] ?? []));
        $data = [
            'round_id' => !empty($_POST['round_id']) ? (int) $_POST['round_id'] : null,
            'interview_type_id' => !empty($_POST['interview_type_id']) ? (int) $_POST['interview_type_id'] : null,
            'scheduled_date' => $scheduledDate ?: null,
            'scheduled_time' => trim($_POST['scheduled_time'] ?? '') ?: null,
            'duration' => !empty($_POST['duration']) ? (int) $_POST['duration'] : null,
            'location' => trim($_POST['location'] ?? '') ?: null,
            'meeting_link' => trim($_POST['meeting_link'] ?? '') ?: null,
            'interviewer_ids' => json_encode(array_values($interviewerIds)),
        ];

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/interviews/edit', [
                'title' => 'Edit Interview',
                'interview' => $interview,
                'rounds' => $interview['job_id'] ? $this->rounds->forJob((int) $interview['job_id']) : [],
                'types' => $this->types->activeTypes(),
                'users' => $this->users->allActive(),
                'old' => array_merge($_POST, ['interviewer_ids' => $interviewerIds]),
                'errors' => $errors,
            ]);
            return;
        }

        $this->interviews->updateRecord($id, $data);
        Audit::log('Update', 'Recruitment', 'Updated interview #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Interview updated.');
        }
        Session::flash('success', 'Interview updated.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $interview = $this->interviews->find($id);
        if (!$interview) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Interview not found.'], 404);
            }
            Session::flash('error', 'Interview not found.');
            $this->redirect('/recruitment/interviews');
            return;
        }
        $data = [
            'title' => 'Interview: ' . $interview['candidate_name'],
            'interview' => $interview,
            'feedback' => $this->feedback->forInterview($id),
            'users' => $this->users->allActive(),
            'recommendations' => self::RECOMMENDATIONS,
            'statuses' => self::STATUSES,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/interviews/show', $data);
            return;
        }
        $this->view('recruitment/interviews/show', $data);
    }

    public function getRoundsForCandidate(int $candidateId): void
    {
        Auth::authorize('recruitment.view');
        $candidate = $this->candidates->find($candidateId);
        $rounds = $candidate ? $this->rounds->forJob((int) $candidate['job_id']) : [];
        header('Content-Type: application/json');
        echo json_encode($rounds);
        exit;
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['status' => 'Invalid status.']);
            }
            Session::flash('error', 'Invalid status.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $interview = $this->interviews->find($id);
        $this->interviews->updateRecord($id, ['status' => $status]);

        // Interview completion auto-advances the candidate from Interview to Offer.
        if ($status === 'Completed' && $interview) {
            $candidate = $this->candidates->find((int) $interview['candidate_id']);
            if ($candidate && $candidate['status'] === 'Interview') {
                $this->candidates->updateStatus((int) $interview['candidate_id'], 'Offer');
            }
        }

        Audit::log('Update', 'Recruitment', 'Updated interview #' . $id . ' status to ' . $status);

        if ($this->isAjax()) {
            $this->jsonSuccess('Interview status updated.', '/recruitment/interviews/' . $id);
        }
        Session::flash('success', 'Interview status updated.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews');
            return;
        }

        $this->interviews->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted interview #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Interview deleted.');
        }
        Session::flash('success', 'Interview deleted.');
        $this->redirect('/recruitment/interviews');
    }

    public function addFeedback(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $recommendation = in_array($_POST['recommendation'] ?? '', self::RECOMMENDATIONS, true) ? $_POST['recommendation'] : 'Maybe';
        $interviewerIds = array_filter(array_map('intval', $_POST['interviewer_ids'] ?? []));

        $this->feedback->create([
            'interview_id' => $id,
            'technical_rating' => !empty($_POST['technical_rating']) ? (int) $_POST['technical_rating'] : null,
            'communication_rating' => !empty($_POST['communication_rating']) ? (int) $_POST['communication_rating'] : null,
            'cultural_fit_rating' => !empty($_POST['cultural_fit_rating']) ? (int) $_POST['cultural_fit_rating'] : null,
            'overall_rating' => !empty($_POST['overall_rating']) ? (int) $_POST['overall_rating'] : null,
            'strengths' => trim($_POST['strengths'] ?? '') ?: null,
            'weaknesses' => trim($_POST['weaknesses'] ?? '') ?: null,
            'comments' => trim($_POST['comments'] ?? '') ?: null,
            'recommendation' => $recommendation,
            'interviewer_ids' => json_encode(array_values($interviewerIds)),
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        $this->interviews->updateRecord($id, ['feedback_submitted' => 1]);

        Audit::log('Create', 'Recruitment', 'Recorded feedback for interview #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Feedback recorded.', '/recruitment/interviews/' . $id);
        }
        Session::flash('success', 'Feedback recorded.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function deleteFeedback(int $id, int $feedbackId): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $this->feedback->delete($feedbackId);

        if ($this->isAjax()) {
            $this->jsonSuccess('Feedback deleted.', '/recruitment/interviews/' . $id);
        }
        Session::flash('success', 'Feedback deleted.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    private function validate(array $post): array
    {
        $candidateId = !empty($post['candidate_id']) ? (int) $post['candidate_id'] : null;
        $scheduledDate = $post['scheduled_date'] ?? '';
        $errors = [];

        if (!$candidateId) {
            $errors['candidate_id'] = 'Candidate is required.';
        }
        if ($scheduledDate === '') {
            $errors['scheduled_date'] = 'Scheduled date is required.';
        }

        $candidate = $candidateId ? $this->candidates->find($candidateId) : null;
        $interviewerIds = array_filter(array_map('intval', $post['interviewer_ids'] ?? []));

        $data = [
            'candidate_id' => $candidateId,
            'job_id' => $candidate ? (int) $candidate['job_id'] : null,
            'round_id' => !empty($post['round_id']) ? (int) $post['round_id'] : null,
            'interview_type_id' => !empty($post['interview_type_id']) ? (int) $post['interview_type_id'] : null,
            'scheduled_date' => $scheduledDate ?: null,
            'scheduled_time' => trim($post['scheduled_time'] ?? '') ?: null,
            'duration' => !empty($post['duration']) ? (int) $post['duration'] : null,
            'location' => trim($post['location'] ?? '') ?: null,
            'meeting_link' => trim($post['meeting_link'] ?? '') ?: null,
            'interviewer_ids' => json_encode(array_values($interviewerIds)),
        ];

        if (!$candidate) {
            $errors['candidate_id'] = $errors['candidate_id'] ?? 'Candidate not found.';
        }

        return [$data, $errors];
    }
}
