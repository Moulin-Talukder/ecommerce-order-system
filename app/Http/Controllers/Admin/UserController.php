<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin user management (Super Admin only; see UserPolicy). Accounts are
 * deactivated rather than deleted, because orders and history reference them.
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $role = UserRole::tryFrom((string) $request->query('role'));
        $status = $request->query('status');

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query->where('name', 'like', Like::contains($search))->orWhere('email', 'like', Like::contains($search))
            ))
            ->when($role, fn ($query, UserRole $role) => $query->where('role', $role))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25, ['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', [
            'user' => new User(['role' => UserRole::Staff, 'is_active' => true]),
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated());

        return to_route('admin.users.index')->with('success', "Account for {$user->name} created.");
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated());

        return to_route('admin.users.index')->with('success', "Account for {$user->name} updated.");
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $active = $request->boolean('is_active');
        $this->users->setActive($user, $active);

        return back()->with('success', $active
            ? "{$user->name} can sign in again."
            : "{$user->name} was deactivated and signed out everywhere.");
    }

    public function updatePassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->users->resetPassword($user, $request->validated('password'));

        return to_route('admin.users.edit', $user)->with('success', "A new password was set for {$user->name}, and they were signed out everywhere.");
    }

    /**
     * @return array<string, string> role value => label (no Super Admin)
     */
    private function assignableRoles(): array
    {
        return collect(UserRole::cases())
            ->reject(fn (UserRole $role) => $role === UserRole::SuperAdmin)
            ->mapWithKeys(fn (UserRole $role) => [$role->value => $role->label()])
            ->all();
    }
}
