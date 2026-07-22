<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;

class DocumentationController extends Controller
{
    private const FILES = [
        'staff-guide' => [
            'path' => 'Staff_User_Guide.docx',
            'label' => 'Staff User Guide',
        ],
        'technical-reference' => [
            'path' => 'Technical_Reference.docx',
            'label' => 'Technical Reference',
        ],
    ];

    public function download(string $key): void
    {
        Auth::authorize('dashboard.view');

        $file = self::FILES[$key] ?? null;
        if (!$file) {
            Session::flash('error', 'Unknown document.');
            $this->redirect('/dashboard');
            return;
        }

        $fullPath = STORAGE_PATH . '/documentation/' . $file['path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'This document is not available right now.');
            $this->redirect('/dashboard');
            return;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
