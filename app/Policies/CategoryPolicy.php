<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalog;

class CategoryPolicy
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

    public function update(User $user, Category $category): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->canDelete($user);
    }
}
