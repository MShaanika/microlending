<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateAssessment;
use App\Models\RecruitmentCandidateSource;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentJobPosting;
use App\Models\RecruitmentOffer;

class RecruitmentCandidateController extends Controller
{
    private const STATUSES = ['New', 'Screening', 'Interview', 'Offer', 'Hired', 'Rejected'];
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private RecruitmentCandidate $candidates;
    private RecruitmentJobPosting $postings;
    private RecruitmentCandidateSource $sources;
    private RecruitmentInterview $interviews;
    private RecruitmentCandidateAssessment $assessments;
    private RecruitmentOffer $offers;

    public function __construct()
    {
        $this->candidates = new RecruitmentCandidate();
        $this->postings = new RecruitmentJobPosting();
        $this->sources = new RecruitmentCandidateSource();
        $this->interviews = new RecruitmentInterview();
        $this->assessments = new RecruitmentCandidateAssessment();
        $this->offers = new RecruitmentOffer();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $filters = [
            'job_id' => $_GET['job_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'application_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->candidates->paginated($filters, $sort, $dir, $page, $perPage);

        $this->view('recruitment/candidates/index', [
            'title' => 'Candidates',
            'candidates' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'postings' => $this->postings->allPostings(),
            'filters' => $filters,
            'search' => $filters['search'],
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
            'statuses' => self::STATUSES,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/candidates/create', [
            'title' => 'Add Candidate',
            'postings' => $this->postings->allPostings(),
            'sources' => $this->sources->activeSources(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/candidates/create', [
                'title' => 'Add Candidate',
                'postings' => $this->postings->allPostings(),
                'sources' => $this->sources->activeSources(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['tracking_id'] = generate_reference('TRK');
        $data['status'] = 'New';
        $id = $this->candidates->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        foreach (['profile' => 'profile_path', 'resume' => 'resume_path', 'cover_letter' => 'cover_letter_path'] as $field => $column) {
            if (!empty($_FILES[$field]['name'])) {
                $error = $this->validateFile($_FILES[$field]);
                if ($error === null) {
                    $path = $this->storeFile($id, $_FILES[$field]);
                    $this->candidates->updateRecord($id, [$column => $path]);
                }
            }
        }

        Audit::log('Create', 'Recruitment', 'Added candidate #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Candidate added.');
        $this->redirect('/recruitment/candidates/' . $id);
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $candidate = $this->candidates->find($id);
        if (!$candidate) {
            Session::flash('error', 'Candidate not found.');
            $this->redirect('/recruitment/candidates');
            return;
        }
        $this->view('recruitment/candidates/edit', [
            'title' => 'Edit Candidate',
            'candidate' => $candidate,
            'postings' => $this->postings->allPostings(),
            'sources' => $this->sources->activeSources(),
            'old' => $candidate,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates/' . $id . '/edit');
            return;
        }

        $candidate = $this->candidates->find($id);
        if (!$candidate) {
            Session::flash('error', 'Candidate not found.');
            $this->redirect('/recruitment/candidates');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/candidates/edit', [
                'title' => 'Edit Candidate',
                'candidate' => $candidate,
                'postings' => $this->postings->allPostings(),
                'sources' => $this->sources->activeSources(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $this->candidates->updateRecord($id, $data);

        foreach (['profile' => 'profile_path', 'resume' => 'resume_path', 'cover_letter' => 'cover_letter_path'] as $field => $column) {
            if (!empty($_FILES[$field]['name'])) {
                $error = $this->validateFile($_FILES[$field]);
                if ($error === null) {
                    $path = $this->storeFile($id, $_FILES[$field]);
                    $this->candidates->updateRecord($id, [$column => $path]);
                }
            }
        }

        Audit::log('Update', 'Recruitment', 'Updated candidate #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Candidate updated.');
        $this->redirect('/recruitment/candidates/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $candidate = $this->candidates->find($id);
        if (!$candidate) {
            Session::flash('error', 'Candidate not found.');
            $this->redirect('/recruitment/candidates');
            return;
        }
        $this->view('recruitment/candidates/show', [
            'title' => $candidate['first_name'] . ' ' . $candidate['last_name'],
            'candidate' => $candidate,
            'interviews' => $this->interviews->forCandidate($id),
            'assessments' => $this->assessments->forCandidate($id),
            'offers' => $this->offers->forCandidate($id),
            'statuses' => self::STATUSES,
        ]);
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates/' . $id);
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/recruitment/candidates/' . $id);
            return;
        }

        $this->candidates->updateStatus($id, $status);
        Audit::log('Update', 'Recruitment', 'Updated candidate #' . $id . ' status to ' . $status);
        Session::flash('success', 'Candidate status updated.');
        $this->redirect('/recruitment/candidates/' . $id);
    }

    public function addAssessment(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates/' . $id);
            return;
        }

        $name = trim($_POST['assessment_name'] ?? '');
        if ($name === '') {
            Session::flash('error', 'Assessment name is required.');
            $this->redirect('/recruitment/candidates/' . $id);
            return;
        }

        $this->assessments->create([
            'candidate_id' => $id,
            'assessment_name' => $name,
            'score' => ($_POST['score'] ?? '') !== '' ? (float) $_POST['score'] : null,
            'max_score' => ($_POST['max_score'] ?? '') !== '' ? (float) $_POST['max_score'] : null,
            'pass_fail_status' => in_array($_POST['pass_fail_status'] ?? '', ['Pass', 'Fail', 'Pending'], true) ? $_POST['pass_fail_status'] : 'Pending',
            'comments' => trim($_POST['comments'] ?? '') ?: null,
            'assessment_date' => !empty($_POST['assessment_date']) ? $_POST['assessment_date'] : date('Y-m-d'),
            'conducted_by' => Auth::user()['id'] ?? null,
        ]);

        Session::flash('success', 'Assessment recorded.');
        $this->redirect('/recruitment/candidates/' . $id);
    }

    public function deleteAssessment(int $id, int $assessmentId): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates/' . $id);
            return;
        }

        $this->assessments->delete($assessmentId);
        Session::flash('success', 'Assessment deleted.');
        $this->redirect('/recruitment/candidates/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/candidates');
            return;
        }

        $this->candidates->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted candidate #' . $id);
        Session::flash('success', 'Candidate deleted.');
        $this->redirect('/recruitment/candidates');
    }

    public function downloadFile(int $id, string $field): void
    {
        Auth::authorize('recruitment.view');
        $this->streamCandidateFile($id, $field, '/recruitment/candidates/' . $id);
    }

    private function streamCandidateFile(int $id, string $field, string $backRedirect): void
    {
        $columns = ['profile' => 'profile_path', 'resume' => 'resume_path', 'cover_letter' => 'cover_letter_path'];
        $candidate = $this->candidates->find($id);

        if (!$candidate || !isset($columns[$field]) || empty($candidate[$columns[$field]])) {
            Session::flash('error', 'File not found.');
            $this->redirect($backRedirect);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $candidate[$columns[$field]];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect($backRedirect);
            return;
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $field . '_' . $candidate['id'] . '.' . $ext . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    private function validate(array $post): array
    {
        $firstName = trim($post['first_name'] ?? '');
        $lastName = trim($post['last_name'] ?? '');
        $email = trim($post['email'] ?? '');
        $jobId = !empty($post['job_id']) ? (int) $post['job_id'] : null;
        $errors = [];

        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }
        if (!$jobId) {
            $errors['job_id'] = 'Job posting is required.';
        }

        $data = [
            'job_id' => $jobId,
            'source_id' => !empty($post['source_id']) ? (int) $post['source_id'] : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => trim($post['phone'] ?? '') ?: null,
            'gender' => in_array($post['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $post['gender'] : null,
            'dob' => !empty($post['dob']) ? $post['dob'] : null,
            'country' => trim($post['country'] ?? '') ?: null,
            'region' => trim($post['region'] ?? '') ?: null,
            'city' => trim($post['city'] ?? '') ?: null,
            'current_company' => trim($post['current_company'] ?? '') ?: null,
            'current_position' => trim($post['current_position'] ?? '') ?: null,
            'experience_years' => ($post['experience_years'] ?? '') !== '' ? (float) $post['experience_years'] : null,
            'current_salary' => ($post['current_salary'] ?? '') !== '' ? (float) $post['current_salary'] : null,
            'expected_salary' => ($post['expected_salary'] ?? '') !== '' ? (float) $post['expected_salary'] : null,
            'notice_period' => trim($post['notice_period'] ?? '') ?: null,
            'skills' => trim($post['skills'] ?? '') ?: null,
            'education' => trim($post['education'] ?? '') ?: null,
            'portfolio_url' => trim($post['portfolio_url'] ?? '') ?: null,
            'linkedin_url' => trim($post['linkedin_url'] ?? '') ?: null,
            'application_date' => !empty($post['application_date']) ? $post['application_date'] : date('Y-m-d'),
        ];

        return [$data, $errors];
    }

    private function validateFile(array $file): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed.';
        }
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return 'File exceeds the 5MB limit.';
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return 'Only PDF, JPG, and PNG files are allowed.';
        }
        return null;
    }

    private function storeFile(int $candidateId, array $file): string
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $targetDir = STORAGE_PATH . '/uploads/recruitment_candidates/' . $candidateId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = 'doc_' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $targetDir . '/' . $filename);
        return 'uploads/recruitment_candidates/' . $candidateId . '/' . $filename;
    }
}
