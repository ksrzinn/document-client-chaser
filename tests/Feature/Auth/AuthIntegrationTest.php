<?php

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as RouteFacade;

test('registered user password is hashed and not stored in plaintext', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'hash-check@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'hash-check@example.com')->firstOrFail();

    expect($user->password)->not->toBe('password');
    expect(Hash::check('password', $user->password))->toBeTrue();
});

test('authenticated user can access the dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

test('unauthenticated user is redirected to login when accessing the dashboard', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('session id is regenerated after login', function () {
    $user = User::factory()->create();

    $this->get('/login');
    $idBeforeLogin = session()->getId();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(session()->getId())->not->toBe($idBeforeLogin);
});

test('session is invalidated and a new token is issued after logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard');
    $tokenBeforeLogout = session()->token();

    $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    expect(session()->token())->not->toBe($tokenBeforeLogout);
});

test('an invalid password reset token is rejected', function () {
    $user = User::factory()->create();

    $response = $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(Hash::check('new-password', $user->fresh()->password))->toBeFalse();
});

test('password reset link request does not reveal whether the account exists', function () {
    Notification::fake();

    $response = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('status');
    Notification::assertNothingSent();
});

test('the web middleware group enforces CSRF verification', function () {
    $middleware = RouteFacade::getRoutes()->getByName('login')->gatherMiddleware();

    expect($middleware)->toContain('web');

    $webGroup = app(Router::class)->getMiddlewareGroups()['web'];

    $hasCsrfMiddleware = collect($webGroup)->contains(
        fn ($middleware) => is_string($middleware) && str_contains($middleware, 'PreventRequestForgery')
    );

    expect($hasCsrfMiddleware)->toBeTrue();
});
