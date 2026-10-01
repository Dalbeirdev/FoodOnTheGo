<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Support\ListQuery;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\Role;
use App\Services\Auth\AdminUserService;
use App\Support\NoticeLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administrator accounts and admin roles. Permissions are checked on the routes and must be held
 * platform-wide: accounts and roles are not market data. The rules that no permission overrides live in
 * AdminUserService.
 */
class AdminUserController extends Controller
{
    private const REASON = ['required', 'string', 'min:3', 'max:500'];

    public function __construct(private readonly AdminUserService $admins) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $list = new ListQuery($request, filterable: ['status'], sortable: ['name', 'email', 'status', 'last_login_at', 'created_at'], defaultSort: 'name');

        $query = AdminUser::query()->with('roleAssignments.role');
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return AdminUserResource::collection($list->paginate($query));
    }

    /**
     * The admin roles and what each one allows.
     */
    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->where('principal_type', PrincipalType::AdminUser->value)->with('permissions')->orderBy('name')->get()
            ->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name, 'system' => $role->is_system, 'permissions' => $role->permissions->pluck('permission')->sort()->values()->all()])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
            'market_id' => ['sometimes', 'nullable', 'uuid'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(NoticeLocales::available())],
        ]);

        $admin = $this->admins->invite($input['name'], $input['email'], $this->role($input['role']), $this->market($input['market_id'] ?? null), $request->user(), $input['locale'] ?? null);

        return (new AdminUserResource($admin->load('roleAssignments.role')))->response()->setStatusCode(201);
    }

    public function updateStatus(Request $request, AdminUser $adminUser): AdminUserResource
    {
        $input = $request->validate([
            'status' => ['required', Rule::in([StaffStatus::Active->value, StaffStatus::Suspended->value, StaffStatus::Disabled->value])],
            'reason' => self::REASON,
        ]);

        return new AdminUserResource($this->admins->changeStatus($adminUser, StaffStatus::from($input['status']), $input['reason'], $request->user())->load('roleAssignments.role'));
    }

    public function updateRole(Request $request, AdminUser $adminUser): AdminUserResource
    {
        $input = $request->validate([
            'role' => ['required', 'string', 'max:60'],
            'market_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => self::REASON,
        ]);

        $admin = $this->admins->changeRole($adminUser, $this->role($input['role']), $this->market($input['market_id'] ?? null), $input['reason'], $request->user());

        return new AdminUserResource($admin->load('roleAssignments.role'));
    }

    public function resetMfa(Request $request, AdminUser $adminUser): AdminUserResource
    {
        $input = $request->validate(['reason' => self::REASON]);

        return new AdminUserResource($this->admins->resetMfa($adminUser, $input['reason'], $request->user())->load('roleAssignments.role'));
    }

    public function resendInvitation(AdminUser $adminUser): JsonResponse
    {
        $this->admins->resendInvitation($adminUser);

        return response()->json(['message' => 'A new invitation link has been sent. Earlier links no longer work.']);
    }

    /**
     * Public: the invited person sets their own password with the link they were sent.
     */
    public function acceptInvitation(ResetPasswordRequest $request): JsonResponse
    {
        $this->admins->acceptInvitation((string) $request->validated('token'), (string) $request->validated('password'));

        return response()->json(['message' => 'Your password is set. You can sign in now.']);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->first()
            ?? throw ValidationException::withMessages(['role' => ['Unknown administrator role.']]);
    }

    private function market(?string $publicId): ?Market
    {
        if ($publicId === null) {
            return null;
        }

        return Market::query()->where('public_id', $publicId)->first() ?? throw ValidationException::withMessages(['market_id' => ['Unknown market.']]);
    }
}
