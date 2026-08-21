<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderCollection;
use App\Models\DebitOrderCollectionImport;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\CollexiaV3Codes;

/**
 * Reconciles a CollexiaEndoApiClient::downloadPayments() response (spec
 * section 7.4 / structures 9.19-9.20) against our own mandates, using the
 * exact same matching/idempotency/posting rules as
 * DebitOrderCollectionController's Excel-based "Successful Transactions"
 * import -- this is the REST-API-sourced equivalent of that same report,
 * just pulled on Collexia's recommended schedule (06:00/10:00/15:00/20:00)
 * instead of manually uploaded.
 *
 * Every response entry is recorded into debit_order_collections regardless
 * of outcome (matching the existing review screen at
 * /debit-order-collections/{id}), and only responseCode "0" (Transaction
 * Successful) ever posts a real Payment -- every other Appendix A code
 * (insufficient funds, account closed, etc.) is recorded for visibility
 * only, same as the Excel "Unsuccessful Transactions" path.
 *
 * REQUIRES a small schema change before use -- see
 * database/collexia_v3_reconciliation.sql: debit_order_collections.
 * merchant_system_contract_no is VARCHAR(10) (the old v1.0 spec's contract
 * number length), but V3's contractReference is up to 14 characters, and
 * debit_order_collection_imports.report_type's ENUM needs a 'CollexiaAPI'
 * value alongside the existing Excel report types. That migration has not
 * been run against production.
 *
 * NOTE: unlike the Excel "Successful Transactions" report, the API's
 * Download Payments response does not include the installment's original
 * scheduled date or scheduled amount -- only what was actually paid. Those
 * two columns are left null for API-sourced rows rather than guessed.
 */
class CollexiaPaymentReconciliationService
{
    private DebitOrder $debitOrders;
    private DebitOrderCollection $collections;
    private DebitOrderCollectionImport $imports;
    private Loan $loans;
    private Payment $payments;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->collections = new DebitOrderCollection();
        $this->imports = new DebitOrderCollectionImport();
        $this->loans = new Loan();
        $this->payments = new Payment();
    }

    /**
     * @param array $downloadPaymentsResponse raw return of CollexiaEndoApiClient::downloadPayments()
     * @return array{import_id: int, total: int, matched: int, posted: int}
     */
    public function reconcile(array $downloadPaymentsResponse, ?int $userId = null, ?int $bankAccountId = null): array
    {
        $responses = $downloadPaymentsResponse['responses'] ?? [];

        $importId = $this->imports->create([
            'filename' => 'Collexia API download ' . date('Y-m-d H:i:s'),
            'report_type' => 'CollexiaAPI',
            'total_rows' => count($responses),
            'imported_by' => $userId,
        ]);

        $matched = 0;
        $posted = 0;

        foreach ($responses as $row) {
            $contractReference = (string) ($row['contractReference'] ?? '');
            $installmentNo = (int) ($row['installmentNo'] ?? 0);
            $responseCode = (string) ($row['responseCode'] ?? '');
            $isSuccessful = $responseCode === '0';

            $mandate = $contractReference !== '' ? $this->debitOrders->findByContractNo($contractReference) : null;
            $debitOrderId = $mandate['id'] ?? null;
            $loanId = $mandate['loan_id'] ?? null;
            $paymentId = null;

            if ($mandate) {
                $matched++;
            }

            $paymentDate = $this->parseDate($row['paymentDate'] ?? null);
            $paymentAmount = isset($row['paymentAmount']) ? (float) $row['paymentAmount'] : null;

            if ($isSuccessful && $mandate) {
                $alreadyPosted = $this->collections->alreadyPosted((int) $debitOrderId, $installmentNo);

                if (!$alreadyPosted && $paymentAmount !== null && $paymentDate !== null) {
                    $loan = $this->loans->find((int) $loanId);
                    if ($loan) {
                        $paymentId = $this->payments->recordAndAllocate($loan, $paymentAmount, [
                            'payment_date' => $paymentDate,
                            'payment_source' => 'Debit Order',
                            'bank_account_id' => $bankAccountId,
                            'reference_no' => $contractReference . '-' . $installmentNo,
                            'payer_name' => $loan['borrower_name'] ?? ($row['clientSurname'] ?? null),
                            'notes' => 'Collexia EnDO API download: ' . ($row['statementRef'] ?? $contractReference),
                            'user_id' => $userId,
                        ]);
                        $posted++;
                    }
                }
            }

            $this->collections->create([
                'import_id' => $importId,
                'debit_order_id' => $debitOrderId,
                'loan_id' => $loanId,
                'merchant_system_contract_no' => $contractReference,
                'installment_no' => $installmentNo ?: null,
                'scheduled_date' => null,
                'installment_amount' => 0,
                'payment_date' => $isSuccessful ? $paymentDate : null,
                'payment_amount' => $isSuccessful ? $paymentAmount : null,
                'installment_status' => CollexiaV3Codes::RESPONSE_CODES[$responseCode] ?? ('Unknown response code ' . $responseCode),
                'matched' => $mandate ? 1 : 0,
                'payment_id' => $paymentId,
            ]);
        }

        $this->imports->updateRecord($importId, [
            'matched_rows' => $matched,
            'posted_payments' => $posted,
        ]);

        return [
            'import_id' => $importId,
            'total' => count($responses),
            'matched' => $matched,
            'posted' => $posted,
        ];
    }

    /** Collexia dates are YYYYMMDD strings; DB columns are DATE. */
    private function parseDate(?string $yyyymmdd): ?string
    {
        if (!$yyyymmdd || strlen($yyyymmdd) !== 8) {
            return null;
        }
        return substr($yyyymmdd, 0, 4) . '-' . substr($yyyymmdd, 4, 2) . '-' . substr($yyyymmdd, 6, 2);
    }
}
