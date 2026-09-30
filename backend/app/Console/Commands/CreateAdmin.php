<?php

namespace App\Console\Commands;

use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Auth\StaffLoginService;
use App\Services\Rbac\RoleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('admin:create {email} {name} {--role=SUPER_ADMIN : Role code to assign platform-wide}')]
#[Description('Create an admin user from the server console (the only way to bootstrap the first administrator)')]
class CreateAdmin extends Command
{
    /**
     * The password is typed at a hidden prompt, or read from the ADMIN_BOOTSTRAP_PASSWORD environment
     * variable for unattended provisioning. It is never an argument, so it cannot land in shell history.
     */
    public function handle(RoleService $roles): int
    {
        $email = StaffLoginService::normaliseEmail((string) $this->argument('email'));
        $password = (string) (config('auth_security.bootstrap_password') ?: $this->secret('Password for '.$email));

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'unique:admin_users,email'],
            'password' => ['required', Password::min((int) config('auth_security.password.min_length'))],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $role = Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', (string) $this->option('role'))->first();
        if ($role === null) {
            $this->error('Unknown admin role. Run db:seed first, or pass an existing --role code.');

            return self::FAILURE;
        }

        $admin = (new AdminUser)->forceFill([
            'email' => $email,
            'name' => (string) $this->argument('name'),
            'password' => $password,
            'status' => StaffStatus::Active,
            'password_changed_at' => now(),
        ]);
        $admin->save();
        $roles->assign($admin, $role);

        $this->info("Admin {$email} created with role {$role->code}. Enable multi-factor authentication at first sign-in.");

        return self::SUCCESS;
    }
}
