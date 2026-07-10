<?php

namespace App\Models;

use App\Core\Model;

class LoanProduct extends Model
{
    public function allWithPlans(): array
    {
        $products = $this->all("SELECT * FROM loan_products ORDER BY product_name");
        foreach ($products as &$product) {
            $product['plans'] = $this->all(
                "SELECT * FROM loan_plans WHERE product_id = ? ORDER BY months",
                [$product['id']]
            );
        }
        return $products;
    }

    public function activeWithPlans(): array
    {
        $products = $this->all("SELECT * FROM loan_products WHERE is_active = 1 ORDER BY product_name");
        foreach ($products as &$product) {
            $product['plans'] = $this->all(
                "SELECT * FROM loan_plans WHERE product_id = ? AND is_active = 1 ORDER BY months",
                [$product['id']]
            );
        }
        return $products;
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM loan_products WHERE id = ?", [$id]);
    }

    public function codeExists(string $code): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM loan_products WHERE product_code = ?", [$code]);
    }

    public function create(array $data): int
    {
        return $this->insert('loan_products', $data);
    }

    public function addPlan(array $data): int
    {
        return $this->insert('loan_plans', $data);
    }

    public function findPlan(int $id): ?array
    {
        return $this->one("SELECT * FROM loan_plans WHERE id = ?", [$id]);
    }
}
