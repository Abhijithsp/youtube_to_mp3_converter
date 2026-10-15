<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('app:hash-password')]
#[Description('Hash a password for APP_PASSWORD, so .env does not hold it in plain text')]
class HashPassword extends Command
{
    public function handle(): int
    {
        $password = (string) $this->secret('Password');

        if ($password === '' || $password !== (string) $this->secret('Repeat the password')) {
            $this->components->error('The passwords are empty or do not match.');

            return self::FAILURE;
        }

        $this->components->info('Put this line in .env (keep the single quotes):');
        $this->line("APP_PASSWORD='" . Hash::make($password) . "'");

        return self::SUCCESS;
    }
}
