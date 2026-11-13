<?php

namespace Tests\Feature;

use App\Controllers\BorrowerController;
use App\Core\View;
use PHPUnit\Framework\TestCase;

/** The Add Borrower page is a step wizard with a loan request step and the ID copy and payslip up front. */
class BorrowerWizardTest extends TestCase
{
    private function page(array $old = [], array $errors = []): string
    {
        $_SESSION = [];
        $reqs = [
            ['id' => 1, 'requirement_name' => 'Latest Payslip', 'document_type' => 'Payslip', 'is_required' => 1],
            ['id' => 2, 'requirement_name' => 'Employment Confirmation', 'document_type' => 'Employment Confirmation', 'is_required' => 0],
            ['id' => 3, 'requirement_name' => 'National ID Copy', 'document_type' => 'ID Copy', 'is_required' => 1],
        ];
        set_error_handler(static function ($n, $m, $f, $l) { throw new \ErrorException($m, 0, $n, $f, $l); });
        ob_start();
        try {
            View::renderFragment('borrowers/create', ['branches' => [['id' => 1, 'branch_name' => 'HO']], 'documentRequirements' => $reqs, 'existingBorrowers' => [], 'old' => $old, 'errors' => $errors]);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }

    public function testTheStepsAreInOrderEndingWithDocumentsAndReview(): void
    {
        $html = $this->page();
        preg_match_all('/data-step="([a-z]+)"/', $html, $m);

        $this->assertSame(['personal', 'address', 'employment', 'financial', 'banking', 'contacts', 'loan', 'documents', 'review'], $m[1]);
        foreach (['bw-back', 'bw-next', 'bw-save'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
    }

    public function testTheLoanRequestStepAsksHowMuchTheClientWantsToBorrow(): void
    {
        $html = $this->page(['requested_amount' => '5000', 'requested_term_months' => '6', 'requested_purpose' => 'School fees']);

        $this->assertMatchesRegularExpression('/name="requested_amount"[^>]*value="5000"[^>]*required/', $html);
        $this->assertStringContainsString('Amount the client wants to borrow', $html);
        $this->assertStringContainsString('name="requested_term_months"', $html);
        $this->assertStringContainsString('School fees</textarea>', $html);
    }

    public function testTheLoanErrorsAreShown(): void
    {
        $html = $this->page([], ['requested_amount' => 'Enter how much the client wants to borrow (an amount above zero).']);

        $this->assertStringContainsString('Enter how much the client wants to borrow', $html);
    }

    public function testTheIdCopyAndPayslipComeFirstAndAreHighlighted(): void
    {
        $html = $this->page();
        preg_match_all('/data-doc-type="([^"]+)"/', $html, $m);

        $this->assertSame(['ID Copy', 'Payslip', 'Employment Confirmation'], $m[1]);
        $this->assertStringContainsString('Both sides of the ID', $html);
        $this->assertSame(2, substr_count($html, '<div class="bw-featured" data-doc-type='));
    }

    /** @dataProvider loanRequests */
    public function testTheLoanRequestIsValidated(array $post, array $expectedFields): void
    {
        $method = new \ReflectionMethod(BorrowerController::class, 'validateLoanRequest');
        $method->setAccessible(true);
        $controller = (new \ReflectionClass(BorrowerController::class))->newInstanceWithoutConstructor();

        $this->assertSame($expectedFields, array_keys($method->invoke($controller, $post)));
    }

    public static function loanRequests(): array
    {
        return [
            'a good request' => [['requested_amount' => '5000', 'requested_term_months' => '12'], []],
            'amount only' => [['requested_amount' => '250.50'], []],
            'no amount' => [['requested_amount' => ''], ['requested_amount']],
            'missing amount' => [[], ['requested_amount']],
            'zero' => [['requested_amount' => '0'], ['requested_amount']],
            'negative' => [['requested_amount' => '-5'], ['requested_amount']],
            'text' => [['requested_amount' => 'lots'], ['requested_amount']],
            'absurd' => [['requested_amount' => '1000000000'], ['requested_amount']],
            'term not a number' => [['requested_amount' => '10', 'requested_term_months' => 'six'], ['requested_term_months']],
            'term zero' => [['requested_amount' => '10', 'requested_term_months' => '0'], ['requested_term_months']],
            'term too long' => [['requested_amount' => '10', 'requested_term_months' => '121'], ['requested_term_months']],
            'both wrong' => [['requested_amount' => '', 'requested_term_months' => '-1'], ['requested_amount', 'requested_term_months']],
        ];
    }
}
