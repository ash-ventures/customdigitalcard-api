<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Admin} {--phone=} {--password=}';

    protected $description = 'Create an admin account, or promote an existing user to admin.';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        $password = $this->option('password') ?: password('Kata laluan admin (min 8 aksara)');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 8) {
            $this->error('Emel tidak sah atau kata laluan kurang daripada 8 aksara.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $user->exists ? $user->name : $this->option('name');
        $user->phone = $this->option('phone') ?: $user->phone;
        $user->password = $password;
        $user->forceFill(['is_admin' => true])->save();

        $this->info("Admin {$email} sedia digunakan.");

        return self::SUCCESS;
    }
}
