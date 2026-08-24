<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AfsManualFigure;
use App\Models\FiscalYear;

/**
 * Manual/judgment figures behind the extended AFS export (Tax Computation,
 * Notes to the AFS) that genuinely can't be derived from posted ledger or
 * fixed-asset data -- see database/afs_extended_reports.sql. Fixed slots
 * (a handful of rows per repeatable section) rather than a fully dynamic
 * add/remove UI, trading some flexibility for a much simpler screen; blank
 * rows are just ignored wherever they're used downstream.
 *
 * receivables_prepayment and prior_year_assessed are auto-computed by
 * AfsReportService (from the Balance Sheet and the prior fiscal year's
 * results respectively) when left blank here -- a value entered on this
 * screen overrides the auto figure. Capital allowances are not a manual
 * figure at all: they're derived entirely from the fixed asset register
 * (see AfsReportService::capitalAllowancesFromAssetRegister()). Land &
 * Building is the opposite case -- it's ALWAYS manual (notes_land),
 * deliberately never pulled from the asset register, since land is
 * typically member-owned rather than company-owned and needs a human to
 * confirm actual ownership before it belongs on the Balance Sheet.
 */
class AfsManualFigureController extends Controller
{
    private const MEMBER_TRANSACTION_SLOTS = 5;
    private const BORROWING_SLOTS = 3;
    private const OWNERSHIP_SLOTS = 3;

    private const DEFAULT_POLICY_TEXT = [
        'ppe_policy' => "Property, plant and equipment are stated at historical costs less depreciation. Depreciation is calculated on the straight-line method to reduce book values over the anticipated useful lives of the assets concerned.",
        'revenue_policy' => "Revenue comprises the invoiced value of Interest Income on principal amounts sold and excludes investments and other non-operating income and value added taxation.",
        'inventory_policy' => "Inventories are valued at the lower of cost or estimated net realisable value. Estimated net realisable value is the estimated selling price in the ordinary course of business less any costs of completion and disposal.",
    ];

    private AfsManualFigure $figures;
    private FiscalYear $fiscalYears;

    public function __construct()
    {
        $this->figures = new AfsManualFigure();
        $this->fiscalYears = new FiscalYear();
    }

    public function edit(string $fiscalYearId): void
    {
        Auth::authorize('accounting.balance_sheet');
        $fy = $this->fiscalYears->find((int) $fiscalYearId);
        if (!$fy) {
            Session::flash('error', 'Fiscal year not found.');
            $this->redirect('/accounting/afs-export');
            return;
        }

        $tax = $this->figures->forSection((int) $fiscalYearId, 'tax_computation');
        $policies = $this->figures->forSection((int) $fiscalYearId, 'notes_policies');
        $members = $this->figures->forSection((int) $fiscalYearId, 'notes_members_transactions');
        $borrowings = $this->figures->forSection((int) $fiscalYearId, 'notes_borrowings');
        $ownership = $this->figures->forSection((int) $fiscalYearId, 'notes_ownership');
        $land = $this->figures->forSection((int) $fiscalYearId, 'notes_land');

        $this->view('accounting/afs_manual_figures/edit', [
            'title' => 'AFS Manual Figures - ' . $fy['financial_year'],
            'fiscalYear' => $fy,
            'tax' => $tax,
            'policies' => $policies,
            'defaultPolicyText' => self::DEFAULT_POLICY_TEXT,
            'members' => $members,
            'borrowings' => $borrowings,
            'ownership' => $ownership,
            'land' => $land,
            'memberSlots' => self::MEMBER_TRANSACTION_SLOTS,
            'borrowingSlots' => self::BORROWING_SLOTS,
            'ownershipSlots' => self::OWNERSHIP_SLOTS,
        ]);
    }

    public function update(string $fiscalYearId): void
    {
        Auth::authorize('accounting.balance_sheet');
        $fiscalYearId = (int) $fiscalYearId;
        $fy = $this->fiscalYears->find($fiscalYearId);
        if (!$fy) {
            Session::flash('error', 'Fiscal year not found.');
            $this->redirect('/accounting/afs-export');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/afs-manual-figures/' . $fiscalYearId);
            return;
        }

        $userId = Auth::user()['id'] ?? null;

        foreach (['section17_investment', 'receivables_prepayment', 'insurance_warranty', 'prior_year_assessed', 'tax_rate'] as $key) {
            $value = trim((string) ($_POST[$key] ?? ''));
            $this->figures->set($fiscalYearId, 'tax_computation', $key, null, null, $value !== '' ? (float) $value : null, $userId);
        }

        foreach (array_keys(self::DEFAULT_POLICY_TEXT) as $key) {
            $text = trim((string) ($_POST[$key] ?? ''));
            $this->figures->set($fiscalYearId, 'notes_policies', $key, null, $text ?: null, null, $userId);
        }

        for ($i = 1; $i <= self::MEMBER_TRANSACTION_SLOTS; $i++) {
            $label = trim((string) ($_POST['member_label_' . $i] ?? ''));
            $amount = trim((string) ($_POST['member_amount_' . $i] ?? ''));
            $this->figures->set($fiscalYearId, 'notes_members_transactions', 'transaction_' . $i, $label ?: null, null, $amount !== '' ? (float) $amount : null, $userId);
        }

        for ($i = 1; $i <= self::BORROWING_SLOTS; $i++) {
            $label = trim((string) ($_POST['borrowing_label_' . $i] ?? ''));
            $narrative = trim((string) ($_POST['borrowing_narrative_' . $i] ?? ''));
            $amount = trim((string) ($_POST['borrowing_amount_' . $i] ?? ''));
            $this->figures->set($fiscalYearId, 'notes_borrowings', 'borrowing_' . $i, $label ?: null, $narrative ?: null, $amount !== '' ? (float) $amount : null, $userId);
        }

        for ($i = 1; $i <= self::OWNERSHIP_SLOTS; $i++) {
            $label = trim((string) ($_POST['owner_label_' . $i] ?? ''));
            $pct = trim((string) ($_POST['owner_pct_' . $i] ?? ''));
            $this->figures->set($fiscalYearId, 'notes_ownership', 'owner_' . $i, $label ?: null, null, $pct !== '' ? (float) $pct : null, $userId);
        }

        $landBuilding = trim((string) ($_POST['land_building'] ?? ''));
        $this->figures->set($fiscalYearId, 'notes_land', 'land_building', null, null, $landBuilding !== '' ? (float) $landBuilding : null, $userId);

        Audit::log('Update', 'Accounting', 'Updated AFS manual figures for ' . $fy['financial_year']);
        Session::flash('success', 'Manual figures saved.');
        $this->redirect('/accounting/afs-manual-figures/' . $fiscalYearId);
    }
}
