<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentCustomQuestion;

class RecruitmentCustomQuestionController extends Controller
{
    private const TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'number'];

    private RecruitmentCustomQuestion $questions;

    public function __construct()
    {
        $this->questions = new RecruitmentCustomQuestion();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $data = [
            'title' => 'Application Questions',
            'questions' => $this->questions->search($search),
            'search' => $search,
        ];

        if ($this->isAjax()) {
            $this->fragment('recruitment/custom-questions/index', $data);
            return;
        }
        $this->view('recruitment/custom-questions/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $data = ['title' => 'Add Application Question', 'types' => self::TYPES, 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/custom-questions/create', $data);
            return;
        }
        $this->view('recruitment/custom-questions/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/custom-questions/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/custom-questions/create', [
                'title' => 'Add Application Question',
                'types' => self::TYPES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->questions->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created application question #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Question created.');
        }
        Session::flash('success', 'Question created.');
        $this->redirect('/recruitment/custom-questions');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $question = $this->questions->find($id);
        if (!$question) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Question not found.'], 404);
            }
            Session::flash('error', 'Question not found.');
            $this->redirect('/recruitment/custom-questions');
            return;
        }
        $data = ['title' => 'Edit Application Question', 'question' => $question, 'types' => self::TYPES, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('recruitment/custom-questions/edit', $data);
            return;
        }
        $this->view('recruitment/custom-questions/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/custom-questions/' . $id . '/edit');
            return;
        }

        $question = $this->questions->find($id);
        if (!$question) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Question not found.'], 404);
            }
            Session::flash('error', 'Question not found.');
            $this->redirect('/recruitment/custom-questions');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('recruitment/custom-questions/edit', [
                'title' => 'Edit Application Question',
                'question' => array_merge($question, $_POST),
                'types' => self::TYPES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->questions->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated application question #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Question updated.');
        }
        Session::flash('success', 'Question updated.');
        $this->redirect('/recruitment/custom-questions');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/custom-questions');
            return;
        }

        $this->questions->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted application question #' . $id);
        Session::flash('success', 'Question deleted.');
        $this->redirect('/recruitment/custom-questions');
    }

    private function validate(array $post): array
    {
        $question = trim($post['question'] ?? '');
        $errors = [];
        if ($question === '') {
            $errors['question'] = 'Question text is required.';
        }

        $type = in_array($post['type'] ?? '', self::TYPES, true) ? $post['type'] : 'text';

        $data = [
            'question' => $question,
            'type' => $type,
            'options' => trim($post['options'] ?? '') ?: null,
            'is_required' => !empty($post['is_required']) ? 1 : 0,
            'is_active' => !empty($post['is_active']) ? 1 : 0,
            'sort_order' => !empty($post['sort_order']) ? (int) $post['sort_order'] : 0,
        ];

        return [$data, $errors];
    }
}
