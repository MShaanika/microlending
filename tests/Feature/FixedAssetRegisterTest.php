<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Core\View;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Support\AssetStatus;
use App\Support\ListFilter;
use PHPUnit\Framework\TestCase;

/**
 * The fixed asset register: the acquisition-date range, the Serial No column, the Under Maintenance / Damaged statuses, and the rule that posting
 * depreciation never overwrites a status a person set. Runs against the local dev database; every asset it creates carries a unique asset_no prefix
 * and is removed afterwards (with the journals that posting created).
 */
class FixedAssetRegisterTest extends TestCase
{
    private \PDO $db;
    private FixedAsset $assets;
    private string $prefix;
    private int $categoryId;
    /** @var array<string, int> fixture key => asset id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->assets = new FixedAsset();
        $this->prefix = 'PHPUNIT-FA-' . uniqid() . '-';
        $this->categoryId = (int) $this->db->query('SELECT id FROM asset_categories ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->categoryId === 0) {
            $this->markTestSkipped('The dev database has no asset category to attach test assets to.');
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // the asset first: its schedule rows go with it, which frees the journals that posting created
        $this->db->prepare('DELETE FROM fixed_assets WHERE asset_no LIKE ?')->execute([$this->prefix . '%']);
        $journals = $this->db->prepare("SELECT id FROM accounting_journal_entries WHERE source_module = 'Fixed Assets' AND reference_no LIKE ?");
        $journals->execute([$this->prefix . '%']);
        foreach ($journals->fetchAll(\PDO::FETCH_COLUMN) as $journalId) {
            $this->db->prepare('DELETE FROM accounting_journal_lines WHERE journal_id = ?')->execute([$journalId]);
            $this->db->prepare('DELETE FROM accounting_journal_entries WHERE id = ?')->execute([$journalId]);
        }
        $_SESSION = [];
    }

    /** @param array<string, mixed> $overrides */
    private function asset(string $key, array $overrides = []): int
    {
        $id = $this->assets->create(array_merge([
            'category_id' => $this->categoryId,
            'asset_no' => $this->prefix . $key,
            'asset_name' => 'Test asset ' . $key,
            'asset_nature' => 'Tangible',
            'serial_no' => null,
            'purchase_date' => '2026-09-01',
            'purchase_cost' => 1200,
            'additional_costs' => 0,
            'capitalized_cost' => 1200,
            'residual_value' => 0,
            'useful_life_months' => 2,
            'depreciation_method' => 'Straight Line',
            'depreciation_start_date' => '2026-09-01',
            'accumulated_depreciation' => 0,
            'net_book_value' => 1200,
            'status' => 'Active',
        ], $overrides));
        $this->ids[$key] = $id;
        return $id;
    }

    /** Gives an asset a two-period straight-line schedule. */
    private function schedule(int $assetId): void
    {
        $this->assets->insertScheduleRows($assetId, DepreciationService::generate(1200.0, 0.0, 2, 'Straight Line', null, '2026-09-01'));
    }

    private function status(int $assetId): string
    {
        return (string) $this->db->query('SELECT status FROM fixed_assets WHERE id = ' . $assetId)->fetchColumn();
    }

    /** @return string[] the fixture keys among the rows, sorted */
    private function keys(array $rows): array
    {
        $byId = array_flip($this->ids);
        $out = [];
        foreach ($rows as $row) {
            if (isset($byId[(int) $row['id']])) {
                $out[] = $byId[(int) $row['id']];
            }
        }
        sort($out);
        return $out;
    }

    /** @return string[] */
    private function acquired(?string $from, ?string $to, string $search = '', string $status = ''): array
    {
        return $this->keys($this->assets->paginated($search, $status, 5000, null, ['from' => $from, 'to' => $to]));
    }

    // ---- the acquisition-date range ----------------------------------------------------------------------------------------

    private function threeAssetsAcrossTwoMonths(): void
    {
        $this->asset('early', ['purchase_date' => '2026-09-01']);
        $this->asset('mid', ['purchase_date' => '2026-09-15']);
        $this->asset('late', ['purchase_date' => '2026-10-01']);
    }

    public function testWithoutDatesEveryAssetIsReturned(): void
    {
        $this->threeAssetsAcrossTwoMonths();
        $this->assertSame(['early', 'late', 'mid'], $this->acquired(null, null));
    }

    public function testBothEndsOfTheRangeAreInclusive(): void
    {
        $this->threeAssetsAcrossTwoMonths();
        $this->assertSame(['mid'], $this->acquired('2026-09-15', '2026-09-15'), 'a single day');
        $this->assertSame(['early', 'mid'], $this->acquired('2026-09-01', '2026-09-15'), 'both end dates belong to the range');
        $this->assertSame(['late'], $this->acquired('2026-10-01', '2026-10-01'));
    }

