<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalog;

class BrandPolicy
{
    use AuthorizesCatalog;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->canDelete($user);
    }
}
