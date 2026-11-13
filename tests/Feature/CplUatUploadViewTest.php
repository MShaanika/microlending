<?php

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * What the two sign-off pages show for the upload features, in each state: ready, not ready, already uploaded,
 * damaged data. Renders the real templates; any PHP notice, warning or deprecation inside them fails the test.
 */
class CplUatUploadViewTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
    }

    /** @param array<string, mixed> $data */
    private function render(string $fragment, array $data): string
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        ob_start();
        try {
            View::renderFragment($fragment, $data);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }

    /** @return array<string, mixed> */
    private function showData(array $upload = [], array $batch = []): array
    {
        return [
            'batch' => array_merge([
                'id' => 7, 'batch_type' => 'Monthly', 'month_end' => '2026-10-31', 'blocking_error_count' => 0, 'total_records' => 5, 'warning_count' => 0,
                'submission_outcome' => null, 'submission_notes' => null, 'submission_recorded_at' => null,
            ], $batch),
            'testRecords' => [['fields' => ['account_no' => 'UATTEST-MONTHLY-001'], 'status' => 'Valid', 'issues' => []]],
            'upload' => array_merge([
                'filename' => 'NA02629_ALL_T702_M_20261031_1_1.txt', 'kind' => 'Monthly', 'record_count' => 5, 'problem' => null, 'can_upload' => true,
                'destination' => 'sftp.test.invalid / In', 'uploaded_at' => null, 'uploaded_note' => null, 'recorded_outcome' => null, 'supplier_ref' => 'NA02629', 'trading_name' => 'Solid Desert Cash Loan',
            ], $upload),
        ];
    }

    private function renderShow(array $upload = [], array $batch = []): string
    {
        return $this->render('reports/cpl_export/uat_signoff/show', $this->showData($upload, $batch));
    }

    /** @return array<string, mixed> */
    private function indexData(array $fileUpload): array
    {
        return [
            'overall' => 'NOT READY', 'authStatus' => 'PASSED', 'searchStatus' => 'PASSED', 'reportStatus' => 'PASSED', 'pdfStatus' => 'PASSED',
            'dailyStatus' => 'READY', 'monthlyStatus' => 'SUBMITTED', 'latestDailyBatch' => null, 'latestMonthlyBatch' => null,
            'fileUpload' => $fileUpload,
        ];
    }

    /** The value of the first onsubmit attribute in the html, as the browser would read it (entities decoded). */
    private function onsubmitOf(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
            foreach ($doc->getElementsByTagName('form') as $form) {
                if ($form->hasAttribute('onsubmit')) {
                    return $form->getAttribute('onsubmit');
                }
            }
            return '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    // ---- the page of one test batch ---------------------------------------------------

    public function testTheUploadIsOfferedWhenEverythingIsInOrder(): void
    {
        $html = $this->renderShow();

        $this->assertStringContainsString('Send to Creditinfo', $html);
        $this->assertStringContainsString('<code>NA02629_ALL_T702_M_20261031_1_1.txt</code>', $html);
        $this->assertStringContainsString('(5 records)', $html);
        $this->assertStringContainsString('sftp.test.invalid / In', $html);
        $this->assertStringContainsString('/reports/cpl-export/uat-signoff/7/upload-sftp', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('Upload to Creditinfo', $html);
        $this->assertStringNotContainsString('Upload again', $html);
        $this->assertStringNotContainsString('Already uploaded', $html);
        $this->assertStringNotContainsString('uatUploadMail', $html);
        $this->assertStringNotContainsString('Cannot upload yet', $html);
        $this->assertStringContainsString('cannot be taken back', $this->onsubmitOf($html));
    }

    public function testNoUploadIsOfferedWhenItCannotGoAheadAndTheReasonIsShownEscaped(): void
    {
        $html = $this->renderShow(['can_upload' => false, 'problem' => 'Bad <b>thing</b> & "quotes"']);

        $this->assertStringNotContainsString('/upload-sftp', $html, 'no form to post to');
        $this->assertStringNotContainsString('Upload to Creditinfo', $html);
        $this->assertStringContainsString('Cannot upload yet.', $html);
        $this->assertStringContainsString('Bad &lt;b&gt;thing&lt;/b&gt; &amp; &quot;quotes&quot;', $html);
        $this->assertStringNotContainsString('<b>thing</b>', $html);
    }

    public function testAnUploadedBatchShowsWhenAndOffersUploadAgainWithACopyReadyMessage(): void
    {
        $html = $this->renderShow([
            'uploaded_at' => '2026-11-13 14:05:00',
            'uploaded_note' => 'Uploaded to Creditinfo SFTP on 13 Nov 2026 14:05 as NA02629_ALL_T702_M_20261031_1_1.txt (5 records, 4912 bytes). Waiting for the Creditinfo load report.',
        ]);

        $this->assertStringContainsString('Already uploaded.', $html);
        $this->assertStringContainsString('Waiting for the Creditinfo load report.', $html);
        $this->assertStringContainsString('Upload again', $html);
        $this->assertStringContainsString('id="uatUploadMail"', $html);
        foreach (['Subject: Solid Desert Cash Loan (NA02629) - CPL Monthly sign-off test file uploaded', 'on 13 Nov 2026 at 14:05', 'File: NA02629_ALL_T702_M_20261031_1_1.txt', 'Records: 5', 'Supplier reference number: NA02629'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        $this->assertStringContainsString('was already uploaded on 13 Nov 2026 at 14:05', $this->onsubmitOf($html));
    }

    public function testARecordedResultWithoutOurUploadNoteStillWarnsBeforeSendingAgain(): void
    {
        $html = $this->renderShow(['recorded_outcome' => 'Rejected'], ['submission_outcome' => 'Rejected', 'submission_notes' => 'Rejected: ID not found', 'submission_recorded_at' => '2026-11-12 09:00:00']);

        $this->assertStringContainsString('Upload again', $html);
        $this->assertStringContainsString('A result is already recorded for this file (Rejected)', $this->onsubmitOf($html));
        $this->assertStringNotContainsString('Already uploaded.', $html, 'there is no upload note of ours to show');
        $this->assertStringNotContainsString('id="uatUploadMail"', $html);
    }

    public function testAnAcceptedFileOffersNoUploadAndSaysWhy(): void
    {
        $html = $this->renderShow(
            ['can_upload' => false, 'recorded_outcome' => 'Accepted', 'problem' => 'Creditinfo has already accepted this test file (the result is recorded on its page), so it does not need to be sent again. Nothing was sent.'],
            ['submission_outcome' => 'Accepted', 'submission_notes' => 'Load report ref 123', 'submission_recorded_at' => '2026-11-12 09:00:00']
        );

        $this->assertStringContainsString('Cannot upload yet.', $html);
        $this->assertStringContainsString('already accepted this test file', $html);
        $this->assertStringNotContainsString('/upload-sftp', $html);
        $this->assertStringContainsString('Current outcome:', $html);
        $this->assertStringContainsString('Accepted', $html);
    }

    public function testAnUploadedBatchWhoseConnectionIsLaterSwitchedOffStillShowsTheRecord(): void
    {
        $html = $this->renderShow([
            'can_upload' => false,
            'problem' => 'The SFTP connection is not set up or not switched on yet.',
            'uploaded_at' => '2026-11-13 14:05:00',
            'uploaded_note' => 'Uploaded to Creditinfo SFTP on 13 Nov 2026 14:05 as x.',
        ]);

        $this->assertStringContainsString('Already uploaded.', $html);
        $this->assertStringContainsString('Cannot upload yet.', $html);
        $this->assertStringNotContainsString('/upload-sftp', $html);
        $this->assertStringContainsString('id="uatUploadMail"', $html, 'the message to Creditinfo is still there to copy');
    }

    public function testDamagedBatchDataRendersWithoutAnyPhpNotice(): void
    {
        $html = $this->renderShow(['filename' => null, 'record_count' => 0, 'can_upload' => false, 'problem' => 'The stored test records are damaged. Generate the test file again.', 'destination' => '']);

        $this->assertStringContainsString('Cannot upload yet.', $html);
        $this->assertStringContainsString('damaged', $html);
        $this->assertStringNotContainsString('File name', $html);
        $this->assertStringNotContainsString('/upload-sftp', $html);
    }

    public function testHostileTextCannotBreakOutOfTheConfirmQuestionOrTheMessageBox(): void
    {
        $evilName = "x'\"</script><img src=x onerror=alert(1)>.txt";
        $html = $this->renderShow([
            'filename' => $evilName,
            'trading_name' => '</textarea><script>alert(1)</script>',
            'uploaded_at' => '2026-11-13 14:05:00',
            'uploaded_note' => '</div><script>alert(2)</script>',
        ]);

        $this->assertStringNotContainsString('</textarea><script>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        // What the browser reads from the attribute must be exactly one JavaScript string literal that decodes to the question.
        $this->assertSame(1, preg_match('/^return confirm\((.*)\);$/s', $this->onsubmitOf($html), $m), 'the handler is one confirm(...) call');
        $question = json_decode($m[1]);
        $this->assertIsString($question, 'and its argument is a single well-formed string literal');
        $this->assertStringContainsString($evilName, $question, 'carrying the file name intact');
    }

    // ---- the sign-off dashboard -----------------------------------------------------------

    public function testTheDashboardOffersToUploadAFileChosenFromTheComputer(): void
    {
        $html = $this->render('reports/cpl_export/uat_signoff/index', $this->indexData(['ready' => true, 'destination' => 'sftp.test.invalid / In', 'problem' => null]));

        $this->assertStringContainsString('Upload a test file you already have', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('/reports/cpl-export/uat-signoff/upload-file', $html);
        $this->assertStringContainsString('type="file" name="test_file"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('sftp.test.invalid / In', $html);
        $this->assertStringContainsString('if even one character', $html);
        $this->assertStringNotContainsString('Cannot upload yet', $html);
        $this->assertStringContainsString('cannot be taken back', $this->onsubmitOf($html));
    }

    public function testTheDashboardExplainsWhyAFileCannotBeUploadedYet(): void
    {
        $html = $this->render('reports/cpl_export/uat_signoff/index', $this->indexData(['ready' => false, 'destination' => '', 'problem' => 'The SFTP connection is not set up or not switched on yet.']));

        $this->assertStringContainsString('Cannot upload yet.', $html);
        $this->assertStringContainsString('not set up or not switched on', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('/upload-file', $html);
    }

    public function testTheDashboardStillExistsWithoutTheFileUploadVariable(): void
    {
        $data = $this->indexData([]);
        unset($data['fileUpload']);
        $data['fileUpload'] = [];

        $html = $this->render('reports/cpl_export/uat_signoff/index', $data);

        $this->assertStringContainsString('Cannot upload yet.', $html, 'an empty status is treated as not ready, never as ready');
        $this->assertStringNotContainsString('type="file"', $html);
    }
}
