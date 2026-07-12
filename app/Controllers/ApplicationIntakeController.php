<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Models\IntakeSource;
use App\Models\LoanApplication;

/**
 * Public, unauthenticated endpoint that an external client website's own
 * "Apply Now" form POSTs to directly (cross-origin -- no session, so this
 * cannot use the normal session-based CSRF token). Security instead comes
 * from a per-source api_token + a basic per-IP rate limit.
 *
 * A new client with a differently-named form just needs an intake_sources
 * row + intake_field_mappings rows (see database/schema.sql) -- this
 * controller never hardcodes a client's field names.
 */
class ApplicationIntakeController extends Controller
{
    private IntakeSource $sources;
    private LoanApplication $applications;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const ALLOWED_DOCUMENT_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_SUBMISSIONS_PER_HOUR = 10;

    public function __construct()
    {
        $this->sources = new IntakeSource();
        $this->applications = new LoanApplication();
    }

    public function submit(string $sourceCode): void
    {
        header('Content-Type: application/json');

        $token = (string) ($_POST['_source_token'] ?? '');
        $source = $this->sources->findActiveByCodeAndToken($sourceCode, $token);

        if (!$source) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or inactive source.']);
            return;
        }

        if ($source['allowed_origin']) {
            header('Access-Control-Allow-Origin: ' . $source['allowed_origin']);
        }

