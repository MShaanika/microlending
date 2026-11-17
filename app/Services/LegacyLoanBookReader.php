<?php

namespace App\Services;

use PDO;

/**
 * Reads the loan book of the OLD loan system (SolidFin) through a connection that is only ever used for SELECT. Portable SQL (no vendor functions), so the
 * same code runs against the real MariaDB and the in-memory test database.
 */
final class LegacyLoanBookReader implements LegacyLoanSource
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Every loan that could belong in a file (released, completed or written off), with its payments and first due date, ready for LegacyCplMapper.
     *
     * @return array<int, array{loan: array<string,mixed>, payments: array<int,array{amount:string,date:string}>, first_due: ?string}>
     */
    public function loans(): array
    {
        $loans = $this->pdo->query(
            "SELECT l.id, l.ref_no, l.borrower_id, l.amount, l.plan_id, l.status, l.date_released, l.date_written_off,
                    p.months, p.interest_percentage,
                    b.firstname, b.middlename, b.lastname, b.id_number, b.identity, b.gender, b.contact_no, b.address, b.company_name, b.gross_salary, b.payment_day
             FROM loan_list l
             LEFT JOIN loan_plan p ON p.id = l.plan_id
             LEFT JOIN borrowers b ON b.id = l.borrower_id
             WHERE l.status IN (2, 3, 5)
             ORDER BY l.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $payments = [];
        foreach ($this->pdo->query("SELECT loan_id, amount, date_created FROM payments WHERE transaction_tpye = 'Loan Repayment' ORDER BY date_created")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[(int) $row['loan_id']][] = ['amount' => (string) $row['amount'], 'date' => (string) $row['date_created']];
        }
        $firstDue = [];
        foreach ($this->pdo->query('SELECT loan_id, MIN(date_due) AS first_due FROM loan_schedules GROUP BY loan_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $firstDue[(int) $row['loan_id']] = $row['first_due'] !== null ? (string) $row['first_due'] : null;
        }

        $out = [];
        foreach ($loans as $loan) {
            $id = (int) $loan['id'];
            $out[] = ['loan' => $loan, 'payments' => $payments[$id] ?? [], 'first_due' => $firstDue[$id] ?? null];
        }
        return $out;
    }
}
