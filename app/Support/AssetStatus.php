<?php

namespace App\Support;

/**
 * The statuses of a fixed asset, and what each means for the books and on screen. One place, so the register, the asset page, the depreciation
 * posting and the status change cannot disagree.
 *
 *   Active, Under Maintenance, Damaged   still owned and still depreciating; a person moves an asset between these
 *   Fully Depreciated                    still owned; every depreciation period is posted (the books set it, it is not a choice)
 *   Disposed, Written Off                no longer owned; the disposal sets Disposed and posts its journal
 */
class AssetStatus
{
    public const ACTIVE = 'Active';
    public const UNDER_MAINTENANCE = 'Under Maintenance';
    public const DAMAGED = 'Damaged';
    public const FULLY_DEPRECIATED = 'Fully Depreciated';
    public const DISPOSED = 'Disposed';
    public const WRITTEN_OFF = 'Written Off';

    /** Every value the status column holds, in the order the register's filter lists them. */
    public const ALL = [self::ACTIVE, self::UNDER_MAINTENANCE, self::DAMAGED, self::FULLY_DEPRECIATED, self::DISPOSED, self::WRITTEN_OFF];

    /** What a person may set on the asset page. */
    public const CHOOSABLE = [self::ACTIVE, self::UNDER_MAINTENANCE, self::DAMAGED];

    /**
     * [background, text] of each badge. Drawn explicitly rather than with the theme's bg-* classes: the theme's "success" is a cyan and its
     * "info" follows the company accent colour, so neither would read as the green and the blue these statuses call for.
     */
    private const COLOURS = [
        self::ACTIVE => ['#198754', '#ffffff'],
        self::UNDER_MAINTENANCE => ['#ffc107', '#212529'],
        self::DAMAGED => ['#dc3545', '#ffffff'],
        self::FULLY_DEPRECIATED => ['#2b6cb0', '#ffffff'],
        self::DISPOSED => ['#212529', '#ffffff'],
        self::WRITTEN_OFF => ['#6c757d', '#ffffff'],
    ];

    /** A depreciation or amortization period may be posted: the asset is in the books and not yet fully depreciated. */
    public static function canPostDepreciation(string $status): bool
    {
        return in_array($status, self::CHOOSABLE, true);
    }

    /** The company still owns it, so a person can still change its status. */
    public static function stillOwned(string $status): bool
    {
        return in_array($status, self::CHOOSABLE, true) || $status === self::FULLY_DEPRECIATED;
    }

    /** The badge for a status, ready to print. An unknown value is shown grey, as it is written. */
    public static function badge(string $status): string
    {
        [$background, $text] = self::COLOURS[$status] ?? ['#6c757d', '#ffffff'];
        return '<span class="badge" style="background-color:' . $background . ';color:' . $text . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
