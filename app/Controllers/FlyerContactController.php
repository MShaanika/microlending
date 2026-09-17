<?php

namespace App\Controllers;

use App\Core\ClientIp;
use App\Core\Controller;
use App\Core\Database;
use App\Core\SecurityEvent;
use App\Services\EmailSenderService;

/**
 * Public, unauthenticated endpoint for the flyer.html contact form
 * (public/flyer.html -- a static page, so it can't hold a session-based
 * CSRF token). Security instead comes from a honeypot field and a basic
 * per-IP rate limit, the same shape as ApplicationIntakeController.
 */
class FlyerContactController extends Controller
{
    private const RECIPIENT_EMAIL = 'nestor@kodecamp.org';
    private const RECIPIENT_NAME = 'DesertLedger';
    private const MAX_SUBMISSIONS_PER_HOUR = 5;
    private const EVENT_TYPE = 'FLYER_CONTACT_SUBMITTED';

    public function submit(): void
    {
        header('Content-Type: application/json');

        $ip = ClientIp::resolve() ?: '0.0.0.0';

        // Honeypot: a hidden field real visitors never fill. A bot that
        // fills every field trips this -- respond as if it worked so it
        // doesn't learn to skip the field, but never send anything.
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            echo json_encode(['status' => 'success']);
            return;
        }

        if ($this->recentSubmissionCount($ip) >= self::MAX_SUBMISSIONS_PER_HOUR) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many messages sent from this connection. Please try again later.']);
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Enter your name.']);
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Enter a valid email address.']);
            return;
        }
        if ($message === '' || mb_strlen($message) > 4000) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Enter a message (up to 4000 characters).']);
            return;
        }

        SecurityEvent::record(self::EVENT_TYPE, 'Low', [
            'description' => 'Flyer contact form submitted',
            'ip' => $ip,
            'metadata' => ['name' => $name, 'email' => $email],
        ]);

        $body = "New enquiry from the DesertLedger flyer page:\n\n"
            . "Name: {$name}\n"
            . "Email: {$email}\n\n"
            . "Message:\n{$message}\n";

        $result = EmailSenderService::send(
            self::RECIPIENT_EMAIL,
            'DesertLedger enquiry from ' . $name,
            $body,
            self::RECIPIENT_NAME,
            false,
            [],
            $email,
            $name
        );

        if (!$result['success']) {
            http_response_code(502);
            echo json_encode(['status' => 'error', 'message' => 'Could not send your message right now -- please email us directly instead.']);
            return;
        }

        echo json_encode(['status' => 'success']);
    }

    private function recentSubmissionCount(string $ip, int $sinceMinutes = 60): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM security_events
             WHERE event_type = ? AND ip_address = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([self::EVENT_TYPE, $ip, $sinceMinutes]);
        return (int) $stmt->fetchColumn();
    }
}
