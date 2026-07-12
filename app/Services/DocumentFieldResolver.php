<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\LoanReschedule;
use App\Models\DebitOrderCancellation;

/**
 * Resolves a template's placeholder fields (document_template_fields) into
 * real values for one generated_documents request. This is the piece that
 * makes the letter engine reusable across clients without code changes:
 * a new client's own templates use the same ${PLACEHOLDER} vocabulary, and
 * document_template_fields rows say where each value comes from (a plain
 * column on borrowers/loans/refund_claims, or one of the fixed "computed"
 * keys below for anything that needs real calculation).
 */
class DocumentFieldResolver
{
    /**
     * @param array $document A generated_documents row (borrower_id, loan_id,
     *              refund_claim_id, template_id already populated).
     * @param array $fields document_template_fields rows for that template.
     * @return array{values: array<string,string>, transactionEvents: ?array}
     */
    public static function resolve(array $document, array $fields): array
    {
        $context = self::buildContext($document);
        $values = [];
        $transactionEvents = null;

        foreach ($fields as $field) {
            if ($field['source_table'] === 'computed' && $field['source_column'] === 'transaction_table') {
                $transactionEvents = $context['loan_id']
                    ? LoanStatementService::ledger((int) $context['loan_id'])['events']
                    : [];
                continue;
            }

            $value = self::resolveOne($field, $context);
            $values[$field['field_key']] = $value !== null && $value !== ''
                ? $value
                : (string) ($field['default_value'] ?? '');
        }

        return ['values' => $values, 'transactionEvents' => $transactionEvents];
    }

    private static function buildContext(array $document): array
    {
        $borrower = $document['borrower_id'] ? (new Borrower())->find((int) $document['borrower_id']) : null;
        $loan = $document['loan_id'] ? (new Loan())->find((int) $document['loan_id']) : null;
        $refundClaim = $document['refund_claim_id'] ? self::findRefundClaim((int) $document['refund_claim_id']) : null;
        $application = !empty($document['application_id']) ? (new LoanApplication())->find((int) $document['application_id']) : null;
        $reschedule = !empty($document['reschedule_id']) ? (new LoanReschedule())->find((int) $document['reschedule_id']) : null;
        $debitOrderCancellation = !empty($document['debit_order_cancellation_id']) ? (new DebitOrderCancellation())->find((int) $document['debit_order_cancellation_id']) : null;

        // "All loans" consolidation: no single loan_id, aggregate every
        // currently-active loan for this borrower instead. Deliberately
        // restricted to the same statuses ArrearsService treats as "still
        // owed" -- a written-off loan keeps its full loan_schedules balance
        // forever (write-off only posts a GL journal, it doesn't touch the
        // schedule), and a completed loan has nothing left to consolidate,
        // so both would otherwise inflate this total with stale debt.
        $allLoans = [];
        if (!$loan && $borrower) {
            $allLoans = array_filter(
                (new Loan())->forBorrower((int) $borrower['id']),
                fn ($l) => in_array($l['loan_status'], ['Active', 'Current', 'Released'], true)
            );
        }

        return [
            'borrower' => $borrower,
            'loan' => $loan,
            'loan_id' => $loan['id'] ?? null,
            'all_loans' => $allLoans,
            'refund_claim' => $refundClaim,
            'application' => $application,
            'reschedule' => $reschedule,
            'debit_order_cancellation' => $debitOrderCancellation,
        ];
    }

    private static function resolveOne(array $field, array $context): ?string
    {
        $table = $field['source_table'];
        $column = $field['source_column'];

        if ($table === 'borrowers') {
            return $context['borrower'][$column] ?? null;
        }
        if ($table === 'loans') {
            return $context['loan'][$column] ?? null;
        }
        if ($table === 'refund_claims') {
            return $context['refund_claim'][$column] ?? null;
        }
        if ($table === 'loan_applications') {
            return $context['application'][$column] ?? null;
        }
        if ($table === 'loan_reschedules') {
            return $context['reschedule'][$column] ?? null;
        }
        if ($table === 'debit_order_cancellations') {
            return $context['debit_order_cancellation'][$column] ?? null;
        }
        if ($table === 'computed') {
            return self::computed($column, $context);
        }

        return null;
    }

    private static function computed(string $key, array $context): ?string
    {
        $borrower = $context['borrower'];
        $loan = $context['loan'];

        switch ($key) {
            case 'client_name':
                if ($borrower) {
                    return trim($borrower['first_name'] . ' ' . $borrower['last_name']);
                }
                $application = $context['application'];
                return $application ? trim($application['applicant_first_name'] . ' ' . $application['applicant_last_name']) : null;

            case 'current_date':
                return date('j F Y');

            case 'cleared_balance':
                return $loan ? format_money($loan['total_payable']) : null;

            case 'cleared_balance_words':
                return $loan ? self::numberToWords((float) $loan['total_payable']) : null;

            case 'outstanding_balance':
                $amount = self::outstandingBalance($context);
                return $amount === null ? null : format_money($amount);

            case 'outstanding_balance_words':
                $amount = self::outstandingBalance($context);
                return $amount === null ? null : self::numberToWords($amount);

            case 'loan_end_date':
                if ($loan) {
                    return $loan['maturity_date'] ? date('j F Y', strtotime($loan['maturity_date'])) : null;
                }
                $dates = array_filter(array_column($context['all_loans'], 'maturity_date'));
                return $dates ? date('j F Y', max(array_map('strtotime', $dates))) : null;

            case 'refund_amount':
                $amount = self::refundAmount($context);
                return $amount === null ? null : format_money($amount);

            case 'refund_amount_words':
                $amount = self::refundAmount($context);
                return $amount === null ? null : self::numberToWords($amount);

            default:
                return null;
        }
    }

    private static function outstandingBalance(array $context): ?float
    {
        $db = Database::connection();

        if ($context['loan']) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(total_due - total_paid), 0) FROM loan_schedules WHERE loan_id = ?");
            $stmt->execute([$context['loan']['id']]);
            return round((float) $stmt->fetchColumn(), 2);
        }

        $loanIds = array_column($context['all_loans'], 'id');
        if (empty($loanIds)) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_due - total_paid), 0) FROM loan_schedules WHERE loan_id IN ($placeholders)");
        $stmt->execute($loanIds);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function refundAmount(array $context): ?float
    {
        $claim = $context['refund_claim'];
        if (!$claim) {
            return null;
        }
        $approved = (float) ($claim['approved_amount'] ?? 0);
        return $approved > 0 ? $approved : (float) $claim['claim_amount'];
    }

    private static function findRefundClaim(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM refund_claims WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * "Three thousand two hundred and six Namibian Dollars and seventy-two
     * Cents" -- same convention the legacy letters already used.
     */
    public static function numberToWords(float $amount): string
    {
        $whole = (int) floor(abs($amount));
        $cents = (int) round((abs($amount) - $whole) * 100);

        if (class_exists('NumberFormatter')) {
            $fmt = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
            $wholeWords = ucfirst($fmt->format($whole));
            $result = $wholeWords . ' Namibian Dollar' . ($whole === 1 ? '' : 's');
            if ($cents > 0) {
                $centWords = trim($fmt->format($cents));
                $result .= ' and ' . $centWords . ' Cent' . ($cents === 1 ? '' : 's');
            }
        } else {
            $result = number_format($amount, 2) . ' Namibian Dollars';
        }

        return $amount < 0 ? 'Minus ' . $result : $result;
    }
}
