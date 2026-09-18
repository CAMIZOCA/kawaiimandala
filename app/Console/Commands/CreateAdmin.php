<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'kawaii:create-admin {email} {--name=Admin} {--password=}';

    protected $description = 'Create (or update the password of) the administrator user';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');

        if (! is_string($password) || strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password],
        );

        $this->info("Administrador listo: {$user->email}");

        return self::SUCCESS;
    }
}
