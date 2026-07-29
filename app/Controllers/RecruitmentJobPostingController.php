<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentCustomQuestion;
use App\Models\RecruitmentInterviewRound;
use App\Models\RecruitmentJobLocation;
use App\Models\RecruitmentJobPosting;
use App\Models\RecruitmentJobType;

class RecruitmentJobPostingController extends Controller
{
    private const PRIORITIES = ['Low', 'Medium', 'High'];
    private const STATUSES = ['Draft', 'Active', 'Closed'];

    private RecruitmentJobPosting $postings;
    private RecruitmentJobType $types;
    private RecruitmentJobLocation $locations;
    private RecruitmentCustomQuestion $questions;
    private RecruitmentInterviewRound $rounds;

    public function __construct()
    {
        $this->postings = new RecruitmentJobPosting();
        $this->types = new RecruitmentJobType();
        $this->locations = new RecruitmentJobLocation();
        $this->questions = new RecruitmentCustomQuestion();
        $this->rounds = new RecruitmentInterviewRound();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $filters = ['status' => $_GET['status'] ?? null];
        $this->view('recruitment/job-postings/index', [
            'title' => 'Job Postings',
            'postings' => $this->postings->allPostings($filters),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/job-postings/create', [
            'title' => 'Add Job Posting',
            'types' => $this->types->activeTypes(),
            'locations' => $this->locations->activeLocations(),
            'questions' => $this->questions->activeQuestions(),
            'priorities' => self::PRIORITIES,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/job-postings/create', [
                'title' => 'Add Job Posting',
                'types' => $this->types->activeTypes(),
                'locations' => $this->locations->activeLocations(),
                'questions' => $this->questions->activeQuestions(),
                'priorities' => self::PRIORITIES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['posting_code'] = generate_reference('JP');
        $id = $this->postings->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created job posting #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Job posting created.');
        $this->redirect('/recruitment/job-postings/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $posting = $this->postings->find($id);
        if (!$posting) {
            Session::flash('error', 'Job posting not found.');
            $this->redirect('/recruitment/job-postings');
            return;
        }
        $questionIds = json_decode($posting['custom_questions'] ?? '[]', true) ?: [];
        $this->view('recruitment/job-postings/show', [
            'title' => $posting['title'],
            'posting' => $posting,
            'questions' => $this->questions->findMany($questionIds),
            'rounds' => $this->rounds->forJob($id),
            'publicUrl' => url('/careers/' . $posting['posting_code']),
        ]);
    }

    public function addRound(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings/' . $id);
            return;
        }

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            Session::flash('error', 'Round name is required.');
            $this->redirect('/recruitment/job-postings/' . $id);
            return;
        }

        $this->rounds->create([
            'job_id' => $id,
            'name' => $name,
            'sequence_number' => !empty($_POST['sequence_number']) ? (int) $_POST['sequence_number'] : 1,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'status' => 'Active',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Session::flash('success', 'Interview round added.');
        $this->redirect('/recruitment/job-postings/' . $id);
    }

    public function deleteRound(int $id, int $roundId): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings/' . $id);
            return;
        }

        $this->rounds->delete($roundId);
        Session::flash('success', 'Interview round deleted.');
        $this->redirect('/recruitment/job-postings/' . $id);
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $posting = $this->postings->find($id);
        if (!$posting) {
            Session::flash('error', 'Job posting not found.');
            $this->redirect('/recruitment/job-postings');
            return;
        }
        $this->view('recruitment/job-postings/edit', [
            'title' => 'Edit Job Posting',
            'posting' => $posting,
            'selectedQuestions' => json_decode($posting['custom_questions'] ?? '[]', true) ?: [],
            'types' => $this->types->activeTypes(),
            'locations' => $this->locations->activeLocations(),
            'questions' => $this->questions->activeQuestions(),
            'priorities' => self::PRIORITIES,
            'statuses' => self::STATUSES,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings/' . $id . '/edit');
            return;
        }

        $posting = $this->postings->find($id);
        if (!$posting) {
            Session::flash('error', 'Job posting not found.');
            $this->redirect('/recruitment/job-postings');
            return;
        }

        [$data, $errors] = $this->validate($_POST);
        $data['status'] = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : $posting['status'];

        if (!empty($errors)) {
            $this->view('recruitment/job-postings/edit', [
                'title' => 'Edit Job Posting',
                'posting' => array_merge($posting, $_POST),
                'selectedQuestions' => array_map('intval', $_POST['custom_questions'] ?? []),
                'types' => $this->types->activeTypes(),
                'locations' => $this->locations->activeLocations(),
                'questions' => $this->questions->activeQuestions(),
                'priorities' => self::PRIORITIES,
                'statuses' => self::STATUSES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->postings->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated job posting #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Job posting updated.');
        $this->redirect('/recruitment/job-postings/' . $id);
    }

    public function togglePublish(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings/' . $id);
            return;
        }

        $posting = $this->postings->find($id);
        if (!$posting) {
            Session::flash('error', 'Job posting not found.');
            $this->redirect('/recruitment/job-postings');
            return;
        }

        $nowPublished = !$posting['is_published'];
        $this->postings->updateRecord($id, [
            'is_published' => $nowPublished ? 1 : 0,
            'publish_date' => $nowPublished ? date('Y-m-d') : null,
        ]);

        Audit::log($nowPublished ? 'Publish' : 'Unpublish', 'Recruitment', ($nowPublished ? 'Published' : 'Unpublished') . ' job posting #' . $id);
        Session::flash('success', $nowPublished ? 'Job posting published.' : 'Job posting unpublished.');
        $this->redirect('/recruitment/job-postings/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-postings');
            return;
        }

        $this->postings->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted job posting #' . $id);
        Session::flash('success', 'Job posting deleted.');
        $this->redirect('/recruitment/job-postings');
    }

    private function validate(array $post): array
    {
        $title = trim($post['title'] ?? '');
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }

        $questionIds = array_filter(array_map('intval', $post['custom_questions'] ?? []));

        $data = [
            'title' => $title,
            'job_type_id' => !empty($post['job_type_id']) ? (int) $post['job_type_id'] : null,
            'location_id' => !empty($post['location_id']) ? (int) $post['location_id'] : null,
            'position' => !empty($post['position']) ? (int) $post['position'] : 1,
            'priority' => in_array($post['priority'] ?? '', self::PRIORITIES, true) ? $post['priority'] : 'Medium',
            'min_experience' => ($post['min_experience'] ?? '') !== '' ? (float) $post['min_experience'] : null,
            'max_experience' => ($post['max_experience'] ?? '') !== '' ? (float) $post['max_experience'] : null,
            'min_salary' => ($post['min_salary'] ?? '') !== '' ? (float) $post['min_salary'] : null,
            'max_salary' => ($post['max_salary'] ?? '') !== '' ? (float) $post['max_salary'] : null,
            'description' => trim($post['description'] ?? '') ?: null,
            'requirements' => trim($post['requirements'] ?? '') ?: null,
            'skills' => trim($post['skills'] ?? '') ?: null,
            'benefits' => trim($post['benefits'] ?? '') ?: null,
            'terms_condition' => trim($post['terms_condition'] ?? '') ?: null,
            'application_deadline' => !empty($post['application_deadline']) ? $post['application_deadline'] : null,
            'is_featured' => !empty($post['is_featured']) ? 1 : 0,
            'custom_questions' => json_encode(array_values($questionIds)),
        ];

        return [$data, $errors];
    }
}
