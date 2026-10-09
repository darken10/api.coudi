<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('Registration', function (): void {
    it('registers a new user successfully', function (): void {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJson([
                'success' => true,
                'message' => 'User registered successfully. Please check your email to verify your account.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    });

    it('fails registration with invalid data', function (): void {
        $response = $this->postJson('/api/v1/register', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
        ]);

        $response->assertStatus(422);
    });

    it('fails registration with duplicate email', function (): void {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    });
});

describe('Login', function (): void {
    it('logs in with valid credentials', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Login successful',
            ]);
    });

    /*
     * MySQL borne TIMESTAMP à 2038-01-19 — l'entier signé de 32 bits. Une
     * échéance au-delà fait échouer l'insertion du jeton, donc la connexion
     * entière. SQLite n'a pas de type date et accepte n'importe quelle valeur :
     * sans ces deux cas, la régression ne réapparaîtrait qu'en production.
     */
    it('stores no expiry when tokens are configured to never expire', function (): void {
        config(['tokens.access_expiry_days' => null]);

        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonPath('data.expires_at', null);

        expect(DB::table('personal_access_tokens')->latest('id')->value('expires_at'))->toBeNull();
    });

    it('keeps a configured expiry within the MySQL TIMESTAMP range', function (): void {
        config(['tokens.access_expiry_days' => 30]);

        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200);

        $expiresAt = Carbon::parse($response->json('data.expires_at'));

        expect($expiresAt->isFuture())->toBeTrue()
            ->and($expiresAt->lessThan(Carbon::create(2038, 1, 19)))->toBeTrue();
    });

    it('fails login with invalid credentials', function (): void {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    });

    it('fails login with non-existent user', function (): void {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    });
});

describe('Logout', function (): void {
    it('logs out authenticated user', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
    });

    it('fails logout without authentication', function (): void {
        $response = $this->postJson('/api/v1/logout');

        $response->assertStatus(401);
    });
});

describe('Me', function (): void {
    it('returns authenticated user data', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'email'],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],
            ]);
    });

    it('fails without authentication', function (): void {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    });
});
