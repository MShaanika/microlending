<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentOfferLetterTemplate;

class RecruitmentOfferLetterTemplateController extends Controller
{
    private RecruitmentOfferLetterTemplate $templates;

    public function __construct()
    {
        $this->templates = new RecruitmentOfferLetterTemplate();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->templates->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('recruitment/offer-letter-templates/index', [
            'title' => 'Offer Letter Templates',
            'templates' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/offer-letter-templates/create', [
            'title' => 'Add Offer Letter Template',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offer-letter-templates/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/offer-letter-templates/create', [
                'title' => 'Add Offer Letter Template',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->templates->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created offer letter template #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Template created.');
        $this->redirect('/recruitment/offer-letter-templates');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/recruitment/offer-letter-templates');
            return;
        }
        $this->view('recruitment/offer-letter-templates/edit', [
            'title' => 'Edit Offer Letter Template',
            'template' => $template,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offer-letter-templates/' . $id . '/edit');
            return;
        }

        $template = $this->templates->find($id);
        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/recruitment/offer-letter-templates');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/offer-letter-templates/edit', [
                'title' => 'Edit Offer Letter Template',
                'template' => array_merge($template, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->templates->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated offer letter template #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Template updated.');
        $this->redirect('/recruitment/offer-letter-templates');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/offer-letter-templates');
            return;
        }

        $this->templates->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted offer letter template #' . $id);
        Session::flash('success', 'Template deleted.');
        $this->redirect('/recruitment/offer-letter-templates');
    }

    private function validate(array $post): array
    {
        $name = trim($post['name'] ?? '');
        $content = trim($post['content'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($content === '') {
            $errors['content'] = 'Template content is required.';
        }

        $data = [
            'name' => $name,
            'content' => $content,
            'is_active' => !empty($post['is_active']) ? 1 : 0,
        ];

        return [$data, $errors];
    }
}
