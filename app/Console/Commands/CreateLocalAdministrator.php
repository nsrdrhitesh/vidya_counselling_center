<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateLocalAdministrator extends Command
{
    protected $signature = 'vidya:local-admin';
    protected $description = 'Interactively create an Administrator for a local development database only';

    public function handle(): int
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $connection = config('database.default');
        $databaseHost = config('database.connections.'.$connection.'.host');
        if (!app()->environment('local') || !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || ($connection !== 'sqlite' && !in_array($databaseHost, ['localhost', '127.0.0.1', '::1'], true))) {
            $this->error('This command requires APP_ENV=local, a localhost APP_URL and a local database.');
            return self::FAILURE;
        }

        $name = trim((string)$this->ask('Administrator name'));
        $email = mb_strtolower(trim((string)$this->ask('Administrator email')));
        $password = (string)$this->secret('Password (at least 12 characters)');
        $confirmation = (string)$this->secret('Confirm password');
        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ]);
        if ($validator->fails() || !hash_equals($password, $confirmation)) {
            foreach ($validator->errors()->all() as $message) $this->error($message);
            if (!hash_equals($password, $confirmation)) $this->error('Passwords do not match.');
            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill(['name'=>$name, 'email'=>$email, 'password'=>$password, 'role'=>'administrator', 'is_admin'=>true, 'active'=>true])->save();
        $this->info('Local Administrator created. Sign in at /login. No password was logged or emailed.');
        return self::SUCCESS;
    }
}
