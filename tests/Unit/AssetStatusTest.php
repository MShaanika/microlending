<?php

namespace Tests\Unit;

use App\Support\AssetStatus;
use PHPUnit\Framework\TestCase;

/** The statuses of a fixed asset: which are listed, which still depreciate, which can be changed, and how each badge looks. */
class AssetStatusTest extends TestCase
{
    /** @return array{0: int, 1: int, 2: int} red, green, blue of a #rrggbb colour */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** @return array{0: string, 1: string} background and text colour of a badge */
    private function colours(string $status): array
    {
        $this->assertSame(1, preg_match('/background-color:(#[0-9a-f]{6});color:(#[0-9a-f]{6})/i', AssetStatus::badge($status), $m), 'the badge carries its own colours');
        return [$m[1], $m[2]];
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $this->rgb($hex));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function testTheFilterListsEveryStatusOnceInOrder(): void
    {
        $this->assertSame(['Active', 'Under Maintenance', 'Damaged', 'Fully Depreciated', 'Disposed', 'Written Off'], AssetStatus::ALL);
        $this->assertSame(AssetStatus::ALL, array_values(array_unique(AssetStatus::ALL)));
    }

    public function testOnlyAnAssetInTheBooksAndNotFullyDepreciatedCanHaveAPeriodPosted(): void
    {
        foreach (['Active', 'Under Maintenance', 'Damaged'] as $status) {
            $this->assertTrue(AssetStatus::canPostDepreciation($status), "$status keeps depreciating");
        }
        foreach (['Fully Depreciated', 'Disposed', 'Written Off', '', 'Nonsense'] as $status) {
            $this->assertFalse(AssetStatus::canPostDepreciation($status), "$status takes no more depreciation");
        }
    }

    public function testThePostableListIsExactlyTheStatusesThatCanPost(): void
    {
        $this->assertSame(['Active', 'Under Maintenance', 'Damaged'], AssetStatus::postable());
        foreach (AssetStatus::ALL as $status) {
            $this->assertSame(AssetStatus::canPostDepreciation($status), in_array($status, AssetStatus::postable(), true), "$status: the list and the rule agree");
        }
    }

    public function testStatusCanBeChangedWhileTheCompanyStillOwnsTheAsset(): void
    {
        foreach (['Active', 'Under Maintenance', 'Damaged', 'Fully Depreciated'] as $status) {
            $this->assertTrue(AssetStatus::stillOwned($status), $status);
        }
        foreach (['Disposed', 'Written Off', '', 'Nonsense'] as $status) {
            $this->assertFalse(AssetStatus::stillOwned($status), $status);
        }
    }

    public function testAPersonMayChooseOnlyStillInUseStatuses(): void
    {
        $this->assertSame(['Active', 'Under Maintenance', 'Damaged'], AssetStatus::CHOOSABLE);
    }

    public function testEveryStatusHasItsOwnBackgroundColour(): void
    {
        $backgrounds = array_map(fn (string $s) => $this->colours($s)[0], AssetStatus::ALL);
        $this->assertSame($backgrounds, array_values(array_unique($backgrounds)), 'no two statuses share a colour');
    }

    public function testTheColoursAreTheOnesAskedFor(): void
    {
        [$r, $g, $b] = $this->rgb($this->colours('Active')[0]);
        $this->assertTrue($g > $r && $g > $b, 'Active is green');

        [$r, $g, $b] = $this->rgb($this->colours('Under Maintenance')[0]);
        $this->assertTrue($r > 200 && $g > 150 && $b < 80, 'Under Maintenance is yellow');

        [$r, $g, $b] = $this->rgb($this->colours('Damaged')[0]);
        $this->assertTrue($r > 180 && $g < 100 && $b < 100, 'Damaged is red');

        [$r, $g, $b] = $this->rgb($this->colours('Disposed')[0]);
        $this->assertTrue($r < 64 && $g < 64 && $b < 64, 'Disposed is black');

        [$r, $g, $b] = $this->rgb($this->colours('Fully Depreciated')[0]);
        $this->assertTrue($b > $r && $b > $g, 'Fully Depreciated is blue');
    }

    public function testTheTextIsReadableOnEveryBadge(): void
    {
        foreach (AssetStatus::ALL as $status) {
            [$background, $text] = $this->colours($status);
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($background, $text), "$status: $text on $background");
        }
    }

    public function testTheBadgeDoesNotDependOnTheThemesColourClasses(): void
    {
        // the theme's "success" is a cyan and "info" follows the company accent colour, which is why Active used to look teal
        foreach (AssetStatus::ALL as $status) {
            $this->assertStringNotContainsString('bg-', AssetStatus::badge($status), $status);
        }
    }

    public function testTheBadgeShowsTheStatusAndEscapesIt(): void
    {
        $this->assertStringContainsString('>Under Maintenance</span>', AssetStatus::badge('Under Maintenance'));

        $odd = AssetStatus::badge('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $odd);
        $this->assertStringContainsString('&lt;script&gt;', $odd);
        $this->assertStringContainsString('background-color:#6c757d', $odd, 'an unknown value is drawn grey');
    }
}