    public function testEitherEndMayBeLeftOpen(): void
    {
        $this->threeAssetsAcrossTwoMonths();
        $this->assertSame(['late', 'mid'], $this->acquired('2026-09-02', null), 'from only');
        $this->assertSame(['early'], $this->acquired(null, '2026-09-14'), 'to only');
    }

    public function testARangeWithNothingInItReturnsNothing(): void
    {
        $this->threeAssetsAcrossTwoMonths();
        $this->assertSame([], $this->acquired('2026-09-16', '2026-09-30'));
    }

    public function testTheRangeCombinesWithTheStatusAndTheSearch(): void
    {
        $this->asset('early', ['purchase_date' => '2026-09-01', 'status' => 'Active']);
        $this->asset('mid', ['purchase_date' => '2026-09-15', 'status' => 'Damaged']);
        $this->asset('late', ['purchase_date' => '2026-10-01', 'status' => 'Damaged', 'serial_no' => 'SN-LATE']);

        $this->assertSame(['late', 'mid'], $this->acquired('2026-09-10', null, '', 'Damaged'));
        $this->assertSame(['late'], $this->acquired('2026-09-10', null, 'SN-LATE'), 'a serial number found inside the range');
        $this->assertSame([], $this->acquired('2026-09-10', null, '', 'Active'), 'the Active one was acquired before the range');
        $this->assertSame(['early'], $this->acquired('2026-08-01', '2026-09-10', '', 'Active'));
    }

    // ---- search -----------------------------------------------------------------------------------------------------------

    public function testSearchFindsASerialNumberAndTakesWildcardsLiterally(): void
    {
        $this->asset('percent', ['serial_no' => 'AB%CD']);
        $this->asset('plain', ['serial_no' => 'ABXCD']);

        $this->assertSame(['percent'], $this->keys($this->assets->paginated($this->prefix . 'percent')), 'by asset no');
        $this->assertSame(['percent'], $this->keys($this->assets->paginated('AB%C', '', 5000)), 'a typed % is not a wildcard');
        $this->assertSame(['plain'], $this->keys($this->assets->paginated('ABXCD', '', 5000)), 'by serial no');
    }

    public function testTheFinancialStatementsStillCallItPositionally(): void
    {
        $this->asset('afs');
        // AfsReportGenerationService::fixedAssetsSection() calls paginated('', '', 500) with no range
        $this->assertIsArray($this->assets->paginated('', '', 500));
    }

    // ---- statuses ---------------------------------------------------------------------------------------------------------

    public function testTheStatusColumnHoldsEveryStatusTheCodeOffers(): void
    {
        $type = (string) $this->db->query("SHOW COLUMNS FROM fixed_assets LIKE 'status'")->fetch()['Type'];
        preg_match_all("/'([^']+)'/", $type, $m);
        $this->assertEqualsCanonicalizing(AssetStatus::ALL, $m[1], 'apply database/fixed_asset_statuses.sql');
    }

    public function testPostingKeepsAnUnderMaintenanceOrDamagedStatusEvenOnTheLastPeriod(): void
    {
        foreach (['Under Maintenance', 'Damaged'] as $status) {
            $id = $this->asset('keep' . str_replace(' ', '', $status), ['status' => $status]);
            $this->schedule($id);

            $this->assets->depreciateNextPeriod($id, null);
            $this->assertSame($status, $this->status($id), "$status after the first period");
            $this->assets->depreciateNextPeriod($id, null);
            $this->assertSame($status, $this->status($id), "$status after the last period: still owned, still shown under its own status");
        }
    }

    public function testAnActiveAssetStillBecomesFullyDepreciatedOnItsLastPeriod(): void
    {
        $id = $this->asset('active');
        $this->schedule($id);

        $this->assets->depreciateNextPeriod($id, null);
        $this->assertSame('Active', $this->status($id));
        $this->assets->depreciateNextPeriod($id, null);
        $this->assertSame('Fully Depreciated', $this->status($id));
    }

    public function testStatusMovesBetweenTheStillInUseStatuses(): void
    {
        $id = $this->asset('move');
        $this->schedule($id);

        $this->assertSame('Under Maintenance', $this->assets->changeStatus($id, 'Under Maintenance'));
        $this->assertSame('Under Maintenance', $this->status($id));
        $this->assertSame('Damaged', $this->assets->changeStatus($id, 'Damaged'));
        $this->assertSame('Active', $this->assets->changeStatus($id, 'Active'), 'periods are still pending, so it is simply Active again');
        $this->assertSame('Active', $this->status($id));
    }

