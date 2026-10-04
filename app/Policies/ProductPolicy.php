<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalog;

/**
 * Same split as categories/brands. Managing images, status and stock
 * adjustments counts as updating the product.
 */
class ProductPolicy
{
    use AuthorizesCatalog;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManage($user);
    }

    public function adjustStock(User $user, Product $product): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canDelete($user);
    }
}
