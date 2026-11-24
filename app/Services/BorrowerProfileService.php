<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Borrower;
use App\Support\DateOfBirth;

/**
 * Full edit of a borrower ("client"): the borrowers row itself plus the CURRENT employment, the PRIMARY bank account and the
 * contacts / next of kin list, validated together and saved in one transaction (all or nothing).
 *
 * The employment, bank and contact form fields carry the same names as the Add Borrower form (borrowers/create) so both screens
 * stay in step; BorrowerController::store()/ApplicationController::convert() remain the creation path.
 *
 * Rules that protect existing data:
 *  - the three sub-sections are only touched when the posted form says it carries them (full_edit=1), so a stale or partial
 *    post can never wipe contacts, employment or bank details;
 *  - employment history is never deleted: a cleared employment section only retires EVERY current row (is_current = 0), and a normal
 *    save collapses older duplicate current rows to is_current = 0 (the newest stays current). Re-entering employment after a clear
 *    brings the retired row back only when it is the SAME employer; a different employer gets a new row and the old one stays as history;
 *  - stale-form protection: the form carries a hidden loaded-version token (borrowers.updated_at plus the current employment and primary
 *    bank rows). A save whose token no longer matches is refused ("changed by someone else, reload"), re-checked under a row lock;
 *  - the bank section is only read / validated / saved when the caller says the user may edit bank details (permission borrowers.edit_bank);
 *  - contacts are diffed by id: posted ids are updated, rows without an id are inserted, and only ids the form LOADED (hidden field
 *    loaded_contact_ids) and that are no longer posted are removed -- a contact added elsewhere meanwhile is never deleted;
 *  - format / date rules for employment and contact values only apply to values that were changed (legacy data never blocks an edit).
 *
 * PHP 8.0 compatible.
 */
class BorrowerProfileService
{
    public const CONTACT_TYPES = ['Next of Kin', 'Guarantor', 'Emergency', 'Employer', 'Other'];
    public const EMPLOYMENT_TYPES = ['Permanent', 'Contract', 'Self-Employed', 'Government', 'Casual', 'Other'];
    public const INCOME_FREQUENCIES = ['M' => 'Monthly', 'W' => 'Weekly', 'F' => 'Fortnightly', 'Q' => 'Quarterly', 'A' => 'Annually'];
    public const ACCOUNT_TYPES = ['Savings', 'Cheque', 'Current'];

    private const MAX_AMOUNT = 9999999999999.99;

    public const STALE_MESSAGE = 'This client was changed by someone else since you opened this page, so nothing was saved. Reload the page to see the latest details, then make your change again.';

    /** form field name => [column, max length] */
    private const EMPLOYMENT_TEXT = [
        'employer_name' => ['employer_name', 150, 'Employer Name'],
        'employee_no' => ['employee_no', 100, 'Employee No'],
        'job_title' => ['job_title', 150, 'Job Title'],
        'employer_phone' => ['employer_phone', 50, 'Employer Phone'],
        'employer_email' => ['employer_email', 150, 'Employer Email'],
        'employer_address' => ['employer_address', 5000, 'Employer Address'],
    ];

    public const BANK_TEXT = [
        'bank_name' => ['bank_name', 150, 'Bank Name'],
        'account_name' => ['account_name', 150, 'Account Name'],
        'account_number' => ['account_number', 100, 'Account Number'],
        'bank_branch_name' => ['branch_name', 150, 'Bank Branch Name'],
        'bank_branch_code' => ['branch_code', 50, 'Bank Branch Code'],
    ];

    public const CONTACT_TEXT = [
        'full_name' => 150,
        'relationship' => 100,
        'phone' => 50,
        'email' => 150,
        'address' => 5000,
    ];

    /** borrowers columns edited on this form => [max length, label] (the varchar sizes of the table; longer values would be cut or refused by MySQL) */
    public const BORROWER_TEXT = [
        'first_name' => [100, 'First Name'],
        'middle_name' => [100, 'Middle Name'],
        'last_name' => [100, 'Last Name'],
        'id_number' => [50, 'ID Number'],
        'passport_no' => [50, 'Passport No'],
        'loan_ref_no' => [30, 'Loan Ref No'],
        'phone' => [50, 'Phone'],
        'home_telephone' => [20, 'Home Telephone'],
        'email' => [150, 'Email'],
        'nationality' => [100, 'Nationality'],
    ];

