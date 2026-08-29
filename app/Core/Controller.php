<?php
namespace App\Core;

class Controller
{
    protected function view(string $view, array $data = []): void { View::render($view, $data); }
    protected function redirect(string $path): void { header('Location: ' . url($path)); exit; }

    /**
     * Reads the client-generated idempotency key submit-guard.js injects
     * into every guarded form (_idempotency_key hidden field). Falls back
     * to a fresh server-generated one if it's somehow missing (JS disabled,
     * an opted-out form, or a direct API call) -- degrades to "always
     * proceed, never replay" rather than erroring, since a missing key
     * means we have no way to recognize a resubmit anyway.
     */
    protected function idempotencyKey(): string
    {
        $key = $_POST['_idempotency_key'] ?? '';
        return is_string($key) && $key !== '' ? $key : bin2hex(random_bytes(16));
    }

    /**
     * Marks an idempotency key as completed with a flash+redirect response,
     * then performs that exact redirect. A resubmit of the same key later
     * replays this identical flash message + destination via
     * replayIdempotent() instead of re-running the operation.
     */
    protected function idempotentRedirect(string $key, string $operationType, string $flashType, string $flashMessage, string $path): void
    {
        Idempotency::complete($key, $operationType, 'REDIRECT', [
            'flash_type' => $flashType,
            'flash_message' => $flashMessage,
            'redirect' => $path,
        ]);
        Session::flash($flashType, $flashMessage);
        $this->redirect($path);
    }

    /** Replays a previously-completed operation's exact original response instead of re-running it. */
    protected function replayIdempotent(IdempotencyReplayException $e): void
    {
        if ($e->responseType === 'JSON') {
            header('Content-Type: application/json');
            echo json_encode($e->payload);
            exit;
        }
        $payload = $e->payload;
        if (!empty($payload['flash_type']) && !empty($payload['flash_message'])) {
            Session::flash($payload['flash_type'], $payload['flash_message']);
        }
        $this->redirect($payload['redirect'] ?? '/dashboard');
    }

    /** Standard "please wait, your previous request is still processing" response for a busy idempotency key. */
    protected function busyIdempotent(IdempotencyBusyException $e, string $path): void
    {
        if ($this->isAjax()) {
            $this->jsonErrors(['_general' => $e->getMessage()], 409);
        }
        Session::flash('error', $e->getMessage());
        $this->redirect($path);
    }

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
