<?php
namespace App\Core;

class Controller
{
    protected function view(string $view, array $data = []): void { View::render($view, $data); }
    protected function redirect(string $path): void { header('Location: ' . url($path)); exit; }

    /**
     * True when the request came from the modal-loader JS (fetch() calls
     * always set this header) rather than a direct browser navigation --
     * lets a single show()/create()/edit() action serve either a full page
     * or just its .content fragment for injection into a modal, and lets
     * store()/update() return JSON instead of a flash+redirect, without any
     * separate routes or duplicated controller logic.
     */
    protected function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Renders a view's .content fragment directly, bypassing the .php
     * layout wrapper (and therefore layouts/main.php) -- the fragment
     * already contains zero layout chrome (see the .php/.php.content
     * convention used everywhere), so it's exactly the HTML a modal body
     * needs. Same $data shape as the equivalent full-page view() call.
     */
    protected function fragment(string $view, array $data = []): void
    {
        View::renderFragment($view, $data);
    }

    /** JSON success response for an AJAX form submission (store/update). */
    protected function jsonSuccess(string $message, ?string $refresh = null, array $extra = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true, 'message' => $message, 'refresh' => $refresh], $extra));
        exit;
    }

    /** JSON validation-failure response: per-field messages the modal JS maps onto each input. */
    protected function jsonErrors(array $errors, int $status = 422): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    /** JSON CSRF-failure response -- mirrors the flash+redirect message used on full-page submits. */
    protected function jsonCsrfFailure(): void
    {
        $this->jsonErrors(['_csrf' => 'Security token expired. Please try again.'], 419);
    }
}
