<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class LocalSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_administrator_command_refuses_production(): void
    {
        $this->artisan('vidya:local-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_setup_creates_a_hashed_administrator_without_registration(): void
    {
        app()->instance('env', 'local');
        config(['app.url'=>'http://127.0.0.1:8000']);
        $this->artisan('vidya:local-admin')
            ->expectsQuestion('Administrator name', 'Local Owner')
            ->expectsQuestion('Administrator email', 'local-owner@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'Local-Only-Test123!')
            ->expectsQuestion('Confirm password', 'Local-Only-Test123!')
            ->assertExitCode(0);
        $user = User::firstOrFail();
        $this->assertTrue($user->isAdministrator());
        $this->assertTrue(Hash::check('Local-Only-Test123!', $user->password));
        $this->get('/register')->assertNotFound();
    }

    public function test_local_setup_refuses_a_remote_application_url(): void
    {
        app()->instance('env', 'local');
        config(['app.url'=>'https://vidyacounselling.com']);
        $this->artisan('vidya:local-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }
}
