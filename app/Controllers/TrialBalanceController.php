<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\JournalEntry;

class TrialBalanceController extends Controller
{
    private JournalEntry $journalEntries;

    public function __construct()
    {
        $this->journalEntries = new JournalEntry();
    }

    public function index(): void
    {
        Auth::authorize('accounting.trial_balance');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

        $result = $this->journalEntries->trialBalanceGrouped($asOfDate);

        $this->view('accounting/trial_balance/index', [
            'title' => 'Trial Balance',
            'groups' => $result['groups'],
            'asOfDate' => $asOfDate,
            'totalDebit' => $result['grand_total_debit'],
            'totalCredit' => $result['grand_total_credit'],
        ]);
    }

    public function exportExcel(): void
    {
        Auth::authorize('accounting.trial_balance');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

        $result = $this->journalEntries->trialBalanceGrouped($asOfDate);

        $spreadsheet = \App\Services\TrialBalanceExcelExporter::build($result, $asOfDate);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Trial_Balance_' . $asOfDate . '.xlsx"');
        header('Cache-Control: max-age=0');
        \App\Services\TrialBalanceExcelExporter::save($spreadsheet, 'php://output');
        exit;
    }
}
