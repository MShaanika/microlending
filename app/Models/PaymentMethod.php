<?php

namespace App\Models;

use App\Core\Model;

class PaymentMethod extends Model
{
    public function allMethods(): array
    {
        return $this->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY method_name")->fetchAll();
    }
}
