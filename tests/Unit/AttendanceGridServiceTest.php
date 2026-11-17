<?php

namespace Tests\Unit;

use App\Services\AttendanceGridService;
use PHPUnit\Framework\TestCase;

class AttendanceGridServiceTest extends TestCase
{
    private function cell(array $record, array $employee = []): array
    {
        $employee += ['id' => 1, 'shift_start_time' => '08:00:00', 'shift_end_time' => '17:00:00'];
        $record += ['id' => 5, 'status' => 'Present', 'clock_in' => null, 'clock_out' => null, 'overtime_hours' => 0, 'shift_id' => null];
        $grid = AttendanceGridService::build([$employee], 2026, 11, [1 => ['2026-11-11' => $record]], [], []);
        return $grid[0]['days'][11];
    }

    public function testAnEarlyClockInIsNotLate(): void
    {
        $c = $this->cell(['clock_in' => '2026-11-11 07:30:00', 'clock_out' => '2026-11-11 17:00:00']);
        $this->assertNotContains('late', $c['flags']);
        $this->assertSame('present', $c['code']);
    }

    public function testClockedInWithoutClockOutIsNeitherAbsentNorDoubleMarked(): void
    {
        $c = $this->cell(['status' => 'Absent', 'clock_in' => '2026-11-11 09:10:00']);
        $this->assertSame('open', $c['code']);
        $this->assertSame(['late'], $c['flags'], 'a genuinely late arrival is still shown, but not next to an Absent cross');
    }

    public function testAnAbsentDayIsNotAlsoMarkedLate(): void
    {
        $c = $this->cell(['status' => 'Absent', 'clock_in' => '2026-11-11 10:00:00', 'clock_out' => '2026-11-11 10:30:00']);
        $this->assertSame('absent', $c['code']);
        $this->assertNotContains('late', $c['flags']);
    }

    public function testTheShiftRecordedOnTheDayDecidesLateNotTheEmployeesCurrentShift(): void
    {
        // employee was moved to a 10:00 shift later; this day was worked under the 07:00 shift and the clock-in at 07:50 was late
        $c = $this->cell(
            ['shift_id' => 3, 'shift_start_time' => '07:00:00', 'shift_end_time' => '16:00:00', 'clock_in' => '2026-11-11 07:50:00', 'clock_out' => '2026-11-11 16:00:00'],
            ['shift_start_time' => '10:00:00', 'shift_end_time' => '19:00:00']
        );
        $this->assertContains('late', $c['flags']);

        // and the reverse: current shift is 07:00, the day was under a 09:00 shift, clock-in 08:50 is early, not late
        $c = $this->cell(
            ['shift_id' => 4, 'shift_start_time' => '09:00:00', 'shift_end_time' => '18:00:00', 'clock_in' => '2026-11-11 08:50:00', 'clock_out' => '2026-11-11 18:00:00'],
            ['shift_start_time' => '07:00:00', 'shift_end_time' => '16:00:00']
        );
        $this->assertNotContains('late', $c['flags']);
    }
}
