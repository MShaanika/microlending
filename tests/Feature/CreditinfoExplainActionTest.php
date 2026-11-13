<?php

namespace Tests\Feature;

use App\Controllers\CreditinfoAssessmentController;
use App\Core\Database;
use App\Core\Session;
use App\Models\CreditinfoReportContent;
use App\Models\NotificationSetting;
use App\Services\CreditinfoReportExplainer;
use PHPUnit\Framework\TestCase;

/**
 * The "Explain this report" action, run for real against the local database with a fake AI provider: who may use it, what it refuses, what it
 * stores (encrypted, on the report's own row), what it writes to the audit trail (no personal data), and that a failed or repeated request
 * never loses an explanation that already exists.
 */
class CreditinfoExplainActionTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $savedSession = null;
    private ?array $savedPost = null;

    private \PDO $db;
    private int $userId = 0;
    /** @var int[] */
    private array $applicationIds = [];
    /** @var int[] */
    private array $consentIds = [];
    /** @var int[] */
    private array $cacheIds = [];

    /** Planted in the report, plus the National ID the credit check was made with (stored on the cache row). */
    private const SECRETS = ['Shikongo', 'Maria', '80050412345', 'maria.shikongo@example.com', 'First National Bank', 'FNB-998877', '77082851070'];

    private string $previousErrorLog = '';

    protected function setUp(): void
    {
        // The explainer logs provider failures; keep that out of the test output.
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'explain_action_test_error.log');

        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $this->savedPost = $_POST;
        $this->db = Database::connection();

        $suffix = bin2hex(random_bytes(4));
        $this->db->prepare("INSERT INTO users (branch_id, name, username, email, password, user_type, is_active, bypass_ip_restriction) VALUES (1, ?, ?, ?, ?, 'Staff', 1, 1)")
            ->execute(['Explain Test', 'explain_test_' . $suffix, 'explain_test_' . $suffix . '@example.invalid', password_hash('x', PASSWORD_DEFAULT)]);
        $this->userId = (int) $this->db->lastInsertId();

        $_SESSION = [
            'user' => ['id' => $this->userId, 'user_type' => 'Staff', 'branch_id' => 1, 'permissions' => ['applications.credit_check']],
            '_csrf_token' => 'test-token',
        ];
        $_POST = ['_csrf' => 'test-token'];
    }

    protected function tearDown(): void
    {
        $run = function (string $sql, array $params = []): void {
            try {
                $this->db->prepare($sql)->execute($params);
            } catch (\Throwable $e) {
                // keep cleaning up whatever else can be removed
            }
        };
        foreach ($this->cacheIds as $id) {
            $run('DELETE FROM creditinfo_report_content WHERE report_cache_id = ?', [$id]);
            $run('DELETE FROM creditinfo_report_cache WHERE id = ?', [$id]);
        }
        foreach ($this->consentIds as $id) {
            $run('DELETE FROM credit_bureau_consents WHERE id = ?', [$id]);
        }
        foreach ($this->applicationIds as $id) {
            $run('DELETE FROM loan_applications WHERE id = ?', [$id]);
        }
        $run('DELETE FROM audit_logs WHERE user_id = ?', [$this->userId]);
        $run('DELETE FROM security_events WHERE user_id = ?', [$this->userId]);
        $run('DELETE FROM users WHERE id = ?', [$this->userId]);

        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
        $_POST = $this->savedPost ?? [];
        ini_set('error_log', $this->previousErrorLog);
    }

    // ---- fixtures ----------------------------------------------------------------------------------------------------

    /** @return array{app: int, cache: int} */
    private function makeApplicationWithReport(?int $branchId = null, bool $withReport = true): array
    {
        $this->db->prepare('INSERT INTO loan_applications (application_no, branch_id) VALUES (?, ?)')->execute(['EXPL-' . bin2hex(random_bytes(5)), $branchId]);
        $appId = (int) $this->db->lastInsertId();
        $this->applicationIds[] = $appId;

        $this->db->prepare("INSERT INTO credit_bureau_consents (application_id, consent_given, consent_method, recorded_by, recorded_at) VALUES (?, 1, 'Test', ?, NOW())")->execute([$appId, $this->userId]);
        $consentId = (int) $this->db->lastInsertId();
        $this->consentIds[] = $consentId;

        $this->db->prepare("INSERT INTO creditinfo_report_cache (application_id, consent_id, requested_by, national_id_used, is_uat_test_id, environment, search_outcome, report_status) VALUES (?, ?, ?, '77082851070', 1, 'uat', 'SubjectFound', 'Finished')")
            ->execute([$appId, $consentId, $this->userId]);
        $cacheId = (int) $this->db->lastInsertId();
        $this->cacheIds[] = $cacheId;

        if ($withReport) {
            (new CreditinfoReportContent())->storeReport($cacheId, $this->report(), 'TOKEN');
        }
        return ['app' => $appId, 'cache' => $cacheId];
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'dashboard' => ['score' => 531, 'grade' => 'E2', 'probabilityOfDefault' => 76],
            'individual' => ['surname' => 'Shikongo', 'firstName' => 'Maria', 'nationalId' => '80050412345'],
            'subjectInfoHistory' => [['email' => 'maria.shikongo@example.com']],
            'contracts' => [['subscriberName' => 'First National Bank of Namibia', 'contractCode' => 'FNB-998877', 'overdueAmount' => 43757.85, 'daysPastDue' => 1461]],
        ];
    }

    /** What the fake AI answers (already in the strict-schema shape). */
    private function answer(): array
    {
        return [
            'risk_level' => 'very_high', 'summary' => 'This credit record shows a very high risk.', 'score' => 531, 'grade' => 'E2', 'probability_of_default_percent' => 76,
            'trend_note' => null, 'findings' => [['status' => 'bad', 'title' => 'Overdue', 'detail' => 'Lender A has N$43,757.85 overdue for 1,461 days.', 'uncertain' => false]],
            'reasons' => [], 'payment_months' => [],
            'accounts' => [['kind' => 'Personal loan', 'lender' => 'Lender A', 'owes' => null, 'overdue' => 43757.85, 'days_late' => 1461, 'status' => 'behind']],
            'closed_accounts_note' => null, 'check_next' => [], 'uncertainties' => [],
        ];
    }

    private function settings(string $key): NotificationSetting
    {
        return new class($key) extends NotificationSetting {
            public function __construct(private string $key)
            {
            }

            public function get(string $key, string $default = ''): string
            {
                return $key === 'OPENAI_API_KEY' ? $this->key : ($key === 'OPENAI_MODEL' ? 'test-model' : $default);
            }
        };
    }

    /**
     * Runs the action for an application; returns where it redirected to. $calls counts the requests the fake AI provider received.
     *
     * @param callable|null $transport fn(array $payload, string $key): array, defaults to a good answer
     */
    private function callExplain(int $applicationId, ?callable $transport = null, int &$calls = 0, string $apiKey = 'sk-test'): string
    {
        $good = fn () => ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output_text' => json_encode($this->answer())]), 'error' => null];
        $inner = $transport ?? $good;
        $counting = function (array $payload, string $key) use ($inner, &$calls): array {
            $calls++;
            return $inner($payload, $key);
        };
        $settings = $this->settings($apiKey);

        $controller = new class($counting, $settings) extends CreditinfoAssessmentController {
            public function __construct(private $transport, private NotificationSetting $settingsStub)
            {
                parent::__construct();
            }

            protected function explainer(): CreditinfoReportExplainer
            {
                return new CreditinfoReportExplainer($this->transport, $this->settingsStub);
            }

            protected function redirect(string $path): void
            {
                throw new class($path) extends \RuntimeException {
                    public function __construct(public string $path)
                    {
                        parent::__construct($path);
                    }
                };
            }
        };

        try {
            $controller->explainAssessment((string) $applicationId);
        } catch (\RuntimeException $redirect) {
            if (property_exists($redirect, 'path')) {
                return $redirect->path;
            }
            throw $redirect;
        }
        $this->fail('The action finished without redirecting');
    }

    /**
     * Runs the page action for an application and returns the data it hands to the view (or the path it redirected to).
     *
     * @return array<string, mixed>|string
     */
    private function callView(int $applicationId): array|string
    {
        $controller = new class extends CreditinfoAssessmentController {
            public ?array $viewData = null;

            protected function view(string $view, array $data = []): void
            {
                $this->viewData = $data;
            }

            protected function redirect(string $path): void
            {
                throw new class($path) extends \RuntimeException {
                    public function __construct(public string $path)
                    {
                        parent::__construct($path);
                    }
                };
            }
        };

        try {
            $controller->viewAssessment((string) $applicationId);
        } catch (\RuntimeException $redirect) {
            if (property_exists($redirect, 'path')) {
                return $redirect->path;
            }
            throw $redirect;
        }
        return $controller->viewData ?? [];
    }

    private function explanationOf(int $cacheId): ?array
    {
        $model = new CreditinfoReportContent();
        $row = $model->findByReportCacheId($cacheId);
        return $row ? $model->decryptedExplanation($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function auditRows(string $action): array
    {
        $stmt = $this->db->prepare("SELECT * FROM audit_logs WHERE user_id = ? AND module_name = 'Creditinfo' AND action = ? ORDER BY id");
        $stmt->execute([$this->userId, $action]);
        return $stmt->fetchAll();
    }

    private function storeEarlier(int $cacheId, int $secondsAgo): void
    {
        (new CreditinfoReportContent())->storeExplanation($cacheId, ['version' => 1, 'risk_level' => 'low', 'summary' => 'earlier', 'meta' => ['generated_at' => date('Y-m-d H:i:s', time() - $secondsAgo)]]);
    }

    // ---- the good path -----------------------------------------------------------------------------------------------

    public function testAnExplanationIsStoredEncryptedOnTheReportsOwnRowAndAuditedWithoutPersonalData(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();

        $path = $this->callExplain($app);

        $this->assertSame('/applications/' . $app . '/creditinfo/assessment', $path);
        $stored = $this->explanationOf($cache);
        $this->assertSame('very_high', $stored['risk_level']);
        $this->assertEquals(531, $stored['score'], 'JSON keeps a whole number whole, so it may come back as an integer');
        $this->assertSame($this->userId, $stored['meta']['generated_by']);
        $this->assertArrayNotHasKey('model', $stored['meta'], 'the name of the AI model is not kept with the explanation');
        $this->assertStringNotContainsString('test-model', (string) json_encode($stored));

        // The real lender names are kept with the explanation (so the page can show them) ...
        $this->assertSame('First National Bank of Namibia', $stored['lenders']['Lender A']);
        // ... and the whole thing is encrypted at rest, names included.
        $raw = (string) (new CreditinfoReportContent())->findByReportCacheId($cache)['explanation_encrypted'];
        $this->assertNotSame('', $raw);
        foreach (['very_high', 'Lender A', 'credit record', 'First National Bank'] as $readable) {
            $this->assertStringNotContainsString($readable, $raw, 'the explanation is encrypted at rest');
        }

        $audit = $this->auditRows('Create');
        $this->assertCount(1, $audit);
        $this->assertStringContainsString('application #' . $app, $audit[0]['description']);
        $meta = json_decode((string) $audit[0]['metadata'], true);
        $this->assertArrayNotHasKey('model', $meta, 'the name of the AI model is secret: not in the audit trail either');
        $this->assertSame(['dashboard', 'contracts'], $meta['sections']);
        $this->assertFalse($meta['replaced_earlier_explanation']);
        $this->assertArrayHasKey('unverified_figures', $meta);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($audit[0]), "'$secret' must not reach the audit trail");
        }
        $this->assertStringNotContainsString('test-model', json_encode($audit[0]));
        $this->assertSame('Done. The report is explained below.', Session::flash('success'));
    }

    public function testOnlyTheAnonymousCopyReachedTheProvider(): void
    {
        ['app' => $app] = $this->makeApplicationWithReport();
        $seen = '';

        $this->callExplain($app, function (array $payload) use (&$seen): array {
            $seen = json_encode($payload);
            return ['status' => 200, 'body' => json_encode(['output_text' => json_encode($this->answer())]), 'error' => null];
        });

        $this->assertNotSame('', $seen);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $seen, "'$secret' must never be sent");
        }
    }

    public function testExplainingAgainReplacesTheEarlierExplanationOnceTheWaitIsOver(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->storeEarlier($cache, 120);

        $this->callExplain($app);

        $this->assertSame('very_high', $this->explanationOf($cache)['risk_level']);
        $meta = json_decode((string) $this->auditRows('Create')[0]['metadata'], true);
        $this->assertTrue($meta['replaced_earlier_explanation']);
    }

    public function testASecondApplicationsReportIsNeverTouched(): void
    {
        ['app' => $first] = $this->makeApplicationWithReport();
        ['cache' => $otherCache] = $this->makeApplicationWithReport();

        $this->callExplain($first);

        $this->assertNull($this->explanationOf($otherCache));
    }

    // ---- refusals ----------------------------------------------------------------------------------------------------

    public function testABadSecurityTokenIsRefusedBeforeAnythingIsSent(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $_POST['_csrf'] = 'not-the-token';
        $calls = 0;

        $path = $this->callExplain($app, null, $calls);

        $this->assertSame('/applications/' . $app . '/creditinfo/assessment', $path);
        $this->assertSame('Security token expired. Please try again.', Session::flash('error'));
        $this->assertSame(0, $calls);
        $this->assertNull($this->explanationOf($cache));
        $this->assertSame([], $this->auditRows('Create'));
    }

    public function testAMissingTokenIsRefusedToo(): void
    {
        ['app' => $app] = $this->makeApplicationWithReport();
        unset($_POST['_csrf']);
        $calls = 0;

        $this->callExplain($app, null, $calls);

        $this->assertSame(0, $calls);
        $this->assertSame('Security token expired. Please try again.', Session::flash('error'));
    }

    public function testAnUnknownApplicationIsRefused(): void
    {
        $calls = 0;

        $path = $this->callExplain(2147483000, null, $calls);

        $this->assertSame('/applications', $path);
        $this->assertSame('Application not found.', Session::flash('error'));
        $this->assertSame(0, $calls);
    }

    public function testAnApplicationOfAnotherBranchIsRefusedForANormalUser(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport(1);
        $_SESSION['user']['branch_id'] = 999999;
        $calls = 0;

        $path = $this->callExplain($app, null, $calls);

        $this->assertSame('/applications', $path);
        $this->assertSame('Application not found.', Session::flash('error'));
        $this->assertSame(0, $calls);
        $this->assertNull($this->explanationOf($cache));
    }

    public function testASuperAdminMayExplainAnyBranchesReport(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport(1);
        $_SESSION['user']['branch_id'] = 999999;
        $_SESSION['user']['user_type'] = 'Super Admin';

        $this->callExplain($app);

        $this->assertNotNull($this->explanationOf($cache));
    }

    public function testAnApplicationWithoutAStoredReportHasNothingToExplain(): void
    {
        ['app' => $app] = $this->makeApplicationWithReport(null, false);
        $calls = 0;

        $path = $this->callExplain($app, null, $calls);

        $this->assertSame('/applications/' . $app . '/creditinfo/assessment', $path);
        $this->assertStringContainsString('no stored report to explain', (string) Session::flash('error'));
        $this->assertSame(0, $calls);
    }

    public function testAnApplicationWithNoCreditCheckAtAllHasNothingToExplain(): void
    {
        $this->db->prepare('INSERT INTO loan_applications (application_no) VALUES (?)')->execute(['EXPL-' . bin2hex(random_bytes(5))]);
        $app = (int) $this->db->lastInsertId();
        $this->applicationIds[] = $app;
        $calls = 0;

        $this->callExplain($app, null, $calls);

        $this->assertStringContainsString('no stored report to explain', (string) Session::flash('error'));
        $this->assertSame(0, $calls);
    }

    public function testARecentExplanationCannotBeRequestedAgainStraightAway(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->storeEarlier($cache, 10);
        $calls = 0;

        $this->callExplain($app, null, $calls);

        $this->assertMatchesRegularExpression('/Please wait \d+ seconds before asking again/', (string) Session::flash('error'));
        $this->assertSame(0, $calls, 'a paid call must not be made');
        $this->assertSame('low', $this->explanationOf($cache)['risk_level'], 'the earlier explanation is untouched');
    }

    public function testWithoutAnApiKeyNothingIsSentAndTheErrorSaysWhereToAddOne(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $calls = 0;

        $this->callExplain($app, null, $calls, '');

        $this->assertStringContainsString('Settings > AI Settings', (string) Session::flash('error'));
        $this->assertSame(0, $calls);
        $this->assertNull($this->explanationOf($cache));
    }

    // ---- when the provider fails -------------------------------------------------------------------------------------

    public function testAFailedRequestKeepsAnEarlierExplanationAndIsAudited(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->storeEarlier($cache, 300);

        $path = $this->callExplain($app, fn () => ['status' => 500, 'body' => '{}', 'error' => null]);

        $this->assertSame('/applications/' . $app . '/creditinfo/assessment', $path);
        $this->assertStringContainsString('HTTP 500', (string) Session::flash('error'));
        $this->assertSame('low', $this->explanationOf($cache)['risk_level'], 'the old explanation survives a failed attempt');
        $this->assertSame([], $this->auditRows('Create'));
        $failed = $this->auditRows('Uncertain');
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('application #' . $app, $failed[0]['description']);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($failed[0]));
        }
    }

    public function testAnUnreachableProviderStoresNothing(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();

        $this->callExplain($app, fn () => ['status' => 0, 'body' => null, 'error' => 'Could not resolve host']);

        $this->assertStringContainsString('Could not reach the AI provider', (string) Session::flash('error'));
        $this->assertNull($this->explanationOf($cache));
    }

    public function testAnAnswerThatCannotBeReadStoresNothing(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();

        $this->callExplain($app, fn () => ['status' => 200, 'body' => json_encode(['output_text' => 'not json']), 'error' => null]);

        $this->assertStringContainsString('could be read', (string) Session::flash('error'));
        $this->assertNull($this->explanationOf($cache));
    }

    // ---- the page --------------------------------------------------------------------------------------------------

    public function testThePageGetsTheExplanationAndTheExactAnonymousCopyThatWouldBeSent(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->callExplain($app);

        $data = $this->callView($app);

        $this->assertIsArray($data);
        $this->assertSame('very_high', $data['explanation']['risk_level']);
        $this->assertSame((new NotificationSetting())->get('OPENAI_API_KEY') !== '', $data['aiConfigured']);
        $this->assertSame('ok', $data['aiCopyState']);
        $this->assertSame(['dashboard', 'contracts'], array_keys($data['aiCopy']));
        $json = json_encode($data['aiCopy']);
        $this->assertStringContainsString('Lender A', $json);
        $this->assertStringContainsString('43757.85', $json);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json, "'$secret' must not be in the copy the page shows");
        }
        $this->assertSame($data['reportData']['dashboard']['score'], 531, 'the raw report is still given to the technical details');
        $this->assertNotNull($this->explanationOf($cache));
    }

    public function testThePageCopyIsTheSameThingTheProviderIsGiven(): void
    {
        ['app' => $app] = $this->makeApplicationWithReport();
        $seen = '';
        $this->callExplain($app, function (array $payload) use (&$seen): array {
            $seen = (string) $payload['input'][0]['content'][0]['text'];
            return ['status' => 200, 'body' => json_encode(['output_text' => json_encode($this->answer())]), 'error' => null];
        });

        $data = $this->callView($app);

        $this->assertStringContainsString(json_encode($data['aiCopy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $seen, 'what the page shows is what was sent');
    }

    public function testAWrappedStoredResponseIsAnonymisedLikeAPlainOne(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport(null, false);
        (new CreditinfoReportContent())->storeReport($cache, ['status' => 'Finished', 'report' => $this->report()], 'TOKEN');

        $data = $this->callView($app);

        $this->assertSame('ok', $data['aiCopyState']);
        $this->assertSame(['dashboard', 'contracts'], array_keys($data['aiCopy']));
    }

    public function testAReportThatCannotBeMadeAnonymousIsReportedToThePageNotThrown(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport(null, false);
        $report = $this->report();
        $report['contracts'][0]['8005041234567'] = 'x';
        (new CreditinfoReportContent())->storeReport($cache, $report, 'TOKEN');

        $data = $this->callView($app);

        $this->assertSame('unsafe', $data['aiCopyState']);
        $this->assertSame([], $data['aiCopy']);
        $this->assertNotEmpty($data['reportData'], 'the page still shows the raw report to the staff');
    }

    public function testAReportWithOnlyPersonalSectionsHasNothingToSend(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport(null, false);
        (new CreditinfoReportContent())->storeReport($cache, ['individual' => ['surname' => 'Shikongo'], 'reportInfo' => ['created' => '2026-11-13']], 'TOKEN');

        $data = $this->callView($app);

        $this->assertSame('empty', $data['aiCopyState']);
        $this->assertSame([], $data['aiCopy']);
    }

    public function testAPurgedReportHasNoCopyAndNoExplanation(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->callExplain($app);
        $this->db->prepare('DELETE FROM creditinfo_report_content WHERE report_cache_id = ?')->execute([$cache]);
        $this->db->prepare('UPDATE creditinfo_report_cache SET purged_at = NOW() WHERE id = ?')->execute([$cache]);

        $data = $this->callView($app);

        $this->assertNull($data['explanation']);
        $this->assertNull($data['reportData']);
        $this->assertTrue((bool) $data['purged']);
        $this->assertSame([], $data['aiCopy']);
    }

    public function testThePageOfAnotherBranchIsRefusedForANormalUser(): void
    {
        ['app' => $app] = $this->makeApplicationWithReport(1);
        $_SESSION['user']['branch_id'] = 999999;

        $this->assertSame('/applications', $this->callView($app));
    }

    public function testReplacingTheStoredReportDropsTheExplanationOfTheOldOne(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->callExplain($app);
        $this->assertNotNull($this->explanationOf($cache));
        $model = new CreditinfoReportContent();

        $model->storeReport($cache, ['dashboard' => ['score' => 700]], 'NEW-TOKEN');

        $this->assertNull($this->explanationOf($cache), 'an explanation of the old report must not sit beside a new one');
        $row = $model->findByReportCacheId($cache);
        $this->assertSame(700, $model->decryptedReport($row)['dashboard']['score']);
        $this->assertSame('NEW-TOKEN', $row['report_token']);
        $this->assertNull($row['explanation_encrypted']);
    }

    public function testReplacingAReportThatHasNoExplanationNeverTouchesTheExplanationColumn(): void
    {
        ['cache' => $cache] = $this->makeApplicationWithReport();
        // The explanation column is new: a server that does not have it yet must still be able to store reports (every credit check does). A row
        // read from such a server has no explanation_encrypted key at all; a row without an explanation has it empty. Neither may write it.
        $model = new class extends CreditinfoReportContent {
            public array $updates = [];
            public bool $hideColumn = true;

            public function findByReportCacheId(int $reportCacheId): ?array
            {
                $row = parent::findByReportCacheId($reportCacheId);
                if ($row !== null && $this->hideColumn) {
                    unset($row['explanation_encrypted']);
                }
                return $row;
            }

            public function updateFields(int $id, array $data): bool
            {
                $this->updates[] = $data;
                return parent::updateFields($id, $data);
            }
        };

        $model->storeReport($cache, ['dashboard' => ['score' => 701]], 'T2');
        $model->hideColumn = false;
        $model->storeReport($cache, ['dashboard' => ['score' => 702]], 'T3');

        $this->assertCount(2, $model->updates);
        foreach ($model->updates as $update) {
            $this->assertSame(['report_content_encrypted', 'report_token'], array_keys($update), 'the explanation column is only written when there is an explanation to clear');
        }
        $this->assertSame(702, $model->decryptedReport($model->findByReportCacheId($cache))['dashboard']['score']);
    }

    public function testTheExplanationLivesOnTheReportsOwnRowSoThePurgeTakesItAway(): void
    {
        ['app' => $app, 'cache' => $cache] = $this->makeApplicationWithReport();
        $this->callExplain($app);
        $this->assertNotNull($this->explanationOf($cache));

        // What the retention purge does: the content row is deleted, the audit-only cache row stays.
        $this->db->prepare('DELETE FROM creditinfo_report_content WHERE report_cache_id = ?')->execute([$cache]);

        $this->assertNull($this->explanationOf($cache));
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM creditinfo_report_cache WHERE id = ' . $cache)->fetchColumn());
    }
}
