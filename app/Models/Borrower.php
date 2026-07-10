<?php

namespace App\Models;

use App\Core\Model;

class Borrower extends Model
{
    protected string $table = 'borrowers';

    public function paginated(string $search = '', string $status = '', int $limit = 50): array
    {
        $sql = "SELECT b.*, br.branch_name
                FROM borrowers b
                LEFT JOIN branches br ON br.id = b.branch_id
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (b.first_name LIKE ? OR b.last_name LIKE ? OR b.borrower_no LIKE ? OR b.id_number LIKE ? OR b.phone LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($status !== '') {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY b.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function count(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM borrowers");
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM borrowers WHERE status = ?", [$status]);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT b.*, br.branch_name FROM borrowers b LEFT JOIN branches br ON br.id = b.branch_id WHERE b.id = ?",
            [$id]
        );
    }

    public function loansFor(int $borrowerId): array
    {
        return $this->all(
            "SELECT l.*, p.product_name FROM loans l LEFT JOIN loan_products p ON p.id = l.product_id WHERE l.borrower_id = ? ORDER BY l.id DESC",
            [$borrowerId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('borrowers', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('borrowers', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        return $this->query("DELETE FROM borrowers WHERE id = ?", [$id])->rowCount() > 0;
    }

    public function idNumberExists(string $idNumber, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM borrowers WHERE id_number = ? AND id != ?", [$idNumber, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM borrowers WHERE id_number = ?", [$idNumber]);
    }

    /**
     * Create a borrower together with its optional bank details, employment
     * record, and next-of-kin/guarantor contacts in a single transaction.
     * Document uploads are handled separately by the controller since they
     * touch the filesystem, not just the database.
     */
    public function createFull(array $borrower, ?array $bank, ?array $employment, array $contacts): int
    {
        $this->db->beginTransaction();

        try {
            $borrowerId = $this->insert('borrowers', $borrower);

            if ($bank) {
                $bank['borrower_id'] = $borrowerId;
                $this->insert('borrower_bank_details', $bank);
            }

            if ($employment) {
                $employment['borrower_id'] = $borrowerId;
                $this->insert('borrower_employment', $employment);
            }

            foreach ($contacts as $contact) {
                $contact['borrower_id'] = $borrowerId;
                $this->insert('borrower_contacts', $contact);
            }

            $this->db->commit();
            return $borrowerId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function bankDetails(int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM borrower_bank_details WHERE borrower_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1", [$borrowerId]);
    }

    public function employmentFor(int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM borrower_employment WHERE borrower_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1", [$borrowerId]);
    }

    public function contactsFor(int $borrowerId): array
    {
        return $this->all("SELECT * FROM borrower_contacts WHERE borrower_id = ? ORDER BY id ASC", [$borrowerId]);
    }

    public function documentsFor(int $borrowerId): array
    {
        return $this->all("SELECT * FROM borrower_documents WHERE borrower_id = ? ORDER BY id ASC", [$borrowerId]);
    }

    public function addDocument(array $data): int
    {
        return $this->insert('borrower_documents', $data);
    }

    public function findDocument(int $borrowerId, int $documentId): ?array
    {
        return $this->one("SELECT * FROM borrower_documents WHERE id = ? AND borrower_id = ?", [$documentId, $borrowerId]);
    }
}
