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
        $this->view('recruitment/interviews/index', [
            'title' => 'Interviews',
            'interviews' => $this->interviews->allInterviews(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/interviews/create', [
            'title' => 'Schedule Interview',
            'candidates' => $this->candidates->interviewStage(),
            'types' => $this->types->activeTypes(),
            'users' => $this->users->allActive(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
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
        Session::flash('success', 'Interview scheduled.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $interview = $this->interviews->find($id);
        if (!$interview) {
            Session::flash('error', 'Interview not found.');
            $this->redirect('/recruitment/interviews');
            return;
        }
        $this->view('recruitment/interviews/show', [
            'title' => 'Interview: ' . $interview['candidate_name'],
            'interview' => $interview,
            'feedback' => $this->feedback->forInterview($id),
            'users' => $this->users->allActive(),
            'recommendations' => self::RECOMMENDATIONS,
            'statuses' => self::STATUSES,
        ]);
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
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
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
        Session::flash('success', 'Interview status updated.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews');
            return;
        }

        $this->interviews->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted interview #' . $id);
        Session::flash('success', 'Interview deleted.');
        $this->redirect('/recruitment/interviews');
    }

    public function addFeedback(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
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
        Session::flash('success', 'Feedback recorded.');
        $this->redirect('/recruitment/interviews/' . $id);
    }

    public function deleteFeedback(int $id, int $feedbackId): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/interviews/' . $id);
            return;
        }

        $this->feedback->delete($feedbackId);
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
