<?php

namespace Tests\Feature\Api;

use App\Support\AppLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_password_everything_stays_open(): void
    {
        $this->getJson('/api/lock')->assertExactJson(['enabled' => false, 'unlocked' => true]);
        $this->getJson('/api/history')->assertOk();
    }

    public function test_with_a_password_the_api_is_locked(): void
    {
        config(['youtube-converter.password' => 'secret']);

        $this->getJson('/api/lock')->assertExactJson(['enabled' => true, 'unlocked' => false]);
        $this->getJson('/api/history')->assertUnauthorized()->assertJsonPath('locked', true);
        $this->getJson('/api/settings/folders')->assertUnauthorized();
        $this->get('/api/downloads/1/stream')->assertUnauthorized();

        // The interface itself and the health check still load
        $this->get('/')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_the_right_password_unlocks_with_a_cookie(): void
    {
        config(['youtube-converter.password' => 'secret']);

        $this->postJson('/api/lock/unlock', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $response = $this->postJson('/api/lock/unlock', ['password' => 'secret'])->assertOk();
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === AppLock::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGreaterThan(now()->addDays(29)->timestamp, $cookie->getExpiresTime());
        $this->assertStringNotContainsString('secret', $cookie->getValue());

        $this->withCredentials()->withUnencryptedCookie(AppLock::COOKIE, $cookie->getValue())
            ->getJson('/api/history')
            ->assertOk();
        $this->withCredentials()->withUnencryptedCookie(AppLock::COOKIE, $cookie->getValue())
            ->getJson('/api/lock')
            ->assertJsonPath('unlocked', true);
    }

    public function test_changing_the_password_locks_old_cookies_out(): void
    {
        config(['youtube-converter.password' => 'secret']);
        $cookie = collect($this->postJson('/api/lock/unlock', ['password' => 'secret'])->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === AppLock::COOKIE)
            ->getValue();

        config(['youtube-converter.password' => 'new secret']);

        $this->withCredentials()->withUnencryptedCookie(AppLock::COOKIE, $cookie)->getJson('/api/history')->assertUnauthorized();
        $this->withCredentials()->withUnencryptedCookie(AppLock::COOKIE, 'made up')->getJson('/api/history')->assertUnauthorized();
    }

    public function test_a_hashed_password_works(): void
    {
        config(['youtube-converter.password' => Hash::make('secret')]);

        $this->postJson('/api/lock/unlock', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/lock/unlock', ['password' => 'secret'])->assertOk();
    }

    public function test_the_hash_password_command_prints_a_usable_hash(): void
    {
        $this->artisan('app:hash-password')
            ->expectsQuestion('Password', 'secret')
            ->expectsQuestion('Repeat the password', 'secret')
            ->expectsOutputToContain("APP_PASSWORD='\$2y\$")
            ->assertSuccessful();

        $this->artisan('app:hash-password')
            ->expectsQuestion('Password', 'secret')
            ->expectsQuestion('Repeat the password', 'typo')
            ->assertFailed();
    }

    public function test_locking_forgets_the_cookie(): void
    {
        config(['youtube-converter.password' => 'secret']);

        $response = $this->postJson('/api/lock')->assertOk()->assertJsonPath('unlocked', false);
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === AppLock::COOKIE);

        $this->assertTrue($cookie->isCleared());
    }

    public function test_password_guessing_is_throttled(): void
    {
        config(['youtube-converter.password' => 'secret']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/lock/unlock', ['password' => "guess {$i}"])->assertUnprocessable();
        }

        $this->postJson('/api/lock/unlock', ['password' => 'secret'])->assertTooManyRequests();
    }
}
