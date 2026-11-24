<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Borrower;
use App\Services\BorrowerProfileService;
use PHPUnit\Framework\TestCase;

/**
 * Full edit of a borrower (profile + current employment + primary bank + contacts), against the local development database.
 * Fixtures use the borrower_no prefix BRW-FE- and the id_number prefix FE-; they are removed in tearDown (child rows cascade).
 */
class BorrowerFullEditTest extends TestCase
{
    private \PDO $db;
    private BorrowerProfileService $svc;
    private Borrower $borrowers;
    private int $branchId;
    private int $borrowerId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->svc = new BorrowerProfileService($this->db);
        $this->borrowers = new Borrower();
        $this->wipe();
        $this->branchId = (int) $this->db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
        $this->assertGreaterThan(0, $this->branchId, 'the local database needs at least one branch');
        $this->borrowerId = $this->makeBorrower('BRW-FE-0001', 'FE-1000001', '1985-05-05');
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    private function wipe(): void
    {
        $this->db->exec("DELETE FROM borrowers WHERE borrower_no LIKE 'BRW-FE-%'");
    }

    private function makeBorrower(string $no, string $idNumber, ?string $dob): int
    {
        $st = $this->db->prepare("INSERT INTO borrowers (branch_id, borrower_no, first_name, last_name, id_number, date_of_birth, phone, status) VALUES (?, ?, 'Fixture', 'Client', ?, ?, '0811111111', 'Approved')");
        $st->execute([$this->branchId, $no, $idNumber, $dob]);
        return (int) $this->db->lastInsertId();
    }

    private function row(string $sql, array $params = []): ?array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function rowCount(string $table): int
    {
        return (int) $this->row("SELECT COUNT(*) c FROM $table WHERE borrower_id = ?", [$this->borrowerId])['c'];
    }

    /** What the edit form would post for the stored borrower, with $override applied on top (full_edit=1 unless overridden). */
    private function post(array $override = []): array
    {
        $b = $this->borrowers->find($this->borrowerId);
        $base = [
            'full_edit' => '1',
            'branch_id' => (string) $b['branch_id'],
            'first_name' => $b['first_name'],
            'middle_name' => '',
            'last_name' => $b['last_name'],
            'gender' => '',
            'title' => '',
            'date_of_birth' => (string) $b['date_of_birth'],
            'id_number' => (string) $b['id_number'],
            'passport_no' => '',
            'loan_ref_no' => '',
            'phone' => (string) $b['phone'],
            'home_telephone' => '',
            'email' => '',
            'marital_status' => '',
            'nationality' => 'Namibian',
            'status' => 'Approved',
            'ownership_type' => '00',
        ];
        return array_merge($base, $override);
    }

    private function validate(array $post): array
    {
        return $this->svc->validate($post, $this->borrowers->find($this->borrowerId));
    }

