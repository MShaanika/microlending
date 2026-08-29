<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Core\SecurityEvent;
use App\Models\Company;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCustomQuestion;
use App\Models\RecruitmentJobPosting;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentSetting;

/**
 * Public, unauthenticated careers portal: job listing, apply form,
 * application-status tracking (by tracking code), and offer response.
 * No session/login involved -- same "no Auth::authorize() call" pattern
 * as ApplicationIntakeController.
 *
 * The reference module protects its public offer-download/response links
 * with Crypt::encrypt($offer->id). This app has no general-purpose
 * encryption helper, so instead the offer-response link requires BOTH the
 * offer id AND the candidate's own tracking_id (a random, non-sequential
 * code only the candidate and staff know) -- an equivalent unguessable-URL
 * scheme without adding new crypto infrastructure.
 */
class RecruitmentFrontendController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private RecruitmentJobPosting $postings;
    private RecruitmentCandidate $candidates;
    private RecruitmentCustomQuestion $questions;
    private RecruitmentOffer $offers;
    private RecruitmentSetting $settings;
    private Company $company;

    public function __construct()
    {
        $this->postings = new RecruitmentJobPosting();
        $this->candidates = new RecruitmentCandidate();
        $this->questions = new RecruitmentCustomQuestion();
        $this->offers = new RecruitmentOffer();
        $this->settings = new RecruitmentSetting();
        $this->company = new Company();
    }

    public function index(): void
    {
        $this->view('recruitment/public/index', [
            'jobs' => $this->postings->publishedActive(),
            'company' => $this->company->primary() ?: [],
            'settings' => $this->settings->allSettings(),
        ]);
    }

    public function show(string $code): void
    {
        $job = $this->postings->findPublicByCode($code);
        if (!$job) {
            http_response_code(404);
            $this->view('recruitment/public/not_found', ['company' => $this->company->primary() ?: []]);
            return;
        }

        $questionIds = json_decode($job['custom_questions'] ?? '[]', true) ?: [];
        $data = [
            'job' => $job,
            'questions' => $this->questions->findMany($questionIds),
            'company' => $this->company->primary() ?: [],
            'settings' => $this->settings->allSettings(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/public/show', $data);
            return;
        }
        $this->view('recruitment/public/show', $data);
    }

    public function apply(string $code): void
    {
        $job = $this->postings->findPublicByCode($code);
        if (!$job) {
            http_response_code(404);
            $this->view('recruitment/public/not_found', ['company' => $this->company->primary() ?: []]);
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            $this->view('recruitment/public/show', [
                'job' => $job,
                'questions' => $this->questions->findMany(json_decode($job['custom_questions'] ?? '[]', true) ?: []),
                'company' => $this->company->primary() ?: [],
                'settings' => $this->settings->allSettings(),
                'old' => $_POST,
                'errors' => ['_general' => 'Security token expired. Please try again.'],
            ]);
            return;
        }

        [$data, $errors] = $this->validate($_POST);
        $questionIds = json_decode($job['custom_questions'] ?? '[]', true) ?: [];
        $answers = [];
        foreach ($questionIds as $qid) {
            $answers[$qid] = trim($_POST['question_' . $qid] ?? '');
        }

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/public/show', [
                'job' => $job,
                'questions' => $this->questions->findMany($questionIds),
                'company' => $this->company->primary() ?: [],
                'settings' => $this->settings->allSettings(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['job_id'] = $job['id'];
        $data['tracking_id'] = generate_reference('TRK');
        while ($this->candidates->trackingIdExists($data['tracking_id'])) {
            $data['tracking_id'] = generate_reference('TRK');
        }
        $data['status'] = 'New';
        $data['custom_answers'] = json_encode($answers);

        $id = $this->candidates->create($data);

        foreach (['profile' => 'profile_path', 'resume' => 'resume_path', 'cover_letter' => 'cover_letter_path'] as $field => $column) {
            if (!empty($_FILES[$field]['name'])) {
                $error = $this->validateFile($_FILES[$field]);
                if ($error === null) {
                    $path = $this->storeFile($id, $_FILES[$field]);
                    $this->candidates->updateRecord($id, [$column => $path]);
                }
            }
        }

        if ($this->isAjax()) {
            $this->jsonSuccess(
                'Application submitted! Your tracking code is ' . $data['tracking_id'] . '.',
                null,
                ['redirect' => url('/careers/track?tracking_id=' . $data['tracking_id'])]
            );
        }
        $this->view('recruitment/public/apply_success', [
            'trackingId' => $data['tracking_id'],
            'company' => $this->company->primary() ?: [],
            'settings' => $this->settings->allSettings(),
        ]);
    }

    public function trackResult(): void
    {
        $trackingId = trim($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? '');
        $candidate = $trackingId !== '' ? $this->candidates->findByTrackingId($trackingId) : null;
        $offers = $candidate ? array_filter(
            $this->offers->forCandidate((int) $candidate['id']),
            fn ($o) => in_array($o['status'], ['Sent', 'Negotiating'], true)
        ) : [];

        $this->view('recruitment/public/track', [
            'company' => $this->company->primary() ?: [],
            'settings' => $this->settings->allSettings(),
            'result' => $candidate,
            'pendingOffers' => $offers,
            'trackingId' => $trackingId,
            'notFound' => $trackingId !== '' && !$candidate,
        ]);
    }

    public function offerShow(string $trackingId, int $offerId): void
    {
        $offer = $this->resolveOffer($trackingId, $offerId);
        if (!$offer) {
            http_response_code(404);
            $this->view('recruitment/public/not_found', ['company' => $this->company->primary() ?: []]);
            return;
        }

        $this->view('recruitment/public/offer', [
            'offer' => $offer,
            'trackingId' => $trackingId,
            'company' => $this->company->primary() ?: [],
        ]);
    }

    public function offerRespond(string $trackingId, int $offerId): void
    {
        $offer = $this->resolveOffer($trackingId, $offerId);
        if (!$offer) {
            http_response_code(404);
            $this->view('recruitment/public/not_found', ['company' => $this->company->primary() ?: []]);
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->redirect('/careers/offer/' . $trackingId . '/' . $offerId);
            return;
        }

        $decision = $_POST['decision'] ?? '';
        if (!in_array($decision, ['Accepted', 'Declined'], true) || !in_array($offer['status'], ['Sent', 'Negotiating'], true)) {
            $this->redirect('/careers/offer/' . $trackingId . '/' . $offerId);
            return;
        }

        $this->offers->updateRecord($offerId, [
            'status' => $decision,
            'response_date' => date('Y-m-d'),
            'decline_reason' => $decision === 'Declined' ? trim($_POST['decline_reason'] ?? '') ?: null : null,
        ]);

        $this->redirect('/careers/offer/' . $trackingId . '/' . $offerId);
    }

    private function resolveOffer(string $trackingId, int $offerId): ?array
    {
        $candidate = $this->candidates->findByTrackingId($trackingId);
        if (!$candidate) {
            return null;
        }
        $offer = $this->offers->find($offerId);
        if (!$offer || (int) $offer['candidate_id'] !== (int) $candidate['id']) {
            return null;
        }
        return $offer;
    }

    private function validate(array $post): array
    {
        $firstName = trim($post['first_name'] ?? '');
        $lastName = trim($post['last_name'] ?? '');
        $email = trim($post['email'] ?? '');
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

        $data = [
            'source_id' => null,
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
            'expected_salary' => ($post['expected_salary'] ?? '') !== '' ? (float) $post['expected_salary'] : null,
            'notice_period' => trim($post['notice_period'] ?? '') ?: null,
            'skills' => trim($post['skills'] ?? '') ?: null,
            'education' => trim($post['education'] ?? '') ?: null,
            'portfolio_url' => trim($post['portfolio_url'] ?? '') ?: null,
            'linkedin_url' => trim($post['linkedin_url'] ?? '') ?: null,
            'application_date' => date('Y-m-d'),
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
            SecurityEvent::record('SUSPICIOUS_UPLOAD', 'Low', [
                'description' => 'Careers portal upload rejected: disallowed extension .' . $ext,
                'metadata' => ['claimed_extension' => $ext],
            ]);
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
