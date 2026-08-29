<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class FeatureFlagServiceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdIds as $id) {
            $db->prepare('DELETE FROM feature_flags WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    private function makeFlag(array $overrides = []): array
    {
        $key = 'phpunit_test_' . uniqid();
        $data = array_merge([
            'flag_key' => $key,
            'name' => 'PHPUnit Test Flag',
            'enabled' => 1,
            'rollout_type' => 'ALL_USERS',
            'environment' => 'production',
        ], $overrides);

        $id = (new FeatureFlag())->create($data);
        $this->createdIds[] = $id;

        return ['id' => $id, 'flag_key' => $key];
    }

    public function testUnknownFlagFailsClosed(): void
    {
        $this->assertFalse(FeatureFlagService::isEnabled('no_such_flag_' . uniqid()));
    }

    public function testDisabledFlagIsOffRegardlessOfRolloutType(): void
    {
        $flag = $this->makeFlag(['enabled' => 0, 'rollout_type' => 'ALL_USERS']);
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));
    }

    public function testAllUsersRolloutIsOnForAnyUser(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'ALL_USERS']);
        $this->assertTrue(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 999]));
    }

    public function testOffRolloutTypeIsOffEvenWhenEnabledFlagIsSet(): void
    {
        $flag = $this->makeFlag(['enabled' => 1, 'rollout_type' => 'OFF']);
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));
    }

    public function testSpecificUsersRolloutOnlyMatchesListedUserIds(): void
    {
        $flag = $this->makeFlag([
            'rollout_type' => 'SPECIFIC_USERS',
            'metadata' => json_encode(['user_ids' => [5, 9]]),
        ]);

        $this->assertTrue(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 9]));
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 10]));
    }

    public function testSpecificRolesRolloutMatchesOnUserType(): void
    {
        $flag = $this->makeFlag([
            'rollout_type' => 'SPECIFIC_ROLES',
            'metadata' => json_encode(['role_names' => ['Manager']]),
        ]);

        $this->assertTrue(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1, 'user_type' => 'Manager']));
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 2, 'user_type' => 'Loan Officer']));
    }

    public function testInternalOnlyExcludesOrdinaryStaff(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'INTERNAL_ONLY']);

        $this->assertTrue(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1, 'user_type' => 'Super Admin']));
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 2, 'user_type' => 'Loan Officer']));
    }

    public function testPercentageRolloutIsDeterministicAndStickyPerUser(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'PERCENTAGE', 'rollout_percentage' => 50]);

        $first = FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 123]);
        $second = FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 123]);
        $this->assertSame($first, $second);
    }

    public function testPercentageZeroIsOffAndHundredIsOnForEveryone(): void
    {
        $off = $this->makeFlag(['rollout_type' => 'PERCENTAGE', 'rollout_percentage' => 0]);
        $on = $this->makeFlag(['rollout_type' => 'PERCENTAGE', 'rollout_percentage' => 100]);

        $this->assertFalse(FeatureFlagService::isEnabled($off['flag_key'], ['id' => 55]));
        $this->assertTrue(FeatureFlagService::isEnabled($on['flag_key'], ['id' => 55]));
    }

    /** Emergency disable (Part 39): flipping enabled=0 must immediately turn a flag off, with no code deploy needed. */
    public function testEmergencyDisableImmediatelyTurnsAllUsersRolloutOff(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'ALL_USERS', 'enabled' => 1]);
        $this->assertTrue(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));

        (new FeatureFlag())->updateFlag($flag['id'], ['enabled' => 0]);
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));
    }

    public function testFutureStartDateKeepsFlagOff(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'ALL_USERS', 'starts_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));
    }

    public function testPastEndDateTurnsFlagOff(): void
    {
        $flag = $this->makeFlag(['rollout_type' => 'ALL_USERS', 'ends_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->assertFalse(FeatureFlagService::isEnabled($flag['flag_key'], ['id' => 1]));
    }
}