    public function testBackToActiveAfterTheLastPeriodGivesFullyDepreciated(): void
    {
        $id = $this->asset('allposted', ['status' => 'Damaged']);
        $this->schedule($id);
        $this->assets->depreciateNextPeriod($id, null);
        $this->assets->depreciateNextPeriod($id, null);
        $this->assertSame('Damaged', $this->status($id));

        $this->assertSame('Fully Depreciated', $this->assets->changeStatus($id, 'Active'), 'every period is posted, which is what it is');
        $this->assertSame('Fully Depreciated', $this->status($id));
    }

    public function testAnAssetWithNoDepreciationScheduleStaysActive(): void
    {
        // land and the like: "No Depreciation" means no schedule at all, which must not read as "all periods posted"
        $id = $this->asset('land', ['depreciation_method' => 'No Depreciation', 'status' => 'Damaged']);

        $this->assertSame('Active', $this->assets->changeStatus($id, 'Active'));
        $this->assertSame('Active', $this->status($id));
    }

    // ---- next month's charge on the register -------------------------------------------------------------------------------

    /** The dev database holds real assets, so the register total is read before and after the fixtures go in and only the difference is judged. */
    public function testNextMonthsChargeCountsOnlyAssetsThatCanStillPost(): void
    {
        $before = $this->assets->totals()['monthly_charge'];

        foreach (['Active', 'Under Maintenance', 'Damaged', 'Disposed', 'Written Off'] as $i => $status) {
            $this->schedule($this->asset('charge' . $i, ['status' => $status]));
        }

        // each schedule has a pending first period of 600: the three assets still in use will post it next month, the disposed and written-off never will
        $this->assertEqualsWithDelta(1800.0, $this->assets->totals()['monthly_charge'] - $before, 0.001);
    }

    public function testNextMonthsChargeIsTheFirstPendingPeriodOnlyAndDropsAnAssetOnceItIsDisposed(): void
    {
        $before = $this->assets->totals()['monthly_charge'];
        $id = $this->asset('twoPeriods');
        $this->schedule($id);
        $this->assertEqualsWithDelta(600.0, $this->assets->totals()['monthly_charge'] - $before, 0.001, 'one period of the two, not both');

        // exactly what a disposal leaves behind: the status changes and the pending rows stay
        $this->assets->updateFields($id, ['status' => 'Disposed']);
        $pending = (int) $this->db->query("SELECT COUNT(*) FROM asset_depreciation_schedules WHERE asset_id = $id AND status = 'Pending'")->fetchColumn();
        $this->assertGreaterThan(0, $pending, 'the disposed asset still has pending rows');
        $this->assertEqualsWithDelta(0.0, $this->assets->totals()['monthly_charge'] - $before, 0.001, 'but they no longer count');
    }

    public function testNextMonthsChargeFollowsTheBranchFilterAndStillIgnoresDisposedAssets(): void
    {
        $branchId = (int) $this->db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
        if ($branchId === 0) {
            $this->markTestSkipped('The dev database has no branch to attach test assets to.');
        }
        $before = $this->assets->totals($branchId)['monthly_charge'];

        $this->schedule($this->asset('inBranch', ['branch_id' => $branchId]));
        $this->schedule($this->asset('disposedInBranch', ['branch_id' => $branchId, 'status' => 'Disposed']));

        $this->assertEqualsWithDelta(600.0, $this->assets->totals($branchId)['monthly_charge'] - $before, 0.001);
    }

    // ---- what the screens show --------------------------------------------------------------------------------------------

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

    /** @param array<string, mixed> $query what is in the address bar */
    private function renderRegister(string $search, array $query = [], ?array $assets = null, bool $filtersActive = false): string
    {
        $filter = ListFilter::fromRequest($query);
        return $this->render('assets/index', [
            'title' => 'Fixed Assets',
            'assets' => $assets ?? $this->assets->paginated($search, '', 100),
            'totals' => ['count' => 0, 'net_book_value' => 0.0, 'monthly_charge' => 0.0],
            'search' => $search,
            'status' => $filter['status'],
            'filter' => $filter,
            'filtersActive' => $filtersActive,
            'branches' => [],
            'selectedBranchId' => null,
        ]);
    }

    /** @param string[] $permissions */
    private function renderAssetPage(int $assetId, array $permissions): string
    {
        $_SESSION['user'] = ['id' => 1, 'permissions' => $permissions];
        return $this->render('assets/show', [
            'title' => 'Asset',
            'asset' => $this->assets->find($assetId),
            'schedule' => $this->assets->schedule($assetId),
            'bankAccounts' => [],
        ]);
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        return new \DOMXPath($dom);
    }

