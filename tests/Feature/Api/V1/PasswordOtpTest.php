<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Otp\OtpCodeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Réinitialisation par OTP', function (): void {
    it('envoie un code, le vérifie et change le mot de passe', function (): void {
        $user = User::factory()->create([
            'password' => 'ancienmotdepasse',
            'phone' => '+22370000009',
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => $user->email,
            'channel' => 'email',
        ])->assertOk();

        $code = app(OtpCodeRegistry::class)->get('email', $user->email);

        expect($code)->toBeString()->toHaveLength(6);

        $token = $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->email,
            'channel' => 'email',
            'code' => $code,
        ])->assertOk()->json('data.reset_token');

        expect($token)->toBeString()->not->toBeEmpty();

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'password' => 'nouveaumotdepasse',
            'password_confirmation' => 'nouveaumotdepasse',
        ])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'nouveaumotdepasse',
        ])->assertOk();
    });

    it('refuse un code erroné sans révéler l’existence du compte', function (): void {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => $user->email,
            'channel' => 'email',
        ])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->email,
            'channel' => 'email',
            'code' => '000000',
        ])->assertStatus(422);

        // Compte inconnu : même statut et même message de demande.
        $inconnu = $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => 'personne@example.com',
            'channel' => 'email',
        ])->assertOk();

        expect($inconnu->json('message'))
            ->toBe('Si ce compte existe, un code vient d’être envoyé.');
    });

    it('invalide l’ancien code quand une nouvelle demande est faite', function (): void {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => $user->email,
            'channel' => 'email',
        ])->assertOk();

        $premier = app(OtpCodeRegistry::class)->get('email', $user->email);

        // Le délai anti-renvoi est neutralisé pour ce scénario.
        config(['koudi.otp.resend_seconds' => 0]);

        $this->postJson('/api/v1/auth/otp/request', [
            'identifier' => $user->email,
            'channel' => 'email',
        ])->assertOk();

        $second = app(OtpCodeRegistry::class)->get('email', $user->email);

        expect($premier)->not->toBe($second);

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->email,
            'channel' => 'email',
            'code' => $premier,
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/otp/verify', [
            'identifier' => $user->email,
            'channel' => 'email',
            'code' => $second,
        ])->assertOk();
    });
});
