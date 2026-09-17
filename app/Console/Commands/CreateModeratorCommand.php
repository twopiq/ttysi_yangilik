<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateModeratorCommand extends Command
{
    protected $signature = 'moderator:create
                            {--name= : Ism}
                            {--email= : Email}
                            {--password= : Parol}';

    protected $description = 'Moderatsiya paneliga kiradigan foydalanuvchi yaratadi';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Ism');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Parol');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Moderator yaratildi: {$user->email}");

        return self::SUCCESS;
    }
}
