<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginTest extends DuskTestCase
{
    /**
     * Test: User can log in successfully from the POS frontend.
     */
    public function test_user_can_login_to_dashboard(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'duskadmin@test.com'],
            ['name' => 'Dusk Admin', 'password' => bcrypt('password123')]
        );

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->waitForText('Log in', 5) // Wait for Vue to render
                ->assertSee('Email address')
                ->assertSee('Password')
                ->type('#email', $user->email)
                ->type('#password', 'password123')
                ->click('[data-test="login-button"]')
                ->pause(3000) // Wait for Inertia/API to resolve
                ->assertPathIsNot('/login'); // Verifies we left the login page
        });
    }

    /**
     * Test: Invalid credentials show an error message.
     */
    public function test_invalid_credentials_show_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitForText('Log in', 5)
                ->type('#email', 'invalid@notexist.com')
                ->type('#password', 'wrongpassword')
                ->click('[data-test="login-button"]')
                ->pause(2000)
                ->assertSee('credentials'); // Laravel returns "These credentials do not match..."
        });
    }
}
