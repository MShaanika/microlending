<?php

namespace App\Services;

use DateTime;

/**
 * Builds the monthly attendance grid (employee rows x day-of-month columns)
 * shown on the Attendance Report -- a pure function over already-fetched
 * data (attendance rows, approved leave ranges, holiday ranges) so it stays
 * cheap to call once per report request regardless of employee/day count.
 *
 * Status precedence per cell: Holiday > On Leave > a recorded attendance
 * row > Day Off > Pending > (future date, blank) -- a holiday or approved
 * leave outranks a same-day attendance record because those are the
 * authoritative reason nobody was expected at work that day.
 *
 * "Day Off" assumes a single weekly rest day (Sunday) since the shift/
 * employee model has no per-employee working-days pattern yet -- flagged
 * here as the one spot to change if the client's real work week differs.
 */
class AttendanceGridService
{
    private const GRACE_MINUTES = 15;
    private const DAY_OFF_ISO_WEEKDAY = 7; // Sunday

    /**
     * @param array $employees Rows from HrmEmployee::allEmployees() (needs id, shift_start_time, shift_end_time)
     * @param array $attendanceMap HrmAttendance::forRange() result: [employee_id][date] => row
     * @param array $leaveMap HrmLeaveApplication::approvedInRange() result: [employee_id] => [[start_date,end_date],...]
     * @param array $holidays HrmHoliday::inRange() result
     */
    public static function build(array $employees, int $year, int $month, array $attendanceMap, array $leaveMap, array $holidays): array
    {
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $today = date('Y-m-d');

        $rows = [];
        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];
            $leaveRanges = $leaveMap[$employeeId] ?? [];
            $days = [];

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $days[$d] = self::cellFor($date, $employee, $attendanceMap[$employeeId][$date] ?? null, $leaveRanges, $holidays, $today);
            }

            $rows[] = ['employee' => $employee, 'days' => $days];
        }

        return $rows;
    }

    private static function cellFor(string $date, array $employee, ?array $record, array $leaveRanges, array $holidays, string $today): array
    {
        $holiday = null;
        foreach ($holidays as $h) {
            if ($date >= $h['start_date'] && $date <= $h['end_date']) {
                $holiday = $h;
                break;
            }
        }

        foreach ($leaveRanges as $leave) {
            if ($date >= $leave['start_date'] && $date <= $leave['end_date']) {
                return ['code' => 'leave', 'title' => 'On Leave'];
            }
        }

        // A holiday with an actual clock-in that day (worked the holiday)
        // still shows the real attendance status, with "holiday" added as
        // a flag rather than hidden behind the Holiday marker -- otherwise
        // that day's clock-in/overtime would disappear from the report.
        if ($record) {
            $cell = self::cellForRecord($date, $employee, $record);
            if ($holiday) {
                $cell['flags'][] = 'holiday';
                $cell['title'] = 'Worked on Holiday (' . $holiday['name'] . ') -- ' . $cell['title'];
            }
            return $cell;
        }

        if ($holiday) {
            return ['code' => 'holiday', 'title' => 'Holiday: ' . $holiday['name']];
        }

        if ((int) date('N', strtotime($date)) === self::DAY_OFF_ISO_WEEKDAY) {
            return ['code' => 'dayoff', 'title' => 'Day Off'];
        }

        if ($date <= $today) {
            return ['code' => 'pending', 'title' => 'Pending -- no attendance recorded'];
        }

        return ['code' => 'future', 'title' => ''];
    }

    private static function cellForRecord(string $date, array $employee, array $record): array
    {
        $flags = [];

        if (!empty($employee['shift_start_time']) && !empty($record['clock_in'])) {
            $graceEnd = new DateTime($date . ' ' . $employee['shift_start_time']);
            $graceEnd->modify('+' . self::GRACE_MINUTES . ' minutes');
            if (new DateTime($record['clock_in']) > $graceEnd) {
                $flags[] = 'late';
            }
        }

        if (!empty($employee['shift_end_time']) && !empty($record['clock_out'])) {
            $graceStart = new DateTime($date . ' ' . $employee['shift_end_time']);
            $graceStart->modify('-' . self::GRACE_MINUTES . ' minutes');
            if (new DateTime($record['clock_out']) < $graceStart) {
                $flags[] = 'early';
            }
        }

        if ((float) ($record['overtime_hours'] ?? 0) > 0) {
            $flags[] = 'overtime';
        }

        $code = match ($record['status']) {
            'Half Day' => 'half',
            'Absent' => 'absent',
            default => 'present',
        };

        $titleParts = [$record['status']];
        if ($record['clock_in']) {
            $titleParts[] = 'In ' . date('H:i', strtotime($record['clock_in']));
        }
        if ($record['clock_out']) {
            $titleParts[] = 'Out ' . date('H:i', strtotime($record['clock_out']));
        }
        if (in_array('late', $flags, true)) {
            $titleParts[] = 'Late';
        }
        if (in_array('early', $flags, true)) {
            $titleParts[] = 'Left Early';
        }
        if (in_array('overtime', $flags, true)) {
            $titleParts[] = 'Overtime ' . number_format((float) $record['overtime_hours'], 2) . 'h';
        }

        return ['code' => $code, 'flags' => $flags, 'title' => implode(' -- ', $titleParts), 'record_id' => (int) $record['id']];
    }
}