    private \PDO $db;
    private Borrower $borrowers;

    public function __construct(?\PDO $db = null, ?Borrower $borrowers = null)
    {
        $this->db = $db ?? Database::connection();
        $this->borrowers = $borrowers ?? new Borrower();
    }

    /** True when the posted form carries the employment / bank / contacts sections. */
    public static function hasSections(array $post): bool
    {
        return ($post['full_edit'] ?? '') === '1';
    }

    // ------------------------------------------------------------------ reading

    /** The current employment row, using the same definition as Borrower::employmentFor(). */
    public function currentEmployment(int $borrowerId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM borrower_employment WHERE borrower_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$borrowerId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The primary bank row, using the same definition as Borrower::bankDetails(). */
    public function primaryBank(int $borrowerId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM borrower_bank_details WHERE borrower_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $st->execute([$borrowerId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function contacts(int $borrowerId): array
    {
        $st = $this->db->prepare('SELECT * FROM borrower_contacts WHERE borrower_id = ? ORDER BY id ASC');
        $st->execute([$borrowerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------ stale-form protection

    /**
     * An opaque token for "the version of this client the edit form was built from": borrowers.updated_at plus the current
     * employment row and the primary bank row (their ids and contents). It is a hash, so it reveals nothing.
     * (Contacts have their own protection: only the ids the form loaded can ever be deleted.)
     */
    public function versionToken(int $borrowerId): string
    {
        $st = $this->db->prepare('SELECT updated_at FROM borrowers WHERE id = ?');
        $st->execute([$borrowerId]);
        $updatedAt = $st->fetchColumn();
        $e = $this->currentEmployment($borrowerId);
        $b = $this->primaryBank($borrowerId);
        return 'v1.' . substr(sha1((string) json_encode([
            $updatedAt === false ? null : $updatedAt,
            $e['id'] ?? null, $e,
            $b['id'] ?? null, $b,
        ])), 0, 40);
    }

    /**
     * The "changed by someone else, reload" message when the posted form's version no longer matches the stored client, else null.
     * A full-edit form without a token (a page opened before this protection existed) is refused too: reloading gives it one.
     * A post that is not a full-edit form and carries no token is not checked (it cannot touch employment, bank or contacts).
     */
    public function staleFormError(array $post, int $borrowerId): ?string
    {
        if (!array_key_exists('loaded_version', $post) && !self::hasSections($post)) {
            return null;
        }
        $posted = is_string($post['loaded_version'] ?? null) ? $post['loaded_version'] : '';
        return $posted !== '' && hash_equals($this->versionToken($borrowerId), $posted) ? null : self::STALE_MESSAGE;
    }

    /**
     * Employment and bank details under the FORM field names (the names borrowers/create uses), ready to merge into the form values.
     *
     * @return array<string,string>
     */
    public function formValues(int $borrowerId): array
    {
        $out = [];
        $e = $this->currentEmployment($borrowerId);
        if ($e) {
            foreach (self::EMPLOYMENT_TEXT as $field => [$col]) {
                $out[$field] = (string) ($e[$col] ?? '');
            }
            $out['employment_type'] = (string) ($e['employment_type'] ?? '');
            $out['employment_start_date'] = (string) ($e['employment_start_date'] ?? '');
            $out['employment_payment_day'] = (string) ($e['payment_day'] ?? '');
            $out['gross_salary'] = $e['gross_salary'] === null ? '' : (string) $e['gross_salary'];
            $out['net_salary'] = $e['net_salary'] === null ? '' : (string) $e['net_salary'];
            $out['income_frequency'] = (string) ($e['income_frequency'] ?? '');
        }
        $b = $this->primaryBank($borrowerId);
        if ($b) {
            foreach (self::BANK_TEXT as $field => [$col]) {
                $out[$field] = (string) ($b[$col] ?? '');
            }
            $out['account_type'] = (string) ($b['account_type'] ?? '');
        }
        return $out;
    }

    /**
     * Contact rows as the form shows them: id (or '' for a new row) plus the editable fields. From a post (a re-render after an error)
     * the rows are re-indexed and keep exactly what was typed; otherwise they come from the database.
     *
     * @return array<int,array<string,string>>
     */
    public function contactsForForm(int $borrowerId, ?array $post = null): array
    {
        $rows = [];
        if ($post !== null && self::hasSections($post)) {
            $posted = is_array($post['contacts'] ?? null) ? $post['contacts'] : [];
            foreach ($posted as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = $this->contactRow($row);
            }
            return $rows;
        }
        foreach ($this->contacts($borrowerId) as $row) {
            $rows[] = $this->contactRow($row);
        }
        return $rows;
    }

    /**
     * The contact ids the form shows, as the comma-separated hidden field loaded_contact_ids. After a failed save the ids the page
     * ORIGINALLY loaded are kept (from the post), never re-read from the database: otherwise a contact added in another tab meanwhile
     * would look "loaded" and be deleted by the next save.
     */
    public function loadedContactIdsForForm(int $borrowerId, ?array $post = null): string
    {
        if ($post !== null && self::hasSections($post)) {
            return implode(',', (array) self::loadedContactIds($post));
        }
        return implode(',', array_map(static fn ($r) => (int) $r['id'], $this->contacts($borrowerId)));
    }

    /** @return int[]|null the ids the posted form says it loaded; null when the field is missing (then no contact is ever deleted) */
    public static function loadedContactIds(array $post): ?array
    {
        if (!array_key_exists('loaded_contact_ids', $post) || !is_string($post['loaded_contact_ids'])) {
            return null;
        }
        $ids = [];
        foreach (explode(',', $post['loaded_contact_ids']) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p) && (int) $p > 0) {
                $ids[(int) $p] = (int) $p;
            }
        }
        return array_values($ids);
    }

    /** @return array<string,string> */
    private function contactRow(array $row): array
    {
        $out = ['id' => (string) ($row['id'] ?? ''), 'contact_type' => ((string) ($row['contact_type'] ?? '')) ?: 'Next of Kin'];
        foreach (array_keys(self::CONTACT_TEXT) as $f) {
            $out[$f] = (string) ($row[$f] ?? '');
        }
        return $out;
    }

    // ---------------------------------------------------------------- validation

    /**
     * Everything the edit form needs checked beyond first name / last name / branch / email (BorrowerController::validate()):
     * the date-of-birth rule (only when the DOB was changed), ID number and loan ref uniqueness, passport, employment, bank and contacts.
     *
     * Error keys: the plain field name for single fields; 'contacts.{rowIndex}.{field}' for a contact field; 'contacts' for the list.
     *
     * @param array $borrower the borrower as currently stored
     * @param bool $canEditBank false when the user lacks borrowers.edit_bank: the bank section is not validated (it is ignored on save)
     * @return array<string,string>
     */
    public function validate(array $post, array $borrower, bool $canEditBank = true): array
    {
        $errors = [];
        $id = (int) $borrower['id'];

        $dob = trim((string) ($post['date_of_birth'] ?? ''));
        if ($dob !== '' && $dob !== (string) ($borrower['date_of_birth'] ?? '') && ($dobMsg = DateOfBirth::check($dob)) !== null) {
            $errors['date_of_birth'] = $dobMsg;
        }

        // Column sizes first: a too-long value is reported per field instead of being cut off (or refused) by the database.
        foreach (self::BORROWER_TEXT as $field => [$max, $label]) {
            if (array_key_exists($field, $post) && mb_strlen(trim((string) $post[$field])) > $max) {
                $errors[$field] = $label . ' can be at most ' . $max . ' characters.';
            }
        }

        $idNumber = trim((string) ($post['id_number'] ?? ''));
        if (!isset($errors['id_number']) && $idNumber !== '' && $this->borrowers->idNumberExists($idNumber, $id)) {
            $errors['id_number'] = 'Another client already uses this ID number.';
        }
        $loanRef = trim((string) ($post['loan_ref_no'] ?? ''));
        if (!isset($errors['loan_ref_no']) && $loanRef !== '' && $this->borrowers->loanRefNoExists($loanRef, $id)) {
            $errors['loan_ref_no'] = 'Another client already uses this loan ref no.';
        }

        if (!self::hasSections($post)) {
            return $errors;
        }

        return array_merge(
            $errors,
            $this->validateEmployment($post, $this->currentEmployment($id)),
            $canEditBank ? $this->validateBank($post, $this->primaryBank($id)) : [],
            $this->validateContacts($post, $id)
        );
    }

    /** @return array<string,string> */
    private function validateEmployment(array $post, ?array $current): array
    {
        $errors = [];
        $t = static fn (string $k): string => trim((string) ($post[$k] ?? ''));

        foreach (self::EMPLOYMENT_TEXT as $field => [, $max, $label]) {
            if (mb_strlen($t($field)) > $max) {
                $errors[$field] = $label . ' is too long (at most ' . $max . ' characters).';
            }
        }
        // Format / date rules only apply to a value that was CHANGED: legacy data (an old typo, a start date in the future) must not
        // block an unrelated edit of the same client.
        $email = $t('employer_email');
        if ($email !== '' && $email !== trim((string) ($current['employer_email'] ?? '')) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['employer_email'] = 'Enter a valid employer email address.';
        }

        foreach (['gross_salary' => 'Gross Salary', 'net_salary' => 'Net Salary'] as $field => $label) {
            $v = $t($field);
            if ($v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > self::MAX_AMOUNT)) {
                $errors[$field] = $label . ' must be a number of zero or more.';
            }
        }

        $type = $t('employment_type');
        if ($type !== '' && !in_array($type, self::EMPLOYMENT_TYPES, true) && $type !== (string) ($current['employment_type'] ?? '')) {
            $errors['employment_type'] = 'Choose one of the listed employment types.';
        }

        $freq = $t('income_frequency');
        if ($freq !== '' && !isset(self::INCOME_FREQUENCIES[$freq]) && $freq !== (string) ($current['income_frequency'] ?? '')) {
            $errors['income_frequency'] = 'Choose one of the listed income frequencies.';
        }

        $day = $t('employment_payment_day');
        if ($day !== '' && (!ctype_digit($day) || (int) $day < 1 || (int) $day > 31)) {
            $errors['employment_payment_day'] = 'Salary Payment Day must be a whole number from 1 to 31.';
        }

        $start = $t('employment_start_date');
        if ($start !== '' && $start !== (string) ($current['employment_start_date'] ?? '')) {
            $d = \DateTime::createFromFormat('Y-m-d', $start);
            if (!$d || $d->format('Y-m-d') !== $start) {
                $errors['employment_start_date'] = 'Employment Start Date is not a valid date.';
            } elseif ($start > date('Y-m-d')) {
                $errors['employment_start_date'] = 'Employment Start Date cannot be in the future.';
            }
        }

        // The employer name is what identifies an employment record: other details without it cannot be saved.
        if ($t('employer_name') === '' && $this->employmentAny($post)) {
            $errors['employer_name'] = 'Employer Name is required when other employment details are filled in.';
        }

        return $errors;
    }

    /** @return array<string,string> */
    private function validateBank(array $post, ?array $current): array
    {
        $errors = [];
        $t = static fn (string $k): string => trim((string) ($post[$k] ?? ''));

        foreach (self::BANK_TEXT as $field => [, $max, $label]) {
            if (mb_strlen($t($field)) > $max) {
                $errors[$field] = $label . ' is too long (at most ' . $max . ' characters).';
            }
        }
        $type = $t('account_type');
        if ($type !== '' && !in_array($type, self::ACCOUNT_TYPES, true) && $type !== (string) ($current['account_type'] ?? '')) {
            $errors['account_type'] = 'Choose one of the listed account types.';
        }
        if ($this->bankAny($post) && $t('bank_name') === '' && $t('account_number') === '') {
            $errors['bank_name'] = 'Enter the Bank Name or the Account Number (or clear the whole bank section).';
        }
        return $errors;
    }

    /** @return array<string,string> */
    private function validateContacts(array $post, int $borrowerId): array
    {
        $errors = [];
        $rows = $post['contacts'] ?? [];
        if (!is_array($rows)) {
            return ['contacts' => 'The contacts could not be read. Reload the page and try again.'];
        }

        $stored = [];
        foreach ($this->contacts($borrowerId) as $r) {
            $stored[(int) $r['id']] = $r;
        }
        $owned = array_keys($stored);
        $seen = [];
        $n = 0;
        foreach ($rows as $row) {
            $i = $n++;
            if (!is_array($row)) {
                $errors['contacts'] = 'The contacts could not be read. Reload the page and try again.';
                continue;
            }
            $c = $this->contactRow($row);
            $hasData = $this->contactHasData($c);
            $was = null; // the stored row this posted row edits, null for a new row

            if ($c['id'] !== '') {
                $cid = ctype_digit($c['id']) ? (int) $c['id'] : 0;
                if ($cid === 0 || !in_array($cid, $owned, true)) {
                    $errors['contacts.' . $i . '.id'] = 'This contact no longer exists. Reload the page and try again.';
                    continue;
                }
                if (isset($seen[$cid])) {
                    $errors['contacts.' . $i . '.id'] = 'This contact was posted twice. Reload the page and try again.';
                    continue;
                }
                $seen[$cid] = true;
                $was = $stored[$cid];
            } elseif (!$hasData) {
                continue; // an untouched blank row the user never filled in
            }

            if (trim($c['full_name']) === '') {
                $errors['contacts.' . $i . '.full_name'] = $c['id'] !== ''
                    ? 'Full Name is required. Use the remove button to delete this contact.'
                    : 'Full Name is required for a contact.';
            }
            if (!in_array($c['contact_type'], self::CONTACT_TYPES, true) && ($was === null || $c['contact_type'] !== (string) ($was['contact_type'] ?? ''))) {
                $errors['contacts.' . $i . '.contact_type'] = 'Choose one of the listed contact types.';
            }
            foreach (self::CONTACT_TEXT as $f => $max) {
                if (mb_strlen(trim($c[$f])) > $max) {
                    $errors['contacts.' . $i . '.' . $f] = 'Too long (at most ' . $max . ' characters).';
                }
            }
            // an unchanged stored email is left alone even if it would not pass today's format check
            $emailChanged = $was === null || trim($c['email']) !== trim((string) ($was['email'] ?? ''));
            if ($emailChanged && trim($c['email']) !== '' && !filter_var(trim($c['email']), FILTER_VALIDATE_EMAIL)) {
                $errors['contacts.' . $i . '.email'] = 'Enter a valid email address.';
            }
        }
        return $errors;
    }

    // -------------------------------------------------------------------- saving

    /**
     * Saves the borrower row and (when the form carries them) employment, bank and contacts in ONE transaction.
     * Call validate() first; any exception rolls everything back.
     *
     * @param array $borrower the borrower as currently stored (for change detection and the status fallback)
     * @param bool $canEditBank false when the user lacks borrowers.edit_bank: the bank section is left exactly as stored
     * @return string[] names of the fields that changed (never values), e.g. 'phone', 'employment.gross_salary', 'contacts: 1 added'
     * @throws StaleBorrowerFormException when the post carries a loaded_version token that no longer matches (checked under a row lock)
     */
    public function save(int $borrowerId, array $borrower, array $post, bool $canEditBank = true): array
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            if (array_key_exists('loaded_version', $post)) {
                $this->db->prepare('SELECT id FROM borrowers WHERE id = ? FOR UPDATE')->execute([$borrowerId]);
                if ($this->staleFormError($post, $borrowerId) !== null) {
                    throw new StaleBorrowerFormException(self::STALE_MESSAGE);
                }
            }
            $changed = $this->saveBorrower($borrowerId, $borrower, $post);
            if (self::hasSections($post)) {
                $changed = array_merge(
                    $changed,
                    $this->saveEmployment($borrowerId, $post),
                    $canEditBank ? $this->saveBank($borrowerId, $post) : [],
                    $this->saveContacts($borrowerId, $post)
                );
            }
            if ($own) {
                $this->db->commit();
            }
            return $changed;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return string[] */
    private function saveBorrower(int $id, array $borrower, array $post): array
    {
        $t = static fn (string $k): ?string => (($v = trim((string) ($post[$k] ?? ''))) !== '') ? $v : null;

        $new = [
            'branch_id' => (int) $post['branch_id'],
            'loan_ref_no' => $t('loan_ref_no'),
            'first_name' => trim((string) $post['first_name']),
            'middle_name' => $t('middle_name'),
            'last_name' => trim((string) $post['last_name']),
            'gender' => ($post['gender'] ?? '') ?: null,
            'date_of_birth' => ($post['date_of_birth'] ?? '') ?: null,
            'id_number' => $t('id_number'),
            'phone' => $t('phone'),
            'home_telephone' => $t('home_telephone'),
            'email' => $t('email'),
            'marital_status' => ($post['marital_status'] ?? '') ?: null,
            'nationality' => $t('nationality') ?? 'Namibian',
            'status' => ($post['status'] ?? '') ?: $borrower['status'],
            'title' => $t('title'),
            'ownership_type' => ($post['ownership_type'] ?? '') ?: '00',
            'residential_ownership' => ($post['residential_ownership'] ?? '') ?: null,
        ];
        foreach (['residential_line1', 'residential_line2', 'residential_line3', 'residential_line4', 'residential_postal_code', 'postal_line1', 'postal_line2', 'postal_line3', 'postal_line4', 'postal_postal_code'] as $k) {
            $new[$k] = $t($k);
        }
        // Only touched when the form actually carried the field (an older cached form must not blank it).
        if (array_key_exists('passport_no', $post)) {
            $new['passport_no'] = $t('passport_no');
        }

        $changed = [];
        foreach ($new as $col => $val) {
            if ((string) ($borrower[$col] ?? '') !== (string) ($val ?? '')) {
                $changed[] = $col;
            }
        }

        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($new)));
        $st = $this->db->prepare("UPDATE borrowers SET $set WHERE id = :__id");
        foreach ($new as $col => $val) {
            $st->bindValue(':' . $col, $val);
        }
        $st->bindValue(':__id', $id);
        $st->execute();

        return $changed;
    }

    private function employmentAny(array $post): bool
    {
        foreach (['employer_name', 'employee_no', 'job_title', 'employment_type', 'employment_start_date', 'employment_payment_day', 'gross_salary', 'net_salary', 'income_frequency', 'employer_phone', 'employer_email', 'employer_address'] as $k) {
            if (trim((string) ($post[$k] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function bankAny(array $post): bool
    {
        foreach (['bank_name', 'account_name', 'account_number', 'account_type', 'bank_branch_name', 'bank_branch_code'] as $k) {
            if (trim((string) ($post[$k] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private function saveEmployment(int $borrowerId, array $post): array
    {
        $current = $this->currentEmployment($borrowerId);

        if (!$this->employmentAny($post)) {
            // history is kept: rows are only retired, never deleted -- ALL current rows (a client can have several after old data or
            // a race), not just the newest, otherwise the "ended" employment would still be read as current elsewhere
            $end = $this->db->prepare('UPDATE borrower_employment SET is_current = 0 WHERE borrower_id = ? AND is_current = 1');
            $end->execute([$borrowerId]);
            return $end->rowCount() > 0 ? ['employment (ended)'] : [];
        }

        $t = static fn (string $k): ?string => (($v = trim((string) ($post[$k] ?? ''))) !== '') ? $v : null;
        $gross = $t('gross_salary');
        $net = $t('net_salary');
        $new = [
            'employer_name' => $t('employer_name'),
            'employee_no' => $t('employee_no'),
            'job_title' => $t('job_title'),
            'employment_type' => $t('employment_type'),
            'employment_start_date' => $t('employment_start_date'),
            'gross_salary' => $gross !== null ? round((float) $gross, 2) : 0,
            'net_salary' => $net !== null ? round((float) $net, 2) : 0,
            'income_frequency' => $t('income_frequency'),
            'payment_day' => $t('employment_payment_day') !== null ? (int) $t('employment_payment_day') : null,
            'employer_phone' => $t('employer_phone'),
            'employer_email' => $t('employer_email'),
            'employer_address' => self::lfText($t('employer_address')),
        ];

        if ($current === null) {
            // Re-entered after being cleared. The newest retired row comes back ONLY when it is the same employer (a correction of the
            // same job); a different employer is a new job: a new row, and the retired one stays as history.
            $st = $this->db->prepare('SELECT id, employer_name FROM borrower_employment WHERE borrower_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$borrowerId]);
            $retired = $st->fetch(\PDO::FETCH_ASSOC);
            if ($retired !== false && self::sameName($retired['employer_name'] ?? null, $new['employer_name'])) {
                $new['is_current'] = 1;
                $this->updateRow('borrower_employment', $new, (int) $retired['id']);
                return ['employment (re-activated)'];
            }
            $new['borrower_id'] = $borrowerId;
            $new['is_current'] = 1;
            $this->insertRow('borrower_employment', $new);
            return ['employment (added)'];
        }

        // an address that only differs in whitespace / line endings (a browser posts textarea newlines as CRLF) is not a change
        if (self::sameText($current['employer_address'] ?? null, $new['employer_address'])) {
            $new['employer_address'] = $current['employer_address'];
        }
        $changed = [];
        foreach ($new as $col => $val) {
            $before = $current[$col] ?? null;
            $same = in_array($col, ['gross_salary', 'net_salary'], true)
                ? abs((float) $before - (float) $val) < 0.005
                : (string) ($before ?? '') === (string) ($val ?? '');
            if (!$same) {
                $changed[] = 'employment.' . $col;
            }
        }
        if ($changed) {
            $this->updateRow('borrower_employment', $new, (int) $current['id']);
        }
        // The newest current row is the one shown and edited; any older row still flagged current is collapsed into history.
        $dups = $this->db->prepare('UPDATE borrower_employment SET is_current = 0 WHERE borrower_id = ? AND is_current = 1 AND id <> ?');
        $dups->execute([$borrowerId, (int) $current['id']]);
        if ($dups->rowCount() > 0) {
            $changed[] = 'employment (older duplicate current rows retired: ' . $dups->rowCount() . ')';
        }
        return $changed;
    }

    /** @return string[] */
    private function saveBank(int $borrowerId, array $post): array
    {
        $current = $this->primaryBank($borrowerId);

        if (!$this->bankAny($post)) {
            if ($current) {
                $this->db->prepare('DELETE FROM borrower_bank_details WHERE id = ? AND borrower_id = ?')->execute([$current['id'], $borrowerId]);
                // another account the client has must not be left without a primary one
                $next = $this->db->prepare('SELECT id FROM borrower_bank_details WHERE borrower_id = ? ORDER BY id ASC LIMIT 1');
                $next->execute([$borrowerId]);
                $nextId = $next->fetchColumn();
                $out = ['bank (removed)', 'bank.account_number: ' . self::maskAccount($current['account_number'] ?? null) . ' -> (none)'];
                if ($nextId !== false) {
                    $this->db->prepare('UPDATE borrower_bank_details SET is_primary = 1 WHERE id = ? AND borrower_id = ?')->execute([$nextId, $borrowerId]);
                    $out[] = 'bank (next account made primary)';
                }
                return $out;
            }
            return [];
        }

        $t = static fn (string $k): ?string => (($v = trim((string) ($post[$k] ?? ''))) !== '') ? $v : null;
        $new = [
            'bank_name' => $t('bank_name'),
            'account_name' => $t('account_name'),
            'account_number' => $t('account_number'),
            'account_type' => $t('account_type'),
            'branch_name' => $t('bank_branch_name'),
            'branch_code' => $t('bank_branch_code'),
        ];

        if ($current === null) {
            $new['borrower_id'] = $borrowerId;
            $new['is_primary'] = 1;
            $this->insertRow('borrower_bank_details', $new);
            return ['bank (added)', 'bank.account_number: (none) -> ' . self::maskAccount($new['account_number'])];
        }

        $changed = [];
        foreach ($new as $col => $val) {
            if ((string) ($current[$col] ?? '') !== (string) ($val ?? '')) {
                $changed[] = 'bank.' . $col;
                if ($col === 'account_number') {
                    // the audit trail records WHICH account changed but never a full account number
                    $changed[] = 'bank.account_number: ' . self::maskAccount($current[$col] ?? null) . ' -> ' . self::maskAccount($val);
                }
            }
        }
        // the row shown and edited here is the primary one: make sure it is flagged as such
        if ((int) ($current['is_primary'] ?? 0) !== 1) {
            $new['is_primary'] = 1;
            $changed[] = 'bank.is_primary';
        }
        if ($changed) {
            $this->updateRow('borrower_bank_details', $new, (int) $current['id']);
        }
        return $changed;
    }

    /** Whitespace-insensitive comparison key for free text (line endings, runs of spaces, case of nothing else). */
    private static function squash($v): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $v));
    }

    private static function sameText($a, $b): bool
    {
        return self::squash($a) === self::squash($b);
    }

    private static function sameName($a, $b): bool
    {
        return mb_strtolower(self::squash($a)) === mb_strtolower(self::squash($b));
    }

    /** Line endings as LF (a browser posts textarea newlines as CRLF); null stays null. */
    private static function lfText(?string $v): ?string
    {
        return $v === null ? null : str_replace(["\r\n", "\r"], "\n", $v);
    }

    /** Last four characters only ('****1234'); an empty value reads '(none)' and anything of four characters or fewer is fully hidden. */
    private static function maskAccount($number): string
    {
        $n = preg_replace('/\s+/', '', (string) $number);
        if ($n === '') {
            return '(none)';
        }
        return '****' . (mb_strlen($n) > 4 ? mb_substr($n, -4) : '');
    }

    /** @return string[] (protected so a test can make this last step fail and prove the whole save rolls back) */
    protected function saveContacts(int $borrowerId, array $post): array
    {
        $rows = is_array($post['contacts'] ?? null) ? $post['contacts'] : [];
        $existing = [];
        foreach ($this->contacts($borrowerId) as $r) {
            $existing[(int) $r['id']] = $r;
        }

        $added = $updated = 0;
        $keep = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $c = $this->contactRow($row);
            $data = [
                'contact_type' => $c['contact_type'] ?: 'Next of Kin',
                'full_name' => trim($c['full_name']),
                'relationship' => trim($c['relationship']) ?: null,
                'phone' => trim($c['phone']) ?: null,
                'email' => trim($c['email']) ?: null,
                'address' => self::lfText(trim($c['address']) ?: null),
            ];

            if ($c['id'] === '') {
                if (!$this->contactHasData($c)) {
                    continue;
                }
                $data['borrower_id'] = $borrowerId;
                $this->insertRow('borrower_contacts', $data);
                $added++;
                continue;
            }

            $cid = (int) $c['id'];
            if (!isset($existing[$cid])) {
                continue; // validate() already rejected this; never touch a row that is not this borrower's
            }
            $keep[$cid] = true;
            // an address that only differs in whitespace / line endings is untouched: keep the stored text, never rewrite it
            if (self::sameText($existing[$cid]['address'] ?? null, $data['address'])) {
                $data['address'] = $existing[$cid]['address'];
            }
            $differs = false;
            foreach ($data as $col => $val) {
                if ((string) ($existing[$cid][$col] ?? '') !== (string) ($val ?? '')) {
                    $differs = true;
                    break;
                }
            }
            if ($differs) {
                $this->updateRow('borrower_contacts', $data, $cid);
                $updated++;
            }
        }

        // Only a contact the form LOADED and that is no longer posted was removed by the user. A contact the form never saw (added in
        // another tab or by another user meanwhile) is never deleted; a post without loaded_contact_ids deletes nothing.
        $removed = 0;
        foreach ((self::loadedContactIds($post) ?? []) as $cid) {
            if (isset($existing[$cid]) && !isset($keep[$cid])) {
                $this->db->prepare('DELETE FROM borrower_contacts WHERE id = ? AND borrower_id = ?')->execute([$cid, $borrowerId]);
                $removed++;
            }
        }

        $changed = [];
        if ($added) {
            $changed[] = 'contacts: ' . $added . ' added';
        }
        if ($updated) {
            $changed[] = 'contacts: ' . $updated . ' updated';
        }
        if ($removed) {
            $changed[] = 'contacts: ' . $removed . ' removed';
        }
        return $changed;
    }

    private function contactHasData(array $c): bool
    {
        foreach (['full_name', 'relationship', 'phone', 'email', 'address'] as $f) {
            if (trim((string) ($c[$f] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function insertRow(string $table, array $data): void
    {
        $cols = array_keys($data);
        $st = $this->db->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(static fn ($c) => ':' . $c, $cols))));
        foreach ($data as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->execute();
    }

    private function updateRow(string $table, array $data, int $id): void
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $st = $this->db->prepare("UPDATE $table SET $set WHERE id = :__id");
        foreach ($data as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->bindValue(':__id', $id);
        $st->execute();
    }
}
