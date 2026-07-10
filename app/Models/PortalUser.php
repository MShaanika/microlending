<?php

namespace App\Models;

use App\Core\Model;

class PortalUser extends Model
{
    public function findByBorrower(int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM borrower_portal_users WHERE borrower_id = ?", [$borrowerId]);
    }

    /**
     * Create portal access for a borrower, or reset credentials if it
     * already exists (acts as both "create" and "reset password").
     */
    public function provision(int $borrowerId, string $username, ?string $email, string $passwordHash): int
    {
        $existing = $this->findByBorrower($borrowerId);

        if ($existing) {
            $this->update('borrower_portal_users', [
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'is_active' => 1,
            ], 'id', $existing['id']);
            return (int) $existing['id'];
        }

        return $this->insert('borrower_portal_users', [
            'borrower_id' => $borrowerId,
            'username' => $username,
            'email' => $email,
            'password' => $passwordHash,
            'is_active' => 1,
        ]);
    }
}
