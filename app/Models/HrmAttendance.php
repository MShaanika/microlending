<?php

namespace App\Models;

use App\Core\Model;
use DateTime;

class HrmAttendance extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = a.employee_id
        LEFT JOIN hrm_shifts s ON s.id = a.shift_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no, s.shift_name
    ";

    public function allAttendances(array $filters = []): array
    {
        $sql = "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_attendances a " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'a.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.attendance_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.attendance_date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.attendance_date DESC, e.first_name';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_attendances a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    public function existsForEmployeeDate(int $employeeId, string $date, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_attendances WHERE employee_id = ? AND attendance_date = ? AND id != ?",
                [$employeeId, $date, $excludeId]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_attendances WHERE employee_id = ? AND attendance_date = ?",
            [$employeeId, $date]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_attendances', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_attendances', $data, 'id', $id);
    }

    /**
     * Computes break/total/overtime hours and Present/Half Day/Absent
     * status for a clock-in/clock-out pair, ported from the reference
     * HRM module's Carbon-based logic to native DateTime. $shift and
     * $employee are the joined rows (or null) -- $shift supplies the
     * standard working hours and break window, $employee supplies
     * rate_per_hour for the overtime pay amount.
     */
    public function calculate(string $clockIn, ?string $clockOut, ?array $shift, ?array $employee): array
    {
        if (!$clockOut) {
            return [
                'break_hour' => 0.0, 'total_hour' => 0.0,
                'overtime_hours' => 0.0, 'overtime_amount' => 0.0, 'status' => 'Absent',
            ];
        }

        $in = new DateTime($clockIn);
        $out = new DateTime($clockOut);
        if ($out < $in) {
            $out->modify('+1 day');
        }
        $totalMinutes = abs(($out->getTimestamp() - $in->getTimestamp()) / 60);

        $breakMinutes = 0;
        if (!empty($shift['break_start_time']) && !empty($shift['break_end_time'])) {
            $day = $in->format('Y-m-d');
            $breakStart = new DateTime($day . ' ' . $shift['break_start_time']);
            $breakEnd = new DateTime($day . ' ' . $shift['break_end_time']);
            if ($breakEnd < $breakStart) {
                $breakEnd->modify('+1 day');
            }
            $breakDuration = abs(($breakEnd->getTimestamp() - $breakStart->getTimestamp()) / 60);

            if ($in <= $breakStart && $out >= $breakEnd) {
                $breakMinutes = $breakDuration;
            } elseif ($in <= $breakStart && $out > $breakStart && $out <= $breakEnd) {
                $breakMinutes = abs(($out->getTimestamp() - $breakStart->getTimestamp()) / 60);
            } elseif ($in > $breakStart && $in < $breakEnd && $out >= $breakEnd) {
                $breakMinutes = abs(($breakEnd->getTimestamp() - $in->getTimestamp()) / 60);
            }
            // else: clocked in and out entirely within the break window -> no deduction
        }

        $workingMinutes = max(0, $totalMinutes - $breakMinutes);
        $totalHour = round($workingMinutes / 60, 2);
        $breakHour = round($breakMinutes / 60, 2);

        $standardHours = 8.0;
        if (!empty($shift['start_time']) && !empty($shift['end_time'])) {
            $day = $in->format('Y-m-d');
            $shiftStart = new DateTime($day . ' ' . $shift['start_time']);
            $shiftEnd = new DateTime($day . ' ' . $shift['end_time']);
            if ($shiftEnd < $shiftStart) {
                $shiftEnd->modify('+1 day');
            }
            $shiftBreak = 0;
            if (!empty($shift['break_start_time']) && !empty($shift['break_end_time'])) {
                $bs = new DateTime($day . ' ' . $shift['break_start_time']);
                $be = new DateTime($day . ' ' . $shift['break_end_time']);
                if ($be < $bs) {
                    $be->modify('+1 day');
                }
                $shiftBreak = abs(($be->getTimestamp() - $bs->getTimestamp()) / 60);
            }
            $shiftMinutes = max(0, abs(($shiftEnd->getTimestamp() - $shiftStart->getTimestamp()) / 60) - $shiftBreak);
            if ($shiftMinutes > 0) {
                $standardHours = round($shiftMinutes / 60, 2);
            }
        }

        $overtimeHours = (float) max(0, round($totalHour - $standardHours, 2));
        $overtimeAmount = 0.0;
        if ($overtimeHours > 0 && !empty($employee['rate_per_hour'])) {
            $overtimeAmount = round($overtimeHours * (float) $employee['rate_per_hour'], 2);
        }

        $status = 'Absent';
        if ($totalHour > 0) {
            $halfDayThreshold = $standardHours / 2;
            if ($totalHour >= $standardHours) {
                $status = 'Present';
            } elseif ($totalHour >= $halfDayThreshold) {
                $status = 'Half Day';
            }
        }

        return [
            'break_hour' => $breakHour,
            'total_hour' => $totalHour,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'status' => $status,
        ];
    }
}
