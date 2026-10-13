<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateStudentAccount;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $role = Role::tryFrom((string) $request->query('role'));
        $search = Str::lower(trim((string) $request->query('search')));
        $deactivated = $request->query('status') === 'deactivated';

        $users = User::query()
            ->when($deactivated, fn (Builder $query) => $query->onlyTrashed())
            ->when($role, fn (Builder $query) => $query->where('role', $role))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")))
            ->with('studentProfile')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role->value,
                'yearGroup' => $user->studentProfile?->year_group->label(),
                'deactivated' => $user->trashed(),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => [
                'role' => $role?->value,
                'search' => $search,
                'status' => $deactivated ? 'deactivated' : 'active',
            ],
            'roles' => self::roleOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/create', [
            'roles' => self::roleOptions(),
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function store(StoreUserRequest $request, CreateStudentAccount $createStudent): RedirectResponse
    {
        $role = $request->enum('role', Role::class);

        if ($role === Role::Student) {
            $user = $createStudent->handle($request->safe()->only(['name', 'username', 'email', 'password', 'year_group']));
        } else {
            $user = new User([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);
            $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been created."]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role->value,
                'yearGroup' => $user->studentProfile?->year_group->value,
                'deactivated' => $user->trashed(),
            ],
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email']));

        if ($user->hasRole(Role::Student)) {
            $user->forceFill(['username' => $request->validated('username')]);
            $user->studentProfile()->update(['year_group' => (int) $request->validated('year_group')]);
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been updated."]);

        return to_route('admin.users.index');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function roleOptions(): array
    {
        return [
            ['value' => Role::Student->value, 'label' => 'Student'],
            ['value' => Role::Guardian->value, 'label' => 'Parent / guardian'],
            ['value' => Role::Tutor->value, 'label' => 'Tutor'],
            ['value' => Role::Admin->value, 'label' => 'Admin'],
        ];
    }
}
