<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmDocumentType;

class HrmDocumentTypeController extends Controller
{
    private HrmDocumentType $types;

    public function __construct()
    {
        $this->types = new HrmDocumentType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->types->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Document Types',
            'types' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/document-types/index', $data);
            return;
        }
        $this->view('hrm/document-types/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = ['title' => 'Add Document Type', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/document-types/create', $data);
            return;
        }
        $this->view('hrm/document-types/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/document-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/document-types/create', [
                'title' => 'Add Document Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created document type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Document type created.');
        }
        Session::flash('success', 'Document type created.');
        $this->redirect('/hrm/document-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Document type not found.'], 404);
            }
            Session::flash('error', 'Document type not found.');
            $this->redirect('/hrm/document-types');
            return;
        }
        $data = ['title' => 'Edit Document Type', 'type' => $type, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/document-types/edit', $data);
            return;
        }
        $this->view('hrm/document-types/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/document-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Document type not found.'], 404);
            }
            Session::flash('error', 'Document type not found.');
            $this->redirect('/hrm/document-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/document-types/edit', [
                'title' => 'Edit Document Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated document type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Document type updated.');
        }
        Session::flash('success', 'Document type updated.');
        $this->redirect('/hrm/document-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/document-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This document type is used by uploaded employee documents and cannot be deleted.');
            $this->redirect('/hrm/document-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted document type #' . $id);
        Session::flash('success', 'Document type deleted.');
        $this->redirect('/hrm/document-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'A document type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'is_required' => !empty($post['is_required']) ? 1 : 0,
        ];

        return [$data, $errors];
    }
}