    /** @return string[] */
    private function texts(\DOMXPath $xp, string $query, ?\DOMNode $context = null): array
    {
        $out = [];
        foreach ($xp->query($query, $context) as $node) {
            $out[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
        return $out;
    }

    public function testTheRegisterShowsSerialNoRightAfterTheNameAndADashWhenThereIsNone(): void
    {
        $this->asset('laptop', ['asset_name' => 'Laptop', 'serial_no' => 'SN-8841']);
        $this->asset('desk', ['asset_name' => 'Desk']);
        $this->asset('zero', ['asset_name' => 'Zero', 'serial_no' => '0']);

        $xp = $this->dom($this->renderRegister($this->prefix));
        $head = $this->texts($xp, '//table/thead//th');
        $name = array_search('Name', $head, true);
        $this->assertNotFalse($name);
        $this->assertSame('Serial No', $head[$name + 1], 'the column sits next to the asset name');

        $serialByName = [];
        foreach ($xp->query('//table/tbody/tr') as $row) {
            $cells = $this->texts($xp, './td', $row);
            $this->assertCount(count($head), $cells, 'every row has a cell for every column');
            $serialByName[$cells[$name]] = $cells[$name + 1];
        }
        $this->assertSame('SN-8841', $serialByName['Laptop']);
        $this->assertSame('—', $serialByName['Desk'], 'a dash when there is no serial number');
        $this->assertSame('0', $serialByName['Zero'], 'a serial number of 0 is still a serial number');
    }

    public function testTheRegisterOffersTheAcquisitionDateRangeAndEveryStatus(): void
    {
        $xp = $this->dom($this->renderRegister('', ['from' => '2026-09-01', 'to' => '2026-09-30'], [], true));

        $this->assertSame(['2026-09-01'], $this->attributes($xp, '//input[@name="from"]', 'value'), 'the From date keeps its value');
        $this->assertSame(['2026-09-30'], $this->attributes($xp, '//input[@name="to"]', 'value'), 'the To date keeps its value');
        $this->assertContains('Acquisition date from', $this->texts($xp, '//label'));
        $this->assertSame(array_merge(['All Statuses'], AssetStatus::ALL), $this->texts($xp, '//select[@name="status"]/option'));
    }

    /** @return string[] */
    private function attributes(\DOMXPath $xp, string $query, string $attribute): array
    {
        $out = [];
        foreach ($xp->query($query) as $node) {
            $out[] = $node->getAttribute($attribute);
        }
        return $out;
    }

    public function testTheEmptyRegisterSaysWhetherTheFiltersOrTheRegisterIsEmpty(): void
    {
        $filtered = $this->renderRegister('', ['from' => '2026-09-01'], [], true);
        $this->assertStringContainsString('Nothing matches your filters.', $filtered);
        $this->assertStringContainsString('colspan="10"', $filtered, 'the message spans every column, Serial No included');

        $this->assertStringContainsString('No assets registered yet.', $this->renderRegister('', [], [], false));
    }

    public function testTheRegisterDrawsEachStatusInItsOwnColour(): void
    {
        $this->asset('maint', ['status' => 'Under Maintenance']);
        $this->asset('broken', ['status' => 'Damaged']);
        $this->asset('gone', ['status' => 'Disposed']);
        $this->asset('fine', ['status' => 'Active']);
        $this->asset('done', ['status' => 'Fully Depreciated']);

        $html = $this->renderRegister($this->prefix);
        foreach (['Under Maintenance', 'Damaged', 'Disposed', 'Active', 'Fully Depreciated'] as $status) {
            $this->assertStringContainsString(AssetStatus::badge($status), $html, $status);
        }
    }

    public function testTheAssetPageOffersChangeStatusOnlyToSomeoneWhoManagesAssets(): void
    {
        $id = $this->asset('page');

        $this->assertStringNotContainsString('Change Status', $this->renderAssetPage($id, ['assets.view']), 'a viewer does not see it');

        $html = $this->renderAssetPage($id, ['assets.view', 'assets.manage']);
        $this->assertStringContainsString('Change Status', $html);
        $this->assertStringContainsString('/fixed-assets/' . $id . '/status', $html);
        $this->assertSame(['Active', 'Under Maintenance', 'Damaged'], $this->texts($this->dom($html), '//select[@name="status"]/option'), 'the three still-in-use statuses');
    }

    public function testTheAssetPageDoesNotOfferChangeStatusOnceTheAssetIsDisposed(): void
    {
        $id = $this->asset('gone', ['status' => 'Disposed']);
        $this->assertStringNotContainsString('Change Status', $this->renderAssetPage($id, ['assets.manage']));
    }

    public function testAnUnderMaintenanceOrDamagedAssetCanStillHaveItsNextPeriodPosted(): void
    {
        foreach (['Under Maintenance' => true, 'Damaged' => true, 'Active' => true, 'Fully Depreciated' => false] as $status => $offered) {
            $id = $this->asset('post' . str_replace(' ', '', $status), ['status' => $status]);
            $this->schedule($id);
            $html = $this->renderAssetPage($id, ['assets.manage']);
            $this->assertSame($offered, str_contains($html, 'Post Next Depreciation Period'), $status);
        }
    }
}
