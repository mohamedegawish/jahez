<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * First-time setup (ADR-011): accounts are otherwise created only by an IMC administrator
 * through the API, so the first administrator is created from the server console.
 * The password is prompted for, never passed as an argument, so it stays out of shell history.
 */
class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin
                            {email : Email address of the IMC administrator}
                            {name : Display name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an IMC administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $input = [
            'email' => $this->argument('email'),
            'name' => $this->argument('name'),
            'password' => password('Password', required: true),
        ];
        $input['password_confirmation'] = password('Confirm password', required: true);

        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $administrator = DB::transaction(function () use ($input): User {
            $administrator = new User(['name' => $input['name'], 'email' => $input['email'], 'password' => $input['password']]);
            $administrator->role = Role::ImcAdmin;
            $administrator->email_verified_at = now();
            $administrator->save();

            AuditLog::record(AuditEvent::AdministratorCreatedFromConsole, subject: $administrator);

            return $administrator;
        });

        $this->components->info("IMC administrator {$administrator->email} created.");

        return self::SUCCESS;
    }
}