        $ip = $this->clientIp();
        if ($this->sources->recentSubmissionCount((int) $source['id'], $ip) >= self::MAX_SUBMISSIONS_PER_HOUR) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many submissions. Please try again later.']);
            return;
        }

        $mappings = $this->sources->fieldMappings((int) $source['id']);
        if (empty($mappings)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'This source has no field mapping configured.']);
            return;
        }

        [$canonical, $extra, $missing] = $this->normalize($_POST, $mappings);
        if (!empty($missing)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required field(s): ' . implode(', ', $missing)]);
            return;
        }

        $applicationNo = generate_reference('APP');

        $applicationData = array_merge($canonical, [
            'intake_source_id' => (int) $source['id'],
            'application_no' => $applicationNo,
            'application_source' => 'Online',
            'application_type' => 'New Loan',
            'status' => 'Submitted',
            'ip_address' => $ip,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'extra_data' => empty($extra) ? null : json_encode($extra),
        ]);
        $applicationData['requested_amount'] = isset($applicationData['requested_amount']) ? (float) $applicationData['requested_amount'] : 0;
        $applicationData['requested_term_months'] = isset($applicationData['requested_term_months']) ? (int) $applicationData['requested_term_months'] : 1;
        foreach (['gross_salary', 'net_salary'] as $moneyField) {
            if (isset($applicationData[$moneyField])) {
                $applicationData[$moneyField] = (float) $applicationData[$moneyField];
            }
        }
        if (isset($applicationData['payment_day'])) {
            $applicationData['payment_day'] = (int) $applicationData['payment_day'];
        }

        $applicationId = $this->applications->create($applicationData);
        $this->applications->addStatusHistory($applicationId, null, 'Submitted', null, 'Submitted online via ' . $source['source_name'] . '.');

        $fileErrors = $this->storeDocuments($applicationId, $applicationNo);
        $signatureErrors = $this->storeSignatures($applicationId, $applicationNo);

        $this->sources->logSubmission((int) $source['id'], $ip);
        Audit::log('Create', 'Applications', 'Online application ' . $applicationNo . ' submitted via ' . $source['source_name'] . '.');

        $warnings = array_merge($fileErrors, $signatureErrors);

        echo json_encode([
            'status' => 'success',
            'application_no' => $applicationNo,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return array{0: array, 1: array, 2: array} [canonical loan_applications columns, extra_data map, missing required field names]
     */
    private function normalize(array $post, array $mappings): array
    {
        $canonical = [];
        $extra = [];
        $missing = [];

        foreach ($mappings as $mapping) {
            $incoming = $mapping['incoming_field_name'];
            $value = trim((string) ($post[$incoming] ?? ''));

            if ($value === '') {
                if ((int) $mapping['is_required'] === 1) {
                    $missing[] = $incoming;
                }
                continue;
            }

            $target = $mapping['target_field'];
            if (str_starts_with($target, 'extra:')) {
                $extra[substr($target, 6)] = $value;
            } else {
                $canonical[$target] = $value;
            }
        }

        return [$canonical, $extra, $missing];
    }

    /**
     * @return string[] Non-fatal warnings (e.g. a document was skipped) --
     *  a rejected document should not fail the whole application submission.
     */
    private function storeDocuments(int $applicationId, string $applicationNo): array
    {
        $warnings = [];
        $targetDir = $this->targetDir($applicationNo);

        $fileFields = ['payslip', 'id_copy', 'bank_statement_merged', 'bank_statement_1', 'bank_statement_2', 'bank_statement_3'];
        foreach ($fileFields as $field) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $result = $this->storeOneUpload($_FILES[$field], $field, $targetDir);
            if ($result === null) {
                $warnings[] = "Could not accept '$field': invalid or oversized file.";
                continue;
            }

            $this->applications->addDocument([
                'application_id' => $applicationId,
                'document_type' => $this->documentTypeLabel($field),
                'document_name' => $_FILES[$field]['name'],
                'file_path' => $result,
                'status' => 'Pending',
            ]);
        }

        return $warnings;
    }

    private function documentTypeLabel(string $field): string
    {
        return match ($field) {
            'payslip' => 'Payslip',
            'id_copy' => 'ID Copy',
            'bank_statement_merged' => 'Bank Statement (Merged)',
            'bank_statement_1', 'bank_statement_2', 'bank_statement_3' => 'Bank Statement',
            default => 'Document',
        };
    }

    private function storeOneUpload(array $file, string $fieldName, string $targetDir): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_DOCUMENT_SIZE) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return null;
        }

        // Real MIME sniffing, not just the client-supplied extension/type --
        // the legacy backend trusted $_FILES[...]['type'] outright.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($realMime, self::ALLOWED_DOCUMENT_MIMES, true)) {
            return null;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $storedName = $fieldName . '_' . uniqid('', true) . '.' . $ext;
        $destination = $targetDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return null;
        }

        return 'uploads/loan_applications/' . basename($targetDir) . '/' . $storedName;
    }

    /**
     * Canvas signatures arrive as base64 data URLs. The legacy backend
     * base64_decode()'d and wrote them straight to disk with no check that
     * the payload was actually an image -- decode, then verify with
     * getimagesizefromstring() before anything touches the filesystem.
     */
    private function storeSignatures(int $applicationId, string $applicationNo): array
    {
        $warnings = [];
        $targetDir = $this->targetDir($applicationNo);

        $signatures = [
            'borrower_signature' => 'Signature - Borrower',
            'witness_signature' => 'Signature - Witness',
        ];

        foreach ($signatures as $field => $label) {
            $raw = (string) ($_POST[$field] ?? '');
            if ($raw === '') {
                continue;
            }

            $binary = $this->decodeSignatureImage($raw);
            if ($binary === null) {
                $warnings[] = "Could not accept '$field': not a valid image.";
                continue;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $storedName = $field . '_' . uniqid('', true) . '.png';
            $destination = $targetDir . '/' . $storedName;
            file_put_contents($destination, $binary);

            $this->applications->addDocument([
                'application_id' => $applicationId,
                'document_type' => $label,
                'document_name' => $label,
                'file_path' => 'uploads/loan_applications/' . basename($targetDir) . '/' . $storedName,
                'status' => 'Pending',
            ]);
        }

        return $warnings;
    }

    private function decodeSignatureImage(string $dataUrl): ?string
    {
        $payload = preg_replace('#^data:image/\w+;base64,#', '', $dataUrl);
        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return null;
        }

        return $binary;
    }

    private function targetDir(string $applicationNo): string
    {
        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $applicationNo);
        return STORAGE_PATH . '/uploads/loan_applications/' . $safeFolder;
    }

    private function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