    private function save(array $post): array
    {
        return $this->svc->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $post);
    }

    private function employmentPost(array $o = []): array
    {
        return array_merge([
            'employer_name' => 'Acme Mining',
            'employee_no' => 'E-77',
            'job_title' => 'Foreman',
            'employment_type' => 'Permanent',
            'employment_start_date' => '2020-02-01',
            'employment_payment_day' => '25',
            'gross_salary' => '20000.00',
            'net_salary' => '15500.50',
            'income_frequency' => 'M',
            'employer_phone' => '061123456',
            'employer_email' => 'hr@acme.example',
            'employer_address' => '1 Mine Road',
        ], $o);
    }

    private function bankPost(array $o = []): array
    {
        return array_merge([
            'bank_name' => 'FNB',
            'account_name' => 'F Client',
            'account_number' => '62000111222',
            'account_type' => 'Savings',
            'bank_branch_name' => 'Windhoek',
            'bank_branch_code' => '280172',
        ], $o);
    }

    // ------------------------------------------------------------------ passport / borrower row

    public function testPassportAndBorrowerFieldsAreSaved(): void
    {
        $changed = $this->save($this->post(['passport_no' => ' P1234567 ', 'phone' => '0812222222']));

        $b = $this->borrowers->find($this->borrowerId);
        $this->assertSame('P1234567', $b['passport_no']);
        $this->assertSame('0812222222', $b['phone']);
        $this->assertContains('passport_no', $changed);
        $this->assertContains('phone', $changed);
        $this->assertNotContains('first_name', $changed, 'unchanged fields are not reported');
    }

    public function testAnOlderFormWithoutPassportOrSectionsDoesNotBlankAnything(): void
    {
        $this->save($this->post(['passport_no' => 'P7654321']));
        $stale = $this->post(['full_edit' => '0'] + $this->employmentPost() + $this->bankPost());
        unset($stale['passport_no']);
        $this->save($stale);
        $old = $this->post();
        unset($old['passport_no'], $old['full_edit']);
        $this->save($old);

        $this->assertSame('P7654321', $this->borrowers->find($this->borrowerId)['passport_no']);
        $this->assertSame(0, $this->rowCount('borrower_employment'), 'sections are only saved when full_edit=1');
        $this->assertSame(0, $this->rowCount('borrower_bank_details'));
    }

    // ------------------------------------------------------------------ employment

    public function testEmploymentIsCreatedThenUpdatedInPlace(): void
    {
        $this->assertSame([], $this->validate($this->post($this->employmentPost())));
        $changed = $this->save($this->post($this->employmentPost()));
        $this->assertContains('employment (added)', $changed);

        $e = $this->svc->currentEmployment($this->borrowerId);
        $this->assertSame('Acme Mining', $e['employer_name']);
        $this->assertSame('1', (string) $e['is_current']);
        $this->assertSame('M', $e['income_frequency']);
        $this->assertSame('25', (string) $e['payment_day']);
        $this->assertEquals(20000.00, (float) $e['gross_salary']);
        $this->assertEquals(15500.50, (float) $e['net_salary']);
        $this->assertSame('2020-02-01', $e['employment_start_date']);

        $changed = $this->save($this->post($this->employmentPost(['gross_salary' => '21000', 'job_title' => 'Supervisor'])));
        $this->assertSame(1, $this->rowCount('borrower_employment'), 'edited in place, not duplicated');
        $e2 = $this->svc->currentEmployment($this->borrowerId);
        $this->assertSame($e['id'], $e2['id']);
        $this->assertEquals(21000.00, (float) $e2['gross_salary']);
        $this->assertSame('Supervisor', $e2['job_title']);
        $this->assertContains('employment.job_title', $changed);
        $this->assertContains('employment.gross_salary', $changed);
        $this->assertNotContains('employment.employer_name', $changed);
    }

    public function testEmploymentHistoryRowIsKeptAndClearingEndsTheCurrentRow(): void
    {
        $this->save($this->post($this->employmentPost()));
        $this->db->prepare("INSERT INTO borrower_employment (borrower_id, employer_name, gross_salary, is_current) VALUES (?, 'Old Employer', 5000, 0)")->execute([$this->borrowerId]);
        $this->assertSame(2, $this->rowCount('borrower_employment'));
        $this->assertSame('Acme Mining', $this->svc->currentEmployment($this->borrowerId)['employer_name']);

        $changed = $this->save($this->post(['employer_name' => '']));
        $this->assertContains('employment (ended)', $changed);
        $this->assertNull($this->svc->currentEmployment($this->borrowerId));
        $this->assertSame(2, $this->rowCount('borrower_employment'), 'nothing is deleted');
        $this->assertSame('Old Employer', $this->row("SELECT employer_name FROM borrower_employment WHERE borrower_id = ? AND employer_name = 'Old Employer'", [$this->borrowerId])['employer_name']);
    }

    // ------------------------------------------------------------------ bank

    public function testBankIsCreatedUpdatedInPlaceAndRemovedWhenCleared(): void
    {
        $this->assertSame([], $this->validate($this->post($this->bankPost())));
        $this->assertContains('bank (added)', $this->save($this->post($this->bankPost())));
        $b = $this->svc->primaryBank($this->borrowerId);
        $this->assertSame('62000111222', $b['account_number']);
        $this->assertSame('1', (string) $b['is_primary']);
        $this->assertSame('Windhoek', $b['branch_name']);
        $this->assertSame('280172', $b['branch_code']);

        $changed = $this->save($this->post($this->bankPost(['account_number' => '62999999999', 'account_type' => 'Cheque'])));
        $this->assertSame(1, $this->rowCount('borrower_bank_details'));
        $b2 = $this->svc->primaryBank($this->borrowerId);
        $this->assertSame($b['id'], $b2['id']);
        $this->assertSame('62999999999', $b2['account_number']);
        $this->assertSame('Cheque', $b2['account_type']);
        $this->assertContains('bank.account_number', $changed);
        $this->assertNotContains('bank.bank_name', $changed);

        $this->assertContains('bank (removed)', $this->save($this->post($this->bankPost(['bank_name' => '', 'account_name' => '', 'account_number' => '', 'account_type' => '', 'bank_branch_name' => '', 'bank_branch_code' => '']))));
        $this->assertSame(0, $this->rowCount('borrower_bank_details'));
    }

    // ------------------------------------------------------------------ contacts

    public function testContactsAreAddedUpdatedAndRemovedByDiff(): void
    {
        $post = $this->post(['contacts' => [
            ['id' => '', 'contact_type' => 'Next of Kin', 'full_name' => 'Anna Kin', 'relationship' => 'Sister', 'phone' => '0813333333', 'email' => 'anna@example.com', 'address' => 'House 1'],
            ['id' => '', 'contact_type' => 'Guarantor', 'full_name' => 'Gus Guarantor', 'relationship' => '', 'phone' => '', 'email' => '', 'address' => ''],
            ['id' => '', 'contact_type' => 'Next of Kin', 'full_name' => '', 'relationship' => '', 'phone' => '', 'email' => '', 'address' => ''], // untouched blank row
        ]]);
        $this->assertSame([], $this->validate($post));
        $changed = $this->save($post);
        $this->assertContains('contacts: 2 added', $changed);
        $rows = $this->svc->contacts($this->borrowerId);
        $this->assertCount(2, $rows);
        [$anna, $gus] = $rows;
        $this->assertSame('Anna Kin', $anna['full_name']);
        $this->assertNull($gus['phone'], 'blank optional fields are stored as NULL');

        // update Anna in place, drop Gus (not posted), add a new one
        $post2 = $this->post(['loaded_contact_ids' => $anna['id'] . ',' . $gus['id'], 'contacts' => [
            ['id' => (string) $anna['id'], 'contact_type' => 'Emergency', 'full_name' => 'Anna K. Kin', 'relationship' => 'Sister', 'phone' => '0813333333', 'email' => 'anna@example.com', 'address' => 'House 1'],
            ['id' => '', 'contact_type' => 'Other', 'full_name' => 'Newton New', 'relationship' => '', 'phone' => '', 'email' => '', 'address' => ''],
        ]]);
        $this->assertSame([], $this->validate($post2));
        $changed = $this->save($post2);
        $this->assertContains('contacts: 1 added', $changed);
        $this->assertContains('contacts: 1 updated', $changed);
        $this->assertContains('contacts: 1 removed', $changed);

        $rows = $this->svc->contacts($this->borrowerId);
        $this->assertCount(2, $rows);
        $byName = array_column($rows, null, 'full_name');
        $this->assertArrayHasKey('Anna K. Kin', $byName);
        $this->assertSame($anna['id'], $byName['Anna K. Kin']['id'], 'the existing row was updated, not replaced');
        $this->assertSame('Emergency', $byName['Anna K. Kin']['contact_type']);
        $this->assertArrayHasKey('Newton New', $byName);
        $this->assertArrayNotHasKey('Gus Guarantor', $byName);
    }

    public function testSavingWithoutTheSectionsNeverTouchesContacts(): void
    {
        $this->save($this->post(['contacts' => [['id' => '', 'contact_type' => 'Next of Kin', 'full_name' => 'Keep Me']]]));
        $stale = $this->post(['first_name' => 'Renamed']);
        unset($stale['full_edit']);
        $this->save($stale);

        $this->assertSame('Renamed', $this->borrowers->find($this->borrowerId)['first_name']);
        $this->assertCount(1, $this->svc->contacts($this->borrowerId));
    }

    public function testAContactCannotBeEditedThroughAnotherBorrower(): void
    {
        $otherId = $this->makeBorrower('BRW-FE-0002', 'FE-1000002', '1990-01-01');
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Next of Kin', 'Someone Else')")->execute([$otherId]);
        $foreignId = (int) $this->db->lastInsertId();

        $errors = $this->validate($this->post(['contacts' => [['id' => (string) $foreignId, 'contact_type' => 'Next of Kin', 'full_name' => 'Hijacked']]]));
        $this->assertArrayHasKey('contacts.0.id', $errors);
        $this->assertSame('Someone Else', $this->row('SELECT full_name FROM borrower_contacts WHERE id = ?', [$foreignId])['full_name']);
    }

    // ------------------------------------------------------------------ validation + old input

    public function testValidationErrorsAreReportedPerField(): void
    {
        $errors = $this->validate($this->post($this->employmentPost([
            'employer_name' => '',
            'gross_salary' => '-5',
            'net_salary' => 'abc',
            'employment_start_date' => date('Y-m-d', strtotime('+10 days')),
            'employment_payment_day' => '40',
            'income_frequency' => 'X',
            'employment_type' => 'Astronaut',
            'employer_email' => 'not-an-email',
        ]) + $this->bankPost(['account_type' => 'Piggybank', 'bank_name' => '', 'account_number' => '']) + ['contacts' => [
            ['id' => '', 'contact_type' => 'Next of Kin', 'full_name' => '', 'phone' => '081'],
            ['id' => '', 'contact_type' => 'Wizard', 'full_name' => 'Gandalf', 'email' => 'bad'],
        ]]));

        foreach (['employer_name', 'gross_salary', 'net_salary', 'employment_start_date', 'employment_payment_day', 'income_frequency', 'employment_type', 'employer_email', 'account_type', 'bank_name', 'contacts.0.full_name', 'contacts.1.contact_type', 'contacts.1.email'] as $key) {
            $this->assertArrayHasKey($key, $errors, "expected an error for $key");
        }
    }

    public function testStartDateTodayAndZeroSalaryAreAccepted(): void
    {
        $this->assertSame([], $this->validate($this->post($this->employmentPost(['employment_start_date' => date('Y-m-d'), 'gross_salary' => '0', 'net_salary' => '0']))));
    }

    public function testDuplicateIdNumberLoanRefAndLongPassportAreRejected(): void
    {
        $this->makeBorrower('BRW-FE-0003', 'FE-1000003', '1990-01-01');
        $this->db->exec("UPDATE borrowers SET loan_ref_no = 'FE-REF-1' WHERE borrower_no = 'BRW-FE-0003'");

        $errors = $this->validate($this->post(['id_number' => 'FE-1000003', 'loan_ref_no' => 'FE-REF-1', 'passport_no' => str_repeat('P', 51)]));
        $this->assertArrayHasKey('id_number', $errors);
        $this->assertArrayHasKey('loan_ref_no', $errors);
        $this->assertArrayHasKey('passport_no', $errors);
        $this->assertSame([], $this->validate($this->post(['id_number' => 'FE-1000001'])), 'its own ID number is fine');
    }

    public function testFailedValidationKeepsWhatWasTypedForTheForm(): void
    {
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Guarantor', 'Stored Contact')")->execute([$this->borrowerId]);
        $storedId = (int) $this->db->lastInsertId();

        $post = $this->post($this->employmentPost(['gross_salary' => '-1', 'job_title' => 'Typed Title']) + ['loaded_contact_ids' => (string) $storedId, 'contacts' => [
            ['id' => (string) $storedId, 'contact_type' => 'Emergency', 'full_name' => 'Typed Name', 'email' => 'bad'],
            ['id' => '', 'contact_type' => 'Other', 'full_name' => 'Brand New'],
        ]]);
        $errors = $this->validate($post);
        $this->assertArrayHasKey('gross_salary', $errors);
        $this->assertArrayHasKey('contacts.0.email', $errors);

        // what the controller hands the view: the typed values win over the stored ones, contacts keep their ids and positions
        $rows = $this->svc->contactsForForm($this->borrowerId, $post);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $storedId, $rows[0]['id']);
        $this->assertSame('Typed Name', $rows[0]['full_name']);
        $this->assertSame('Emergency', $rows[0]['contact_type']);
        $this->assertSame('', $rows[1]['id']);

        $this->assertSame((string) $storedId, $this->svc->loadedContactIdsForForm($this->borrowerId, $post));
        $html = $this->renderEdit(array_merge($this->borrowers->find($this->borrowerId), $this->svc->formValues($this->borrowerId), $post), $rows, $errors, $this->svc->loadedContactIdsForForm($this->borrowerId, $post));
        $this->assertStringContainsString('Typed Title', $html);
        $this->assertStringContainsString('Typed Name', $html);
        $this->assertStringContainsString('Brand New', $html);
        $this->assertStringContainsString('name="contacts[0][id]" value="' . $storedId . '"', $html);
        $this->assertStringContainsString('Gross Salary must be a number of zero or more.', $html);
        $this->assertStringContainsString('Enter a valid email address.', $html);
        $this->assertStringContainsString('name="full_edit" value="1"', $html);
        $this->assertStringContainsString('name="loaded_contact_ids" value="' . $storedId . '"', $html);
        $this->assertStringContainsString('name="passport_no"', $html);
        $this->assertStringNotContainsString('Stored Contact', $html, 'the re-render shows the typed rows, not the stored ones');
    }

    public function testTheEditPageShowsStoredEmploymentBankAndContacts(): void
    {
        $this->save($this->post($this->employmentPost() + $this->bankPost() + ['passport_no' => 'P555', 'contacts' => [['id' => '', 'contact_type' => 'Guarantor', 'full_name' => 'Stored Gus', 'phone' => '0814444444']]]));

        $borrower = $this->borrowers->find($this->borrowerId);
        $html = $this->renderEdit(array_merge($borrower, $this->svc->formValues($this->borrowerId)), $this->svc->contactsForForm($this->borrowerId), []);
        foreach (['Acme Mining', 'Foreman', '62000111222', 'FNB', 'P555', 'Stored Gus', '0814444444', '2020-02-01'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertMatchesRegularExpression('/<option value="Permanent" selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="M" selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="Savings" selected>/', $html);
    }

    // ------------------------------------------------------------------ atomicity

    public function testAFailureHalfWayRollsEverythingBack(): void
    {
        // the contacts step is the last one: make it fail AFTER the borrower row, employment and bank were already written
        $failing = new class ($this->db) extends BorrowerProfileService {
            protected function saveContacts(int $borrowerId, array $post): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $post = $this->post(['first_name' => 'ShouldNotStick'] + $this->employmentPost() + $this->bankPost() + ['contacts' => [
            ['id' => '', 'contact_type' => 'Next of Kin', 'full_name' => 'Boom'],
        ]]);

        try {
            $failing->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $post);
            $this->fail('the save should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertFalse($this->db->inTransaction(), 'the transaction was rolled back, not left open');
        }

        $this->assertSame('Fixture', $this->borrowers->find($this->borrowerId)['first_name']);
        $this->assertSame(0, $this->rowCount('borrower_employment'));
        $this->assertSame(0, $this->rowCount('borrower_bank_details'));
        $this->assertSame(0, $this->rowCount('borrower_contacts'));
    }

    public function testInvalidInputSavesNothingWhenTheCallerStopsAtValidation(): void
    {
        $post = $this->post(['first_name' => 'Nope'] + $this->employmentPost(['gross_salary' => '-1']) + $this->bankPost());
        $this->assertNotEmpty($this->validate($post));
        // the controller never calls save() when validate() returned errors -- the stored data is untouched
        $this->assertSame('Fixture', $this->borrowers->find($this->borrowerId)['first_name']);
        $this->assertSame(0, $this->rowCount('borrower_employment'));
        $this->assertSame(0, $this->rowCount('borrower_bank_details'));
    }

    // ------------------------------------------------------------------ date of birth

    public function testDateOfBirthRuleOnlyAppliesWhenTheDateWasChanged(): void
    {
        // a stored DOB that would fail today's rule (under age) is left alone while the staff member edits something else
        $this->db->prepare('UPDATE borrowers SET date_of_birth = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-5 years')), $this->borrowerId]);
        $this->assertArrayNotHasKey('date_of_birth', $this->validate($this->post(['phone' => '0819999999'])));

        // changing it to something unbelievable is refused
        $this->assertArrayHasKey('date_of_birth', $this->validate($this->post(['date_of_birth' => date('Y-m-d')])));
        $this->assertArrayHasKey('date_of_birth', $this->validate($this->post(['date_of_birth' => date('Y-m-d', strtotime('-10 years'))])));

        // changing it to a believable date is accepted
        $this->assertArrayNotHasKey('date_of_birth', $this->validate($this->post(['date_of_birth' => '1980-03-03'])));
    }

    // ------------------------------------------------------------------ re-entered employment + CPL duplicates

    public function testReEnteredEmploymentOfTheSameEmployerReactivatesTheRetiredRow(): void
    {
        $this->save($this->post($this->employmentPost()));
        $firstId = $this->svc->currentEmployment($this->borrowerId)['id'];
        $this->save($this->post(['employer_name' => '']));
        $this->assertNull($this->svc->currentEmployment($this->borrowerId));

        // same employer (a correction of the same job; case and spacing do not matter): the retired row comes back, no second row
        $changed = $this->save($this->post($this->employmentPost(['employer_name' => '  acme   MINING ', 'gross_salary' => '30000'])));
        $this->assertContains('employment (re-activated)', $changed);
        $this->assertSame(1, $this->rowCount('borrower_employment'), 'no second row');
        $e = $this->svc->currentEmployment($this->borrowerId);
        $this->assertSame($firstId, $e['id']);
        $this->assertEquals(30000.00, (float) $e['gross_salary']);
        $this->assertSame('1', (string) $e['is_current']);
    }

    public function testReEnteredEmploymentOfADifferentEmployerInsertsANewRowAndKeepsTheOldOneAsHistory(): void
    {
        $this->save($this->post($this->employmentPost()));
        $firstId = $this->svc->currentEmployment($this->borrowerId)['id'];
        $this->save($this->post(['employer_name' => '']));

        $changed = $this->save($this->post($this->employmentPost(['employer_name' => 'Beta Corp', 'gross_salary' => '30000'])));
        $this->assertContains('employment (added)', $changed);
        $this->assertSame(2, $this->rowCount('borrower_employment'), 'the old job stays as history');
        $e = $this->svc->currentEmployment($this->borrowerId);
        $this->assertNotSame($firstId, $e['id']);
        $this->assertSame('Beta Corp', $e['employer_name']);
        $old = $this->row('SELECT * FROM borrower_employment WHERE id = ?', [$firstId]);
        $this->assertSame('Acme Mining', $old['employer_name'], 'history untouched');
        $this->assertSame('0', (string) $old['is_current']);
        $this->assertEquals(20000.00, (float) $old['gross_salary']);
        $this->assertSame(1, (int) $this->row('SELECT COUNT(*) c FROM borrower_employment WHERE borrower_id = ? AND is_current = 1', [$this->borrowerId])['c']);
    }

    public function testReEnteringEmploymentLooksOnlyAtTheNewestRetiredRow(): void
    {
        $ins = $this->db->prepare("INSERT INTO borrower_employment (borrower_id, employer_name, gross_salary, is_current) VALUES (?, ?, 1000, 0)");
        $ins->execute([$this->borrowerId, 'Oldest']);
        $ins->execute([$this->borrowerId, 'Newest retired']);
        $newestId = (int) $this->db->lastInsertId();

        // same employer as the newest retired row: that row is re-activated
        $this->save($this->post($this->employmentPost(['employer_name' => 'Newest retired'])));
        $this->assertSame(2, $this->rowCount('borrower_employment'));
        $this->assertSame($newestId, (int) $this->svc->currentEmployment($this->borrowerId)['id']);
        $this->assertSame('Oldest', $this->row("SELECT employer_name FROM borrower_employment WHERE borrower_id = ? AND is_current = 0", [$this->borrowerId])['employer_name']);

        // an employer matching only an OLDER retired row is a new job
        $this->save($this->post(['employer_name' => '']));
        $this->save($this->post($this->employmentPost(['employer_name' => 'Oldest'])));
        $this->assertSame(3, $this->rowCount('borrower_employment'));
    }

    public function testClearingEmploymentRetiresEveryCurrentRow(): void
    {
        $ins = $this->db->prepare('INSERT INTO borrower_employment (borrower_id, employer_name, gross_salary, is_current) VALUES (?, ?, ?, 1)');
        $ins->execute([$this->borrowerId, 'Current One', 200]);
        $ins->execute([$this->borrowerId, 'Current Two', 300]);
        $this->assertSame(2, (int) $this->row('SELECT COUNT(*) c FROM borrower_employment WHERE borrower_id = ? AND is_current = 1', [$this->borrowerId])['c']);

        $changed = $this->save($this->post(['employer_name' => '']));
        $this->assertContains('employment (ended)', $changed);
        $this->assertSame(0, (int) $this->row('SELECT COUNT(*) c FROM borrower_employment WHERE borrower_id = ? AND is_current = 1', [$this->borrowerId])['c'], 'no row is still read as current');
        $this->assertSame(2, $this->rowCount('borrower_employment'), 'nothing deleted');
        $this->assertNull($this->svc->currentEmployment($this->borrowerId));
        $this->assertSame([], $this->save($this->post(['employer_name' => ''])), 'clearing again changes nothing');
    }

    public function testANormalSaveCollapsesOlderDuplicateCurrentRowsKeepingTheNewest(): void
    {
        $ins = $this->db->prepare('INSERT INTO borrower_employment (borrower_id, employer_name, gross_salary, is_current) VALUES (?, ?, ?, 1)');
        $ins->execute([$this->borrowerId, 'Current One', 200]);
        $ins->execute([$this->borrowerId, 'Current Two', 300]);
        $newest = (int) $this->db->lastInsertId();
        $ins->execute([$this->borrowerId, 'Current Three', 400]);
        $newest = (int) $this->db->lastInsertId();

        // an unrelated edit (phone) with the form showing the newest row unchanged
        $changed = $this->save($this->post(['employer_name' => 'Current Three', 'gross_salary' => '400', 'phone' => '0819999999']));
        $this->assertContains('phone', $changed);
        $this->assertSame(1, (int) $this->row('SELECT COUNT(*) c FROM borrower_employment WHERE borrower_id = ? AND is_current = 1', [$this->borrowerId])['c']);
        $this->assertSame($newest, (int) $this->svc->currentEmployment($this->borrowerId)['id']);
        $this->assertSame(3, $this->rowCount('borrower_employment'), 'history kept');
        $this->assertNotEmpty(array_filter($changed, static fn ($c) => str_starts_with($c, 'employment (older duplicate')));
        // and now stable
        $this->assertSame([], array_filter($this->save($this->post(['employer_name' => 'Current Three', 'gross_salary' => '400', 'phone' => '0819999999'])), static fn ($c) => str_starts_with($c, 'employment')));
    }

    private function cplRowsFor(string $loanNo): array
    {
        return array_values(array_filter((new \App\Services\CplExporter())->eligibleLoans('2099-12-31'), static fn ($r) => $r['loan_no'] === $loanNo));
    }

    public function testCplExtractNeverMultipliesALoanWhenAClientHasSeveralEmploymentRows(): void
    {
        $tpl = $this->row('SELECT product_id, plan_id FROM loans WHERE product_id IS NOT NULL AND plan_id IS NOT NULL ORDER BY id LIMIT 1');
        if ($tpl === null) {
            $this->markTestSkipped('the local database has no loan to copy the product and plan from');
        }
        $loanNo = 'LN-FE-' . uniqid();
        $this->db->prepare("INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date) VALUES (?, ?, ?, ?, ?, 'Active', 1000, 100, 17, '2020-01-17')")
            ->execute([$loanNo, $this->borrowerId, $tpl['product_id'], $tpl['plan_id'], $this->branchId]);

        try {
            // three rows the way real data can look: two flagged current (the old defect) plus a retired one
            $ins = $this->db->prepare('INSERT INTO borrower_employment (borrower_id, employer_name, gross_salary, is_current) VALUES (?, ?, ?, ?)');
            $ins->execute([$this->borrowerId, 'Retired Co', 100, 0]);
            $ins->execute([$this->borrowerId, 'Current One', 200, 1]);
            $ins->execute([$this->borrowerId, 'Current Two', 300, 1]);

            $rows = $this->cplRowsFor($loanNo);
            $this->assertCount(1, $rows, 'one extract row per loan');
            $this->assertSame('Current Two', $rows[0]['employer_name'], 'the newest current employment row is the one used');

            // a client whose employment was cleared has retired rows only: the loan is still extracted once, with no employer
            $this->db->prepare('UPDATE borrower_employment SET is_current = 0 WHERE borrower_id = ?')->execute([$this->borrowerId]);
            $rows = $this->cplRowsFor($loanNo);
            $this->assertCount(1, $rows);
            $this->assertNull($rows[0]['employer_name']);
        } finally {
            $this->db->prepare('DELETE FROM loans WHERE loan_no = ?')->execute([$loanNo]);
        }
    }

    public function testClearThenReEnterEmploymentGivesOneCplRowPerLoan(): void
    {
        $tpl = $this->row('SELECT product_id, plan_id FROM loans WHERE product_id IS NOT NULL AND plan_id IS NOT NULL ORDER BY id LIMIT 1');
        if ($tpl === null) {
            $this->markTestSkipped('the local database has no loan to copy the product and plan from');
        }
        $loanNo = 'LN-FE-' . uniqid();
        $this->db->prepare("INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date) VALUES (?, ?, ?, ?, ?, 'Active', 1000, 100, 17, '2020-01-17')")
            ->execute([$loanNo, $this->borrowerId, $tpl['product_id'], $tpl['plan_id'], $this->branchId]);
        try {
            $this->save($this->post($this->employmentPost()));
            $this->save($this->post(['employer_name' => '']));
            $this->save($this->post($this->employmentPost(['employer_name' => 'Re-entered Co'])));
            $rows = $this->cplRowsFor($loanNo);
            $this->assertCount(1, $rows);
            $this->assertSame('Re-entered Co', $rows[0]['employer_name']);
        } finally {
            $this->db->prepare('DELETE FROM loans WHERE loan_no = ?')->execute([$loanNo]);
        }
    }

    // ------------------------------------------------------------------ column sizes

    public function testTooLongBorrowerFieldsAreReportedPerField(): void
    {
        $long = static fn (int $n): string => str_repeat('x', $n);
        $errors = $this->validate($this->post([
            'home_telephone' => $long(21), 'loan_ref_no' => $long(31), 'id_number' => $long(51),
            'first_name' => $long(101), 'middle_name' => $long(101), 'last_name' => $long(101),
            'nationality' => $long(101), 'phone' => $long(51), 'email' => $long(151),
        ]));
        foreach (['home_telephone' => 20, 'loan_ref_no' => 30, 'id_number' => 50, 'first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'nationality' => 100, 'phone' => 50, 'email' => 150] as $field => $max) {
            $this->assertArrayHasKey($field, $errors, "expected a length error for $field");
            $this->assertStringContainsString((string) $max, $errors[$field]);
        }

        // exactly at the limit is fine
        $ok = $this->validate($this->post(['home_telephone' => $long(20), 'loan_ref_no' => $long(30), 'phone' => $long(50), 'nationality' => $long(100), 'middle_name' => $long(100)]));
        foreach (['home_telephone', 'loan_ref_no', 'phone', 'nationality', 'middle_name'] as $field) {
            $this->assertArrayNotHasKey($field, $ok);
        }
    }

    public function testTheFormCarriesTheColumnMaxLengths(): void
    {
        $html = $this->renderEdit($this->borrowers->find($this->borrowerId), [], []);
        foreach (['home_telephone' => 20, 'loan_ref_no' => 30, 'id_number' => 50, 'first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'nationality' => 100, 'phone' => 50, 'email' => 150] as $field => $max) {
            $this->assertMatchesRegularExpression('/name="' . $field . '"[^>]*maxlength="' . $max . '"|maxlength="' . $max . '"[^>]*name="' . $field . '"/', $html, "$field maxlength $max");
        }
        $this->assertStringNotContainsString('max="' . date('Y-m-d') . '"', $html, 'no max=today on the start date');
    }

    // ------------------------------------------------------------------ contacts: only loaded ids are deleted

    public function testAContactAddedElsewhereMeanwhileIsNeverDeleted(): void
    {
        $ins = $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Next of Kin', ?)");
        $ins->execute([$this->borrowerId, 'Loaded A']);
        $a = (int) $this->db->lastInsertId();
        $ins->execute([$this->borrowerId, 'Loaded B']);
        $b = (int) $this->db->lastInsertId();
        // the user has the page open ... another tab / user adds a contact ...
        $ins->execute([$this->borrowerId, 'Added In Another Tab']);
        $other = (int) $this->db->lastInsertId();

        // ... and saves the page, having removed B (A kept, the other tab's contact never seen)
        $post = $this->post(['loaded_contact_ids' => $a . ',' . $b, 'contacts' => [
            ['id' => (string) $a, 'contact_type' => 'Next of Kin', 'full_name' => 'Loaded A'],
        ]]);
        $this->assertSame([], $this->validate($post));
        $changed = $this->save($post);

        $names = array_column($this->svc->contacts($this->borrowerId), 'full_name', 'id');
        $this->assertSame('Loaded A', $names[$a]);
        $this->assertArrayNotHasKey($b, $names, 'the contact the user removed is gone');
        $this->assertSame('Added In Another Tab', $names[$other], 'the contact the form never loaded survives');
        $this->assertContains('contacts: 1 removed', $changed);
    }

    public function testAPostWithoutLoadedContactIdsDeletesNoContact(): void
    {
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Guarantor', 'Keep Me')")->execute([$this->borrowerId]);
        $this->save($this->post(['contacts' => []]));
        $this->assertCount(1, $this->svc->contacts($this->borrowerId));
    }

    public function testLoadedContactIdsAreReadFromThePostAndSurviveAReRender(): void
    {
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Guarantor', 'Stored')")->execute([$this->borrowerId]);
        $storedId = (int) $this->db->lastInsertId();
        $this->assertSame((string) $storedId, $this->svc->loadedContactIdsForForm($this->borrowerId));

        // a contact appears elsewhere while the user's failed post is re-rendered: the page must still say it loaded only the first one
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name) VALUES (?, 'Other', 'Appeared')")->execute([$this->borrowerId]);
        $post = $this->post(['loaded_contact_ids' => ' ' . $storedId . ', 0, abc,' . $storedId, 'contacts' => []]);
        $this->assertSame((string) $storedId, $this->svc->loadedContactIdsForForm($this->borrowerId, $post));
        $this->assertSame([$storedId], BorrowerProfileService::loadedContactIds($post));
        $this->assertNull(BorrowerProfileService::loadedContactIds($this->post()));
    }

    // ------------------------------------------------------------------ legacy values only block when changed

    public function testLegacyValuesDoNotBlockAnUnrelatedEditButChangedOnesAreChecked(): void
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $this->db->prepare("INSERT INTO borrower_employment (borrower_id, employer_name, employer_email, employment_start_date, is_current) VALUES (?, 'Legacy Co', 'legacy-typo', ?, 1)")->execute([$this->borrowerId, $future]);
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name, email) VALUES (?, 'Next of Kin', 'Legacy Kin', 'kin-at-nowhere')")->execute([$this->borrowerId]);
        $loaded = (string) $this->db->lastInsertId();

        $same = $this->post($this->employmentPost(['employer_name' => 'Legacy Co', 'employer_email' => 'legacy-typo', 'employment_start_date' => $future]) + [
            'phone' => '0819998888', 'loaded_contact_ids' => $loaded,
            'contacts' => [['id' => $loaded, 'contact_type' => 'Next of Kin', 'full_name' => 'Legacy Kin', 'email' => 'kin-at-nowhere']],
        ]);
        $this->assertSame([], $this->validate($same), 'an unrelated edit is not blocked by legacy data');

        $edited = $this->post($this->employmentPost(['employer_name' => 'Legacy Co', 'employer_email' => 'still-bad', 'employment_start_date' => date('Y-m-d', strtotime('+31 days'))]) + [
            'loaded_contact_ids' => $loaded,
            'contacts' => [['id' => $loaded, 'contact_type' => 'Next of Kin', 'full_name' => 'Legacy Kin', 'email' => 'changed-bad'], ['id' => '', 'contact_type' => 'Other', 'full_name' => 'New', 'email' => 'new-bad']],
        ]);
        $errors = $this->validate($edited);
        foreach (['employer_email', 'employment_start_date', 'contacts.0.email', 'contacts.1.email'] as $key) {
            $this->assertArrayHasKey($key, $errors, "a changed value is still checked: $key");
        }
    }

    // ------------------------------------------------------------------ bank: audit masking and the primary flag

    public function testBankAuditEntriesNeverContainAFullAccountNumber(): void
    {
        $all = $this->save($this->post($this->bankPost(['account_number' => '62000111222'])));
        $all = array_merge($all, $this->save($this->post($this->bankPost(['account_number' => '62999999999']))));
        $all = array_merge($all, $this->save($this->post($this->bankPost(['bank_name' => '', 'account_name' => '', 'account_number' => '', 'account_type' => '', 'bank_branch_name' => '', 'bank_branch_code' => '']))));
        $text = implode(' | ', $all);

        $this->assertStringContainsString('bank.account_number: (none) -> ****1222', $text);
        $this->assertStringContainsString('bank.account_number: ****1222 -> ****9999', $text);
        $this->assertStringContainsString('bank.account_number: ****9999 -> (none)', $text);
        foreach (['62000111222', '62999999999', '6200011', '6299999'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function testRemovingThePrimaryBankRowPromotesTheNextOne(): void
    {
        $ins = $this->db->prepare("INSERT INTO borrower_bank_details (borrower_id, bank_name, account_number, is_primary) VALUES (?, ?, ?, ?)");
        $ins->execute([$this->borrowerId, 'Primary Bank', '111111', 1]);
        $ins->execute([$this->borrowerId, 'Second Bank', '222222', 0]);
        $secondId = (int) $this->db->lastInsertId();

        $this->save($this->post(['bank_name' => '', 'account_number' => '']));
        $this->assertSame(1, $this->rowCount('borrower_bank_details'));
        $left = $this->svc->primaryBank($this->borrowerId);
        $this->assertSame($secondId, (int) $left['id']);
        $this->assertSame('1', (string) $left['is_primary'], 'the remaining account is now flagged primary');
    }

    public function testEditingABankRowThatIsNotFlaggedPrimaryFlagsIt(): void
    {
        $this->db->prepare("INSERT INTO borrower_bank_details (borrower_id, bank_name, account_number, is_primary) VALUES (?, 'Lone Bank', '333333', 0)")->execute([$this->borrowerId]);
        $this->save($this->post($this->bankPost(['bank_name' => 'Lone Bank', 'account_number' => '333333', 'account_name' => '', 'account_type' => '', 'bank_branch_name' => '', 'bank_branch_code' => ''])));
        $this->assertSame('1', (string) $this->svc->primaryBank($this->borrowerId)['is_primary']);
    }

    // ------------------------------------------------------------------ errors must be visible

    public function testTheDateOfBirthErrorIsShownOnTheForm(): void
    {
        $post = $this->post(['date_of_birth' => date('Y-m-d', strtotime('-10 years'))]);
        $errors = $this->validate($post);
        $this->assertArrayHasKey('date_of_birth', $errors);
        $html = $this->renderEdit(array_merge($this->borrowers->find($this->borrowerId), $post), [], $errors);
        $this->assertMatchesRegularExpression('/name="date_of_birth" class="form-control is-invalid"/', $html);
        $this->assertStringContainsString('<div class="invalid-feedback d-block">' . htmlspecialchars($errors['date_of_birth'], ENT_QUOTES) . '</div>', $html);
    }

    public function testEveryErrorKeyTheValidatorCanReturnIsRenderedSomewhere(): void
    {
        $long = static fn (int $n): string => str_repeat('x', $n);
        // 1. every key the validation code can produce, triggered for real
        $post = $this->post(
            $this->employmentPost([
                'employer_name' => $long(151), 'employee_no' => $long(101), 'job_title' => $long(151), 'employer_phone' => $long(51),
                'employer_email' => 'not-an-email', 'employer_address' => $long(5001), 'gross_salary' => '-1', 'net_salary' => 'abc',
                'employment_type' => 'Astronaut', 'income_frequency' => 'Z', 'employment_payment_day' => '40', 'employment_start_date' => '2999-01-01',
            ])
            + $this->bankPost(['bank_name' => $long(151), 'account_name' => $long(151), 'account_number' => $long(101), 'account_type' => 'Piggy', 'bank_branch_name' => $long(151), 'bank_branch_code' => $long(51)])
            + [
                'date_of_birth' => date('Y-m-d', strtotime('-10 years')), 'passport_no' => $long(51), 'first_name' => $long(101), 'middle_name' => $long(101), 'last_name' => $long(101),
                'phone' => $long(51), 'home_telephone' => $long(21), 'email' => $long(151), 'nationality' => $long(101), 'loan_ref_no' => $long(31),
                'contacts' => [
                    ['id' => '999999999', 'contact_type' => 'Other', 'full_name' => 'Ghost'],
                    ['id' => '', 'contact_type' => 'Wizard', 'full_name' => '', 'relationship' => $long(101), 'phone' => $long(51), 'email' => 'bad', 'address' => $long(5001)],
                ],
            ]
        );
        $errors = $this->validate($post);
        // a duplicate ID number / loan ref belongs to another client
        $this->db->prepare("INSERT INTO borrowers (branch_id, borrower_no, first_name, last_name, id_number, loan_ref_no, phone, status) VALUES (?, 'BRW-FE-0002', 'Other', 'Client', 'FE-2000002', 'FE-REF-2', '0822222222', 'Approved')")->execute([$this->branchId]);
        $errors += $this->validate($this->post(['id_number' => 'FE-2000002', 'loan_ref_no' => 'FE-REF-2']));
        $errors += ['first_name' => 'First name is required.', 'last_name' => 'Last name is required.', 'branch_id' => 'Branch is required.', 'form' => BorrowerProfileService::STALE_MESSAGE, 'contacts' => 'The contacts could not be read.'];
        $errors += ['employer_name' => 'Employer Name is required when other employment details are filled in.', 'contacts.0.full_name' => 'Full Name is required.'];

        $expected = ['date_of_birth', 'passport_no', 'middle_name', 'phone', 'home_telephone', 'email', 'nationality', 'loan_ref_no', 'id_number',
            'employee_no', 'job_title', 'employer_phone', 'employer_email', 'employer_address', 'gross_salary', 'net_salary', 'employment_type',
            'income_frequency', 'employment_payment_day', 'employment_start_date', 'bank_name', 'account_name', 'account_number', 'account_type',
            'bank_branch_name', 'bank_branch_code', 'contacts.0.id', 'contacts.1.contact_type', 'contacts.1.relationship', 'contacts.1.phone', 'contacts.1.email', 'contacts.1.address'];
        foreach ($expected as $k) {
            $this->assertArrayHasKey($k, $errors, "the fixture must trigger $k");
        }

        $rows = $this->svc->contactsForForm($this->borrowerId, $post);
        $html = $this->renderEdit(array_merge($this->borrowers->find($this->borrowerId), $post), $rows, $errors);
        foreach ($errors as $key => $message) {
            $this->assertStringContainsString(htmlspecialchars((string) $message, ENT_QUOTES), $html, "the error for '$key' is never shown on the edit form");
        }
    }

    public function testNoUnrenderedErrorKeyIsPossibleOutsideTheKnownList(): void
    {
        // every field name the service validates must be a name the form renders, otherwise its error could never be shown
        $html = $this->renderEdit($this->borrowers->find($this->borrowerId), [], []);
        $names = array_merge(array_keys(BorrowerProfileService::BORROWER_TEXT), array_keys(BorrowerProfileService::BANK_TEXT), ['date_of_birth', 'account_type', 'employment_type', 'employment_start_date', 'employment_payment_day', 'gross_salary', 'net_salary', 'income_frequency', 'employer_name', 'employee_no', 'job_title', 'employer_phone', 'employer_email', 'employer_address']);
        foreach ($names as $n) {
            $this->assertStringContainsString('name="' . $n . '"', $html, "$n has no input on the form");
        }
    }

    // ------------------------------------------------------------------ contact address is multi-line text

    public function testContactAddressIsATextareaThatKeepsLineBreaks(): void
    {
        $post = $this->post(['contacts' => [['id' => '', 'contact_type' => 'Guarantor', 'full_name' => 'Gus', 'address' => "12 Main Street\r\nWindhoek\r\nNamibia"]]]);
        $this->assertSame([], $this->validate($post));
        $this->save($post);
        $stored = $this->row('SELECT address FROM borrower_contacts WHERE borrower_id = ?', [$this->borrowerId])['address'];
        $this->assertSame("12 Main Street\nWindhoek\nNamibia", $stored, 'line breaks survive (stored as LF)');

        $html = $this->renderEdit($this->borrowers->find($this->borrowerId), $this->svc->contactsForForm($this->borrowerId), []);
        $this->assertMatchesRegularExpression('#<textarea maxlength="5000" name="contacts\[0\]\[address\]" class="form-control" rows="1">12 Main Street\nWindhoek\nNamibia</textarea>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<input[^>]*contacts\[0\]\[address\]#', $html);
        $this->assertMatchesRegularExpression('#<textarea[^>]*contacts\[__INDEX__\]\[address\]#', $html, 'the add-contact template uses a textarea too');
    }

    public function testAnUntouchedContactAddressIsNotRewritten(): void
    {
        $this->db->prepare("INSERT INTO borrower_contacts (borrower_id, contact_type, full_name, address) VALUES (?, 'Guarantor', 'Gus', ?)")->execute([$this->borrowerId, "Line one\nLine two"]);
        $cid = (int) $this->db->lastInsertId();
        // the browser posts textarea newlines as CRLF and may add trailing space
        $changed = $this->save($this->post(['loaded_contact_ids' => (string) $cid, 'contacts' => [['id' => (string) $cid, 'contact_type' => 'Guarantor', 'full_name' => 'Gus', 'address' => "Line one\r\nLine two  "]]]));
        $this->assertSame([], array_filter($changed, static fn ($c) => str_starts_with($c, 'contacts')), 'not reported as a change');
        $this->assertSame("Line one\nLine two", $this->row('SELECT address FROM borrower_contacts WHERE id = ?', [$cid])['address']);

        // another field changed in the same row: the address text is still kept exactly as stored
        $changed = $this->save($this->post(['loaded_contact_ids' => (string) $cid, 'contacts' => [['id' => (string) $cid, 'contact_type' => 'Guarantor', 'full_name' => 'Gus', 'phone' => '0815555555', 'address' => "Line one\r\nLine two"]]]));
        $this->assertContains('contacts: 1 updated', $changed);
        $this->assertSame("Line one\nLine two", $this->row('SELECT address FROM borrower_contacts WHERE id = ?', [$cid])['address']);

        // a real address change is saved
        $this->save($this->post(['loaded_contact_ids' => (string) $cid, 'contacts' => [['id' => (string) $cid, 'contact_type' => 'Guarantor', 'full_name' => 'Gus', 'phone' => '0815555555', 'address' => "Line one\r\nLine THREE"]]]));
        $this->assertSame("Line one\nLine THREE", $this->row('SELECT address FROM borrower_contacts WHERE id = ?', [$cid])['address']);
    }

    public function testAnUntouchedEmployerAddressIsNotReportedAsAChange(): void
    {
        $this->save($this->post($this->employmentPost(['employer_address' => "1 Mine Road\nKarasburg"])));
        $changed = $this->save($this->post($this->employmentPost(['employer_address' => "1 Mine Road\r\nKarasburg"])));
        $this->assertSame([], array_filter($changed, static fn ($c) => str_starts_with($c, 'employment')));
        $this->assertSame("1 Mine Road\nKarasburg", $this->svc->currentEmployment($this->borrowerId)['employer_address']);
    }

    // ------------------------------------------------------------------ stale form protection

    public function testTheVersionTokenChangesWhenTheClientEmploymentOrBankChanges(): void
    {
        $t0 = $this->svc->versionToken($this->borrowerId);
        $this->assertSame($t0, $this->svc->versionToken($this->borrowerId), 'stable while nothing changes');

        $this->db->prepare("UPDATE borrowers SET phone = '0813333333', updated_at = '2030-01-01 10:00:00' WHERE id = ?")->execute([$this->borrowerId]);
        $t1 = $this->svc->versionToken($this->borrowerId);
        $this->assertNotSame($t0, $t1, 'borrower row');

        $this->db->prepare("INSERT INTO borrower_employment (borrower_id, employer_name, is_current) VALUES (?, 'X', 1)")->execute([$this->borrowerId]);
        $t2 = $this->svc->versionToken($this->borrowerId);
        $this->assertNotSame($t1, $t2, 'employment row added');
        $this->db->prepare("UPDATE borrower_employment SET gross_salary = 777 WHERE borrower_id = ?")->execute([$this->borrowerId]);
        $t3 = $this->svc->versionToken($this->borrowerId);
        $this->assertNotSame($t2, $t3, 'employment row edited elsewhere');

        $this->db->prepare("INSERT INTO borrower_bank_details (borrower_id, bank_name, account_number, is_primary) VALUES (?, 'B', '1', 1)")->execute([$this->borrowerId]);
        $t4 = $this->svc->versionToken($this->borrowerId);
        $this->assertNotSame($t3, $t4, 'bank row added');
        $this->assertMatchesRegularExpression('/^v1\.[0-9a-f]{40}$/', $t4);
        $this->assertStringNotContainsString('62000', $t4);
    }

    public function testAStaleFormIsRefusedWithAClearMessage(): void
    {
        $token = $this->svc->versionToken($this->borrowerId);
        $this->assertNull($this->svc->staleFormError($this->post(['loaded_version' => $token]), $this->borrowerId));

        // someone else saves first
        $this->save($this->post($this->employmentPost()));
        $msg = $this->svc->staleFormError($this->post(['loaded_version' => $token]), $this->borrowerId);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('changed by someone else', $msg);
        $this->assertStringContainsString('Reload', $msg);

        // a full-edit form with no token at all (a page opened before the protection existed) is refused too; a partial one is not checked
        $this->assertNotNull($this->svc->staleFormError($this->post(), $this->borrowerId));
        $this->assertNull($this->svc->staleFormError($this->post(['full_edit' => '0']), $this->borrowerId));
    }

    public function testSavingWithAStaleTokenWritesNothing(): void
    {
        $token = $this->svc->versionToken($this->borrowerId);
        $this->save($this->post($this->bankPost()));          // another user adds a bank account
        $this->expectException(\App\Services\StaleBorrowerFormException::class);
        try {
            $this->save($this->post($this->employmentPost(['employer_name' => 'Late Co']) + ['phone' => '0814444444', 'loaded_version' => $token]));
        } finally {
            $this->assertNull($this->svc->currentEmployment($this->borrowerId), 'nothing of the stale save was written');
            $this->assertSame('0811111111', $this->borrowers->find($this->borrowerId)['phone']);
        }
    }

    public function testSavingWithTheCurrentTokenWorksAndTheNextOneIsFresh(): void
    {
        $token = $this->svc->versionToken($this->borrowerId);
        $this->save($this->post($this->employmentPost() + ['loaded_version' => $token]));
        $this->assertSame('Acme Mining', $this->svc->currentEmployment($this->borrowerId)['employer_name']);
        $token2 = $this->svc->versionToken($this->borrowerId);
        $this->assertNotSame($token, $token2);
        $this->save($this->post($this->employmentPost(['job_title' => 'Boss']) + ['loaded_version' => $token2]));
        $this->assertSame('Boss', $this->svc->currentEmployment($this->borrowerId)['job_title']);
    }

    public function testTheEditFormCarriesTheVersionTokenAndShowsTheStaleMessage(): void
    {
        $token = $this->svc->versionToken($this->borrowerId);
        $html = $this->renderEdit($this->borrowers->find($this->borrowerId), [], [], '', $token);
        $this->assertStringContainsString('name="loaded_version" value="' . $token . '"', $html);
        $html = $this->renderEdit($this->borrowers->find($this->borrowerId), [], ['form' => BorrowerProfileService::STALE_MESSAGE], '', $token);
        $this->assertStringContainsString('class="alert alert-danger"', $html);
        $this->assertStringContainsString('changed by someone else', $html);
    }

    // ------------------------------------------------------------------ bank behind borrowers.edit_bank

    public function testWithoutTheBankPermissionTheBankSectionIsIgnoredOnSave(): void
    {
        $this->save($this->post($this->bankPost()));
        $before = $this->svc->primaryBank($this->borrowerId);

        $post = $this->post($this->bankPost(['account_number' => '99999999999', 'bank_name' => 'Evil Bank']));
        $changed = $this->svc->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $post, false);
        $this->assertSame([], array_filter($changed, static fn ($c) => str_starts_with($c, 'bank')));
        $after = $this->svc->primaryBank($this->borrowerId);
        $this->assertSame($before, $after, 'the stored bank row is untouched');

        // a post without any bank fields (the read-only form) must not delete the bank row either
        $bare = $this->post();
        $this->svc->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $bare, false);
        $this->assertSame($before, $this->svc->primaryBank($this->borrowerId), 'a missing bank section never removes the account');

        // with the permission the same bare post WOULD clear it (proves the flag is what protects it)
        $this->svc->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $bare, true);
        $this->assertNull($this->svc->primaryBank($this->borrowerId));
    }

    public function testWithoutTheBankPermissionBankValuesAreNotValidatedOrAdded(): void
    {
        $post = $this->post($this->bankPost(['account_type' => 'Piggy']));
        $this->assertArrayHasKey('account_type', $this->svc->validate($post, $this->borrowers->find($this->borrowerId), true));
        $this->assertArrayNotHasKey('account_type', $this->svc->validate($post, $this->borrowers->find($this->borrowerId), false));

        $this->svc->save($this->borrowerId, $this->borrowers->find($this->borrowerId), $this->post($this->bankPost()), false);
        $this->assertSame(0, $this->rowCount('borrower_bank_details'), 'no bank account can be added without the permission');
    }

    public function testTheBankSectionIsReadOnlyWithoutThePermission(): void
    {
        $this->save($this->post($this->bankPost()));
        $values = array_merge($this->borrowers->find($this->borrowerId), $this->svc->formValues($this->borrowerId));

        $html = $this->renderEdit($values, [], [], '', '', false);
        $this->assertStringContainsString('62000111222', $html, 'still visible (the client page shows it too)');
        foreach (['bank_name', 'account_name', 'account_number', 'account_type', 'bank_branch_name', 'bank_branch_code'] as $f) {
            $this->assertStringNotContainsString('name="' . $f . '"', $html, "$f must not be posted");
        }
        $this->assertStringContainsString('Edit Client Bank Details', $html);

        $html = $this->renderEdit($values, [], [], '', '', true);
        $this->assertStringContainsString('name="account_number"', $html);
        $this->assertStringNotContainsString('Edit Client Bank Details', $html);
    }

    public function testTheBankPermissionMigrationGrantsItToEveryRoleThatCanEditClients(): void
    {
        $perm = $this->row("SELECT id FROM permissions WHERE permission_key = 'borrowers.edit_bank'");
        $this->assertNotNull($perm, 'run database/borrowers_edit_bank_permission.sql first');
        $missing = $this->row("SELECT COUNT(*) c FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id AND p.permission_key = 'borrowers.edit'
            WHERE NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.permission_id = ?)", [$perm['id']]);
        $this->assertSame(0, (int) $missing['c'], 'every role with borrowers.edit also holds borrowers.edit_bank');

        // re-running the migration changes nothing
        $count = fn () => (int) $this->db->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id = " . (int) $perm['id'])->fetchColumn();
        $permCount = fn () => (int) $this->db->query("SELECT COUNT(*) FROM permissions WHERE permission_key = 'borrowers.edit_bank'")->fetchColumn();
        [$a, $b] = [$count(), $permCount()];
        $this->db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/borrowers_edit_bank_permission.sql'));
        $this->assertSame([$a, $b], [$count(), $permCount()]);
    }

    // ------------------------------------------------------------------ helper

    /** Renders the edit view's content file the way the layout would, with the same variables the controller supplies. */
    private function renderEdit(array $values, array $contacts, array $errors, string $loadedContactIds = '', string $loadedVersion = '', bool $canEditBank = true): string
    {
        $borrower = $values;
        $branches = [['id' => $this->branchId, 'branch_name' => 'Test Branch']];
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/app/Views/borrowers/edit.php.content';
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }
}
