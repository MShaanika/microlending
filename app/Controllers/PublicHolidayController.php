<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PublicHoliday;

/**
 * Public holidays management -- Add Holiday / Import Holidays / Copy
 * Previous Year, per the explicit "do not hard-code or algorithmically
 * assume public holidays" instruction. Read by BusinessCalendarService;
 * this controller only ever writes rows an administrator explicitly
 * confirmed.
 */
class PublicHolidayController extends Controller
{
    private PublicHoliday $holidays;

    public function __construct()
    {
        $this->holidays = new PublicHoliday();
    }

    public function index(): void
    {
        Auth::authorize('public_holidays.manage');
        $year = (int) ($_GET['year'] ?? date('Y'));
        $this->view('public_holidays/index', [
            'title' => 'Public Holidays',
            'holidays' => $this->holidays->forYear($year),
            'year' => $year,
        ]);
    }

    public function store(): void
    {
        Auth::authorize('public_holidays.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/public-holidays');
            return;
        }

        $date = trim($_POST['holiday_date'] ?? '');
        $name = trim($_POST['holiday_name'] ?? '');
        if ($date === '' || $name === '') {
            Session::flash('error', 'Date and holiday name are both required.');
            $this->redirect('/public-holidays');
            return;
        }

        $year = (int) substr($date, 0, 4);
        $id = $this->holidays->create([
            'holiday_date' => $date,
            'holiday_name' => $name,
            'year' => $year,
            'is_active' => 1,
            'source' => 'Manual',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Collections', 'Added public holiday #' . $id . ' - ' . $name . ' (' . $date . ')');
        Session::flash('success', 'Holiday added.');
        $this->redirect('/public-holidays?year=' . $year);
    }

    public function toggleActive(string $id): void
    {
        Auth::authorize('public_holidays.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/public-holidays');
            return;
        }

        $holiday = $this->holidays->find((int) $id);
        if (!$holiday) {
            Session::flash('error', 'Holiday not found.');
            $this->redirect('/public-holidays');
            return;
        }

        $newActive = $holiday['is_active'] ? 0 : 1;
        $this->holidays->updateRecord((int) $id, ['is_active' => $newActive]);
        Audit::log('Update', 'Collections', ($newActive ? 'Activated' : 'Deactivated') . ' public holiday #' . $id);
        Session::flash('success', 'Holiday ' . ($newActive ? 'activated' : 'deactivated') . '.');
        $this->redirect('/public-holidays?year=' . $holiday['year']);
    }

    /** Bulk paste: one "YYYY-MM-DD,Holiday Name" per line. Inserted active -- an import is treated as an already-confirmed authoritative list, unlike Copy Previous Year's guesses. */
    public function import(): void
    {
        Auth::authorize('public_holidays.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/public-holidays');
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim((string) ($_POST['csv_data'] ?? '')));
        $imported = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode(',', $line, 2));
            if (count($parts) !== 2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])) {
                $skipped++;
                continue;
            }
            [$date, $name] = $parts;
            try {
                $this->holidays->create([
                    'holiday_date' => $date,
                    'holiday_name' => $name,
                    'year' => (int) substr($date, 0, 4),
                    'is_active' => 1,
                    'source' => 'Imported',
                    'created_by' => Auth::user()['id'] ?? null,
                ]);
                $imported++;
            } catch (\PDOException $e) {
                $skipped++; // duplicate holiday_date -- unique constraint
            }
        }

        Audit::log('Create', 'Collections', "Imported $imported public holidays ($skipped skipped)");
        Session::flash('success', "Imported $imported holidays." . ($skipped > 0 ? " $skipped line(s) skipped (duplicate date or bad format)." : ''));
        $this->redirect('/public-holidays');
    }

    /** Never activates copied rows -- Easter-linked and similar non-fixed holidays move by more than exactly one year. */
    public function copyPreviousYear(): void
    {
        Auth::authorize('public_holidays.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/public-holidays');
            return;
        }

        $fromYear = (int) ($_POST['from_year'] ?? (date('Y') - 1));
        $toYear = $fromYear + 1;
        $created = $this->holidays->copyPreviousYear($fromYear, $toYear, Auth::user()['id'] ?? null);

        Audit::log('Create', 'Collections', "Copied $created holidays from $fromYear as $toYear drafts (inactive, need review)");
        Session::flash('success', "Created $created draft holiday(s) for $toYear from $fromYear -- review and correct dates (especially Easter-linked ones) before activating.");
        $this->redirect('/public-holidays?year=' . $toYear);
    }
}
