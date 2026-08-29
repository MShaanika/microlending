<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\DecisionIntelligenceService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class DecisionIntelligenceServiceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdIds as $id) {
            $db->prepare('DELETE FROM exceptions WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    private function insertException(array $overrides = []): int
    {
        $db = Database::connection();
        $data = array_merge([
            'exception_uuid' => bin2hex(random_bytes(16)),
            'exception_type' => 'phpunit_di_type',
            'category' => 'Test',
            'module' => 'PHPUnitModule_' . uniqid(),
            'severity' => 'Medium',
            'status' => 'OPEN',
            'description' => 'PHPUnit DI test exception',
            'detected_at' => date('Y-m-d H:i:s'),
            'root_cause' => null,
            'resolved_at' => null,
        ], $overrides);

        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $db->prepare('INSERT INTO exceptions (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')')
            ->execute(array_values($data));

        $id = (int) $db->lastInsertId();
        $this->createdIds[] = $id;
        return $id;
    }

    public function testRecurringPatternsOnlyIncludesGroupsAtOrAboveTheThreshold(): void
    {
        $module = 'PHPUnitModule_' . uniqid();
        $type = 'recurring_type';

        for ($i = 0; $i < 3; $i++) {
            $this->insertException(['module' => $module, 'exception_type' => $type, 'category' => 'Technical']);
        }
        // A different type in the same module, only once -- must not qualify.
        $this->insertException(['module' => $module, 'exception_type' => 'one_off_type', 'category' => 'Technical']);

        $patterns = DecisionIntelligenceService::recurringPatterns(90, 3);
        $match = array_filter($patterns, fn ($p) => $p['module'] === $module && $p['exception_type'] === $type);
        $oneOff = array_filter($patterns, fn ($p) => $p['module'] === $module && $p['exception_type'] === 'one_off_type');

        $this->assertCount(1, $match);
        $this->assertSame(3, (int) array_values($match)[0]['occurrences']);
        $this->assertCount(0, $oneOff);
    }

    public function testHotspotsByModuleWeighsCriticalSeverityHigherThanLow(): void
    {
        $criticalModule = 'PHPUnitModule_' . uniqid();
        $lowModule = 'PHPUnitModule_' . uniqid();

        $this->insertException(['module' => $criticalModule, 'severity' => 'Critical']);
        $this->insertException(['module' => $lowModule, 'severity' => 'Low']);

        $hotspots = DecisionIntelligenceService::hotspotsByModule(30);
        $indexed = [];
        foreach ($hotspots as $h) {
            $indexed[$h['module']] = $h;
        }

        $this->assertGreaterThan($indexed[$lowModule]['score'], $indexed[$criticalModule]['score']);
    }

    public function testExceptionTrendBucketsIntoTheCorrectDay(): void
    {
        $twoDaysAgo = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->insertException(['detected_at' => $twoDaysAgo]);

        $trend = DecisionIntelligenceService::exceptionTrend(30);
        $targetDay = date('Y-m-d', strtotime('-2 days'));

        $bucket = array_values(array_filter($trend, fn ($r) => $r['date'] === $targetDay))[0];
        $this->assertGreaterThanOrEqual(1, $bucket['count']);
    }

    public function testResolutionMetricsComputesAverageHoursForResolvedExceptions(): void
    {
        $detected = date('Y-m-d H:i:s', strtotime('-10 hours'));
        $resolved = date('Y-m-d H:i:s');
        $module = 'PHPUnitModule_' . uniqid();

        $this->insertException([
            'module' => $module,
            'severity' => 'High',
            'status' => 'RESOLVED',
            'detected_at' => $detected,
            'resolved_at' => $resolved,
        ]);

        $metrics = DecisionIntelligenceService::resolutionMetrics(90);
        $high = array_values(array_filter($metrics, fn ($m) => $m['severity'] === 'High'))[0] ?? null;

        $this->assertNotNull($high);
        $this->assertGreaterThanOrEqual(1, (int) $high['resolved_count']);
    }

    public function testRecentRootCausesExcludesExceptionsWithoutOne(): void
    {
        $withCause = $this->insertException(['root_cause' => 'PHPUnit test root cause', 'resolved_at' => date('Y-m-d H:i:s')]);
        $this->insertException(['root_cause' => null]);

        $recent = DecisionIntelligenceService::recentRootCauses(50);
        $ids = array_column($recent, 'id');

        $this->assertContains($withCause, $ids);
    }
}
